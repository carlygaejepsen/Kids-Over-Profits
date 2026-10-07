<?php
/**
 * Pending news, lawsuits and bills that are already in our records. The
 * Submissions Review page leaves them out of Pending and lists them under
 * their own "Already on file" tab, each naming the record that holds it, so
 * the reviewer rejects them in a batch instead of reading them one by one.
 * Defines no source of its own.
 *
 * On file means one of:
 *   - the same address (kop_normalize_url()) on a kept record of any of the
 *     three types: news approved, published or Industry PR; lawsuits and bills
 *     published or saved as drafts (pending and rejected rows are not records)
 *   - the same address in a facility's resourceLinks (its "Materials and
 *     links"); a link only mentioned in notes or a staff source does not count
 *   - news: the same headline from the same outlet (kop_news_find_title_duplicates()'s rule)
 *   - lawsuits: the same case number (with a digit, 5+ characters)
 *   - bills: the same bill number, jurisdiction and session year
 *
 *   kop_on_file_pending($pdo, $type)  [pending id => {label, url}]
 *   kop_on_file_ids($pdo, $type)      the ids alone
 *
 * Cached per type by a fingerprint of the pending rows, the records and the
 * facility documents, so a new submission or a newly kept record is seen at once.
 * Tested by scripts/test-review-on-file.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The three types with something to compare, and where they keep it. */
function kop_on_file_types() {
    return array(
        'news'        => array('table' => 'news_submissions', 'status_col' => 'status', 'pending' => 'submitted',
                               'kept' => array('approved', 'published', 'promotional'), 'urls' => array('article_url'), 'title' => 'article_title'),
        'lawsuit'     => array('table' => 'lawsuits', 'status_col' => 'publication_status', 'pending' => 'pending',
                               'kept' => array('published', 'draft'), 'urls' => array('source_urls', 'document_urls'), 'title' => 'case_name'),
        'legislation' => array('table' => 'legislation', 'status_col' => 'publication_status', 'pending' => 'pending',
                               'kept' => array('published', 'draft'), 'urls' => array('official_url', 'full_text_url'), 'title' => 'bill_title'),
    );
}

function kop_on_file_load_libs() {
    $api = get_stylesheet_directory() . '/api/';
    require_once $api . 'url-dedupe.php';
    require_once $api . 'news-story-groups.php';
}

/** Normalized addresses in a column value (one URL or a JSON list of them). */
function kop_on_file_urls($value, $sites = false) {
    $value = trim((string) $value);
    if ($value === '') return array();
    if ($value[0] === '[') {
        $list = json_decode($value, true);
        $value = is_array($list) ? array_values(array_filter($list, 'is_string')) : array();
    }
    return array_values(array_filter(kop_normalize_urls($value), function ($u) use ($sites) {
        // A bare site ("example.com") is a program's home page, not one article or filing
        // (unless websites are what is being compared).
        return $sites || strpos($u, '/') !== false;
    }));
}

function kop_on_file_case_key($v) {
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $v));
    return (strlen($k) >= 5 && preg_match('/\d/', $k)) ? $k : '';
}

function kop_on_file_bill_key(array $r) {
    $num = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($r['bill_number'] ?? '')));
    $jur = strtolower(trim((string) ($r['jurisdiction'] ?? '')));
    $year = trim((string) ($r['session_year'] ?? ''));
    return ($num !== '' && preg_match('/\d/', $num) && $jur !== '' && $year !== '') ? "$jur|$year|$num" : '';
}

/** What changes the answer: the pending rows, the kept records, the facility documents. */
function kop_on_file_fingerprint(PDO $pdo, $type) {
    $parts = array();
    foreach (kop_on_file_types() as $t) {
        $parts[] = implode(',', $pdo->query("SELECT COUNT(*), MAX(id), MAX(updated_at) FROM {$t['table']}")->fetch(PDO::FETCH_NUM));
    }
    $parts[] = implode(',', $pdo->query('SELECT COUNT(*), MAX(updated_at) FROM facilities_v2')->fetch(PDO::FETCH_NUM));
    return md5($type . '|' . implode('|', $parts));
}

function kop_on_file_ids(PDO $pdo, $type) {
    return array_map('intval', array_keys(kop_on_file_pending($pdo, $type)));
}

