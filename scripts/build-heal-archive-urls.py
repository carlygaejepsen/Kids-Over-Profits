"""
HEAL's site (heal-online.org, 2004-2022) is gone, and the domain was later parked and filled with spam, so a
link to it is a link to that. This maps every HEAL address the Wayback Machine holds to its latest capture from
before 2023, when HEAL still ran the site (later captures are the squatter's, even when they look like HEAL).
Reads tmp/heal/cdx.txt (scripts/heal-archive.py cdx) -> js/data/reddit-wiki/heal-archive-urls.json:

  {"<page key>": "https://web.archive.org/web/<timestamp>/<address>"}

Key: the file name, lower case, ".html" read as ".htm"; "" is the home page. An address with no capture before
2023 is left out (its link stays as written). Read by reddit_format() in scripts/wiki-drafts.py and
kop_wiki_drafts_reddit_format() in inc/wiki-update-drafts.php, which rewrite heal-online.org links in wiki entries.

    python scripts/heal-archive.py cdx && python scripts/build-heal-archive-urls.py
"""
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CDX = os.path.join(ROOT, 'tmp', 'heal', 'cdx.txt')
OUT = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'heal-archive-urls.json')


def key_of(url):
    """heal-online.org address -> its page key (file name, lower case, .html as .htm)."""
    path = re.sub(r'^[a-z]+://(www\.)?heal-online\.org(:\d+)?', '', url.strip(), flags=re.I).split('?')[0].split('#')[0]
    name = path.strip('/').split('/')[-1].lower() if path.strip('/') else ''
    if name in ('index.htm', 'index.html', 'default.htm', 'default.html'):
        name = ''
    return re.sub(r'\.html$', '.htm', name)


def main():
    best = {}
    with open(CDX, encoding='utf-8') as f:
        for line in f:
            parts = line.split()
            if len(parts) < 4:
                continue
            ts, orig, mime = parts[1], parts[2], parts[3]
            if ts >= '2023' or '/' in re.sub(r'^[a-z]+://[^/]+/?', '', orig).strip('/').split('?')[0]:
                continue   # after HEAL let the domain go, or not a root-level page
            k = key_of(orig)
            want_pdf = k.endswith('.pdf')
            if want_pdf != ('pdf' in mime) or (not want_pdf and 'html' not in mime):
                continue
            if k not in best or ts > best[k][0]:
                best[k] = (ts, re.sub(r':80(?=/|$)', '', orig))
    out = {k: f'https://web.archive.org/web/{ts}/{orig}' for k, (ts, orig) in sorted(best.items())}
    with open(OUT, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(out, f, indent=0, sort_keys=True)
        f.write('\n')
    print(f'{len(out)} HEAL addresses with a capture before 2023 -> {os.path.relpath(OUT, ROOT)}')


if __name__ == '__main__':
    main()
