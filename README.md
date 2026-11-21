# Vast.ai Dashboard Widget for Unraid

An Unraid plugin, listed in Unraid as **Vast.ai Monitor**, that adds a dashboard tile for the machines you host on Vast.ai. It needs Unraid 7.2.0 or later and a Vast.ai host account. A renter-only account shows an empty tile.

## What it shows

One row per machine, and a summary line with the combined earnings of the machines that are online and your account balance.

| Column | Meaning |
|--------|---------|
| DEVICE | Hostname, Vast.ai machine ID, GPU count, GPU model and VRAM. |
| STATUS | `active` (rented, with the rental type, and the job count when Vast.ai reports one), `listed`, `unlisted` or `offline`. An offline machine reads `offline` even with a rental still open on it. |
| RATE | Rented: what the whole machine earns per hour, and Vast.ai's daily figure. Listed: asking price and minimum bid for the whole machine, plus the per-GPU asking price on a multi-GPU host. |
| TEMP | The hottest GPU in the machine. Highlighted from 65 °C and again from 80 °C. |
| RELIABILITY | Vast.ai's reliability score. A warning icon below 95%. |
| UTILIZATION | The share of the machine's GPUs that are rented. It is not GPU load. A dash means rented, with the split not reported. |

**Show details** adds CPU, RAM, storage, network speed and verification state under each row.

The tile header reports the hosts: `ONLINE`, `OFFLINE`, or how many are online, and how many are rented. Vast.ai reports earnings as a running average of what a machine really earned, so an idle machine can show a small amount.

## Installation

### From Community Applications

On the **Apps** tab, search for **Vast.ai Monitor** and click Install.

### From the plugin URL

1. In the Unraid web UI, open **Plugins** > **Install Plugin**.
2. Paste the URL and click **Install**:
   ```
   https://raw.githubusercontent.com/0xntpower/VastAI-Unraid-Widget/main/vastai.plg
   ```

### Then

