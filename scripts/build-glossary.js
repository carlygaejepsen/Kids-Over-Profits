#!/usr/bin/env node
/**
 * Build Glossary
 *
 * Turns js/data/glossary/glossary.md into the glossary.json the /glossary/
 * page reads.
 *
 * Architecture:
 * - js/data/glossary/glossary.md   = source of truth, edited by hand
 * - js/data/glossary/glossary.json = build output, committed
 *
 * The markdown is deliberately plain so it can be pasted to and from a doc:
 *
 *   ## Section            (Treatment Modalities, Shared Terms A-Z, ...)
 *   ### Group             (a program or a family of programs)
 *   #### Subgroup         (a program inside a family)
 *   *Sources: ...*        a group's documents, shown under its heading
 *   **Term** *(aka X, Y)*: definition. Used at: *A, B*; reportedly used at: *C*
 *
 * Any other paragraph is a note shown where it stands. In a definition,
 * **Other Term** is a cross-reference and must name an entry (its term, one
 * half of a "A / B" term, an aka, or "Term (qualifier)" for a term used in
 * two senses); a reference the build cannot resolve fails the build, so a
 * renamed entry cannot leave a dead link behind.
 *
 * Usage: node scripts/build-glossary.js
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'js', 'data', 'glossary');
const SOURCE_FILE = path.join(DATA_DIR, 'glossary.md');
const OUTPUT_FILE = path.join(DATA_DIR, 'glossary.json');

/* Program names that contain a comma, so the tag list cannot be split on
 * every comma. */
const COMMA_NAMES = ['Straight, Inc.'];

function slugify(text) {
    return text
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/\+/g, ' plus ')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** Sort key: case, punctuation and a leading "The" do not count. */
function sortKey(term) {
    return term.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/^the\s+/, '').replace(/[^a-z0-9 ]/g, '');
}

/** Split a comma list, ignoring commas inside parentheses. */
function splitList(text) {
    let masked = text;
    COMMA_NAMES.forEach((name, i) => {
        masked = masked.split(name).join('\u0000' + i + '\u0000');
    });
    const parts = [];
    let depth = 0;
    let current = '';
    for (const ch of masked) {
        if (ch === '(') depth++;
        if (ch === ')') depth--;
        if (ch === ',' && depth === 0) {
            parts.push(current);
            current = '';
        } else {
            current += ch;
        }
    }
    parts.push(current);
    return parts
        .map(p => p.replace(/\u0000(\d+)\u0000/g, (_, i) => COMMA_NAMES[Number(i)]).trim())
        .filter(Boolean);
}

/* Program names that end in parentheses of their own. */
const PAREN_NAMES = ['Bloom (Adult & Teen Challenge)', 'Kids Helping Kids (KHK)'];

/**
 * 'Spring Ridge Academy (also as "vicinity visit")' -> program + note. Any
 * trailing parenthetical is a note on how that program used the term (its
 * own name for it, the part of the program it applied to).
 */
function parseTag(text) {
    let name = text.trim();
    let rest = '';
    const own = PAREN_NAMES.find(n => name.startsWith(n));
    if (own) {
        rest = name.slice(own.length).trim();
        name = own;
    } else {
        const m = name.match(/^(.*?)\s*(\(.*\))$/);
        if (m) {
            name = m[1];
            rest = m[2];
        }
    }
    const tag = { program: name.trim() };
    const note = rest.replace(/^\(|\)$/g, '').trim();
    if (note) tag.note = note;
    return tag;
}

/* "Used at: *A, B*", "Reportedly used at: *C*" or both, at the end. */
const TAGS_RE = /\s*(?:Used at: \*([^*]+)\*)?(?:;?\s*[Rr]eportedly used at: \*([^*]+)\*)?\s*$/;

function parseEntry(para) {
    const head = para.match(/^\*\*(.+?)\*\*(?: \*\(([^)]*(?:\([^)]*\)[^)]*)*)\)\*)?:\s*/);
    if (!head) {
        return null;
    }
    const term = head[1].trim();
    let note = head[2] ? head[2].trim() : '';
    let aka = [];
    if (/^aka /i.test(note)) {
        const quoted = note.slice(4).match(/"[^"]+"/g);
        aka = quoted
            ? quoted.map(q => q.slice(1, -1).replace(/,$/, ''))   /* aka "a," "b," "c" */
            : splitList(note.slice(4));
        note = '';
    }
    let text = para.slice(head[0].length);
    const tags = text.match(TAGS_RE);
    let used = [];
    let reported = [];
    if (tags && (tags[1] || tags[2])) {
        used = tags[1] ? splitList(tags[1]).map(parseTag) : [];
        reported = tags[2] ? splitList(tags[2]).map(parseTag) : [];
        text = text.slice(0, tags.index).trim();
    }
    /* The source writes definitions dictionary-style ("**Drop**: being
     * demoted..."); on the page each one starts its own line. */
    text = text.trim().replace(/^[a-z]/, c => c.toUpperCase());
    return { term, note, aka, text, used, reported };
}

