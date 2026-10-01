"""
The Fornits topics scripts/fornits-crawl.py has saved, tied to facility
records and sent to the server for review at KOP Tools > Fornits
(inc/fornits.php). Runs hourly beside the crawl and only reads what is new.

    python scripts/fornits-process.py [--dir tmp/fornits] [--no-upload] [--all] [--keep-crawling]

The hourly Windows task "KOP Fornits" runs it with --keep-crawling.

For each newly saved topic page:
  - parse the posts (fornits-crawl.parse_topic);
  - find the facilities it is about: the board's own program (Thayer, CALO,
    Peninsula Village, ...), a program named in the title or the opening
    post, or one named in three or more posts. Names come from facilities_v2
    in tmp/prod.sqlite (current, past and other names) through the Drive Docs
    matcher (scripts/gdocs-extract.py Matcher), so generic names
    ("Juvenile Detention Center") never match;
  - topics about at least one facility go into a batch file
    tmp/fornits/upload/batch-<time>.jsonl.gz, one topic per line with its
    posts, which is copied to ~/kop-import/fornits/ on the server.

On the server, each topic becomes a "Fornits discussion" link proposal for
each of its facilities at once, and the hourly Groq read (inc/fornits.php)
proposes staff, incidents, survivor accounts and leads from the posts.

processed.json remembers which topics have been read, so a rerun only does
the new ones (--all reads everything again). The posts never go in the
repository (it is public); tmp/ is gitignored and ~/kop-import is outside
the web root.
"""

import argparse
import collections
import datetime
import gzip
import importlib.util
import json
import os
import sqlite3
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, os.path.join(ROOT, 'scripts', file))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


crawl = load('fornits_crawl', 'fornits-crawl.py')
gd = load('gdocs_extract', 'gdocs-extract.py')
ws = gd.ws

# Boards about one program: their topics are about that record even when no
# post names it (record ids in facilities_v2). Three Springs, Roloff and Kids
# Helping Kids span several records and Benchmark has none, so those boards
# rely on the title and the posts.
BOARD_PROGRAM = {
    39: 11449,  # Thayer Learning Center
    77: 11355,  # CALO Programs (Change Academy at Lake of the Ozarks)
    62: 11240,  # Village Behavioral Health Treatment Center (Peninsula Village)
    38: 12710,  # Mission Mountain School
    2: 11017, 34: 11017, 75: 11017,  # Elan School
    35: 10582,  # Whitmore Academy (Who Am I Discovery)
    8: 11229,   # The Seed
    41: 12539,  # Hidden Lake Academy (Ridge Creek School)
    36: 11191,  # Lighthouse Christian Academy (Lighthouse of Northwest Florida)
}

SSH = ['-i', os.path.expanduser('~/.ssh/kop_nixihost'), '-P', '1157', '-o', 'BatchMode=yes']
REMOTE = 'kidsover@dfw-s07.nixihost.com'
REMOTE_DIR = 'kop-import/fornits'


def log(msg):
    print(datetime.datetime.now().strftime('%H:%M:%S'), msg, flush=True)


def fac(f):
    return {'id': f['id'], 'name': f['name'], 'state': f.get('state', '')}


def board_facilities(m):
    return {b: {fid: m.by_id[fid]} for b, fid in BOARD_PROGRAM.items() if fid in m.by_id}


def topic_matches(m, board_facs, topic, posts):
    """[{id, name, state, how, posts}] strongest first."""
    found = {}

    def add(f, how, k=None):
        e = found.setdefault(f['id'], dict(fac(f), how=[], posts=0, also=[]))
        if how not in e['how']:
            e['how'].append(how)
        # The same name on records in other states: the reviewer picks.
        for o in (m.names.get(k) or m.past.get(k) or []) if k else []:
            if o['id'] != f['id'] and o['id'] not in {x['id'] for x in e['also']}:
                e['also'].append(fac(o))

    for f in board_facs.get(topic['board'], {}).values():
        add(f, 'board')
    title = topic.get('title') or (posts[0]['title'] if posts else '')
    f, how = m.title(title)
    if f:
        add(f, 'title')
    for f, k, past in m.in_text(title):
        add(f, 'title', k)
    per_post = collections.Counter()
    for p in posts:
        ids = set()
        for f, k, past in m.in_text(p['text']):
            if f['id'] not in ids:
                ids.add(f['id'])
                per_post[f['id']] += 1
                if p['n'] == 0:
                    add(f, 'opening post', k)
                elif f['id'] not in found:
                    found.setdefault(f['id'], dict(fac(f), how=[], posts=0, also=[]))
    for fid, e in found.items():
        e['posts'] = per_post.get(fid, 0)
        if not e['how'] and e['posts'] >= 3:
            e['how'].append(f'named in {e["posts"]} posts')
    strong = [e for e in found.values() if e['how']]
    weak = [dict(e, how=[]) for e in found.values() if not e['how']]
    rank = {'board': 0, 'title': 1, 'opening post': 2}
    strong.sort(key=lambda e: (min(rank.get(h, 3) for h in e['how']), -e['posts']))
    weak.sort(key=lambda e: -e['posts'])
    return strong, weak


# Run windowless (pythonw from the scheduled task): no console pops up for ssh, scp or tasklist either.
QUIET = {'creationflags': getattr(subprocess, 'CREATE_NO_WINDOW', 0)}


def child_out():
    return sys.stdout if getattr(sys.stdout, 'fileno', None) else subprocess.DEVNULL


