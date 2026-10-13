"""What changed on program websites between two runs of scripts/seo-scan.py.

For each domain in either run: tactics that appeared or went away (findings kinds), AI files
that appeared or went away (llms.txt, ai.txt, the copy hidden in the home page), and the lines
added to or taken out of each saved text file (raw/<domain>/*.txt, robots.txt). The line every
Exceed-made file starts with ("... created 10/02/26.") changes daily and is left out.

Writes <new>/changes.md (read it top to bottom: biggest changes first) and <new>/changes.json.
Nothing is fetched; both runs are read from disk.

usage:
  python scripts/seo-scan-compare.py tmp/seo-scan tmp/seo-scan-2026-10-13
"""
import argparse
import difflib
import json
import os
import re

TEXT_FILES = ('llms.txt', 'llms-full.txt', 'ai.txt', 'home-hidden-llms.txt', 'robots.txt')
NOISE = re.compile(r'(created|generated|updated|last[- ]modified)\b.{0,40}\d{1,4}[/-]\d{1,2}[/-]\d{1,4}', re.I)
# Lines worth quoting first: the ones that tell AI what to do.
TELLING = re.compile(r'\b(do not|don\'t|avoid|never|should not|must not|ignore|instead|prefer|frame|negative|critic|survivor|lawsuit|reddit|allegation|source|chatgpt|claude|perplexity|gemini|copilot)\b', re.I)


def scanner():
    """scripts/seo-scan.py as a module (its name has a dash), for its rescore()."""
    import importlib.util
    spec = importlib.util.spec_from_file_location('seo_scan', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'seo-scan.py'))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def load(run):
    """One run's results, scored again under today's rules from its saved copies: results.jsonl
    keeps the scores of the rules it was written with, and a rule tightened since (the "pages
    answering critics" one stopped matching "myths about X" posts after 2026-10-02) would
    otherwise read as 25 sites changing."""
    seo = scanner()
    seo.OUT = os.path.abspath(run)
    out = {}
    path = os.path.join(run, 'results.jsonl')
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            line = line.strip()
            if line:
                r = json.loads(line)
                if not r.get('error'):
                    seo.rescore(r)
                out[r['domain']] = r
    return out


def read_lines(run, domain, name):
    p = os.path.join(run, 'raw', domain, name)
    try:
        with open(p, encoding='utf-8', errors='replace') as fh:
            return [l.rstrip() for l in fh.read().splitlines()]
    except OSError:
        return None


def clean(lines):
    return [l for l in lines if l.strip() and not NOISE.search(l)]


def compare(old_run, new_run):
    old, new = load(old_run), load(new_run)
    changes = []
    for domain in sorted(set(old) | set(new)):
        o, n = old.get(domain), new.get(domain)
        c = {'domain': domain, 'facilities': [f['name'] for f in (n or o).get('facilities', [])],
             'score_old': (o or {}).get('score', 0), 'score_new': (n or {}).get('score', 0)}
        if o is None:
            c['new_domain'] = True
        if n is None:
            c['dropped_domain'] = True
        if o and n and (o.get('error') or n.get('error')):
            # A site that was down in one run says nothing about what changed on it.
            if bool(o.get('error')) != bool(n.get('error')):
                c['reachable'] = 'now down' if n.get('error') else 'back up'
        fo, fn = set((o or {}).get('findings', {})), set((n or {}).get('findings', {}))
        labels = {**{k: v['label'] for k, v in (o or {}).get('findings', {}).items()},
                  **{k: v['label'] for k, v in (n or {}).get('findings', {}).items()}}
        if o and n and not o.get('error') and not n.get('error'):
            c['tactics_added'] = sorted(labels[k] for k in fn - fo)
            c['tactics_gone'] = sorted(labels[k] for k in fo - fn)
            ao, an = set(o.get('ai_files', {})), set(n.get('ai_files', {}))
            c['files_added'] = sorted(an - ao)
            c['files_gone'] = sorted(ao - an)
            texts = {}
            for name in TEXT_FILES:
                lo, ln = read_lines(old_run, domain, name), read_lines(new_run, domain, name)
                if lo is None and ln is None:
                    continue
                lo, ln = clean(lo or []), clean(ln or [])
                added, removed = [], []
                for d in difflib.unified_diff(lo, ln, lineterm='', n=0):
                    if d.startswith('+') and not d.startswith('+++'):
                        added.append(d[1:])
                    elif d.startswith('-') and not d.startswith('---'):
                        removed.append(d[1:])
                if added or removed:
                    texts[name] = {
                        'added': len(added), 'removed': len(removed),
                        'added_telling': [l for l in added if TELLING.search(l)][:12],
                        'removed_telling': [l for l in removed if TELLING.search(l)][:12],
                        'added_sample': added[:6], 'removed_sample': removed[:6],
                    }
            c['texts'] = texts
        weight = (len(c.get('tactics_added', [])) * 10 + len(c.get('tactics_gone', [])) * 8
                  + len(c.get('files_added', [])) * 6 + len(c.get('files_gone', [])) * 6
                  + sum(len(t['added_telling']) + len(t['removed_telling']) for t in c.get('texts', {}).values()) * 2
                  + sum(t['added'] + t['removed'] for t in c.get('texts', {}).values() if True) // 20
                  + (4 if c.get('new_domain') and c['score_new'] else 0))
        if weight or c.get('reachable'):
            c['weight'] = weight
            changes.append(c)
    changes.sort(key=lambda c: (-c.get('weight', 0), c['domain']))
    return old, new, changes


