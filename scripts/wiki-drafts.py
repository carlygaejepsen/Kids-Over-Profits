"""Wiki update drafts (docs/PLAN.md 3.12 step 3): apply the drafters' additions to an entry, never anything else.

Each tmp/wiki-updates/drafts/<id>/ holds entry.md and gaps.json (scripts/wiki-pilot-prep.py) and the
drafters' ops files: ops-haiku.json, ops-sonnet.json, ops.json (Opus's final list, every op with a
verdict). An op adds text only:

  {"id": "o1", "op": "append_to_section", "section": "Related Media", "text": "...", "gids": ["g3"]}
  {"id": "o2", "op": "add_section", "after_section": "History and Background Information",
   "heading": "## **Closure**", "text": "...", "gids": ["g1"]}
  {"id": "o3", "op": "set_header_years", "years": "2008-2024", "gids": ["g1"]}

plus "by" (haiku/sonnet/opus), "verdict" (ok, fixed, dropped) and "note". Sections are found by their
heading text without #, * and spaces, case ignored. ops with verdict "dropped" are not applied.

The ops are placed as the wiki editor's template places them (js/wiki-generation.js, templated()): news article lines
go under "In the Media" (the entry's own, else a new one after its abuse section), an addition replaces a section's
stand-in text ("... has not been added yet"), every template section the entry lacks is added with the editor's request
for information and its modmail link, and bold spacing is the editor's ("**Name** was", never "**Name**was").

A closed program's entry (or one about an earlier name) also gets ops-tense.json, the one op that
rewrites existing lines: {"id": "t1", "op": "past_tense", "lines": [{"old": "<exact line>", "new": "..."}]}.
A line is taken only when the sole changes are verbs put in the past tense ("is" -> "was", "uses" ->
"used", "runs" -> "ran") and dropped "current"/"currently"/"now"/"still"; anything else refuses the draft.

    python scripts/wiki-drafts.py assemble [ids...]   # ops-tense.json + ops.json -> draft.md; refuses a draft that loses or changes a line
    python scripts/wiki-drafts.py check [ids...]       # the same checks, prints per entry
    python scripts/wiki-drafts.py selftest             # the tense checker on fixed cases
    python scripts/wiki-drafts.py export [ids...]      # passing drafts' ops -> js/data/reddit-wiki/update-drafts.json (Wiki updates queue)
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRAFTS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
SEPARATOR = re.compile(r'^\s*(-{3,}|\*{3,}|_{3,})\s*$')
HEADING = re.compile(r'^\s*#{1,6}\s')
# A source with no page of its own, cited by name: an inspection report (state and date) or a case (name v. name, number).
TEXT_CITE = re.compile(r'\([^()]*(inspection report|\sv\.\s|No\.\s?\d)[^()]*\)')
# A wiki entry links the programs, companies and people it names to their own r/troubledteens wiki pages, never to KOP's
# profile pages. A fact whose only source is KOP's record is written without a KOP link: its op carries "kop_record": true,
# which lets its lines stand without a citation. KOP's articles and media-library documents are cited as usual.
KOP_PAGE = re.compile(r'^https?://(www\.)?kidsoverprofits\.org/(facility|operator|network-map|wiki-feed|tti-program-index|location-index|'
                      r'search|open-data|phpbb)(/|#|\?|$)', re.I)


def norm(heading):
    return re.sub(r'[#*\s:]+', ' ', heading).strip().lower()


FOOTER = re.compile(r'^\s*(last revised by\b|#{1,6}\s*page title\s*$)', re.I)


def reddit_format(md):
    """KOP's copies came from markdown_output/, converted back from the rendered Reddit pages, not Reddit's own source.
    Undo what the conversion broke so the text pastes onto Reddit as it rendered there (PHP: kop_wiki_drafts_reddit_format(),
    must match): bold opened with a space ("** Name**" -> "**Name**"), a lost bullet ("***Lower Form:**" -> "* **Lower Form:**"),
    the header's missing space ("**Name**(1987-present)" -> "**Name** (1987-present)"), and Reddit's page footer copied in
    ("Last revised by ...", "## Page title"), which is dropped with any separators before it. Runs after the no-loss check."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    stop = footer_start(lines)
    lines = lines[:stop]
    while lines and (not lines[-1].strip() or SEPARATOR.match(lines[-1])):
        lines.pop()
    out = []
    for n, line in enumerate(lines):
        line = re.sub(r'^\*\*\*(?=\S)', '* **', line)
        line = re.sub(r'(^|[\s(\[])\*\* +(?=\S)', r'\1**', line)
        if n == 0:
            line = re.sub(r'\*\*\(', '** (', line, count=1)
        line = HEAL_URL.sub(lambda m: heal_archive().get(heal_key(m.group(0)), m.group(0)), line)
        out.append(bold_spacing(line))
    return '\n'.join(out) + '\n'


def bold_spacing(line):
    """The wiki editor's normalizeBoldSpacing() (js/wiki-generation.js), which every entry it writes goes through:
    "** text **" -> "**text**", "at**Name**" -> "at **Name**", "**Name**text" -> "**Name** text", "by*many*survivors" ->
    "by *many* survivors". The markers are paired in order, first with second, third with fourth, so a line holding two
    spans keeps both. A line with an odd number of markers keeps its bold as it is. JS and PHP must match."""
    parts = line.split('**')
    if len(parts) > 1 and len(parts) % 2 == 1 and all(parts[k].strip() for k in range(1, len(parts), 2)):
        for k in range(1, len(parts), 2):
            parts[k] = parts[k].strip(' \t')
            if parts[k - 1] and not re.search(r'[\s*|\[("\']$', parts[k - 1]):
                parts[k - 1] += ' '
            if parts[k + 1] and not re.match(r'[\s*|).,;:!?\'"\]]', parts[k + 1]):
                parts[k + 1] = ' ' + parts[k + 1]
        line = '**'.join(parts)
    return re.sub(r'([A-Za-z])\*([A-Za-z][^*\n|]{0,60}?)\*([A-Za-z])', r'\1 *\2* \3', line)


# The editor's stand-in text for an empty section (getPlaceholder() and isEffectivelyEmpty() in js/wiki-generation.js).
# A section that gains a line loses its stand-in, as the editor would write it. PHP: kop_wiki_drafts_is_placeholder().
PLACEHOLDER = re.compile(r'^(background information for |information about |detailed information about |documented information about '
                         r'|no survivor testimonies for |no related media links for |no media coverage for |additional information about '
                         r'|programs associated with |no information is (?:currently )?known|no information available)', re.I)


def is_placeholder(line):
    # The older editor set its stand-in in italics ("*No information is currently known regarding ...*").
    t = line.strip().strip('*_').strip()
    if not t or len(t) > 350 or not PLACEHOLDER.match(t):
        return False
    low = t.lower()
    if low.startswith('no information'):
        # The older editor's stand-in: "No information is known about <Section> at <Program>. If you attended <Program> and
        # would like to contribute information to help complete this page, please contact ...".
        return len(t) < 120 or 'would like to contribute information to help complete this page' in low
    return 'added' in low or low.startswith(('detailed ', 'documented '))


