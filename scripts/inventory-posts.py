#!/usr/bin/env python3
"""Inventory published WordPress posts using the refreshed production mirror.

    python scripts/inventory-posts.py

Writes tmp/post-inventory.csv. Inbound links count published page/post content
that links to the post's public permalink; the row counts each referring item
once even if it links several times.
"""
import argparse
import csv
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sqlite3
import sys
from urllib.parse import unquote, urljoin, urlsplit

REPO = Path(__file__).resolve().parent.parent
MIRROR = REPO / 'tmp' / 'prod.sqlite'
OUT = REPO / 'tmp' / 'post-inventory.csv'
NEWS_CATEGORIES = {
    'news', 'local news', 'us news', 'international news',
    'independent journalism',
}
TOOL_SLUGS = {'facility-form-test', 'data-organizer', 'data', 'data-analysis'}


class LinkParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.hrefs = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() == 'a':
            href = dict(attrs).get('href')
            if href:
                self.hrefs.append(href)


def normalized_url(url, base):
    value = urljoin(base, (url or '').strip())
    parts = urlsplit(value)
    host = (parts.hostname or '').lower()
    if host.startswith('www.'):
        host = host[4:]
    if host and host not in ('kidsoverprofits.org', 'staging.kidsoverprofits.org'):
        return ''
    path = unquote(parts.path or '/')
    path = re.sub(r'^/staging(?=/|$)', '', path, flags=re.I)
    path = re.sub(r'/+', '/', path)
    path = '/' + path.strip('/') + '/' if path.strip('/') else '/'
    return path.casefold()


def normalized_title(value):
    return re.sub(r'[^a-z0-9]+', '', (value or '').casefold())


def read_news_feed(conn, base):
    by_id, by_url, by_title = set(), set(), set()
    for row in conn.execute("SELECT article_title, alternate_title, article_url, json_data FROM news_submissions WHERE status <> 'deleted'"):
        try:
            data = json.loads(row['json_data'] or '{}')
        except (TypeError, json.JSONDecodeError):
            data = {}
        source_id = data.get('source_post_id')
        if source_id:
            by_id.add(int(source_id))
        for candidate in (row['article_url'], data.get('source_post_url')):
            key = normalized_url(candidate, base) if candidate else ''
            if key:
                by_url.add(key)
        for candidate in (row['article_title'], row['alternate_title'], data.get('title')):
            key = normalized_title(candidate)
            if key:
                by_title.add(key)
    return by_id, by_url, by_title