def write(new_run, old_run, old, new, changes):
    with open(os.path.join(new_run, 'changes.json'), 'w', encoding='utf-8') as fh:
        json.dump(changes, fh, indent=1, ensure_ascii=False)
    stamp = lambda res: max((r.get('scanned_at', '') for r in res.values()), default='')[:10]
    L = [f'# Program websites: what changed, {stamp(old)} to {stamp(new)}', '',
         f'{len(old)} domains then, {len(new)} now. {len(changes)} with a change. Compared from '
         f'`{os.path.relpath(old_run)}` and `{os.path.relpath(new_run)}`; nothing here was fetched again.', '']
    counts = {}
    for c in changes:
        for t in c.get('tactics_added', []):
            counts.setdefault(t, [0, 0])[0] += 1
        for t in c.get('tactics_gone', []):
            counts.setdefault(t, [0, 0])[1] += 1
    if counts:
        L += ['| Tactic | Sites that started | Sites that stopped |', '|---|---|---|']
        L += [f'| {t} | {a} | {g} |' for t, (a, g) in sorted(counts.items(), key=lambda x: -sum(x[1]))]
        L.append('')
    for c in changes:
        who = f" ({', '.join(c['facilities'][:3])})" if c['facilities'] else ''
        L.append(f"## {c['domain']}{who}")
        L.append(f"Score {c['score_old']} then, {c['score_new']} now."
                 + (' New to the scan.' if c.get('new_domain') else '')
                 + (' No longer on the list.' if c.get('dropped_domain') else '')
                 + (f" Site {c['reachable']}." if c.get('reachable') else ''))
        for key, words in (('tactics_added', 'Started'), ('tactics_gone', 'Stopped'),
                           ('files_added', 'New AI file'), ('files_gone', 'AI file gone')):
            if c.get(key):
                L.append(f"- {words}: {', '.join(c[key])}")
        for name, t in c.get('texts', {}).items():
            L.append(f"- `{name}`: {t['added']} line(s) added, {t['removed']} taken out")
            for l in t['added_telling'] or t['added_sample'][:3]:
                L.append(f'  - added: "{l[:300]}"')
            for l in t['removed_telling'] or t['removed_sample'][:3]:
                L.append(f'  - taken out: "{l[:300]}"')
        L.append('')
    with open(os.path.join(new_run, 'changes.md'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(L) + '\n')


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('old')
    ap.add_argument('new')
    a = ap.parse_args()
    old, new, changes = compare(a.old, a.new)
    write(a.new, a.old, old, new, changes)
    print(f"{len(changes)} domains changed; {os.path.join(a.new, 'changes.md')}")


if __name__ == '__main__':
    main()
