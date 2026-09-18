# History section seeds

The pages under `/history/` (the hub, two index pages, ten timelines, and the
prose overview) were reformatted on 2026-09-17. The reformatted content lives
in `seeds/history/<slug>.html` with a `<slug>.json` spec beside it, and the
seed loader in `inc/admin.php` (`kop_apply_seed_posts`) writes it to the
published page on the next deploy.

## How the seeds reach production

Each spec carries `allow_published: true` and the `last_seed_modified_gmt`
the page had in the prod mirror when the seed was assembled. The loader only
overwrites a published page while its `post_modified_gmt` still equals that
stamp. If someone edits a page in wp-admin before the deploy lands, that page
is skipped and keeps the editor's version; refresh the stamp in the JSON (from
`tmp/prod.sqlite` or wp-admin) and bump `seed_version` to apply it later.

After a successful apply the page's `post_modified` is the deploy time, and
`_kop_seed_modified` records it, so a later `seed_version` bump can refresh
the page again as long as nobody has edited it in between.

## Regenerating the timelines

`normalize.py` turns the raw Gutenberg HTML of a timeline page into the
house format; `fixups.py` holds the per-page edits that the generic rules
cannot express. To rerun:

1. Dump each page's `post_content` from `tmp/prod.sqlite` into
   `scripts/history/raw/<id>-<slug>.html` (see `PAGES` in `normalize.py`).
2. `python scripts/history/normalize.py [slug ...]`
3. Review the `<slug>.outline.txt` files written next to the dumps.

Six pages are written by hand and are not generated: `history.html`,
`early-child-control.html`, `birth-of-the-tti.html`, `antiquity.html`,
`medieval-child-oblation-and-monastic-schools.html`, and
`tti-history-part-one.html`.

## House format for timelines

- Every entry is `<strong>date</strong> – event`. Only the date is bold.
  Ranges use an en dash (`1730s–1790s`); full dates read `July 19, 1983`.
- Detail goes in a nested list under the entry, never after a line break.
- An entry without a date is a sub-bullet of the entry before it.
- Pages without their own headings get era headings (`Before 1500`,
  `1500 to 1799`, `1800s`, `1900 to 1949`, `1950 to 1979`,
  `1980s and 1990s`, `2000 to today`) so the article template's contents
  box works.
- References sit under a `Sources` heading as one list, with no separator.
- Links to the site are relative (`/slug/`), never `kidsoverprofits.org/staging/`.

## Editorial changes made on 2026-09-17

Formatting only, on every page: stray line breaks, empty paragraphs,
non-breaking spaces, split bold runs, and staging URLs removed; the
`/medieval-child-oblation` link on Early Systems of Child Control fixed to the
real slug; excerpts written for every page (the article template shows them
as a standfirst).

Content changes, listed so they can be reverted:

- **Juvenile Justice Timeline**: removed "1930 – Federal Bureau of Prisons
  Established" (adult federal prisons, no youth link on the page). Fixed
  "TruCore" to "TrueCore". "Prototype: Massachusetts State Reform School at
  Westborough (1848)" is now a dated entry.
- **Temperance and the War on Drugs**: removed "1951 – The Durham-Humphrey
  Amendment" and "1984 – The Analogue (Designer Drug) Act" (prescription and
  designer-drug regulation with no connection to youth treatment). The Nancy
  Reagan tour table is now a sub-list under the 1982 entry.
- **Corporatization**: the Straight, Inc. closures of July 1993 were nested
  under a Three Springs entry; they are top-level entries again. Sources were
  paragraphs and are now a list.
- **Experimental Group Psychology**: the 1971 Senate Subcommittee entry was
  buried inside the Elan School entry; it is its own entry.
- **Fundamentalist Christian Homes**: the Ralph Reed quotation about Bush's
  2000 campaign was attached to the 1992 Mississippi Supreme Court entry; it
  now sits under the 1998 TACCCA entry. "1987 girls from Rebekah Home" is a
  dated entry rather than a sub-bullet of 1986.
- **Precursors in Antiquity**: rebuilt as one column with headings per
  institution and a short intro; BC/AD dates standardized to BCE/CE; image
  alt text added. No facts changed.
- **Medieval Child Oblation**: the page was two side-by-side columns whose
  content interleaved out of order; rebuilt as one chronological column with
  headings, a short intro, two pull quotes, and a closing link to the later
  Magdalene material. No facts changed.
- **History hub**: rewritten around headings; links to the new network map
  page as well as the Miro board; links the prose overview, which nothing
  linked before.
- **Early Systems of Child Control** and **Birth of the TTI**: were bare link
  lists; now a short intro and one line per timeline.
- **TTI history part One**: retitled "How the Modern TTI Took Shape, 1919 to
  1969"; headings added; nested empty column blocks removed; the program list
  sorted by year with the duplicate "Cam Huntington" dropped; sources as a
  list. Text unchanged.
- **Draft "TTI History Part 2"** (post 383) is a four-paragraph stub ending
  "More coming soon"; left as a draft, untouched.

Flagged but not changed (dates the editor should confirm):

- Juvenile Justice and War on Drugs both date "Nixon Declares War on Drugs"
  to 1968; the declaration was June 1971 (1968 was the campaign).
- War on Drugs puts "public enemy number one" under 1970; that phrase is from
  the June 1971 press conference.
- Experimental Group Psychology dates the Highfields experiment 1961–63;
  the prose overview and Chatfield date it to 1950.
- Fundamentalist Christian Homes: "Child Residential Home Notification Act
  passes in MS" has no year (it sits between 1988 and 1990).
