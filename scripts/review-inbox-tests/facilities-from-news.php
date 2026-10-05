<?php
/**
 * scripts/test-review-inbox.php checks for the 'facilities-from-news' source
 * (inc/review-inbox/facilities-from-news.php over inc/facility-discovery.php).
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/facility-discovery.php';
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_facility_discovery_db'] = KOP_FACILITY_DISCOVERY_DB_VERSION;

if (!class_exists('KOP_Rinbox_Test_MySQL_PDO')) {
    /** The scratch copy, with the MySQL words the queue's own writes use turned into SQLite's. */
    class KOP_Rinbox_Test_MySQL_PDO extends PDO {
        private function fix($sql) {
            return preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', (string) $sql);
        }
        public function prepare(string $query, array $options = array()): PDOStatement|false {
            return parent::prepare($this->fix($query), $options);
        }
        public function exec(string $statement): int|false {
            return parent::exec($this->fix($statement));
        }
    }
}

function kop_rinbox_test_facilities_from_news(array $src, array $item, callable $check) {
    $pdo = kop_closure_pdo();
    $id = (int) $item['key'];
    $get = function () use ($pdo, $id) {
        $st = $pdo->prepare('SELECT * FROM news_facility_candidates WHERE id = ?');
        $st->execute(array($id));
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $before = $get();
    $check('facilities-from-news: waiting item is held back', in_array($before['decision'], array('possible_duplicate', 'other_era', 'needs_place'), true), $before['decision']);
    // "Same program" only when the card has a record; the other choices open their form first ('ask').
    $choices = $item['facility'] ? array('link', 'link_other', 'create', 'dismiss') : array('link_other', 'create', 'dismiss');
    $check('facilities-from-news: held item offers same program, another record, new program and skip', array_column($item['actions'], 'id') === $choices,
        json_encode(array_column($item['actions'], 'id')));
    $asks = array_column(array_filter($item['actions'], function ($a) { return !empty($a['ask']); }), 'id');
    $check('facilities-from-news: another record and new program open a form first, the record box starts empty', $asks === array('link_other', 'create')
        && (string) array_values(array_filter($item['actions'], function ($a) { return $a['id'] === 'link_other'; }))[0]['params'][0]['value'] === '');

    call_user_func($src['save'], $item['key'], array('officialName' => 'Renamed In Test', 'state' => 'ut', 'type' => 'Wilderness Therapy'));
    $entry = json_decode($get()['detail'], true)['entry'];
    $check('facilities-from-news: save writes the entry', $entry['officialName'] === 'Renamed In Test' && $entry['state'] === 'UT' && $entry['type'] === 'Wilderness Therapy');
    $again = kop_rinbox_get_item('facilities-from-news', $item['key']);
    $values = array_column($again['fields'], 'value', 'name');
    $check('facilities-from-news: the item shows the saved details', $values['officialName'] === 'Renamed In Test' && $values['state'] === 'UT');
    $check('facilities-from-news: the card is headed by the corrected name and does not show the original wording', $again['title'] === 'Renamed In Test' && strpos($again['subtitle'], $item['title']) === false);
    // The old screen's create form: the details as params on Create, prefilled, only the name required.
    $create = array_values(array_filter($again['actions'], function ($a) { return $a['id'] === 'create'; }))[0];
    $p = array_column($create['params'], null, 'name');
    $check('facilities-from-news: Create carries the form, prefilled', $p['officialName']['value'] === 'Renamed In Test' && $p['state']['value'] === 'UT'
        && empty($p['officialName']['optional']) && !empty($p['country']['optional']) && isset($p['type']['options']['Wilderness Therapy']));
    $counts = call_user_func($src['view_counts'], array());
    $check('facilities-from-news: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['all'] >= $counts['held'], json_encode($counts));
    $check('facilities-from-news: All recent lists every name', call_user_func($src['list'], array('view' => 'all', 'search' => '', 'offset' => 0, 'limit' => 1))['total'] === $counts['all']);
    $check('facilities-from-news: details name the article', in_array('Found in', array_column($again['details'], 'label'), true));
    $check('facilities-from-news: the scan tool is offered', ($src['tools'][0]['id'] ?? '') === 'scan' && is_callable($src['tool']));
    foreach (array(array('state' => 'Utah'), array('type' => 'Spa'), array('officialName' => '  ')) as $bad) {
        try {
            call_user_func($src['save'], $item['key'], $bad);
            $check('facilities-from-news: bad ' . key($bad) . ' is refused', false);
        } catch (RuntimeException $e) {
            $check('facilities-from-news: bad ' . key($bad) . ' is refused', true, $e->getMessage());
        }
    }
    try {
        call_user_func($src['act'], $item['key'], 'link', array('facility_id' => 999999999));
        $check('facilities-from-news: linking to no record is refused', false);
    } catch (RuntimeException $e) {
        $check('facilities-from-news: linking to no record is refused', true, $e->getMessage());
    }
    try {
        call_user_func($src['act'], $item['key'], 'remove', array());
        $check('facilities-from-news: remove only takes records the scan created', false);
    } catch (RuntimeException $e) {
        $check('facilities-from-news: remove only takes records the scan created', true, $e->getMessage());
    }

    // Link by hand, through the queue's own function (its INSERT IGNORE needs the rewrite on SQLite).
    $fid = (int) $pdo->query('SELECT id FROM facilities_v2 ORDER BY id LIMIT 1')->fetchColumn();
    $had = $pdo->prepare('SELECT COUNT(*) FROM news_facility_links WHERE news_id = ? AND facility_id = ?');
    $had->execute(array((int) $before['news_id'], $fid));
    $had = (int) $had->fetchColumn();
    $orig = $GLOBALS['pdo'];
    $GLOBALS['pdo'] = new KOP_Rinbox_Test_MySQL_PDO('sqlite:' . $GLOBALS['db_path']);
    $GLOBALS['pdo']->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
    try {
        $res = call_user_func($src['act'], $item['key'], 'link', array('facility_id' => $fid));
        $after = $get();
        $linked = $pdo->prepare('SELECT COUNT(*) FROM news_facility_links WHERE news_id = ? AND facility_id = ?');
        $linked->execute(array((int) $before['news_id'], $fid));
        $check('facilities-from-news: link marks it matched and links the article', $after['decision'] === 'matched' && (int) $after['facility_id'] === $fid && (int) $linked->fetchColumn() === 1, $res['message']);
        $linked->closeCursor();
        $moved = kop_rinbox_get_item('facilities-from-news', $item['key']);
        $check('facilities-from-news: a linked name offers only Remove the link', kop_rinbox_test_own_actions($moved) === array('unlink') && !$moved['fields']);
        try {
            call_user_func($src['act'], $item['key'], 'create', array());
            $check('facilities-from-news: create is refused once it has a record', false);
        } catch (RuntimeException $e) {
            $check('facilities-from-news: create is refused once it has a record', true, $e->getMessage());
        }
        // Remove the link: the article leaves the page (unless it was linked before) and the name is back where it was.
        $res = call_user_func($src['act'], $item['key'], 'unlink', array());
        $back = $get();
        $linked->execute(array((int) $before['news_id'], $fid));
        $check('facilities-from-news: Remove the link puts the name back and takes the article off', $back['decision'] === $before['decision']
            && (string) $back['facility_id'] === (string) $before['facility_id'] && (int) $linked->fetchColumn() === $had, $res['message']);
        $linked->closeCursor();
        $check('facilities-from-news: after Remove the link it can be linked again', array_column(kop_rinbox_get_item('facilities-from-news', $item['key'])['actions'], 'id') === $choices);
        try {
            call_user_func($src['act'], $item['key'], 'unlink', array());
            $check('facilities-from-news: Remove the link is refused when nothing is linked', false);
        } catch (RuntimeException $e) {
            $check('facilities-from-news: Remove the link is refused when nothing is linked', true, $e->getMessage());
        }
    } finally {
        $GLOBALS['pdo'] = $orig;
    }
    if (!$had) {
        $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ?')->execute(array((int) $before['news_id'], $fid));
    }
    $pdo->prepare('UPDATE news_facility_candidates SET decision = ?, facility_id = ?, detail = ?, reviewed_by = ?, updated_at = ? WHERE id = ?')
        ->execute(array($before['decision'], $before['facility_id'], $before['detail'], $before['reviewed_by'], $before['updated_at'], $id));

    $created = call_user_func($src['list'], array('view' => 'created', 'search' => '', 'offset' => 0, 'limit' => 1))['items'][0] ?? null;
    if ($created) {
        $check('facilities-from-news: a created record offers Remove (its undo)', array_column($created['actions'], 'id') === array('remove') && !$created['fields']);
    }
}
