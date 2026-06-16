/**
 * Clean Historical Fields
 *
 * Source data frequently stuffs historical markers ("Previously X", "Formerly Y")
 * into present-tense identification fields (currentOperator / currentOwner /
 * currentOwners / currentName). Rendered verbatim these read ungrammatically
 * ("Operated by: Previously …") and duplicate the former-name copy.
 *
 * This module strips those markers and reclassifies the values into `pastNames`,
 * clearing the present-tense fields. The same transform is reused by the REST
 * database cleanup (scripts/clean-db-historical.js) so the live records and the
 * import sources stay consistent.
 *
 * CLI usage (operates on the files in place):
 *   node scripts/clean-historical-fields.js tmp/location-projects-import.json --dry-run
 *   node scripts/clean-historical-fields.js tmp/location-projects-import.json
 */

const fs = require('fs');

// Mirrors the helpers in js/tti-program-index.js so the display layer and the
// data layer agree on what counts as a "historical" value.
const HISTORICAL_PREFIX_RE = /^\s*(?:previously|formerly|former|prior(?:\s+to)?)\b[\s:;,.\-–—]*/i;
const HISTORICAL_MARKER_RE = /\b(?:previously|former(?:ly)?|prior)\b/i;

const cleanText = value => (typeof value === 'string' ? value.trim() : '');
const isHistoricalText = value => HISTORICAL_MARKER_RE.test(cleanText(value));
const stripHistoricalPrefix = value => cleanText(value).replace(HISTORICAL_PREFIX_RE, '').trim();

// A single field may pack several former names with semicolons, e.g.
// "Previously Camp E-How-Kee; Eckerd Youth Challenge Program".
const splitNames = value =>
    stripHistoricalPrefix(value)
        .split(';')
        .map(part => part.trim())
        .filter(Boolean);

// Mirrors splitNameList in js/tti-program-index.js: some name-list fields store
// several names in one comma/semicolon-joined string. Split into individual
// names, re-attaching bare corporate suffixes ("Foo, Inc.") to the prior name.
const CORP_SUFFIX_RE = /^(?:inc|llc|l\.l\.c|ltd|co|corp|corporation|company|llp|lp|plc|pllc|pc|p\.c|n\.a)\.?$/i;
const splitNameList = value => {
    const out = [];
    cleanText(value).split(/\s*[;,]\s*/).map(part => part.trim()).filter(Boolean).forEach(part => {
        if (out.length && CORP_SUFFIX_RE.test(part)) {
            out[out.length - 1] = `${out[out.length - 1]}, ${part}`;
        } else {
            out.push(part);
        }
    });
    return out;
};

// Split & de-duplicate a name-list array in place. Returns true if it changed.
const normalizeNameArray = (obj, field) => {
    if (!Array.isArray(obj[field])) return false;
    const result = [];
    const seen = new Set();
    let changed = false;
    obj[field].forEach(entry => {
        if (typeof entry !== 'string') {
            result.push(entry);
            return;
        }
        const parts = splitNameList(entry);
        if (parts.length !== 1 || parts[0] !== entry.trim()) changed = true;
        parts.forEach(name => {
            const key = name.toLowerCase();
            if (!seen.has(key)) {
                seen.add(key);
                result.push(name);
            } else {
                changed = true;
            }
        });
    });
    if (changed) obj[field] = result;
    return changed;
};

/**
 * Clean one identification-like object in place. Returns the number of fields
 * that were modified.
 */
function cleanIdentification(obj) {
    if (!obj || typeof obj !== 'object') return 0;

    let changes = 0;
    const collected = [];

    ['currentOperator', 'currentOwner', 'currentName'].forEach(field => {
        if (typeof obj[field] === 'string' && isHistoricalText(obj[field])) {
            splitNames(obj[field]).forEach(name => collected.push(name));
            obj[field] = '';
            changes += 1;
        }
    });

    if (Array.isArray(obj.currentOwners)) {
        const kept = [];
        obj.currentOwners.forEach(entry => {
            if (typeof entry === 'string' && isHistoricalText(entry)) {
                splitNames(entry).forEach(name => collected.push(name));
                changes += 1;
            } else {
                kept.push(entry);
            }
        });
        if (kept.length !== obj.currentOwners.length) obj.currentOwners = kept;
    }

    if (collected.length) {
        if (!Array.isArray(obj.pastNames)) obj.pastNames = [];
        const existing = new Set(obj.pastNames.map(n => String(n).trim().toLowerCase()));
        collected.forEach(name => {
            const key = name.toLowerCase();
            if (name && !existing.has(key)) {
                obj.pastNames.push(name);
                existing.add(key);
            }
        });
    }

    // Normalize name-list fields: split comma/semicolon-joined strings into
    // individual, de-duplicated entries so stored data matches what the card
    // renders (and the Formerly / Also-known-as lines stop overlapping).
    ['otherNames', 'pastNames', 'formerNames'].forEach(field => {
        if (normalizeNameArray(obj, field)) changes += 1;
    });

    return changes;
}

const NAME_LIST_FIELDS = ['otherNames', 'pastNames', 'formerNames'];
const hasIdentificationFields = node =>
    node && typeof node === 'object' && !Array.isArray(node) &&
    ('currentOperator' in node || 'currentOwner' in node ||
     'currentOwners' in node || 'currentName' in node ||
     NAME_LIST_FIELDS.some(field => field in node));

/**
 * Recursively walk any JSON structure and clean every identification-like object.
 * Returns { fields, records } counts.
 */
function cleanTree(node, acc = { fields: 0, records: 0 }) {
    if (Array.isArray(node)) {
        node.forEach(item => cleanTree(item, acc));
        return acc;
    }
    if (node && typeof node === 'object') {
        if (hasIdentificationFields(node)) {
            const changed = cleanIdentification(node);
            if (changed) {
                acc.fields += changed;
                acc.records += 1;
            }
        }
        Object.values(node).forEach(value => cleanTree(value, acc));
    }
    return acc;
}

module.exports = {
    HISTORICAL_PREFIX_RE,
    isHistoricalText,
    stripHistoricalPrefix,
    splitNames,
    splitNameList,
    normalizeNameArray,
    cleanIdentification,
    cleanTree,
};

if (require.main === module) {
    const args = process.argv.slice(2);
    const dryRun = args.includes('--dry-run');
    const files = args.filter(arg => !arg.startsWith('--'));

    if (files.length === 0) {
        console.error('Usage: node scripts/clean-historical-fields.js <file.json> [...] [--dry-run]');
        process.exit(1);
    }

    let totalFields = 0;
    let totalRecords = 0;

    files.forEach(file => {
        const raw = fs.readFileSync(file, 'utf8');
        const data = JSON.parse(raw);
        const { fields, records } = cleanTree(data);
        totalFields += fields;
        totalRecords += records;

        console.log(`${file}: ${records} record(s), ${fields} field(s) ${dryRun ? 'would be' : ''} cleaned`);

        if (fields && !dryRun) {
            // 2-space indent matches how these files were originally serialized,
            // and we preserve the original line endings / trailing newline so only
            // the genuinely changed lines show up in the diff.
            const eol = raw.includes('\r\n') ? '\r\n' : '\n';
            const trailing = /\r?\n$/.test(raw) ? eol : '';
            let out = JSON.stringify(data, null, 2);
            if (eol === '\r\n') out = out.replace(/\n/g, '\r\n');
            fs.writeFileSync(file, out + trailing);
        }
    });

    console.log(`\nTotal: ${totalRecords} record(s), ${totalFields} field(s) ${dryRun ? 'would be' : ''} cleaned`);
}
