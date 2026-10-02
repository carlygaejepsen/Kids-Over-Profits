"""The facility directory (/tti-program-index/) drawn from the split feed must
match the same page drawn from the whole feed.

The page loads kop/v1/facilities?view=index for its lists and
?view=detail&key[]= for each company or place a visitor opens
(inc/directory-feed.php, js/tti-program-index.js, js/location-index.js).
This saves the live whole feed (or reads --feed), splits it with the
working tree's inc/directory-feed.php (scripts/directory-feed-split.php),
then opens the live page twice in Chrome with the working tree's directory
JS and CSS: once answering every feed request with the whole feed (what an
older server sends), once with the split. It compares every search, filter
and sort result in the company list, the body of every company section, the
place list and a few places' cards, and prints load timings.

    python scripts/test-directory-feed.py [--feed tmp/directory-feed/full.json] [--refresh]
        [--php <php.exe>] [--cpu 4] [--places UTAH,CALIFORNIA]

Needs Python Playwright with Chrome. Writes under tmp/directory-feed/ (gitignored).
"""
import argparse
import glob
import hashlib
import json
import os
import subprocess
import sys
import time
import urllib.request
from urllib.parse import parse_qs, urlparse

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WORK = os.path.join(REPO, 'tmp', 'directory-feed')
PAGE = 'https://kidsoverprofits.org/tti-program-index/'
FEED_URL = 'https://kidsoverprofits.org/wp-json/kop/v1/facilities'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'

LOCAL = {
    '/js/tti-program-index.js': 'js/tti-program-index.js',
    '/js/location-index.js': 'js/location-index.js',
    '/js/facility-merge.js': 'js/facility-merge.js',
    '/css/tti-program-index.css': 'css/tti-program-index.css',
}

COMPANY_LIST = r"""
async (terms) => {
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const vis = () => Array.from(document.querySelectorAll('#facilities-container .operator-section'))
      .filter(s => s.style.display !== 'none')
      .map(s => s.dataset.operator + (s.classList.contains('alias-entry') ? ' [old name]' : ''));
  const out = {};
  const input = document.getElementById('searchInput');
  for (const term of terms) {
    input.value = term; input.dispatchEvent(new Event('input')); await wait(300);
    out['search ' + term] = vis();
  }
  input.value = ''; input.dispatchEvent(new Event('input')); await wait(300);
  const status = document.getElementById('statusFilter');
  for (const value of ['open', 'closed', 'transferred']) {
    status.value = value; status.dispatchEvent(new Event('change')); await wait(100);
    out['status ' + value] = vis();
  }
  status.value = ''; status.dispatchEvent(new Event('change'));
  const sort = document.getElementById('sortBy');
  for (const value of ['violations-only', 'reports-desc', 'name']) {
    sort.value = value; sort.dispatchEvent(new Event('change')); await wait(100);
    out['sort ' + value] = vis();
  }
  window.filterByLetter('C'); await wait(100);
  out['letter C'] = vis();
  window.filterByLetter('');
  return out;
}
"""

# The bug reporter adds its links 250 ms after any change: left out.
STRIP_BUG_LINKS = r"html.replace(/<a class=\"kop-bug-report-link[\s\S]*?<\/a>/g, '')"

COMPANY_BODIES = r"""
async () => {
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const out = {};
  for (const section of document.querySelectorAll('#facilities-container details.operator-section')) {
    section.open = true;
    for (let k = 0; k < 150 && !section.querySelector('.operator-content-scrollable'); k++) await wait(100);
    const body = section.querySelector('.operator-content-scrollable');
    const html = body ? body.outerHTML : 'not drawn';
    out[section.dataset.operator] = """ + STRIP_BUG_LINKS + r""";
  }
  return out;
}
"""

PLACES = r"""
async (names) => {
  const wait = ms => new Promise(r => setTimeout(r, ms));
  document.getElementById('kop-dir-tab-location').click();
  for (let k = 0; k < 300 && !document.querySelector('#locations-container details.operator-section'); k++) await wait(100);
  const all = () => Array.from(document.querySelectorAll('#locations-container details.operator-section'));
  const out = { list: all().map(s => s.querySelector('summary').textContent.replace(/\s+/g, ' ').trim()) };
  for (const name of names) {
    const section = all().find(s => s.querySelector('.operator-name').textContent === name);
    if (!section) { out[name] = 'missing'; continue; }
    section.open = true;
    for (let k = 0; k < 150 && !section.querySelector('.operator-content-scrollable'); k++) await wait(100);
    const body = section.querySelector('.operator-content-scrollable');
    const html = body ? body.outerHTML : 'not drawn';
    out[name] = """ + STRIP_BUG_LINKS + r""";
  }
  const input = document.getElementById('loc-searchInput');
  input.value = 'academy'; input.dispatchEvent(new Event('input')); await wait(500);
  out['search academy'] = all().map(s => s.querySelector('summary').textContent.replace(/\s+/g, ' ').trim());
  return out;
}
"""


def find_php(given):
    if given:
        return given
    base = os.path.join(os.environ.get('LOCALAPPDATA', ''), 'Programs', 'Local', 'resources', 'extraResources', 'lightning-services')
    found = sorted(glob.glob(os.path.join(base, 'php-*', 'bin', 'win32', 'php.exe')))
    return found[-1] if found else 'php'


