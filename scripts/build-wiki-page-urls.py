"""
The r/troubledteens wiki pages' addresses by title, for citations that name a
page ('r/troubledteens wiki, page "Laurel Ridge Treatment Center" (as of
2025-12-18)') but carry no link. Reads the copies scripts/wiki-source.py wrote
(tmp/wiki/text/*.txt: "WIKI <url> title='<title>'" + "[Wiki page as of <date>]")
-> js/data/reddit-wiki/page-urls.json:

  {"titles": {"<title>": "<url>"},            one page of that title
   "dated":  {"<title>|<as of>": "<url>"}}    every page, for two of one title

Read by kop_facility_pages_wiki_url() (inc/facility-pages.php).

    python scripts/wiki-source.py text && python scripts/build-wiki-page-urls.py
"""
import ast
import glob
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HEAD = re.compile(r'^WIKI (\S+) title=(.+)$')
ASOF = re.compile(r'^\[Wiki page as of (\d{4}-\d{2}-\d{2})\]')


def main():
    by_title, dated = {}, {}
    files = sorted(glob.glob(os.path.join(ROOT, 'tmp', 'wiki', 'text', '*.txt')))
    if not files:
        raise SystemExit('No tmp/wiki/text/*.txt (run scripts/wiki-source.py text first).')
    for f in files:
        with open(f, encoding='utf-8') as fh:
            lines = [fh.readline().rstrip('\n') for _ in range(3)]
        m = HEAD.match(lines[0])
        if not m:
            continue
        url, title = m.group(1), ast.literal_eval(m.group(2)).strip()
        # Wiki editor drafts (sub-<id>.txt) have no page of their own, only the wiki's front page: no link beats a wrong one.
        if not title or title == 'Page title' or url.rstrip('/').endswith('/wiki/index'):
            continue
        by_title.setdefault(title, set()).add(url)
        d = ASOF.match(lines[2])
        if d:
            dated[title + '|' + d.group(1)] = url
    titles = {t: next(iter(u)) for t, u in sorted(by_title.items()) if len(u) == 1}
    out = {'titles': titles, 'dated': dict(sorted(dated.items()))}
    path = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'page-urls.json')
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
        fh.write('\n')
    print('%d titles (%d with two pages, told apart by date), %d dated -> %s'
          % (len(by_title), len(by_title) - len(titles), len(dated), os.path.relpath(path, ROOT)))


if __name__ == '__main__':
    main()
