#!/usr/bin/env python3
"""Check the live site for anything still pointing into the staging install.

    python scripts/check-staging-links.py            # the usual run
    python scripts/check-staging-links.py --all      # every page the mirror suspects

https://kidsoverprofits.org/staging/ is a second WordPress on the same domain.
It answers 200 and carries no noindex, so anything on the live site that links
into it sends a reader to a stale copy that no longer receives corrections.

Read the rendered page, not the database. In September 2026 a session reported
two such links on /birth-of-the-tti/ from tmp/prod.sqlite, which was five days
old; the links had already been fixed, and the rendered page had none. The
mirror is the right way to find *which* pages to look at, and the wrong way to
say what a reader sees. So the mirror only supplies the candidate list here,
and every claim comes from a fetch.

What it reports:

  - staging URLs in the rendered HTML of each candidate page, which is what a
    reader actually meets, including anything a plugin or a meta tag added
    that was never in post_content;
  - whether the staging copy is still open to the public and to crawlers.

Exit status is 1 when a reader-facing staging URL is found, so this can gate a
deploy if that is ever wanted.
"""

import argparse
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MIRROR = os.path.join(ROOT, 'tmp', 'prod.sqlite')
REPORT = os.path.join(ROOT, 'tmp', 'staging-links.md')

LIVE = 'https://kidsoverprofits.org'
STAGING = LIVE + '/staging'
UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')

URL_RE = re.compile(r'https?://kidsoverprofits\.org/staging/[^\s"\'<>)\\]*')
NOINDEX_RE = re.compile(r'<meta[^>]+name=["\']robots["\'][^>]+noindex', re.I)

# Checked on every run whatever the mirror says, because a query over
# post_content cannot see any of these: the home page's sharing tags come from
# a Code Snippets snippet, the memorial cards come from the memorial_victims
# table, and /hyde/ is the facility profile that keeps picking up staging
# images from the visual link preview block.
ALWAYS = ['/', '/in-loving-memory/', '/hyde/', '/birth-of-the-tti/',
          '/researchreports/', '/history/']

QUERY = ("select post_name, post_type from wpdl_posts "
         "where post_content like '%/staging/%' and post_status = 'publish' "
         "and post_type in ('page', 'post') and post_name != ''")


def fetch(url, timeout=60):
    request = urllib.request.Request(url, headers={'User-Agent': UA})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as err:
        return err.code, ''
    except Exception as err:                                   # noqa: BLE001
        return 0, str(err)


def candidates(mirror, check_all):
    """Pages worth fetching: the fixed list, plus what the mirror suspects."""
    paths = list(ALWAYS)
    if not os.path.exists(mirror):
        print('No mirror at %s; checking the standing list only.' % mirror)
        return paths
    conn = sqlite3.connect(mirror)
    suspect = ['/%s/' % name for name, _ in conn.execute(QUERY)]
    if not check_all:
        suspect = suspect[:25]
    for path in suspect:
        if path not in paths:
            paths.append(path)
    return paths


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--all', action='store_true',
                        help='check every page the mirror suspects, not the first 25')
    parser.add_argument('--mirror', default=MIRROR)
    parser.add_argument('--pause', type=float, default=0.3)
    args = parser.parse_args()

    paths = candidates(args.mirror, args.all)
    print('Checking %d rendered pages.\n' % len(paths))

    hits = {}
    for path in paths:
        status, html = fetch(LIVE + path)
        found = sorted({u.rstrip('.,;') for u in URL_RE.findall(html)})
        if status != 200:
            print('  %-58s HTTP %s' % (path, status))
            continue
        if found:
            hits[path] = found
            print('  %-58s %d staging URL(s)' % (path, len(found)))
        time.sleep(args.pause)

    print('\n-- The staging copy itself --')
    status, html = fetch(STAGING + '/')
    print('  %s/ answers %s' % (STAGING, status or 'nothing'))
    if status == 200:
        print('  noindex: %s' % ('yes' if NOINDEX_RE.search(html) else 'NO'))
    robots_status, robots = fetch(LIVE + '/robots.txt')
    disallowed = '/staging/' in robots
    print('  robots.txt disallows /staging/: %s' % ('yes' if disallowed else 'NO'))

    with open(REPORT, 'w', encoding='utf-8') as handle:
        handle.write('# Staging links on the live site\n\n')
        handle.write('Generated by scripts/check-staging-links.py from rendered pages.\n\n')
        if hits:
            handle.write('## Reader-facing staging URLs (%d pages)\n\n' % len(hits))
            for path in sorted(hits):
                handle.write('- `%s`\n' % path)
                for url in hits[path]:
                    handle.write('  - `%s`\n' % url)
        else:
            handle.write('No rendered page in the checked set links into /staging/.\n')
        handle.write('\n## The staging copy\n\n')
        handle.write('- `%s/` answers %s\n' % (STAGING, status or 'nothing'))
        handle.write('- robots.txt disallows /staging/: %s\n'
                     % ('yes' if disallowed else 'no'))

    print('\nReport: %s' % REPORT)
    if hits:
        total = sum(len(v) for v in hits.values())
        print('%d staging URL(s) reach a reader, on %d page(s).' % (total, len(hits)))
        return 1
    print('No checked page sends a reader into staging.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
