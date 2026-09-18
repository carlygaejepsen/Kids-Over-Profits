"""Normalize the History timeline pages (Gutenberg block HTML).

Reads the raw post_content dumps in scripts/history/raw/ (dump them from
tmp/prod.sqlite as <id>-<slug>.html), writes normalized block HTML to
seeds/history/<slug>.html and a full-text outline (.txt) next to each dump
for the editorial pass. See README.md in this directory.

Rules:
  - strip <strong><br></strong>, trailing <br>, &nbsp;, empty paragraphs
  - merge split <strong> runs
  - timeline items: bold the date only, " – " separator, unbold the rest;
    a line break after the title turns the remainder into a sub-bullet;
    an undated top-level entry is nested under the entry before it
  - merge adjacent list blocks; insert era <h2> headings when the page has
    no headings of its own; existing bold-only marker paragraphs become <h2>
  - column layouts are unwrapped into a single reading column
  - the trailing "Works Cited" block becomes <h2>Sources</h2> + one list
  - staging / absolute site links become relative; known broken slugs fixed
  - per-page fixups (fixups.py) run on the raw HTML first and on the result last
"""
import os, re, sys, html, importlib.util

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'raw')   # post_content dumps, <id>-<slug>.html (gitignored)
OUT = os.path.normpath(os.path.join(HERE, '..', '..', 'seeds', 'history'))

LINK_FIXES = {
    'https://kidsoverprofits.org/staging/': '/',
    'https://kidsoverprofits.org/': '/',
    '"/medieval-child-oblation"': '"/medieval-child-oblation-and-monastic-schools/"',
}

MONTHS = r'(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan\.?|Feb\.?|Mar\.?|Apr\.?|Jun\.?|Jul\.?|Aug\.?|Sept?\.?|Oct\.?|Nov\.?|Dec\.?)'
ERA = r'(?:\s*(?:BCE|BC|CE|AD))?'
CENTURY = r'\d{1,2}(?:st|nd|rd|th)(?:\s*[–—-]\s*\d{1,2}(?:st|nd|rd|th))?\s*(?:c\.|[Cc]entury)'
YEAR = r'(?:' + CENTURY + r'|\d{1,4}s?' + ERA + r')'
DATE_RE = re.compile(
    r'^\s*(?P<date>'
    r'(?:(?:By|Since|Around|Late|Early|Mid-?|Winter|Spring|Summer|Fall|Autumn|c\.|ca\.|circa)\s*)?'
    r'(?:' + MONTHS + r'\s+(?:\d{1,2}(?:\s*[–-]\s*\d{1,2})?,?\s+)?)?'
    + YEAR +
    r'(?:\s*(?:–|—|-|to|/)\s*(?:' + MONTHS + r'\s+)?(?:\d{1,4}s?|[Pp]resent)' + ERA + r')?'
    r'(?:\s*\((?P<paren>[^()]{1,40})\))?'
    r'|Present)'
    r'\s*(?P<sep>--|[–—:]|-(?=\s)|(?=\s+[A-Z]))\s*',
    re.S)
MONTH_RE = re.compile(r'^' + MONTHS + r'(?:\s+\d{1,2})?$')
LEAD_MONTH_RE = re.compile(r'^(' + MONTHS + r')(?:\s+(\d{1,2}))?\s*[,:]\s+')

TAG_RE = re.compile(r'<[^>]+>')


def strip_tags(s):
    return html.unescape(TAG_RE.sub('', s))


# --------------------------------------------------------------------------
# Block parsing
# --------------------------------------------------------------------------
TOKEN_RE = re.compile(r'<!-- (/?)wp:([a-z0-9/-]+)(?:\s(\{.*?\}))?\s*(/)?-->', re.S)


def split_blocks(src):
    """Split Gutenberg HTML into top-level blocks: list of (name, html)."""
    blocks = []
    depth = 0
    start = None
    name = None
    for m in TOKEN_RE.finditer(src):
        closing, bname, attrs, selfclose = m.group(1), m.group(2), m.group(3), m.group(4)
        if selfclose:
            if depth == 0:
                blocks.append((bname, m.group(0)))
            continue
        if not closing:
            if depth == 0:
                start = m.start()
                name = bname
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                blocks.append((name, src[start:m.end()]))
    return blocks


def inner_of(block_html):
    """HTML between the outer wp comment tokens."""
    m = TOKEN_RE.match(block_html)
    end = block_html.rfind('<!-- /wp:')
    return block_html[m.end():end]


def unwrap(blocks):
    """Flatten columns/column/group wrappers into their child blocks."""
    out = []
    for name, h in blocks:
        if name in ('columns', 'column', 'group'):
            out.extend(unwrap(split_blocks(inner_of(h))))
        else:
            out.append((name, h))
    return out


