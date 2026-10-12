<?php
/**
 * Nominations for Essential reporting (inc/featured-reporting.php): stored
 * articles suggested as especially good journalism, each with a draft note
 * and the reason it was put forward, waiting for the owner at Submissions
 * Review > Nominated (inc/review-inbox/featured-nominations.php).
 *
 * Feature picks the article with kop_feat_set() (the note as edited on the
 * card) and keeps that pick's log entry, so Undo is kop_feat_undo() of exactly
 * that change; Pass over changes nothing on the site. Both go back to
 * Nominated with Undo.
 *
 * Table news_featured_nominations in the records database, beside
 * news_featured. New nominations ship in js/data/featured/nominations.json:
 * a higher 'version' adds the ones not there yet; a nomination already in the
 * table (decided or not) is never changed by the file.
 *
 * Tested by scripts/test-review-inbox.php --source=featured-nominations.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_featnom_install(PDO $pdo) {
    static $done = array();
    $key = spl_object_hash($pdo);
    if (isset($done[$key])) return true;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? "CREATE TABLE IF NOT EXISTS news_featured_nominations (news_id INTEGER PRIMARY KEY, note TEXT, reason TEXT, tier INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'nominated', nominated_by TEXT, created_at TEXT NOT NULL, decided_by TEXT, decided_at TEXT, feat_entry TEXT)"
        : "CREATE TABLE IF NOT EXISTS news_featured_nominations (
            news_id int(11) NOT NULL,
            note text,
            reason text,
            tier tinyint NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'nominated',
            nominated_by varchar(190) DEFAULT '',
            created_at datetime NOT NULL,
            decided_by varchar(190) DEFAULT '',
            decided_at datetime DEFAULT NULL,
            feat_entry varchar(40) DEFAULT '',
            PRIMARY KEY (news_id),
            KEY status (status)
          ) DEFAULT CHARSET=utf8mb4 COMMENT='Articles nominated for essential reporting (inc/featured-nominations.php)'");
    return $done[$key] = true;
}

function kop_featnom_statuses() {
    return array('nominated' => 'Nominated', 'featured' => 'Featured', 'passed' => 'Passed over');
}

function kop_featnom_seed_file() {
    return dirname(__DIR__) . '/js/data/featured/nominations.json';
}

/**
 * Adds the file's nominations that are not in the table yet, once per file
 * version. Returns how many were added (0 when the version was already read).
 */
function kop_featnom_seed(PDO $pdo, $file = null, $force = false) {
    $file = $file ?: kop_featnom_seed_file();
    if (!is_readable($file)) return 0;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || empty($data['nominations'])) return 0;
    $version = (string) ($data['version'] ?? '0');
    if (!$force && (string) get_option('kop_featured_nominations_seed', '') === $version) return 0;
    kop_featnom_install($pdo);
    $have = $pdo->prepare('SELECT 1 FROM news_featured_nominations WHERE news_id = ?');
    $exists = $pdo->prepare('SELECT 1 FROM news_submissions WHERE id = ?');
    $add = $pdo->prepare('INSERT INTO news_featured_nominations (news_id, note, reason, tier, status, nominated_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($data['nominations'] as $nom) {
        $id = (int) ($nom['news_id'] ?? 0);
        if ($id <= 0) continue;
        $have->execute(array($id));
        if ($have->fetchColumn()) continue;
        $exists->execute(array($id));
        if (!$exists->fetchColumn()) continue; // not on this site's records
        $add->execute(array($id, kop_feat_clean_note((string) ($nom['note'] ?? '')), trim((string) ($nom['reason'] ?? '')),
            max(1, (int) ($nom['tier'] ?? 1)), 'nominated', 'nominations file v' . $version, kop_feat_now()));
        $n++;
    }
    update_option('kop_featured_nominations_seed', $version, false);
    return $n;
}

