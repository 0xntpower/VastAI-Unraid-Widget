#!/usr/bin/env bash
# The gate for the Vast.ai Unraid plugin. A merge to main is a release that
# every install picks up and runs as root, and the ruleset on main requires
# this script to pass in CI first. Run it before every commit:
#   ./tests/check.sh
#
# Needs bash, git and python3. A step whose tool is missing (pwsh, node, php)
# is skipped with a notice on a dev box, so "All checks passed" there can mean
# less than it says. In CI a skipped step is a failure. Without a local php the
# PHP steps run in the php:8.2-cli-alpine image when docker is available.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REL="src/vastai/usr/local/emhttp/plugins/vastai"
SRC="$ROOT/$REL"
PLG="$ROOT/vastai.plg"
cd "$ROOT"

fail=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fail=1; }
# In CI every tool is preinstalled, so a skipped step there is a failed step.
skip() { printf '  \033[33mSKIP\033[0m  %s\n' "$1"; [ -z "${CI:-}" ] || fail=1; }
# set -e can stop the script between two checks. Never let it do that silently.
trap 'bad "check.sh stopped at line $LINENO"; exit 1' ERR
# Runs a test and prints its output only when it fails.
run() { local out; if out="$("${@:2}" 2>&1)"; then pass "$1"; else bad "$1"; printf '%s\n' "$out"; fi; }

# php, from the box or from a container. Arguments are paths relative to $ROOT.
if command -v php >/dev/null 2>&1; then
  php_() { php "$@"; }
  HAVE_PHP="php $(php -r 'echo PHP_VERSION;')"
