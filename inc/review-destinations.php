<?php
/**
 * Where a link sent in for review can be reclassified to, shared by every
 * review inbox source (inc/review-inbox/*.php) that offers "Move to":
 *
 *   news         a pending news submission (the news queue)
 *   lawsuit      a pending lawsuit row
 *   legislation  a pending legislation row
 *   promo        Industry PR: a news row filed as 'promotional' (the internal
 *                index of the facilities' own marketing; never public)
 *   website      the program's own website on a facility record (profileLinks)
 *   resource     "Materials and links" on a facility record (resourceLinks)
 *
 * The queue rows go in through kop_ext_insert_*() (the browser extension's
 * inserts, with its duplicate check) without emailing admins; the record
 * links through the Drive Docs save path (kop_gdl_save()), as the Submissions
 * Review "Move to facility record" button did. Each put returns what it did,
 * and kop_rdest_take_back() undoes it while nobody has reviewed the new row.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Destinations: id => [label, needs_facility]. */
function kop_rdest_targets() {
    return array(
        'news'        => array('label' => 'News', 'needs_facility' => false),
        'lawsuit'     => array('label' => 'Lawsuits', 'needs_facility' => false),
        'legislation' => array('label' => 'Legislation', 'needs_facility' => false),
        'promo'       => array('label' => 'Industry PR', 'needs_facility' => false),
        'indigenous'  => array('label' => 'Indian boarding schools', 'needs_facility' => false),
        'young_adult' => array('label' => 'Young adult programs (18+)', 'needs_facility' => false),
        'website'     => array('label' => 'Facility website', 'needs_facility' => true),
        'resource'    => array('label' => 'Facility resource (Materials and links)', 'needs_facility' => true),
    );
}

/**
 * "Move to" entries for a card, leaving out $except (the queue the item is in).
 * Facility destinations ask for the facility; a resource also asks its kind.
 */
function kop_rdest_moves(array $except = array(), $default_fid = 0) {
    $kinds = function_exists('kop_facility_resource_link_kinds') ? kop_facility_resource_link_kinds() : array('other' => 'Other links');
    $out = array();
    foreach (kop_rdest_targets() as $id => $t) {
        if (in_array($id, $except, true)) continue;
        $move = array('id' => $id, 'label' => 'Move to ' . $t['label']);
        if ($t['needs_facility']) {
            $move['params'] = array(array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => (int) $default_fid));
            if ($id === 'resource') {
                $move['params'][] = array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => $kinds, 'value' => 'reference');
            }
        }
        if ($id === 'indigenous') {
            $move['params'] = array(array('name' => 'school_id', 'label' => 'About', 'type' => 'select', 'options' => kop_rdest_school_options(), 'value' => '0'));
        }
        if ($id === 'young_adult') {
            $move['params'] = array(
                array('name' => 'ya_id', 'label' => 'Program', 'type' => 'select', 'options' => kop_rdest_ya_options(), 'value' => '0'),
                array('name' => 'ya_name', 'label' => 'or new program named', 'type' => 'text', 'value' => '', 'optional' => true),
            );
        }
        $out[] = $move;
    }
    return $out;
}

/** Choices for "Young adult programs": one on file, or a new one by name (0). */
function kop_rdest_ya_options() {
    $options = array('0' => 'A new program (name it below)');
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    if ($pdo) {
        foreach (kop_ya_all($pdo, null) as $p) {
            $options[(string) (int) $p['id']] = (string) $p['name'];
        }
    }
    return $options;
}

