<?php
/**
 * Review inbox source: facility names the hourly news scan found with no
 * record (inc/facility-discovery.php). Held-back names (possible duplicate,
 * other name era, no place) wait for a person: link the article to an
 * existing record (kop_facdisc_link_by_hand()) or create one from the
 * details (kop_facdisc_create_by_hand()). A record the scan created can be
 * removed again while nobody has edited it (kop_facdisc_remove()).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('facilities-from-news', function () {
    if (!function_exists('kop_facdisc_create_by_hand')) return null;
    $waiting = kop_rinbox_facdisc_waiting();
    return array(
        'label'    => 'Facilities from news',
        'group'    => 'Found by the news scans',
        'help'     => 'Each card is a name the hourly news scan found in an article that matches none of our records exactly. Say what the article is about: '
            . '"Same program" lists the article on the record shown; "A different record" lets you search for the right one; "New program" makes a record; '
            . '"Skip" when the name is not a youth program we track, or the article only names it in passing (a list of a company\'s sites, say). '
            . 'Every choice can be undone from its tab or from Recently done.'
            . ($waiting !== null ? ' ' . $waiting . ' saved articles are not scanned yet; the hourly run reads a handful at a time.' : ''),
        'views'    => array(
            'held'    => 'To decide',
            'matched' => 'Listed on a record',
            'created' => 'New records the scan made',
            'aside'   => 'Skipped',
            'removed' => 'Removed records',
            'all'     => 'All',
        ),
        'view_counts' => 'kop_rinbox_facdisc_view_counts',
        'tools'    => array(array(
            'id' => 'scan', 'label' => 'Scan the next articles now', 'style' => 'neutral',
            'help' => 'Reads saved articles the hourly scan has not reached yet (about 15 at a time). Give article numbers to scan only those.',
            'params' => array(array('name' => 'news', 'label' => 'or only these article numbers', 'type' => 'text', 'value' => '', 'optional' => true)),
        )),
        'tool'     => 'kop_rinbox_facdisc_tool',
        'tool_url' => admin_url('admin.php?page=kop-facilities-from-news'),
        'count'    => function () {
            $pdo = kop_closure_pdo();
            kop_facdisc_ensure_tables($pdo);
            return (int) $pdo->query("SELECT COUNT(*) FROM news_facility_candidates WHERE decision IN ('possible_duplicate','other_era','needs_place','unquoted')")->fetchColumn();
        },
        'list'     => 'kop_rinbox_facdisc_list',
        'get'      => function ($key) {
            $r = kop_rinbox_facdisc_row(kop_closure_pdo(), (int) $key);
            return $r ? kop_rinbox_facdisc_item($r) : null;
        },
        'act'      => 'kop_rinbox_facdisc_act',
        'save'     => 'kop_rinbox_facdisc_save',
    );
});

/** The decisions each view lists ('all' lists every one). */
function kop_rinbox_facdisc_view_decisions() {
    return array(
        'held'    => array('possible_duplicate', 'other_era', 'needs_place', 'unquoted'),
        'matched' => array('matched'),
        'created' => array('created'),
        'aside'   => array('provider', 'not_facility', 'indigenous_school', 'young_adult'),
        'removed' => array('removed'),
    );
}

function kop_rinbox_facdisc_view_counts(array $q = array()) {
    $by = array();
    foreach (kop_closure_pdo()->query('SELECT decision, COUNT(*) AS n FROM news_facility_candidates GROUP BY decision') as $row) $by[$row['decision']] = (int) $row['n'];
    $out = array();
    foreach (kop_rinbox_facdisc_view_decisions() as $view => $decisions) {
        $out[$view] = 0;
        foreach ($decisions as $d) $out[$view] += $by[$d] ?? 0;
    }
    $out['all'] = array_sum($by);
    return $out;
}