def split_items(list_html):
    """Top-level <li> bodies of a wp:list block (nested lists stay inside)."""
    items = []
    depth = 0
    start = None
    for m in re.finditer(r'<!-- (/?)wp:list-item -->', list_html):
        if not m.group(1):
            if depth == 0:
                start = m.end()
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                body = list_html[start:m.start()]
                body = re.sub(r'^\s*<li>', '', body)
                body = re.sub(r'</li>\s*$', '', body)
                items.append(body)
    return items


def ordered(list_html):
    return bool(re.match(r'<!-- wp:list \{[^}]*"ordered":true', list_html))


# --------------------------------------------------------------------------
# Inline cleanup
# --------------------------------------------------------------------------

def clean_inline(s):
    s = s.replace('\u00a0', ' ').replace('&nbsp;', ' ')
    s = re.sub(r'<br\s*/?>', '<br>', s)
    s = re.sub(r'<(strong|em)>\s*(?:<br>\s*)+</\1>', '<br>', s)
    s = re.sub(r'<(strong|em)>\s*</\1>', '', s)
    s = re.sub(r'</strong>(\s*)<strong>', r'\1', s)
    s = re.sub(r'</em>(\s*)<em>', r'\1', s)
    s = re.sub(r'(?:\s*<br>)+\s*(</(?:em|strong|a)>)\s*$', r'\1', s)
    s = re.sub(r'(?:\s*<br>)+\s*$', '', s)
    s = re.sub(r'^(?:\s*<br>)+', '', s)
    s = re.sub(r'(?:\s*<br>){2,}', '<br>', s)
    s = re.sub(r'[ \t]+$', '', s)
    s = re.sub(r'\s{2,}', ' ', s)
    s = re.sub(r'\s+([,.;:])', r'\1', s)
    s = re.sub(r'\.\.(?!\.)', '.', s)
    return s.strip()


def normalize_date(date, paren):
    d = date.strip()
    d = re.sub(r'\s+', ' ', d)
    d = re.sub(r'(?<=[\ds])\s*(?:—|-|--)\s*(?=\d|[Pp]resent)', '–', d)   # ranges use an en dash
    d = re.sub(r'\s*–\s*', '–', d)
    d = re.sub(r'\s+(BCE|BC|CE|AD)\b', r' \1', d)
    d = re.sub(r'\bc\.\s*', 'c. ', d)
    d = re.sub(r'\bSept\.?\s', 'September ', d)
    d = re.sub(r'\b(Jan|Feb|Mar|Apr|Jun|Jul|Aug|Oct|Nov|Dec)\.?\s', lambda m: {
        'Jan': 'January', 'Feb': 'February', 'Mar': 'March', 'Apr': 'April', 'Jun': 'June', 'Jul': 'July',
        'Aug': 'August', 'Oct': 'October', 'Nov': 'November', 'Dec': 'December'}[m.group(1)] + ' ', d)
    d = re.sub(r'^(' + MONTHS + r') (\d{1,2}(?:–\d{1,2})?),? (\d{4})', r'\1 \2, \3', d)
    d = re.sub(r'\s*\([^)]*\)\s*$', '', d)
    if paren:
        p = paren.strip()
        if MONTH_RE.match(p):
            d = p + ' ' + d
        else:
            d = d + ' (' + p + ')'
    d = re.sub(r'\bMid–', 'Mid-', d)
    return d


def own_and_nested(body):
    """Split an <li> body into its own inline HTML and a nested list block."""
    i = body.find('<!-- wp:list')
    if i == -1:
        return body, ''
    return body[:i], body[i:]


def cut_prefix(tagged, n_plain_chars):
    """Return tagged HTML with the first n plain-text characters removed."""
    i = 0
    seen = 0
    out = []
    while i < len(tagged) and seen < n_plain_chars:
        if tagged[i] == '<':
            j = tagged.index('>', i) + 1
            out.append(tagged[i:j])
            i = j
        elif tagged[i] == '&':
            j = tagged.find(';', i)
            if j != -1 and j - i < 8:
                ent = tagged[i:j + 1]
                seen += len(html.unescape(ent))
                i = j + 1
            else:
                seen += 1
                i += 1
        else:
            seen += 1
            i += 1
    rest = tagged[i:]
    opened = [t for t in out if not t.startswith('</') and re.match(r'<(em|a)\b', t)]
    closed = [t for t in out if t.startswith('</') and re.match(r'</(em|a)>', t)]
    prefix = ''.join(opened[len(closed):])
    return prefix + rest


