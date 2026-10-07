<?php
/**
 * Arkansas: js/inspections/states/ar.js report() and isFlagged().
 * A citation is a tag "Citation" on the document; it means the state cited
 * the facility or found a complaint valid.
 */

function kop_iv_ar(array $report) {
    $cats = $report['categories'];
    $tags = kop_iv_list($cats['tags'] ?? array());
    $tags = array_map('kop_iv_str', $tags);

    $cited = in_array('Citation', $tags, true);

    $badges = $cited ? array(kop_iv_badge('Citation', 'flagged')) : array();

    return kop_iv_verdict($cited ? 'flagged' : 'neutral', $cited ? 1 : 0, $badges);
}
