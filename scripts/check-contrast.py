#!/usr/bin/env python3
"""
Find text that cannot be read against the colour actually behind it.

Kadence colours some elements directly instead of letting them inherit, and it
loads after the child theme's stylesheets, so a panel that sets its own text
colour does not reach everything inside it:

  h1-h6              Kadence #1A202C / #2D3748   -> black on a navy panel
  a                  Kadence #000080 (navy)       -> navy on a navy panel
  button, .button    Kadence white text on teal, and button:hover/:focus
                     (0,1,1) beats a one-class rule  -> white on a white or
                     sand button, often only on hover or after a click

This measures what the reader gets: every visible text node in the content
column, its colour against the background composited from its ancestors, and
then again with :hover and with :focus forced on every button and link, where
most of these bugs hide. Text over an image or gradient is reported as
"image" and left to check-bare-text.py.

Usage:
  python scripts/check-contrast.py                     every template's sample page, live site
  python scripts/check-contrast.py glossary/ hyde/     just these paths
  python scripts/check-contrast.py --local             serve the working tree's css/ and js/ in place of the live ones
  python scripts/check-contrast.py --aa                also list text between 3:1 and 4.5:1 (WCAG AA body text)
  python scripts/check-contrast.py --mobile            390px wide instead of 1360px

Fails (exit 1) on text below 2:1, the "cannot see it" kind (white on sand,
navy on navy). Text from 2:1 to 3:1 is listed as LOW: white on the Kadence
teal button is 2.86 on every page, a Customizer setting rather than a bug.
"""
import importlib.util
import mimetypes
import os
import re
import sys

from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://kidsoverprofits.org/'

# Same sample pages as the bare-text check: one per child template.
_spec = importlib.util.spec_from_file_location('bare', os.path.join(ROOT, 'scripts', 'check-bare-text.py'))
_bare = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_bare)
SAMPLES = _bare.SAMPLES

INTERACTIVE = 'button, a, summary, [role="button"], input[type="submit"], input[type="button"], .button'

MEASURE = r"""
(onlyInteractive) => {
  const parse = (c) => {
    const m = c && c.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
  };
  const over = (top, under) => {
    const a = top[3];
    return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1);
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

  // Background behind an element: stack the ancestors' background colours
  // from the nearest opaque one down. An image or gradient on the way makes
  // the answer unknowable here.
  const backgroundOf = (el) => {
    const layers = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (s.backgroundImage !== 'none' && !/^url\(.*\.svg/.test(s.backgroundImage)) return { image: n };
      const c = parse(s.backgroundColor);
      if (c && c[3] > 0) { layers.push(c); if (c[3] >= 1) break; }
    }
    let bg = [255, 255, 255, 1];
    for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
    return { bg };
  };
  const opacityOf = (el) => {
    let o = 1;
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) o *= +getComputedStyle(n).opacity;
    return o;
  };
  const label = (el) => {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    else if (typeof el.className === 'string' && el.className.trim()) s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
    const p = el.parentElement && el.parentElement.closest('[class]');
    if (p && p !== el && typeof p.className === 'string') s = '.' + p.className.trim().split(/\s+/)[0] + ' ' + s;
    return s;
  };

  const root = document.querySelector('#primary') || document.body;
  const out = [];
  const seen = new Set();
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = walker.nextNode())) {
    const text = t.textContent.replace(/\s+/g, ' ').trim();
    if (!text) continue;
    const el = t.parentElement;
    if (!el || el.closest('script,style,noscript,svg,[hidden],[aria-hidden="true"]')) continue;
    if (onlyInteractive && !el.closest('[data-kop-forced]')) continue;
    // Disabled controls are exempt (WCAG 1.4.3): greyed-out letters, spent buttons.
    const ctl = el.closest('a, button, input, select, textarea, [role="button"]');
    if (ctl && (ctl.disabled || ctl.getAttribute('aria-disabled') === 'true' || getComputedStyle(ctl).pointerEvents === 'none')) continue;
    if (seen.has(el)) continue;
    seen.add(el);
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.display === 'none') continue;
    const op = opacityOf(el);
    if (op < 0.05) continue;  // deliberately invisible (slide-out labels at rest)
    const b = backgroundOf(el);
    const size = parseFloat(s.fontSize), bold = +s.fontWeight >= 700;
    const large = size >= 24 || (bold && size >= 18.66);
    if (b.image) { out.push({ where: label(el), text: text.slice(0, 50), image: true }); continue; }
    let fg = parse(s.color);
    fg = [fg[0], fg[1], fg[2], fg[3] * op];
    const shown = over(fg, b.bg);
    const cr = ratio(shown, b.bg);
    out.push({
      where: label(el), text: text.slice(0, 50), ratio: Math.round(cr * 100) / 100, large,
      fg: s.color, bg: 'rgb(' + b.bg.slice(0, 3).map(Math.round).join(',') + ')',
    });
  }
  return out;
}
"""


