"""Gather drafted parent company histories into seeds/operator-histories.json.

Reads tmp/operator-histories/<slug>.json (written by research drafts, see
tmp/operator-histories/BRIEF.md) and writes the seed that
inc/operator-history.php applies on deploy: each company whose record has no
history gets this one as a draft, seen by admins only until it is published
with the pencil on its /operator/ page.

Refuses a draft that cites web.archive.org (archived copies must be imported
first), has a link that is not http(s), or cites a link missing from its
source list. Prints reviewer notes so they can be read before committing.

    python scripts/build-operator-histories.py [--dir tmp/operator-histories]
"""
import argparse
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LINK = re.compile(r'\[([^\]]+)\]\(([^)\s]+)\)')


def check(d):
    problems = []
    if not d.get('name') or not isinstance(d.get('history'), list) or not d['history']:
        return ['no name or no history']
    source_urls = {s.get('url', '').rstrip('/') for s in d.get('sources', [])}
    for p in d['history']:
        if not isinstance(p, str) or not p.strip():
            problems.append('an empty paragraph')
            continue
        if re.search('[\U0001F300-\U0001FAFF☀-➿]', p):
            problems.append('an emoji')
        for _, url in LINK.findall(p):
            if not re.match(r'^https?://', url):
                problems.append('a link that is not http(s): ' + url)
            if 'web.archive.org' in url:
                problems.append('an archive.org link: ' + url)
            if url.rstrip('/') not in source_urls:
                problems.append('a link missing from sources: ' + url)
    for s in d.get('sources', []):
        if 'web.archive.org' in s.get('url', ''):
            problems.append('an archive.org source: ' + s['url'])
    return problems


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default=os.path.join(ROOT, 'tmp', 'operator-histories'))
    args = ap.parse_args()
    companies = []
    bad = 0
    for path in sorted(glob.glob(os.path.join(args.dir, '*.json'))):
        with open(path, encoding='utf-8') as f:
            d = json.load(f)
        problems = check(d)
        words = sum(len(p.split()) for p in d.get('history', []) if isinstance(p, str))
        print('%-60s %4d words %2d sources%s' % (d.get('slug') or os.path.basename(path), words, len(d.get('sources', [])),
                                                ('  REFUSED: ' + '; '.join(problems)) if problems else ''))
        if d.get('reviewer_notes'):
            print('    notes: ' + d['reviewer_notes'][:400])
        if problems:
            bad += 1
            continue
        companies.append({
            'name': d['name'],
            'history': [p.strip() for p in d['history']],
            'sources': [{'label': s.get('label', '').strip(), 'url': s.get('url', '').strip()} for s in d.get('sources', [])],
            'reviewer_notes': d.get('reviewer_notes', ''),
        })
    out = os.path.join(ROOT, 'seeds', 'operator-histories.json')
    with open(out, 'w', encoding='utf-8', newline='\n') as f:
        json.dump({'about': 'Draft parent company histories; applied as drafts by inc/operator-history.php. '
                            'Rebuilt by scripts/build-operator-histories.py.',
                   'companies': companies}, f, ensure_ascii=False, indent=1)
        f.write('\n')
    print('\n%d drafts written to %s, %d refused' % (len(companies), out, bad))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
