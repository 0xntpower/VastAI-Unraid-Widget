#!/usr/bin/env python3
"""
Local Unraid Simulation Preview Server
Serves the Vast.ai Dashboard Widget in an authentic Unraid webGUI shell,
simulating getvaststatus.php with live or scenario data using multi-threaded HTTP.
"""

from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import urllib.parse
import urllib.request
import time
import sys

PORT = 8099
ROOT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(ROOT_DIR)
VAST_PLUGIN_DIR = os.path.join(PROJECT_ROOT, 'src', 'vastai', 'usr', 'local', 'emhttp', 'plugins', 'vastai')

# Try to get live API key from ~/.config/vastai/vast_api_key
LIVE_API_KEY = ""
api_key_file = os.path.expanduser('~/.config/vastai/vast_api_key')
if os.path.exists(api_key_file):
    try:
        with open(api_key_file, 'r', encoding='utf-8') as f:
            LIVE_API_KEY = f.read().strip()
    except Exception:
        pass

# In-memory cache for live data
_live_cache = None
_live_cache_time = 0
CACHE_TTL = 15  # seconds


def fetch_live_vast_data():
    global _live_cache, _live_cache_time
    now = time.time()
    if _live_cache and (now - _live_cache_time) < CACHE_TTL:
        return _live_cache

    if not LIVE_API_KEY:
        return None

    try:
        mach_url = f"https://console.vast.ai/api/v0/machines?owner=me&api_key={LIVE_API_KEY}"
        user_url = f"https://console.vast.ai/api/v0/users/current?api_key={LIVE_API_KEY}"

        req = urllib.request.Request(mach_url, headers={'User-Agent': 'VastAI-Unraid-Widget/1.0'})
        with urllib.request.urlopen(req, timeout=7) as resp:
            mach_data = json.loads(resp.read().decode())

        req_user = urllib.request.Request(user_url, headers={'User-Agent': 'VastAI-Unraid-Widget/1.0'})
        user_data = {}
        try:
            with urllib.request.urlopen(req_user, timeout=5) as resp_user:
                user_data = json.loads(resp_user.read().decode())
        except Exception:
            pass

        machines = mach_data.get('machines', [])
        processed = []
        total_earn_hour = 0.0
        total_earn_day = 0.0
        rented_count = 0
        total_gpus = 0
        rented_gpus = 0

        for m in machines:
            mach_id = m.get('id') or m.get('machine_id') or 0
            hostname = m.get('hostname') or f"Machine #{mach_id}"
            num_gpus = max(1, int(m.get('num_gpus') or 1))
            total_gpus += num_gpus

            gpu_name = m.get('gpu_name', 'GPU').replace('NVIDIA ', '')
            gpu_ram_mb = int(m.get('gpu_ram') or 0)
            gpu_ram_gb = round(gpu_ram_mb / 1024, 1) if gpu_ram_mb > 0 else 0

            listed = bool(m.get('listed'))
            timeout = float(m.get('timeout') or 0)
            is_online = (timeout <= 0.0)

            running_rentals = int(m.get('current_rentals_running') or 0)
            resident_rentals = int(m.get('current_rentals_resident') or 0)
            on_demand_rentals = int(m.get('current_rentals_running_on_demand') or 0)
            reserved_rentals = int(m.get('current_rentals_running_reserved') or 0)
            occupancy_code = (m.get('gpu_occupancy') or '').strip().upper()

            is_rented = (running_rentals > 0) or bool(occupancy_code)

            if not is_online:
                status_key = 'offline'
                status_label = 'Offline'
                status_color = 'red'
            elif is_rented:
                rented_count += 1
                rented_gpus += num_gpus
                if on_demand_rentals > 0 or occupancy_code == 'D':
                    status_key = 'rented_on_demand'
                    status_label = 'Rented (On-Demand)'
                    status_color = 'green'
                elif reserved_rentals > 0 or occupancy_code == 'R':
                    status_key = 'rented_reserved'
                    status_label = 'Rented (Reserved)'
                    status_color = 'purple'
                elif occupancy_code == 'I' or running_rentals > 0:
                    status_key = 'rented_interruptible'
                    status_label = 'Rented (Interruptible)'
                    status_color = 'yellow'
                else:
                    status_key = 'rented'
                    status_label = 'Rented'
                    status_color = 'green'
            elif listed:
                status_key = 'listed'
                status_label = 'Listed & Available'
                status_color = 'blue'
            else:
                status_key = 'unlisted'
                status_label = 'Unlisted'
                status_color = 'gray'

            earn_hour = float(m.get('earn_hour') or 0.0)
            earn_day = float(m.get('earn_day') or 0.0)
            total_earn_hour += earn_hour
            total_earn_day += earn_day

            cpu_name = (m.get('cpu_name') or '').replace('Processor', '').replace('12-Cores', '').strip()

            processed.append({
                'id': mach_id,
                'hostname': hostname,
                'num_gpus': num_gpus,
                'gpu_name': gpu_name,
                'gpu_ram_gb': gpu_ram_gb,
                'gpu_temp': round(float(m.get('gpu_max_cur_temp')), 1) if m.get('gpu_max_cur_temp') is not None else None,
                'reliability': round(float(m.get('reliability2')) * 100, 1) if m.get('reliability2') is not None else None,
                'verification': m.get('verification', 'unverified'),
                'is_online': is_online,
                'listed': listed,
                'is_rented': is_rented,
                'status_key': status_key,
                'status_label': status_label,
                'status_color': status_color,
                'occupancy_code': occupancy_code,
                'running_rentals': running_rentals,
                'resident_rentals': resident_rentals,
                'earn_hour': earn_hour,
                'earn_day': earn_day,
                'listed_gpu_cost': float(m.get('listed_gpu_cost') or 0.0),
                'min_bid_price': float(m.get('min_bid_price') or 0.0),
                'cpu_name': cpu_name,
                'cpu_cores': int(m.get('cpu_cores') or 0),
                'cpu_ram_gb': round(int(m.get('cpu_ram') or 0) / 1024, 1),
                'disk_space_gb': float(m.get('disk_space') or 0),
                'avail_disk_gb': float(m.get('avail_disk_space') or 0),
                'inet_down_mbps': round(float(m.get('inet_down') or 0), 1),
                'inet_up_mbps': round(float(m.get('inet_up') or 0), 1),
            })

        result = {
            'success': True,
            'summary': {
                'total_machines': len(processed),
                'rented_machines': rented_count,
                'total_gpus': total_gpus,
                'rented_gpus': rented_gpus,
                'total_earn_hour': round(total_earn_hour, 4),
                'total_earn_day': round(total_earn_day, 4),
                'account_balance': round(float(user_data.get('balance') or 0.0), 2),
                'account_credit': round(float(user_data.get('credit') or 0.0), 2),
            },
            'machines': processed
        }
        _live_cache = result
        _live_cache_time = now
        return result
    except Exception as e:
        print(f"Error fetching live data: {e}", file=sys.stderr)
        if _live_cache:
            return _live_cache
        return None