elif docker info >/dev/null 2>&1; then
  php_() { MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/repo" -w /repo php:8.2-cli-alpine php "$@"; }
  HAVE_PHP="php 8.2 in docker"
else
  HAVE_PHP=""
fi

echo "== source files =="
empty="$(find "$SRC" -type f -size 0)"
[ -z "$empty" ] && pass "no empty file under src/" || bad "empty source file: $empty"
set +e
python3 - "$SRC" <<'PY'
import sys, pathlib
rc = 0
for p in sorted(pathlib.Path(sys.argv[1]).rglob('*.png')):
    ok = p.read_bytes()[:8] == b'\x89PNG\r\n\x1a\n'
    print('  \033[%s\033[0m  %s is a PNG' % ('32mPASS' if ok else '31mFAIL', p.name if ok else p))
    rc |= not ok
sys.exit(rc)
PY
[ $? -eq 0 ] || fail=1
set -e

echo
echo "== version string =="
# Unraid compares plugin versions with strcmp, not version_compare. That is only
# monotonic if every field is fixed width, so HHMM must be zero-padded to four
# digits. Mixing 0945 and 945 makes a later release sort older and become
# permanently invisible to the update check.
VER="$(tr -d '[:space:]' < "$ROOT/VERSION")"
if printf '%s' "$VER" | grep -qE '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{4}$'; then
  pass "VERSION is $VER (fixed-width YYYY.MM.DD.HHMM)"
else
  bad "VERSION is '$VER', must be fixed-width YYYY.MM.DD.HHMM with zero-padded HHMM"
fi
if grep -qE "<!ENTITY version +\"$VER\">" "$PLG"; then
  pass "manifest version entity matches VERSION"
else
  bad "manifest version entity does not match VERSION, rebuild needed"
fi
# A version stamped far ahead outranks every honest one until that date comes.
if [[ "$VER" < "$(date -u -d '+1 day' +%Y.%m.%d.%H%M)" ]]; then
  pass "VERSION is not in the future"
else
  bad "VERSION $VER is more than a day ahead of the clock. Stamp it with: date -u +%Y.%m.%d.%H%M"
fi

echo
echo "== manifest is reproducible from source =="
# The .plg is a generated file that users install directly. If it does not
# regenerate identically, the committed file does not match the sources.
if command -v pwsh >/dev/null 2>&1; then
  tmp="$(mktemp)"
  if pwsh -NoProfile -File "$ROOT/build/build.ps1" -OutFile "$tmp" >/dev/null && cmp -s "$tmp" "$PLG"; then
    pass "vastai.plg matches build/build.ps1 output"
  else
    bad "vastai.plg is stale or the build failed. Run build/build.ps1 and commit the result"
  fi
  rm -f "$tmp"
else
  skip "pwsh not installed, cannot verify manifest reproducibility"
fi

echo
echo "== manifest integrity =="
# An empty CDATA block means a source file failed to inline. This shipped once.
n="$(grep -c '<!\[CDATA\[\]\]>' "$PLG" || true)"
[ "$n" -eq 0 ] && pass "no empty CDATA blocks" || bad "$n empty CDATA block(s): a source failed to inline"

# <BASE64> is not a .plg element. Base64 content is Type="base64" on the FILE.
if grep -q '<BASE64>' "$PLG"; then
  bad "<BASE64> is not a .plg element, use Type=\"base64\" on the FILE"
else
  pass "no unsupported <BASE64> element"
fi

# A CDATA terminator inside any source would break out of its block and let
# arbitrary XML into a manifest whose FILE Run blocks execute as root.
if grep -rq ']]>' "$SRC" 2>/dev/null; then
  bad "a source file contains a CDATA terminator, which would break out of its block"
else
  pass "no CDATA-terminating sequence in sources"
fi

grep -q 'DO NOT EDIT' "$PLG" \
  && pass "generated-file banner present" \
  || bad "generated-file banner missing"

echo
echo "== icon and description wiring =="
# Unraid draws the Plugins page entry and the Settings entry from images/.
# icons/ holds the smaller copy the plugin's own tile header uses.
grep -q 'icon="vastai.png"' "$PLG" \
  && pass "Plugins page entry names the brand icon" \
  || bad "PLUGIN icon attribute is not vastai.png"
grep -q '^Icon="vastai.png"' "$SRC/VastAISettings.page" \
  && pass "Settings entry declares Icon=vastai.png" \
  || bad "settings page Icon attribute is not vastai.png"
grep -q 'src="/plugins/vastai/icons/vastai.png"' "$SRC/include/VastAI.php" && [ -s "$SRC/icons/vastai.png" ] \
  && pass "the tile glyph exists where the tile looks for it" \
  || bad "the tile header points at an icon that is not shipped"
[ "$(wc -c < "$SRC/README.md")" -gt 10 ] \
  && pass "Plugins page description ships" \
  || bad "README.md is empty, the Plugins page falls back to the bare name"

echo
echo "== Community Applications submission files =="
# CA blocks finalization without a ca_profile.xml carrying a non-empty Profile,
# and a plugin entry needs a PluginURL that resolves to the live manifest.
set +e
python3 - "$ROOT" <<'PY'
import sys, os, re
import xml.dom.minidom as dom
root = sys.argv[1]
rc = 0
E = chr(27)
def ok(m):  print('  %s[32mPASS%s[0m  %s' % (E, E, m))
def no(m):
    global rc
    print('  %s[31mFAIL%s[0m  %s' % (E, E, m)); rc = 1
def read(rel):
    return open(os.path.join(root, rel), encoding='utf-8').read()

for rel in ['ca_profile.xml', 'templates/vastai.xml']:
    try:
        dom.parse(os.path.join(root, rel))
        ok(rel + ' parses as XML')
    except Exception as e:
        no(rel + ' is missing or not well formed: ' + str(e))

m = re.search(r'<Profile>(.*?)</Profile>', read('ca_profile.xml'), re.S)
if m and len(m.group(1).strip()) > 40:
    ok('ca_profile.xml Profile section is non-empty (%d chars)' % len(m.group(1).strip()))
else:
    no('ca_profile.xml Profile is empty or too short, CA will block submission')

tpl, plg = read('templates/vastai.xml'), read('vastai.plg')
u = re.search(r'<PluginURL>(.*?)</PluginURL>', tpl)
gh = re.search(r'<!ENTITY github\s+"(.*?)"', plg)
expect = 'https://raw.githubusercontent.com/' + gh.group(1) + '/main/vastai.plg' if gh else None
if u and u.group(1).strip() == expect:
    ok('template PluginURL matches the manifest pluginURL')
else:
    no('template PluginURL is %s, expected %s' % (u and u.group(1).strip(), expect))

# CA hides a plugin from an Unraid older than MinVer. It has to say what the
# manifest says, or CA offers an install the plugin manager then refuses.
mv = re.search(r'<MinVer>(.*?)</MinVer>', tpl)
mn = re.search(r'\bmin="([^"]+)"', plg)
if mv and mn and mv.group(1).strip() == mn.group(1):
    ok('template MinVer matches the manifest min (%s)' % mn.group(1))
else:
    no('template MinVer is %s, manifest min is %s' % (mv and mv.group(1), mn and mn.group(1)))

# The store icons are fetched from the repository by path.
for rel in ['ca_profile.xml', 'templates/vastai.xml']:
    for url in re.findall(r'<Icon>(.*?)</Icon>', read(rel)):
        path = url.split('/main/', 1)[-1]
        if os.path.isfile(os.path.join(root, path)):
            ok('%s icon exists in the tree' % rel)
        else:
            no('%s icon URL points at %s, which is not in the tree' % (rel, path))
sys.exit(rc)
PY
[ $? -eq 0 ] || fail=1
set -e

# The support URL appears in three files that Unraid and CA read separately.
# Liveness is deliberately not checked here, because a thread can sit in
# moderation and CI should not depend on that. Consistency is checked instead.
SUP_PLG=$(grep -oE '<!ENTITY support +"[^"]*"' "$PLG" | cut -d'"' -f2 || true)
SUP_TPL=$(grep -oE '<Support>[^<]*</Support>' "$ROOT/templates/vastai.xml" | cut -d'>' -f2 | cut -d'<' -f1 || true)
SUP_PRO=$(grep -oE '<Forum>[^<]*</Forum>' "$ROOT/ca_profile.xml" | cut -d'>' -f2 | cut -d'<' -f1 || true)
if [ -n "$SUP_PLG" ] && [ "$SUP_PLG" = "$SUP_TPL" ] && [ "$SUP_PLG" = "$SUP_PRO" ]; then
  pass "support URL is identical in the manifest, CA template and CA profile"
else
  bad "support URL differs: manifest='$SUP_PLG' template='$SUP_TPL' profile='$SUP_PRO'"
fi

echo
echo "== syntax =="
if [ -n "$HAVE_PHP" ]; then
  # .page files are PHP below their header and are linted like the rest.
  for f in "$REL"/include/*.php "$REL"/*.page tests/*.php; do
    run "php -l $(basename "$f")  ($HAVE_PHP)" php_ -l "$f"
  done
else
  skip "no php and no docker, cannot lint PHP"
fi
if command -v node >/dev/null 2>&1; then
  run "node --check vastai.js" node --check "$SRC/javascript/vastai.js"
else
  skip "node not installed, cannot check JS"
fi

echo
echo "== behavior =="
if command -v node >/dev/null 2>&1; then
  run "tile: states, money, escaping, polling" node "$ROOT/tests/tile.test.js"
  run "settings page: Test Connection" node "$ROOT/tests/settings.test.js"
else
  skip "node not installed, tile and settings tests not run"
fi
run "install, update and remove, run from the manifest" python3 "$ROOT/tests/install.test.py"
if [ -n "$HAVE_PHP" ]; then
  run "endpoint: every answer, cache files, calls to Vast.ai" php_ tests/endpoint.test.php
  run "tile shell and settings reader" php_ tests/shell.test.php
else
  skip "no php and no docker, endpoint and shell tests not run"
fi

echo
echo "== version moves with the manifest =="
# Unraid offers an update only when the version string grows. A changed manifest
# under an unchanged version reaches new installs and no existing one.
BASE="${BASE_REF:-origin/main}"
if git -C "$ROOT" rev-parse -q --verify "$BASE^{commit}" >/dev/null 2>&1; then
  OLD="$(git -C "$ROOT" show "$BASE:VERSION" | tr -d '[:space:]')"
  if git -C "$ROOT" diff --quiet "$BASE" -- vastai.plg; then
    pass "manifest unchanged since $BASE"
  elif [[ "$VER" > "$OLD" ]]; then
    pass "manifest changed and VERSION moved from $OLD to $VER"
    grep -qx "###$OLD" "$PLG" \
      && pass "the notes for $OLD kept their own heading" \
      || bad "the release notes have no '###$OLD' heading, so the notes for $OLD were relabelled or lost"
  else
    bad "vastai.plg changed since $BASE but VERSION is still $OLD"
  fi
else
  skip "no $BASE to compare with (set BASE_REF, and fetch history in CI)"
fi

echo
echo "== text contracts =="
# These are text checks and say so. Each guards a name that two files must
# agree on, or a line that no test can execute.

# Every setting needs a form field to change it and a reader that uses it.
# Three settings once had a field and no reader.
for k in $(grep -oE '^[A-Z_]+' "$SRC/default.cfg"); do
  grep -q "'$k'" "$SRC/include/config.php" && grep -q "name=\"$k\"" "$SRC/VastAISettings.page" \
    && pass "setting $k has a form field and a reader" \
    || bad "setting $k is not both editable and read"
done

# The curl call cannot be run by a test without a TLS server, so its two
# promises are read here. A key in a URL lands in every log on the way.
if grep -q 'api_key=' "$SRC/include/getvaststatus.php"; then
  bad "the API key is put in a URL again, send it as an Authorization header"
else
  pass "the API key is sent as a header, not in a URL"
fi
if [ "$(grep -cE 'CURLOPT_(REDIR_)?PROTOCOLS +=> CURLPROTO_HTTPS' "$SRC/include/getvaststatus.php")" -eq 2 ] \
   && grep -qE 'CURLOPT_SSL_VERIFYPEER +=> true' "$SRC/include/getvaststatus.php"; then
  pass "requests and redirects are HTTPS only, with certificate checks"
else
  bad "the curl options no longer restrict requests to verified HTTPS"
fi

# Every var() must name a variable Unraid defines. All five exist in each of
# the four themes of 7.4.1, and first appeared in 7.1.0.
unknown="$(grep -oE 'var\(--[a-z0-9-]+' "$SRC/styles/vastai.css" | sort -u \
  | grep -vxE 'var\(--(text-color|alt-text-color|border-color|mild-background-color|link-text-color)' || true)"
if [ -z "$unknown" ]; then
  pass "CSS uses only the five theme variables on the checked list"
else
  bad "CSS uses variables that are not on the checked list: $(echo $unknown)"
fi

echo
if [ "$fail" -eq 0 ]; then
  printf '\033[32mAll checks passed.\033[0m\n'
else
  printf '\033[31mChecks failed.\033[0m\n'
fi
exit "$fail"