function parse(source) {
    const paras = source.replace(/\r\n/g, '\n').split(/\n{2,}/).map(p => p.trim()).filter(Boolean);
    const doc = { title: '', updated: '', intro: [], sections: [] };
    let section = null;
    let group = null;
    let target = null;          // where entries and notes go

    for (const para of paras) {
        let m;
        if ((m = para.match(/^# (.+)$/))) {
            doc.title = m[1].trim();
            continue;
        }
        if ((m = para.match(/^updated: (\d{4}-\d{2}-\d{2})$/))) {
            doc.updated = m[1];
            continue;
        }
        if ((m = para.match(/^## (.+)$/))) {
            section = { title: m[1].trim(), id: slugify(m[1]), notes: [], entries: [], groups: [] };
            doc.sections.push(section);
            group = null;
            target = section;
            continue;
        }
        if ((m = para.match(/^(###|####) (.+)$/))) {
            if (!section) throw new Error('Group before any section: ' + para);
            const node = { title: m[2].trim(), id: slugify(m[2]), sources: '', notes: [], entries: [], groups: [] };
            if (m[1] === '###') {
                section.groups.push(node);
                group = node;
            } else {
                if (!group) throw new Error('#### without a ### above it: ' + para);
                group.groups.push(node);
            }
            target = node;
            continue;
        }
        if (!section) {
            doc.intro.push(para);
            continue;
        }
        if ((m = para.match(/^\*Sources?: (.+)\*$/)) && target !== section) {
            target.sources = m[1].trim();
            continue;
        }
        const entry = parseEntry(para);
        if (entry) {
            target.entries.push(entry);
        } else {
            target.notes.push(para);
        }
    }
    return doc;
}

/** Every entry in reading order, with the section and group it sits in. */
function allEntries(doc) {
    const out = [];
    const walk = (node, sectionId, groupTitle) => {
        node.entries.forEach(e => out.push({ entry: e, sectionId, groupTitle }));
        node.groups.forEach(g => walk(g, sectionId, g.title));
    };
    doc.sections.forEach(s => walk(s, s.id, ''));
    return out;
}

function build() {
    const doc = parse(fs.readFileSync(SOURCE_FILE, 'utf8'));
    const errors = [];
    const entries = allEntries(doc);

    /* Ids: the term, plus the qualifier when two entries share a term. */
    const counts = {};
    entries.forEach(({ entry }) => {
        const k = slugify(entry.term);
        counts[k] = (counts[k] || 0) + 1;
    });
    const used = new Set();
    entries.forEach(({ entry, groupTitle }) => {
        let id = slugify(entry.term);
        if (counts[id] > 1) {
            id = slugify(entry.term + ' ' + (entry.note || groupTitle));
        }
        if (!id || used.has(id)) {
            errors.push(`Duplicate or empty id "${id}" for "${entry.term}"`);
        }
        used.add(id);
        entry.id = id;
    });

    /* What a **cross-reference** may be called. */
    const index = new Map();
    const add = (name, entry) => {
        const k = name.toLowerCase().trim();
        if (!k) return;
        if (!index.has(k)) index.set(k, []);
        if (!index.get(k).includes(entry)) index.get(k).push(entry);
    };
    entries.forEach(({ entry }) => {
        const bare = entry.term.replace(/\s*\([^)]*\)\s*$/, '');
        add(entry.term, entry);
        add(bare, entry);
        entry.term.split(/\s*\/\s*/).forEach(part => add(part, entry));
        entry.aka.forEach(a => add(a, entry));
        if (entry.note) add(`${entry.term} (${entry.note})`, entry);
    });
    const resolve = (name) => {
        const k = name.toLowerCase().trim();
        const hits = index.get(k) || index.get(k.replace(/s$/, '')) || [];
        /* A term's own name beats an entry that only lists it as an aka. */
        const exact = hits.filter(e => e.term.toLowerCase().replace(/\s*\([^)]*\)\s*$/, '') === k);
        return exact.length === 1 ? exact : hits;
    };

    /* Check every reference and record the id it points at. */
    const refs = {};
    const refCheck = (text, where) => {
        for (const m of text.matchAll(/\*\*(.+?)\*\*/g)) {
            const hits = resolve(m[1]);
            if (hits.length === 0) {
                errors.push(`${where}: **${m[1]}** does not name an entry`);
            } else {
                if (hits.length > 1) {
                    errors.push(`${where}: **${m[1]}** is ambiguous (${hits.map(h => h.id).join(', ')}); write it as "Term (qualifier)"`);
                }
                refs[m[1].toLowerCase()] = hits[0].id;
            }
        }
    };
    entries.forEach(({ entry }) => refCheck(entry.text, entry.term));
    const walkNotes = (node) => {
        node.notes.forEach(n => refCheck(n, node.title));
        node.groups.forEach(walkNotes);
    };
    doc.sections.forEach(walkNotes);

    /* Entries sort alphabetically inside every section and group. */
    const sortNode = (node) => {
        node.entries.sort((a, b) => sortKey(a.term).localeCompare(sortKey(b.term)) || a.id.localeCompare(b.id));
        node.groups.forEach(sortNode);
    };
    doc.sections.forEach(sortNode);

    /* Programs: every name a tag carries, with how many entries name it. */
    const programs = {};
    entries.forEach(({ entry }) => {
        const names = new Set([...entry.used, ...entry.reported].map(t => t.program));
        names.forEach(name => {
            const slug = slugify(name);
            if (!programs[slug]) programs[slug] = { slug, name, count: 0 };
            programs[slug].count++;
        });
        [...entry.used, ...entry.reported].forEach(t => { t.slug = slugify(t.program); });
    });

    if (errors.length) {
        console.error(errors.map(e => '  ' + e).join('\n'));
        console.error(`\n${errors.length} error(s); glossary.json not written.`);
        process.exit(1);
    }

    const out = {
        title: doc.title,
        updated: doc.updated,
        intro: doc.intro,
        count: entries.length,
        programs: Object.values(programs).sort((a, b) => sortKey(a.name).localeCompare(sortKey(b.name))),
        refs,
        sections: doc.sections,
    };
    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(out, null, 1) + '\n');
    console.log(`glossary.json: ${entries.length} entries, ${out.programs.length} programs, ${Object.keys(refs).length} cross-references.`);
}

build();
