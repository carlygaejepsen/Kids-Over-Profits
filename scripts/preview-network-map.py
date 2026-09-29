"""Open the network map from the working tree in a real browser.

WordPress is not needed to see the map: the template's shell is real HTML and
everything else is static files, so a change can be looked at before it is
pushed. This builds the page from templates/page-network-map.php with the PHP
stripped out, serves the theme over http on a free port, and either holds
the server open for you or drives it with Playwright and saves screenshots.

    python scripts/preview-network-map.py                         # serve, print the URL, wait
    python scripts/preview-network-map.py --shots tmp/map-preview # desktop + phone screenshots
    python scripts/preview-network-map.py --shots tmp/map-preview --hash "#open=wwasps"
    python scripts/preview-network-map.py --config tmp/live-config.json   # e.g. the live facilityUrls
    python scripts/preview-network-map.py --hash "#view=historical" --still images/network-map-historical.jpg
    python scripts/preview-network-map.py --facility provo-canyon-school --shots tmp/map-preview   # the page embed

tmp/map-preview/ is gitignored.

The module list is read from inc/enqueue.php, so a module added there is
loaded here. What this cannot show is anything PHP decides: the "Start from"
select (left out) and facility profile links (the config's facilityUrls is
empty, so the drawer falls back to the location index search) - unless
--config names a JSON file whose keys go over the defaults, such as the live
page's KOP_NETWORK_CONFIG saved to a file. Check those on the live page after
the deploy.

Needs Python Playwright for --shots only (pip install playwright).
"""
import argparse
import functools
import http.server
import json
import os
import re
import sys
import threading

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Keys from --config, laid over the preview's own config.
CONFIG_EXTRA = {}

KIND_LABELS = {
    'person': 'Person', 'facility': 'Program', 'parent': 'Company',
    'association': 'Trade group', 'government': 'Government body',
    'church': 'Church', 'other': 'Other',
}


def modules():
    """The map's scripts, in the order inc/enqueue.php loads them."""
    src = open(os.path.join(REPO, 'inc', 'enqueue.php'), encoding='utf-8').read()
    found = re.search(r"foreach \(array\(('store'[^)]*)\) as \$module\)", src)
    if not found:
        sys.exit('Could not find the network map module list in inc/enqueue.php')
    return re.findall(r"'([a-z-]+)'", found.group(1))


def page_html():
    src = open(os.path.join(REPO, 'templates', 'page-network-map.php'), encoding='utf-8').read()
    start = src.index('<div class="kop-network__app"')
    end = src.index('<?php endif; ?>', src.rindex('</aside>'))
    body = src[start:end]
    body = body.replace('<?php echo esc_attr(wp_json_encode($kop_net_kind_labels)); ?>',
                        json.dumps(KIND_LABELS).replace('"', '&quot;'))
    # The starter-view select is built from PHP data; every other PHP block is a comment or a lookup.
    body = re.sub(r'<\?php\s+// Where the map opens.*?<\?php endif; \?>', '', body, flags=re.S)
    body = re.sub(r'<\?php.*?\?>', '', body, flags=re.S)
    scripts = ''.join('<script src="/js/network-map/%s.js"></script>\n' % m for m in modules())
    config = {
        'graphUrl': '/js/data/network/graph.json', 'layoutUrl': '/js/data/network/layout.json',
        'directoryUrl': '/location-index/', 'facilityUrls': {}, 'memorialUrl': '/in-loving-memory/',
    }
    config.update({k: v for k, v in CONFIG_EXTRA.items() if k not in ('graphUrl', 'layoutUrl')})
    return """<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Network map preview</title>
<link rel="stylesheet" href="/css/colors.css"><link rel="stylesheet" href="/css/network-map.css">
<style>body{margin:0 1rem;font-family:system-ui,sans-serif;background:#fff}.screen-reader-text{position:absolute;left:-9999px}</style>
</head><body><div class="kop-network"><header class="kop-network__intro"><h1 class="kop-network__title">Network Map</h1></header>
%s</div>
<script>window.KOP_NETWORK_CONFIG=%s;</script>
<script src="/js/vendor/d3-force.bundle.min.js"></script>
%s</body></html>""" % (body, json.dumps(config), scripts)


class Handler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path.split('?')[0] in ('/', '/map.html'):
            data = page_html().encode('utf-8')
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(data)
            return
        super().do_GET()

    def end_headers(self):
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()

    def log_message(self, *args):
        pass


def serve(port=0):
    server = http.server.ThreadingHTTPServer(('127.0.0.1', port), functools.partial(Handler, directory=REPO))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server, 'http://127.0.0.1:%d/map.html' % server.server_address[1]


def shots(url, out_dir, hash_):
    from playwright.sync_api import sync_playwright
    os.makedirs(out_dir, exist_ok=True)
    problems = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        for name, size in (('desktop', (1440, 900)), ('phone', (390, 780))):
            page = browser.new_page(viewport={'width': size[0], 'height': size[1]})
            page.on('console', lambda m, n=name: problems.append('%s console %s: %s' % (n, m.type, m.text))
                    if m.type in ('error', 'warning') else None)
            page.on('pageerror', lambda e, n=name: problems.append('%s pageerror: %s' % (n, e)))
            page.goto(url + hash_)
            page.wait_for_selector('#kop-network-app[data-state="ready"]', timeout=20000)
            page.wait_for_timeout(1200)  # the click's spring, where a hash opens a name
            path = os.path.join(out_dir, 'network-map-%s.png' % name)
            page.screenshot(path=path)
            print('saved', path)
            page.close()
        browser.close()
    for problem in problems:
        print('PROBLEM', problem)
    return 1 if problems else 0