# A news article line, as the editor's article form writes it: "[Title](url) (Outlet, 8/27/1994)", "- " in front or not.
NEWS_LINE = re.compile(r'^(?:[-*] )?\[[^\]]+\]\(https?://[^)\s]+\) \([^()]*\b\d{1,2}/\d{1,2}/\d{4}\)$')
MEDIA_SECTIONS = ('in the media', 'news articles', 'media coverage', 'news')
# Where the editor's template puts "In the Media" when the entry has none: after the abuse section, else after the last of these.
MEDIA_AFTER = ('rules and punishments', 'punishments', 'program structure', 'founders and notable staff', 'history and background information')


def templated(md, ops, name=''):
    """The ops as the wiki editor's template places them (js/wiki-generation.js): news articles go under "In the Media", the
    rest of Related Media stays. A Related Media op's article lines move to the entry's own In the Media (or News Articles)
    section; an entry without one gets the template's "In the Media" section after its abuse section, at that heading's
    level, as a "- " list. Every other template section the entry lacks is added with the editor's request for
    information (ops "f<n>", "filler": true). Other ops are returned unchanged."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    secs = sections(lines)
    # ", left" after a role ("Therapist (2017-2025), left of X") goes: a past range already says they left (owner,
    # 2026-10-08; kop_wiki_drafts_all() in inc/wiki-update-drafts.php does the same for drafts exported before).
    ops = [dict(op, text=plain_citations(re.sub(r'(?<=[)\w]), left(?= of )', '', op['text']), staff=is_staff_section(op.get('section', ''))))
           if isinstance(op.get('text'), str) else op for op in ops]
    media = next((s for s in secs if s[2] in MEDIA_SECTIONS), None)
    abuse = next((s for s in secs if s[2].startswith('abuse') or 'lawsuit' in s[2] or s[2] == 'deaths'), None)
    after = abuse or next((s for name in MEDIA_AFTER for s in secs if s[2] == name), None)
    sep = next((l.strip() for l in lines[:footer_start(lines)] if SEPARATOR.match(l)), '---')
    out, made = [], None
    for op in ops:
        if op.get('op') != 'append_to_section' or norm(op.get('section', '')) != 'related media' or op.get('verdict') == 'dropped':
            out.append(op)
            continue
        items = [l for l in (op.get('text') or '').split('\n') if l.strip()]
        news = [l for l in items if NEWS_LINE.match(l.strip())]
        if not news or (not media and not after):
            out.append(op)
            continue
        rest = [l for l in items if not NEWS_LINE.match(l.strip())]
        if rest:
            out.append(dict(op, text='\n\n'.join(rest)))
        bare = [re.sub(r'^[-*] ', '', l.strip()) for l in news]
        if media:
            body = [l for l in lines[media[0] + 1:body_end(lines, media[0], media[1])] if l.strip() and not SEPARATOR.match(l)]
            listed = body and all(re.match(r'^[-*] ', l.strip()) for l in body if not is_placeholder(l))
            fresh = all(is_placeholder(l) for l in body)
            text = '\n'.join('- ' + l for l in bare) if fresh or listed else '\n\n'.join(bare)
            out.append(dict(op, id=op['id'] + 'm', section=re.sub(r'[#*]+', '', lines[media[0]]).strip(), text=text,
                            note=(op.get('note') or '') + ' News articles go under In the Media, as in the wiki editor.'))
        elif made is None:
            level = re.match(r'\s*(#+)', lines[after[0]]).group(1)
            made = dict(op, id=op['id'] + 'm', op='add_section', after_section=re.sub(r'[#*]+', '', lines[after[0]]).strip(),
                        heading=f'{level} **In the Media**', separator=sep, text='\n'.join('- ' + l for l in bare),
                        note=(op.get('note') or '') + " News articles go under In the Media, the wiki editor's section for them.")
            made.pop('section', None)
            out.append(made)
        else:
            made['text'] += '\n' + '\n'.join('- ' + l for l in bare)
    # Every section of the template that the entry lacks, with the editor's request for information (getPlaceholder()):
    # after the nearest earlier template section, at its heading's level. Owner, 2026-10-08: all of them, as the editor does.
    present = {c: next((re.sub(r'[#*]+', '', lines[s[0]]).strip() for s in secs if is_c(s[2])), None) for c, is_c, _ in TEMPLATE}
    levels = {s[2]: re.match(r'\s*(#+)', lines[s[0]]).group(1) for s in secs}
    if made:
        present['In the Media'] = 'In the Media'
    level_of = {c: levels.get(norm(h), '##') for c, h in present.items() if h}
    fill = []
    for n, (canon, _, text) in enumerate(TEMPLATE):
        if canon == 'In the Media' and made:
            # The news section made above takes its place in the template's order, so later sections can follow it.
            out.remove(made)
            fill.append(made)
            continue
        if present[canon] or not name:
            continue
        anchor = next((present[c] for c, _, _ in reversed(TEMPLATE[:n]) if present[c]), None)
        if not anchor:
            continue
        level = level_of.get(next(c for c, _, _ in reversed(TEMPLATE[:n]) if present[c]), '##')
        fill.append({'id': 'f' + str(n + 1), 'by': 'script', 'op': 'add_section', 'after_section': anchor,
                    'heading': f'{level} **{canon}**', 'separator': sep, 'text': text.format(name=name, contact=CONTACT_LINK),
                    'gids': [], 'verdict': 'ok', 'filler': True,
                    'note': "The wiki editor's empty section: the page has none, so it asks readers for information."})
        present[canon] = canon
        level_of[canon] = level
    # A section the entry has with nothing under it (no text, no subsection) gets the editor's stand-in too, worded
    # for its heading as getPlaceholder() words it ("Survivor/Parent Testimonials", "Locations", ...).
    for n, (start, end, key) in enumerate(secs):
        depth = len(re.match(r'\s*(#+)', lines[start]).group(1))
        if start == 0 or not name:
            continue   # the page title ("# **Name** (years) Town, ST", some at "##")
        nxt = secs[n + 1] if n + 1 < len(secs) else None
        if nxt and len(re.match(r'\s*(#+)', lines[nxt[0]]).group(1)) > depth:
            continue   # its body is its subsections
        if any(l.strip() and not SEPARATOR.match(l) for l in lines[start + 1:end]):
            continue
        heading = re.sub(r'[#*]+', '', lines[start]).strip()
        fill.append({'id': 'e' + str(n + 1), 'by': 'script', 'op': 'append_to_section', 'section': heading,
                     'text': placeholder(heading, name), 'gids': [], 'verdict': 'ok', 'filler': True,
                     'note': "The wiki editor's empty section: nothing is written under it, so it asks readers for information."})
    # The empty sections come first: an addition the record has for a section the entry lacked then lands in it,
    # in place of its request for information.
    return fill + out


REDDIT_WIKI = re.compile(r'^https?://(www\.|old\.)?reddit\.com/r/troubledteens/wiki/', re.I)
INLINE_CITE = re.compile(r'\(\[([^\]]+)\]\((https?://[^)\s]+)\)\)')
LINK = re.compile(r'\[([^\]]+)\]\((https?://[^)\s]+)\)')
# A citation in brackets: one link, or several separated by ";" or ",", and nothing else.
CITE_GROUP = re.compile(r'\((\[[^\]]+\]\(https?://[^)\s]+\)(?:\s*[;,]\s*\[[^\]]+\]\(https?://[^)\s]+\))*)\)')


def is_staff_section(name):
    k = norm(name)
    return 'staff' in k or 'founder' in k or 'employee' in k


def plain_citations(text, staff=False):
    """Owner, 2026-10-08: a citation is the linked word "source", never the source's name and issue
    ("([Woodbury Reports, October 2008 (#170), p. 21](url))" -> "([source](url))"); links to wiki pages stay. On staff
    lines a year that only dates the source goes ("was the Headmaster of X in 2008" -> "of X"); a span stays as one
    ("in 2010-2012" -> "from 2010 to 2012", "(2010-2012)" kept), and so does the year someone founded the program."""
    text = CITE_GROUP.sub(lambda m: '(' + LINK.sub(lambda k: k.group(0) if REDDIT_WIKI.match(k.group(2)) else f'[source]({k.group(2)})', m.group(1)) + ')', text)
    if not staff:
        return text
    out = []
    for line in text.split('\n'):
        # Only the role sentence that opens a staff paragraph ("**Name** was the Role of Program in 2008 (...)."); the
        # rest of a bio keeps its dates ("He was arrested in 2010").
        m = ROLE_SENTENCE.match(line)
        if m:
            head, rest = m.group(1), line[m.end(1):]
            head = re.sub(r' in (\d{4})[-–](\d{4})$', r' from \1 to \2', head)
            head = re.sub(r' in \d{4}(?: and \d{4})?$', '', head)   # the year(s) a source was written, not a start or end
            # "the Staff; later WWASP president of X": the later job is dropped from the role when the entry says it after.
            semi = re.match(r'^(\*\*[^*]+\*\* (?:was|is) (?:the|a|an) )([^;]+);\s*(?:later\s+)?([^;]+?)( of .*)?$', head)
            if semi:
                later_words = [w for w in re.findall(r'[a-z]{4,}', semi.group(3).lower()) if w != 'later']
                if later_words and all(w in rest.lower() for w in later_words):
                    head = semi.group(1) + semi.group(2).strip() + (semi.group(4) or '')
            head = re.sub(r'^(\*\*[^*]+\*\* (?:was|is) )(?:the|a|an) (.+?)( of |$)', role_phrase, head)
            line = head + rest
        if line.lstrip().startswith('**'):
            # "was also Teacher (2008) of X": a lone year in brackets only dates the source.
            line = re.sub(r'(was also [^.]*?)\s*\(\d{4}\)', r'\1', line)
            line = ALSO_CLAUSE.sub(lambda c: c.group(1) + also_roles(c.group(2)) + c.group(3), line)
        out.append(line)
    return '\n'.join(out)


# "Roe was also Therapist of [Provo Canyon School](...), Executive Director of X and Teacher of Y." (staff_line's other jobs)
ALSO_CLAUSE = re.compile(r'(\b\w+ was also )((?:[^.\[]|\[[^\]]*\]\([^)]*\))+?)(\.(?:\s|$)|$)')
# One job: a role, "of"/"at", and a place that is a link or starts with a capital, up to ", ", " and " or the end.
ALSO_ITEM = re.compile(r'(?:^|,\s+(?:and\s+)?|\s+and\s+)(.+?) (of|at) (\x00\d+\x00|[A-Z0-9"“][^,\x00]*?|(?:unnamed|an?|the|several|two|three)\b[^,\x00]*?)(?=,\s|\s+and\s|$)')
OF_ROLE = re.compile(r'(?i)found|owner|trustee|board|partner|investor|shareholder')
ALSO_MISSES = []


def also_roles(clause):
    """The other jobs of a staff line, each read as the role sentence is: "a therapist at [Provo Canyon School](...)",
    "the Executive Director of X"; a role with no place joins the next ("director, owner of X" -> "the director and
    owner of X"). Links are kept whole."""
    links = []
    masked = re.sub(r'\[[^\]]*\]\([^)]*\)', lambda m: links.append(m.group(0)) or f'\x00{len(links) - 1}\x00', clause)
    groups, pos = [], 0
    for m in ALSO_ITEM.finditer(masked):
        if m.start() != pos:
            ALSO_MISSES.append(clause)
            return clause   # not the shape staff_line writes: leave it as it is
        groups.append((re.sub(r',\s+', ' and ', m.group(1).strip()), m.group(3).strip()))
        pos = m.end()
    if not groups or pos != len(masked):
        ALSO_MISSES.append(clause)
        return clause
    parts = []
    for role, place in groups:
        if re.match(r'(?i)(the|a|an)\s', role):
            parts.append(f'{role} {"of" if role.lower().startswith("the ") else "at"} {place}')
            continue
        if role_article(role) == 'the':
            parts.append(f'the {role} of {place}')
            continue
        r = role.lower() if re.fullmatch(r'[A-Za-z][a-z-]+(?: and [A-Za-z][a-z-]+)*', role) else role
        if re.fullmatch(r'(?i)staff', r):
            r = 'staff member'
        prep = 'of' if OF_ROLE.search(r) else 'at'   # "a co-founder of", "a trustee of"; "a therapist at"
        parts.append(f'{role_article(r)} {r} {prep} {place}')
    text = parts[0] if len(parts) == 1 else ', '.join(parts[:-1]) + ' and ' + parts[-1]
    return re.sub('\x00(\\d+)\x00', lambda m: links[int(m.group(1))], text)


# The role sentence of a staff paragraph: "**Name** was|is|worked ..." up to its citation or full stop.
ROLE_SENTENCE = re.compile(r'^(\*\*[^*\n]+\*\* (?:was|is|worked)\b(?:[^.(\n]|\((?!\[))*?)(?= \(\[|\.(?:\s|$)|$)')
# Titles one person holds at a time take "the"; everything else ("Staff", "Teacher", "Therapist") takes "a"/"an".
ONE_HOLDER = re.compile(r'(?i)^(?!(assistant|associate|deputy|vice|co-?|former )\b)[^,]*\b(director|ceo|coo|cfo|cmo|cto|president|founder|'
                        r'owner|headmaster|headmistress|head of|principal|superintendent|administrator|chair(man|woman|person)?|chief|dean)\b')


def role_phrase(m):
    """'was the Teacher of X' -> 'was a teacher at X', 'was the Staff of X' -> 'was a staff member at X';
    'was the Headmaster of X' stays."""
    lead, role, of = m.group(1), m.group(2), m.group(3)
    if re.fullmatch(r'[A-Z][a-z]+(?: (?:[a-z][\w&/-]*|&))+', role.strip()):
        # A role the source wrote in sentence case ("Adventure therapy coordinator", "Clinical director") reads as
        # words mid-sentence; titles in title case ("Family Teacher", "Dean of Students") stay as written.
        role = role[0].lower() + role[1:]
    art = role_article(role)
    if art == 'the':
        return f'{lead}the {role}{of}'
    if re.fullmatch(r'(?i)staff', role.strip()):
        role = 'staff member'
    elif re.fullmatch(r'[A-Z][a-z]+', role.strip()):
        role = role.lower()   # one word: "Teacher" -> "teacher"; titles ("Assistant Director") and acronyms ("RN") stay
    art = role_article(role)
    return f'{lead}{art} {role}{(" of " if OF_ROLE.search(role) else " at ") if of else ""}'


def role_article(role):
    if ONE_HOLDER.search(role):
        return 'the'
    if re.match(r'[A-Z]{2}', role):   # an abbreviation is read by its letters: "an RN", "a CNA"
        return 'an' if role[0] in 'AEFHILMNORSX' else 'a'
    return 'an' if re.match(r'(?i)[aeiou]|hono|hour', role) and not re.match(r'(?i)(uni|use|eu|one)', role) else 'a'


# The modmail link every request for information names (js/wiki-generation.js CONTACT_LINK, api/lib-wiki-contact.php).
CONTACT_LINK = '[r/troubledteens modmail](https://www.reddit.com/message/compose?to=/r/troubledteens)'
# The program template's sections in the editor's order (generateWikiMarkdown()), how an entry's own heading is recognised
# as one of them, and the editor's stand-in text for it (getPlaceholder()).
TEMPLATE = [
    ('History and Background Information', lambda k: 'history' in k or 'background' in k,
     'Background information for {name} has not been added yet. If you have reliable historical details or sources to share, please contact {contact}.'),
    ('Founders and Notable Staff', lambda k: 'staff' in k or 'founder' in k or 'employee' in k,
     'Information about the founders or notable staff at {name} has not been added yet. If you have reliable names, roles, or source material to share, please contact {contact}.'),
    ('Program Structure', lambda k: 'structure' in k or 'level system' in k or 'phase' in k,
     'Information about the program structure at {name} has not been added yet. If you have reliable descriptions or source material to share, please contact {contact}.'),
    ('Rules and Punishments', lambda k: 'rule' in k or 'punishment' in k,
     'Information about the rules, consequences, or disciplinary practices at {name} has not been added yet. If you have reliable source material to share, please contact {contact}.'),
    ('Abuse/Neglect Allegations and Lawsuits', lambda k: 'abuse' in k or 'lawsuit' in k or 'allegation' in k,
     'Information about abuse allegations, neglect, or lawsuits involving {name} has not been added yet. If you have reliable reports or source material to share, please contact {contact}.'),
    ('In the Media', lambda k: k in MEDIA_SECTIONS,
     'No media coverage for {name} has been added yet. If you have seen a news item about {name} and would like to share it, please contact {contact}.'),
    ('Survivor Testimonies', lambda k: 'testimon' in k,
     'No survivor testimonies for {name} have been added here yet. If you have a firsthand account or reliable source material to share, please contact {contact}.'),
    ('Related Media', lambda k: 'related media' in k,
     'No related media links for {name} have been added yet. If you have reliable external resources to share, please contact {contact}.'),
]


PLACEHOLDER_BY_HEADING = [
    (('history', 'background'), TEMPLATE[0][2]),
    (('founders', 'staff'), TEMPLATE[1][2]),
    (('structure',), TEMPLATE[2][2]),
    (('rules', 'punishments'), TEMPLATE[3][2]),
    (('abuse', 'neglect', 'lawsuits'), TEMPLATE[4][2]),
    (('survivor testimonies', 'survivor testimony', 'testimonies', 'testimonials'), TEMPLATE[6][2]),
    (('related media',), TEMPLATE[7][2]),
    (('related programs', 'affiliated programs'),
     'Programs associated with {name} have not been added yet. If you have reliable information about operated, affiliated, or successor programs to share, please contact {contact}.'),
    (('media',), TEMPLATE[5][2]),
]


def placeholder(heading, name):
    """getPlaceholder() in js/wiki-generation.js: the stand-in text for an empty section, by its heading."""
    low = heading.lower()
    text = next((t for terms, t in PLACEHOLDER_BY_HEADING if any(w in low for w in terms)),
                'Additional information about {name} has not been added yet. If you have reliable updates or references to share, please contact {contact}.')
    return text.format(name=name, contact=CONTACT_LINK)


def entry_name(gaps):
    """The entry's program name as the page should print it, without what the Reddit conversion left on it ("**()")."""
    name = (gaps.get('entry', {}).get('program_name') or gaps.get('record', {}).get('name') or '')
    return re.sub(r'\s+', ' ', re.sub(r'\(\s*\)|\*+', '', name)).strip()


# heal-online.org links -> HEAL's own capture from before 2023 (js/data/reddit-wiki/heal-archive-urls.json, built by
# scripts/build-heal-archive-urls.py); the domain was later parked and filled with spam. PHP: kop_wiki_drafts_heal_key().
HEAL_URL = re.compile(r'(?<![/\w])https?://(?:www\.)?heal-online\.org(?::\d+)?(?:/[^\s)\]]*)?', re.I)
_HEAL = None


def heal_key(url):
    path = re.sub(r'^[a-z]+://(www\.)?heal-online\.org(:\d+)?', '', url.strip(), flags=re.I).split('?')[0].split('#')[0]
    name = path.strip('/').split('/')[-1].lower() if path.strip('/') else ''
    if name in ('index.htm', 'index.html', 'default.htm', 'default.html'):
        name = ''
    return re.sub(r'\.html$', '.htm', name)


def heal_archive():
    global _HEAL
    if _HEAL is None:
        path = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'heal-archive-urls.json')
        _HEAL = json.load(open(path, encoding='utf-8')) if os.path.exists(path) else {}
    return _HEAL