/** Saved articles the scan has not read yet, as the old screen counts them (cached a minute), or null. */
function kop_rinbox_facdisc_waiting() {
    $n = get_transient('kop_rinbox_facdisc_waiting');
    if ($n !== false) return (int) $n;
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) return null;
        kop_facdisc_ensure_tables($pdo);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_facility_scans s ON s.news_id = n.id
                                 WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    set_transient('kop_rinbox_facdisc_waiting', $n, MINUTE_IN_SECONDS);
    return $n;
}

/** The old screen's scan form: the next articles, or only the article numbers given; one line per name it decided. */
function kop_rinbox_facdisc_tool($id, array $params) {
    if ($id !== 'scan') throw new RuntimeException('Unknown tool.');
    $pdo = kop_closure_pdo();
    $decisions = kop_facdisc_decisions();
    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,#]+/', (string) ($params['news'] ?? '')))));
    $lines = array();
    $log = static function ($news, $outcome, $decided, $detail) use (&$lines, $decisions) {
        if ($outcome === 'error') $lines[] = '#' . $news['id'] . ': failed (' . $detail . ')';
        foreach ($decided as $name => $d) {
            $lines[] = '#' . $news['id'] . ': ' . $name . ' - ' . ($decisions[$d[0]] ?? $d[0]) . ($d[1] ? ' #' . $d[1] : '');
        }
    };
    $r = kop_facdisc_scan_batch($pdo, $ids ? count($ids) : 15, 110, true, $ids, $log);
    delete_transient('kop_rinbox_facdisc_waiting');
    $c = $r['counts'];
    $msg = sprintf('Scanned %d articles: %d new facilities, %d closure reports matched, %d failed%s.',
        $c['scanned'], $c['created'], $c['rematched'] ?? 0, $c['error'],
        $c['error'] ? ' (Groq and Gemini allow a few articles a minute; the hourly run carries on)' : '');
    return array('message' => $msg . ($lines ? "\n" . implode("\n", array_slice($lines, 0, 40)) : ''));
}