def still(url, path, hash_):
    """The stage alone at 1440 px, Key and stage buttons hidden, as a JPEG.

    This is the picture pages show of the map where the live map is not
    running (the history hub's figure), so it is the map's own drawing with
    none of its controls.
    """
    from playwright.sync_api import sync_playwright
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    problems = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        page = browser.new_page(viewport={'width': 1440, 'height': 900}, device_scale_factor=1)
        page.on('pageerror', lambda e: problems.append('pageerror: %s' % e))
        page.goto(url + hash_)
        page.wait_for_selector('#kop-network-app[data-state="ready"]', timeout=20000)
        page.wait_for_timeout(1200)
        page.add_style_tag(content=(
            '#kop-network-stage .kop-network__key,'
            '#kop-network-stage .kop-network__stage-button,'
            '#kop-network-stage .kop-network__zoom-hint { visibility: hidden !important; }'))
        page.wait_for_timeout(200)
        page.locator('#kop-network-stage').screenshot(path=path, type='jpeg', quality=82)
        print('saved', path)
        browser.close()
    for problem in problems:
        print('PROBLEM', problem)
    return 1 if problems else 0


EMBED_SCRIPTS = ('js/vendor/d3-force.bundle.min.js', 'js/network-map/store.js', 'js/network-map/canvas.js',
                 'js/network-map/viewport.js', 'js/network-map/focus.js', 'js/network-map/embed.js')


def facility(slug, out_dir):
    """The working tree's map embed on the live facility page, at three widths.

    The figure comes from the page scripts/test-facility-pages.php renders
    into tmp/facility-pages/<slug>.html (run it with --out=tmp/facility-pages
    and --id= for the facility first), so it carries the slice the PHP cuts
    today. It goes into the live page's Network section, above the list,
    with the local stylesheet and scripts; nothing else on the page changes.
    """
    from playwright.sync_api import sync_playwright
    rendered = os.path.join(REPO, 'tmp', 'facility-pages', slug + '.html')
    if not os.path.exists(rendered):
        sys.exit('No %s: run scripts/test-facility-pages.php --out=tmp/facility-pages --id=<id> first' % rendered)
    found = re.search(r'<figure class="kop-network-embed".*?</figure>', open(rendered, encoding='utf-8').read(), re.S)
    if not found:
        sys.exit('%s has no network embed' % rendered)
    figure = found.group(0)
    css = open(os.path.join(REPO, 'css', 'network-embed.css'), encoding='utf-8').read()
    scripts = [open(os.path.join(REPO, rel), encoding='utf-8').read() for rel in EMBED_SCRIPTS]
    os.makedirs(out_dir, exist_ok=True)
    problems = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        for width, height in ((390, 844), (768, 1024), (1440, 900)):
            page = browser.new_page(viewport={'width': width, 'height': height})
            page.on('pageerror', lambda e, w=width: problems.append('%d pageerror: %s' % (w, e)))
            page.goto('https://kidsoverprofits.org/facility/%s/' % slug, wait_until='domcontentloaded')
            page.wait_for_selector('#network', timeout=30000)
            page.add_style_tag(content=css)
            page.evaluate("""(html) => {
                const section = document.getElementById('network');
                const count = section.querySelector('.kop-fp-count');
                const holder = document.createElement('div');
                holder.innerHTML = html;
                (count || section.firstElementChild).after(holder.firstElementChild);
            }""", figure)
            for script in scripts:
                page.add_script_tag(content=script)
            page.locator('#network').scroll_into_view_if_needed()
            try:
                page.wait_for_selector('[data-kop-network-embed][data-state="ready"]', timeout=15000)
            except Exception:
                problems.append('%d: the embed never painted' % width)
            page.wait_for_timeout(1500)
            path = os.path.join(out_dir, 'embed-%s-%d.png' % (slug, width))
            page.locator('#network').screenshot(path=path)
            print('saved', path)
            page.close()
        browser.close()
    for problem in problems:
        print('PROBLEM', problem)
    return 1 if problems else 0


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--port', type=int, default=0, help='port to serve on (default: any free port)')
    parser.add_argument('--shots', metavar='DIR', help='save desktop and phone screenshots here, then exit')
    parser.add_argument('--hash', default='', help='a map address to open, e.g. "#open=wwasps"')
    parser.add_argument('--still', metavar='FILE', help='save the stage alone at 1440 px as a JPEG, then exit')
    parser.add_argument('--facility', metavar='SLUG', help='the map embed on the live /facility/SLUG/ page at 390, 768, 1440 px (with --shots DIR)')
    parser.add_argument('--config', metavar='FILE', help='JSON laid over the preview config (data URLs stay local)')
    args = parser.parse_args()
    if args.config:
        CONFIG_EXTRA.update(json.load(open(args.config, encoding='utf-8')))

    if args.facility:
        return facility(args.facility, args.shots or os.path.join(REPO, 'tmp', 'map-preview'))

    server, url = serve(args.port)
    if args.still:
        code = still(url, args.still, args.hash)
        server.shutdown()
        return code
    if args.shots:
        code = shots(url, args.shots, args.hash)
        server.shutdown()
        return code
    print('Network map preview: ' + url + args.hash)
    print('Ctrl+C to stop.')
    try:
        threading.Event().wait()
    except KeyboardInterrupt:
        server.shutdown()
    return 0


if __name__ == '__main__':
    sys.exit(main())