def footer_start(lines):
    """Reddit's own footer ("Last revised by", "## Page title"), copied in with the page: nothing goes after it."""
    for i, l in enumerate(lines):
        if FOOTER.match(l):
            return i
    return len(lines)


def sections(lines):
    """[(start, end, key)] per heading above the footer: lines start..end-1 are the heading and its body."""
    stop = footer_start(lines)
    heads = [i for i, l in enumerate(lines[:stop]) if HEADING.match(l)]
    out = []
    for n, i in enumerate(heads):
        end = heads[n + 1] if n + 1 < len(heads) else stop
        out.append((i, end, norm(lines[i])))
    return out


def body_end(lines, start, end):
    """Where to add at the end of a section: before its trailing blank lines and separator."""
    j = end
    while j > start + 1 and (not lines[j - 1].strip() or SEPARATOR.match(lines[j - 1])):
        j -= 1
    return j


def find(lines, name):
    want = norm(name)
    for s in sections(lines):
        if s[2] == want:
            return s
    for s in sections(lines):
        if want and (want in s[2] or s[2] in want) and len(s[2]) > 3:
            return s
    return None


MONTH_NAMES = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october',
               'november', 'december']
TIMELINE_SECTION = re.compile(r'abuse|lawsuit|allegation|incident|death|timeline')
TIMELINE_DATE = re.compile(r'(?:\b(?:(' + '|'.join(MONTH_NAMES) + r')\s*(?:and|to|through|-|–)\s*)?'   # "October and November 2023"
                           r'(' + '|'.join(MONTH_NAMES) + r')\s+(?:(\d{1,2})(?:\^\((?:st|nd|rd|th)\)|st|nd|rd|th)?,?\s+)?(?:of\s+)?)?'
                           r'(?<![\d/:.-])((?:19|20)\d\d)(?![\d-]|s\b)', re.I)
