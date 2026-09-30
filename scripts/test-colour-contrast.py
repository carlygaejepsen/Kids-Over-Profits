#!/usr/bin/env python3
"""Check the palette's contrast, and that accent colours are not used as text.

    python scripts/test-colour-contrast.py

The campaign palette is built for borders, fills and marks. Measured against
sand, the ground most of the site uses, none of the four accents is legible as
body text:

    teal #33A7B5  2.46    orange #EF9034  2.07
    coral #FE8088 2.09    chartreuse #B2E102  1.32

WCAG AA asks 4.5 for body text. css/colors.css carries an ink version of each
accent - the same hue and saturation, darkened until it passes on white, sand
and the pastel yellow - and text is supposed to use the ink while everything
else keeps the accent.

This checks both halves of that: the inks really do pass, and no rule in any
stylesheet sets `color:` to a display accent. A rule that also sets a dark
background is left alone, because teal on midnight is 6.86 and the ink would
vanish there.

The same goes the other way round for fills. White on teal is 2.86, on orange
2.33, on coral 2.20, so a button or badge with white text takes the matching
--kop-*-fill (the ink): white on teal fill is 5.35. This checks the fills pass
under white and that no rule puts white text on a display accent.

What this cannot see (colours set by Kadence, plugins, inheritance, hover and
focus states) is measured on the live pages by scripts/check-contrast.py.
"""

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
COLORS = os.path.join(ROOT, 'css', 'colors.css')

# Every stylesheet, the admin tools included. (READING_CSS below was the first
# list, kept so older notes that name it still make sense.)
READING_CSS = [
    'css/article.css',
    'css/trail.css',
    'css/hub.css',
    'css/facility-profile.css',
    'css/global-search.css',
]
ALL_CSS = sorted('css/' + name for name in os.listdir(os.path.join(ROOT, 'css'))
                 if name.endswith('.css') and name != 'plugin-contrast.css')

# Light grounds an ink has to work on.
GROUNDS = {'white': '#FFFFFF', 'sand': '#F2EEDF', 'pastel yellow': '#FFF5CB'}
AA_TEXT = 4.5

ACCENTS = ('teal', 'orange', 'coral-pink', 'chartreuse', 'bubblegum-pink')
ACCENT_HEX = {'teal': '#33a7b5', 'orange': '#ef9034', 'coral-pink': '#fe8088',
              'chartreuse': '#b2e102', 'bubblegum-pink': '#fc8ed6'}
FILLS = ('teal', 'orange', 'coral-pink', 'bubblegum-pink')
WHITE_TEXT = re.compile(r'^(#fff|#ffffff|white|var\(--kop-white[^)]*\))(\s*!important)?$', re.I)
BG_VALUE = re.compile(r'(?<![-\w])background(?:-color)?:\s*([^;]+);', re.I)

# `color:` but not `border-color:`, `background-color:` or any other
# <something>-color property.
TEXT_COLOUR = re.compile(r'(?<![-\w])color:\s*([^;]+);', re.I)
DARK_BG = re.compile(
    r'background(?:-color)?:\s*(?:var\(--kop-(?:midnight|navy)|#000|rgba?\(\s*0\s*,)', re.I)
RULE = re.compile(r'([^{}]+)\{([^{}]*)\}')


