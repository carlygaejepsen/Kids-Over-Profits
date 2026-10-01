"""
Copy of the Fornits forum's treatment-abuse boards (fornits.com/phpbb, SMF),
one polite request at a time, for the facility mention, staff, testimony and
lead passes that read it afterwards.

    python scripts/fornits-crawl.py [--out tmp/fornits] [--delay 3] [--boards 39,77] [--limit N]
    python scripts/fornits-crawl.py --parse-only     # rebuild posts.jsonl from the saved pages

Two steps, both resumable (rerun the same command after a stop):
  1. topics: every listing page of each board -> topics.jsonl (id, board,
     title, starter, replies). A board is listed again only with --relist.
  2. pages:  each topic's print view (the whole thread on one page) ->
     raw/<id>.html.gz, then parsed into posts.jsonl (one line per post:
     topic, board, title, n, author, date, text).

Boards: category 3 ("Treatment Abuse, Behavior Modification, Thought Reform")
less Migrant Detention (82) and Sea Org (64). robots.txt allows /phpbb/.
Everything stays in tmp/fornits/ (gitignored); the repository is public, so
the forum's posts are never committed.
"""

import argparse
import datetime
import gzip
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE = 'https://www.fornits.com/phpbb/index.php'
UA = 'Mozilla/5.0 (compatible; KidsOverProfits-research/1.0; +https://kidsoverprofits.org)'

BOARDS = {
    52: 'Facility Question and Answers', 71: 'Psych Hospitals', 72: 'Three Springs',
    77: 'CALO - Change Academy at Lake of the Ozarks', 59: 'CAN ~ Collective Action Network',
    60: 'Roloff', 61: 'Kids Helping Kids', 62: 'Peninsula Village', 63: 'CEDU (and derivatives)',
    75: 'Elan', 78: 'AARC', 49: 'News Items', 9: 'The Troubled Teen Industry',
    44: 'World Wide Association of Specialty Programs and Schools (WWASPS)',
    39: 'Thayer Learning Center', 11: 'CEDU / Brown Schools and derivatives / clones',
    56: 'Benchmark Young Adult School / Benchmark Transitions', 7: 'Straight, Inc. and Derivatives',
    48: 'Aspen Education Group', 37: 'Brat Camp', 51: 'Public Sector Gulags',
    41: 'The Ridge Creek School / Hidden Lake Academy', 2: 'Elan School', 34: 'Elan history',
    30: 'Vision Quest', 31: 'Daytop Village', 35: 'Who Am I Discovery/Whitmore',
    36: 'Lighthouse of northwest florida (fka VCA) / Rebekah / Roloff', 38: 'Mission Mountain School',
    43: 'Hyde Schools', 45: 'Synanon', 8: 'The Seed Discussion Forum', 57: 'Teen Challenge',
    29: 'Spouses of Survivors', 40: 'EdCons and referring organizations and agencies',
    54: 'PURE Bullshit and CAICA', 14: 'New Info', 19: 'Morgan Yacht',
    6: 'Straight, Inc-By-The-Sea', 5: "Joe's Apartment",
    73: 'Troubled Teen Industry.com - Program Website Division', 74: 'Research Banditos',
    79: 'The Drama Box',
}


class Fetcher:
    def __init__(self, delay):
        self.delay = delay
        self.last = 0.0

    def get(self, url):
        for attempt in range(20):
            wait = self.last + self.delay - time.time()
            if wait > 0:
                time.sleep(wait)
            self.last = time.time()
            try:
                req = urllib.request.Request(url, headers={'User-Agent': UA})
                with urllib.request.urlopen(req, timeout=60) as r:
                    return r.read().decode('utf-8', errors='replace')
            except urllib.error.HTTPError as e:
                if e.code == 404:
                    return None
                back = 60 * (attempt + 1) if e.code in (429, 503) else 10 * (attempt + 1)
                log(f'  HTTP {e.code} on {url}, waiting {back}s')
                time.sleep(back)
            except Exception as e:
                # Usually this machine's network (sleep, Wi-Fi, DNS): wait it out, up to ten minutes a try.
                log(f'  {type(e).__name__} on {url}: {e}, retrying')
                time.sleep(min(600, 30 * (attempt + 1)))
        raise RuntimeError(f'gave up on {url}')


def log(msg):
    print(datetime.datetime.now().strftime('%H:%M:%S'), msg, flush=True)


def clean(fragment):
    s = re.sub(r'<br\s*/?>', '\n', fragment, flags=re.I)
    s = re.sub(r'</(p|div|blockquote|li|tr)>', '\n', s, flags=re.I)
    s = re.sub(r'<cite>(.*?)</cite>', r'[quote \1]\n', s, flags=re.I | re.S)
    s = re.sub(r'<[^>]+>', '', s)
    s = html.unescape(s).replace('\xa0', ' ')
    s = re.sub(r'[ \t]+', ' ', s)
    s = re.sub(r'\n\s*\n+', '\n\n', s)
    return s.strip()


def parse_date(s):
    s = s.strip()
    for fmt in ('%B %d, %Y, %I:%M:%S %p', '%B %d, %Y, %I:%M %p'):
        try:
            return datetime.datetime.strptime(s, fmt).strftime('%Y-%m-%dT%H:%M:%S')
        except ValueError:
            pass
    return s


