"""Preview the utility and legal templates with live page content.

    python scripts/preview-utility-pages.py --shots tmp/utility-preview

The offline renderer supplies the working-tree template markup. The page's
production editor content and Kadence header/footer remain live, while local
template CSS is inlined for screenshots at 390, 768 and 1440 pixels.
"""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess
import sys

REPO = Path(__file__).resolve().parent.parent
PAGES = (
    'links', 'anon-submit', 'donate', 'contact', 'no-access',
    'richardson-v-elevations-rtc-prelitigation-panel-opinion',
)

spec = importlib.util.spec_from_file_location('kop_hub_preview', REPO / 'scripts' / 'preview-hub-pages.py')
hub_preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hub_preview)


def article_for(source, parser, class_name=None):
    for node in parser.nodes:
        if node['tag'] != 'article' or node['close_start'] is None:
            continue
        if class_name and class_name not in hub_preview.classes(node):
            continue
        content = hub_preview.find_descendant(parser, node, 'entry-content')
        if content:
            return node
    return None


def content_for(parser, article, legal=False):
    if legal:
        wanted = 'kop-legal-document__pages'
        node = hub_preview.find_descendant(parser, article, wanted)
        if node:
            return node
    else:
        node = hub_preview.find_descendant(parser, article, 'kop-utility__content')
        if node:
            return node
    return hub_preview.find_descendant(parser, article, 'entry-content')


def label_opinion_images(html):
    count = 0

    def replace(match):
        nonlocal count
        count += 1
        tag = match.group(0)
        alt = 'Opinion page %d' % count
        if re.search(r'\salt\s*=', tag, re.I):
            return re.sub(r'\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)', ' alt="%s"' % alt, tag, count=1, flags=re.I)
        return re.sub(r'<img\b', '<img alt="%s"' % alt, tag, count=1, flags=re.I)

    return re.sub(r'<img\b[^>]*>', replace, html, flags=re.I)


def splice(harness_html, live_html, slug):
    legal = slug.startswith('richardson-')
    template_class = 'kop-legal-document' if legal else 'kop-utility'
    harness_parser = hub_preview.ElementParser(harness_html)
    live_parser = hub_preview.ElementParser(live_html)
    harness_article = article_for(harness_html, harness_parser, template_class)
    live_article = article_for(live_html, live_parser)
    if not harness_article or not live_article:
        raise RuntimeError('%s: cannot locate test or live article' % slug)

    article_html = harness_html[harness_article['start']:harness_article['end']]
    harness_content = content_for(harness_parser, harness_article, legal)
    live_content = content_for(live_parser, live_article, legal)
    if not harness_content or not live_content:
        raise RuntimeError('%s: cannot locate editor content' % slug)
    live_inner = live_html[live_content['open_end']:live_content['close_start']]
    if legal:
        live_inner = label_opinion_images(live_inner)
    elif slug in ('anon-submit', 'contact', 'no-access'):
        share_nodes = [
            node for node in live_parser.nodes
            if 'addtoany_share_save_container' in hub_preview.classes(node)
            and hub_preview.has_ancestor(node, live_content)
            and node['close_start'] is not None
        ]
        if not share_nodes:
            share_nodes = [
                node for node in live_parser.nodes
                if 'a2a_kit' in hub_preview.classes(node)
                and hub_preview.has_ancestor(node, live_content)
                and node['close_start'] is not None
            ]
        share_ranges = [
            (node['start'] - live_content['open_end'], node['end'] - live_content['open_end'], '')
            for node in share_nodes
        ]
        live_inner = hub_preview.replace_ranges(live_inner, share_ranges)
    start = harness_content['open_end'] - harness_article['start']
    end = harness_content['close_start'] - harness_article['start']
    article_html = hub_preview.replace_ranges(article_html, [(start, end, live_inner)])
    return hub_preview.replace_ranges(live_html, [(live_article['start'], live_article['end'], article_html)])


def inline_css(html, css_path):
    css = css_path.read_text(encoding='utf-8').replace('</style', '<\\/style')
    style = '<style id="kop-template-preview-inline">\n' + css + '\n</style>'
    if re.search(r'<base\b', html, re.I) is None:
        html = re.sub(r'(<head\b[^>]*>)', r'\1\n<base href="https://kidsoverprofits.org/">', html, count=1, flags=re.I)
    return re.sub(r'</head\s*>', style + '\n</head>', html, count=1, flags=re.I)


def render_offline():
    app_data = __import__('os').environ.get('LOCALAPPDATA')
    if not app_data:
        raise RuntimeError('LOCALAPPDATA is not set; expected Local PHP 8.2.')
    php = Path(app_data) / 'Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win32/php.exe'
    command = [str(php), '-n', str(REPO / 'scripts' / 'test-utility-pages.php')]
    result = subprocess.run(command, cwd=str(REPO), text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    print(result.stdout, end='')
    if result.returncode:
        raise RuntimeError('offline utility page checks failed')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--shots', default='tmp/utility-preview', help='Screenshot directory (default: tmp/utility-preview)')
    args = parser.parse_args()
    render_offline()
    out_dir = REPO / args.shots
    out_dir.mkdir(parents=True, exist_ok=True)
    pages = []
    for slug in PAGES:
        source = REPO / 'tmp' / 'utility-pages' / (slug + '.html')
        live = hub_preview.fetch_live(slug)
        html = splice(source.read_text(encoding='utf-8'), live, slug)
        # The legal page loads utility.css (shared breadcrumb) before its own.
        html = inline_css(html, REPO / 'css' / 'utility.css')
        if slug.startswith('richardson-'):
            html = inline_css(html, REPO / 'css' / 'legal-document.css')
        output = out_dir / (slug + '.html')
        output.write_text(html, encoding='utf-8')
        pages.append((slug, output))

    try:
        from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
    except ImportError as exc:
        raise RuntimeError('Python Playwright is required for screenshots: %s' % exc)

    failures = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        for slug, path in pages:
            for width, height in ((390, 900), (768, 1024), (1440, 1080)):
                page = browser.new_page(viewport={'width': width, 'height': height}, device_scale_factor=1)
                try:
                    try:
                        page.goto(path.resolve().as_uri(), wait_until='load', timeout=45000)
                    except PlaywrightTimeout:
                        print('WARN %s %dpx: load timed out; capturing rendered page' % (slug, width))
                    page.evaluate('window.scrollTo(0, document.body.scrollHeight)')
                    page.wait_for_timeout(600)
                    page.evaluate('window.scrollTo(0, 0)')
                    page.wait_for_timeout(300)
                    if page.evaluate('document.documentElement.scrollWidth > window.innerWidth'):
                        failures.append('%s at %dpx has horizontal overflow' % (slug, width))
                    screenshot = out_dir / ('%s-%d.png' % (slug, width))
                    page.screenshot(path=str(screenshot), full_page=True)
                    print('saved', screenshot)
                finally:
                    page.close()
        browser.close()
    if failures:
        for failure in failures:
            print('FAIL', failure)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