/** Put the article's link on a young adult program's links (a new program waits for review). */
function kop_rdest_put_young_adult(array $p, $reviewer) {
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    if (!$pdo) throw new RuntimeException('The young adult programs records are not available.');
    kop_ya_install($pdo);
    $url = trim((string) $p['url']);
    $label = trim(str_replace('|', '-', (string) ($p['title'] ?? ''))) ?: $url;
    $line = $label . ' | ' . $url;
    $id = (int) ($p['ya_id'] ?? 0);
    $created = false;
    if ($id > 0) {
        $prog = kop_ya_get($pdo, $id);
        if (!$prog) throw new RuntimeException('That program is gone.');
    } else {
        $name = trim((string) ($p['ya_name'] ?? ''));
        if ($name === '') throw new RuntimeException('Pick the program, or type a name for a new one.');
        $prog = kop_ya_find_by_name($pdo, $name);
        if ($prog) {
            $id = (int) $prog['id'];
        } else {
            $id = kop_ya_save($pdo, array('name' => $name, 'review' => 'pending', 'links' => $line,
                'source' => 'Sent from the review inbox: ' . $label), 0, $reviewer);
            $created = true;
        }
    }
    if (!$created) {
        $have = kop_ya_lines($prog['links']);
        foreach (kop_ya_parse_links($prog['links']) as $l) {
            if ($l[1] === $url) throw new RuntimeException('That link is already on ' . $prog['name'] . '.');
        }
        $have[] = $line;
        kop_ya_save($pdo, array('name' => $prog['name'], 'other_names' => $prog['other_names'], 'city' => $prog['city'], 'state' => $prog['state'],
            'country' => $prog['country'], 'ages' => $prog['ages'], 'program_type' => $prog['program_type'], 'run_by' => $prog['run_by'],
            'opened' => $prog['opened'], 'closed' => $prog['closed'], 'status' => $prog['status'], 'notes' => $prog['notes'],
            'links' => implode("
", $have)), $id, $reviewer);
        $prog = kop_ya_get($pdo, $id);
    }
    return array('to' => 'young_adult', 'id' => $id, 'url' => $url, 'line' => $line, 'created' => $created,
        'message' => ($created ? 'Made the young adult program "' . $prog['name'] . '" (waiting for review) and put the link on it.'
            : 'Put the link on the young adult program "' . $prog['name'] . '".'));
}

/** Undo kop_rdest_put_young_adult(): take the link line off, and the program too when it made it and holds nothing else. */
function kop_rdest_take_back_young_adult(array $done) {
    $pdo = kop_ya_pdo();
    $prog = $pdo ? kop_ya_get($pdo, (int) $done['id']) : null;
    if (!$prog) return;
    $left = array_values(array_filter(kop_ya_lines($prog['links']), function ($l) use ($done) { return $l !== $done['line']; }));
    if (!empty($done['created']) && !$left && $prog['review'] === 'pending' && trim((string) $prog['facts']) === '[]') {
        kop_ya_delete($pdo, (int) $prog['id']);
        return;
    }
    $pdo->prepare('UPDATE young_adult_programs SET links = ?, updated_at = ? WHERE id = ?')->execute(array(implode("
", $left), kop_ya_now(), (int) $prog['id']));
}

/** Choices for "Indian boarding schools": a school on file, or the schools in general (0). */
function kop_rdest_school_options() {
    $options = array('0' => 'The schools in general');
    $pdo = function_exists('kop_ischools_pdo') ? kop_ischools_pdo() : null;
    if ($pdo) {
        foreach (kop_ischools_all($pdo, null) as $s) {
            $options[(string) (int) $s['id']] = (string) $s['name'];
        }
    }
    return $options;
}

/** news_submissions.status gained 'promotional'; tables made before it reject the value (as kop_news_status_enum_ensure()). */
function kop_rdest_promo_enum_ensure(PDO $pdo) {
    try {
        $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_submissions' AND COLUMN_NAME = 'status'")->fetchColumn();
    } catch (Throwable $e) {
        return; // not MySQL (the offline tests): any value is accepted
    }
    if (!$type || strpos($type, "'promotional'") !== false) return;
    $pdo->exec("ALTER TABLE news_submissions
        MODIFY status ENUM('draft','submitted','approved','published','rejected','deleted','promotional')
        NOT NULL DEFAULT 'submitted'");
}

/**
 * Put a link where $to says. $p: url, title, site_name, published (optional),
 * facility_id and kind (facility destinations), note (why it is here).
 * Returns ['to', 'id' (queue row) or 'facility_id', 'url', 'kind', 'facility_name', 'message'].
 * Throws RuntimeException with a reason a person can act on.
 */
function kop_rdest_put($to, array $p, $reviewer) {
    $targets = kop_rdest_targets();
    if (!isset($targets[$to])) throw new RuntimeException('Pick where it goes.');
    $url = trim((string) ($p['url'] ?? ''));
    if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('It has no web address to move.');
    if ($targets[$to]['needs_facility']) return kop_rdest_put_on_record($to, $p, $reviewer);

    if (!function_exists('kop_ext_insert_news')) throw new RuntimeException('The queue inserts are not loaded.');
    kop_ext_load_record_libs();
    $pdo = kop_rinbox_pdo();
    if ($to === 'young_adult') return kop_rdest_put_young_adult($p, $reviewer);
    $type = array('news' => 'article', 'promo' => 'article', 'indigenous' => 'article', 'lawsuit' => 'lawsuit', 'legislation' => 'legislation')[$to];
    $q = array(
        'url' => $url, 'title' => trim((string) ($p['title'] ?? '')) ?: $url, 'type' => $type,
        'site_name' => (string) ($p['site_name'] ?? (parse_url($url, PHP_URL_HOST) ?: '')), 'facility' => (string) ($p['facility'] ?? ''),
    );
    if (!empty($p['published'])) $q['published'] = (string) $p['published'];
    foreach (kop_ext_find_duplicates($pdo, $q) as $d) {
        if (!empty($p['self']) && $d['type'] === $p['self'][0] && (int) $d['id'] === (int) $p['self'][1]) continue;
        throw new RuntimeException('Already in the ' . $d['type'] . ' records (#' . (int) $d['id'] . ').');
    }
    $submitter = mb_substr($reviewer . ' (' . ($p['via'] ?? 'review inbox') . ')', 0, 255);
    $note = (string) ($p['note'] ?? '');
    $quiet = function () { return false; };
    add_filter('kop_notify_admins_enabled', $quiet);
    try {
        if ($to === 'lawsuit') $id = kop_ext_insert_lawsuit($pdo, $q, $submitter, $note);
        elseif ($to === 'legislation') $id = kop_ext_insert_legislation($pdo, $q, $submitter, $note);
        else $id = kop_ext_insert_news($pdo, $q, $submitter, $note);
    } finally {
        remove_filter('kop_notify_admins_enabled', $quiet);
    }
    if ($to === 'promo') {
        kop_rdest_promo_enum_ensure($pdo);
        $pdo->prepare("UPDATE news_submissions SET status = 'promotional', reviewed_by = ? WHERE id = ?")->execute(array($reviewer, (int) $id));
    }
    if ($to === 'indigenous') {
        // An article about an Indian boarding school: filed on its page, approved, off the news list.
        $school_id = (int) ($p['school_id'] ?? 0);
        $pdo_s = kop_ischools_pdo();
        if (!$pdo_s) throw new RuntimeException('The Indian boarding schools records are not available.');
        kop_ischools_install($pdo_s);
        if ($school_id > 0 && !kop_ischools_get($pdo_s, $school_id)) throw new RuntimeException('That school is gone.');
        kop_ischools_link_news($pdo_s, $school_id, (int) $id, $reviewer);
        $pdo->prepare("UPDATE news_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute(array($reviewer, (int) $id));
        return array('to' => 'indigenous', 'id' => (int) $id, 'school_id' => $school_id, 'url' => $url,
            'message' => 'Filed on the Indian boarding schools page as article #' . (int) $id . ' (off the news list).');
    }
    $where = $to === 'promo' ? 'the Industry PR index (internal, never public)' : 'the ' . $targets[$to]['label'] . ' queue, waiting for review';
    return array('to' => $to, 'id' => (int) $id, 'url' => $url, 'message' => 'Moved to ' . $where . ' as #' . (int) $id . '.');
}

/** The website or "Materials and links" of a facility record (the old refile button's path). */
function kop_rdest_put_on_record($to, array $p, $reviewer) {
    $fid = (int) ($p['facility_id'] ?? 0);
    if ($fid <= 0) throw new RuntimeException('Pick the facility first.');
    if (!function_exists('kop_gdl_opts')) throw new RuntimeException('The facility record writer is not loaded.');
    $kinds = kop_facility_resource_link_kinds();
    $kind = (string) ($p['kind'] ?? 'other');
    $kind = isset($kinds[$kind]) ? $kind : 'other';
    $url = trim((string) $p['url']);
    $label = trim((string) ($p['title'] ?? '')) ?: $url;
    $source = (string) ($p['source_note'] ?? 'Sent in for review');
    $target = $to === 'website' ? 'website' : 'resource';
    $moved = array('target' => $target, 'facility_id' => $fid, 'url' => $url, 'kind' => $kind);
    $opts = kop_gdl_opts();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $opts, $url, $kind, $label, $target, $source, &$moved) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        $doc = $stored['doc'];
        $key = kop_gdl_url_key($url);
        foreach (array_merge((array) ($doc['profileLinks'] ?? array()), array_column((array) ($doc['resourceLinks'] ?? array()), 'url')) as $have) {
            if (is_string($have) && kop_gdl_url_key($have) === $key) throw new RuntimeException('That link is already on the record.');
        }
        if ($target === 'website') {
            $doc['profileLinks'][] = $url;
        } else {
            $doc['resourceLinks'][] = array('url' => $url, 'label' => $label, 'kind' => $kind, 'source' => $source);
        }
        kop_gdl_save($doc, $opts);
        $moved['facility_name'] = (string) ($doc['identification']['name'] ?? $stored['unique_name']);
    });
    $what = $target === 'website' ? 'its program website' : 'a resource (' . $kinds[$kind] . ')';
    return $moved + array('to' => $to, 'message' => 'Put on ' . $moved['facility_name'] . ' as ' . $what . '.');
}

/** Undo kop_rdest_put(): take the new row back while nobody has reviewed it, or the link off the record. */
function kop_rdest_take_back(array $done) {
    $to = (string) ($done['to'] ?? ($done['target'] ?? ''));
    if ($to === 'website' || $to === 'resource') {
        $opts = kop_gdl_opts();
        kop_v2_with_write_lock($opts['pdo'], function () use ($done, $opts) {
            $stored = kop_facility_load((int) $done['facility_id'], $opts);
            if (!$stored) return; // the record is gone; nothing to take back
            $doc = $stored['doc'];
            kop_gdl_doc_remove($doc, array('target' => $done['target'] ?? ($done['to'] === 'website' ? 'website' : 'resource'), 'url' => $done['url']));
            kop_gdl_save($doc, $opts);
        });
        return;
    }
    $pdo = kop_rinbox_pdo();
    $id = (int) ($done['id'] ?? 0);
    if ($to === 'young_adult') {
        kop_rdest_take_back_young_adult($done);
        return;
    }
    if ($to === 'indigenous') {
        $pdo_s = kop_ischools_pdo();
        if ($pdo_s) kop_ischools_unlink_news($pdo_s, (int) ($done['school_id'] ?? 0), $id);
        $st = $pdo->prepare("UPDATE news_submissions SET status = 'deleted' WHERE id = ? AND status = 'approved'");
        $st->execute(array($id));
        return;
    }
    if ($to === 'news' || $to === 'promo') {
        $st = $pdo->prepare("UPDATE news_submissions SET status = 'deleted' WHERE id = ? AND status IN ('submitted', 'promotional')");
        $st->execute(array($id));
        if (!$st->rowCount()) throw new RuntimeException('News item #' . $id . ' has been reviewed already, so it stays there.');
        return;
    }
    $table = $to === 'lawsuit' ? 'lawsuits' : 'legislation';
    $st = $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND publication_status = 'pending'");
    $st->execute(array($id));
    if (!$st->rowCount()) throw new RuntimeException(ucfirst($to) . ' #' . $id . ' has been reviewed already, so it stays there.');
    if ($table === 'lawsuits') $pdo->prepare('DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ?')->execute(array($id));
}