def list_board(f, board):
    """All topics of one board, following its listing pages."""
    topics, start, last = [], 0, None
    while True:
        page = f.get(f'{BASE}?board={board}.{start}')
        if page is None:
            break
        if last is None:
            offs = [int(x) for x in re.findall(rf'board={board}\.(\d+)"', page)]
            last = max(offs) if offs else 0
        for m in re.finditer(r'<span id="msg_\d+"><a href="[^"]*topic=(\d+)\.0"[^>]*>(.*?)</a>', page, re.S):
            topics.append({'id': int(m.group(1)), 'board': board, 'title': clean(m.group(2))})
        start += 20
        if start > last:
            break
    return topics


POST_RE = re.compile(
    r'<div class="postheader">\s*Title:\s*<strong>(.*?)</strong><br>\s*Post by:\s*<strong>(.*?)</strong>\s*on\s*<strong>(.*?)</strong>\s*</div>\s*'
    r'<div class="postbody">(.*?)</div><!-- \.postbody -->', re.S)


INNER_QUOTE = re.compile(r'<blockquote([^>]*)>((?:(?!<blockquote).)*?)</blockquote>', re.S | re.I)


def drop_quotes(body):
    """A post's own words: quoted earlier posts become "[quoting X]" and
    signatures (a plain <blockquote>, repeated under every post) go."""
    def sub(m):
        if 'quote' not in m.group(1):
            return ''
        who = re.search(r'(?:On [^,<]+, )?([^<]{1,60}?) wrote:', m.group(2))
        return f' [quoting {who.group(1).strip()}] ' if who else ' [quoting an earlier post] '
    prev = None
    while prev != body:
        prev, body = body, INNER_QUOTE.sub(sub, body)
    return body


def parse_topic(tid, board, page):
    posts = []
    for n, m in enumerate(POST_RE.finditer(page)):
        posts.append({
            'topic': tid, 'board': board, 'n': n,
            'title': clean(m.group(1)), 'author': clean(m.group(2)),
            'date': parse_date(clean(m.group(3))), 'text': clean(drop_quotes(m.group(4))),
        })
    return posts


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default='tmp/fornits')
    ap.add_argument('--delay', type=float, default=3.0)
    ap.add_argument('--boards', help='comma list of board ids (default: all TTI boards)')
    ap.add_argument('--limit', type=int, help='stop after this many new topic pages')
    ap.add_argument('--relist', action='store_true', help='list boards again even if already listed')
    ap.add_argument('--parse-only', action='store_true')
    a = ap.parse_args()

    boards = [int(b) for b in a.boards.split(',')] if a.boards else list(BOARDS)
    raw = os.path.join(a.out, 'raw')
    os.makedirs(raw, exist_ok=True)
    topics_path = os.path.join(a.out, 'topics.jsonl')
    listed_path = os.path.join(a.out, 'listed.json')
    f = Fetcher(a.delay)
    full = not a.boards and not a.limit and not a.parse_only
    if full:
        # scripts/fornits-process.py --keep-crawling restarts a full crawl that died.
        open(os.path.join(a.out, 'crawl.pid'), 'w').write(str(os.getpid()))

    topics = {}
    if os.path.exists(topics_path):
        for line in open(topics_path, encoding='utf-8'):
            t = json.loads(line)
            topics[t['id']] = t
    listed = json.load(open(listed_path)) if os.path.exists(listed_path) else []

    if not a.parse_only:
        for b in boards:
            if b in listed and not a.relist:
                continue
            log(f'listing board {b} {BOARDS.get(b, "")}')
            found = list_board(f, b)
            with open(topics_path, 'a', encoding='utf-8') as out:
                for t in found:
                    if t['id'] not in topics:
                        topics[t['id']] = t
                        out.write(json.dumps(t, ensure_ascii=False) + '\n')
            listed.append(b)
            json.dump(listed, open(listed_path, 'w'))
            log(f'  {len(found)} topics, {len(topics)} in all')

        todo = [t for t in topics.values() if t['board'] in boards
                and not os.path.exists(os.path.join(raw, f'{t["id"]}.html.gz'))]
        todo.sort(key=lambda t: t['id'])
        if a.limit:
            todo = todo[:a.limit]
        log(f'{len(todo)} topic pages to fetch')
        for i, t in enumerate(todo, 1):
            page = f.get(f'{BASE}?action=printpage;topic={t["id"]}.0')
            if page is None:
                page = ''
            tmp = os.path.join(raw, f'{t["id"]}.html.gz.part')
            with gzip.open(tmp, 'wt', encoding='utf-8') as g:
                g.write(page)
            os.replace(tmp, os.path.join(raw, f'{t["id"]}.html.gz'))
            if i % 100 == 0:
                log(f'  {i}/{len(todo)} pages')

    # Parse every saved page into posts.jsonl (cheap; rebuilt whole each run).
    n_posts = n_topics = 0
    tmp = os.path.join(a.out, 'posts.jsonl.part')
    with open(tmp, 'w', encoding='utf-8') as out:
        for tid in sorted(topics):
            p = os.path.join(raw, f'{tid}.html.gz')
            if not os.path.exists(p):
                continue
            page = gzip.open(p, 'rt', encoding='utf-8').read()
            posts = parse_topic(tid, topics[tid]['board'], page)
            if posts:
                n_topics += 1
            for post in posts:
                out.write(json.dumps(post, ensure_ascii=False) + '\n')
                n_posts += 1
    os.replace(tmp, os.path.join(a.out, 'posts.jsonl'))
    log(f'posts.jsonl: {n_posts} posts from {n_topics} topics')
    if full:
        open(os.path.join(a.out, 'crawl.done'), 'w').write(datetime.datetime.now().isoformat())


if __name__ == '__main__':
    sys.exit(main())