def date_lead(own):
    """Return (date, rest_html) when the item starts with a date, else None."""
    own = re.sub(r'^(\s*(?:<strong>)?)In (\d{4}),\s*', r'\1\2 – ', own)
    text = strip_tags(own)
    m = DATE_RE.match(text)
    plain_own = re.sub(r'</?strong>', '', own)
    if m:
        date = normalize_date(m.group('date'), m.group('paren'))
        rest = cut_prefix(plain_own, m.end())
    else:
        t = text.strip()
        # "Auburn System (New York, 1818)"  /  "... Act of 1646"  /  "Nazi Germany 1933 – ..."
        m1 = re.match(r'^(.*?)\s*\((?:([^()]*?),\s*)?(\d{4})\)\s*$', t)
        m2 = re.match(r'^(.*\bof)\s+(\d{4})\s*$', t)
        m3 = re.match(r'^([A-Z][\w ]{2,25}?)\s+(\d{4})\s*(?:--|[–—:]|-(?=\s))\s*(.+)$', t, re.S)
        if m1 and len(t) <= 90:
            date = m1.group(3)
            rest = m1.group(1) + (' (' + m1.group(2) + ')' if m1.group(2) else '')
        elif m2 and len(t) <= 90:
            date = m2.group(2)
            rest = re.sub(r'\s+of$', '', m2.group(1))
        elif m3:
            date = m3.group(2)
            rest = m3.group(1) + ': ' + cut_prefix(plain_own, m3.start(3))
        else:
            return None
    rest = re.sub(r'^[\s–—:-]+', '', rest)
    # "2007 – October 10, Congress ..." -> "October 10, 2007 – Congress ..."
    mm = LEAD_MONTH_RE.match(rest)
    if mm and re.fullmatch(r'\d{4}', date):
        date = mm.group(1) + (' ' + mm.group(2) + ',' if mm.group(2) else '') + ' ' + date
        rest = rest[mm.end():]
    rest = clean_inline(rest)
    if rest and rest[0].islower():
        rest = rest[0].upper() + rest[1:]
    return date, rest


def format_item(body, timeline=True, top=True):
    """Return a list of formatted item bodies (an item may split into several)."""
    own, nested = own_and_nested(body)
    own = clean_inline(own)
    extra_children = []
    siblings = []
    if '<br>' in own:
        parts = [p.strip() for p in own.split('<br>')]
        own = parts[0]
        for p in parts[1:]:
            if len(strip_tags(p).strip()) < 3:
                continue
            if timeline and date_lead(p):
                siblings.append(p)
            else:
                extra_children.append(re.sub(r'</?strong>', '', p) if not date_lead(p) else p)
    if timeline:
        lead = date_lead(own)
        if lead:
            date, rest = lead
            own = '<strong>%s</strong> – %s' % (date, rest) if rest else '<strong>%s</strong>' % date
        else:
            text = strip_tags(own)
            if len(text) > 90 or not top:
                own = re.sub(r'</?strong>', '', own)
    children = []
    for p in extra_children:
        children.extend(format_item(p, timeline, top=False))
    if nested:
        for b in split_items(nested):
            children.extend(format_item(b, timeline, top=False))
    children = [c for c in children if strip_tags(c).strip()]
    result = own + (build_list(children) if children else '')
    items = [result]
    for s in siblings:
        items.extend(format_item(s, timeline, top=top))
    return items


def build_list(items, is_ordered=False):
    tag = 'ol' if is_ordered else 'ul'
    attrs = ' {"ordered":true}' if is_ordered else ''
    parts = []
    for it in items:
        parts.append('<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->' % it)
    return '<!-- wp:list%s -->\n<%s class="wp-block-list">%s</%s>\n<!-- /wp:list -->' % (
        attrs, tag, '\n\n'.join(parts), tag)


def heading(text, level=2):
    return '<!-- wp:heading%s -->\n<h%d class="wp-block-heading">%s</h%d>\n<!-- /wp:heading -->' % (
        '' if level == 2 else ' {"level":%d}' % level, level, text, level)


def paragraph(inner, attrs='', pattrs=''):
    return '<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->' % (attrs, pattrs, inner)


def nest_under(prev, item):
    """Append item as the last sub-bullet of prev."""
    own, nested = own_and_nested(prev)
    kids = split_items(nested) if nested else []
    kids.append(item)
    return own + build_list(kids)


