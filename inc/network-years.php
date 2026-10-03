<?php
/**
 * Network map years, reviewed in wp-admin (KOP Data Tools > Map Years).
 *
 * The timeline (js/network-map/timeline.js) leaves out every name with no
 * known years, and half the board's places and companies had none. Their
 * years were researched from outside sources, each with a quoted sentence
 * and a link, into js/data/network/years-candidates.json
 * (scripts/build-years-candidates.js). This screen puts every candidate on
 * one page for the owner: the proposed years, editable, the quotes and the
 * sources beside them, and one click to accept or reject. The owner asked
 * for anything they review to work that way (2026-09-29).
 *
 * A decision is saved at once (the option kop_network_years_review) and
 * takes effect at once: accepted years go over graph.json's in the map's
 * config (yearOverrides) and in the facility page slices, the way closures
 * go over its status. Nothing needs rebuilding or committing.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_network_years_candidates')) {
    /** The researched candidates, id => candidate; empty when the file is missing. */
    function kop_network_years_candidates() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $path = trailingslashit(get_stylesheet_directory()) . 'js/data/network/years-candidates.json';
        $list = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        $memo = array();
        foreach (is_array($list) ? $list : array() as $item) {
            if (!empty($item['id'])) $memo[(string) $item['id']] = $item;
        }
        return $memo;
    }
}

if (!function_exists('kop_network_years_decisions')) {
    /** id => array('decision' => accepted|rejected, 'years' => '1971-2004', 'by', 'at'). */
    function kop_network_years_decisions() {
        $saved = get_option('kop_network_years_review', array());
        return is_array($saved) ? $saved : array();
    }
}

if (!function_exists('kop_network_years_format')) {
    /** "1971-2004", "from 1971", "until 2004", "1998", or '' - the four forms the map reads. */
    function kop_network_years_format($start, $end) {
        $a = (int) $start;
        $b = (int) $end;
        if ($a && $b) return $a === $b ? (string) $a : ($a < $b ? $a . '-' . $b : '');
        if ($a) return 'from ' . $a;
        if ($b) return 'until ' . $b;
        return '';
    }
}

if (!function_exists('kop_network_map_year_overrides')) {
    /**
     * Map name id => years, for every accepted decision. The map writes these
     * over graph.json's years before anything reads them (store.js), and the
     * page slices do the same, so an accepted year shows on the next request.
     */
    function kop_network_map_year_overrides() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        foreach (kop_network_years_decisions() as $id => $d) {
            if (($d['decision'] ?? '') === 'accepted' && !empty($d['years'])) {
                $memo[(string) $id] = (string) $d['years'];
            }
        }
        // Renames split a name's years at the rename (inc/network-renames.php).
        $memo = (array) apply_filters('kop_network_map_year_overrides', $memo);
        return $memo;
    }
}

if (!function_exists('kop_network_map_apply_year_overrides')) {
    /** graph.json-shaped nodes with the accepted years written into 'years'. */
    function kop_network_map_apply_year_overrides(array $nodes, array $overrides) {
        if (!$overrides) return $nodes;
        foreach ($nodes as $i => $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '' && isset($overrides[$id])) $nodes[$i]['years'] = $overrides[$id];
        }
        return $nodes;
    }
}

/* ---- Saving a decision ------------------------------------------------ */

if (!function_exists('kop_network_years_ajax')) {
    /**
     * POST action=kop_network_years_decide, nonce, and items: a JSON list of
     * {id, decision: accept|reject|undo, start, end}. One or many at once
     * (the "accept all" button sends many). Answers with every saved item's
     * state, so the page shows exactly what was stored.
     */
    function kop_network_years_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Only an administrator can review map years.'), 403);
        }
        check_ajax_referer('kop_network_years', 'nonce');
        $items = json_decode(wp_unslash((string) ($_POST['items'] ?? '[]')), true);
        if (!is_array($items) || !$items) {
            wp_send_json_error(array('message' => 'Nothing to save.'), 400);
        }
        $user = wp_get_current_user();
        wp_send_json_success(array('saved' => kop_network_years_decide($items, $user ? $user->user_login : '')));
    }
    add_action('wp_ajax_kop_network_years_decide', 'kop_network_years_ajax');
}

