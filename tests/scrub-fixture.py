#!/usr/bin/env python3
"""Turns a recorded Vast.ai answer into a fixture that is safe to commit.

Nothing in this repository was recorded from the API. tests/fixtures/machines.json
was written by hand from other projects' recordings. One real answer from a host
account, ideally with a multi-GPU machine that is partly rented, settles the
questions the code comments mark as undocumented: the gpu_occupancy format, the
timeout field, and whether balance or credit holds a host's money.

Record on the Unraid server, with the key the plugin already has. The key is
read into a shell variable and never printed:

  ssh root@tower '. /boot/config/plugins/vastai/vastai.cfg; curl -s -H "Authorization: Bearer $API_KEY" "https://console.vast.ai/api/v0/machines/?owner=me"' > machines.raw.json
  ssh root@tower '. /boot/config/plugins/vastai/vastai.cfg; curl -s -H "Authorization: Bearer $API_KEY" "https://console.vast.ai/api/v0/users/current/"'    > user.raw.json

Then scrub, read the result, and only then commit it:

  python3 tests/scrub-fixture.py machines.raw.json user.raw.json > tests/fixtures/recorded.json

*.raw.json is ignored by git. Delete the two files afterwards.

What is kept: every field name, the fields the plugin reads with their real
shape, and the type of everything else. What is replaced: machine ids,
hostnames, addresses, and every amount of money, which keeps its sign and
whether it was zero.
"""
import json, math, sys

# The fields getvaststatus.php reads. Their values decide what the tile shows.
KEEP = {'num_gpus', 'gpu_name', 'gpu_ram', 'listed', 'timeout', 'gpu_occupancy', 'current_rentals_running',
        'current_rentals_resident', 'current_rentals_on_demand', 'gpu_max_cur_temp', 'reliability2', 'verification',
        'cpu_name', 'cpu_cores', 'cpu_ram', 'disk_space', 'avail_disk_space', 'inet_down', 'inet_up'}
MONEY = {'earn_hour', 'earn_day', 'listed_gpu_cost', 'min_bid_price', 'balance', 'credit'}


def money(v):
    """1.23 with the sign of v, or 0 for 0. Says nothing about the amount."""
    return 0 if not v else math.copysign(1.23, v)


def shape(v):
    """The type of a value the plugin does not read, with the value removed."""
    return {str: 'string', bool: 'bool', int: 'number', float: 'number', type(None): None,
            list: 'list', dict: 'object'}[type(v)]


def scrub(obj, number):
    out = {}
    for k, v in obj.items():
        if k in MONEY and isinstance(v, (int, float)):
            out[k] = money(v)
        elif k in KEEP:
            out[k] = v
        else:
            out[k] = shape(v)
    if 'id' in obj:
        out['id'] = 1000 + number
    if 'hostname' in obj:
        out['hostname'] = f'host-{number}'
    return out


machines = json.load(open(sys.argv[1], encoding='utf-8'))
user = json.load(open(sys.argv[2], encoding='utf-8')) if len(sys.argv) > 2 else {}
json.dump({
    'machines': {'machines': [scrub(m, i + 1) for i, m in enumerate(machines.get('machines', []))]},
    'user': scrub(user, 0),
}, sys.stdout, indent=2, sort_keys=True)
print()
