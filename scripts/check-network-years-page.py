"""Click through the Map Years review screen in a browser, offline.

    php scripts/test-network-years.php          # renders tmp/network-years/page.html
    python scripts/check-network-years-page.py  # clicks it; screenshots in tmp/network-years/

The page's saves go to admin-ajax.php; here they are answered by a stub that
echoes back what a real save stores, so the page's own code runs: accept a
card, see it move to Accepted with its years, undo it, accept all the
high-confidence ones, and the phone width. Needs Python Playwright.
"""
import json
import os
import sys
from urllib.parse import parse_qs

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(REPO, 'tmp', 'network-years')
PAGE = os.path.join(OUT, 'page.html')


def fmt(start, end):
    a, b = int(start or 0), int(end or 0)
    if a and b:
        return str(a) if a == b else '%d-%d' % (a, b)
    return 'from %d' % a if a else ('until %d' % b if b else '')


def answer(route):
    body = route.request.post_data_buffer or b''
    ctype = route.request.headers.get('content-type', '')
    items = []
    if 'multipart' in ctype:
        text = body.decode('utf-8', 'replace')
        marker = 'name="items"'
        at = text.find(marker)
        if at != -1:
            chunk = text[at + len(marker):].split('\r\n\r\n', 1)[1]
            items = json.loads(chunk.split('\r\n--', 1)[0])
    else:
        items = json.loads(parse_qs(body.decode()).get('items', ['[]'])[0])
    saved = {}
    for item in items:
        if item['decision'] == 'undo':
            saved[item['id']] = {'decision': '', 'years': ''}
        elif item['decision'] == 'reject':
            saved[item['id']] = {'decision': 'rejected', 'years': ''}
        else:
            years = fmt(item.get('start'), item.get('end'))
            saved[item['id']] = {'decision': 'accepted', 'years': years} if years else {'error': 'Give an opening year.'}
    route.fulfill(status=200, content_type='application/json',
                  body=json.dumps({'success': True, 'data': {'saved': saved}}))


def main():
    from playwright.sync_api import sync_playwright
    if not os.path.exists(PAGE):
        sys.exit('Run php scripts/test-network-years.php first.')
    problems = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        page = browser.new_page(viewport={'width': 1280, 'height': 900})
        page.on('pageerror', lambda e: problems.append('pageerror: %s' % e))
        page.route('**/admin-ajax.php', answer)
        page.goto('file:///' + PAGE.replace('\\', '/'))
        tabs = lambda: page.locator('.kop-years__tabs').inner_text().replace('\n', ' ')
        print('tabs:', tabs())
        page.screenshot(path=os.path.join(OUT, 'review.png'), full_page=False)
        cards = page.locator('.kop-years__card')
        if cards.count():
            name = cards.first.locator('.kop-years__name').inner_text()
            cards.first.get_by_role('button', name='Accept').click()
            page.wait_for_timeout(300)
            page.get_by_role('tab', name='Accepted').click()
            done = page.locator('.kop-years__card', has_text=name)
            if not done.count() or 'Accepted' not in done.first.inner_text():
                problems.append('accepting %s did not move it to Accepted' % name)
            else:
                print('accepted:', done.first.locator('.kop-years__done').inner_text())
                done.first.get_by_role('button', name='Undo').click()
                page.wait_for_timeout(300)
                page.get_by_role('tab', name='To review').click()
                if not page.locator('.kop-years__card', has_text=name).count():
                    problems.append('undo did not bring %s back to review' % name)
            bulk = page.locator('.kop-years__bulk')
            if bulk.is_visible():
                label = bulk.inner_text()
                bulk.click()
                page.wait_for_timeout(300)
                print(label, '->', tabs())
        page.set_viewport_size({'width': 390, 'height': 844})
        page.get_by_role('tab', name='To review').click()
        page.screenshot(path=os.path.join(OUT, 'review-phone.png'))
        browser.close()
    for p in problems:
        print('PROBLEM', p)
    print('saved screenshots in', OUT)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
