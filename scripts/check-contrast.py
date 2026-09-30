#!/usr/bin/env python3
"""
Find text and icons that do not meet WCAG AA contrast against the colour
actually behind them.

Kadence colours some elements directly instead of letting them inherit, and it
loads after the child theme's stylesheets, so a panel that sets its own text
colour does not reach everything inside it:

  h1-h6              Kadence #1A202C / #2D3748   -> black on a navy panel
  a                  Kadence #000080 (navy)       -> navy on a navy panel
  button, .button    Kadence white text on its button colour, and
                     button:hover/:focus (0,1,1) beats a one-class rule
                     -> white on a white or sand button on hover or after a click

This measures what the reader gets: every visible text node on the page
(header, content, sidebar, footer, open shadow roots of embedded widgets, with
every <details> opened) against the
background composited from its ancestors, every small SVG icon's fill or
stroke the same way, and then again with :hover and with :focus forced on
buttons and links. Text over an image or gradient is left to check-bare-text.py.

For each failure it asks Chrome which rule set the colour (stylesheet, line,
selector), or says the colour was inherited, so the fix goes in the right place.

Floors (WCAG 2.1 AA): 4.5:1 for text, 3:1 for large text (24px, or 18.66px
bold) and for icons (1.4.11). Disabled controls and separator marks are exempt.

Usage:
  python -u scripts/check-contrast.py                  every template's sample page, live site
  python -u scripts/check-contrast.py glossary/ hyde/  just these paths
  python -u scripts/check-contrast.py --local          serve the working tree's css/ and js/ in place of the live ones
  python -u scripts/check-contrast.py --mobile         390px wide instead of 1360px
  python -u scripts/check-contrast.py --json=tmp/contrast.json   also write every failure as JSON
  python -u scripts/check-contrast.py --local --add-css=css/new.css --add-js=js/new.js
                                                       inject a stylesheet or script the live
                                                       PHP does not enqueue yet (a new file)

Exit 1 when any page has a failure.
"""
import importlib.util
import json
import mimetypes
import os
import re
import sys

from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://kidsoverprofits.org/'

# Same sample pages as the bare-text check (one per child template), plus a
# generated facility page and parent company page.
_spec = importlib.util.spec_from_file_location('bare', os.path.join(ROOT, 'scripts', 'check-bare-text.py'))
_bare = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_bare)
SAMPLES = _bare.SAMPLES + ['facility/cedar-crest-hospital-rtc-tx/', 'operator/acadia-healthcare/', '?s=provo']

INTERACTIVE = 'button, a, summary, [role="button"], input[type="submit"], input[type="button"], .button'