# --------------------------------------------------------------------------
# Era bins for pages without headings
# --------------------------------------------------------------------------
BINS = [
    (-10000, 1499, 'Before 1500'),
    (1500, 1799, '1500 to 1799'),
    (1800, 1899, '1800s'),
    (1900, 1949, '1900 to 1949'),
    (1950, 1979, '1950 to 1979'),
    (1980, 1999, '1980s and 1990s'),
    (2000, 9999, '2000 to today'),
]
BINS_INDEX = {label: i for i, (_, _, label) in enumerate(BINS)}


def item_year(item_html):
    m = re.match(r'<strong>([^<]+)</strong>', item_html)
    if not m:
        return None
    d = m.group(1)
    if 'Present' in d:
        return 3000
    c = re.search(r'(\d{1,2})(?:st|nd|rd|th)', d)
    if c and re.search(r'c\.|[Cc]entury', d):
        return (int(c.group(1)) - 1) * 100
    y = re.search(r'(\d{3,4})', d)
    if not y:
        y = re.search(r'(\d{1,2})', d)
        if not y:
            return None
    year = int(y.group(1))
    if re.search(r'\bBCE?\b', d):
        return -year
    return year


def bin_for(year):
    for lo, hi, label in BINS:
        if lo <= year <= hi:
            return label
    return None


# --------------------------------------------------------------------------
# Page transform
# --------------------------------------------------------------------------
SOURCE_WORDS = re.compile(r'^(?:works cited|sources?|references|bibliography|sources for this section included)\s*:?\s*$', re.I)


def para_inner(h):
    m = re.search(r'<p[^>]*>(.*?)</p>', h, re.S)
    return m.group(1) if m else ''


def transform(src, slug, opts):
    for a, b in LINK_FIXES.items():
        src = src.replace(a, b)
    src = src.replace('\r\n', '\n')
    blocks = unwrap(split_blocks(src))

    has_headings = any(n == 'heading' for n, _ in blocks)
    markers = []
    for n, h in blocks:
        if n == 'paragraph':
            m = re.match(r'<!-- wp:paragraph[^>]*-->\s*<p[^>]*>\s*(?:<em>)?<strong>(.*?)</strong>(?:</em>)?\s*(?:<br\s*/?>)?\s*</p>', h, re.S)
            if m and len(strip_tags(m.group(1))) <= 90 and not re.search(r'[.!?]\s*$', strip_tags(m.group(1))):
                markers.append(h)
    use_markers = len(markers) >= 3 and opts.get('markers', True)
    timeline = opts.get('timeline', True)
    auto_bins = opts.get('bins', True) and not has_headings and not use_markers and timeline

    # Sources: from the last separator whose tail is only paragraphs/lists, or
    # from a "Works Cited" paragraph.
    sources_at = None
    for i, (n, h) in enumerate(blocks):
        if n == 'paragraph' and SOURCE_WORDS.match(strip_tags(para_inner(h)).strip()):
            sources_at = i
    if sources_at is None:
        sep_idx = [i for i, (n, _) in enumerate(blocks) if n == 'separator']
        if sep_idx:
            i = sep_idx[-1]
            tail = blocks[i + 1:]
            if tail and all(n in ('paragraph', 'list') for n, _ in tail) and any(n == 'list' for n, _ in tail):
                sources_at = i
    if sources_at is not None and blocks[sources_at - 1][0] == 'separator':
        pass

    out = []
    pending = []
    pending_ordered = False
    current_bin = None
    source_items = []

    def flush():
        nonlocal pending
        if pending:
            out.append(build_list(pending, pending_ordered))
            pending = []

    def flush_sources():
        nonlocal source_items
        if source_items:
            out.append(build_list(source_items))
            source_items = []

    for idx, (name, h) in enumerate(blocks):
        if sources_at is not None and idx >= sources_at:
            if idx == sources_at:
                flush()
                if out and out[-1].startswith('<!-- wp:separator'):
                    out.pop()
                out.append(heading('Sources'))
                if name == 'paragraph':
                    continue
            if name == 'paragraph':
                inner = clean_inline(para_inner(h))
                text = strip_tags(inner).strip()
                if text == '' or SOURCE_WORDS.match(text):
                    continue
                if len(text) <= 40 and not re.search(r'[.)]$', text):
                    flush_sources()
                    out.append(heading(re.sub(r'</?(strong|em)>', '', inner).rstrip(':'), 3))
                    continue
                source_items.append(re.sub(r'</?strong>', '', inner))
                continue
            if name == 'list':
                for b in split_items(h):
                    b = clean_inline(re.sub(r'</?strong>', '', b))
                    if strip_tags(b).strip():
                        source_items.append(b)
                continue
            if name == 'separator':
                continue
            flush_sources()
            out.append(h)
            continue

        if name == 'list':
            is_ord = ordered(h)
            if is_ord != pending_ordered:
                flush()
                pending_ordered = is_ord
            for b in split_items(h):
                for fi in format_item(b, timeline):
                    if not strip_tags(fi).strip():
                        continue
                    y = item_year(fi) if timeline else None
                    if timeline and y is None and pending and not re.match(r'<strong>', fi):
                        pending[-1] = nest_under(pending[-1], fi)
                        continue
                    if auto_bins and y is not None:
                        label = bin_for(y)
                        if label and label != current_bin and (current_bin is None or BINS_INDEX[label] > BINS_INDEX[current_bin]):
                            flush()
                            out.append(heading(label))
                            current_bin = label
                    pending.append(fi)
            continue

        if name == 'paragraph':
            inner = clean_inline(para_inner(h))
            if strip_tags(inner).strip() == '':
                continue
            if use_markers and h in markers:
                flush()
                text = re.sub(r'</?(strong|em)>', '', inner)
                out.append(heading(text.rstrip(':').strip()))
                continue
            flush()
            attrs = re.match(r'<!-- wp:paragraph( \{.*?\})? -->', h)
            pattrs = re.search(r'<p([^>]*)>', h).group(1)
            out.append(paragraph(inner, attrs.group(1) or '', pattrs))
            continue
        if name == 'heading':
            flush()
            m = re.search(r'<(h\d)([^>]*)>(.*?)</h\d>', h, re.S)
            text = re.sub(r'</?(strong|em)>', '', clean_inline(m.group(3)))
            out.append(heading(text.strip(), int(m.group(1)[1])))
            continue
        if name == 'separator':
            flush()
            out.append(h)
            continue
        flush()
        out.append(h)
    flush()
    flush_sources()
    # separators only survive when they sit between content blocks
    out = [b for i, b in enumerate(out) if not (b.startswith('<!-- wp:separator') and (i == 0 or i == len(out) - 1))]
    return '\n\n'.join(out) + '\n'


