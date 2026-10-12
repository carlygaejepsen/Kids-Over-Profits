#!/usr/bin/env python3
"""
Find what breaks at phone and tablet width: text running out of its button or
box, and anything wider than the screen.

For each page and width this reports
  WIDE   an element whose right edge is past the screen (the page scrolls sideways)
  SPILL  a box (button, pill, card, cell) whose content is wider than the box itself
  TEXT   a line of text that runs past the edge of the box it is drawn in
         (nearest ancestor with a border or a background)

Usage:
  python scripts/check-mobile-overflow.py                      one page per template, 390 and 768 px, live site
  python scripts/check-mobile-overflow.py glossary/ news/      just these paths
  python scripts/check-mobile-overflow.py --local              the working tree's css/ and js/ on the live pages
  python scripts/check-mobile-overflow.py --widths 360,390,820 --shots tmp/mobile-shots
"""
import os
import re
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

SITE = 'https://kidsoverprofits.org/'
THEME = '/wp-content/themes/child/'
ROOT = Path(__file__).resolve().parent.parent

SAMPLES = [
    '', 'history/', 'alabama/', 'australia/', 'hyde/', 'report-abuse/', 'glossary/', 'network-map/',
    'lawsuits/', 'legislative-efforts/', 'in-loving-memory/', 'inspection-reports/', 'severe-reports/',
    'tti-program-index/', 'tti-program-index/?view=location', 'referrers-educational-consultants/',
    'youth-transport-companies/', 'mental-health-providers/', 'tti-news-feed/', 'ar-reports/', 'pa-reports/',
    'facility/', 'operator/', 'essential-reporting/', 'open-data/', 'site-map/', 'how-to-use-this-site/',
    'faq/', 'young-adult-programs/', 'indian-boarding-schools/', 'related-industries/', 'send/',
    'richardson-v-elevations-rtc-prelitigation-panel-opinion/', 'submit-lawsuit/', 'tti-data-submission/',
    'r-troubledteens-wiki-backup/', 'contact/', 'privacy-policy/',
]

MEASURE = r"""
() => {
  const vw = document.documentElement.clientWidth;
  const sel = el => {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    if (el.className && typeof el.className === 'string') s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
    return s;
  };
  const path = el => { const p = []; for (let n = el; n && n !== document.body && p.length < 3; n = n.parentElement) p.unshift(sel(n)); return p.join(' > '); };
  const skip = el => el.closest('#wpadminbar, script, style, noscript, svg, [hidden], [aria-hidden="true"], .screen-reader-text, .kop-network-stage, canvas');
  const isBox = s => (s.borderRightStyle !== 'none' && parseFloat(s.borderRightWidth) > 0)
    || (s.backgroundColor && s.backgroundColor !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(s.backgroundColor));
  const clips = s => s.overflowX !== 'visible' || s.textOverflow === 'ellipsis';
  const out = new Map();
  const add = (k, v) => { if (!out.has(k)) out.set(k, v); };
  const scrolls = el => { for (let n = el.parentElement; n && n !== document.body; n = n.parentElement) { const s = getComputedStyle(n); if (s.overflowX === 'auto' || s.overflowX === 'scroll' || s.overflowX === 'hidden' || s.overflowX === 'clip') return true; } return false; };

  for (const el of document.body.querySelectorAll('*')) {
    if (skip(el)) continue;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.position === 'fixed') continue;
    if (r.right > vw + 1 && !scrolls(el)) add('WIDE ' + path(el), Math.round(r.right - vw) + 'px past the screen');
    if (/^(INPUT|SELECT|TEXTAREA|IMG|VIDEO|IFRAME|TABLE|PRE)$/.test(el.tagName)) continue;
    // scrollWidth alone also counts hidden tooltips (::after); a spill is a visible child past the edge.
    if (el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1 && !clips(s) && isBox(s)) {
      const edge = r.right - parseFloat(s.borderRightWidth);
      const kid = [...el.querySelectorAll('*')].find(k => {
        const kr = k.getBoundingClientRect();
        return kr.width && kr.height && kr.right > edge + 1 && getComputedStyle(k).visibility !== 'hidden' && !skip(k);
      });
      if (kid) add('SPILL ' + path(el), JSON.stringify(kid.textContent.trim().replace(/\s+/g, ' ').slice(0, 50)) + ' (' + sel(kid) + ') runs ' + Math.round(kid.getBoundingClientRect().right - edge) + 'px past its edge');
    }
  }
  const boxOf = el => {
    for (let n = el; n && n !== document.body; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (clips(s)) return null;
      if (isBox(s) || /^(BUTTON)$/.test(n.tagName)) return n;
    }
    return null;
  };
  const range = document.createRange();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = walker.nextNode())) {
    if (!t.textContent.trim()) continue;
    const p = t.parentElement;
    if (!p || skip(p)) continue;
    const box = boxOf(p);
    if (!box) continue;
    const br = box.getBoundingClientRect();
    if (!br.width) continue;
    range.selectNodeContents(t);
    for (const tr of range.getClientRects()) {
      if (tr.width && (tr.right > br.right + 1 || tr.left < br.left - 1)) {
        add('TEXT ' + path(p) + '  in  ' + sel(box), JSON.stringify(t.textContent.trim().slice(0, 50)) + ' ' + Math.round(Math.max(tr.right - br.right, br.left - tr.left)) + 'px out');
        break;
      }
    }
  }
  return { docWidth: document.documentElement.scrollWidth, vw, problems: [...out].map(([k, v]) => k + '  ::  ' + v) };
}
"""


