#!/usr/bin/env python3
"""
Find text sitting straight on the site's gradient background.

The body carries the Customizer gradient; every word a reader sees should sit
on a solid panel. For each page this walks the visible text inside the
content column (#primary, so the header, footer and sidebar widgets are not
counted) and reports any text whose nearest ancestor with a background is
<body> or <html>.

Usage:
  python scripts/check-bare-text.py                    every template's sample page, live site
  python scripts/check-bare-text.py glossary/ hyde/    just these paths
  python scripts/check-bare-text.py --css css/site-panels.css   test a local stylesheet on the live pages
"""
import sys
from playwright.sync_api import sync_playwright

SITE = 'https://kidsoverprofits.org/'

# One published page per child template.
SAMPLES = [
    '', 'history/', 'antiquity/', 'alabama/', 'australia/', 'hyde/', 'report-abuse/',
    'glossary/', 'network-map/', 'lawsuits/', 'legislative-efforts/', 'location-index/',
    'in-loving-memory/', 'inspection-reports/', 'severe-reports/', 'tti-program-index/',
    'referrers-educational-consultants/', 'tti-news-feed/', 'ar-reports/',
    'document-library-discovery-ranch/', 'privacy-policy/', 'terms-of-service/',
    'richardson-v-elevations-rtc-prelitigation-panel-opinion/',
    'submit-lawsuit/', 'submit-legislation/', 'tti-data-submission/',
    'r-troubledteens-wiki-backup/', 'tti-wiki-entry-generator/', 'contact/',
]

FIND_BARE = r"""
() => {
  const root = document.querySelector('#primary') || document.body;
  const bgOf = (el) => {
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      if (n === document.body || n === document.documentElement) return n;
      const s = getComputedStyle(n);
      const c = s.backgroundColor;
      const solid = c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c);
      if (solid || s.backgroundImage !== 'none') return n;
    }
    return document.body;
  };
  const out = [];
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = walker.nextNode())) {
    const text = t.textContent.trim();
    if (!text) continue;
    const el = t.parentElement;
    if (!el || el.closest('script,style,noscript,svg,[hidden]')) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.display === 'none' || +s.opacity === 0) continue;
    const bg = bgOf(el);
    if (bg === document.body || bg === document.documentElement) {
      out.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : '') + ': ' + text.slice(0, 60));
    }
  }
  return out;
}
"""

def main():
    args = sys.argv[1:]
    css = None
    if '--css' in args:
        i = args.index('--css')
        css = open(args[i + 1], encoding='utf-8').read()
        del args[i:i + 2]
    paths = args or SAMPLES
    bad = 0
    with sync_playwright() as pw:
        browser = pw.chromium.launch(channel='chrome')
        page = browser.new_page(viewport={'width': 1360, 'height': 1000})
        for path in paths:
            page.goto(SITE + path, wait_until='domcontentloaded', timeout=90000)
            page.wait_for_timeout(2500)
            if css:
                page.add_style_tag(content=css)
                page.wait_for_timeout(200)
            hits = page.evaluate(FIND_BARE)
            status = 'OK  ' if not hits else 'BARE'
            bad += 1 if hits else 0
            print(f'{status} /{path}  ({len(hits)})')
            for h in hits[:4]:
                print('       ', h)
        browser.close()
    print(f'\n{bad} of {len(paths)} pages have text on the gradient.')
    sys.exit(1 if bad else 0)

if __name__ == '__main__':
    main()