1. Create an API key that can only read. See [Your API key](#your-api-key).
2. Go to **Settings** > **Utilities** > **Vast.ai Monitor**, paste the key, click **Test Connection**, then **Apply**.

The tile can be dragged to another dashboard column like any built-in tile.

## Configuration

Settings are under **Settings** > **Utilities** > **Vast.ai Monitor**.

| Setting | Values | Default | What it does |
|---------|--------|---------|--------------|
| Vast.ai API Key | text | empty | The key used for every request. **Test Connection** tries the key in the field without saving it and reports how many machines it found. |
| Default Refresh Interval | 10 s, 30 s, 60 s, 2 min, Off | 30 s | How often an open dashboard asks the server for data. A dashboard in a background browser tab does not ask, and neither does a tile you have closed. Off loads once and then only when you click refresh. |
| Local Cache Expiration | 15 s, 30 s, 60 s, 2 min | 30 s | How long the server reuses the last answer from Vast.ai before fetching again. All open dashboards share it. |
| Show Account Balance | Yes, No | Yes | Shows the balance in the summary line. With No, the balance is not requested from Vast.ai. |

A value in the settings file that this page does not offer is ignored and the default is used.

**Controls on the tile.** The dropdown in the tile header changes the refresh interval for the current page only. It is not saved. The refresh icon fetches from Vast.ai at once, ignoring the cache. The cog opens these settings.

## Your API key

**Create a restricted key.** A new Vast.ai key has full access unless you restrict it when you create it. A full-access key can change your listings and move account credit. This plugin only reads. It needs two permissions and nothing else:

- `machine_read`, for the machine list
- `user_read`, for the account balance. Not needed if you turn Show Account Balance off

In the console, restrict the key to those when you create it at [console.vast.ai/manage-keys](https://console.vast.ai/manage-keys/). With the `vastai` command line tool:

```bash
echo '{"api": {"machine_read": {}, "user_read": {}}}' > perms.json
vastai create api-key --name unraid-monitor --permission_file perms.json
```

**Where it is stored.** `/boot/config/plugins/vastai/vastai.cfg` on the Unraid flash drive, in plaintext. That is the standard place for Unraid plugin settings, and it survives reboots and plugin updates. On a running server only root can read the file. That comes from how Unraid mounts the flash drive, not from anything this plugin does.

**Where else it ends up.**

- Anyone who holds the flash drive can read the file on another computer.
- If Unraid Connect Flash Backup is on, the file is uploaded with the rest of the flash drive, and earlier versions of it stay in the backup's history.
- Anyone who can open the Unraid web UI can reveal the key on the settings page. If your web UI is served over plain HTTP, the key crosses your network unencrypted each time that page loads and each time you click Test Connection.

**What is sent where.** The plugin contacts one host, `console.vast.ai`, over HTTPS with certificate verification. A refresh is a GET to `/api/v0/machines/?owner=me` and, at most every five minutes when Show Account Balance is on, a GET to `/api/v0/users/current/`. The key travels in an `Authorization: Bearer` header, never in a URL. Redirects are followed only to HTTPS. Nothing is sent to the plugin author or anyone else, and the plugin loads no third-party code.

## Caching and failures

Answers from Vast.ai are kept in `/var/local/vastai/`, a directory in RAM that only root can use, one file per API key. Nothing is written to the flash drive or the array while the tile runs. A reboot or a plugin update clears the cache.

- Several dashboards open at once share one request to Vast.ai. While one request is fetching, the others get the previous answer.
- If Vast.ai fails, the tile shows the last good answer labelled `STALE`, with its age, and the server waits 30 seconds before trying again. With nothing cached it shows the error and waits the same 30 seconds.
- A rejected key is shown as an error, never as stale data, and is tried again only every five minutes.
- The refresh icon and the Retry link skip every wait.
- Failures are written to the system log (`grep vastai /var/log/syslog`), once when a failure starts or changes and not once per poll.

## Troubleshooting

| What you see | What it means | What to do |
|--------------|---------------|------------|
| `Not Configured` | No API key is saved. | Enter one under Settings > Utilities > Vast.ai Monitor. |
| `API Key Rejected` | Vast.ai refused the key. Retrying will not help. | Paste a valid key in Settings. |
| `Vast.ai Unreachable` with a timeout or DNS message | The server could not reach `console.vast.ai` and has nothing cached to show. | Check the server's internet access and DNS. |
| `Status: STALE` and a warning box | Vast.ai failed and the tile shows the last good answer. | Wait. The server retries every 30 seconds. |
| `Status: 1/2 ONLINE` or `OFFLINE` | Some or all of your machines are out of contact with Vast.ai. Each row has its own status. | |
| `cached 12s ago` | The poll was answered from the server's cache. Normal. | |
| `Signed Out` | The web UI returned its login page, usually because your session ended. | Reload the page and log in. |
| `Error` with `timeout` | The Unraid server did not answer within 20 seconds. | Check that the web UI responds. |
| `No host machines found on this Vast.ai account.` | The key works and the account hosts no machines. | |
| A dash under UTILIZATION | The machine is rented and Vast.ai did not report how many of its GPUs are. | |
| No balance in the summary line | Show Account Balance is off, the key lacks `user_read`, or the balance request failed. | |
| An update installed and the tile looks the same | A dashboard tab that was open during the update keeps the old page. | Reload the dashboard. |
| The update is refused | The server runs an Unraid older than 7.2.0. | The plugin stays on the version it has. |

### Reporting a problem

Post in the [forum thread](https://forums.unraid.net/topic/200608-plugin-vastai-dashboard-tile/) or open a GitHub issue, with:

- the plugin version from the **Plugins** page and your Unraid version
- the text in the tile
- what `/plugins/vastai/include/getvaststatus.php` shows when you open it in the browser while logged in to Unraid. It contains hostnames, machine IDs and earnings, so remove what you do not want to share. It never contains the key
- the output of `grep vastai /var/log/syslog | tail`

Never post `vastai.cfg`. It holds your key.

## Uninstalling

Remove **Vast.ai Monitor** on the **Plugins** page. This deletes the plugin's files and its cache, blanks the API key in `/boot/config/plugins/vastai/vastai.cfg` and keeps your other settings. To remove those too, delete the `/boot/config/plugins/vastai/` folder.

Uninstalling cannot remove the key from a flash backup, from the backup's history or from the flash drive's free space. When you stop using the plugin, or when the flash drive or your Unraid.net account leaves your control, delete the key at [console.vast.ai/manage-keys](https://console.vast.ai/manage-keys/).

## Reporting a security problem

Please do not post it in the forum thread or in a public issue. Use **Report a vulnerability** on the repository's Security tab. Only the current release is supported, because every install updates itself from `main`.

## Development

### Layout

- `src/vastai/usr/local/emhttp/plugins/vastai/` mirrors the install path on the server. Every file under it ships.
  - `VastAIDashboard.page` registers the tile and includes `include/VastAI.php`, which builds the empty tile and links the stylesheet and the script.
  - `javascript/vastai.js` polls `include/getvaststatus.php` and draws the table.
  - `include/getvaststatus.php` calls Vast.ai, caches the answer and returns JSON. It decides each machine's state and prices. The script only draws them.
  - `include/config.php` reads the settings and decides what a valid value is. The endpoint, the tile and the settings page all use it.
  - `VastAISettings.page` is the settings form. Unraid's own `update.php` writes the values to `/boot/config/plugins/vastai/vastai.cfg`.
  - `default.cfg` holds the defaults. No settings file is created at install. It appears the first time the user presses Apply, and holds every setting from then on, so a default changed later reaches only the installs that never pressed Apply.
- `build/build.ps1` generates `vastai.plg` from every file under `src/`. It also holds the install script, the removal script and the release notes.
- `tests/` holds the checks, described below.
- `preview/` shows the tile in a browser without an Unraid server.

### Checks

```bash
./tests/check.sh
```

Needs `bash`, `git` and `python3`. It uses `pwsh`, `node` and `php` when they are present and prints `SKIP` when one is missing, so "All checks passed" on a machine without them means less than it says. Without a local `php` the PHP steps run in the `php:8.2-cli-alpine` image if docker is running. In CI a skipped step is a failure.

| File | What it runs |
|------|--------------|
| `tests/endpoint.test.php` | The real endpoint, once per request, against a canned Vast.ai: every answer, the cache files, and how many calls were made. |
| `tests/shell.test.php` | The real tile shell and the settings reader. |
| `tests/tile.test.js` | The real tile script with a stubbed jQuery: states, money, escaping, polling. |
| `tests/settings.test.js` | Test Connection from the real settings page. |
| `tests/install.test.py` | Install, update and remove, run from `vastai.plg` in a scratch directory. |

Three files under `tests/` are written by the tests and compared on every run:

- `tests/run-blocks.golden` is every script the manifest runs as root. `install.test.py` refuses to run them if they differ from this copy. After a deliberate change, run `python3 tests/install.test.py --update-golden`, read the diff, and run the test again.
- `tests/fixtures/payload.json` is the endpoint's answer for `tests/fixtures/machines.json`. Update it with `php tests/endpoint.test.php --update-fixture`.
- `tests/fixtures/shell.html` is the tile shell with default settings. Update it with `php tests/shell.test.php --update-fixture`.

The tile test and the preview read the last two, so neither can drift from the PHP.

`tests/fixtures/machines.json` was written by hand from other projects' recordings of the API. Vast.ai documents almost none of these fields. `tests/scrub-fixture.py` explains how to record a real answer and make it safe to commit.

### Preview

```bash
python3 -m http.server 8099 --bind 127.0.0.1
# then open http://127.0.0.1:8099/preview/
```

The page loads the real stylesheet and script from `src/`, the pinned tile shell and the pinned endpoint answer, and lets you switch between Unraid's white and black theme colours and between the answers the endpoint can give. It makes no request to Vast.ai and reads no key. jQuery and Font Awesome come from public CDNs with integrity hashes. It does not show Unraid's own page around the tile, so check layout on a real server.

### Endpoint response

`GET /plugins/vastai/include/getvaststatus.php` returns one JSON object. `?force=1` skips the cache and every wait. `POST` with `test_key` tries that key and neither reads nor writes the cache.

On failure: `success: false`, `code` (`not_configured`, `auth` for a rejected key, `upstream` for anything else), `error` (text for the user), `http_code` and `timestamp`.

On success: `success: true`, `timestamp` (when the data was fetched), `summary`, `machines`, and one of these when the data is not fresh:

- `cached: true` and `cache_age` in seconds
- `stale: true`, `cache_age` and `warning`

`summary`: `total_machines`, `online_machines`, `rented_machines`, `total_earn_hour` and `total_earn_day` (machines that are online), and `account_balance`, which is `null` when it is not known.

Each machine:

| Field | From the Vast.ai field | Notes |
|-------|------------------------|-------|
| `id`, `hostname`, `verification` | same names | |
| `num_gpus`, `gpu_name`, `gpu_ram_gb` | `num_gpus`, `gpu_name`, `gpu_ram` (MB) | A leading "NVIDIA " is dropped. |
| `gpu_temp` | `gpu_max_cur_temp` | °C, `null` when absent. |
| `reliability` | `reliability2` (0 to 1) | Percent, `null` when absent. |
| `state` | `timeout`, `gpu_occupancy`, `current_rentals_running`, `listed` | `offline` when `timeout` is above 0, else `rented`, `listed` or `unlisted`. |
| `rental_type` | `gpu_occupancy` | `on_demand`, `reserved`, `interruptible`, or `null`. |
| `gpus_rented` | `gpu_occupancy` | A count, or `null` when the API did not say. |
| `running_rentals` | `current_rentals_running` | Rentals, not GPUs. |
| `earn_hour`, `earn_day` | same names | Per machine. |
| `ask_hour`, `min_bid_hour` | `listed_gpu_cost`, `min_bid_price` (per GPU) | Scaled to the machine. |
| `listed_gpu_cost` | same name | Per GPU. |
| `cpu_name`, `cpu_cores`, `cpu_ram_gb` | `cpu_name`, `cpu_cores`, `cpu_ram` (MB) | |
| `disk_space_gb`, `avail_disk_gb` | `disk_space`, `avail_disk_space` | |
| `inet_down_mbps`, `inet_up_mbps` | `inet_down`, `inet_up` | |
| `is_online`, `listed`, `is_rented`, `occupancy_code`, `min_bid_price` | | Kept for a dashboard tab opened before an update. The tile does not read them. |

`gpu_occupancy` is one letter per GPU separated by spaces, for example `"D x x x "`: `D` on-demand, `R` reserved, `I` interruptible, `x` idle. That, the meaning of `timeout`, and `balance` being the field that holds a host's money are read from other projects' recordings and from this plugin's own use, not from Vast.ai documentation.

### Icons

`images/vastai.png` is the icon on the Plugins page, on the Settings entry and in the settings banner. `icons/vastai.png` is the smaller copy in the dashboard tile header. Both are generated from `build/assets/vastai-master.png`:

```bash
python3 build/make-icons.py
```

That crops the master to the badge and cuts the corners to transparency. Needs Pillow, and only needs rerunning when the master artwork changes.

The short description on the Unraid Plugins page comes from `src/vastai/usr/local/emhttp/plugins/vastai/README.md`, which is a different file from this one.

## Releasing

Every install checks `vastai.plg` on the `main` branch and updates itself from it. A merge to `main` is a release to every user, and the install script inside it runs as root. Users with the Auto Update plugin take it with no click. There is no staging channel.

`main` has a ruleset: changes arrive by pull request, the `check` job must be green, and nobody can bypass it, the owner included. Its definition is `.github/ruleset-main.json`.

`vastai.plg` is a generated file. Edit the files under `src/` and rebuild. Never edit it by hand.

For every change under `src/` or to `build/build.ps1`:

1. Branch from `main`.
2. Make the change.
3. Release notes: in `build/build.ps1`, add a new `###` heading with the new version above the others in `<CHANGES>` and write what users will see change. If they need to do something, say that first. The text is XML, so write no `&` and no `<`.
4. Stamp the version last, in UTC, with the same value as the heading:
   ```bash
   date -u +%Y.%m.%d.%H%M > VERSION
   ```
5. Build and check:
   ```bash
   pwsh -NoProfile -File build/build.ps1
   ./tests/check.sh
   ```
   The build stops if the top heading and `VERSION` differ. If the golden check fails, read the script diff first.
6. Commit `VERSION`, `build/build.ps1`, the sources, any changed file under `tests/` and `vastai.plg` together. Push and open a pull request.
7. Try the exact commit on your own Unraid server before merging:
   ```bash
   plugin install https://raw.githubusercontent.com/0xntpower/VastAI-Unraid-Widget/<commit sha>/vastai.plg
   ```
   Reload the Dashboard and look at the tile, open the settings page, press Test Connection, and read `grep vastai /var/log/syslog | tail`. If anything in the payload changes after this, go back to step 4. The same version does not install twice.
8. Merge the pull request. That is the release.
9. Tag it:
   ```bash
   git switch main && git pull
   gh release create "v$(cat VERSION)" --target main --title "$(cat VERSION)" --notes "See the changelog on the Plugins page."
   ```
10. Confirm the channel serves it. The raw URL can lag a few minutes:
    ```bash
    curl -s https://raw.githubusercontent.com/0xntpower/VastAI-Unraid-Widget/main/vastai.plg | grep -m1 'ENTITY version'
    ```

**Why the version format matters.** Unraid compares plugin versions with `strcmp`, not a version-aware comparison, so string order alone decides whether an update is offered. The version must be fixed width, `YYYY.MM.DD.HHMM`, with `HHMM` zero-padded: `2026.09.17.0945`, never `2026.09.17.945`. A change shipped without a higher version never reaches existing installs, while new installs get it under the old number. `tests/check.sh` checks the format, that the version moved when the manifest did, and that it is not in the future.

**What an update does on a user's server.** It deletes `/usr/local/emhttp/plugins/vastai` and the cache, writes the new files and leaves `vastai.cfg` alone. A dashboard tab that was open during the update keeps the old page until reloaded.

A change to `templates/vastai.xml` or `ca_profile.xml` is a store listing change. It needs no version and takes effect when Community Applications next reads the repository.

### A bad release

1. Do not stop at `git revert`. It restores an older version string that updated users will never be offered.
2. On a branch: revert the bad source commit, add a note that says what broke, stamp a new `VERSION`, build, open a pull request, try it, merge.
3. Tell users in the forum thread which version to wait for. For someone who cannot wait:
   ```bash
   plugin remove vastai.plg
   plugin install https://raw.githubusercontent.com/0xntpower/VastAI-Unraid-Widget/v<last good version>/vastai.plg
   ```
   This blanks the saved API key. They paste it again and skip the update until the fixed version appears.
4. Add the test that would have caught it.

### Pull requests from outside

A green check on a pull request from a fork proves little. The fork's own copy of the workflow, of `tests/check.sh` and of `build/build.ps1` is what ran. Never merge a fork pull request that touches `vastai.plg`, `build/`, `tests/` or `.github/`. Apply its change under `src/` on your own branch and rebuild there.

### Repository settings

These live in GitHub, not in the tree, so they are written down here.

```bash
R=0xntpower/VastAI-Unraid-Widget
gh api -X POST "repos/$R/rulesets" --input .github/ruleset-main.json
gh api -X PUT "repos/$R/private-vulnerability-reporting"
gh api -X PATCH "repos/$R" \
  -f 'security_and_analysis[secret_scanning][status]=enabled' \
  -f 'security_and_analysis[secret_scanning_push_protection][status]=enabled'
# Actions may only run GitHub's own actions, pinned to a commit.
gh api -X PUT "repos/$R/actions/permissions" -F enabled=true -f allowed_actions=selected -F sha_pinning_required=true
gh api -X PUT "repos/$R/actions/permissions/selected-actions" -F github_owned_allowed=true -F verified_allowed=false
```

The owner account is the one key to every install. Keep two-factor authentication on it.

**If a Vast.ai key leaks.** Delete it at console.vast.ai/manage-keys before anything else, then create a restricted one. Deleting the key is the fix, wherever the copy is: a commit, a log or a flash backup.

**If the GitHub account is taken over, or `main` holds a manifest you did not write.**

1. Recover the account, then revoke sessions, tokens, SSH keys and OAuth grants.
2. Compare what users were served with what you built: `git fetch origin`, then `git diff <last tag> origin/main -- vastai.plg`.
3. Restore `main` to your own build under a `VERSION` that sorts above the attacker's.
4. Recreate the ruleset from `.github/ruleset-main.json`.
5. Post in the forum thread, pin a GitHub issue, and ask the Community Applications moderators to pull the listing until it is clean.
6. Say plainly that a server which installed the bad manifest ran someone else's code as root. An update does not undo that.

### Community Applications listing

The plugin is listed in Community Applications from two files: `ca_profile.xml` at the root and `templates/vastai.xml`, the plugin entry. `tests/check.sh` checks that both parse, that the template's `PluginURL` and `MinVer` match the manifest, that the icon paths exist and that the support link is the same in all three places. After a repository rename, change the `github` name in `build/build.ps1` first. The check then flags the template.

## License

MIT. See [LICENSE](LICENSE).