MEASURE = r"""
(onlyInteractive) => {
  const parse = (c) => {
    const m = c && c.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1];
  };
  const over = (top, under) => {
    const a = top[3];
    return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1);
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const rgb = (c) => 'rgb(' + c.slice(0, 3).map(Math.round).join(',') + ')';
  // Parent element, stepping out of a shadow root to its host (embedded widgets).
  const up = (n) => n.parentElement || (n.parentNode && n.parentNode.host) || null;

  // Background behind an element: stack the ancestors' background colours
  // from the nearest opaque one down. An image or gradient on the way makes
  // the answer unknowable here.
  const backgroundOf = (el) => {
    const layers = [];
    for (let n = el; n && n.nodeType === 1; n = up(n)) {
      const s = getComputedStyle(n);
      if (s.backgroundImage !== 'none' && !/^url\(.*\.svg/.test(s.backgroundImage)) return { image: n };
      const c = parse(s.backgroundColor);
      if (c && c[3] > 0) { layers.push(c); if (c[3] >= 1) break; }
    }
    let bg = [255, 255, 255, 1];
    for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
    return { bg };
  };
  const opacityOf = (el) => {
    let o = 1;
    for (let n = el; n && n.nodeType === 1; n = up(n)) o *= +getComputedStyle(n).opacity;
    return o;
  };
  const label = (el) => {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    else if (typeof el.className === 'string' && el.className.trim()) s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
    const p = el.parentElement && el.parentElement.closest('[class]');
    if (p && p !== el && typeof p.className === 'string') s = '.' + p.className.trim().split(/\s+/)[0] + ' ' + s;
    return s;
  };
  const exempt = (el) => {
    if (el.closest('script,style,noscript,[hidden],[aria-hidden="true"]:not(svg)')) return true;
    // The network map draws its own graphic; its colours are data, not UI.
    if (el.closest('.kop-network-stage, .kop-network-canvas, .kop-network svg.kop-network-graph')) return true;
    if (onlyInteractive && !el.closest('[data-kop-forced]')) return true;
    // Disabled controls are exempt (WCAG 1.4.3): greyed-out letters, spent buttons.
    if (el.closest('[aria-disabled="true"]')) return true;
    const ctl = el.closest('a, button, input, select, textarea, [role="button"]');
    if (ctl && (ctl.disabled || ctl.getAttribute('aria-disabled') === 'true' || getComputedStyle(ctl).pointerEvents === 'none')) return true;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return true;
    const s = getComputedStyle(el);
    return s.visibility === 'hidden' || s.display === 'none';
  };

  let nextId = +(document.body.getAttribute('data-kop-cid') || 0);
  const tag = (el) => {
    let id = el.getAttribute('data-kop-cid');
    if (!id) { id = String(++nextId); el.setAttribute('data-kop-cid', id); }
    return id;
  };

  const out = [];
  const push = (el, kind, text, fgCss, fgRaw, large) => {
    const op = opacityOf(el);
    if (op < 0.05) return;  // deliberately invisible (slide-out labels at rest)
    const b = backgroundOf(el);
    if (b.image) return;
    const fg = [fgRaw[0], fgRaw[1], fgRaw[2], fgRaw[3] * op];
    if (fg[3] < 0.05) return;
    const cr = ratio(over(fg, b.bg), b.bg);
    const floor = kind === 'icon' ? 3 : (large ? 3 : 4.5);
    if (cr >= floor) return;
    out.push({ cid: tag(el), kind, where: label(el), text: text.slice(0, 50),
               ratio: Math.round(cr * 100) / 100, floor, fg: fgCss, bg: rgb(b.bg) });
  };

  // The page plus every open shadow root inside it (Givebutter and the like).
  const roots = [document.body];
  for (let i = 0; i < roots.length; i++) {
    for (const e of roots[i].querySelectorAll('*')) if (e.shadowRoot) roots.push(e.shadowRoot);
  }

  // Text
  const seen = new Set();
  for (const root of roots) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = walker.nextNode())) {
    const text = t.textContent.replace(/\s+/g, ' ').trim();
    if (!text) continue;
    if (/^[•·|\/–—»«-]+$/.test(text)) continue;  // separator marks, decoration
    const el = t.parentElement;
    if (!el || seen.has(el) || el.closest('svg')) continue;
    seen.add(el);
    if (exempt(el)) continue;
    const s = getComputedStyle(el);
    const size = parseFloat(s.fontSize), bold = +s.fontWeight >= 700;
    push(el, 'text', text, s.color, parse(s.color), size >= 24 || (bold && size >= 18.66));
  }
  }

  // Icons: small SVGs, judged by the paint of their first drawn shape.
  for (const svg of roots.flatMap(r => [...r.querySelectorAll('svg')])) {
    const r = svg.getBoundingClientRect();
    if (r.width === 0 || r.width > 64 || r.height > 64) continue;
    if (exempt(svg)) continue;
    const shape = svg.querySelector('path, circle, rect, polygon, polyline, line, ellipse, use') || svg;
    const s = getComputedStyle(shape);
    let paint = null, css = null;
    if (s.stroke && s.stroke !== 'none' && parseFloat(s.strokeWidth) > 0 && parse(s.stroke)) { paint = parse(s.stroke); css = s.stroke; }
    else if (s.fill && s.fill !== 'none' && parse(s.fill)) { paint = parse(s.fill); css = s.fill; }
    if (!paint) continue;
    const name = svg.getAttribute('data-icon') || (svg.getAttribute('class') || '').split(/\s+/)[0] || 'svg';
    push(svg, 'icon', '[' + name + ']', css, paint, false);
  }
  document.body.setAttribute('data-kop-cid', String(nextId));
  return out;
}
"""