if (!function_exists('kop_network_years_decide')) {
    /**
     * Store decisions: $items [{id, decision: accept|reject|undo, start, end}].
     * Returns id => {decision, years} as stored, or {error} for an item refused.
     */
    function kop_network_years_decide(array $items, $login) {
        $candidates = kop_network_years_candidates();
        $decisions = kop_network_years_decisions();
        $out = array();
        $this_year = (int) gmdate('Y');
        foreach ($items as $item) {
            $id = sanitize_title((string) ($item['id'] ?? ''));
            if ($id === '' || !isset($candidates[$id])) continue;
            $decision = (string) ($item['decision'] ?? '');
            if ($decision === 'undo') {
                unset($decisions[$id]);
                $out[$id] = array('decision' => '', 'years' => '');
                continue;
            }
            if ($decision === 'accept') {
                $start = (int) ($item['start'] ?? 0);
                $end = (int) ($item['end'] ?? 0);
                $valid = function ($y) use ($this_year) { return $y === 0 || ($y >= 1800 && $y <= $this_year); };
                $years = ($valid($start) && $valid($end)) ? kop_network_years_format($start, $end) : '';
                if ($years === '') {
                    $out[$id] = array('error' => 'Give an opening year, a closing year, or both (closing after opening).');
                    continue;
                }
                $decisions[$id] = array('decision' => 'accepted', 'years' => $years,
                    'by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
            } elseif ($decision === 'reject') {
                $decisions[$id] = array('decision' => 'rejected', 'years' => '',
                    'by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
            } else {
                continue;
            }
            $out[$id] = array('decision' => $decisions[$id]['decision'], 'years' => $decisions[$id]['years']);
        }
        update_option('kop_network_years_review', $decisions, false);
        return $out;
    }
}

/* ---- The review screen ------------------------------------------------ */

if (!function_exists('kop_network_years_menu')) {
    function kop_network_years_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Map Years', 'Map Years', 'manage_options',
            'kop-network-years', 'kop_network_years_page');
    }
    add_action('admin_menu', 'kop_network_years_menu', 22);
}

