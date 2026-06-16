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

    return changes;
}

const hasIdentificationFields = node =>
    node && typeof node === 'object' && !Array.isArray(node) &&
    ('currentOperator' in node || 'currentOwner' in node ||
     'currentOwners' in node || 'currentName' in node);

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