MARK_REPRESENTATIVES = r"""
(sel) => {
  // Hover and focus colours come from the element's classes and its context,
  // so three of each kind stand for the rest (the glossary has thousands of links).
  const count = new Map();
  let n = 0;
  for (const el of document.body.querySelectorAll(sel)) {
    const p = el.parentElement;
    const key = el.tagName + '|' + (typeof el.className === 'string' ? el.className : '') + '|' +
      (p && typeof p.className === 'string' ? p.className : '');
    const c = count.get(key) || 0;
    if (c >= 3) continue;
    count.set(key, c + 1);
    el.setAttribute('data-kop-forced', '1');
    n++;
  }
  return n;
}
"""

OPEN_DETAILS = r"""
() => { for (const d of document.querySelectorAll('details:not([open])')) d.open = true; }
"""


def local_routes(page):
    """Serve the working tree's theme css/js in place of the live copies."""
    def handler(route, request):
        rel = request.url.split('/themes/child/', 1)[1].split('?', 1)[0]
        path = os.path.join(ROOT, *rel.split('/'))
        if os.path.isfile(path):
            ctype = mimetypes.guess_type(path)[0] or 'text/plain'
            route.fulfill(path=path, content_type=ctype)
        else:
            route.continue_()
    page.route(re.compile(r'/themes/child/.*\.(css|js)(\?|$)'), handler)


def force_state(cdp, state):
    """Force a pseudo-class on the marked representatives (or clear it with [])."""
    doc = cdp.send('DOM.getDocument', {'depth': 0})
    ids = cdp.send('DOM.querySelectorAll', {'nodeId': doc['root']['nodeId'], 'selector': '[data-kop-forced]'})['nodeIds']
    for nid in ids:
        try:
            cdp.send('CSS.forcePseudoState', {'nodeId': nid, 'forcedPseudoClasses': state})
        except Exception:
            pass  # the page re-rendered and dropped this node


def short_sheet(url):
    if not url:
        return 'inline'
    m = re.search(r'/themes/(child|kadence)/(.+?)(\?|$)', url)
    if m:
        return ('' if m.group(1) == 'child' else 'kadence/') + m.group(2)
    m = re.search(r'/plugins/([^/]+)/', url)
    return 'plugin:' + m.group(1) if m else url[-60:]


def color_source(cdp, sheets, cid, prop):
    """Which rule set `prop` on the element: 'file:line selector', or 'inherited'."""
    doc = cdp.send('DOM.getDocument', {'depth': 0})
    nid = cdp.send('DOM.querySelector', {'nodeId': doc['root']['nodeId'], 'selector': f'[data-kop-cid="{cid}"]'})['nodeId']
    if not nid:
        return '?'
    m = cdp.send('CSS.getMatchedStylesForNode', {'nodeId': nid})
    inline = (m.get('inlineStyle') or {}).get('cssProperties', [])
    if any(p['name'] == prop and p.get('value') for p in inline):
        return 'style="" attribute'
    best = None
    for match in m.get('matchedCSSRules', []):  # ascending precedence
        rule = match['rule']
        for p in rule['style'].get('cssProperties', []):
            if p['name'] == prop and p.get('value') and not p.get('disabled'):
                important = p.get('important', False)
                if best is None or important or not best[2]:
                    line = (rule['style'].get('range') or {}).get('startLine')
                    src = sheets.get(rule.get('styleSheetId'), rule.get('origin', ''))
                    best = (f"{short_sheet(src)}:{(line or 0) + 1}", rule['selectorList']['text'][:90], important)
    if best is None:
        return 'inherited' if prop == 'color' else 'default'
    return f"{best[0]}  {best[1]}" + (' !important' if best[2] else '')


