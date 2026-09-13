# Vast.ai Dashboard Widget for Unraid

An Unraid 6.12+ and 7.x plugin that adds a dashboard tile for your Vast.ai host machines. It displays rental status, current hourly earnings, temperatures, and hardware specs in a table formatted to match Unraid's native dashboard layout.

## What it shows

- **Machine status**: Shows whether each machine is currently rented (on-demand, interruptible, or reserved), listed and waiting for clients, unlisted, or offline.
- **Earnings and rates**: Live hourly income (`$/hr`) when rented, estimated 24-hour earnings, base listed prices, and minimum bid floors.
- **Hardware metrics**: GPU model, VRAM, real-time GPU temperature, and host reliability score.
- **System specs**: An expandable "Show details" toggle displays CPU, RAM, storage, and network bandwidth.
- **Fleet summary**: Header bar shows total machines, total GPUs, active rentals count, total earnings rate, and account balance.
- **API caching**: Server-side response caching in `/tmp/vastai_cache.json` (configurable TTL, default 30s) prevents hitting Vast.ai rate limits when multiple dashboard tabs are open.

## Installation

### Install via plugin URL

1. In the Unraid webGUI, open **Plugins** > **Install Plugin**.
2. Paste the URL:
   ```
   https://raw.githubusercontent.com/0xntpower/VastAI-Unraid-Widget/main/vastai.plg
   ```
3. Click **Install**.
4. Go to **Settings** > **Utilities** > **Vast.ai Monitor**.
5. Paste your API key from [console.vast.ai/manage-keys](https://console.vast.ai/manage-keys/) and click **Apply**.

### Manual install

1. Copy `vastai.plg` to `/boot/config/plugins/vastai.plg` on your flash drive.
2. In the Unraid terminal, run:
   ```bash
   plugin install /boot/config/plugins/vastai.plg
   ```

## Configuration

Settings are in the Unraid webGUI under **Settings** > **Utilities** > **Vast.ai Monitor**:

- **API key**: Your Vast.ai API key. Includes a "Test Connection" button to verify your key and preview detected machines before saving.
- **Refresh interval**: How often the dashboard tile updates in the background (10s, 30s, 60s, 2m, or off).
- **Cache TTL**: How long cached API data stays valid before fetching fresh data (15s, 30s, 60s, or 2m).
- **Dashboard placement**: Column 1 (left), Column 2 (middle), or Column 3 (right).
- **Display toggles**: Show or hide account balance, temperatures, and hardware specs.

## Building from source

To regenerate `vastai.plg` from the files in `src/`:

**PowerShell (Windows):**
```powershell
.\build\build.ps1
```

**Bash (Linux / macOS):**
```bash
./build/build.sh
```
