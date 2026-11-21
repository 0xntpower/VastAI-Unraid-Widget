#!/usr/bin/env python3
"""Installs, updates and removes the plugin from vastai.plg in a scratch
directory, following the rules of Unraid's plugin manager, and checks the result.

Run:  python3 tests/install.test.py
      python3 tests/install.test.py --update-golden   (after reading a script change)
"""
import base64, pathlib, re, shutil, subprocess, sys, tempfile
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'src/vastai/usr/local/emhttp/plugins/vastai'
GOLDEN = ROOT / 'tests/run-blocks.golden'
plg = (ROOT / 'vastai.plg').read_text(encoding='utf-8')
fails = []


def check(name, ok):
    print(('  ok    ' if ok else '  FAIL  ') + name)
    if not ok:
        fails.append(name)


# 1. Everything the manifest runs as root, compared with the reviewed copy
#    BEFORE any of it is executed here. Approving a change writes the file and
#    stops, so the new scripts are read before they are ever run.
blocks = '\n# ----\n'.join(re.findall(r'<FILE Run=[^>]*>\s*<INLINE>\n(.*?)</INLINE>', plg, re.S))
if '--update-golden' in sys.argv:
    GOLDEN.write_text(blocks, encoding='utf-8', newline='\n')
    sys.exit('  wrote tests/run-blocks.golden. Read its diff, then run the test again.')
check('root scripts match tests/run-blocks.golden', GOLDEN.exists() and GOLDEN.read_text(encoding='utf-8') == blocks)
if fails:
    sys.exit('  the install and remove scripts changed. Read them, then run with --update-golden')

# 2. Point every path the scripts touch at a scratch directory and parse the
#    manifest. Parsing is itself a check: a bare & or < in the release notes
#    breaks it.
tmp = pathlib.Path(tempfile.mkdtemp(prefix='vastai-install-')).as_posix()
redirected, n = re.subn(r'<!ENTITY (plugin|emhttp|cache|oldcache)(\s+)"', lambda m: f'<!ENTITY {m[1]}{m[2]}"{tmp}', plg)
assert n == 4, 'could not redirect every path entity, refusing to run the scripts'
try:
    tree = ET.fromstring(redirected)
except ET.ParseError as e:
    shutil.rmtree(tmp)
    sys.exit(f'  FAIL  vastai.plg is not well-formed XML: {e}')
emhttp = pathlib.Path(tmp + '/usr/local/emhttp/plugins/vastai')
cfg = pathlib.Path(tmp + '/boot/config/plugins/vastai/vastai.cfg')
cache = pathlib.Path(tmp + '/var/local/vastai/cache_0123.json')
oldcache = pathlib.Path(tmp + '/tmp/vastai_cache_0123.json')
bash = shutil.which('bash')  # by PATH. On Windows a bare "bash" can resolve to WSL.


def plugin(method):
    """/usr/local/sbin/plugin, reduced to what this manifest uses."""
    for f in tree.iter('FILE'):
        if f.get('Method', 'install') != method:
            continue
        inline = f.findtext('INLINE') or ''
        if f.get('Name'):
            p = pathlib.Path(f.get('Name'))
            if p.exists():
                continue  # no SHA256 or MD5 in this manifest, so an existing file is never replaced
            p.parent.mkdir(parents=True, exist_ok=True)
            data = (inline.strip() + '\n').encode('utf-8')
            p.write_bytes(base64.b64decode(data) if f.get('Type') == 'base64' else data)
        if f.get('Run'):
            subprocess.run([bash], input=inline.encode('utf-8'), check=True, stdout=subprocess.DEVNULL)


def tree_of(d):
    return sorted(p.relative_to(d).as_posix() for p in d.rglob('*') if p.is_file())


def same(rel):
    a, b = (emhttp / rel).read_bytes(), (SRC / rel).read_bytes()
    return a == b if rel.endswith('.png') else a.strip() == b.strip()


def leave_caches():
    for f in (cache, oldcache):
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_text('{}')


print('install')
plugin('install')
check('installs exactly the files under src/', tree_of(emhttp) == tree_of(SRC))
check('every installed file has its source content', all(same(r) for r in tree_of(emhttp) if (SRC / r).exists()))
check('creates the settings directory and writes no settings file', cfg.parent.is_dir() and not cfg.exists())
check('the version is the one in VERSION',
      tree.get('version') == (ROOT / 'VERSION').read_text().strip() == re.search(r'^###(\S+)', plg, re.M)[1])

print('update over an older install')
(emhttp / 'javascript/vastai.js').write_text('// previous release\n')
(emhttp / 'include/removed-in-this-release.php').write_text('<?php\n')
cfg.write_text('API_KEY="secret"\nINTERVAL="60"\n')
leave_caches()
plugin('install')
check('replaces a file that already existed', same('javascript/vastai.js'))
check('drops files the new release no longer ships', tree_of(emhttp) == tree_of(SRC))
check('keeps the saved settings and key', cfg.read_text() == 'API_KEY="secret"\nINTERVAL="60"\n')
check('clears the cache, and the one earlier versions kept in /tmp', not cache.parent.exists() and not oldcache.exists())

print('remove')
leave_caches()
plugin('remove')
check('removes the plugin directory', not emhttp.exists())
check('removes both caches', not cache.parent.exists() and not oldcache.exists())
check('blanks the API key and keeps the other settings', cfg.read_text() == 'API_KEY=""\nINTERVAL="60"\n')

shutil.rmtree(tmp)
print('install.test.py: ' + ('FAILED' if fails else 'ok'))
sys.exit(1 if fails else 0)
