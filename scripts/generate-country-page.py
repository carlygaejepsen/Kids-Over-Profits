"""Regenerate js/country-page.js from js/state-page.js (see the header in country-page.js).

Usage: python scripts/generate-country-page.py
"""
import io
import os
R = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..') + os.sep
src = io.open(R + 'js/state-page.js', encoding='utf-8', newline='').read()
crlf = '\r\n' in src
s = src.replace('\r\n', '\n')
def rep(old, new, count=1):
    global s
    assert s.count(old) == count, (s.count(old), old[:80])
    s = s.replace(old, new)
rep("    const config = window.statePageConfig;\n",
"""    const config = window.countryPageConfig;
    // Compat shim: this file is generated from state-page.js (see the header
    // comment) and its helpers read config.stateName / config.stateSlug.
    // Mirror the country fields so the shared code needs no changes.
    if (config) {
        if (!config.stateName && config.countryName) config.stateName = config.countryName;
        if (!config.stateSlug && config.countrySlug) config.stateSlug = config.countrySlug;
    }
""")
rep("No facilities on file for this state yet.", "No facilities on file for this country yet.")
rep("No news items tagged for this state.", "No news items tagged for this country.")
rep("No lawsuits on record for this state yet.", "No lawsuits on record for this country yet.")
rep("No legislation on record for this state yet.", "No legislation on record for this country yet.")
rep("prefill_state=", "prefill_country=")
rep('data-kop-bug-feature="state-page/facility-card"', 'data-kop-bug-feature="country-page/facility-card"')
header = """/**
 * Country hub page script.
 *
 * GENERATED from js/state-page.js: the country REST route returns the same
 * facility/news/lawsuit/legislation shape as the state route, so the two pages
 * share one renderer. Edit state-page.js, then regenerate this file with the
 * substitution list in scripts/generate-country-page.py. Do not hand-edit.
 */
"""
s = header + s
if crlf: s = s.replace('\n', '\r\n')
io.open(R + 'js/country-page.js', 'w', encoding='utf-8', newline='').write(s)
print('country-page.js regenerated')