def get_scenario_data(scenario):
    if scenario == 'live':
        live = fetch_live_vast_data()
        if live:
            return live

    if scenario == 'multi':
        return {
            'success': True,
            'summary': {
                'total_machines': 3,
                'rented_machines': 2,
                'total_gpus': 10,
                'rented_gpus': 9,
                'total_earn_hour': 24.845,
                'total_earn_day': 596.28,
                'account_balance': 482.50,
            },
            'machines': [
                {
                    'id': 148920,
                    'hostname': 'ai-datacenter-node1',
                    'num_gpus': 8,
                    'gpu_name': 'H100 SXM5 80GB',
                    'gpu_ram_gb': 80.0,
                    'gpu_temp': 62.4,
                    'reliability': 99.9,
                    'verification': 'verified',
                    'is_online': True,
                    'listed': True,
                    'is_rented': True,
                    'status_key': 'rented_on_demand',
                    'status_label': 'Rented (On-Demand)',
                    'status_color': 'green',
                    'occupancy_code': 'D',
                    'running_rentals': 1,
                    'earn_hour': 23.20,
                    'earn_day': 556.80,
                    'listed_gpu_cost': 2.90,
                    'min_bid_price': 1.85,
                    'cpu_name': 'AMD EPYC 9654 96-Core',
                    'cpu_cores': 96,
                    'cpu_ram_gb': 1024.0,
                    'disk_space_gb': 4000.0,
                    'inet_down_mbps': 9850.0,
                    'inet_up_mbps': 9850.0,
                },
                {
                    'id': 142293,
                    'hostname': 'vast-gpu',
                    'num_gpus': 1,
                    'gpu_name': 'RTX 3060 Ti',
                    'gpu_ram_gb': 8.0,
                    'gpu_temp': 70.0,
                    'reliability': 99.0,
                    'verification': 'unverified',
                    'is_online': True,
                    'listed': True,
                    'is_rented': True,
                    'status_key': 'rented_interruptible',
                    'status_label': 'Rented (Interruptible)',
                    'status_color': 'yellow',
                    'occupancy_code': 'I',
                    'running_rentals': 1,
                    'earn_hour': 0.0147,
                    'earn_day': 0.198,
                    'listed_gpu_cost': 0.047,
                    'min_bid_price': 0.02,
                    'cpu_name': 'Ryzen Threadripper PRO 3945WX',
                    'cpu_cores': 4,
                    'cpu_ram_gb': 7.7,
                    'disk_space_gb': 35.0,
                    'inet_down_mbps': 356.8,
                    'inet_up_mbps': 110.3,
                },
                {
                    'id': 139102,
                    'hostname': 'render-station-02',
                    'num_gpus': 1,
                    'gpu_name': 'RTX 4090 24GB',
                    'gpu_ram_gb': 24.0,
                    'gpu_temp': 39.0,
                    'reliability': 98.4,
                    'verification': 'verified',
                    'is_online': True,
                    'listed': True,
                    'is_rented': False,
                    'status_key': 'listed',
                    'status_label': 'Listed & Available',
                    'status_color': 'blue',
                    'occupancy_code': '',
                    'running_rentals': 0,
                    'earn_hour': 0.0,
                    'earn_day': 0.0,
                    'listed_gpu_cost': 0.45,
                    'min_bid_price': 0.28,
                    'cpu_name': 'Intel Core i9-14900K',
                    'cpu_cores': 24,
                    'cpu_ram_gb': 64.0,
                    'disk_space_gb': 500.0,
                    'inet_down_mbps': 940.0,
                    'inet_up_mbps': 940.0,
                }
            ]
        }

    if scenario == 'offline':
        return {
            'success': True,
            'summary': {
                'total_machines': 1,
                'rented_machines': 0,
                'total_gpus': 1,
                'rented_gpus': 0,
                'total_earn_hour': 0.0,
                'total_earn_day': 0.0,
                'account_balance': 11.46,
            },
            'machines': [
                {
                    'id': 142293,
                    'hostname': 'vast-gpu',
                    'num_gpus': 1,
                    'gpu_name': 'RTX 3060 Ti',
                    'gpu_ram_gb': 8.0,
                    'gpu_temp': None,
                    'reliability': 92.1,
                    'verification': 'unverified',
                    'is_online': False,
                    'listed': True,
                    'is_rented': False,
                    'status_key': 'offline',
                    'status_label': 'Offline',
                    'status_color': 'red',
                    'earn_hour': 0.0,
                    'earn_day': 0.0,
                    'listed_gpu_cost': 0.047,
                    'min_bid_price': 0.02,
                    'cpu_name': 'Ryzen Threadripper PRO',
                    'cpu_cores': 4,
                    'cpu_ram_gb': 7.7,
                    'disk_space_gb': 35.0,
                    'inet_down_mbps': 0.0,
                    'inet_up_mbps': 0.0,
                }
            ]
        }

    if scenario == 'unconfigured':
        return {
            'success': False,
            'not_configured': True,
            'error': 'Vast.ai API Key is not configured. Please open Settings → Utilities → Vast.ai Monitor to enter your API key.'
        }

    live = fetch_live_vast_data()
    if live:
        return live
    return get_scenario_data('multi')