/** [pending id => ['label' => what holds it, 'url' => its page or '']] */
function kop_on_file_pending(PDO $pdo, $type) {
    static $memo = array();
    $types = kop_on_file_types();
    if (!isset($types[$type])) return array();
    if (isset($memo[$type])) return $memo[$type];
    try {
        $fp = kop_on_file_fingerprint($pdo, $type);
        $cached = get_transient('kop_on_file_' . $type);
        if (is_array($cached) && ($cached['fp'] ?? '') === $fp) {
            return $memo[$type] = (array) $cached['found'];
        }
        $found = kop_on_file_find($pdo, $type);
        set_transient('kop_on_file_' . $type, array('fp' => $fp, 'found' => $found), HOUR_IN_SECONDS);
        return $memo[$type] = $found;
    } catch (Throwable $e) {
        // Without the check every pending item simply stays in Pending.
        return $memo[$type] = array();
    }
}

function kop_on_file_find(PDO $pdo, $type) {
    kop_on_file_load_libs();
    $types = kop_on_file_types();
    $t = $types[$type];

    $st = $pdo->prepare("SELECT * FROM {$t['table']} WHERE {$t['status_col']} = ?");
    $st->execute(array($t['pending']));
    $pending = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pending) return array();

    // Each pending row's addresses -> ids.
    $want = array();
    foreach ($pending as $r) {
        foreach ($t['urls'] as $col) {
            foreach (kop_on_file_urls($r[$col] ?? '') as $u) $want[$u][] = (int) $r['id'];
        }
    }

    $found = kop_on_file_match_urls($pdo, $want, $type);
    $mark = function ($id, $label, $url = '') use (&$found) {
        if (!isset($found[$id])) $found[$id] = array('label' => $label, 'url' => $url);
    };
    $admin = function ($kind, $id) {
        return function_exists('kop_submission_review_url') ? kop_submission_review_url($kind) : '';
    };

    // The same thing under another address.
    $kept_in = implode(',', array_fill(0, count($t['kept']), '?'));
    if ($type === 'news') {
        $kept = array_flip($t['kept']);
        foreach ($pending as $r) {
            if (isset($found[(int) $r['id']])) continue;
            foreach (kop_news_find_title_duplicates($pdo, $r['article_title'] ?? '', $r['publication_name'] ?? '', (int) $r['id']) as $d) {
                if (!isset($kept[$d['status']])) continue;
                $mark((int) $r['id'], 'News article #' . $d['id'] . ' (' . kop_on_file_status_word($d['status']) . '), same headline and outlet: ' . $d['title'], $admin('news', $d['id']));
                break;
            }
        }
    } elseif ($type === 'lawsuit') {
        $q = $pdo->prepare("SELECT id, case_name, case_number, publication_status FROM lawsuits WHERE publication_status IN ($kept_in) AND case_number <> ''");
        $q->execute($t['kept']);
        $by = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            if ($k = kop_on_file_case_key($rec['case_number'])) $by[$k] = $rec;
        }
        foreach ($pending as $r) {
            $k = kop_on_file_case_key($r['case_number'] ?? '');
            if ($k !== '' && isset($by[$k])) {
                $rec = $by[$k];
                $mark((int) $r['id'], 'Lawsuit #' . $rec['id'] . ' (' . kop_on_file_status_word($rec['publication_status']) . '), same case number ' . $rec['case_number'] . ': ' . $rec['case_name'], $admin('lawsuit', $rec['id']));
            }
        }
    } else {
        $q = $pdo->prepare("SELECT id, bill_title, bill_number, jurisdiction, session_year, publication_status FROM legislation WHERE publication_status IN ($kept_in)");
        $q->execute($t['kept']);
        $by = array();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            if ($k = kop_on_file_bill_key($rec)) $by[$k] = $rec;
        }
        foreach ($pending as $r) {
            $k = kop_on_file_bill_key($r);
            if ($k !== '' && isset($by[$k])) {
                $rec = $by[$k];
                $mark((int) $r['id'], 'Bill #' . $rec['id'] . ' (' . kop_on_file_status_word($rec['publication_status']) . '), same bill ' . $rec['bill_number'] . ': ' . ($rec['bill_title'] ?: $rec['jurisdiction']), $admin('legislation', $rec['id']));
            }
        }
    }

    ksort($found);
    return $found;
}

/**
 * Which of $want's addresses are in our records. $want: [normalized url => [key, ...]]
 * (kop_on_file_urls() gives the normalized form). Kept news, lawsuits and bills, and
 * facilities' resourceLinks; with $websites, also facilities' own website links
 * (profileLinks). A row of $self_type never matches itself (same id as the key).
 * Returns [key => {label, url}], the first place found for each key.
 */