def proposed_class(post, categories):
    slug = post['post_name']
    category_keys = {value.casefold() for value in categories}
    template = post['template']
    if template == 'templates/single-facility-profile.php' or 'facility profile' in category_keys:
        return 'facility-profile'
    if 'lawsuits' in category_keys:
        return 'legal-record'
    if category_keys & NEWS_CATEGORIES:
        return 'news'
    if re.search(r'\b(v\.?|vs\.?|lawsuit|complaint|court opinion|settlement)\b', post['post_title'], re.I):
        return 'legal-record'
    if slug in TOOL_SLUGS:
        return 'tool-or-test'
    if slug == 'arizona-adhs-inspections':
        return 'obsolete'
    return 'article'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--db', type=Path, default=MIRROR, help='SQLite production mirror (default: tmp/prod.sqlite)')
    parser.add_argument('--out', type=Path, default=OUT, help='CSV output path (default: tmp/post-inventory.csv)')
    args = parser.parse_args()
    if not args.db.is_file():
        raise SystemExit('Production mirror not found: %s (run python scripts/sync-prod-sqlite.py first)' % args.db)

    conn = sqlite3.connect(str(args.db))
    conn.row_factory = sqlite3.Row
    options = {row['option_name']: row['option_value'] for row in conn.execute(
        "SELECT option_name, option_value FROM wpdl_options WHERE option_name IN ('home','siteurl')"
    )}
    base = options.get('home') or options.get('siteurl') or 'https://kidsoverprofits.org'

    posts = conn.execute(
        "SELECT ID, post_author, post_date, post_content, post_title, post_status, post_name, post_modified, post_type "
        "FROM wpdl_posts WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC, ID DESC"
    ).fetchall()
    post_ids = {int(row['ID']) for row in posts}

    categories = {}
    for row in conn.execute(
        "SELECT tr.object_id, t.name FROM wpdl_term_relationships tr "
        "JOIN wpdl_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='category' "
        "JOIN wpdl_terms t ON t.term_id=tt.term_id"
    ):
        categories.setdefault(int(row['object_id']), []).append(row['name'])

    template_meta, thumbnail_meta = {}, {}
    for row in conn.execute(
        "SELECT post_id, meta_key, meta_value FROM wpdl_postmeta "
        "WHERE meta_key IN ('_wp_page_template','_thumbnail_id')"
    ):
        if row['meta_key'] == '_wp_page_template':
            template_meta[int(row['post_id'])] = row['meta_value'] or ''
        else:
            thumbnail_meta[int(row['post_id'])] = row['meta_value'] or ''
    attachment_ids = {int(row['ID']) for row in conn.execute(
        "SELECT ID FROM wpdl_posts WHERE post_type='attachment' AND post_status IN ('inherit','publish')"
    )}

    inbound_sources = {pid: set() for pid in post_ids}
    target_paths = {pid: normalized_url(base + '/' + row['post_name'] + '/', base) for pid, row in ((int(p['ID']), p) for p in posts)}
    route_to_id = {path: pid for pid, path in target_paths.items()}
    content_rows = conn.execute(
        "SELECT ID, post_content FROM wpdl_posts WHERE post_type IN ('post','page') AND post_status='publish'"
    ).fetchall()
    for source in content_rows:
        source_id = int(source['ID'])
        links = LinkParser()
        links.feed(source['post_content'] or '')
        for href in links.hrefs:
            target_id = route_to_id.get(normalized_url(href, base))
            if target_id is not None and target_id != source_id:
                inbound_sources[target_id].add(source_id)

    feed_ids, feed_urls, feed_titles = read_news_feed(conn, base)
    output_rows = []
    counts = {}
    news_like_rows, exact_news_rows, exact_news_in_feed, missing_feed = 0, 0, 0, []
    for post in posts:
        pid = int(post['ID'])
        cats = sorted(categories.get(pid, []), key=str.casefold)
        template = template_meta.get(pid, '')
        has_image = thumbnail_meta.get(pid, '')
        try:
            has_image = bool(int(has_image) in attachment_ids)
        except (TypeError, ValueError):
            has_image = False
        permalink = base.rstrip('/') + '/' + post['post_name'].strip('/') + '/'
        feed_match = (
            pid in feed_ids
            or normalized_url(permalink, base) in feed_urls
            or normalized_title(post['post_title']) in feed_titles
        )
        suggested = proposed_class({**dict(post), 'template': template}, cats)
        counts[suggested] = counts.get(suggested, 0) + 1
        is_news = bool({c.casefold() for c in cats} & NEWS_CATEGORIES)
        if is_news:
            news_like_rows += 1
            if not feed_match:
                missing_feed.append('%s (ID %d)' % (post['post_name'], pid))
        if 'news' in {c.casefold() for c in cats}:
            exact_news_rows += 1
            exact_news_in_feed += int(feed_match)
        output_rows.append({
            'id': pid,
            'slug': post['post_name'],
            'title': post['post_title'],
            'date': (post['post_date'] or '')[:10],
            'categories': '; '.join(cats),
            'content_length': len(post['post_content'] or ''),
            'featured_image': 'yes' if has_image else 'no',
            'template_meta': template,
            'inbound_links': len(inbound_sources[pid]),
            'in_news_feed': 'yes' if feed_match else 'no',
            'proposed_class': suggested,
            'permalink': permalink,
        })

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open('w', newline='', encoding='utf-8-sig') as handle:
        writer = csv.DictWriter(handle, fieldnames=list(output_rows[0].keys()))
        writer.writeheader()
        writer.writerows(output_rows)

    print('Published posts:', len(posts))
    print('Proposed classes:')
    for name, count in sorted(counts.items()):
        print('  %-18s %d' % (name, count))
    print('Posts in the News category in feed: %d/%d' % (exact_news_in_feed, exact_news_rows))
    print('Posts in all news-related categories in feed: %d/%d' % (news_like_rows - len(missing_feed), news_like_rows))
    print('Posts with at least one inbound link: %d' % sum(bool(row['inbound_links']) for row in output_rows))
    print('CSV:', args.out.relative_to(REPO) if args.out.is_relative_to(REPO) else args.out)
    if missing_feed:
        print('News posts missing a feed match:')
        for slug in missing_feed:
            print('  ', slug)
    conn.close()


if __name__ == '__main__':
    main()
