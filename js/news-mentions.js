/**
 * Shared helper for normalizing news_submissions.facilities_mentioned values
 * on the JS side.
 *
 * facilities_mentioned is an array that may contain either:
 *   - legacy string entries: "Mountain View Academy"
 *   - new object entries:    { name: "Mountain View Academy", facility_id: 17 }
 *
 * This module returns a uniform list of { name, facility_id } objects.
 *
 * Loaded as a plain script (window.KopNewsMentions) AND exports for ES module
 * consumers.
 */

(function (root) {
    function normalizeFacilityMentions(value) {
        if (typeof value === 'string') {
            try { value = JSON.parse(value); } catch (e) { return []; }
        }
        if (!Array.isArray(value)) return [];

        const out = [];
        const seen = new Map(); // lowercase name -> index in out

        for (const item of value) {
            let name = '';
            let fid = null;
            if (typeof item === 'string') {
                name = item.trim();
            } else if (item && typeof item === 'object') {
                name = String(item.name || '').trim();
                if (item.facility_id !== null && item.facility_id !== undefined && item.facility_id !== '') {
                    const n = parseInt(item.facility_id, 10);
                    if (Number.isFinite(n) && n > 0) fid = n;
                }
            }
            if (!name) continue;

            const key = name.toLowerCase();
            if (seen.has(key)) {
                const existing = out[seen.get(key)];
                if (existing.facility_id == null && fid != null) {
                    existing.facility_id = fid;
                }
                continue;
            }
            seen.set(key, out.length);
            out.push({ name, facility_id: fid });
        }
        return out;
    }

    function facilityMentionNames(value) {
        return normalizeFacilityMentions(value).map(m => m.name);
    }

    const api = { normalizeFacilityMentions, facilityMentionNames };
    if (root) root.KopNewsMentions = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : null);
