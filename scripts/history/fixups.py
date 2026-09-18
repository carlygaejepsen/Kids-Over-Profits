"""Per-page fixups for normalize.py.

PRE rules run on the raw post_content before the generic transform; POST
rules run on the normalized output. A rule is either (regex, replacement)
or a callable(text) -> text. Every content cut made here is listed in the
review notes so an editor can put it back.
"""
import re

ITEM_OPEN = '<!-- wp:list-item -->'
ITEM_CLOSE = '<!-- /wp:list-item -->'


def _find_item(s, text):
    """Span of the whole list-item block whose <li> body starts with text."""
    needle = ITEM_OPEN + '\n<li>' + text
    i = s.find(needle)
    if i == -1:
        raise ValueError('item not found: ' + text[:60])
    depth = 0
    for m in re.finditer(r'<!-- (/?)wp:list-item -->', s[i:]):
        if not m.group(1):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                end = i + m.end()
                return i, end
    raise ValueError('unterminated item: ' + text[:60])


def _strip_item_from_list(s, start, end):
    """Remove an item; if its parent list becomes empty, remove the list too."""
    before, item, after = s[:start], s[start:end], s[end:]
    before = before.rstrip('\n')
    after = after.lstrip('\n')
    if before.endswith('<ul class="wp-block-list">') and after.startswith('</ul>'):
        # only child: drop the whole nested list wrapper
        before = before[: before.rfind('<!-- wp:list')].rstrip('\n')
        after = after[after.find('<!-- /wp:list -->') + len('<!-- /wp:list -->'):].lstrip('\n')
        return before + after, item
    if before.endswith('<ul class="wp-block-list">'):
        return before + after, item
    return before + '\n\n' + after, item


def remove_item(text):
    def f(s):
        start, end = _find_item(s, text)
        s, _ = _strip_item_from_list(s, start, end)
        return s
    return f


def move_item_after(text, target):
    """Cut the item starting with text and paste it as a sibling after the
    top-level item starting with target."""
    def f(s):
        start, end = _find_item(s, text)
        s, item = _strip_item_from_list(s, start, end)
        tstart, tend = _find_item(s, target)
        return s[:tend] + '\n\n' + item + s[tend:]
    return f


def nest_item_under(text, target):
    """Cut the item starting with text and append it as the last sub-bullet
    of the item starting with target."""
    def f(s):
        start, end = _find_item(s, text)
        s, item = _strip_item_from_list(s, start, end)
        tstart, tend = _find_item(s, target)
        block = s[tstart:tend]
        if '<!-- wp:list -->' in block:
            k = block.rfind('</ul>\n<!-- /wp:list --></li>')
            block = block[:k] + '\n\n' + item + block[k:]
        else:
            k = block.rfind('</li>')
            block = block[:k] + '<!-- wp:list -->\n<ul class="wp-block-list">' + item + '</ul>\n<!-- /wp:list --></li>' + block[k + 5:]
        return s[:tstart] + block + s[tend:]
    return f


REAGAN_TOUR = '''<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Program visits on the tour included:<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>February 16, 1982 – Straight, Inc.</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>May 10, 1982 – Gateway House Drug Program</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>August 5, 1982 – Palmer Drug Abuse Program</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>May 4, 1983 – Phoenix House</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>August 11, 1983 – CENIKOR</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>August 23, 1983 – John Tracy Clinic</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>September 17, 1984 – Straight, Inc.</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>July 25, 1985 – Ohio anti-drug program trip</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>August 8, 1985 – Massachusetts anti-drug program trip</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>September 17, 1986 – Phoenix House</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>September 25, 1986 – Pennsylvania Chemical People anti-drug program</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>October 1, 1986 – Missouri anti-drug program event</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>November 6, 1986 – Charles A. Dana Foundation event, New York</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>November 25, 1986 – Boys Town, Nebraska</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>March 24, 1987 – TPC Village</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>May 19, 1987 – Teen Challenge of Tennessee, Chattanooga</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->'''

PRE = {
    'corporatization': [
        (r'<p>Works Cited:<br>', '<p>Works Cited:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>'),
        (r'July 1-2, 1993: ', 'July 1–2, 1993 – '),
        (r'July 2-3, 1993: ', 'July 2–3, 1993 – '),
        (r'April 24-25, 1993: ', 'April 24–25, 1993 – '),
        (r'operation”\.', 'operation."'),
        (r'Late 2002 – Early 2003: ', 'Late 2002 to early 2003 – '),
    ],
    'juvenile-justice-timeline': [
        (r'Published</strong> <strong><em>On Crimes and Punishments</em></strong><strong><em><br></em></strong>', 'publishes <em>On Crimes and Punishments</em><br>'),
        (r'juvenile justice ideals<br>\.', 'juvenile justice ideals.'),
        (r'Prototype: Massachusetts State Reform School at Westborough \(1848\)', '1848 – Massachusetts State Reform School at Westborough'),
        (r'TruCore', 'TrueCore'),
    ],
    'fundamentalist': [
        (r'<strong>1987 girls from Rebekah Home', '<strong>1987 – Girls from Rebekah Home'),
        (r'<strong>1986 George W and Jeb Bush', '<strong>1986 – George W. and Jeb Bush'),
    ],
    'war-on-drugs': [
        (r'<!-- wp:table \{"hasFixedLayout":false,"className":"is-style-stripes"\} -->.*?<!-- /wp:table -->', REAGAN_TOUR),
    ],
    'experimental-group-psychology': [
        (r'1971 -- Werner', '1971 – Werner'),
    ],
    'advocacy-history': [
        (r'2007 – October 10, Congress', 'October 10, 2007 – Congress'),
    ],
}

POST = {
    'corporatization': [
        move_item_after('<strong>July 1–2, 1993</strong>', '<strong>June 1993</strong> – Three Springs announces'),
        move_item_after('<strong>July 2–3, 1993</strong>', '<strong>July 1–2, 1993</strong>'),
    ],
    'juvenile-justice-timeline': [
        # Federal prison administration with no youth connection on the page.
        remove_item('<strong>1930</strong> – Federal Bureau of Prisons Established'),
    ],
    'war-on-drugs': [
        # Prescription and designer-drug regulation with no link to youth treatment.
        remove_item('<strong>1951</strong> – The Durham-Humphrey Amendment'),
        remove_item('<strong>1984</strong> – The Analogue (Designer Drug) Act'),
    ],
    'experimental-group-psychology': [
        move_item_after('<strong>1971</strong> – Senate Subcommittee on Constitutional Rights', '<strong>1971</strong> – Elan School is founded'),
    ],
    'fundamentalist': [
        nest_item_under('“In the eyes of [W] Bush’s campaign strategist', '<strong>1998</strong> – Texas Association of Christian Child Care Agencies'),
    ],
}
