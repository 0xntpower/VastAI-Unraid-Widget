#!/usr/bin/env bash
# Build and packaging guard for the Vast.ai Unraid plugin.
#
# Every check here exists because a real defect got shipped past it. Run with:
#   ./tests/check.sh
#
# Steps needing a tool that is not installed are skipped with a notice rather
# than failing, so this stays runnable on a dev box. CI is the real gate.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/src/vastai/usr/local/emhttp/plugins/vastai"
PLG="$ROOT/vastai.plg"

fail=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
skip() { printf '  \033[33mSKIP\033[0m  %s\n' "$1"; }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fail=1; }

echo "== source files present =="
REQUIRED=(
  default.cfg
  VastAIDashboard.page
  VastAISettings.page
  include/VastAI.php
  include/getvaststatus.php
  javascript/vastai.js
  styles/vastai.css
  images/vastai.png
)
for f in "${REQUIRED[@]}"; do
  if [ -s "$SRC/$f" ]; then pass "$f"; else bad "$f missing or empty"; fi
done

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
# the manifest must carry the same string the VERSION file declares
if grep -q "<!ENTITY version   \"$VER\">" "$PLG"; then
  pass "manifest version entity matches VERSION"
else
  bad "manifest version entity does not match VERSION, rebuild needed"
fi

echo
echo "== manifest is reproducible from source =="
# The .plg is a generated artifact that users install directly. If it does not
# regenerate identically, the committed file does not match the sources.
if command -v pwsh >/dev/null 2>&1; then
  tmp="$(mktemp -d)"
  cp "$PLG" "$tmp/committed.plg"
  pwsh -NoProfile -File "$ROOT/build/build.ps1" >/dev/null
  if cmp -s "$tmp/committed.plg" "$PLG"; then
    pass "vastai.plg matches build/build.ps1 output"
  else
    bad "vastai.plg is stale. Run build/build.ps1 and commit the result"
    diff <(head -50 "$tmp/committed.plg") <(head -50 "$PLG") | head -20 || true
  fi
  cp "$tmp/committed.plg" "$PLG"
  rm -rf "$tmp"
else
  skip "pwsh not installed, cannot verify manifest reproducibility"
fi

echo
echo "== manifest integrity =="
# An empty CDATA block means a source file failed to inline. This shipped once.
n="$(grep -c '<!\[CDATA\[\]\]>' "$PLG" || true)"
[ "$n" -eq 0 ] && pass "no empty CDATA blocks" || bad "$n empty CDATA block(s): a source failed to inline"

# Unraid skips any FILE Name whose target already exists unless a checksum
# invalidates it. INLINE files have no checksum, so without rm -rf an update
# installs nothing until the next reboot.
grep -q 'rm -rf &emhttp;' "$PLG" \
  && pass "install clears &emhttp; so updates actually apply" \
  || bad "missing 'rm -rf &emhttp;' - updates will silently install nothing"

# <BASE64> is not a .plg element. Base64 content is Type="base64" on the FILE.
if grep -q '<BASE64>' "$PLG"; then
  bad "<BASE64> is not a .plg element, use Type=\"base64\" on the FILE"
else
  pass "no unsupported <BASE64> element"
fi
grep -q 'Type="base64"' "$PLG" \
  && pass "icon declared as Type=\"base64\"" \
  || bad "icon FILE is not declared Type=\"base64\""

# A ]]> sequence inside any source would terminate its CDATA block early and
# let arbitrary XML into a manifest whose FILE Run blocks execute as root.
if grep -rq ']]>' "$SRC" 2>/dev/null; then
  bad "a source file contains ]]> which would break out of its CDATA block"
else
  pass "no CDATA-terminating sequence in sources"
fi

grep -q 'DO NOT EDIT' "$PLG" \
  && pass "generated-file banner present" \
  || bad "generated-file banner missing"

echo
echo "== every source is shipped and non-empty in the manifest =="
python3 - "$PLG" <<'PY'
import sys, re
x = open(sys.argv[1], encoding='utf-8').read()
want = ['default.cfg','VastAIDashboard.page','VastAISettings.page',
        'include/getvaststatus.php','include/VastAI.php',
        'javascript/vastai.js','styles/vastai.css','images/vastai.png']
rc = 0
for w in want:
    m = re.search(r'<FILE Name="&emhttp;/%s"[^>]*>\s*<INLINE>(.*?)</INLINE>' % re.escape(w), x, re.S)
    if not m:
        print('  \033[31mFAIL\033[0m  %s not present in manifest' % w); rc = 1
    elif len(m.group(1).strip()) < 20:
        print('  \033[31mFAIL\033[0m  %s inlined but effectively empty' % w); rc = 1
    else:
        print('  \033[32mPASS\033[0m  %s (%d bytes inlined)' % (w, len(m.group(1).strip())))
sys.exit(rc)
PY

echo
echo "== the icon is a real PNG =="
python3 - "$PLG" <<'PY'
import sys, re, base64
x = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'<FILE Name="&emhttp;/images/vastai\.png" Type="base64">\s*<INLINE>(.*?)</INLINE>', x, re.S)
if not m:
    print('  \033[31mFAIL\033[0m  icon FILE not found'); sys.exit(1)
raw = base64.b64decode(m.group(1).strip())
if raw[:8] == b'\x89PNG\r\n\x1a\n':
    print('  \033[32mPASS\033[0m  icon decodes to a %d byte PNG' % len(raw))
else:
    print('  \033[31mFAIL\033[0m  icon does not decode to a PNG'); sys.exit(1)
PY

echo
echo "== syntax =="
if command -v php >/dev/null 2>&1; then
  for f in "$SRC"/include/*.php; do
    php -l "$f" >/dev/null && pass "php -l $(basename "$f")" || bad "php -l $(basename "$f")"
  done
else
  skip "php not installed, cannot lint PHP"
fi
if command -v node >/dev/null 2>&1; then
  node --check "$SRC/javascript/vastai.js" && pass "node --check vastai.js" || bad "node --check vastai.js"
else
  skip "node not installed, cannot check JS"
fi

echo
echo "== regressions with a known cause =="
# gpu_occupancy is a per-GPU string. Comparing it to a single character can
# never match a multi-GPU host, and shipped as a real misclassification bug.
if grep -qE "occupancy_code (===|==) '[DRI]'" "$SRC/include/getvaststatus.php" \
   || grep -qE "occupancy_code === '[DRI]'" "$SRC/javascript/vastai.js"; then
  bad "single-character comparison against gpu_occupancy has come back"
else
  pass "gpu_occupancy is not compared as a single character"
fi

# test_key over GET is the only path Unraid does not CSRF-validate.
if grep -q "_GET\['test_key'\]" "$SRC/include/getvaststatus.php"; then
  bad "test_key is accepted over GET again, which bypasses CSRF validation"
else
  pass "test_key is POST-only"
fi

# Every var() must name a variable Unraid actually defines, or themes break.
if grep -qE 'var\(--(text-main|text-muted|control-bg)' "$SRC/styles/vastai.css"; then
  bad "CSS references variables Unraid does not define"
else
  pass "CSS references only defined Unraid theme variables"
fi

echo
if [ "$fail" -eq 0 ]; then
  printf '\033[32mAll checks passed.\033[0m\n'
else
  printf '\033[31mChecks failed.\033[0m\n'
fi
exit "$fail"
