#!/usr/bin/env python3
"""
Generate the plugin icon assets from the master artwork.

The master is a 128x128 opaque render with the badge inset inside dark padding.
Unraid draws these icons directly on the page background and two of its four
themes are light, so an opaque dark square reads as a misplaced block. This
crops to the badge and cuts the corners to transparency.

Outputs, both RGBA:
  src/.../images/vastai.png   128x128  Plugins page entry and the settings banner
  src/.../icons/vastai.png     48x48   Settings > Utilities nav entry

Requires Pillow. Run:  python3 build/make-icons.py
"""
import os
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUG = os.path.join(ROOT, 'src', 'vastai', 'usr', 'local', 'emhttp', 'plugins', 'vastai')
MASTER = os.path.join(ROOT, 'build', 'assets', 'vastai-master.png')

# Badge bounds inside the master, found by scanning inward from each edge for
# the first row or column containing a pixel brighter than the dark padding.
BBOX = (14, 13, 115, 114)
SS = 8             # supersample factor, for a smooth corner cut
RADIUS_PCT = 0.22  # corner radius as a fraction of the badge edge


def rounded_alpha(size, radius_pct=RADIUS_PCT):
    """A rounded-rectangle alpha mask, antialiased by supersampling."""
    big = size * SS
    mask = Image.new('L', (big, big), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, big - 1, big - 1), radius=int(big * radius_pct), fill=255)
    return mask.resize((size, size), Image.LANCZOS)


def rel(path):
    return os.path.relpath(path, ROOT).replace(os.sep, '/')


def build(size, out):
    badge = Image.open(MASTER).convert('RGB').crop(BBOX)
    badge = badge.resize((size, size), Image.LANCZOS).convert('RGBA')
    badge.putalpha(rounded_alpha(size))
    os.makedirs(os.path.dirname(out), exist_ok=True)
    badge.save(out, 'PNG', optimize=True)
    print('  %-44s %3dx%-4d %6d bytes' % (rel(out), size, size, os.path.getsize(out)))


if __name__ == '__main__':
    print('Generating icon assets from %s' % rel(MASTER))
    build(128, os.path.join(PLUG, 'images', 'vastai.png'))
    build(48, os.path.join(PLUG, 'icons', 'vastai.png'))