current_scenario = 'live'


class UnraidPreviewHandler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', '*')
        self.send_header('Cache-Control', 'no-cache, no-store, must-revalidate')
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.end_headers()

    def do_GET(self):
        global current_scenario
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        query = urllib.parse.parse_qs(parsed.query)

        if 'scenario' in query:
            current_scenario = query['scenario'][0]

        # Handle API endpoint: both relative and full paths
        if path.endswith('getvaststatus.php') or path == '/api/status':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.end_headers()
            data = get_scenario_data(current_scenario)
            self.wfile.write(json.dumps(data).encode('utf-8'))
            return

        # Serve styles/vastai.css
        if path.endswith('/styles/vastai.css') or path == '/plugins/vastai/styles/vastai.css':
            css_path = os.path.join(VAST_PLUGIN_DIR, 'styles', 'vastai.css')
            self.send_response(200)
            self.send_header('Content-Type', 'text/css; charset=utf-8')
            self.end_headers()
            with open(css_path, 'rb') as f:
                self.wfile.write(f.read())
            return

        # Serve javascript/vastai.js
        if path.endswith('/javascript/vastai.js') or path == '/plugins/vastai/javascript/vastai.js':
            js_path = os.path.join(VAST_PLUGIN_DIR, 'javascript', 'vastai.js')
            self.send_response(200)
            self.send_header('Content-Type', 'application/javascript; charset=utf-8')
            self.end_headers()
            with open(js_path, 'rb') as f:
                self.wfile.write(f.read())
            return

        # Serve images/vastai.png
        if 'vastai.png' in path or 'vastai-small.png' in path:
            img_name = os.path.basename(path)
            img_path = os.path.join(VAST_PLUGIN_DIR, 'images', img_name)
            if os.path.exists(img_path):
                self.send_response(200)
                self.send_header('Content-Type', 'image/png')
                self.end_headers()
                with open(img_path, 'rb') as f:
                    self.wfile.write(f.read())
                return

        # Default serve index.html
        if path == '/' or path == '/index.html':
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.end_headers()
            index_path = os.path.join(ROOT_DIR, 'index.html')
            with open(index_path, 'rb') as f:
                self.wfile.write(f.read())
            return

        super().do_GET()


if __name__ == '__main__':
    os.chdir(ROOT_DIR)
    # Pre-fetch live data at startup so the first request is instant!
    print("Pre-fetching initial Vast.ai data...")
    fetch_live_vast_data()
    server = ThreadingHTTPServer(("", PORT), UnraidPreviewHandler)
    server.daemon_threads = True
    print(f"Threading preview server running on http://localhost:{PORT}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()
