"""Render the live hub pages with the working tree's hub frame and screenshot them.

    python scripts/preview-hub-pages.py
    python scripts/preview-hub-pages.py history law-policy --shots tmp/hub-preview

The script first runs the offline PHP renderer, then fetches the live page over
SSH. It keeps the live editor content (including shortcode output), replaces
the hub article with the tested working-tree article, and inlines css/hub.css.
Screenshots are saved at 390, 768 and 1440 pixels wide.
"""
import argparse
from html.parser import HTMLParser
import os
from pathlib import Path
import random
import shlex
import subprocess
import sys

REPO = Path(__file__).resolve().parent.parent
HUBS = (
    'history', 'survivors', 'researchreports', 'families',
    'where-are-the-kids', 'advocates', 'journalists',
    'volunteer', 'law-policy', 'resources', 'editorials',
    'investigatory-spotlight',
)
USER_AGENT = (
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    'Chrome/128 Safari/537.36'
)
VOID_TAGS = {
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
    'meta', 'param', 'source', 'track', 'wbr',
}


class ElementParser(HTMLParser):
    """Record source offsets for elements without reserializing the markup."""

    def __init__(self, source):
        super().__init__(convert_charrefs=False)
        self.source = source
        self.line_starts = [0]
        for line in source.splitlines(keepends=True):
            self.line_starts.append(self.line_starts[-1] + len(line))
        self.nodes = []
        self.stack = []
        self.feed(source)

    def source_offset(self):
        line, column = self.getpos()
        return self.line_starts[line - 1] + column

    def handle_starttag(self, tag, attrs):
        start = self.source_offset()
        raw = self.get_starttag_text()
        node = {
            'tag': tag.lower(), 'attrs': dict(attrs), 'start': start,
            'open_end': start + len(raw), 'close_start': None,
            'end': start + len(raw), 'parent': self.stack[-1] if self.stack else None,
        }
        self.nodes.append(node)
        if tag.lower() not in VOID_TAGS:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        start = self.source_offset()
        raw = self.get_starttag_text()
        self.nodes.append({
            'tag': tag.lower(), 'attrs': dict(attrs), 'start': start,
            'open_end': start + len(raw), 'close_start': start + len(raw),
            'end': start + len(raw), 'parent': self.stack[-1] if self.stack else None,
        })

    def handle_endtag(self, tag):
        tag = tag.lower()
        for index in range(len(self.stack) - 1, -1, -1):
            if self.stack[index]['tag'] == tag:
                node = self.stack[index]
                close_start = self.source_offset()
                close_end = self.source.find('>', close_start)
                node['close_start'] = close_start
                node['end'] = close_end + 1 if close_end >= 0 else len(self.source)
                del self.stack[index:]
                return


def classes(node):
    return set((node['attrs'].get('class') or '').split())


def has_ancestor(node, ancestor):
    parent = node['parent']
    while parent is not None:
        if parent is ancestor:
            return True
        parent = parent['parent']
    return False


def find_article(source):
    parser = ElementParser(source)
    return next((node for node in parser.nodes
                 if node['tag'] == 'article' and 'kop-hub' in classes(node)
                 and node['close_start'] is not None), None), parser


def find_descendant(parser, parent, class_name):
    return next((node for node in parser.nodes
                 if class_name in classes(node) and has_ancestor(node, parent)
                 and node['close_start'] is not None), None)


def replace_ranges(source, replacements):
    for start, end, value in sorted(replacements, key=lambda item: item[0], reverse=True):
        source = source[:start] + value + source[end:]
    return source


def splice_article(harness_html, live_html, slug):
    harness_article, harness_parser = find_article(harness_html)
    live_article, live_parser = find_article(live_html)
    if not harness_article:
        raise RuntimeError('%s: test output has no .kop-hub article' % slug)
    if not live_article:
        raise RuntimeError('%s: live HTML has no .kop-hub article' % slug)

    harness_fragment = harness_html[harness_article['start']:harness_article['end']]
    harness_content = find_descendant(harness_parser, harness_article, 'entry-content')
    live_content = find_descendant(live_parser, live_article, 'entry-content')
    changes = []
    if harness_content and live_content:
        live_content_html = live_html[live_content['open_end']:live_content['close_start']]
        inner_start = harness_content['open_end'] - harness_article['start']
        inner_end = harness_content['close_start'] - harness_article['start']
        changes.append((inner_start, inner_end, live_content_html))
    elif harness_content or live_content:
        raise RuntimeError('%s: live and test output disagree about editor content' % slug)

    # These modules have independent tests rather than the DB stubs needed by
    # the hub harness. Use production-rendered module HTML with the working-
    # tree frame and CSS so the screenshots still include the content.
    if slug in ('researchreports', 'resources'):
        harness_modules = find_descendant(harness_parser, harness_article, 'kop-hub-modules')
        live_modules = find_descendant(live_parser, live_article, 'kop-hub-modules')
        if not harness_modules and live_modules:
            if not harness_content:
                raise RuntimeError('%s: cannot place its live module without editor content' % slug)
            module_html = live_html[live_modules['start']:live_modules['end']]
            insertion = harness_content['end'] - harness_article['start']
            changes.append((insertion, insertion, '\n' + module_html + '\n'))
    harness_fragment = replace_ranges(harness_fragment, changes)

    live_share = next((node for node in live_parser.nodes
                       if 'a2a_kit' in classes(node)
                       and has_ancestor(node, live_article)
                       and node['close_start'] is not None), None)
    harness_share_parser = ElementParser(harness_fragment)
    harness_article_after, _ = find_article(harness_fragment)
    harness_share = next((node for node in harness_share_parser.nodes
                          if 'a2a_kit' in classes(node)
                          and node['close_start'] is not None), None)
    if live_share and harness_share and harness_article_after:
        share_html = live_html[live_share['start']:live_share['end']]
        harness_fragment = replace_ranges(
            harness_fragment,
            [(harness_share['start'], harness_share['end'], share_html)],
        )
    else:
        raise RuntimeError('%s: could not map the share placeholder to live AddToAny markup' % slug)

    return replace_ranges(live_html, [(live_article['start'], live_article['end'], harness_fragment)])