def measure_page(page, cdp, sheets, path, groups):
    """Measure at rest, then with :hover and :focus forced; failures into groups."""
    page.evaluate(MARK_REPRESENTATIVES, INTERACTIVE)
    for state in ([], ['hover'], ['focus', 'focus-visible']):
        if state:
            force_state(cdp, state)
            page.wait_for_timeout(700)  # let colour transitions finish
        st = ':' + state[0] if state else ''
        for row in page.evaluate(MEASURE, bool(state)):
            key = (row['where'] + st, row['fg'], row['bg'])
            g = groups.get(key)
            if g is None:
                # Icons paint with currentColor, so for both kinds the rule
                # that matters is the one setting `color`.
                try:
                    src = color_source(cdp, sheets, row['cid'], 'color')
                except Exception:
                    src = '?'
                groups[key] = dict(row, state=st, count=1, source=src, page=path)
            else:
                g['count'] += 1
        if state:
            force_state(cdp, [])
            page.wait_for_timeout(300)


def main():
    args = sys.argv[1:]
    local = '--local' in args
    mobile = '--mobile' in args
    json_out = next((a.split('=', 1)[1] for a in args if a.startswith('--json=')), None)
    add_css = [c for a in args if a.startswith('--add-css=') for c in a.split('=', 1)[1].split(',') if c]
    add_js = [c for a in args if a.startswith('--add-js=') for c in a.split('=', 1)[1].split(',') if c]
    paths = [a for a in args if not a.startswith('--')] or SAMPLES
    width = 390 if mobile else 1360

    failed_pages = 0
    report = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(channel='chrome')
        page = browser.new_page(viewport={'width': width, 'height': 1000})
        if local:
            local_routes(page)
        cdp = page.context.new_cdp_session(page)
        sheets = {}
        cdp.on('CSS.styleSheetAdded', lambda e: sheets.__setitem__(e['header']['styleSheetId'], e['header'].get('sourceURL', '')))
        cdp.send('DOM.enable')
        cdp.send('CSS.enable')

        for path in paths:
            try:
                page.goto(SITE + path, wait_until='domcontentloaded', timeout=90000)
            except Exception as e:
                print(f'SKIP /{path}  ({e.__class__.__name__})')
                continue
            page.wait_for_timeout(2500)
            for css_path in add_css:
                page.add_style_tag(content=open(os.path.join(ROOT, css_path), encoding='utf-8').read())
            for js_path in add_js:
                page.add_script_tag(content=open(os.path.join(ROOT, js_path), encoding='utf-8').read())
            if add_js:
                page.wait_for_timeout(1500)
            page.evaluate(OPEN_DETAILS)
            page.wait_for_timeout(300)

            groups = {}
            try:
                measure_page(page, cdp, sheets, path, groups)
            except Exception as e:
                print(f'ERROR /{path}  ({e.__class__.__name__}: {str(e)[:120]}); keeping what was measured')
            rows = sorted(groups.values(), key=lambda g: g['ratio'])
            report.extend(rows)
            if json_out:  # after every page, so a crash keeps what was measured
                os.makedirs(os.path.dirname(os.path.abspath(json_out)), exist_ok=True)
                with open(json_out, 'w', encoding='utf-8') as f:
                    json.dump(report, f, indent=1)
            failed_pages += 1 if rows else 0
            n = sum(g['count'] for g in rows)
            print(f"{'FAIL' if rows else 'OK  '} /{path}  ({n} below AA in {len(rows)} groups)")
            for g in rows:
                print(f"   {g['ratio']:>5}/{g['floor']}  {g['kind']:<4} {g['where']}{g['state']}  x{g['count']}  \"{g['text']}\"")
                print(f"              {g['fg']} on {g['bg']}   <- {g['source']}")
        browser.close()

    print(f'\n{failed_pages} of {len(paths)} pages have text or icons below WCAG AA.')
    sys.exit(1 if failed_pages else 0)


if __name__ == '__main__':
    main()
