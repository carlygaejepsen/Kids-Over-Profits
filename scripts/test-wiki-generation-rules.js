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

console.log(bad ? `${bad} failed` : 'all passed');
process.exit(bad ? 1 : 0);