/** "Closed, ended 2019" for a record as it is now (the old screen's facility cell), or ''. */
function kop_rinbox_facdisc_record_now(PDO $pdo, $fid) {
    $st = $pdo->prepare('SELECT status, end_year FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) $fid));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    return $f ? ($f['status'] ?: 'Unknown') . ($f['end_year'] ? ', ended ' . (int) $f['end_year'] : '') : '';
}

/** The decisions a person can still turn into a record (what kop_facdisc_create_by_hand() accepts). */
function kop_rinbox_facdisc_open_decisions() {
    return array('possible_duplicate', 'other_era', 'needs_place', 'unquoted', 'provider', 'not_facility', 'removed');
}

function kop_rinbox_facdisc_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                           FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE c.id = ?');
    $st->execute(array((int) $id));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_rinbox_facdisc_list(array $q) {
    $pdo = kop_closure_pdo();
    kop_facdisc_ensure_tables($pdo);
    $views = kop_rinbox_facdisc_view_decisions();
    $where = $q['view'] === 'all' ? '1=1'
        : "c.decision IN ('" . implode("','", $views[$q['view']] ?? $views['held']) . "')";
    $params = array();
    if ($q['search'] !== '') {
        $where .= ' AND (c.mention LIKE ? OR n.article_title LIKE ?)';
        $like = '%' . $q['search'] . '%';
        $params = array($like, $like);
    }
    $from = "FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE $where";
    $st = $pdo->prepare("SELECT COUNT(*) $from");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                          $from ORDER BY c.updated_at DESC, c.id DESC LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset']);
    $st->execute($params);
    return array('items' => array_map('kop_rinbox_facdisc_item', $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total);
}

function kop_rinbox_facdisc_item(array $r) {
    $decisions = kop_facdisc_decisions();
    $detail = json_decode((string) $r['detail'], true) ?: array();
    $e = (array) ($detail['entry'] ?? array());
    $place = trim(implode(', ', array_filter(array($e['city'] ?? '', ($e['state'] ?? '') ?: ($e['country'] ?? '')))));
    // A corrected name (Rename, Edit details) heads the card; the wording the article came in with is not shown.
    $renamed = trim((string) ($e['officialName'] ?? ''));
    $bits = array();
    if ($place !== '') $bits[] = $place;
    if (!empty($e['type'])) $bits[] = $e['type'];
    // The record on the card ("Kissimmee Youth Academy (Kissimmee, FL)"), null when there is none or it is gone.
    $fid = (int) $r['facility_id'];
    $now = $fid > 0 ? kop_rinbox_facdisc_record_now(kop_closure_pdo(), $fid) : '';
    $fac = $now !== '' ? kop_rinbox_facility($fid) : null;
    $fac_name = $fac ? (string) $fac['name'] : '';
    $mention = '"' . $r['mention'] . '"';
    $held = in_array($r['decision'], kop_rinbox_facdisc_view_decisions()['held'], true);

    // One plain sentence on where the card stands, then what the article itself says.
    $lines = array();
    switch ($r['decision']) {
        case 'possible_duplicate':
            $lines[] = 'The article names ' . $mention . '.' . ($fac ? ' The closest record we have is ' . $fac_name . '. Is it the same program?' : '');
            break;
        case 'other_era':
            $lines[] = 'The article names ' . $mention . ($fac ? ', which may be an earlier or later name of ' . $fac_name . '. Each name keeps its own record.' : '.');
            break;
        case 'needs_place':
            $lines[] = 'The article names ' . $mention . ' but does not say where it is.';
            break;
        case 'unquoted':
            $lines[] = $mention . ' is not in the article the scan read, so the article is probably not about it.'
                . ($fac ? ' The closest record is ' . $fac_name . '.' : '');
            break;
        case 'matched':
            $lines[] = 'This article is listed on the page of ' . ($fac_name ?: 'a record that is gone') . '.';
            break;
        case 'created':
            $lines[] = 'The scan made a new record, ' . ($fac_name ?: $r['mention']) . ', from this article.';
            break;
        case 'provider':
        case 'not_facility':
            $lines[] = 'Skipped: ' . $mention . ' is not a youth program we track.';
            break;
    }
    if (!empty($e['noText'])) {
        $lines[] = 'Careful: the scan could not open this article and only saw its title and summary.';
    }
    $evidence = trim((string) ($e['evidence'] ?? ''));
    // A "quote" the scan could not find in the article is the model's own words: never shown as a quote.
    if ($evidence !== '' && (!isset($e['evidenceQuoted']) || $e['evidenceQuoted'])) {
        $lines[] = 'The article says: "' . kop_rinbox_excerpt($evidence, 2000) . '"';
    } elseif ($held) {
        $lines[] = 'No sentence from the article mentions it; open the article to check.';
    }

    $open = in_array($r['decision'], kop_rinbox_facdisc_open_decisions(), true);
    $actions = array();
    $fields = array();
    $links = array();
    if ($open) {
        // "Same program" is the main button only when the article was read and quotes the name.
        $solid = $fac && empty($e['noText']) && (!isset($e['evidenceQuoted']) || $e['evidenceQuoted']) && $r['decision'] !== 'unquoted';
        if ($fac) {
            $actions[] = array('id' => 'link', 'label' => 'Same program: list it on ' . $fac_name, 'style' => $solid ? 'approve' : 'neutral',
                'help' => 'Puts this article on the page of ' . $fac_name . ', and later articles that name ' . $mention . ' go there too. Nothing else changes; Undo is on the "Listed on a record" tab.');
        }
        $actions[] = array('id' => 'link_other', 'label' => $fac ? 'A different record…' : 'It is a record we have…', 'style' => 'neutral', 'ask' => true,
            'submit' => 'List the article on this record',
            'help' => 'Search for the record this article is about and pick it from the list. The article goes on its page, and later articles that name ' . $mention . ' go there too.',
            'params' => array(array('name' => 'facility_id', 'label' => 'Record', 'type' => 'facility', 'value' => '')));
        $new_name = (string) (($e['officialName'] ?? '') ?: $r['mention']);
        $types = kop_rinbox_options(array_combine(kop_facdisc_types(), kop_facdisc_types()));
        $actions[] = array('id' => 'create', 'label' => 'New program…', 'style' => 'neutral', 'ask' => true,
            'submit' => 'Create the record',
            'help' => 'Makes a new facility record with its own page and lists this article there. Check the name and place first. If that name and place already have a record, the article goes on it instead.',
            'params' => array(
                array('name' => 'officialName', 'label' => 'Name', 'type' => 'text', 'value' => $new_name),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? ''), 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => (string) ($e['state'] ?? ''), 'optional' => true),
                array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => (string) ($e['country'] ?? ''), 'optional' => true),
                array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => (string) ($e['type'] ?? ''), 'optional' => true),
            ));
        $actions[] = array('id' => 'create_home', 'label' => 'New home of a program…', 'style' => 'neutral', 'ask' => true,
            'submit' => 'Create the record as a home',
            'help' => 'Makes a facility record for this home or cottage, lists the article there, and ties it to a program through Program Homes: pick the program\'s record, or name a new one. Undo: Remove on the Created tab.',
            'params' => array(
                array('name' => 'officialName', 'label' => 'Home\'s name', 'type' => 'text', 'value' => $new_name),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? ''), 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => (string) ($e['state'] ?? ''), 'optional' => true),
                array('name' => 'home_program', 'label' => 'Program record', 'type' => 'facility', 'value' => '', 'optional' => true),
                array('name' => 'home_program_name', 'label' => 'or new program named', 'type' => 'text', 'value' => '', 'optional' => true),
            ));
        $actions[] = array('id' => 'young_adult', 'label' => 'Young adult program (18+)…', 'style' => 'neutral', 'ask' => true,
            'submit' => 'File as a young adult program',
            'help' => 'Not a facility record: makes a young adult program (people 18 and older) waiting for review at Young Adult Programs, with this article as a link. Undo is on the "Skipped" tab.',
            'params' => array(
                array('name' => 'officialName', 'label' => 'Name', 'type' => 'text', 'value' => $new_name),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? ''), 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'text', 'value' => (string) ($e['state'] ?? ''), 'optional' => true),
            ));
        $fields = array(
            array('name' => 'officialName', 'label' => 'Name', 'type' => 'text', 'value' => $new_name, 'title' => true),
            array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => (string) ($e['city'] ?? '')),
            array('name' => 'state', 'label' => 'State (two letters)', 'type' => 'text', 'value' => (string) ($e['state'] ?? '')),
            array('name' => 'country', 'label' => 'Country', 'type' => 'text', 'value' => (string) ($e['country'] ?? '')),
            array('name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => (string) ($e['type'] ?? ''), 'category' => true),
        );
    }
    if ($held) {
        $actions[] = array('id' => 'dismiss', 'label' => 'Skip: not a program we track', 'style' => 'reject',
            'help' => 'Nothing is listed or created. The name moves to "Skipped" and later scans leave it alone.');
    } elseif ($r['decision'] === 'not_facility' && !empty($detail['dismissed_from'])) {
        $actions[] = array('id' => 'undismiss', 'label' => 'Undo: back to To decide', 'style' => 'undo',
            'help' => 'Nothing on the site changes; the card goes back to "To decide".');
    }
    if ($r['decision'] === 'created') {
        $actions[] = array('id' => 'remove', 'label' => 'Undo: delete the record the scan made', 'style' => 'undo',
            'help' => 'Deletes ' . ($fac_name ?: $r['mention']) . ' and its article links, as long as nobody has edited it; later scans will not make it again.',
            'confirm' => 'Delete the record the scan made for this? The scan will not make it again.');
    } elseif ($r['decision'] === 'matched' && $fid > 0) {
        $actions[] = array('id' => 'unlink', 'label' => 'Undo: take it off ' . ($fac_name ?: 'that record'), 'style' => 'undo',
            'help' => 'Takes the article off that page and puts the card back in "To decide". No record is changed or deleted.');
    } elseif ($r['decision'] === 'young_adult') {
        $actions[] = array('id' => 'undo_young_adult', 'label' => 'Undo: back to To decide', 'style' => 'undo',
            'help' => 'Deletes the young adult program this made while it is still waiting for review and untouched; the card goes back to "To decide".');
        $links[] = array('label' => 'Filed at Young Adult Programs', 'url' => admin_url('admin.php?page=kop-young-adult-programs'));
    } elseif ($r['decision'] === 'indigenous_school') {
        $links[] = array('label' => 'Filed at Indigenous Schools', 'url' => admin_url('admin.php?page=kop-indigenous-schools'));
    }

    $details = array();
    $article = trim((string) ($r['article_title'] ?? '')) ?: 'Article #' . (int) $r['news_id'];
    $source = trim(implode(', ', array_filter(array($r['publication_name'] ?? '', $r['publication_date'] ?? ''))));
    $details[] = array('label' => 'Found in', 'value' => $article . ($source !== '' ? ' (' . $source . ')' : ''), 'url' => (string) ($r['article_url'] ?? ''));
    if ($fid > 0) {
        $label = array('matched' => 'Listed on', 'created' => 'Record it made', 'other_era' => 'Other name of')[$r['decision']] ?? 'Closest record';
        $details[] = array('label' => $label, 'value' => $fac ? $fac_name . ' · ' . $now : 'gone (no such record)', 'url' => $fac ? (string) $fac['url'] : '');
    }
    if (!empty($r['reviewed_by'])) $details[] = array('label' => 'Decided by', 'value' => (string) $r['reviewed_by']);
    return array(
        'key'          => (string) $r['id'],
        'title'        => $renamed !== '' ? $renamed : (string) $r['mention'],
        'details'      => $details,
        'subtitle'     => implode(' · ', $bits),
        'url'          => (string) ($r['article_url'] ?? ''),
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['updated_at'],
        'status'       => $r['decision'],
        'status_label' => $decisions[$r['decision']] ?? $r['decision'],
        'facility'     => $fac,
        'fields'       => $fields,
        'actions'      => $actions,
        'links'        => $links,
    );
}

