<?php
/**
 * scripts/test-review-inbox.php checks for the 'federal-funding' source
 * (inc/review-inbox/federal-funding.php over inc/federal-funding.php), on the
 * real proposals in js/data/federal-funding/proposals.json and the mirror's
 * records: every proposal is well formed and names records that exist, the
 * record field survives the save path (normalize, to legacy, normalize), Add
 * and Undo on a real document give it back unchanged, a second Add adds
 * nothing, the facility page groups the entries, the wording a reviewer saves
 * is what goes on the record, and every card says what its buttons do.
 * Saving a record needs MySQL and is not run (as for Data audit).
 */

require_once dirname(__DIR__, 2) . '/inc/federal-funding.php';
if (!function_exists('kop_facdisc_types')) require_once dirname(__DIR__, 2) . '/inc/facility-discovery.php';

function kop_rinbox_test_federal_funding(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $props = kop_ffund_proposals();
    $streams = kop_facility_federal_funding_streams();
    $doc_of = function ($fid) use ($pdo) {
        $st = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
        $st->execute(array((int) $fid));
        $js = $st->fetchColumn();
        return $js === false ? null : json_decode((string) $js, true);
    };

    // Every proposal: known streams, wording and a link on each entry, records that exist, the view its match says.
    $bad = array();
    $views = array('matched' => 0, 'check' => 0, 'new' => 0);
    foreach ($props as $key => $p) {
        if (!$p['entries']) $bad[] = $key . ': no entries';
        foreach ($p['entries'] as $n => $e) {
            if (!isset($streams[$e['stream'] ?? ''])) $bad[] = $key . ': stream ' . json_encode($e['stream'] ?? null);
            if (trim((string) ($e['detail'] ?? '')) === '') $bad[] = $key . ': entry ' . $n . ' has no wording';
            if (empty($e['sources'][0]['url'])) $bad[] = $key . ': entry ' . $n . ' has no source';
            if (($e['years'] ?? '') !== '' && !preg_match('/^\d{4}(-\d{4})?$/', $e['years'])) $bad[] = $key . ': years ' . $e['years'];
            foreach ((array) ($e['sources'] ?? array()) as $s) if (stripos((string) $s['url'], 'drive.google.com') !== false) $bad[] = $key . ': a private Drive link';
        }
        foreach ((array) ($p['candidates'] ?? array()) as $c) if ($doc_of($c['id']) === null) $bad[] = $key . ': no record #' . $c['id'];
        if (($p['match'] === 'no record') !== empty($p['candidates'])) $bad[] = $key . ': match ' . $p['match'] . ' with ' . count((array) $p['candidates']) . ' candidates';
        $views[kop_rinbox_ffund_view($p, array())]++;
        if (count(kop_ffund_entries($key, $p)) !== count($p['entries'])) $bad[] = $key . ': an entry is dropped by the record normalizer';
    }
    $check('federal-funding: every proposal is well formed', !$bad, count($props) . ' proposals, ' . json_encode($views) . ($bad ? '; ' . implode(' | ', array_slice($bad, 0, 6)) : ''));

    // The field on a real record: Add, a second Add, the save path, Undo.
    $key = '';
    foreach ($props as $k => $p) if ($p['match'] === 'matched' && count($p['entries']) > 1) { $key = $k; break; }
    $p = $props[$key];
    $fid = (int) $p['candidates'][0]['id'];
    $doc = $doc_of($fid);
    $entries = kop_ffund_entries($key, $p);
    $after = $doc;
    $refs = kop_ffund_doc_add($after, $entries);
    $check('federal-funding: Add puts every entry on the record with its ref and credit', count($refs) === count($entries)
        && count($after['federalFunding']) === count((array) ($doc['federalFunding'] ?? array())) + count($entries)
        && $after['federalFunding'][count($after['federalFunding']) - 1]['source'] === KOP_FFUND_SOURCE, $key . ' on #' . $fid);
    $again = $after;
    $check('federal-funding: a second Add adds nothing', kop_ffund_doc_add($again, $entries) === array() && $again['federalFunding'] == $after['federalFunding']);
    $saved = kop_facility_normalize(kop_facility_to_legacy($after), array('facility_id' => $fid, 'unique_name' => $after['provenance']['uniqueName'] ?? ''));
    $check('federal-funding: the entries survive the save path (to legacy and back)', $saved['federalFunding'] == $after['federalFunding'],
        count($saved['federalFunding'] ?? array()) . ' of ' . count($after['federalFunding']));
    $check('federal-funding: the record normalizer keeps the field out of legacy', !isset($saved['legacy']['federalFunding']));
    $back = $after;
    $removed = kop_ffund_doc_remove($back, $refs);
    $check('federal-funding: Undo takes off exactly what Add put on', $removed === count($refs) && kop_facility_same_document(
        array_merge($back, array('federalFunding' => kop_facility_federal_funding_list($back['federalFunding']))),
        array_merge($doc, array('federalFunding' => kop_facility_federal_funding_list($doc['federalFunding'] ?? array())))));
    $edited = $after;
    $edited['federalFunding'][] = array('stream' => 'medicaid', 'detail' => 'Added by hand.', 'sources' => array('https://example.test/a'));
    kop_ffund_doc_remove($edited, $refs);
    $check('federal-funding: Undo leaves an entry added by hand since', count($edited['federalFunding']) === 1 && $edited['federalFunding'][0]['detail'] === 'Added by hand.');

    // The normalizer: unknown stream is 'other', a bad link is dropped, an empty entry is dropped.
    $norm = kop_facility_federal_funding_list(array(
        array('stream' => 'grants', 'detail' => 'x', 'sources' => array('ftp://no', array('url' => 'https://a.test/1', 'label' => 'A'), 'https://a.test/1')),
        array('stream' => 'medicaid', 'detail' => '', 'sources' => array()),
        'not an entry',
    ));
    $check('federal-funding: the normalizer cleans entries', count($norm) === 1 && $norm[0]['stream'] === 'other'
        && $norm[0]['sources'] === array(array('url' => 'https://a.test/1', 'label' => 'A')), json_encode($norm));

    // The facility page: grouped by stream in the page's order.
    if (!function_exists('kop_facility_pages_federal_funding')) require_once dirname(__DIR__, 2) . '/inc/facility-pages.php';
    $groups = kop_facility_pages_federal_funding($after['federalFunding']);
    $order = array_keys($streams);
    $pos = array_map(function ($g) use ($order) { return array_search($g['stream'], $order, true); }, $groups);
    $sorted = $pos;
    sort($sorted);
    $check('federal-funding: the facility page groups the entries by kind, in order', $groups && $pos === $sorted
        && array_sum(array_map(function ($g) { return count($g['entries']); }, $groups)) === count($after['federalFunding']), implode(', ', array_column($groups, 'label')));

    // The reviewer's wording is what goes on the record; a bad year is refused.
    if (kop_rinbox_test_options_persist()) {
        kop_ffund_save_edits($key, array('detail_0' => 'Reworded for the page.', 'years_0' => '2021-2023'));
        $e0 = kop_ffund_entries($key, $p)[0];
        $check('federal-funding: saved wording and years go on the record', $e0['detail'] === 'Reworded for the page.' && $e0['years'] === '2021-2023');
        $refused = '';
        try { kop_ffund_save_edits($key, array('years_0' => 'around 2020')); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
        $check('federal-funding: a year that is not a year is refused', $refused !== '', $refused);
        kop_ffund_save_edits($key, array('detail_0' => $p['entries'][0]['detail'], 'years_0' => $p['entries'][0]['years']));
        $check('federal-funding: putting the wording back clears the edit', !isset(kop_ffund_edits()[$key]));
    }

    // Every card says what its buttons do; waiting cards pick a record, Added and Left off offer Undo.
    $missing = array();
    foreach (array('matched', 'check', 'new') as $view) {
        $first = null;
        foreach ($props as $k => $q) if (kop_rinbox_ffund_view($q, array()) === $view) { $first = $k; break; }
        if ($first === null) continue;
        $it = kop_rinbox_ffund_item($first, $props[$first]);
        $ids = array_column($it['actions'], 'id');
        if ($ids !== array('add', 'create', 'reject')) $missing[] = $view . ': ' . implode(',', $ids);
        foreach ($it['actions'] as $a) if (empty($a['help'])) $missing[] = $view . ': ' . $a['id'] . ' has no help';
        if ($view !== 'new' && (int) $it['actions'][0]['params'][0]['value'] !== (int) $props[$first]['candidates'][0]['id']) $missing[] = $view . ': record not filled in';
    }
    $check('federal-funding: waiting cards offer Add, New record and Leave it off, each with its help', !$missing, implode(' | ', $missing));
}