def local_route(route):
    url = route.request.url
    rel = url.split(THEME, 1)[1].split('?', 1)[0]
    f = ROOT / rel
    if f.is_file() and f.suffix in ('.css', '.js'):
        ctype = 'text/css' if f.suffix == '.css' else 'application/javascript'
        route.fulfill(status=200, body=f.read_bytes(), headers={'content-type': ctype, 'cache-control': 'no-store'})
    else:
        route.continue_()


def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    args = sys.argv[1:]
    widths = [390, 768]
    shots = None
    local = False
    if '--widths' in args:
        i = args.index('--widths')
        widths = [int(w) for w in args[i + 1].split(',')]
        del args[i:i + 2]
    if '--shots' in args:
        i = args.index('--shots')
        shots = Path(args[i + 1])
        shots.mkdir(parents=True, exist_ok=True)
        del args[i:i + 2]
    if '--local' in args:
        local = True
        args.remove('--local')
    paths = args or SAMPLES
    total = 0
    with sync_playwright() as pw:
        browser = pw.chromium.launch(channel='chrome')
        for width in widths:
            ctx = browser.new_context(
                viewport={'width': width, 'height': 900}, is_mobile=width < 700, has_touch=width < 1024,
                device_scale_factor=2 if width < 700 else 1,
                user_agent='Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36'
                if width < 700 else None,
            )
            if local:
                ctx.route(re.compile(r'.*' + re.escape(THEME) + r'(css|js)/.*'), local_route)
            page = ctx.new_page()
            for path in paths:
                try:
                    page.goto(SITE + path, wait_until='domcontentloaded', timeout=90000)
                    page.wait_for_timeout(4000)
                    res = page.evaluate(MEASURE)
                except Exception as e:  # noqa: BLE001
                    print(f'\n[{width}] /{path}  could not check: {e}')
                    continue
                probs = res['problems']
                total += len(probs)
                side = '' if res['docWidth'] <= res['vw'] else f"  (page scrolls sideways: {res['docWidth']}px wide)"
                print(f"\n[{width}] /{path}  {len(probs)} problem(s){side}")
                for p in probs[:25]:
                    print('   ' + p)
                if len(probs) > 25:
                    print(f'   ... {len(probs) - 25} more')
                if shots:
                    name = (path.strip('/').replace('/', '_').replace('?', '_').replace('=', '-') or 'home')
                    try:
                        page.screenshot(path=str(shots / f'{name}-{width}.png'), full_page=True, timeout=20000)
                    except Exception:  # noqa: BLE001  (a very long page; the first screen will do)
                        page.screenshot(path=str(shots / f'{name}-{width}.png'), timeout=20000)
            ctx.close()
        browser.close()
    print(f'\n{total} problem(s) in all')
    return 1 if total else 0


if __name__ == '__main__':
    sys.exit(main())
