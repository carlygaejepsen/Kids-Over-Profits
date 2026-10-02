"""
Batches of HEAL pages for the readers (tmp/heal/INSTRUCTIONS.md): pages not
read yet, grouped by size so each reader gets about the same amount of text.
Pages about animals, prisons and the like are left out by their words.

    python scripts/heal-batches.py [--chars=220000] [--prefix=b]
writes tmp/heal/batches/<prefix>NN.txt (one text file name per line).
"""

import argparse
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HEAL = os.path.join(ROOT, 'tmp', 'heal')
TTI = re.compile(r'\b(academy|ranch|boarding|residential|wilderness|treatment|boot ?camp|teen challenge|program|'
                 r'school|youth|troubled teen|wwasp|natsap|staff list|students?|enroll)', re.I)
OFF_TOPIC = re.compile(r'\b(animal|vegan|vivisect|dissection|inmate|prison|death row|hemp|environment|fur\b|circus)', re.I)


def doc_batches(size, prefix):
    """
    PDFs for the document readers (DOCS-INSTRUCTIONS.md): every extracted
    PDF not read or queued yet, `size` to a batch, written as
    batches/<prefix>NN.txt; each reader writes docs/<prefix>NN.json.
    """
    pdfs = json.load(open(os.path.join(HEAL, 'pdfs.json'), encoding='utf-8'))
    bdir = os.path.join(HEAL, 'batches')
    os.makedirs(bdir, exist_ok=True)
    queued = set()
    for fn in os.listdir(bdir):
        if fn.startswith(prefix):
            queued.update(x.strip() for x in open(os.path.join(bdir, fn), encoding='utf-8') if x.strip())
    todo = [p['name'] for p in pdfs if p['name'] not in queued and p.get('chars', 0) > 40]
    start = len([f for f in os.listdir(bdir) if f.startswith(prefix)])
    n = 0
    for k in range(0, len(todo), size):
        with open(os.path.join(bdir, '%s%03d.txt' % (prefix, start + n)), 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(todo[k:k + size]) + '\n')
        n += 1
    print('%d PDFs in %d new batches (%d already queued)' % (len(todo), n, len(queued)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--chars', type=int, default=220000)
    ap.add_argument('--prefix', default='b')
    ap.add_argument('--docs', type=int, default=0, help='batch PDFs instead, this many to a batch')
    a = ap.parse_args()
    if a.docs:
        doc_batches(a.docs, a.prefix if a.prefix != 'b' else 'p')
        return
    issues = json.load(open(os.path.join(HEAL, 'issues.json'), encoding='utf-8'))
    done = set(f[:-5] for f in os.listdir(os.path.join(HEAL, 'facts'))) if os.path.isdir(os.path.join(HEAL, 'facts')) else set()
    bdir = os.path.join(HEAL, 'batches')
    os.makedirs(bdir, exist_ok=True)
    queued = set()
    for fn in os.listdir(bdir):
        queued.update(x.strip() for x in open(os.path.join(bdir, fn), encoding='utf-8') if x.strip())
    todo, skipped = [], []
    for i in issues:
        if i['date'] in done or i['file'] in queued or i['chars'] < 200:
            continue
        text = open(os.path.join(HEAL, 'text', i['file']), encoding='utf-8').read()
        tti, off = len(TTI.findall(text)), len(OFF_TOPIC.findall(text))
        if i['kind'] != 'staff' and (tti < 5 or off > tti):
            skipped.append(i['file'])
            continue
        todo.append((i['file'], len(text)))
    batches, cur, size = [], [], 0
    for f, n in sorted(todo):
        if cur and size + n > a.chars:
            batches.append(cur)
            cur, size = [], 0
        cur.append(f)
        size += n
    if cur:
        batches.append(cur)
    start = len([f for f in os.listdir(bdir) if f.startswith(a.prefix)])
    for k, b in enumerate(batches, start):
        with open(os.path.join(bdir, '%s%02d.txt' % (a.prefix, k)), 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(b) + '\n')
    print('%d pages in %d batches; %d left out as off topic: %s' % (len(todo), len(batches), len(skipped), ', '.join(skipped[:40])))


if __name__ == '__main__':
    main()
