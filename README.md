# Vast.ai Dashboard Widget for Unraid

An Unraid 6.12+ and 7.x plugin that adds a dashboard tile for your Vast.ai host machines. It displays rental status, current hourly earnings, GPU temperatures, and hardware specs in a table formatted to match Unraid's native dashboard layout.

## What it shows

- **Machine status**: Shows whether each machine is currently rented (on-demand, interruptible, or reserved), listed and waiting for clients, unlisted, or offline.
- **Earnings and rates**: Live hourly income (`$/hr`) when rented, estimated 24-hour earnings, base listed prices, and minimum bid floors.
- **Hardware metrics**: GPU model, VRAM, real-time GPU temperature, and host reliability score.
- **System specs**: An expandable "Show details" toggle displays CPU, RAM, storage, and network bandwidth.
- **Fleet summary**: The tile header shows how many machines are rented out of the total. A summary line above the table shows the combined hourly earnings rate, the projected daily figure, and your account balance when it is above zero.
- **API caching**: Server-side response caching in `/tmp/vastai_cache.json` (configurable TTL, default 30s) keeps multiple open dashboard tabs from each hitting the Vast.ai API. If the API becomes unreachable the tile falls back to the last cached response, labels it `STALE`, and backs off for 30 seconds before retrying rather than retrying on every poll.

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

## Where your API key is stored

The key is written to `/boot/config/plugins/vastai/vastai.cfg` on the Unraid flash drive,
in plaintext, with file mode `600` owned by `root`. That is the standard location for
Unraid plugin configuration and it survives reboots and plugin upgrades.

Uninstalling the plugin blanks the `API_KEY` line in that file. Your other settings are
left in place so a reinstall keeps your preferences.

The key is sent to Vast.ai over HTTPS with certificate verification enabled. The plugin
has no other network destination and no third-party runtime dependencies.
- **Show account balance**: Show or hide your account balance in the summary line.

## Building from source

`vastai.plg` is a **generated file**. Every source under `src/` is inlined into it, so edit
the files in `src/` and rebuild rather than editing `vastai.plg` directly.

Building needs PowerShell 7 (`pwsh`), which runs on Windows, Linux and macOS:

```bash
pwsh -NoProfile -File build/build.ps1
```

There was previously a second Bash generator. It was removed because two hand-maintained
copies of the same template had already drifted, and the Bash version could exit
successfully while emitting empty file bodies when a source path was wrong.

The version string lives in the `VERSION` file at the repository root and is read by the
build. It must be **fixed width**, `YYYY.MM.DD.HHMM`, with `HHMM` zero-padded to four
digits. Write `2026.09.17.0945`, never `2026.09.17.945`.

Unraid compares plugin versions with `strcmp`, not a version-aware comparison, so string
order is the only thing that decides whether an update is offered. Fixed-width fields make
string order match chronological order. Drop a leading zero and a later release can sort
below an earlier one and become permanently invisible to the update check. `tests/check.sh`
enforces the format.

### Icons

The plugin ships the same mark to two directories, because Unraid resolves them
through different code paths. `images/vastai.png` backs the Plugins page entry and
the settings banner, and `icons/vastai.png` backs the Settings and Utilities nav
entry. Both are generated from `build/assets/vastai-master.png`:

```bash
python3 build/make-icons.py
```

That crops the master to the badge and cuts the corners to transparency, because
two of the four Unraid themes are light and an opaque dark square looks wrong on
them. Needs Pillow, and only needs rerunning when the master artwork changes.

The short description shown on the Unraid Plugins page comes from
`src/vastai/usr/local/emhttp/plugins/vastai/README.md`, which is a different file
from this one and is rendered by Unraid as Markdown.

Before committing, run the checks:

```bash
./tests/check.sh
```

This verifies that `vastai.plg` regenerates identically from source, that no file body is
empty, that the icon decodes to a real PNG, and that a handful of previously shipped bugs
have not come back. The same script runs in CI.
