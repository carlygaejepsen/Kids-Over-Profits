<?php
/**
 * Offline checks for moving or removing a document from the page it shows on
 * (inc/doc-placement.php, the document tile's pencil) against the production
 * mirror in tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-placement.php [--db=tmp/prod.sqlite]
 *
 * The folder tables are copied into TEMP tables that shadow the mirror's, so
 * every move, removal and undo really writes and is read back, and the
 * mirror itself is never changed. Deleting a document is checked only as far
 * as which files it would keep on disk.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';

if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
function get_post_type($id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SELECT post_type FROM wpdl_posts WHERE ID = %d', $id));
}
function get_current_user_id() { return 1; }
function wp_generate_password($n = 12) { return substr(bin2hex(random_bytes($n)), 0, $n); }
function wp_basename($p) { return basename(str_replace('\\', '/', (string) $p)); }
function wp_get_attachment_metadata($id) {
    $v = get_post_meta($id, '_wp_attachment_metadata', true);
    return is_string($v) ? @unserialize($v) : $v;
}

/** The harness's $wpdb plus the writes the module makes. */
class KOP_DP_Test_Wpdb extends wpdb {
    public $insert_id = 0;
    private function where(array $where) {
        global $pdo;
        $sql = array();
        foreach ($where as $k => $v) {
            $sql[] = "`$k` = " . (is_int($v) ? (string) $v : $pdo->quote((string) $v));
        }
        return implode(' AND ', $sql);
    }
    public function delete($table, $where, $format = null) {
        global $pdo;
        return $pdo->exec("DELETE FROM `$table` WHERE " . $this->where($where));
    }
    public function insert($table, $data, $format = null) {
        global $pdo;
        $cols = array_keys($data);
        $vals = array_map(function ($v) use ($pdo) { return is_int($v) ? (string) $v : $pdo->quote((string) $v); }, array_values($data));
        $ok = $pdo->exec("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ')');
        $this->insert_id = (int) $pdo->lastInsertId();
        return $ok;
    }
}
$wpdb = new KOP_DP_Test_Wpdb($pdo);
$GLOBALS['wpdb'] = $wpdb;

// TEMP tables shadow the mirror's tables of the same name: writes land there.
$pdo->exec('CREATE TEMP TABLE wpdl_fbv (id INTEGER PRIMARY KEY, name TEXT, parent INTEGER, type INTEGER, ord INTEGER, created_by INTEGER)');
$pdo->exec('INSERT INTO temp.wpdl_fbv SELECT id, name, parent, type, ord, created_by FROM main.wpdl_fbv');
foreach (array('wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links') as $t) {
    $pdo->exec("CREATE TEMP TABLE `$t` AS SELECT * FROM main.`$t`");
}
$pdo->exec('CREATE UNIQUE INDEX temp.dp_rel ON wpdl_fbv_attachment_folder (folder_id, attachment_id)');
$pdo->exec('CREATE UNIQUE INDEX temp.dp_tag ON wpdl_kop_media_folder_tags (folder_id, attachment_id)');

require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/inline-edit.php';
require_once dirname(__DIR__) . '/inc/doc-placement.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$filed = function ($att) use ($pdo) {
    return array_map('intval', $pdo->query('SELECT folder_id FROM wpdl_fbv_attachment_folder WHERE attachment_id = ' . (int) $att . ' ORDER BY folder_id')->fetchAll(PDO::FETCH_COLUMN));
};
$tagged = function ($att) use ($pdo) {
    return array_map('intval', $pdo->query('SELECT folder_id FROM wpdl_kop_media_folder_tags WHERE attachment_id = ' . (int) $att . ' ORDER BY folder_id')->fetchAll(PDO::FETCH_COLUMN));
};
$values = function (array $fields) {
    $out = array();
    foreach ($fields as $f) $out[$f['name']] = $f;
    return $out;
};