function kop_rinbox_facdisc_act($key, $action, array $params) {
    $pdo = kop_closure_pdo();
    $id = (int) $key;
    $user = kop_rinbox_reviewer();
    $r = kop_rinbox_facdisc_row($pdo, $id);
    if (!$r) throw new RuntimeException('That name is gone (someone may have handled it).');
    $article = $r['article_title'] ? '"' . $r['article_title'] . '"' : 'the article';
    switch ($action) {
        case 'link':
        case 'link_other':
            // "Same program" takes the record on the card; "A different record" the one picked (a passed id wins either way).
            $fid = (int) ($params['facility_id'] ?? 0);
            if ($fid <= 0 && $action === 'link') $fid = (int) $r['facility_id'];
            if ($fid <= 0) throw new RuntimeException('Search for the record and pick it from the list first.');
            $fid = kop_facdisc_link_by_hand($pdo, $id, $fid, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Listed ' . $article . ' on the page of ' . wp_strip_all_tags(kop_facility_finder_label($pdo, $fid)) . '. Undo is on the "Listed on a record" tab.');
        case 'create':
            // The details saved on the item, or any passed with the button.
            $detail = json_decode((string) $r['detail'], true) ?: array();
            $fields = array_merge((array) ($detail['entry'] ?? array()), array_intersect_key($params, array_flip(array('officialName', 'city', 'state', 'country', 'type'))));
            list($decision, $fid) = kop_facdisc_create_by_hand($pdo, $id, $fields, $user);
            kop_rinbox_flush_counts();
            $label = wp_strip_all_tags(kop_facility_finder_label($pdo, $fid));
            return array('message' => $decision === 'created'
                ? 'Created ' . $label . ' and linked ' . $article . ' to it. Remove (on the Created tab) takes it out again.'
                : 'That name and place is already in the database as ' . $label . ', so ' . $article . ' is linked to it. No new record was made.');
        case 'create_home':
            if (!function_exists('kop_program_homes_group')) throw new RuntimeException('Program Homes is not installed here.');
            $pid = (int) ($params['home_program'] ?? 0);
            $pname = trim(sanitize_text_field((string) ($params['home_program_name'] ?? '')));
            if ($pid <= 0 && $pname === '') throw new RuntimeException('Pick the program\'s record, or type a name for a new one.');
            kop_program_homes_install();
            $fields = array_intersect_key($params, array_flip(array('officialName', 'city', 'state')));
            list($decision, $fid) = kop_facdisc_create_by_hand($pdo, $id, $fields, $user);
            $home = (string) wp_strip_all_tags(kop_facility_finder_label($pdo, $fid));
            if ($pid === $fid) throw new RuntimeException('Pick the program\'s own record, not this one.');
            kop_program_homes_group(array($fid => $home), $pid, $pname, kop_program_homes_opts());
            kop_rinbox_flush_counts();
            return array('message' => 'Made ' . $home . ' and listed it as a home of ' . ($pid > 0 ? wp_strip_all_tags(kop_facility_finder_label($pdo, $pid)) : $pname) . '. Remove (on the Created tab) takes it out again.');
        case 'young_adult':
            if (!in_array($r['decision'], kop_rinbox_facdisc_open_decisions(), true)) throw new RuntimeException('This name is already decided.');
            $detail = json_decode((string) $r['detail'], true) ?: array();
            $e = (array) ($detail['entry'] ?? array());
            $ya = kop_ya_pdo();
            kop_ya_install($ya);
            $name = trim((string) ($params['officialName'] ?? '')) ?: (string) $r['mention'];
            $cite = trim(implode(', ', array_filter(array($r['publication_name'] ?? '', $r['publication_date'] ?? ''))));
            $line = (($r['article_title'] ?? '') !== '' ? str_replace('|', '-', $r['article_title']) : 'Article') . ' | ' . $r['article_url'];
            $existing = kop_ya_find_by_name($ya, $name);
            $yid = $existing ? (int) $existing['id'] : kop_ya_save($ya, array(
                'name' => $name, 'city' => (string) ($params['city'] ?? ($e['city'] ?? '')), 'state' => (string) ($params['state'] ?? ($e['state'] ?? '')),
                'country' => (string) ($e['country'] ?? ''), 'status' => (string) ($e['status'] ?? 'Unknown'), 'run_by' => (string) ($e['operator'] ?? ''),
                'review' => 'pending', 'links' => preg_match('#^https?://#i', (string) $r['article_url']) ? $line : '',
                'source' => 'Found in the news: ' . $cite), 0, $user);
            $detail['dismissed_from'] = $r['decision'];
            $detail['ya_id'] = $yid;
            $detail['ya_made'] = !$existing;
            $pdo->prepare('UPDATE news_facility_candidates SET decision = ?, detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute(array('young_adult', wp_json_encode($detail), $user, $id));
            kop_rinbox_flush_counts();
            return array('message' => ($existing ? 'Filed under the young adult program ' . $existing['name'] : 'Made the young adult program ' . $name . ' (waiting for review)') . '. Undo is on the "Skipped" tab.');
        case 'undo_young_adult':
            $detail = json_decode((string) $r['detail'], true) ?: array();
            if ($r['decision'] !== 'young_adult' || empty($detail['dismissed_from'])) throw new RuntimeException('There is nothing to undo.');
            $ya = kop_ya_pdo();
            $prog = !empty($detail['ya_made']) && $ya ? kop_ya_get($ya, (int) $detail['ya_id']) : null;
            if ($prog && $prog['review'] === 'pending' && trim((string) $prog['facts']) === '[]') kop_ya_delete($ya, (int) $prog['id']);
            $back = (string) $detail['dismissed_from'];
            unset($detail['dismissed_from'], $detail['ya_id'], $detail['ya_made']);
            $pdo->prepare('UPDATE news_facility_candidates SET decision = ?, detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute(array($back, wp_json_encode($detail), $user, $id));
            kop_rinbox_flush_counts();
            return array('message' => 'Back in "To decide".');
        case 'dismiss':
        case 'undismiss':
            $detail = json_decode((string) $r['detail'], true) ?: array();
            if ($action === 'dismiss') {
                if (!in_array($r['decision'], kop_rinbox_facdisc_view_decisions()['held'], true)) {
                    throw new RuntimeException('Only a name still waiting to be decided can be set aside.');
                }
                $detail['dismissed_from'] = $r['decision'];
                $to = 'not_facility';
            } else {
                if ($r['decision'] !== 'not_facility' || empty($detail['dismissed_from'])) {
                    throw new RuntimeException('Only a name set aside here can go back to review.');
                }
                $to = (string) $detail['dismissed_from'];
                unset($detail['dismissed_from']);
            }
            // The same row the scan keeps (kop_facdisc_record()): a set-aside name is known, so later scans skip it.
            $pdo->prepare('UPDATE news_facility_candidates SET decision = ?, detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute(array($to, wp_json_encode($detail), $user, $id));
            kop_rinbox_flush_counts();
            return array('message' => $action === 'dismiss'
                ? 'Skipped. Nothing was listed or created; Undo is on the "Skipped" tab.'
                : 'Back in "To decide".');
        case 'unlink':
            $was = wp_strip_all_tags(kop_facility_finder_label($pdo, (int) $r['facility_id']));
            kop_facdisc_unlink($pdo, $id, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Took ' . $article . ' off the page of ' . $was . '. The card is back in "To decide".');
        case 'remove':
            $was = $r['facility_id'] ? wp_strip_all_tags(kop_facility_finder_label($pdo, (int) $r['facility_id'])) : '"' . $r['mention'] . '"';
            if ($r['facility_id'] && function_exists('kop_program_homes_program_of') && kop_program_homes_program_of((int) $r['facility_id'])) {
                kop_program_homes_remove_home((int) $r['facility_id']);   // a home made here leaves its program's list first
            }
            kop_facdisc_remove($pdo, $id, $user);
            kop_rinbox_flush_counts();
            return array('message' => 'Removed the record for ' . $was . '. The scan will not create it again; it waits on the Removed tab.');
    }
    throw new RuntimeException('Unknown action.');
}

/** Save the details a new record would be made from (the scan's entry in detail). */
function kop_rinbox_facdisc_save($key, array $fields) {
    $pdo = kop_closure_pdo();
    $r = kop_rinbox_facdisc_row($pdo, (int) $key);
    if (!$r) throw new RuntimeException('That name is gone.');
    if (!in_array($r['decision'], kop_rinbox_facdisc_open_decisions(), true)) {
        throw new RuntimeException('This name already has a record; edit the record in the data form instead.');
    }
    $detail = json_decode((string) $r['detail'], true) ?: array();
    $entry = (array) ($detail['entry'] ?? array());
    $changed = false;
    foreach (array('officialName', 'city', 'state', 'country', 'type') as $k) {
        if (!array_key_exists($k, $fields)) continue;
        $v = trim(preg_replace('/\s+/u', ' ', (string) $fields[$k]));
        if ($k === 'officialName' && $v === '') throw new RuntimeException('The name cannot be empty.');
        if ($k === 'state') {
            $v = strtoupper($v);
            if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) throw new RuntimeException('State: write the two-letter code, like UT or NC.');
        }
        if ($k === 'type' && $v !== '' && !in_array($v, kop_facdisc_types(), true)) throw new RuntimeException('Type: pick one from the list.');
        $entry[$k] = mb_substr($v, 0, 255);
        $changed = true;
    }
    if (!$changed) return array('message' => 'Nothing to save.');
    $entry += array('name' => $r['mention'], 'kind' => 'facility');
    $detail['entry'] = $entry;
    $pdo->prepare('UPDATE news_facility_candidates SET detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute(array(wp_json_encode($detail), kop_rinbox_reviewer(), (int) $key));
    return array('message' => 'Saved. Create a new record uses these details.');
}
