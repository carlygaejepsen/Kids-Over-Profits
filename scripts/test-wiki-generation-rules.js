// The wiki editor (js/wiki-generation.js) follows the rules the wiki update drafts settled on (scripts/wiki-drafts.py):
// no space before punctuation after a link (normalizePunctSpacing() == punct_spacing() on every line of every draft and
// entry in tmp/wiki-updates/drafts/, when present), staff role sentences ("a therapist", "the Executive Director",
// "worked in admissions", "from 2008 to 2010", no "Former"), closed programs' staff in the past, one paragraph per person.
//     node scripts/test-wiki-generation-rules.js
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const g = require('../js/wiki-generation.js');

let bad = 0;
const eq = (got, want, what) => {
    if (got !== want) {
        bad++;
        console.log(`FAIL ${what}\n  got:  ${got}\n  want: ${want}`);
    }
};

// 1. Spacing: fixed cases, then parity with the Python on real text.
eq(g.normalizePunctSpacing('[Name](https://x.org/a) , which was'), '[Name](https://x.org/a), which was', 'comma after a link');
eq(g.normalizePunctSpacing('([source](https://x.org/a) ).'), '([source](https://x.org/a)).', 'bracket after a link');
eq(g.normalizePunctSpacing('[A](https://x.org) (FOX 13 News, )'), '[A](https://x.org) (FOX 13 News)', 'empty date');
eq(g.normalizePunctSpacing('"GET ME OUT OF HERE !" she wrote'), '"GET ME OUT OF HERE !" she wrote', 'a survivor\'s "HERE !" stays');
eq(g.normalizePunctSpacing('see [x](https://x.org) : the list'), 'see [x](https://x.org): the list', 'colon after a link');

const root = path.join(__dirname, '..');
const drafts = path.join(root, 'tmp', 'wiki-updates', 'drafts');
if (fs.existsSync(drafts)) {
    const lines = [];
    for (const d of fs.readdirSync(drafts)) {
        for (const f of ['entry.md', 'draft.md']) {
            const p = path.join(drafts, d, f);
            if (fs.existsSync(p)) lines.push(...fs.readFileSync(p, 'utf8').replace(/\r\n/g, '\n').split('\n'));
        }
    }
    const tmp = path.join(os.tmpdir(), `kop-punct-${process.pid}.txt`);
    fs.writeFileSync(tmp, lines.join('\n'), 'utf8');
    const py = "import importlib.util,sys\n" +
        "s=importlib.util.spec_from_file_location('wd',sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)\n" +
        "sys.stdout.reconfigure(encoding='utf-8',newline='\\n')\n" +
        "print('\\n'.join(m.punct_spacing(l) for l in open(sys.argv[2],encoding='utf-8').read().split('\\n')),end='')";
    let pyOut = null;
    for (const exe of ['python', 'py', 'python3']) {
        try { pyOut = execFileSync(exe, ['-c', py, path.join(root, 'scripts', 'wiki-drafts.py'), tmp], { encoding: 'utf8', maxBuffer: 1 << 28 }); break; } catch (e) { /* next */ }
    }
    fs.unlinkSync(tmp);
    if (pyOut === null) {
        console.log('python not found: spacing parity skipped');
    } else {
        const want = pyOut.split('\n');
        let diff = 0;
        lines.forEach((l, i) => {
            if (g.normalizePunctSpacing(l) !== want[i]) {
                if (diff++ < 5) console.log(`FAIL spacing parity, line ${i}: ${l.slice(0, 120)}`);
            }
        });
        bad += diff;
        console.log(`spacing: ${lines.length} lines, JS == Python on ${lines.length - diff}`);
    }
}

// 2. Staff role sentences.
const cases = [
    ['Therapist', false, 'is a therapist'],
    ['Therapist', true, 'was a therapist'],
    ['Executive Director', true, 'was the Executive Director'],
    ['Former Clinical Director', true, 'was the Clinical Director'],
    ['Assistant Director', true, 'was an Assistant Director'],
    ['exec director', true, 'was the executive director'],
    ['asst principal', true, 'was an assistant principal'],
    ['Admissions', true, 'worked in admissions'],
    ['Admissions & Marketing', true, 'worked in admissions and marketing'],
    ['Staff', true, 'was a staff member'],
    ['Teacher (2007)', true, 'was a teacher'],
    ['Program Director (2008-2010)', true, 'was the Program Director from 2008 to 2010'],
    ['Headmaster in 2008', true, 'was the Headmaster'],
    ['RN', true, 'was an RN'],
    ['Adventure therapy coordinator', true, 'was an adventure therapy coordinator'],
    ['one of the founders', true, 'was one of the founders'],
];
cases.forEach(([role, past, want]) => eq(g.staffRoleClause(role, past), want, `role "${role}"`));
eq(g.isClosedYears('1994-2005/2010'), true, 'closed years');
eq(g.isClosedYears('1971-present'), false, 'open years');