def luminance(hex_colour):
    hex_colour = hex_colour.lstrip('#')
    if len(hex_colour) == 3:
        hex_colour = ''.join(c * 2 for c in hex_colour)
    channels = [int(hex_colour[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    channels = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
                for c in channels]
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]


def contrast(a, b):
    la, lb = luminance(a), luminance(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def tokens():
    """Every --kop-* custom property whose value is a plain hex colour."""
    text = open(COLORS, encoding='utf-8').read()
    found = {}
    for name, value in re.findall(r'(--kop-[\w-]+):\s*(#[0-9a-fA-F]{3,8})\s*;', text):
        found[name] = value
    return found


failures = []
checks = 0


def check(label, ok, detail=''):
    global checks
    checks += 1
    if ok:
        print('PASS %s' % label)
        return
    failures.append(label)
    print('FAIL %s%s' % (label, ('\n     ' + detail) if detail else ''))


print('-- The inks pass AA on every light ground --')

palette = tokens()
for accent in ACCENTS:
    ink_name = '--kop-%s-ink' % accent
    ink = palette.get(ink_name)
    if not ink:
        check(ink_name + ' is defined', False, 'not found in css/colors.css')
        continue
    worst_ground, worst = min(
        ((name, contrast(ink, ground)) for name, ground in GROUNDS.items()),
        key=lambda pair: pair[1])
    check('%s (%s) is legible as text, worst ground %s at %.2f'
          % (ink_name, ink, worst_ground, worst),
          worst >= AA_TEXT,
          'needs %.1f' % AA_TEXT)

print('\n-- The inks still look like the accent they came from --')

for accent in ACCENTS:
    ink = palette.get('--kop-%s-ink' % accent)
    display = palette.get('--kop-%s' % accent)
    if not ink or not display:
        continue
    # Same hue: a "darker teal" that reads as brown would not be the palette.
    def hue(colour):
        colour = colour.lstrip('#')
        r, g, b = [int(colour[i:i + 2], 16) for i in (0, 2, 4)]
        mx, mn = max(r, g, b), min(r, g, b)
        if mx == mn:
            return 0.0
        d = mx - mn
        if mx == r:
            h = ((g - b) / d) % 6
        elif mx == g:
            h = (b - r) / d + 2
        else:
            h = (r - g) / d + 4
        return h * 60
    gap = abs(hue(ink) - hue(display))
    gap = min(gap, 360 - gap)
    check('%s keeps the hue of %s (%.0f degrees apart)' % (accent + '-ink', accent, gap),
          gap <= 12)

print('\n-- No accent is used as text on a light ground --')

offenders = []
for relative in ALL_CSS:
    path = os.path.join(ROOT, relative)
    if not os.path.exists(path):
        continue
    css = open(path, encoding='utf-8').read()
    for rule in RULE.finditer(css):
        selector = rule.group(1).strip().splitlines()[-1].strip()
        body = rule.group(2)
        if DARK_BG.search(body):
            continue
        for value in TEXT_COLOUR.findall(body):
            for accent in ACCENTS:
                if (re.search(r'var\(\s*--kop-%s\s*[,)]' % re.escape(accent), value)
                        or re.match(r'\s*%s\b' % ACCENT_HEX[accent], value, re.I)):
                    offenders.append('%s  %s  color: %s'
                                     % (relative, selector[:48], value.strip()))

check('no display accent is set as text in any stylesheet',
      not offenders, '\n     '.join(offenders))

print('\n-- White text sits on a fill, never on a display accent --')

for accent in FILLS:
    ink = palette.get('--kop-%s-ink' % accent)
    if ink:
        ratio = contrast(ink, '#FFFFFF')
        check('white on the %s fill (%s) is %.2f' % (accent, ink, ratio), ratio >= AA_TEXT)

offenders = []
for relative in ALL_CSS:
    css = open(os.path.join(ROOT, relative), encoding='utf-8').read()
    for rule in RULE.finditer(css):
        body = rule.group(2)
        colour = TEXT_COLOUR.search(body)
        if not colour or not WHITE_TEXT.match(colour.group(1).strip()):
            continue
        for value in BG_VALUE.findall(body):
            if 'gradient' in value:
                continue
            for accent in FILLS:
                if (re.search(r'var\(\s*--kop-%s\s*[,)]' % re.escape(accent), value)
                        or re.match(r'\s*%s\b' % ACCENT_HEX[accent], value, re.I)):
                    selector = rule.group(1).strip().splitlines()[-1].strip()
                    offenders.append('%s  %s  background: %s' % (relative, selector[:48], value.strip()))

check('no rule puts white text on a display accent', not offenders, '\n     '.join(offenders))

print('\n-- The display accents are still failing, which is why the inks exist --')

for accent in ACCENTS:
    display = palette.get('--kop-%s' % accent)
    if display:
        ratio = contrast(display, GROUNDS['sand'])
        check('%s on sand is %.2f, below AA, so it stays a border colour'
              % (accent, ratio), ratio < AA_TEXT)

print()
if failures:
    print('%d of %d checks FAILED' % (len(failures), checks))
    sys.exit(1)
print('colour contrast: PASS (%d checks)' % checks)