def local_routes(page):
    """Serve the working tree's theme css/js in place of the live copies."""
    def handler(route, request):
        rel = request.url.split('/themes/child/', 1)[1].split('?', 1)[0]
        path = os.path.join(ROOT, *rel.split('/'))
        if os.path.isfile(path):
            ctype = mimetypes.guess_type(path)[0] or 'text/plain'
            route.fulfill(path=path, content_type=ctype)
        else:
            route.continue_()
    page.route(re.compile(r'/themes/child/.*\.(css|js)(\?|$)'), handler)


def force_state(cdp, state):
    """Force a pseudo-class on every interactive element (or clear it with [])."""
    doc = cdp.send('DOM.getDocument', {'depth': -1})
    ids = cdp.send('DOM.querySelectorAll', {'nodeId': doc['root']['nodeId'], 'selector': INTERACTIVE})['nodeIds']
    for nid in ids:
        cdp.send('CSS.forcePseudoState', {'nodeId': nid, 'forcedPseudoClasses': state})
        if state:
            cdp.send('DOM.setAttributeValue', {'nodeId': nid, 'name': 'data-kop-forced', 'value': '1'})
        else:
            cdp.send('DOM.removeAttribute', {'nodeId': nid, 'name': 'data-kop-forced'})


def main():
    args = sys.argv[1:]
    aa = '--aa' in args
    local = '--local' in args
    mobile = '--mobile' in args
    paths = [a for a in args if not a.startswith('--')] or SAMPLES
    width = 390 if mobile else 1360

    failed_pages = 0
    with sync_playwright() as pw:
        browser = pw.chromium.launch(channel='chrome')
        page = browser.new_page(viewport={'width': width, 'height': 1000})
        if local:
            local_routes(page)
        cdp = page.context.new_cdp_session(page)
        cdp.send('DOM.enable')
        cdp.send('CSS.enable')

        for path in paths:
            try:
                page.goto(SITE + path, wait_until='domcontentloaded', timeout=90000)
            except Exception as e:
                print(f'SKIP /{path}  ({e.__class__.__name__})')
                continue
            page.wait_for_timeout(2500)

            found = {}
            for state in ([], ['hover'], ['focus', 'focus-visible']):
                if state:
                    force_state(cdp, state)
                    page.wait_for_timeout(700)  # let colour transitions finish
                for row in page.evaluate(MEASURE, bool(state)):
                    if row.get('image'):
                        continue
                    floor = 3 if row['large'] else 4.5
                    if row['ratio'] < 3 or (aa and row['ratio'] < floor):
                        key = (row['where'], row['text'])
                        tag = ':' + state[0] if state else ''
                        if key not in found or row['ratio'] < found[key][0]['ratio']:
                            found[key] = (row, tag)
                if state:
                    force_state(cdp, [])
                    page.wait_for_timeout(300)

            bad = [f for f in found.values() if f[0]['ratio'] < 2]
            low = [f for f in found.values() if 2 <= f[0]['ratio'] < 3]
            failed_pages += 1 if bad else 0
            print(f"{'FAIL' if bad else 'OK  '} /{path}  ({len(bad)} below 2:1, {len(low)} low" + (f", {len(found) - len(bad) - len(low)} below AA)" if aa else ')'))
            for row, tag in sorted(found.values(), key=lambda f: f[0]['ratio'])[:12]:
                print(f"       {row['ratio']:>5}  {row['where']}{tag}  \"{row['text']}\"  {row['fg']} on {row['bg']}")
        browser.close()

    print(f'\n{failed_pages} of {len(paths)} pages have text below 2:1.')
    sys.exit(1 if failed_pages else 0)


if __name__ == '__main__':
    main()