function kop_on_file_match_urls(PDO $pdo, array $want, $self_type = '', $websites = false) {
    kop_on_file_load_libs();
    $found = array();
    if (!$want) return $found;
    $mark = function ($id, $label, $url = '') use (&$found) {
        if (!isset($found[$id])) $found[$id] = array('label' => $label, 'url' => $url);
    };
    // The record's own tab on the Submissions Review page, its id in the label.
    $admin = function ($kind, $id) {
        return function_exists('kop_submission_review_url') ? kop_submission_review_url($kind) : '';
    };
    $names = array('news' => 'News article', 'lawsuit' => 'Lawsuit', 'legislation' => 'Bill');

    foreach (kop_on_file_types() as $kind => $k) {
        $in = implode(',', array_fill(0, count($k['kept']), '?'));
        $cols = implode(', ', array_unique(array_merge(array('id', $k['title'], $k['status_col']), $k['urls'])));
        $q = $pdo->prepare("SELECT $cols FROM {$k['table']} WHERE {$k['status_col']} IN ($in)");
        $q->execute($k['kept']);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            foreach ($k['urls'] as $col) {
                foreach (kop_on_file_urls($rec[$col] ?? '') as $u) {
                    foreach ($want[$u] ?? array() as $id) {
                        if ($kind === $self_type && (string) $id === (string) $rec['id']) continue;
                        $title = trim((string) $rec[$k['title']]) ?: ('#' . $rec['id']);
                        $mark($id, $names[$kind] . ' #' . $rec['id'] . ' (' . kop_on_file_status_word($rec[$k['status_col']]) . '): ' . $title, $admin($kind, $rec['id']));
                    }
                }
            }
        }
    }

    // A facility's "Materials and links" (and, for websites, its own website links).
    $like = $websites ? "json_data LIKE '%resourceLinks%' OR json_data LIKE '%profileLinks%'" : "json_data LIKE '%resourceLinks%'";
    foreach ($pdo->query("SELECT id, name, json_data FROM facilities_v2 WHERE $like") as $f) {
        $doc = json_decode((string) $f['json_data'], true);
        $lists = array('Materials and links' => (array) ($doc['resourceLinks'] ?? array()));
        if ($websites) $lists['its websites'] = (array) ($doc['profileLinks'] ?? array());
        foreach ($lists as $where => $links) {
            foreach ($links as $link) {
                $raw = is_array($link) ? (string) ($link['url'] ?? '') : (string) $link;
                foreach (kop_on_file_urls($raw, $websites) as $u) {
                    foreach ($want[$u] ?? array() as $id) {
                        $page = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $f['id']) : '';
                        $mark($id, 'On the facility page of ' . $f['name'] . ' (' . $where . ')', $page);
                    }
                }
            }
        }
    }
    return $found;
}

/** [facility id => ['name' => ..., 'doc' => facilities_v2 document]] for the ids given that exist. */
function kop_on_file_docs(PDO $pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = array();
    foreach (array_chunk($ids, 300) as $chunk) {
        foreach ($pdo->query('SELECT id, name, json_data FROM facilities_v2 WHERE id IN (' . implode(',', $chunk) . ')')->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $doc = json_decode((string) $f['json_data'], true);
            if (is_array($doc)) $out[(int) $f['id']] = array('name' => (string) $f['name'], 'doc' => $doc);
        }
    }
    return $out;
}

/**
 * $build() cached in a transient until the queue's table ($table, read with
 * $wpdb) or any facility record changes: a newly added item or record is seen at once.
 */
function kop_on_file_cached($name, $table, callable $build) {
    global $wpdb;
    try {
        $pdo = kop_rinbox_pdo();
        $fp = md5(implode(',', (array) $wpdb->get_row("SELECT COUNT(*), MAX(id), MAX(reviewed_at), SUM(status = 'pending') FROM {$table}", ARRAY_N))
            . '|' . implode(',', $pdo->query('SELECT COUNT(*), MAX(updated_at) FROM facilities_v2')->fetch(PDO::FETCH_NUM)));
        $cached = get_transient('kop_on_file_' . $name);
        if (is_array($cached) && ($cached['fp'] ?? '') === $fp) return (array) $cached['found'];
        kop_on_file_load_libs();
        $found = $build($pdo);
        set_transient('kop_on_file_' . $name, array('fp' => $fp, 'found' => $found), DAY_IN_SECONDS);
        return $found;
    } catch (Throwable $e) {
        return array();
    }
}

/** " AND <col> NOT IN (...)" for keys already on file (hex keys only), or ''. */
function kop_on_file_not_in($col, array $keys) {
    $keys = array_values(array_filter(array_map('strval', $keys), function ($k) { return preg_match('/^[a-f0-9]{1,64}$/', $k); }));
    return $keys ? " AND {$col} NOT IN ('" . implode("','", $keys) . "')" : '';
}

function kop_on_file_status_word($status) {
    $words = array('approved' => 'approved', 'published' => 'published', 'promotional' => 'Industry PR', 'draft' => 'draft');
    return $words[(string) $status] ?? (string) $status;
}