# A paragraph that dates itself from the one before ("Only a week after Clark's death", "The same day", "Shortly
# after, on ...") stays with it.
TIMELINE_RELATIVE = re.compile(r'(?:only|just|shortly|soon|later|then|also|following|after|afterwards|meanwhile|the same|'
                               r'the following|the next|that same|a (?:day|week|month|year)s? (?:later|after))\b', re.I)
TIMELINE_NUMERIC = re.compile(r'^\W*(\d{1,2})/(\d{1,2})/((?:19|20)\d\d)\b')


def timeline_key(line):
    """The date a paragraph of the abuse section is about: the first date on its first line, (year, month, day), or
    None for a paragraph that is not about a dated event (a list, a quote, a general description). Same as
    kop_wiki_drafts_timeline_key()."""
    t = line.strip()
    m = TIMELINE_NUMERIC.match(t)
    if m:
        return (int(m.group(3)), int(m.group(1)), int(m.group(2)))
    if not re.match(r'[A-Za-z]', t) or TIMELINE_RELATIVE.match(t):
        return None
    # Only the first sentence: a general paragraph that names a year further on ("between 2015 and 2020") is not
    # about that year.
    first = re.match(r'(.+?[.!?]["”)\]]*)(?=\s+["“(\[*]*[A-Z])', t)
    m = TIMELINE_DATE.search(first.group(1) if first else t)
    if not m:
        return None
    name = m.group(1) or m.group(2)
    month = MONTH_NAMES.index(name.lower()) + 1 if name else 0
    day = int(m.group(3)) if m.group(3) and not m.group(1) else 0
    return (int(m.group(4)), month, day)