PAGES = {
    '2102-advocacy-history': ('advocacy-history', {}),
    '2128-orphanages': ('orphanages', {'bins': False}),
    '2133-idd-timeline': ('idd-timeline', {}),
    '2137-juvenile-justice-timeline': ('juvenile-justice-timeline', {}),
    '2142-fundamentalist': ('fundamentalist', {}),
    '2147-wilderness-therapy-timeline': ('wilderness-therapy-timeline', {}),
    '2156-experimental-group-psychology': ('experimental-group-psychology', {}),
    '2160-war-on-drugs': ('war-on-drugs', {}),
    '2339-corporatization': ('corporatization', {}),
}


def outline(html_out):
    lines = []

    def walk(items, depth):
        for b in items:
            own, nested = own_and_nested(b)
            lines.append('    ' * depth + '- ' + strip_tags(own).strip())
            if nested:
                walk(split_items(nested), depth + 1)

    for name, h in split_blocks(html_out):
        if name == 'heading':
            lines.append('\n## ' + strip_tags(h).strip())
        elif name == 'list':
            walk(split_items(h), 0)
        elif name == 'paragraph':
            lines.append('P: ' + strip_tags(h).strip())
        else:
            lines.append('[%s]' % name)
    return '\n'.join(lines)


def load_fixups():
    path = os.path.join(HERE, 'fixups.py')
    if not os.path.exists(path):
        return {}, {}
    spec = importlib.util.spec_from_file_location('fixups', path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return getattr(mod, 'PRE', {}), getattr(mod, 'POST', {})


def apply_fixups(s, rules, slug):
    for rule in rules.get(slug, []):
        if callable(rule):
            s = rule(s)
            continue
        pat, repl = rule
        n = len(re.findall(pat, s, flags=re.S))
        if n == 0:
            print('   !! fixup did not match on %s: %r' % (slug, pat[:70]))
        s = re.sub(pat, repl, s, flags=re.S)
    return s


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    only = sys.argv[1:]
    pre, post = load_fixups()
    for key, (slug, opts) in PAGES.items():
        if only and slug not in only:
            continue
        src = open(os.path.join(SRC, key + '.html'), encoding='utf-8').read()
        src = apply_fixups(src, pre, slug)
        res = transform(src, slug, opts)
        res = apply_fixups(res, post, slug)
        open(os.path.join(OUT, slug + '.html'), 'w', encoding='utf-8', newline='\n').write(res)
        open(os.path.join(SRC, slug + '.outline.txt'), 'w', encoding='utf-8').write(outline(res))
        print('%-48s %6d -> %6d  headings=%d items=%d' % (
            slug, len(src), len(res), res.count('<!-- wp:heading'), res.count('<!-- wp:list-item -->')))