def run(split, feed_path, split_dir, cpu, terms, places):
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome')
        ctx = browser.new_context(viewport={'width': 1300, 'height': 900})
        page = ctx.new_page()
        if cpu > 1:
            ctx.new_cdp_session(page).send('Emulation.setCPUThrottlingRate', {'rate': cpu})
        requests = []
        page.add_init_script("""
          window.__lt = [];
          try { new PerformanceObserver(l => l.getEntries().forEach(e => window.__lt.push(Math.round(e.duration)))).observe({ type: 'longtask', buffered: true }); } catch (e) {}
          new MutationObserver(() => { if (!window.__drawn && document.querySelector('#facilities-container .operator-section')) window.__drawn = performance.now(); })
            .observe(document, { subtree: true, childList: true });
        """)

        def theme(route):
            path = urlparse(route.request.url).path
            for suffix, rel in LOCAL.items():
                if path.endswith(suffix):
                    kind = 'text/css' if rel.endswith('.css') else 'application/javascript'
                    return route.fulfill(path=os.path.join(REPO, rel), content_type=kind)
            return route.continue_()
        page.route('**/themes/child/**', theme)

        def feed(route):
            query = parse_qs(urlparse(route.request.url).query)
            view = (query.get('view') or [''])[0]
            requests.append(view or 'full')
            if split and view == 'index':
                return route.fulfill(path=os.path.join(split_dir, 'index.json'), content_type='application/json')
            if split and view == 'detail':
                found = {}
                for key in query.get('key[]', []):
                    path = os.path.join(split_dir, 'p-' + hashlib.md5(key.encode('utf-8')).hexdigest() + '.json')
                    if os.path.exists(path):
                        with open(path, encoding='utf-8') as fh:
                            found[key] = json.load(fh)
                return route.fulfill(body=json.dumps({'view': 'detail', 'projects': found}), content_type='application/json')
            return route.fulfill(path=feed_path, content_type='application/json')
        page.route('**/wp-json/kop/v1/facilities*', feed)

        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(PAGE, wait_until='domcontentloaded', timeout=120000)
        page.wait_for_function('window.__drawn', timeout=300000)
        page.wait_for_timeout(2000)
        stats = {
            'list drawn (ms)': page.evaluate('Math.round(window.__drawn)'),
            'elements': page.evaluate("document.getElementsByTagName('*').length"),
            'longest freeze (ms)': page.evaluate('Math.max(0, ...window.__lt)'),
        }
        lists = page.evaluate(COMPANY_LIST, terms)
        bodies = page.evaluate(COMPANY_BODIES)
        place_data = page.evaluate(PLACES, places)
        stats['feed requests'] = len(requests)
        stats['errors'] = errors
        browser.close()
        return stats, lists, bodies, place_data


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--feed', default=os.path.join(WORK, 'full.json'))
    ap.add_argument('--refresh', action='store_true', help='download the live feed again')
    ap.add_argument('--php')
    ap.add_argument('--cpu', type=int, default=1, help='CPU slowdown for the timings, e.g. 4 for a phone')
    ap.add_argument('--places', default='UTAH,MONTANA,COSTA RICA,CALIFORNIA')
    args = ap.parse_args()

    os.makedirs(WORK, exist_ok=True)
    if args.refresh or not os.path.exists(args.feed):
        print('Saving', FEED_URL)
        req = urllib.request.Request(FEED_URL, headers={'User-Agent': UA})
        with urllib.request.urlopen(req, timeout=180) as resp, open(args.feed, 'wb') as fh:
            fh.write(resp.read())
    split_dir = os.path.join(WORK, 'split')
    subprocess.run([find_php(args.php), '-n', '-d', 'memory_limit=2G',
                    os.path.join(REPO, 'scripts', 'directory-feed-split.php'), args.feed, split_dir], check=True)

    terms = ['acadia', 'island view', 'utah', 'cedu', 'provo canyon', 'wwasp', 'montana', 'zz-nothing']
    places = [p.strip() for p in args.places.split(',') if p.strip()]
    t = time.time()
    whole = run(False, args.feed, split_dir, args.cpu, terms, places)
    split = run(True, args.feed, split_dir, args.cpu, terms, places)
    print('whole feed:', json.dumps(whole[0]))
    print('split feed:', json.dumps(split[0]))

    failures = []
    if split[0]['errors']:
        failures.append('page errors: ' + '; '.join(split[0]['errors']))
    for key, value in whole[1].items():
        if split[1].get(key) != value:
            failures.append('company list differs: ' + key)
    for name, html in whole[2].items():
        if split[2].get(name) != html:
            failures.append('company section differs: ' + name)
    for key, value in whole[3].items():
        if split[3].get(key) != value:
            failures.append('places differ: ' + key)
    print('compared %d list states, %d companies, %d places (%ds)' % (
        len(whole[1]), len(whole[2]), len(whole[3]) - 2, time.time() - t))
    if failures:
        print('FAIL')
        for line in failures:
            print('  ' + line)
        sys.exit(1)
    print('OK: the split feed draws the same directory')


if __name__ == '__main__':
    main()
