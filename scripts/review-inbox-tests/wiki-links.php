<?php
/**
 * scripts/test-review-inbox.php checks for the 'wiki-links' source
 * (inc/review-inbox/wiki-links.php over inc/wiki-updates.php): every waiting
 * card offers its candidates, the finder and Set aside; Link saves a
 * 'suggested' link without touching the entry's text or updated_at; Undo puts
 * back what the entry had; Set aside and its Undo; "Link every clear match"
 * and undoing them all.
 */

require_once __DIR__ . '/_shared.php';

function kop_rinbox_test_wiki_links(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $key = (int) $item['key'];
    $ids = array_column($item['actions'], 'id');
    $check('wiki-links: a card links its record, finds another, or sets it aside', $ids[0] === 'link_0' && in_array('link_other', $ids, true) && end($ids) === 'skip', implode(',', $ids));
    $counts = call_user_func($src['view_counts']);
    $check('wiki-links: every tab has a count', array_keys($counts) === array_keys($src['views']) && call_user_func($src['count']) === $counts['clear'] + $counts['choose'] + $counts['none'], json_encode($counts));
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('wiki-links: link, set aside and undo', 'update_option does not store in this harness');
        return;
    }
    $row = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT facility_unique_name, facility_link_status, updated_at, original_markdown, generated_markdown FROM wiki_submissions WHERE id = ?');
        $st->execute(array($id));
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $before = $row($key);
    $rows = kop_rinbox_wlinks_rows();
    $want = $rows[$key]['cands'][0]['unique_name'];
    $res = call_user_func($src['act'], (string) $key, 'link_0', array());
    $after = $row($key);
    $check('wiki-links: Link saves a suggested link', $after['facility_unique_name'] === $want && $after['facility_link_status'] === 'suggested', $res['message']);
    $check('wiki-links: the entry\'s text and updated_at stay as they were', $after['updated_at'] === $before['updated_at']
        && $after['original_markdown'] === $before['original_markdown'] && $after['generated_markdown'] === $before['generated_markdown']);
    $got = kop_rinbox_get_item('wiki-links', (string) $key);
    $check('wiki-links: it moves to Linked here with Undo', $got['status'] === 'linked' && $got['actions'][0]['id'] === 'undo');
    call_user_func($src['act'], (string) $key, 'undo', array());
    $check('wiki-links: Undo puts back what the entry had', $row($key) == $before && kop_rinbox_get_item('wiki-links', (string) $key)['status'] === $item['status']);

    call_user_func($src['act'], (string) $key, 'skip', array());
    $check('wiki-links: Set aside leaves it unlinked', kop_rinbox_get_item('wiki-links', (string) $key)['status'] === 'skip' && $row($key) == $before);
    call_user_func($src['act'], (string) $key, 'undo', array());

    // Every card shows what the entry says and where each candidate can be looked at.
    $card = kop_rinbox_get_item('wiki-links', (string) $key);
    $says = array_values(array_filter($card['details'], function ($d) { return $d['label'] === 'The entry says'; }));
    $unlinked = array_filter($card['details'], function ($d) { return preg_match('/^(Record|Company) /', $d['label']) && empty($d['url']); });
    $check('wiki-links: the card shows what the entry says and links every candidate', $says && $says[0]['value'] !== '' && !$unlinked, $says[0]['value'] ?? '');

    // A company: the list offers every company record and the link stores its name.
    $companies = kop_rinbox_wlinks_companies();
    $co = array_values(array_filter($card['actions'], function ($a) { return $a['id'] === 'link_company'; }))[0] ?? null;
    $check('wiki-links: "Link to a company" lists every company', $co && count($co['params'][0]['options']) === count($companies) && count($companies) > 10, count($companies) . ' companies');
    $pick = array_keys($companies)[0];
    call_user_func($src['act'], (string) $key, 'link_company', array('company' => $pick));
    $check('wiki-links: Link to a company stores the company', $row($key)['facility_unique_name'] === $pick && kop_rinbox_get_item('wiki-links', (string) $key)['status'] === 'linked');
    call_user_func($src['act'], (string) $key, 'undo', array());

    // The finder: any record by id.
    $other = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE unique_name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    call_user_func($src['act'], (string) $key, 'link_other', array('facility' => (string) $other));
    $un = $pdo->query('SELECT unique_name FROM facilities_v2 WHERE id = ' . $other)->fetchColumn();
    $check('wiki-links: Link to another record takes the finder\'s pick', $row($key)['facility_unique_name'] === $un);
    call_user_func($src['act'], (string) $key, 'undo', array());

    $clear = array_keys(array_filter(kop_rinbox_wlinks_rows(), function ($e) { return $e['view'] === 'clear'; }));
    $res = call_user_func($src['tool'], 'link_clear', array());
    $linked = array_filter(kop_wiki_upd_link_log(), function ($d) { return $d['decision'] === 'link'; });
    $check('wiki-links: "Link every clear match" links them all', $clear && count($linked) === count($clear), count($clear) . ' clear; ' . $res['message']);
    foreach (array_keys($linked) as $id) kop_wiki_upd_unlink($pdo, $id);
    kop_rinbox_wlinks_rows(true);
    $check('wiki-links: and they can be undone', !kop_wiki_upd_link_log() && (int) $pdo->query("SELECT COUNT(*) FROM wiki_submissions WHERE id IN (" . implode(',', $clear) . ") AND facility_unique_name IS NOT NULL AND facility_unique_name <> ''")->fetchColumn() === 0);
}