def timeline_order(md):
    """The abuse section's paragraphs in date order (owner, 2026-10-08: the incident timeline was not chronological;
    additions go at the end of the section, and earlier rounds were pasted that way). A paragraph with no date stays
    with the one before it (the list or quote that paragraph introduces); paragraphs before the first dated one stay
    on top. Runs after the no-loss check: every line is still there, only moved. -> (md, order), order[new] = old
    line number. Same as kop_wiki_drafts_timeline_order()."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    order = list(range(len(lines)))
    for start, end, key in reversed(sections(lines)):
        if not TIMELINE_SECTION.search(key):
            continue
        stop = body_end(lines, start, end)
        blocks, cur = [], []
        for k in range(start + 1, stop):
            if lines[k].strip():
                cur.append(k)
            elif cur:
                blocks.append(cur)
                cur = []
        if cur:
            blocks.append(cur)
        head, groups = [], []
        for b in blocks:
            d = timeline_key(lines[b[0]])
            if d is not None:
                groups.append((d, [b]))
            elif groups:
                groups[-1][1].append(b)
            else:
                head.append(b)
        ordered = head + [b for _, bs in sorted(groups, key=lambda g: g[0]) for b in bs]
        if ordered == blocks:
            continue
        seq = [start]
        for n, b in enumerate(ordered):
            seq += b
            if n < len(ordered) - 1:
                seq.append(None)   # one blank line between paragraphs
        new_lines = [lines[k] if k is not None else '' for k in seq]
        new_order = [order[k] if k is not None else None for k in seq]
        lines[start:stop] = new_lines
        order[start:stop] = new_order
    return '\n'.join(lines) + '\n', order


def apply(md, ops):
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    applied, errors = [], []
    for op in ops:
        if op.get('verdict') == 'dropped':
            continue
        kind = op.get('op')
        text = (op.get('text') or '').strip('\n')
        new = text.split('\n') if text else []
        if kind == 'set_header_years':
            first = lines[0]
            changed = re.sub(r'(\*\*\s*)\(([^)]*)\)', lambda m: m.group(1) + '(' + op['years'] + ')', first, count=1)
            if changed == first and '(' not in first:
                changed = re.sub(r'^(#+\s*\*\*.*?\*\*)', lambda m: m.group(1) + '(' + op['years'] + ')', first, count=1)
            if changed == first:
                errors.append(f"{op.get('id')}: header has no years to set")
                continue
            lines[0] = changed
        elif kind == 'append_to_section':
            s = find(lines, op.get('section', ''))
            if not s:
                errors.append(f"{op.get('id')}: no section '{op.get('section')}'")
                continue
            at = body_end(lines, s[0], s[1])
            body = [k for k in range(s[0] + 1, at) if lines[k].strip() and not SEPARATOR.match(lines[k])]
            if body and all(is_placeholder(lines[k]) for k in body):
                # Only the editor's stand-in text: the addition takes its place.
                lines[body[0]:at] = new
            else:
                lines[at:at] = [''] + new
        elif kind == 'add_section':
            s = find(lines, op.get('after_section', ''))
            at = body_end(lines, s[0], s[1]) if s else body_end(lines, 0, footer_start(lines))
            lines[at:at] = ['', op.get('separator') or '---', '', op['heading'].strip(), ''] + new
        else:
            errors.append(f"{op.get('id')}: unknown op '{kind}'")
            continue
        applied.append(op.get('id'))
    return '\n'.join(lines) + '\n', applied, errors


def check(original, draft, header_changed, kop_lines=frozenset(), kop_page_lines=frozenset()):
    """Every original line is still there, in order (the header may only change its years); every added line cites a link."""
    o = original.replace('\r\n', '\n').rstrip('\n').split('\n')
    d = draft.rstrip('\n').split('\n')
    problems = []
    if header_changed:
        strip = lambda l: re.sub(r'\([^)]*\)', '()', l, count=1)
        if strip(o[0]) != strip(d[0]):
            problems.append('the header changed beyond its years')
        o, d = o[1:], d[1:]
    j = 0
    added = []
    for line in d:
        # The editor's stand-in text for an empty section may go (apply() replaces it with the addition).
        while j < len(o) and line != o[j] and is_placeholder(o[j]):
            j += 1
        if j < len(o) and line == o[j]:
            j += 1
        else:
            added.append(line)
    while j < len(o) and is_placeholder(o[j]):
        j += 1
    if j < len(o):
        problems.append(f'original line {j + 1} is missing or changed: {o[j][:80]!r}')
    for line in added:
        t = line.strip()
        if not t or HEADING.match(t) or SEPARATOR.match(t):
            continue
        if '](' not in t and not TEXT_CITE.search(t) and t not in kop_lines:
            problems.append(f'added line without a source: {t[:80]!r}')
        for url in re.findall(r'\]\((https?://[^)\s]+)\)', t):
            if KOP_PAGE.match(url) and t not in kop_page_lines:
                problems.append(f'links a KOP page, not the wiki page or the original source: {url}')
    if re.search(r'[\U0001F300-\U0001FAFF☀-➿]', '\n'.join(added)):
        problems.append('an emoji in the added text')
    return problems, added


IRREGULAR = {
    'is': 'was', 'are': 'were', 'has': 'had', 'have': 'had', 'does': 'did', 'do': 'did', 'can': 'could', 'will': 'would',
    "isn't": "wasn't", "aren't": "weren't", "doesn't": "didn't", "don't": "didn't", "hasn't": "hadn't", "haven't": "hadn't",
    "can't": "couldn't", "won't": "wouldn't", 'am': 'was', 'may': 'might', 'shall': 'should', 'must': 'hadto', 'hasto': 'hadto', 'haveto': 'hadto',
}
STRONG = {
    'run': 'ran', 'keep': 'kept', 'send': 'sent', 'take': 'took', 'make': 'made', 'hold': 'held', 'give': 'gave', 'get': 'got',
    'say': 'said', 'teach': 'taught', 'bring': 'brought', 'lead': 'led', 'pay': 'paid', 'spend': 'spent', 'cost': 'cost',
    'put': 'put', 'see': 'saw', 'go': 'went', 'come': 'came', 'become': 'became', 'tell': 'told', 'feel': 'felt', 'meet': 'met',
    'find': 'found', 'think': 'thought', 'buy': 'bought', 'sell': 'sold', 'let': 'let', 'set': 'set', 'write': 'wrote',
    'speak': 'spoke', 'wear': 'wore', 'sleep': 'slept', 'eat': 'ate', 'forbid': 'forbade', 'beat': 'beat', 'hire': 'hired',
    'stand': 'stood', 'understand': 'understood', 'leave': 'left', 'sit': 'sat', 'lose': 'lost', 'build': 'built', 'win': 'won',
    'break': 'broke', 'choose': 'chose', 'wake': 'woke', 'drive': 'drove', 'fall': 'fell', 'hide': 'hid', 'fight': 'fought',
    'catch': 'caught', 'seek': 'sought', 'ride': 'rode', 'rise': 'rose', 'shake': 'shook', 'throw': 'threw', 'grow': 'grew',
    'know': 'knew', 'draw': 'drew', 'begin': 'began', 'sing': 'sang', 'drink': 'drank', 'forget': 'forgot', 'hear': 'heard',
    'mean': 'meant', 'read': 'read', 'deal': 'dealt', 'shut': 'shut', 'hurt': 'hurt', 'hit': 'hit', 'cut': 'cut', 'quit': 'quit',
    'spread': 'spread', 'strike': 'struck', 'stick': 'stuck', 'swear': 'swore', 'tear': 'tore', 'steal': 'stole', 'bite': 'bit',
    'feed': 'fed', 'flee': 'fled', 'bleed': 'bled', 'lay': 'laid', 'forgive': 'forgave', 'withhold': 'withheld', 'undergo': 'underwent',
    'overcome': 'overcame', 'oversee': 'oversaw', 'shoot': 'shot', 'sweep': 'swept', 'weep': 'wept', 'kneel': 'knelt',
    'lie': 'lay', 'lies': 'lay', 'bear': 'bore', 'bind': 'bound', 'dig': 'dug', 'fly': 'flew', 'freeze': 'froze', 'hang': 'hung',
    'light': 'lit', 'ring': 'rang', 'seek': 'sought', 'shine': 'shone', 'sink': 'sank', 'slide': 'slid', 'spin': 'spun',
    'stink': 'stank', 'swim': 'swam', 'swing': 'swung', 'wind': 'wound', 'withdraw': 'withdrew', 'mislead': 'misled',
}
# Words a past-tense rewrite may drop: they only say "as of now".
DROPPABLE = {'current', 'currently', 'now', 'still', 'presently', 'today'}


def _stems(word):
    """Base forms a present-tense verb may come from ("uses" -> use, "carries" -> carry, "pushes" -> push)."""
    out = {word}
    if word.endswith('ies'):
        out.add(word[:-3] + 'y')
    if word.endswith('es'):
        out.add(word[:-2])
    if word.endswith('s'):
        out.add(word[:-1])
    return out


def tense_pair(old, new):
    """True when new is old put in the past tense."""
    o, n = old.lower(), new.lower()
    if IRREGULAR.get(o) == n:
        return True
    if (o, n) in (('may', 'could'), ('might', 'could')):   # "may" of permission: "Students may not speak" -> "could not"
        return True
    for stem in _stems(o):
        if STRONG.get(stem) == n:
            return True
        if n in (stem + 'd', stem + 'ed', stem[:-1] + 'ied' if stem.endswith('y') else '', stem + stem[-1:] + 'ed'):
            return True
    return False


TOKEN = re.compile(r"[A-Za-z]+(?:'[A-Za-z]+)?|\s+|[^\sA-Za-z]")


def tense_only(old, new):
    """'' when new differs from old only by verbs put in the past tense and dropped "current"/"still"/...; else why not."""
    import difflib
    # Curly apostrophes read as straight ones; "must" -> "had to" is one change.
    old, new = old.replace('\u2019', "'"), new.replace('\u2019', "'")
    # "has to"/"have to"/"had to" become one word on both sides, so "has to" -> "had to" and "must" -> "had to" both pair.
    joined = lambda t: re.sub(r'\b([Hh])(as|ave|ad) to\b', lambda m: m.group(1) + m.group(2) + 'to', t)
    # "cannot" reads as "can not", so "cannot" -> "could not" pairs "can" with "could".
    old = re.sub(r'\b([Cc])annot\b', r'\1an not', old)
    new = re.sub(r'\b([Cc])annot\b', r'\1an not', new)
    old, new = joined(old), joined(new)
    a, b = TOKEN.findall(old), TOKEN.findall(new)
    if ''.join(a) != old or ''.join(b) != new:
        return 'could not read the line'
    for tag, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
        if tag == 'equal':
            continue
        olds = [t for t in a[i1:i2] if t.strip()]
        news = [t for t in b[j1:j2] if t.strip()]
        if tag == 'delete' or (tag == 'replace' and not news):
            if all(t.lower() in DROPPABLE or t == ',' for t in olds):
                continue
            return 'removes ' + ' '.join(olds)
        if tag == 'insert':
            return 'adds ' + ' '.join(news)
        # A replaced run: pair the words up after dropping droppable ones.
        olds = [t for t in olds if t.lower() not in DROPPABLE]
        if len(olds) != len(news) or not all(x == y or tense_pair(x, y) for x, y in zip(olds, news)):
            return f"changes '{' '.join(a[i1:i2]).strip()}' to '{' '.join(b[j1:j2]).strip()}'"
    return ''


def apply_tense(md, ops):
    """Past-tense ops: each line replaced must exist once and change only its verbs' tense. -> (md, lines changed, errors)."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    changed, errors = 0, []
    for op in ops:
        if op.get('op') != 'past_tense' or op.get('verdict') == 'dropped':
            continue
        for k, pair in enumerate(op.get('lines', [])):
            old, new = pair.get('old', '').rstrip('\r'), pair.get('new', '').rstrip('\r')
            hits = [n for n, line in enumerate(lines) if line == old]
            if len(hits) != 1:
                errors.append(f"{op.get('id')}.{k + 1}: line {'not found' if not hits else 'found more than once'}: {old[:70]!r}")
                continue
            why = tense_only(old, new)
            if why:
                errors.append(f"{op.get('id')}.{k + 1}: not only a tense change ({why}): {old[:70]!r}")
                continue
            lines[hits[0]] = new
            changed += 1
    return '\n'.join(lines) + '\n', changed, errors