def upload(path):
    subprocess.run(['ssh'] + [a if a != '-P' else '-p' for a in SSH] + [REMOTE, f'mkdir -p {REMOTE_DIR}'],
                   check=True, stdin=subprocess.DEVNULL, stdout=child_out(), stderr=child_out(), **QUIET)
    subprocess.run(['scp'] + SSH + [path, f'{REMOTE}:{REMOTE_DIR}/'],
                   check=True, stdin=subprocess.DEVNULL, stdout=child_out(), stderr=child_out(), **QUIET)


def keep_crawling(d):
    """Start the full crawl again when it is neither finished nor running (a reboot, a crash)."""
    if os.path.exists(os.path.join(d, 'crawl.done')):
        return
    pid_path = os.path.join(d, 'crawl.pid')
    if os.path.exists(pid_path):
        pid = open(pid_path).read().strip()
        tl = subprocess.run(['tasklist', '/FI', f'PID eq {pid}', '/NH'], capture_output=True, text=True, **QUIET).stdout
        if pid and pid in tl and 'python' in tl.lower():
            return
    log('crawl not running: starting it again')
    flags = getattr(subprocess, 'DETACHED_PROCESS', 0) | getattr(subprocess, 'CREATE_NEW_PROCESS_GROUP', 0)
    subprocess.Popen([sys.executable, '-u', os.path.join(ROOT, 'scripts', 'fornits-crawl.py'), '--out', d],
                     stdout=open(os.path.join(d, 'crawl.log'), 'a'), stderr=open(os.path.join(d, 'crawl.err'), 'a'),
                     cwd=ROOT, creationflags=flags | QUIET['creationflags'], close_fds=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default=os.path.join(ROOT, 'tmp', 'fornits'))
    ap.add_argument('--no-upload', action='store_true', help='dry run: write the batch, upload nothing, mark nothing read')
    ap.add_argument('--all', action='store_true', help='read every saved topic again')
    ap.add_argument('--keep-crawling', action='store_true', help='restart the full crawl if it stopped before finishing')
    ap.add_argument('--log', help='append everything printed to this file (the windowless scheduled run)')
    a = ap.parse_args()
    if a.log:
        sys.stdout = sys.stderr = open(a.log, 'a', encoding='utf-8', buffering=1)
    if a.keep_crawling:
        keep_crawling(a.dir)

    raw = os.path.join(a.dir, 'raw')
    state_path = os.path.join(a.dir, 'processed.json')
    state = {} if a.all or not os.path.exists(state_path) else json.load(open(state_path))
    topics = {}
    tp = os.path.join(a.dir, 'topics.jsonl')
    if os.path.exists(tp):
        for line in open(tp, encoding='utf-8'):
            t = json.loads(line)
            topics[t['id']] = t

    new = []
    for name in os.listdir(raw) if os.path.isdir(raw) else []:
        if not name.endswith('.html.gz'):
            continue
        tid = int(name.split('.')[0])
        if str(tid) not in state and tid in topics:
            new.append(tid)
    new.sort()
    if not new:
        log('nothing new')
        return 0
    log(f'{len(new)} new topics')

    m = gd.Matcher(sqlite3.connect(ws.DB))
    board_facs = board_facilities(m)
    os.makedirs(os.path.join(a.dir, 'upload'), exist_ok=True)
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    out_path = os.path.join(a.dir, 'upload', f'batch-{stamp}.jsonl.gz')
    kept = 0
    per_facility = collections.Counter()
    with gzip.open(out_path + '.part', 'wt', encoding='utf-8') as out:
        for tid in new:
            t = topics[tid]
            page = gzip.open(os.path.join(raw, f'{tid}.html.gz'), 'rt', encoding='utf-8').read()
            posts = crawl.parse_topic(tid, t['board'], page)
            strong, weak = topic_matches(m, board_facs, t, posts) if posts else ([], [])
            state[str(tid)] = len(strong)
            if not strong:
                continue
            kept += 1
            for e in strong:
                per_facility[e['name']] += 1
            out.write(json.dumps({
                'topic': tid,
                'board': t['board'],
                'board_name': crawl.BOARDS.get(t['board'], ''),
                'title': posts[0]['title'] or t['title'],
                'url': f'https://www.fornits.com/phpbb/index.php?topic={tid}.0',
                'started': posts[0]['date'],
                'last': posts[-1]['date'],
                'facilities': strong,
                'mentions': weak[:10],
                'posts': [{'n': p['n'], 'author': p['author'], 'date': p['date'], 'text': p['text']} for p in posts],
            }, ensure_ascii=False) + '\n')
    os.replace(out_path + '.part', out_path)
    log(f'{kept} of {len(new)} topics are about a facility -> {os.path.relpath(out_path, ROOT)}')
    for name, n in per_facility.most_common(8):
        log(f'  {n:4d}  {name}')

    if kept and not a.no_upload:
        try:
            upload(out_path)
            log('uploaded to ~/' + REMOTE_DIR)
        except Exception as e:
            # Not marked read: the next run makes a new batch from them.
            log(f'upload failed ({e}); these topics stay unread for the next run')
            os.remove(out_path)
            return 1
    elif not kept:
        os.remove(out_path)
    if a.no_upload:
        return 0  # a dry run: the topics stay unread, so the real run still uploads them
    tmp = state_path + '.part'
    json.dump(state, open(tmp, 'w'))
    os.replace(tmp, state_path)
    return 0


if __name__ == '__main__':
    sys.exit(main())