/** One nomination with its article, or null. */
function kop_featnom_row(PDO $pdo, $news_id) {
    kop_featnom_install($pdo);
    $st = $pdo->prepare(kop_featnom_select_sql() . ' WHERE m.news_id = ?');
    $st->execute(array((int) $news_id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_featnom_select_sql() {
    return 'SELECT m.*, n.article_title, n.alternate_title, n.publication_name, n.author, n.publication_date, n.article_url,
                   n.archive_url, n.summary, n.status AS article_status
              FROM news_featured_nominations m LEFT JOIN news_submissions n ON n.id = m.news_id';
}

/** Nominations in one status, best tier first, then newest article first. */
function kop_featnom_rows(PDO $pdo, $status, $search = '', $offset = 0, $limit = 25, array $skip = array()) {
    kop_featnom_install($pdo);
    $where = array('m.status = ?');
    $args = array($status);
    if ($search !== '') {
        $where[] = '(n.article_title LIKE ? OR n.alternate_title LIKE ? OR n.publication_name LIKE ? OR n.author LIKE ? OR m.note LIKE ?)';
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if ($skip) {
        $where[] = 'm.news_id NOT IN (' . implode(',', array_map('intval', $skip)) . ')';
    }
    $sql_where = ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM news_featured_nominations m LEFT JOIN news_submissions n ON n.id = m.news_id' . $sql_where);
    $count->execute($args);
    $order = $status === 'nominated'
        ? ' ORDER BY m.tier ASC, n.publication_date DESC, m.news_id DESC'
        : ' ORDER BY m.decided_at DESC, m.news_id DESC';
    $st = $pdo->prepare(kop_featnom_select_sql() . $sql_where . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array('rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn());
}

function kop_featnom_counts(PDO $pdo) {
    kop_featnom_install($pdo);
    $out = array_fill_keys(array_keys(kop_featnom_statuses()), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM news_featured_nominations GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($out[$r['status']])) $out[$r['status']] = (int) $r['c'];
    }
    return $out;
}

function kop_featnom_decide(PDO $pdo, $news_id, $status, $who, $entry = '') {
    $pdo->prepare('UPDATE news_featured_nominations SET status = ?, decided_by = ?, decided_at = ?, feat_entry = ? WHERE news_id = ?')
        ->execute(array($status, $status === 'nominated' ? '' : (string) $who, $status === 'nominated' ? null : kop_feat_now(), (string) $entry, (int) $news_id));
}

/** Features the article with $note. Returns the message for the card. */
function kop_featnom_feature(PDO $pdo, $news_id, $note, $who) {
    $row = kop_featnom_row($pdo, $news_id);
    if (!$row) throw new RuntimeException('That nomination is gone.');
    if ($row['status'] !== 'nominated') throw new RuntimeException('This one was already decided; Undo it first.');
    $note = kop_feat_clean_note($note);
    $entry = kop_feat_set($pdo, $news_id, $note, $who); // refuses an article that is not approved
    $pdo->prepare('UPDATE news_featured_nominations SET note = ? WHERE news_id = ?')->execute(array($note, (int) $news_id));
    kop_featnom_decide($pdo, $news_id, 'featured', $who, $entry ? $entry['id'] : '');
    return 'Featured: it is on the Essential reporting page and flagged wherever the article is listed. Undo is on the Featured tab.';
}

function kop_featnom_pass(PDO $pdo, $news_id, $who) {
    $row = kop_featnom_row($pdo, $news_id);
    if (!$row) throw new RuntimeException('That nomination is gone.');
    if ($row['status'] !== 'nominated') throw new RuntimeException('This one was already decided; Undo it first.');
    kop_featnom_decide($pdo, $news_id, 'passed', $who);
    return 'Passed over. Nothing on the site changed; Back to nominated is on the Passed over tab.';
}

/** Back to Nominated; a pick made from here is taken back exactly (kop_feat_undo). */
function kop_featnom_undo(PDO $pdo, $news_id, $who) {
    $row = kop_featnom_row($pdo, $news_id);
    if (!$row) throw new RuntimeException('That nomination is gone.');
    if ($row['status'] === 'nominated') return 'It is already waiting.';
    $msg = 'Back with the nominations.';
    if ($row['status'] === 'featured' && $row['feat_entry'] !== '' && $row['feat_entry'] !== null) {
        kop_feat_undo($pdo, (string) $row['feat_entry'], $who); // refused when the pick was changed since
        $msg = 'Taken off Essential reporting and back with the nominations.';
    } elseif ($row['status'] === 'featured') {
        $msg = 'Back with the nominations. It was already featured before, so it stays featured.';
    }
    kop_featnom_decide($pdo, $news_id, 'nominated', $who);
    return $msg;
}

/** Nominated articles that are featured already (picked with the pencil or at KOP Tools): news id => true. */
function kop_featnom_already_featured(PDO $pdo) {
    kop_featnom_install($pdo);
    $out = array();
    foreach ($pdo->query("SELECT news_id FROM news_featured_nominations WHERE status = 'nominated'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (kop_feat_get((int) $id)) $out[(int) $id] = true;
    }
    return $out;
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options') || !function_exists('kop_feat_pdo')) return;
    $pdo = kop_feat_pdo();
    if (!$pdo) return;
    try {
        kop_featnom_seed($pdo);
    } catch (Throwable $e) {
        error_log('kop featured nominations seed: ' . $e->getMessage());
    }
});