def load_fixes(folder):
    """ops-fix.json: corrections of existing lines the owner asked for (a misspelled name), each
    {"id": "f1", "old": "<exact line>", "new": "<the line corrected>", "note": "why"}. -> the list."""
    path = os.path.join(folder, 'ops-fix.json')
    return json.load(open(path, encoding='utf-8')).get('fixes', []) if os.path.exists(path) else []


def apply_fixes(md, fixes):
    """Each fix replaces one existing line that must be there once (applied before the past tense). -> (md, count, errors)."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    done, errors = 0, []
    for f in fixes:
        hits = [n for n, line in enumerate(lines) if line == f['old'].rstrip('\r')]
        if len(hits) != 1:
            errors.append(f"{f.get('id')}: line {'not found' if not hits else 'found more than once'}: {f['old'][:70]!r}")
            continue
        lines[hits[0]] = f['new'].rstrip('\r')
        done += 1
    return '\n'.join(lines) + '\n', done, errors


def add_kop_page(folder, md, ops):
    """The record's KOP facility page as the last Related Media item (owner, 2026-10-06), unless the entry or an op links it
    already or the record has no page. The one KOP page link a draft may carry. -> True when an op was added."""
    try:
        gaps = json.load(open(os.path.join(folder, 'gaps.json'), encoding='utf-8'))
    except FileNotFoundError:
        return False
    rec = gaps.get('record', {})
    url = rec.get('url') or ''
    name = re.sub(r'\s+', ' ', re.sub(r'\(\s*\)|\*+', '', gaps.get('entry', {}).get('program_name') or rec.get('name') or '')).strip()   # the entry's own name
    if not url or not name or url in md or any(o.get('kop_page') or url in (o.get('text') or '') for o in ops):
        return False
    if not find(md.replace('\r\n', '\n').split('\n'), 'Related Media'):
        return False
    ops.append({'id': 'k1', 'by': 'script', 'op': 'append_to_section', 'section': 'Related Media', 'kop_page': True,
                'text': f'[{name} on Kids Over Profits]({url})', 'gids': [], 'verdict': 'ok',
                'note': "KOP's facility page for the record, as the last Related Media item."})
    return True


def exported_tense(i):
    """The past tense already in the export for an entry whose folder has no ops-tense.json, as one past_tense op."""
    try:
        pairs = json.load(open(EXPORT, encoding='utf-8'))['entries'].get(str(i), {}).get('tense', [])
    except (FileNotFoundError, ValueError):
        return []
    return [{'id': 't0', 'op': 'past_tense', 'lines': [{'old': p['old'], 'new': p['new']} for p in pairs]}] if pairs else []


def run(ids, write):
    ok = True
    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        path = os.path.join(folder, 'ops.json')
        if not os.path.exists(path):
            print(f'{i}: no ops.json')
            ok = False
            continue
        with open(os.path.join(folder, 'entry.md'), encoding='utf-8') as f:
            md = f.read()
        with open(path, encoding='utf-8') as f:
            ops_file = json.load(f)
        ops = ops_file.get('ops', [])
        if write and add_kop_page(folder, md, ops):
            with open(path, 'w', encoding='utf-8', newline='\n') as f:
                json.dump(ops_file, f, ensure_ascii=False, indent=1)
        tense_path = os.path.join(folder, 'ops-tense.json')
        tense_ops = json.load(open(tense_path, encoding='utf-8')).get('ops', []) if os.path.exists(tense_path) else exported_tense(i)
        # The tense pass rewrites lines in place; the additions then go on top, and the check
        # compares with the tensed text, so every other original line must still be there.
        fixed_md, fixes, fix_errors = apply_fixes(md, load_fixes(folder))
        base, tensed, tense_errors = apply_tense(fixed_md, tense_ops)
        tense_errors = fix_errors + tense_errors
        gaps_path = os.path.join(folder, 'gaps.json')
        ops = templated(base, ops, entry_name(json.load(open(gaps_path, encoding='utf-8'))) if os.path.exists(gaps_path) else '')
        draft, applied, errors = apply(base, ops)
        header = any(o.get('op') == 'set_header_years' and o.get('verdict') != 'dropped' for o in ops)
        # Lines standing on KOP's own record, or citing a source named in words with no link, need no link.
        kop_lines = frozenset(l.strip() for o in ops if (o.get('kop_record') or o.get('text_cited')) and o.get('verdict') != 'dropped'
                              for l in (o.get('text') or '').split('\n') if l.strip())
        kop_page_lines = frozenset((o.get('text') or '').strip() for o in ops if o.get('kop_page') and o.get('verdict') != 'dropped')
        problems, added = check(base, draft, header, kop_lines, kop_page_lines)
        draft = timeline_order(draft)[0]
        problems = tense_errors + errors + problems
        status = 'OK' if not problems else 'REFUSED'
        print(f'{i}: {status}, {len(applied)} ops applied, {sum(1 for a in added if a.strip())} lines added, {tensed} put in the past tense'
              + ''.join(f'\n    {p}' for p in problems))
        if problems:
            ok = False
        if write:
            with open(os.path.join(folder, 'draft.md'), 'w', encoding='utf-8', newline='\n') as f:
                f.write(reddit_format(draft))
            with open(os.path.join(folder, 'check.json'), 'w', encoding='utf-8', newline='\n') as f:
                json.dump({'status': status, 'problems': problems, 'applied': applied, 'tensed': tensed}, f, indent=1)
    return ok


def selftest():
    cases = [
        ('Provo Canyon School is a residential treatment center that serves teens.', 'Provo Canyon School was a residential treatment center that served teens.', True),
        ('**Jane Doe** is the current Executive Director.', '**Jane Doe** was the Executive Director.', True),
        ('The program still uses level systems and carries out restraints.', 'The program used level systems and carried out restraints.', True),
        ('Staff run the groups and admit students year round.', 'Staff ran the groups and admitted students year round.', True),
        ('"I have nightmares," she said.', '"I had nightmares," she said.', True),   # allowed by the checker; the drafter must leave quotes alone
        ('The school is in Provo.', 'The school was in Orem.', False),
        ('The school is abusive.', 'The school was reportedly abusive.', False),
        ('It has 106 beds.', 'It had 100 beds.', False),
        ('It is open.', 'It was closed.', False),
        ('If a resident breaks a rule, the teens must sit and won\u2019t speak.', 'If a resident broke a rule, the teens had to sit and wouldn\u2019t speak.', True),
        ('Staff may restrain them.', 'Staff might restrain them.', True),
        ('The teen has to accept it and they have to stay; others had to wait.', 'The teen had to accept it and they had to stay; others had to wait.', True),
        ('The teen has to accept it.', 'The teen has to accept it.', True),
        ('Staff must restrain them.', 'Staff had to hold them.', False),
    ]
    bad = 0
    for old, new, want in cases:
        got = tense_only(old, new) == ''
        if got != want:
            bad += 1
            print(f'FAIL {old!r} -> {new!r}: {tense_only(old, new) or "accepted"}')
    md = 'line one is here\nline two is here\n'
    out, n, err = apply_tense(md, [{'id': 't1', 'op': 'past_tense', 'lines': [{'old': 'line two is here', 'new': 'line two was here'}]}])
    if n != 1 or err or out != 'line one is here\nline two was here\n':
        bad += 1
        print('FAIL apply_tense', n, err)
    out, n, err = apply_tense(md, [{'id': 't1', 'op': 'past_tense', 'lines': [{'old': 'line three', 'new': 'x'}]}])
    if n or not err:
        bad += 1
        print('FAIL a line that is not there is refused')
    print('selftest:', 'all passed' if not bad else f'{bad} failed')
    return not bad


EXPORT = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'update-drafts.json')
OP_KEYS = ('id', 'op', 'section', 'after_section', 'heading', 'separator', 'years', 'filler', 'text', 'by', 'verdict', 'note', 'kop_record', 'kop_page')


def export(ids):
    """Every assembled draft that passed its check -> js/data/reddit-wiki/update-drafts.json, read by the Wiki updates queue
    (inc/wiki-update-drafts.php). Only the ops travel: the site applies them to the entry's own text, so a draft whose entry
    changed since is still reviewable (the card says so). Entries already in the file and not in ids are kept."""
    import hashlib
    try:
        with open(EXPORT, encoding='utf-8') as f:
            out = json.load(f)
    except FileNotFoundError:
        out = {'entries': {}}
    done = 0
    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        try:
            check_ = json.load(open(os.path.join(folder, 'check.json'), encoding='utf-8'))
            gaps = json.load(open(os.path.join(folder, 'gaps.json'), encoding='utf-8'))
            ops = json.load(open(os.path.join(folder, 'ops.json'), encoding='utf-8')).get('ops', [])
        except FileNotFoundError as e:
            print(f'{i}: skipped, {os.path.basename(e.filename)} missing (run assemble first)')
            continue
        if check_.get('status') != 'OK':
            print(f'{i}: skipped, its draft was refused')
            continue
        tense_path = os.path.join(folder, 'ops-tense.json')
        tense_ops = json.load(open(tense_path, encoding='utf-8')).get('ops', []) if os.path.exists(tense_path) else []
        with open(os.path.join(folder, 'entry.md'), encoding='utf-8') as f:
            base = f.read().replace('\r\n', '\n').rstrip('\n')
        tense = []
        for op in tense_ops:
            if op.get('op') != 'past_tense' or op.get('verdict') == 'dropped':
                continue
            for k, pair in enumerate(op.get('lines', [])):
                tense.append({'id': f"{op.get('id')}.{k + 1}", 'old': pair['old'].rstrip('\r'), 'new': pair['new'].rstrip('\r')})
        entry, record = gaps.get('entry', {}), gaps.get('record', {})
        kept = out['entries'].get(str(i), {})
        if not os.path.exists(tense_path) and kept.get('tense'):
            # The past tense was exported from a folder that no longer has its ops-tense.json: keep it, never drop it.
            tense = kept['tense']
        fixes = [{'id': f['id'], 'old': f['old'].rstrip('\r'), 'new': f['new'].rstrip('\r'), 'note': f.get('note', '')}
                 for f in load_fixes(folder)] or (kept.get('fixes', []) if not os.path.exists(os.path.join(folder, 'ops-fix.json')) else [])
        out['entries'][str(i)] = {
            'program': entry.get('program_name', ''),
            'place': entry.get('place', ''),
            'years': entry.get('years', ''),
            'column': entry.get('markdown_field', ''),
            'base_sha1': hashlib.sha1(base.encode('utf-8')).hexdigest(),
            'record': {'id': record.get('id'), 'name': record.get('name', ''), 'status': record.get('status', '')},
            'ops': [{k: op[k] for k in OP_KEYS if k in op} for op in templated(base, ops, entry_name(gaps)) if op.get('verdict') != 'dropped'],
            'tense': tense,
            'fixes': fixes,
            'conflicts': [{'text': g.get('text', ''), 'source_label': g.get('source_label', ''), 'source_url': g.get('source_url', '')}
                          for g in gaps.get('gaps', []) if g.get('conflict')],
        }
        done += 1
    out['entries'] = dict(sorted(out['entries'].items(), key=lambda kv: int(kv[0])))
    with open(EXPORT, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
        f.write('\n')
    print(f'{done} drafts exported, {len(out["entries"])} in {os.path.relpath(EXPORT, ROOT)}')
    return True


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == 'selftest':
        sys.exit(0 if selftest() else 1)
    if len(sys.argv) >= 2 and sys.argv[1] == 'export':
        ids = [int(x) for x in sys.argv[2:]] or sorted(int(d) for d in os.listdir(DRAFTS) if d.isdigit())
        sys.exit(0 if export(ids) else 1)
    if len(sys.argv) < 2 or sys.argv[1] not in ('assemble', 'check'):
        print(__doc__)
        sys.exit(2)
    ids = [int(x) for x in sys.argv[2:]] or sorted(int(d) for d in os.listdir(DRAFTS) if d.isdigit())
    sys.exit(0 if run(ids, sys.argv[1] == 'assemble') else 1)


if __name__ == '__main__':
    main()