// 3. One paragraph per person, and the whole page.
const merged = g.mergeStaffByName([
    { name: 'Rabbi Michele Medwin', role: 'Director of Jewish Studies' },
    { name: 'Michele Medwin', role: 'Spiritual Advisor' },
    { name: 'Tom Kovaleski', role: 'Choreographer' },
]);
eq(merged.length, 2, 'two people from three entries');
const md = g.generateWikiMarkdown({
    programName: 'Test Academy', yearsActive: '1994-2010', cityState: 'Provo, UT', programType: 'Residential Treatment Center',
    staffMembers: [
        { name: 'Jane Roe', role: 'Therapist' },
        { name: 'Jane Roe', role: 'Admissions' },
        { name: 'John Doe', role: 'Former exec director (2003-2006)' },
    ],
    relatedMedia: [{ title: 'An article', url: 'https://example.org/a', source: 'FOX 13 News' }],
});
const has = (s, what) => { if (!md.includes(s)) { bad++; console.log(`FAIL page: ${what}: no "${s}"`); } };
has('**Jane Roe** was a therapist. Roe also worked in admissions.', 'merged person, closed program');
has('**John Doe** was the executive director from 2003 to 2006.', 'role with a span');
if ((md.match(/\*\*Jane Roe\*\*/g) || []).length !== 1) { bad++; console.log('FAIL page: Jane Roe printed twice'); }

// 4. One person, many names: the site's data (tmp/name-variants.json from scripts/test-name-variants.php) when
// present, else the files alone (nicknames + the reviewed names).
const variantsFile = path.join(root, 'tmp', 'name-variants.json');
if (fs.existsSync(variantsFile)) g.setNameVariants(JSON.parse(fs.readFileSync(variantsFile, 'utf8')));
const same = (a, b, want) => eq(g.samePerson(a, b), want, `samePerson("${a}", "${b}")`);
const maybe = (a, b, want) => eq(Boolean(g.possibleSamePerson(a, b)), want, `possibleSamePerson("${a}", "${b}")`);
same('Charlie Smith', 'Charles Smith', true);        // a nickname that is only Charles
same('Chuck Dederich', 'Charles Dederich', true);
same('Bill Jones', 'William R. Jones', true);
same('Dr. Ken Huey', 'Ken Huey', true);
same('Tom Kovalesky', 'Tom Kovaleski', true);         // reviewed
same('Jerry Spanos', 'Gerald Spanos', true);
same('Nale Fakahua', 'Salesi Misinale Fakahua', true);
same('Oscar Fakahua', 'Nale Fakahua', false);          // two people (owner)
maybe('Oscar Fakahua', 'Nale Fakahua', false);
same('Sam Jones', 'Samuel Jones', false);              // Sam may be Samantha: asked, not merged
maybe('Sam Jones', 'Samuel Jones', true);
maybe('Kathy Lee', 'Katherine Lee', true);
maybe('Paul Ravenscraft', 'Paul Ravenscroft', true);   // one letter apart
maybe('Adele Logan', 'Adele Logan Smith', true);       // maiden or married name
maybe('Roe Jane', 'Jane Roe', true);                   // swapped
maybe('Jane Roe', 'John Roe', false);
maybe('Jane Roe', 'Mary Smith', false);
if (fs.existsSync(variantsFile)) {
    same('Robert Christ', 'Robert H. Crist', true);   // the people table's other names
    same('Kris Archer', 'Kristen Archer', true);      // a Merge People merge
}
eq(JSON.stringify(g.staffNamesInText('**Jane Roe** was a therapist.\n\n** Charlie Smith** was a teacher.\n**Orientation:** none')), '["Jane Roe","Charlie Smith"]', 'names in staff text');
const found = g.findStaffNameMatches(['Charlie Smith', 'Charles Smith', 'Sam Jones', 'Samuel Jones', 'Jane Roe']);
eq(found.map(m => m.kind).join(','), 'same,maybe', 'the editor\'s check: one known, one to ask');
const page = g.generateWikiMarkdown({
    programName: 'Test Academy', yearsActive: '1994-2010', cityState: 'Provo, UT', programType: 'Residential Treatment Center',
    staffMembers: [{ name: 'Charlie Smith', role: 'Therapist' }, { name: 'Charles Smith', role: 'Program Director' }, { name: 'Sam Jones', role: 'Teacher' }, { name: 'Samuel Jones', role: 'Teacher' }],
});
eq((page.match(/\*\*Charl(?:ie|es) Smith\*\*/g) || []).length, 1, 'Charlie and Charles Smith are one paragraph');
eq((page.match(/\*\*Sam(?:uel)? Jones\*\*/g) || []).length, 2, 'Sam and Samuel Jones stay two until someone says');

console.log(bad ? `${bad} failed` : 'all passed');
process.exit(bad ? 1 : 0);