// A facility page folder with a Woodbury subfolder holding a filed document,
// and a document filed on the folder itself.
$sub = $pdo->query("SELECT s.id AS sub, s.parent AS home, MIN(r.attachment_id) AS att
    FROM wpdl_fbv s JOIN wpdl_fbv_attachment_folder r ON r.folder_id = s.id
    JOIN wpdl_posts p ON p.ID = r.attachment_id AND p.post_type = 'attachment'
    WHERE s.type = 0 AND s.name = 'Woodbury Reports Mentions' AND s.parent > 0
      AND EXISTS (SELECT 1 FROM wpdl_fbv_attachment_folder r2 JOIN wpdl_posts p2 ON p2.ID = r2.attachment_id AND p2.post_type = 'attachment'
                  WHERE r2.folder_id = s.parent)
    GROUP BY s.id ORDER BY s.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    fwrite(STDERR, "No folder with a filed Woodbury subfolder in the mirror.\n");
    exit(2);
}
$home = (int) $sub['home'];
$woodbury_doc = (int) $sub['att'];
$root_doc = (int) $pdo->query("SELECT MIN(r.attachment_id) FROM wpdl_fbv_attachment_folder r JOIN wpdl_posts p ON p.ID = r.attachment_id
    AND p.post_type = 'attachment' WHERE r.folder_id = $home")->fetchColumn();
$page = kop_dp_page_folders($home);
echo "Page folder: " . kop_dp_folder_path($home) . " (#$home), " . count($page['all']) . " folders; documents #$root_doc and #$woodbury_doc\n";

echo "-- The page's folders --\n";
$check('the page folder group holds its own folder', in_array($home, $page['roots'], true));
$check('subfolders are on the page', in_array((int) $sub['sub'], $page['all'], true));
$check('a subfolder keeps its name under the page', kop_dp_relative_names((int) $sub['sub'], $page['roots']) === array('Woodbury Reports Mentions'));
$check('a root has no relative names', kop_dp_relative_names($home, $page['roots']) === array());
$check('paths read top down', substr(kop_dp_folder_path((int) $sub['sub']), -strlen(' > Woodbury Reports Mentions')) === ' > Woodbury Reports Mentions');

echo "-- The dialog --\n";
$fields = $values(kop_dp_fields($root_doc, $home));
$check('lists where it is filed', isset($fields['dp_rows']) && count($fields['dp_rows']['options']) >= 1);
$check('ticks the filing that puts it on this page', in_array('r:' . $home, $fields['dp_rows']['value'], true));
$check('says which filing puts it here', strpos(json_encode($fields['dp_rows']['options']), 'puts it on this page') !== false);
$check('offers move, remove and delete', array_column($fields['dp_action']['options'], 'value') === array('', 'move', 'remove', 'delete'));
$check('delete asks first', !empty($fields['dp_action']['confirm']['delete']));
$check('the target shows only for a move', ($fields['dp_target']['show_if'] ?? null) === array('dp_action' => 'move') && $fields['dp_target']['type'] === 'place');
$check('the doc: ref reads its page folder', kop_ie_doc_home(array('5', 'h' . $home)) === $home && kop_ie_doc_home(array('5')) === 0);
$elsewhere = $values(kop_dp_fields($root_doc, 0));
$check('without a page every filing is ticked', $elsewhere['dp_rows']['value'] === array_column($elsewhere['dp_rows']['options'], 'value'));

echo "-- Leave it --\n";
$before = $filed($root_doc);
$check('no action changes nothing', kop_dp_apply($root_doc, $home, array('dp_action' => '')) === array() && $filed($root_doc) === $before);

echo "-- Move to a folder (folder lookup) --\n";
$hits = kop_dp_folder_search('Woodbury Reports');
$check('folder search finds folders by name', $hits && strpos($hits[0]['path'], 'Woodbury') !== false, $hits ? $hits[0]['path'] : 'none');
$check('a one-letter search finds nothing', kop_dp_folder_search('W') === array());
$target = (int) $pdo->query("SELECT id FROM wpdl_fbv WHERE type = 0 AND id NOT IN (" . implode(',', $page['all']) . ") ORDER BY id LIMIT 1")->fetchColumn();
$res = kop_dp_apply($root_doc, $home, array('dp_action' => 'move', 'dp_rows' => array('r:' . $home), 'dp_target' => 'd:' . $target, 'dp_copies' => false));
$check('the move says where it went', strpos($res['message'], kop_dp_folder_path($target)) !== false, $res['message']);
$check('the document is filed in the new folder', in_array($target, $filed($root_doc), true));
$check('and no longer on this page', !array_intersect($filed($root_doc), $page['all']));
$check('the move can be undone', strpos($res['undo'] ?? '', 'docundo:') === 0);
list($src, $parts) = kop_ie_resolve($res['undo']);
$undone = call_user_func($src['save'], $parts, array());
$check('undo puts it back', $filed($root_doc) === $before, json_encode($filed($root_doc)));
$check('undo says so', strpos($undone['message'], 'back where it was') !== false);
$threw = false;
try { call_user_func($src['save'], $parts, array()); } catch (RuntimeException $e) { $threw = true; }
$check('an undo works once', $threw);

echo "-- Move to a facility, keeping the Woodbury subfolder --\n";
// A facility with a page and a folder of its own, not this page's.
$fid = 0; $fac_folder = 0;
foreach (kop_facility_pages_index()['ids'] as $id => $entry) {
    if (!empty($entry['folder']) && !in_array((int) $entry['folder'], $page['all'], true)
        && !kop_wb_find_folder('Woodbury Reports Mentions', (int) $entry['folder'])) {
        $fid = (int) $id;
        $fac_folder = (int) $entry['folder'];
        break;
    }
}
if (!$fid) {
    echo "SKIP no facility page with a folder and no Woodbury subfolder\n";
} else {
    $check('the facility target is its page\'s folder', kop_dp_target('f:' . $fid)[0] === $fac_folder);
    $before = $filed($woodbury_doc);
    $res = kop_dp_apply($woodbury_doc, $home, array('dp_action' => 'move', 'dp_rows' => array('r:' . $sub['sub']), 'dp_target' => 'f:' . $fid, 'dp_keep_sub' => true));
    $now = $filed($woodbury_doc);
    $dest = $now ? $now[0] : 0;
    $check('lands in a new Woodbury subfolder of the facility\'s folder', count($now) === 1 && $dest > 0
        && (int) $pdo->query("SELECT parent FROM wpdl_fbv WHERE id = $dest")->fetchColumn() === $fac_folder, kop_dp_folder_path($dest));
    $check('the subfolder is named the same', (string) $pdo->query("SELECT name FROM wpdl_fbv WHERE id = $dest")->fetchColumn() === 'Woodbury Reports Mentions');
    $check('the message names the facility', strpos($res['message'], 'Moved') === 0, $res['message']);
    list($src, $parts) = kop_ie_resolve($res['undo']);
    call_user_func($src['save'], $parts, array());
    $check('undo puts it back in the subfolder', $filed($woodbury_doc) === $before);
    $res = kop_dp_apply($woodbury_doc, $home, array('dp_action' => 'move', 'dp_rows' => array('r:' . $sub['sub']), 'dp_target' => 'f:' . $fid, 'dp_keep_sub' => false));
    $check('without keeping the subfolder it lands on the facility\'s folder', $filed($woodbury_doc) === array($fac_folder));
    list($src, $parts) = kop_ie_resolve($res['undo']);
    call_user_func($src['save'], $parts, array());
}
$threw = '';
try { kop_dp_apply($root_doc, $home, array('dp_action' => 'move', 'dp_rows' => array('r:' . $home), 'dp_target' => '')); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
$check('a move with no target is refused', $threw !== '' && $filed($root_doc) === array_values(array_unique($filed($root_doc))), $threw);
$threw = '';
try { kop_dp_apply($root_doc, $home, array('dp_action' => 'remove', 'dp_rows' => array())); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
$check('nothing ticked is refused', $threw !== '', $threw);

echo "-- Take it off the page --\n";
$before = $filed($root_doc);
$res = kop_dp_apply($root_doc, $home, array('dp_action' => 'remove', 'dp_rows' => array('r:' . $home)));
$check('the filing is gone', !in_array($home, $filed($root_doc), true));
$check('the message says it is off the page', strpos($res['message'], 'off this page') !== false, $res['message']);
list($src, $parts) = kop_ie_resolve($res['undo']);
call_user_func($src['save'], $parts, array());
$check('undo files it again', $filed($root_doc) === $before);

echo "-- A document only listed here (a tag) --\n";
$tag = $pdo->query("SELECT t.folder_id, t.attachment_id FROM wpdl_kop_media_folder_tags t
    JOIN wpdl_posts p ON p.ID = t.attachment_id AND p.post_type = 'attachment'
    WHERE EXISTS (SELECT 1 FROM wpdl_fbv_attachment_folder r WHERE r.attachment_id = t.attachment_id AND r.folder_id <> t.folder_id)
    ORDER BY t.attachment_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($tag) {
    $att = (int) $tag['attachment_id'];
    $tag_home = (int) $tag['folder_id'];
    $f_before = $filed($att);
    $t_before = $tagged($att);
    $fields = $values(kop_dp_fields($att, $tag_home));
    $check('the tag is listed as "Also listed in"', strpos(json_encode($fields['dp_rows']['options']), 'Also listed in') !== false);
    $res = kop_dp_apply($att, $tag_home, array('dp_action' => 'move', 'dp_rows' => array('t:' . $tag_home), 'dp_target' => 'd:' . $target));
    $check('a tag moves as a tag', in_array($target, $tagged($att), true) && !in_array($tag_home, $tagged($att), true));
    $check('its filing is left alone', $filed($att) === $f_before);
    list($src, $parts) = kop_ie_resolve($res['undo']);
    call_user_func($src['save'], $parts, array());
    $check('undo restores the tag', $tagged($att) === $t_before && $filed($att) === $f_before);
} else {
    echo "SKIP no tagged document in the mirror\n";
}

echo "-- Identical copies on one page --\n";
$copies = null;
$last = null;
foreach ($pdo->query("SELECT r.folder_id, r.attachment_id, m.meta_value AS h FROM wpdl_fbv_attachment_folder r
    JOIN wpdl_postmeta m ON m.post_id = r.attachment_id AND m.meta_key = 'mdd_hash' AND m.meta_value <> ''
    ORDER BY m.meta_value, r.attachment_id") as $row) {
    if ($last && $last['h'] === $row['h'] && (int) $last['folder_id'] === (int) $row['folder_id']) {
        $copies = array((int) $row['folder_id'], (int) $last['attachment_id'], (int) $row['attachment_id']);
        break;
    }
    $last = $row;
}
if ($copies) {
    list($cf, $a, $b) = $copies;
    $found = kop_dp_copies_here($a, kop_dp_page_folders($cf)['all']);
    $check('finds the identical copy on the same page', in_array($b, $found, true), "#$a and #$b in folder #$cf");
    $fields = $values(kop_dp_fields($a, $cf));
    $check('offers to take the copies along', isset($fields['dp_copies']));
    $res = kop_dp_apply($a, $cf, array('dp_action' => 'remove', 'dp_rows' => array('r:' . $cf), 'dp_copies' => true));
    $check('both copies leave the page', !in_array($cf, $filed($a), true) && !in_array($cf, $filed($b), true), $res['message']);
    list($src, $parts) = kop_ie_resolve($res['undo']);
    call_user_func($src['save'], $parts, array());
    $check('undo brings both back', in_array($cf, $filed($a), true) && in_array($cf, $filed($b), true));
} else {
    echo "SKIP no identical copies filed in one folder\n";
}

echo "-- Deleting keeps files another record uses --\n";
$pair = $pdo->query("SELECT pdf.post_id AS pdf, jpg.post_id AS jpg, jpg.meta_value AS path
    FROM wpdl_postmeta jpg
    JOIN wpdl_postmeta pdf ON pdf.meta_key = '_wp_attachment_metadata'
         AND pdf.meta_value LIKE '%\"' || REPLACE(jpg.meta_value, RTRIM(jpg.meta_value, REPLACE(jpg.meta_value, '/', '')), '') || '\"%'
         AND pdf.post_id <> jpg.post_id
    WHERE jpg.meta_key = '_wp_attached_file' AND jpg.meta_value LIKE '%-pdf.jpg'
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($pair) {
    $base = basename($pair['path']);
    $check("a PDF keeps its preview JPG that is its own record", isset(kop_dp_shared_files((int) $pair['pdf'])[$base]), "#{$pair['pdf']} / $base");
    $check('the preview record keeps the file the PDF uses', isset(kop_dp_shared_files((int) $pair['jpg'])[$base]));
} else {
    echo "SKIP no PDF preview registered as its own record\n";
}
$lone = (int) $pdo->query("SELECT a.post_id FROM wpdl_postmeta a WHERE a.meta_key = '_wp_attached_file'
    AND NOT EXISTS (SELECT 1 FROM wpdl_postmeta b WHERE b.meta_key = '_wp_attached_file' AND b.meta_value = a.meta_value AND b.post_id <> a.post_id)
    AND a.meta_value LIKE '%.pdf' ORDER BY a.post_id LIMIT 1")->fetchColumn();
$check('a file nobody else uses is deleted with it', !isset(kop_dp_shared_files($lone)[basename((string) get_post_meta($lone, '_wp_attached_file', true))]), "#$lone");

echo "\n" . ($failures ? "$failures FAILED" : 'All passed') . "\n";
exit($failures ? 1 : 0);