def local_php():
    local_app_data = os.environ.get('LOCALAPPDATA')
    if not local_app_data:
        raise RuntimeError('LOCALAPPDATA is not set; expected Local PHP 8.2.')
    php = Path(local_app_data) / 'Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win32/php.exe'
    if not php.is_file():
        raise RuntimeError('Local PHP was not found at %s' % php)
    return php


def run_hub_test():
    php = local_php()
    extension_dir = php.parent / 'ext'
    command = [
        str(php), '-n', '-d', 'extension_dir=' + str(extension_dir),
        '-d', 'extension=pdo_sqlite', '-d', 'extension=mbstring',
        str(REPO / 'scripts' / 'test-hub-pages.php'),
    ]
    result = subprocess.run(command, cwd=str(REPO), text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    print(result.stdout, end='')
    if result.returncode:
        raise RuntimeError('test-hub-pages.php failed with exit code %d' % result.returncode)


def fetch_live(slug):
    key = Path.home() / '.ssh' / 'kop_nixihost'
    if not key.is_file():
        raise RuntimeError('SSH key not found: %s' % key)
    url = 'https://kidsoverprofits.org/%s/?nc=%d' % (slug, random.randrange(1, 2 ** 31))
    remote = 'curl -fsS --max-time 45 -A %s %s' % (shlex.quote(USER_AGENT), shlex.quote(url))
    command = [
        'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-i', str(key),
        '-p', '1157', 'kidsover@dfw-s07.nixihost.com', remote,
    ]
    result = subprocess.run(command, cwd=str(REPO), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        message = result.stderr.decode('utf-8', errors='replace').strip()
        raise RuntimeError('%s: production fetch failed: %s' % (slug, message))
    return result.stdout.decode('utf-8', errors='replace')


def inline_hub_css(html):
    css_path = REPO / 'css' / 'hub.css'
    css = css_path.read_text(encoding='utf-8').replace('</style', '<\\/style')
    import re
    pattern = re.compile(r'<link\b(?=[^>]*\bhref=["\'][^"\']*/css/hub\.css(?:\?[^"\']*)?["\'])[^>]*>', re.I)
    html, count = pattern.subn('<style id="kop-hub-preview-inline">\n' + css + '\n</style>', html, count=1)
    if not count:
        raise RuntimeError('live HTML has no css/hub.css link to replace')
    if not re.search(r'<base\b', html, re.I):
        html = re.sub(r'(<head\b[^>]*>)', r'\1\n<base href="https://kidsoverprofits.org/">', html, count=1, flags=re.I)
    return html


def take_screenshots(pages, out_dir):
    try:
        from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
    except ImportError as exc:
        raise RuntimeError('Python Playwright is required for screenshots: %s' % exc)

    out_dir.mkdir(parents=True, exist_ok=True)
    failures = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        for slug, page_path in pages:
            for width, height in ((390, 900), (768, 1024), (1440, 1080)):
                page = browser.new_page(viewport={'width': width, 'height': height}, device_scale_factor=1)
                page_path_uri = page_path.resolve().as_uri()
                try:
                    try:
                        page.goto(page_path_uri, wait_until='load', timeout=45000)
                    except PlaywrightTimeout:
                        print('WARN %s %dpx: page load timed out; capturing rendered page' % (slug, width))
                    page.wait_for_timeout(1200)
                    overflow = page.evaluate('document.documentElement.scrollWidth > window.innerWidth')
                    screenshot = out_dir / ('%s-%d.png' % (slug, width))
                    page.screenshot(path=str(screenshot), full_page=True)
                    print('saved', screenshot)
                    if overflow:
                        failures.append('%s at %dpx has horizontal overflow' % (slug, width))
                finally:
                    page.close()
        browser.close()
    for failure in failures:
        print('FAIL', failure)
    return failures


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('slugs', nargs='*', choices=HUBS, help='hub slugs (default: all 12)')
    parser.add_argument('--shots', default='tmp/hub-preview', metavar='DIR',
                        help='output directory for HTML and screenshots (default: tmp/hub-preview)')
    args = parser.parse_args()
    slugs = args.slugs or list(HUBS)
    out_dir = Path(args.shots)
    if not out_dir.is_absolute():
        out_dir = REPO / out_dir
    pages_dir = out_dir / 'pages'
    pages_dir.mkdir(parents=True, exist_ok=True)

    run_hub_test()
    rendered_dir = REPO / 'tmp' / 'hub-pages'
    pages = []
    for slug in slugs:
        rendered = rendered_dir / (slug + '.html')
        if not rendered.is_file():
            raise RuntimeError('%s: missing output from test-hub-pages.php: %s' % (slug, rendered))
        live = fetch_live(slug)
        preview = inline_hub_css(splice_article(rendered.read_text(encoding='utf-8'), live, slug))
        page_path = pages_dir / (slug + '.html')
        page_path.write_text(preview, encoding='utf-8')
        pages.append((slug, page_path))
        print('prepared', slug)

    failures = take_screenshots(pages, out_dir)
    return 1 if failures else 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, RuntimeError) as exc:
        sys.exit(str(exc))