if (!function_exists('kop_network_years_page')) {
    function kop_network_years_page() {
        if (!current_user_can('manage_options')) return;
        $candidates = kop_network_years_candidates();
        $decisions = kop_network_years_decisions();
        $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
        $kinds = array('facility' => 'Program', 'parent' => 'Company', 'association' => 'Trade group',
            'church' => 'Church', 'government' => 'Government body', 'other' => 'Other');
        $rows = array();
        foreach ($candidates as $id => $c) {
            $d = $decisions[$id] ?? array();
            $rows[] = array(
                'id' => $id,
                'name' => (string) ($c['name'] ?? $id),
                'kind' => $kinds[$c['kind'] ?? ''] ?? '',
                'place' => (string) ($c['place'] ?? ''),
                'start' => $c['start'] ?? null,
                'end' => $c['end'] ?? null,
                'still' => !empty($c['stillOperating']),
                'confidence' => (string) ($c['confidence'] ?? 'low'),
                'sources' => array_values(array_filter((array) ($c['sources'] ?? array()), function ($s) {
                    return !empty($s['url']);
                })),
                'note' => (string) ($c['note'] ?? ''),
                'decision' => (string) ($d['decision'] ?? ''),
                'years' => (string) ($d['years'] ?? ''),
                'mapUrl' => $map . '#open=' . rawurlencode($id),
            );
        }
        // Most useful first: well-sourced proposals, then weaker ones, then
        // the names nothing was found for.
        $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
        usort($rows, function ($a, $b) use ($rank) {
            $ha = $a['start'] || $a['end'] ? 0 : 1;
            $hb = $b['start'] || $b['end'] ? 0 : 1;
            return $ha <=> $hb ?: ($rank[$a['confidence']] ?? 3) <=> ($rank[$b['confidence']] ?? 3) ?: strcasecmp($a['name'], $b['name']);
        });
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kop_network_years'),
            'rows' => $rows,
        );
        ?>
        <div class="wrap kop-years">
            <h1>Map years</h1>
            <p class="kop-years__intro">
                When each program and company on the <a href="<?php echo esc_url($map); ?>" target="_blank" rel="noopener">network map</a>
                opened and closed, researched from the sources shown. The map's timeline leaves out any name with no years,
                so each one you accept comes back on the timeline straight away. Check the quote, fix a year if it is off,
                then accept or reject. Everything saves as you click. Some quotes were read through a summary of the page,
                so the link beside each one opens the source to confirm the wording.
            </p>
            <?php if (!$rows) : ?>
                <div class="notice notice-info"><p>No researched years yet.</p></div>
            <?php else : ?>
            <div class="kop-years__bar">
                <div class="kop-years__tabs" role="tablist">
                    <button type="button" role="tab" data-tab="review" aria-selected="true">To review <span></span></button>
                    <button type="button" role="tab" data-tab="accepted" aria-selected="false">Accepted <span></span></button>
                    <button type="button" role="tab" data-tab="rejected" aria-selected="false">Rejected <span></span></button>
                    <button type="button" role="tab" data-tab="none" aria-selected="false">No year found <span></span></button>
                </div>
                <button type="button" class="button button-primary kop-years__bulk" hidden></button>
                <span class="kop-years__status" role="status" aria-live="polite"></span>
            </div>
            <div class="kop-years__list"></div>
            <?php endif; ?>
        </div>
        <style>
            .kop-years__intro { max-width: 60em; font-size: 14px; }
            .kop-years__bar { position: sticky; top: 32px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                margin: 16px 0; padding: 10px 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; }
            .kop-years__tabs { display: flex; flex-wrap: wrap; gap: 4px; }
            .kop-years__tabs button { padding: 6px 12px; border: 1px solid #c3c4c7; border-radius: 999px; background: #f6f7f7; cursor: pointer; font-size: 13px; }
            .kop-years__tabs button[aria-selected="true"] { background: #000080; border-color: #000080; color: #fff; }
            .kop-years__tabs span { font-weight: 600; }
            .kop-years__status { color: #1d7a33; font-weight: 600; }
            .kop-years__list { display: grid; gap: 12px; max-width: 1100px; }
            .kop-years__card { padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #33a7b5; border-radius: 6px; }
            .kop-years__card.is-accepted { border-left-color: #1d7a33; }
            .kop-years__card.is-rejected { border-left-color: #b32d2e; opacity: .8; }
            .kop-years__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px 14px; }
            .kop-years__name { margin: 0; font-size: 16px; }
            .kop-years__meta { color: #50575e; }
            .kop-years__badge { padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f0f0f1; }
            .kop-years__badge.is-high { background: #d7f0dd; color: #14532d; }
            .kop-years__badge.is-medium { background: #fff3cd; color: #6b4e00; }
            .kop-years__badge.is-low { background: #fbe3e4; color: #7a1c1f; }
            .kop-years__fields { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin: 10px 0; }
            .kop-years__fields label { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
            .kop-years__fields input[type="number"] { width: 6.5em; }
            .kop-years__sources { margin: 6px 0; padding: 0; list-style: none; }
            .kop-years__sources li { margin: 4px 0; padding: 6px 10px; background: #f6f7f7; border-radius: 4px; }
            .kop-years__sources q { font-style: italic; }
            .kop-years__note { margin: 6px 0; color: #50575e; }
            .kop-years__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
            .kop-years__done { font-weight: 600; }
            .kop-years__error { color: #b32d2e; font-weight: 600; }
            .kop-years__empty { padding: 24px; background: #fff; border: 1px dashed #c3c4c7; border-radius: 6px; text-align: center; }
        </style>
        <script>
        (function () {
            var C = <?php echo wp_json_encode($config); ?>;
            var list = document.querySelector('.kop-years__list');
            if (!list) return;
            var bulk = document.querySelector('.kop-years__bulk');
            var status = document.querySelector('.kop-years__status');
            var tab = 'review';
            var rows = C.rows;

            function hasYears(r) { return !!(r.start || r.end); }
            function tabOf(r) {
                if (r.decision === 'accepted') return 'accepted';
                if (r.decision === 'rejected') return 'rejected';
                return hasYears(r) ? 'review' : 'none';
            }
            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function yearsText(r) {
                var a = r.start, b = r.still ? null : r.end;
                if (a && b) return a === b ? String(a) : a + ' to ' + b;
                if (a) return 'from ' + a + (r.still ? ' (still operating)' : '');
                if (b) return 'until ' + b;
                return '';
            }

            function save(items, done) {
                var body = new FormData();
                body.append('action', 'kop_network_years_decide');
                body.append('nonce', C.nonce);
                body.append('items', JSON.stringify(items));
                status.textContent = 'Saving...';
                fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Not saved.');
                        var saved = json.data.saved || {};
                        var errors = 0;
                        rows.forEach(function (r) {
                            var s = saved[r.id];
                            if (!s) return;
                            if (s.error) { r.error = s.error; errors++; return; }
                            r.error = '';
                            r.decision = s.decision;
                            r.years = s.years;
                        });
                        status.textContent = errors ? errors + ' not saved; see the red note.' : 'Saved.';
                        render();
                        if (done) done();
                    })
                    .catch(function (e) { status.textContent = 'Not saved: ' + e.message; });
            }

            function card(r) {
                var c = el('article', 'kop-years__card' + (r.decision ? ' is-' + r.decision : ''));
                var head = el('div', 'kop-years__head');
                head.appendChild(el('h2', 'kop-years__name', r.name));
                head.appendChild(el('span', 'kop-years__meta', [r.kind, r.place].filter(Boolean).join(' · ')));
                if (hasYears(r)) head.appendChild(el('span', 'kop-years__badge is-' + r.confidence, r.confidence + ' confidence'));
                var link = el('a', '', 'See it on the map');
                link.href = r.mapUrl; link.target = '_blank'; link.rel = 'noopener';
                head.appendChild(link);
                c.appendChild(head);

                if (r.decision) {
                    var done = el('div', 'kop-years__actions');
                    done.appendChild(el('span', 'kop-years__done', r.decision === 'accepted'
                        ? 'Accepted: ' + r.years.replace('-', ' to ') + '. On the map now.'
                        : 'Rejected. The name stays off the timeline.'));
                    var undo = el('button', 'button', 'Undo');
                    undo.type = 'button';
                    undo.addEventListener('click', function () { save([{ id: r.id, decision: 'undo' }]); });
                    done.appendChild(undo);
                    c.appendChild(done);
                    if (r.sources.length) c.appendChild(sources(r));
                    return c;
                }

                var fields = el('div', 'kop-years__fields');
                var start = el('input'); start.type = 'number'; start.min = 1800; start.max = new Date().getFullYear();
                start.value = r.start || ''; start.placeholder = 'year';
                var end = el('input'); end.type = 'number'; end.min = 1800; end.max = new Date().getFullYear();
                end.value = r.still ? '' : (r.end || ''); end.placeholder = 'year';
                var still = el('input'); still.type = 'checkbox'; still.checked = !!r.still;
                end.disabled = still.checked;
                still.addEventListener('change', function () { end.disabled = still.checked; if (still.checked) end.value = ''; });
                var l1 = el('label', '', 'Opened '); l1.appendChild(start);
                var l2 = el('label', '', 'Closed '); l2.appendChild(end);
                var l3 = el('label', ''); l3.appendChild(still); l3.appendChild(document.createTextNode(' Still operating'));
                fields.appendChild(l1); fields.appendChild(l2); fields.appendChild(l3);
                c.appendChild(fields);
                if (r.sources.length) c.appendChild(sources(r));
                if (r.note) c.appendChild(el('p', 'kop-years__note', r.note));
                if (r.error) c.appendChild(el('p', 'kop-years__error', r.error));

                var actions = el('div', 'kop-years__actions');
                var ok = el('button', 'button button-primary', hasYears(r) ? 'Accept' : 'Save these years');
                ok.type = 'button';
                ok.addEventListener('click', function () {
                    save([{ id: r.id, decision: 'accept', start: Number(start.value) || 0, end: still.checked ? 0 : (Number(end.value) || 0) }]);
                });
                var no = el('button', 'button', hasYears(r) ? 'Reject' : 'Leave off the timeline');
                no.type = 'button';
                no.addEventListener('click', function () { save([{ id: r.id, decision: 'reject' }]); });
                actions.appendChild(ok); actions.appendChild(no);
                c.appendChild(actions);
                return c;
            }

            function sources(r) {
                var ul = el('ul', 'kop-years__sources');
                r.sources.forEach(function (s) {
                    var li = el('li');
                    if (s.quote) { li.appendChild(el('q', '', s.quote)); li.appendChild(document.createTextNode(' ')); }
                    var a = el('a', '', hostOf(s.url));
                    a.href = s.url; a.target = '_blank'; a.rel = 'noopener nofollow';
                    li.appendChild(a);
                    ul.appendChild(li);
                });
                return ul;
            }
            function hostOf(url) {
                try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return url; }
            }

            function render() {
                var counts = { review: 0, accepted: 0, rejected: 0, none: 0 };
                rows.forEach(function (r) { counts[tabOf(r)]++; });
                document.querySelectorAll('.kop-years__tabs button').forEach(function (b) {
                    var t = b.getAttribute('data-tab');
                    b.querySelector('span').textContent = '(' + counts[t] + ')';
                    b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                list.textContent = '';
                var shown = rows.filter(function (r) { return tabOf(r) === tab; });
                if (!shown.length) {
                    list.appendChild(el('p', 'kop-years__empty', tab === 'review' ? 'Nothing left to review.' : 'Nothing here.'));
                }
                shown.forEach(function (r) { list.appendChild(card(r)); });
                var high = rows.filter(function (r) { return tabOf(r) === 'review' && r.confidence === 'high'; });
                bulk.hidden = tab !== 'review' || !high.length;
                bulk.textContent = 'Accept all ' + high.length + ' high-confidence years';
            }

            document.querySelectorAll('.kop-years__tabs button').forEach(function (b) {
                b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); status.textContent = ''; render(); });
            });
            bulk.addEventListener('click', function () {
                var high = rows.filter(function (r) { return tabOf(r) === 'review' && r.confidence === 'high'; });
                save(high.map(function (r) {
                    return { id: r.id, decision: 'accept', start: r.start || 0, end: r.still ? 0 : (r.end || 0) };
                }));
            });
            render();
        })();
        </script>
        <?php
    }
}
