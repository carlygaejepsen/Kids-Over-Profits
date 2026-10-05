<?php
/**
 * Facilities that surface in the news and are not in the database yet.
 *
 * Every saved article (nightly discovery, the news processor, imports) lists
 * the facilities it mentions. An hourly WP-Cron job takes the names that do
 * not resolve to a record (api/facility-aliases.php) - plus programs a closure
 * report could not match (inc/closure-reports.php) - and asks the AI about them
 * with the article text and the database's look-alike records:
 *
 *   - a specific youth residential program we already have under another
 *     spelling of the same name: the article is linked to that record;
 *   - an earlier or later name of a record: held. Each name era is its own
 *     record (owner's rule, 2026-09-29: Bethel Boys Academy and Eagle Point
 *     Christian Academy are two records), so the article belongs to a
 *     record for that era, which a person creates (docs/PLAN.md 3.7);
 *   - a specific youth residential program we do not have, with a known
 *     state or country: a new facilities_v2 record is created from what the
 *     article says (name, place, type, status, years, operator), citing the
 *     article, placed on its state hub and linked to the article;
 *   - anything else (an agency, a court, a psychiatric ward or other
 *     provider, a vague label like "the facility"): recorded and not asked
 *     about again.
 *
 * Every decision is kept in news_facility_candidates, one row per name, and
 * listed under KOP Data Tools > Facilities from News, where an admin can
 * remove a record the scan created (only while nobody has edited it) or
 * create one the scan held back. A created record also closes the loop on
 * closure reports: unmatched reports are matched again after each run.
 *
 * Server CLI: php api/scan-new-facilities.php (dry run unless "apply").
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/news-mentions.php';   // and facility-aliases.php

define('KOP_FACILITY_DISCOVERY_DB_VERSION', '1');

/** Decisions, as the review screen names them. */
function kop_facdisc_decisions() {
    return array(
        'created'      => 'New record (made by the scan)',
        'matched'      => 'Listed on a record',
        'possible_duplicate' => 'Might be a record we have',
        'other_era'    => 'Might be another name of a record',
        'needs_place'  => 'Place unknown',
        'unquoted'     => 'Not found in the article',
        'provider'     => 'Provider, not a facility',
        'not_facility' => 'Skipped (not a facility)',
        'indigenous_school' => 'Indigenous residential school',
        'removed'      => 'Removed',
    );
}

/** The facility types the model may choose from (the data's own spellings). */
function kop_facdisc_types() {
    return array(
        'Residential Treatment Center', 'Psychiatric Residential Treatment Facility', 'Therapeutic Boarding School',
        'Wilderness Therapy', 'Boot Camp', 'Therapeutic Group Home', 'Group Home', 'Transitional Living Program',
        'Substance Abuse Treatment', 'Eating Disorder Treatment Center', 'Maternity Home', 'Fundamentalist Religious Home', 'Specialty Boarding School',
        'Juvenile Detention Facility', 'Juvenile Correctional Facility', 'Juvenile Justice RTC', 'Other',
    );
}

function kop_facdisc_ensure_tables(PDO $pdo) {
    if (get_option('kop_facility_discovery_db') === KOP_FACILITY_DISCOVERY_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_facility_candidates (
        id INT NOT NULL AUTO_INCREMENT,
        name_key VARCHAR(191) NOT NULL,
        mention VARCHAR(255) NOT NULL,
        news_id INT NOT NULL,
        decision VARCHAR(20) NOT NULL,
        facility_id INT NULL,
        detail MEDIUMTEXT NULL,
        reviewed_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name_key (name_key),
        KEY decision (decision),
        KEY facility (facility_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_facility_scans (
        news_id INT NOT NULL,
        content_hash CHAR(32) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        attempts INT NOT NULL DEFAULT 1,
        detail TEXT NULL,
        scanned_at DATETIME NOT NULL,
        PRIMARY KEY (news_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_facility_discovery_db', KOP_FACILITY_DISCOVERY_DB_VERSION);
}

function kop_facdisc_tables_exist(PDO $pdo) {
    return (bool) $pdo->query("SHOW TABLES LIKE 'news_facility_candidates'")->fetchColumn();
}

/* ---- What to ask about ---------------------------------------------- */

/** The index is costly (it decodes every record); one per request, rebuilt after a create. */
function kop_facdisc_alias_index(PDO $pdo, $rebuild = false) {
    static $index = null;
    if ($index === null || $rebuild) {
        require_once get_stylesheet_directory() . '/api/facility-aliases.php';
        $index = kop_build_facility_alias_index($pdo);
    }
    return $index;
}

/**
 * The article's names that do not resolve to a record: its facility
 * mentions, and the programs its closure reports could not match. Names a
 * past run already decided are left out; $decided gets their decisions.
 */
function kop_facdisc_unresolved(PDO $pdo, array $news, array $index, array &$decided = array()) {
    $names = array();
    foreach (kop_normalize_facility_mentions($news['facilities_mentioned'] ?? '') as $m) {
        if (empty($m['facility_id'])) {
            $names[] = $m['name'];
        }
    }
    foreach ($news['closure_programs'] ?? array() as $name) {
        $names[] = $name;
    }
    $out = array();
    $seen = array();
    foreach ($names as $name) {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        $key = kop_normalize_name_key($name);
        if ($key === '' || isset($seen[$key]) || mb_strlen($name) > 200) {
            continue;
        }
        $seen[$key] = true;
        if (kop_resolve_mention_to_facility($name, $index) !== null) {
            continue;
        }
        // A name held for want of a place, or not found in the article, is
        // asked again: another article, or a better prompt, may place it.
        if (isset($news['known'][$key]) && !in_array($news['known'][$key]['decision'], array('needs_place', 'unquoted'), true)) {
            $decided[$key] = $news['known'][$key];
            continue;
        }
        $out[] = $name;
    }
    return $out;
}

/**
 * Records that look like $name: the facilities sharing its distinctive words
 * (four letters or more, not a stopword such as "academy" or "youth"), most
 * shared first. The model decides whether one of them is the same place.
 */
function kop_facdisc_lookalikes(PDO $pdo, $name, array $index, $limit = 6) {
    $stop = kop_news_stopwords();
    $score = array();
    foreach (array_unique(preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: array()) as $t) {
        if (strlen($t) < 4 || ctype_digit($t) || isset($stop[$t])) {
            continue;
        }
        // The word itself, or words a letter or two off it: "Ashville" has
        // to find "Asheville", or a misspelling becomes a second record.
        foreach (kop_facdisc_near_tokens($t, $index) as $near) {
            if (count($index['tokens'][$near]) > 40) {
                continue;
            }
            foreach (array_keys($index['tokens'][$near]) as $fid) {
                $score[$fid] = ($score[$fid] ?? 0) + 1;
            }
        }
    }
    if (!$score) {
        return array();
    }
    arsort($score);
    $ids = array_slice(array_keys($score), 0, $limit * 3);
    $in = implode(',', array_map('intval', $ids));
    $rows = array();
    foreach ($pdo->query("SELECT id, unique_name, city, state, country, status FROM facilities_v2 WHERE id IN ({$in})") as $r) {
        $rows[(int) $r['id']] = $r;
    }
    $out = array();
    foreach ($ids as $fid) {
        if (isset($rows[$fid])) {
            $out[] = $rows[$fid];
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Index words equal to $t or within one edit of it (two for words of eight
 * letters or more), sharing its first letter. Five letters at least for the
 * near ones: "home" and "hope" are not a misspelling of each other.
 */
function kop_facdisc_near_tokens($t, array $index) {
    $out = isset($index['tokens'][$t]) ? array($t) : array();
    $len = strlen($t);
    if ($len < 5) {
        return $out;
    }
    $max = $len >= 8 ? 2 : 1;
    $stop = kop_news_stopwords();
    foreach ($index['tokens'] as $word => $ids) {
        $word = (string) $word;
        if ($word === $t || $word[0] !== $t[0] || abs(strlen($word) - $len) > $max || isset($stop[$word])) {
            continue;
        }
        if (levenshtein($word, $t) <= $max) {
            $out[] = $word;
        }
    }
    return $out;
}

/**
 * Words that are town names: the entry's city and every city our records list
 * in that state (cached per request), as kop_normalize_name_key() words.
 */
function kop_facdisc_place_words(PDO $pdo, $state, $city = '') {
    static $by_state = array();
    $state = strtoupper((string) $state);
    if (!isset($by_state[$state])) {
        $words = array();
        if ($state !== '') {
            $st = $pdo->prepare("SELECT DISTINCT city FROM facilities_v2 WHERE state = ? AND city IS NOT NULL AND city <> ''");
            $st->execute(array($state));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                foreach (explode(' ', kop_normalize_name_key((string) $c)) as $w) {
                    if (strlen($w) >= 4) $words[$w] = true;
                }
            }
        }
        $by_state[$state] = $words;
    }
    $words = $by_state[$state];
    foreach (explode(' ', kop_normalize_name_key((string) $city)) as $w) {
        if (strlen($w) >= 4) $words[$w] = true;
    }
    return array_keys($words);
}

/** A "name" that is only a town ("Kissimmee", "Madison"): a place, never a program. */
function kop_facdisc_is_place_name(PDO $pdo, $name, $state, $city = '') {
    $key = kop_normalize_name_key(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $name));
    if ($key === '') {
        return false;
    }
    if ($key === kop_normalize_name_key((string) $city)) {
        return true;
    }
    if ((string) $state === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM facilities_v2 WHERE state = ? AND LOWER(city) = ? LIMIT 1');
    $st->execute(array(strtoupper((string) $state), strtolower(trim((string) $name))));
    return (bool) $st->fetchColumn();
}

/**
 * An existing record that is probably the same place, which the model did
 * not call the same: returns its id, or null. In the same state (anywhere,
 * with no state):
 *   - a name a couple of letters off ("Ashville Academy for Girls");
 *   - one name inside the other ("Maple Lake Academy" and "Maple Lake
 *     Academy, LLC - Boys' Home");
 *   - the same city and a distinctive word in common ("Three Points Ranch"
 *     and "Three Points Center", both in Hurricane, UT).
 * Such a name is held for a person rather than created.
 */
function kop_facdisc_near_duplicate(PDO $pdo, $name, $state, $city = '') {
    $clean = static function ($n) {
        $k = kop_normalize_name_key(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $n));
        $k = preg_replace('/\b(the|of|for|and|inc|llc|ltd|corp)\b/', ' ', $k);
        return trim(preg_replace('/\s+/', ' ', $k));
    };
    // Not kop_news_stopwords(): that list also drops place words ("maple",
    // "lake"), which are exactly what tells two same-town programs apart.
    // Only the words every facility name shares are left out here.
    $generic = array_flip(array('academy', 'school', 'schools', 'center', 'centre', 'home', 'homes', 'house', 'program', 'programs',
        'youth', 'girls', 'boys', 'treatment', 'residential', 'ranch', 'camp', 'lodge', 'facility', 'services', 'family',
        'children', 'childrens', 'juvenile', 'detention', 'county', 'teen', 'teens', 'therapeutic', 'behavioral', 'health'));
    $words = static function ($k) use ($generic) {
        return array_filter(explode(' ', $k), static function ($w) use ($generic) {
            return strlen($w) >= 4 && !ctype_digit($w) && !isset($generic[$w]);
        });
    };
    $key = $clean($name);
    if (strlen($key) < 6) {
        return null;
    }
    $city_key = kop_normalize_name_key((string) $city);
    // Town names say where a program is, never which one: "Kissimmee" is not
    // Kissimmee Youth Academy, "Tuskegee Union" not Sequel TSI of Tuskegee.
    $places = kop_facdisc_place_words($pdo, $state, $city);
    $mine = array_diff($words($key), $places);
    // Capitalised short words may be an acronym of a record's name: "Camp
    // SAYLA" is Southeast Alabama Youth Leadership Academy.
    preg_match_all('/\b[A-Z]{3,6}\b/', (string) $name, $m);
    $acronyms = array_flip($m[0]);
    $initials = static function ($n) {
        $out = '';
        foreach (preg_split('/[^A-Za-z]+/', preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $n), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (!in_array(strtolower($w), array('of', 'the', 'and', 'for', 'inc', 'llc'), true)) $out .= strtoupper($w[0]);
        }
        return $out;
    };
    $stmt = $state !== ''
        ? $pdo->prepare('SELECT id, unique_name, city FROM facilities_v2 WHERE state = ?')
        : $pdo->prepare('SELECT id, unique_name, city FROM facilities_v2');
    $stmt->execute($state !== '' ? array($state) : array());
    $max = max(2, (int) floor(strlen($key) / 10));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $other = $clean($r['unique_name']);
        if ($other === '') {
            continue;
        }
        if (abs(strlen($other) - strlen($key)) <= $max && levenshtein($other, $key) <= $max) {
            return (int) $r['id'];
        }
        if ($state !== '' && strlen($other) >= 6 && $mine && array_diff($words($other), $places)
            && (strpos(' ' . $other . ' ', ' ' . $key . ' ') !== false || strpos(' ' . $key . ' ', ' ' . $other . ' ') !== false)) {
            return (int) $r['id'];
        }
        if ($state !== '' && $acronyms && isset($acronyms[$initials($r['unique_name'])])) {
            return (int) $r['id'];
        }
        if ($state !== '' && $city_key !== '' && kop_normalize_name_key((string) $r['city']) === $city_key
            && array_intersect($mine, array_diff($words($other), $places))) {
            return (int) $r['id'];
        }
    }
    return null;
}

/**
 * Names that are plainly not a youth residential site - a department, a
 * police force, a court, a college, a law firm, an adult jail - decided
 * without spending a Groq call on them. Returns 'organization', 'provider'
 * or null when the model has to look.
 */
function kop_facdisc_obvious_kind($name) {
    // "Indian Agency Boarding School" and the like: the model decides, so
    // the name reaches Indigenous Schools rather than "organization".
    if (kop_facdisc_looks_indigenous_school($name)) {
        return null;
    }
    if (preg_match('/\b(department|dept|police|sheriff|sheriff\'s|court|courts|university|college|attorney|attorneys|llp|pllc|commission|agency|authority|ministry|bureau|division|office|council|legislature|senate|museum|newspaper|jail|prison|penitentiary|diocese)\b/i', $name)) {
        return 'organization';
    }
    if (preg_match('/\b(hospital|clinic)\b/i', $name) && !preg_match('/\b(residential|youth|academy|ranch|school)\b/i', $name)) {
        return 'provider';
    }
    return null;
}

/** A name that reads like an Indian boarding, residential or mission school. */
function kop_facdisc_looks_indigenous_school($name) {
    return (bool) preg_match('/\b(indian|indigenous|native american|first nations?|tribal)\b.*\b(boarding|residential|industrial|manual labou?r|mission|agency)\b.*\bschool\b/i', (string) $name);
}

/** Changes when the article's names, or its unmatched closure programs, do. */
function kop_facdisc_news_hash(array $news) {
    return md5(implode("\x1f", array(
        (string) ($news['facilities_mentioned'] ?? ''), implode('|', $news['closure_programs'] ?? array()),
        $news['article_title'] ?? '', 'v2',
    )));
}

/**
 * Articles to look at: not rejected, deleted or promotional, and not yet
 * scanned in their current form (failures retried three times).
 */
function kop_facdisc_articles(PDO $pdo, $limit, array $only_ids = array()) {
    $where = "n.status NOT IN ('rejected','deleted','promotional')";
    $params = array();
    if ($only_ids) {
        $where .= ' AND n.id IN (' . implode(',', array_fill(0, count($only_ids), '?')) . ')';
        $params = array_map('intval', $only_ids);
    }
    $has_tables = kop_facdisc_tables_exist($pdo);
    $scans = $has_tables
        ? 's.content_hash, s.outcome, s.attempts FROM news_submissions n LEFT JOIN news_facility_scans s ON s.news_id = n.id'
        : 'NULL AS content_hash, NULL AS outcome, 0 AS attempts FROM news_submissions n';
    $stmt = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url,
                                  n.summary, n.status, n.facilities_mentioned, {$scans}
                            WHERE {$where} ORDER BY n.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $closure = array();
    if ($pdo->query("SHOW TABLES LIKE 'facility_closure_reports'")->fetchColumn()) {
        foreach ($pdo->query("SELECT news_id, program_name FROM facility_closure_reports WHERE status = 'unmatched' ORDER BY id") as $r) {
            $closure[(int) $r['news_id']][] = $r['program_name'];
        }
    }
    $known = array();
    if ($has_tables) {
        foreach ($pdo->query('SELECT name_key, mention, decision, facility_id FROM news_facility_candidates') as $r) {
            $known[$r['name_key']] = $r;
        }
    }

    $out = array();
    foreach ($rows as $row) {
        $row['closure_programs'] = $closure[(int) $row['id']] ?? array();
        $row['known'] = $known;
        $hash = kop_facdisc_news_hash($row);
        if (!$only_ids && $row['content_hash'] === $hash && ($row['outcome'] !== 'error' || (int) $row['attempts'] >= 3)) {
            continue;
        }
        $row['hash'] = $hash;
        $out[] = $row;
    }
    // Groq allows a handful of articles a run, so spend them where a program
    // is likeliest: names that read like one, reviewed articles, closures.
    $score = static function ($row) {
        $names = (string) $row['facilities_mentioned'] . ' ' . implode(' ', $row['closure_programs']);
        return (preg_match('/\b(academy|ranch|school|treatment|residential|wilderness|lodge|camp|home|house|village|farm|institute|center|centre)\b/i', $names) ? 4 : 0)
            + ($row['closure_programs'] ? 2 : 0)
            + (in_array($row['status'], array('approved', 'published'), true) ? 1 : 0);
    };
    usort($out, static function ($a, $b) use ($score) {
        return array($score($b), (int) $b['id']) <=> array($score($a), (int) $a['id']);
    });
    $out = array_slice($out, 0, $limit);
    return $out;
}

function kop_facdisc_record_scan(PDO $pdo, $news_id, $hash, $outcome, $detail = '') {
    $pdo->prepare("INSERT INTO news_facility_scans (news_id, content_hash, outcome, attempts, detail, scanned_at)
                   VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE
                       attempts = IF(content_hash = VALUES(content_hash) AND outcome = 'error', attempts + 1, 1),
                       content_hash = VALUES(content_hash), outcome = VALUES(outcome),
                       detail = VALUES(detail), scanned_at = VALUES(scanned_at)")
        ->execute(array((int) $news_id, $hash, $outcome, mb_substr((string) $detail, 0, 2000)));
}

/* ---- Asking the AI ------------------------------------------------- */

/** Lower case words and digits only, padded with spaces, for "is this in the article" checks. */
function kop_facdisc_norm_text($text) {
    return ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $text, 'UTF-8'))) . ' ';
}

/** Whether $needle (a name, a quote) appears in $source as whole words; $source from kop_facdisc_norm_text(). */
function kop_facdisc_in_source($needle, $source) {
    $n = kop_facdisc_norm_text($needle);
    return trim($n) !== '' && strpos($source, $n) !== false;
}

function kop_facdisc_build_prompt(array $news, array $names, array $lookalikes, $text) {
    $p  = "You maintain a research database of youth residential programs in the troubled teen industry: residential treatment centers, therapeutic boarding schools, wilderness programs, boot camps, group homes, religious homes for youth, and juvenile detention and correctional facilities.\n\n";
    $p .= 'Article: ' . $news['article_title'] . ' (' . ($news['publication_name'] ?: 'unknown outlet') . ', ' . ($news['publication_date'] ?: 'date unknown') . ")\n";
    $p .= 'URL: ' . $news['article_url'] . "\n";
    if (trim((string) $news['summary']) !== '') {
        $p .= 'Summary: ' . trim($news['summary']) . "\n";
    }
    $p .= "\nArticle text:\n" . ($text !== '' ? $text : '(could not be fetched; use the title and summary)') . "\n\n";
    $p .= "The article mentions these names, which are not in the database under these spellings. For each, records already in the database that look alike are listed (id | name | place | status):\n";
    foreach ($names as $i => $name) {
        $p .= "\n" . ($i + 1) . '. "' . $name . "\"\n";
        foreach ($lookalikes[$name] ?? array() as $f) {
            $place = trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country']))));
            $p .= '   ' . $f['id'] . ' | ' . $f['unique_name'] . ' | ' . ($place ?: '-') . ' | ' . ($f['status'] ?: 'Unknown') . "\n";
        }
    }
    $p .= "\nReturn ONLY a JSON object with one entry per name, in the same order:\n";
    $p .= '{"names":[{"name":"the name exactly as listed","kind":"facility|indigenous_school|provider|organization|vague","sameAs":null,"renameOf":null,"officialName":"","otherNames":[],"city":"","state":"two-letter US state code, or empty","country":"","type":"","status":"Open|Closed|Suspended|Unknown","startYear":null,"endYear":null,"operator":"","gender":"Male|Female|Co-ed or empty","evidence":"one sentence copied from the article that places or describes it"}]}' . "\n\n";
    $p .= "kind:\n";
    $p .= "- facility: one specific youth residential program or site, named well enough to identify (a detention center, an academy, a ranch, a group home).\n";
    $p .= "- indigenous_school: a school, past or present, that Indigenous children (American Indian, Alaska Native, Native Hawaiian, First Nations, Inuit, Metis) were sent to under a government or church policy of removal or assimilation: an Indian boarding school, Indian residential school, Indian industrial or manual labor school, or Indian mission school. Never a troubled teen program, even one with Native students. For one, fill officialName, otherNames, city, state or country, status, startYear and endYear (any year the article gives, even before 1900) and operator (the government, church or order that ran it).\n";
    $p .= "- provider: a psychiatric hospital or ward, outpatient clinic, day school, day treatment or partial hospitalization program.\n";
    $p .= "- organization: an agency, department, court, police force, law firm, company, charity, church, school district, or anything that is not one residential site. A company that runs programs is an organization; its programs are facilities.\n";
    $p .= "- vague: a description rather than a name (\"the facility\", \"an unnamed children's home\", \"Hope unit\", \"Safe\"), or a name too generic to identify one place.\n";
    $p .= "sameAs: the id of a listed record that is the same site under the same name: a misspelling, an abbreviation or acronym, or the name with or without its operator's. Not a former or later name.\n";
    $p .= "renameOf: the id of a listed record that is the same site under a different name, earlier or later (the article's name was used before or after the record's). Only an id from that name's list; otherwise null.\n";
    $p .= "For sameAs, besides the above: Only an id from that name's list; null when none is the same place. Two different sites with similar names are not the same, and a college, school district, company or agency that shares a word with a listed program is not that program.\n";
    $p .= "For a facility, fill the rest only from the article. The state or country may come from anything the article says about where it is: a city, a county (\"Knox County\" in a Tennessee paper), the dateline, the outlet's own state, or the state agency involved. officialName (its correct full name), otherNames (other names the article gives it), city, state or country, type (one of: " . implode(', ', kop_facdisc_types()) . '; empty if unclear), status (Closed only if the article says it closed for good; Suspended if it is temporarily closed, its license or admissions suspended), startYear and endYear (only years the article gives), operator (the company or agency running it), gender. Leave a field empty rather than guess.' . "\n";
    return $p;
}

/** The model's entries, cleaned, keyed by the names asked about. */
function kop_facdisc_parse_reply($reply, array $names, array $lookalikes) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $data = is_array($reply) ? $reply : kop_ai_extract_json((string) $reply);
    if (!is_array($data) || !isset($data['names']) || !is_array($data['names'])) {
        return null;
    }
    $by_key = array();
    foreach ($names as $name) {
        $by_key[kop_normalize_name_key($name)] = $name;
    }
    $types = array_flip(kop_facdisc_types());
    $str = static function ($v, $max = 255) {
        return is_scalar($v) ? mb_substr(trim(preg_replace('/\s+/', ' ', (string) $v)), 0, $max) : '';
    };
    $year = static function ($v) {
        $y = is_numeric($v) ? (int) $v : 0;
        return ($y >= 1900 && $y <= (int) gmdate('Y') + 1) ? $y : null;
    };
    // An Indigenous school's years go back to the early 1800s.
    $old_year = static function ($v) {
        $y = is_numeric($v) ? (int) $v : 0;
        return ($y >= 1700 && $y <= (int) gmdate('Y')) ? $y : null;
    };
    $out = array();
    foreach ($data['names'] as $i => $e) {
        if (!is_array($e)) {
            continue;
        }
        $key = kop_normalize_name_key($str($e['name'] ?? ''));
        if (!isset($by_key[$key])) {
            // A model that rewrote the name: fall back to the list order.
            $key = isset($names[$i]) ? kop_normalize_name_key($names[$i]) : '';
            if (!isset($by_key[$key])) {
                continue;
            }
        }
        $name = $by_key[$key];
        $kind = strtolower($str($e['kind'] ?? ''));
        if (!in_array($kind, array('facility', 'indigenous_school', 'provider', 'organization', 'vague'), true)) {
            $kind = 'vague';
        }
        $allowed = array();
        foreach ($lookalikes[$name] ?? array() as $f) {
            $allowed[(int) $f['id']] = true;
        }
        $same = isset($e['sameAs']) && is_numeric($e['sameAs']) && isset($allowed[(int) $e['sameAs']]) ? (int) $e['sameAs'] : null;
        $rename = isset($e['renameOf']) && is_numeric($e['renameOf']) && isset($allowed[(int) $e['renameOf']]) ? (int) $e['renameOf'] : null;
        $state = strtoupper($str($e['state'] ?? '', 2));
        $status = ucfirst(strtolower($str($e['status'] ?? '')));
        $gender = $str($e['gender'] ?? '');
        $other = array();
        foreach ((array) ($e['otherNames'] ?? array()) as $n) {
            $n = $str($n);
            if ($n !== '') $other[] = $n;
        }
        $out[$name] = array(
            'name'         => $name,
            'kind'         => $kind,
            'sameAs'       => $same,
            'renameOf'     => $same ? null : $rename,
            'officialName' => $str($e['officialName'] ?? '') ?: $name,
            'otherNames'   => array_slice($other, 0, 8),
            'city'         => $str($e['city'] ?? '', 100),
            'state'        => preg_match('/^[A-Z]{2}$/', $state) ? $state : '',
            'country'      => $str($e['country'] ?? '', 100),
            'type'         => isset($types[$str($e['type'] ?? '')]) ? $str($e['type']) : '',
            'status'       => in_array($status, array('Open', 'Closed', 'Suspended'), true) ? $status : 'Unknown',
            'startYear'    => $kind === 'indigenous_school' ? $old_year($e['startYear'] ?? null) : $year($e['startYear'] ?? null),
            'endYear'      => $kind === 'indigenous_school' ? $old_year($e['endYear'] ?? null) : $year($e['endYear'] ?? null),
            'operator'     => $str($e['operator'] ?? ''),
            'gender'       => in_array($gender, array('Male', 'Female', 'Co-ed'), true) ? $gender : '',
            'evidence'     => $str($e['evidence'] ?? '', 600),
        );
    }
    return $out;
}

/* ---- Creating the record -------------------------------------------- */

/** The article, as the new record's notes cite it. */
function kop_facdisc_source_note(array $news) {
    // Another source creating through kop_facdisc_create() (KOP Tools > State Lists) words its own note.
    if (!empty($news['source_note'])) {
        return (string) $news['source_note'];
    }
    $bits = array_filter(array(
        $news['publication_name'] ?: '',
        $news['article_title'] ? '"' . $news['article_title'] . '"' : '',
        $news['publication_date'] ?: '',
    ));
    return 'Added ' . gmdate('Y-m-d') . ' by the news scan from: ' . implode(', ', $bits)
        . ($news['article_url'] ? ' ' . $news['article_url'] : '');
}

/**
 * The new facility as a canonical document, from the model's entry. The name
 * the article used is kept in otherNames when it differs, so the article's
 * mention resolves to the record from now on.
 */
function kop_facdisc_build_doc(array $entry, array $news) {
    $other = $entry['otherNames'];
    if (kop_normalize_name_key($entry['name']) !== kop_normalize_name_key($entry['officialName'])) {
        array_unshift($other, $entry['name']);
    }
    $other = array_values(array_unique(array_filter($other, static function ($n) use ($entry) {
        return kop_normalize_name_key($n) !== kop_normalize_name_key($entry['officialName']);
    })));
    $place = trim(implode(', ', array_filter(array($entry['city'], $entry['state'] ?: $entry['country']))));
    $legacy = array(
        'identification'  => array(
            'name'            => $entry['officialName'],
            'currentOperator' => $entry['operator'],
            'otherNames'      => $other,
        ),
        'locationDetails' => array(
            'city'    => $entry['city'],
            'state'   => $entry['state'],
            'country' => $entry['country'] !== '' ? $entry['country'] : ($entry['state'] !== '' ? 'United States' : ''),
        ),
        'location'        => $place,
        'operatingPeriod' => array(
            'startYear' => $entry['startYear'],
            'endYear'   => $entry['endYear'],
            'status'    => $entry['status'],
            'notes'     => array(),
        ),
        'facilityDetails' => array(
            'type'   => $entry['type'],
            'gender' => $entry['gender'],
        ),
        'notes'           => array(kop_facdisc_source_note($news)),
        'resourceLinks'   => (array) ($news['resource_links'] ?? array()),
    );
    $doc = kop_facility_normalize($legacy, array('facility_id' => null, 'unique_name' => ''));
    $doc['facility_id'] = null;
    $doc['provenance']['source'] = (string) ($news['provenance_source'] ?? 'news-discovery');
    $doc['provenance']['sourceCategory'] = (string) ($news['provenance_category'] ?? 'news');
    $doc['provenance']['sourceProject'] = '';
    $doc['provenance']['sourceProjectId'] = null;
    $doc['provenance']['sourceOperator'] = null;
    $doc['provenance']['migratedAt'] = '';
    $doc['provenance']['legacyIds'] = array();
    return $doc;
}

/** Link the article to the record; 'related' so a later edit's mention sync keeps it. */
function kop_facdisc_link(PDO $pdo, $news_id, $facility_id) {
    $pdo->prepare("INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, 'related', 'news-discovery')")
        ->execute(array((int) $news_id, (int) $facility_id));
}

function kop_facdisc_record(PDO $pdo, $name, array $news, $decision, $facility_id, array $detail, $reviewer = null) {
    $pdo->prepare("INSERT INTO news_facility_candidates (name_key, mention, news_id, decision, facility_id, detail, reviewed_by, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE decision = VALUES(decision), facility_id = VALUES(facility_id),
                       detail = VALUES(detail), reviewed_by = VALUES(reviewed_by), updated_at = VALUES(updated_at)")
        ->execute(array(kop_normalize_name_key($name), mb_substr($name, 0, 255), (int) $news['id'], $decision,
            $facility_id ? (int) $facility_id : null, wp_json_encode($detail), $reviewer));
}

/**
 * Create the record for one entry, unless the identity rule finds it
 * (same name and place). Returns array(decision, facility id).
 */
function kop_facdisc_create(PDO $pdo, array $entry, array $news, array $more_opts = array()) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    // $more_opts: kop_facility_save() options a caller adds (skip_memberships on a test copy).
    $opts = array('pdo' => $pdo, 'prefix' => $wpdb->prefix) + $more_opts;
    return kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $entry, $news) {
        $doc = kop_facdisc_build_doc($entry, $news);
        $found = kop_facility_resolve_identity($doc['identification']['name'], $doc['location']['state'], $doc['location']['city'], $opts);
        if ($found) {
            return array('matched', (int) $found, null);
        }
        $status = null;
        $id = kop_facility_save($doc, $opts, $status);
        $saved = kop_facility_load($id, $opts);
        return array($status === 'created' ? 'created' : 'matched', (int) $id, $saved ? $saved['doc'] : null);
    });
}

/**
 * Decide one name from its entry. With $write false nothing is stored and
 * the decision is only reported (the CLI dry run).
 */
function kop_facdisc_apply_entry(PDO $pdo, array $entry, array $news, $write) {
    $detail = array('entry' => $entry);
    if ($entry['kind'] === 'indigenous_school' && !function_exists('kop_ischools_from_news')) {
        $entry['kind'] = 'organization';   // the schools module is not loaded: never a facility
    }
    if ($entry['kind'] === 'indigenous_school') {
        // Not a TTI facility: filed at Indigenous Schools, waiting for review,
        // with the article (inc/indigenous-schools.php).
        if (!$write) {
            return array('indigenous_school', null);
        }
        $detail['school_id'] = kop_ischools_from_news($pdo, $entry, $news);
        kop_facdisc_record($pdo, $entry['name'], $news, 'indigenous_school', null, $detail);
        return array('indigenous_school', null);
    }
    if ($entry['kind'] === 'facility' && kop_facdisc_is_place_name($pdo, $entry['name'], $entry['state'] ?? '', $entry['city'] ?? '')) {
        // A town in a list of a company's sites, not a program's name.
        $decision = 'not_facility';
        $fid = null;
        $detail['entry']['by'] = 'place name';
    } elseif ($entry['kind'] === 'facility' && isset($entry['quoted']) && !$entry['quoted']) {
        // Not in the article it read: held for a person, with the record it looked like.
        $decision = 'unquoted';
        $fid = $entry['sameAs'] ?: ($entry['renameOf'] ?: null);
    } elseif ($entry['kind'] === 'facility' && $entry['sameAs']) {
        $decision = 'matched';
        $fid = $entry['sameAs'];
    } elseif ($entry['kind'] === 'facility' && !empty($entry['renameOf'])) {
        // Another era of a record: not linked to it, not created yet.
        $decision = 'other_era';
        $fid = $entry['renameOf'];
    } elseif ($entry['kind'] === 'facility' && $entry['state'] === '' && $entry['country'] === '') {
        $decision = 'needs_place';
        $fid = null;
    } elseif ($entry['kind'] === 'facility' && ($near = kop_facdisc_near_duplicate($pdo, $entry['officialName'], $entry['state'], $entry['city']))) {
        $decision = 'possible_duplicate';
        $fid = $near;
    } elseif ($entry['kind'] === 'facility') {
        if (!$write) {
            return array('created', null);
        }
        list($decision, $fid, $doc) = kop_facdisc_create($pdo, $entry, $news);
        if ($doc) {
            $detail['doc'] = $doc;
        }
    } else {
        $decision = $entry['kind'] === 'provider' ? 'provider' : 'not_facility';
        $fid = null;
    }
    if ($write) {
        if ($fid && !in_array($decision, array('possible_duplicate', 'other_era', 'unquoted'), true)) {
            kop_facdisc_link($pdo, $news['id'], $fid);
        }
        kop_facdisc_record($pdo, $entry['name'], $news, $decision, $fid, $detail);
    }
    return array($decision, $fid);
}

/** Scan one article. Returns array(outcome, decisions[name => [decision, id, entry]], detail). */
function kop_facdisc_scan_article(PDO $pdo, array $news, $write) {
    $index = kop_facdisc_alias_index($pdo);
    $decided = array();
    $names = kop_facdisc_unresolved($pdo, $news, $index, $decided);
    // A name decided on another article still links this one to its record.
    if ($write) {
        foreach ($decided as $known) {
            if (!empty($known['facility_id']) && in_array($known['decision'], array('created', 'matched'), true)) {
                kop_facdisc_link($pdo, $news['id'], $known['facility_id']);
            } elseif ($known['decision'] === 'indigenous_school' && function_exists('kop_ischools_find_by_name')
                && ($school = kop_ischools_find_by_name($pdo, $known['mention'] ?? ''))) {
                kop_ischools_link_news($pdo, (int) $school['id'], (int) $news['id'], 'news-discovery');
            }
        }
    }
    if (!$names) {
        if ($write) {
            kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'none');
        }
        return array('none', array(), '');
    }
    $out = array();
    $asked = array();
    foreach ($names as $name) {
        $kind = kop_facdisc_obvious_kind($name);
        if ($kind === null) {
            $asked[] = $name;
            continue;
        }
        $decision = $kind === 'provider' ? 'provider' : 'not_facility';
        if ($write) {
            kop_facdisc_record($pdo, $name, $news, $decision, null, array('entry' => array('name' => $name, 'kind' => $kind, 'officialName' => $name, 'by' => 'name')));
        }
        $out[$name] = array($decision, null, array('name' => $name, 'kind' => $kind, 'officialName' => $name, 'city' => '', 'state' => '', 'country' => '', 'type' => '', 'status' => 'Unknown'));
    }
    if (!$asked) {
        if ($write) {
            kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'none', 'names only');
        }
        return array('none', $out, '');
    }
    $text = kop_closure_article_text($news['article_url'] ?? '');
    // Without the article's text the model has only the title and summary; a
    // name in neither is a guess (an archived company page listing its sites
    // by town once became "Kissimmee, FL" and a new "Owens Cross Roads"
    // record). Those wait for a fetch that works, up to the three tries.
    $head = kop_facdisc_norm_text(($news['article_title'] ?? '') . ' ' . ($news['summary'] ?? ''));
    if ($text === '') {
        $asked = array_values(array_filter($asked, function ($name) use ($head) { return kop_facdisc_in_source($name, $head); }));
        if (!$asked) {
            if ($write) {
                kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'error', 'no article text, and the names are not in the title or summary');
            }
            return array('error', $out, 'no article text');
        }
    }
    $source = $text === '' ? $head : kop_facdisc_norm_text(($news['article_title'] ?? '') . ' ' . ($news['summary'] ?? '') . ' ' . $text);
    $names = array_slice($asked, 0, 12);
    $lookalikes = array();
    foreach ($names as $name) {
        $lookalikes[$name] = kop_facdisc_lookalikes($pdo, $name, $index);
    }
    try {
        $raw = kop_closure_ai(kop_facdisc_build_prompt($news, $names, $lookalikes, $text), 3000);
    } catch (Throwable $e) {
        if ($write && stripos($e->getMessage(), 'rate limit') === false) {
            kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'error', $e->getMessage());
        }
        return array('error', array(), $e->getMessage());
    }
    $entries = kop_facdisc_parse_reply($raw, $names, $lookalikes);
    if ($entries === null) {
        if ($write) {
            kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'error', 'Unreadable reply: ' . mb_substr((string) $raw, 0, 300));
        }
        return array('error', array(), 'Unreadable reply');
    }
    $created = false;
    foreach ($entries as $name => $entry) {
        // What the article says, not what the model made up: the name or the
        // quote has to be in what it read, or nothing is created or linked.
        $entry['evidenceQuoted'] = $entry['evidence'] !== '' && kop_facdisc_in_source($entry['evidence'], $source);
        $entry['quoted'] = kop_facdisc_in_source($name, $source) || $entry['evidenceQuoted'];
        $entry['noText'] = $text === '';
        try {
            list($decision, $fid) = kop_facdisc_apply_entry($pdo, $entry, $news, $write);
        } catch (Throwable $e) {
            $decision = 'error';
            $fid = null;
            error_log('kop facility discovery: ' . $name . ': ' . $e->getMessage());
        }
        $created = $created || $decision === 'created';
        $out[$name] = array($decision, $fid, $entry);
    }
    if ($created && $write) {
        kop_facdisc_alias_index($pdo, true);
    }
    if ($write) {
        kop_facdisc_record_scan($pdo, $news['id'], $news['hash'], 'found', $text === '' ? 'no article text' : '');
    }
    return array('found', $out, $text === '' ? 'no article text' : '');
}

/**
 * Closure reports whose program had no record: match them again now that
 * the scan may have created it.
 */
function kop_facdisc_rematch_closure_reports(PDO $pdo) {
    if (!$pdo->query("SHOW TABLES LIKE 'facility_closure_reports'")->fetchColumn()) {
        return 0;
    }
    $index = kop_facdisc_alias_index($pdo);
    $n = 0;
    $status = $pdo->prepare('SELECT status FROM facilities_v2 WHERE id = ?');
    $set = $pdo->prepare("UPDATE facility_closure_reports SET facility_id = ?, status = ? WHERE id = ? AND status = 'unmatched'");
    foreach ($pdo->query("SELECT id, program_name, target_status FROM facility_closure_reports WHERE status = 'unmatched'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fid = kop_resolve_mention_to_facility($r['program_name'], $index);
        if ($fid === null) {
            continue;
        }
        $status->execute(array($fid));
        $now = $status->fetchColumn();
        if ($now === false) {
            continue;   // an operator project, not a facility
        }
        $set->execute(array($fid, $now === $r['target_status'] ? 'already' : 'pending', (int) $r['id']));
        $n++;
    }
    return $n;
}

function kop_facdisc_scan_batch(PDO $pdo, $limit, $seconds, $write = true, array $only_ids = array(), $log = null) {
    if ($write) {
        kop_facdisc_ensure_tables($pdo);
    }
    $started = time();
    $counts = array('scanned' => 0, 'none' => 0, 'found' => 0, 'error' => 0, 'created' => 0);
    $created = array();
    foreach (kop_facdisc_articles($pdo, $limit, $only_ids) as $news) {
        list($outcome, $decisions, $detail) = kop_facdisc_scan_article($pdo, $news, $write);
        $counts['scanned']++;
        $counts[$outcome]++;
        foreach ($decisions as $name => $d) {
            if ($d[0] === 'created') {
                $counts['created']++;
                $created[] = array('news' => $news, 'name' => $name, 'facility_id' => $d[1], 'entry' => $d[2]);
            }
        }
        if ($log) {
            $log($news, $outcome, $decisions, $detail);
        }
        if (($outcome === 'error' && stripos($detail, 'rate limit') !== false) || time() - $started > $seconds) {
            break;
        }
    }
    if ($write) {
        $counts['rematched'] = kop_facdisc_rematch_closure_reports($pdo);
        if ($created) {
            do_action('kop_facility_status_changed', 0);
        }
    }
    return array('counts' => $counts, 'created' => $created);
}

/* ---- Removing and creating by hand ---------------------------------- */

/**
 * Take out a record the scan created, while nobody has changed it since:
 * the facility row, its hub placements, identity and article links. The
 * name is kept as 'removed' so the scan never creates it again.
 */
function kop_facdisc_remove(PDO $pdo, $candidate_id, $reviewer) {
    global $wpdb;
    $stmt = $pdo->prepare('SELECT * FROM news_facility_candidates WHERE id = ?');
    $stmt->execute(array((int) $candidate_id));
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['decision'] !== 'created' || !$c['facility_id']) {
        throw new RuntimeException('Only a record the scan created can be removed here.');
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';   // kop_migration_tables(), the write lock
    $detail = json_decode((string) $c['detail'], true) ?: array();
    $fid = (int) $c['facility_id'];
    $opts = array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
    $stored = kop_facility_load($fid, $opts);
    if ($stored && (empty($detail['doc']) || !kop_facility_same_document($detail['doc'], $stored['doc']))) {
        throw new RuntimeException('Someone has edited this record since the scan created it; change or delete it in the data form instead.');
    }
    $lawsuits = $pdo->prepare('SELECT COUNT(*) FROM lawsuit_facility_links WHERE facility_id = ?');
    try {
        $lawsuits->execute(array($fid));
        if ((int) $lawsuits->fetchColumn() > 0) {
            throw new RuntimeException('A lawsuit is linked to this record; unlink it first.');
        }
    } catch (PDOException $e) {
        // No lawsuit links table on this install.
    }
    $t = kop_migration_tables($wpdb->prefix);
    kop_v2_with_write_lock($pdo, function () use ($pdo, $t, $fid, $candidate_id, $reviewer) {
        foreach (array($t['facility_locations'], $t['operator_facilities'], $t['identity']) as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ?")->execute(array($fid));
        }
        $pdo->prepare('DELETE FROM news_facility_links WHERE facility_id = ?')->execute(array($fid));
        if ($pdo->query("SHOW TABLES LIKE 'facility_closure_reports'")->fetchColumn()) {
            $pdo->prepare("UPDATE facility_closure_reports SET facility_id = NULL, status = 'unmatched' WHERE facility_id = ? AND status IN ('pending','already')")
                ->execute(array($fid));
        }
        $pdo->prepare("DELETE FROM `{$t['facilities']}` WHERE id = ?")->execute(array($fid));
        $pdo->prepare("UPDATE news_facility_candidates SET decision = 'removed', reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute(array($reviewer, (int) $candidate_id));
    });
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

/** An admin says a held-back name is an existing record: link the article to it. */
function kop_facdisc_link_by_hand(PDO $pdo, $candidate_id, $facility_id, $reviewer) {
    $stmt = $pdo->prepare('SELECT * FROM news_facility_candidates WHERE id = ?');
    $stmt->execute(array((int) $candidate_id));
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    $fid = (int) $facility_id;
    $exists = $pdo->prepare('SELECT 1 FROM facilities_v2 WHERE id = ?');
    $exists->execute(array($fid));
    if (!$c || $fid <= 0 || !$exists->fetchColumn()) {
        throw new RuntimeException('No such facility.');
    }
    kop_facdisc_link($pdo, $c['news_id'], $fid);
    // Where the name was before, so Remove the link (kop_facdisc_unlink()) can put it back.
    $detail = json_decode((string) $c['detail'], true) ?: array();
    if ($c['decision'] !== 'matched') {
        $detail['linked_from'] = array('decision' => $c['decision'], 'facility_id' => $c['facility_id'] !== null ? (int) $c['facility_id'] : null);
    }
    $pdo->prepare("UPDATE news_facility_candidates SET decision = 'matched', facility_id = ?, detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute(array($fid, wp_json_encode($detail), $reviewer, (int) $candidate_id));
    return $fid;
}

/**
 * Take back a link to a record: the article leaves that facility's page
 * (unless another name from the same article is still linked to it) and the
 * name goes back to where it was before, "To decide" when that is not known.
 * Only the link the news scan made is removed; one added elsewhere stays.
 */
function kop_facdisc_unlink(PDO $pdo, $candidate_id, $reviewer) {
    $stmt = $pdo->prepare('SELECT * FROM news_facility_candidates WHERE id = ?');
    $stmt->execute(array((int) $candidate_id));
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['decision'] !== 'matched' || (int) $c['facility_id'] <= 0) {
        throw new RuntimeException('This name is not linked to a record.');
    }
    $fid = (int) $c['facility_id'];
    $others = $pdo->prepare("SELECT COUNT(*) FROM news_facility_candidates WHERE news_id = ? AND facility_id = ? AND id <> ? AND decision IN ('matched', 'created')");
    $others->execute(array((int) $c['news_id'], $fid, (int) $c['id']));
    if (!(int) $others->fetchColumn()) {
        $pdo->prepare("DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ? AND created_by = 'news-discovery'")
            ->execute(array((int) $c['news_id'], $fid));
    }
    $detail = json_decode((string) $c['detail'], true) ?: array();
    $from = (array) ($detail['linked_from'] ?? array());
    unset($detail['linked_from']);
    $decision = (string) ($from['decision'] ?? '');
    if (!in_array($decision, array('possible_duplicate', 'other_era', 'needs_place', 'unquoted', 'provider', 'not_facility', 'removed'), true)) {
        $decision = 'possible_duplicate';
    }
    // The record stays as the suggestion the card offers, unless the name had none before.
    $suggest = array_key_exists('facility_id', $from) ? $from['facility_id'] : $fid;
    $pdo->prepare('UPDATE news_facility_candidates SET decision = ?, facility_id = ?, detail = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute(array($decision, $suggest ? (int) $suggest : null, wp_json_encode($detail), $reviewer, (int) $c['id']));
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

/**
 * One time: take back the links made by hand on 2026-10-05, when the queue's
 * cards made "link" the obvious click (ten names around 16:25 UTC and
 * Project RENEW at 20:04; Kissimmee was already undone). Each goes back to
 * "To decide" to be decided again on the reworked card; a link someone has
 * since changed is left alone.
 */
add_action('init', function () {
    // The first run fell in the middle of a deploy and undid nothing; this one
    // counts as done only when every link is gone, and tries at most five times.
    $state = get_option('kop_facdisc_undo_20261005_v2');
    if ((is_array($state) && (!empty($state['done']) || (int) ($state['tries'] ?? 0) >= 5)) || !function_exists('kop_closure_pdo')) {
        return;
    }
    $state = array('tries' => (int) (is_array($state) ? ($state['tries'] ?? 0) : 0) + 1, 'at' => gmdate('c'), 'done' => false, 'undone' => array(), 'errors' => array());
    update_option('kop_facdisc_undo_20261005_v2', $state, false);
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) {
            throw new RuntimeException('no records DB connection');
        }
        $row = $pdo->prepare('SELECT decision, reviewed_by FROM news_facility_candidates WHERE id = ?');
        $left = 0;
        foreach (array(506, 504, 496, 487, 477, 475, 455, 433, 426, 421, 486) as $id) {
            $row->execute(array($id));
            $c = $row->fetch(PDO::FETCH_ASSOC);
            $row->closeCursor();
            if (!$c || $c['decision'] !== 'matched' || $c['reviewed_by'] !== 'admin') {
                continue;
            }
            try {
                kop_facdisc_unlink($pdo, $id, 'admin (undo of 2026-10-05 links)');
                $state['undone'][] = $id;
            } catch (Throwable $e) {
                $left++;
                $state['errors'][] = $id . ': ' . $e->getMessage();
            }
        }
        $state['done'] = $left === 0;
    } catch (Throwable $e) {
        $state['errors'][] = $e->getMessage();
    }
    update_option('kop_facdisc_undo_20261005_v2', $state, false);
}, 40);

/**
 * One time: cards waiting in "To decide" whose name is only a town
 * ("Kissimmee", "Madison", "Courtland" from a company's list of sites) go to
 * Skipped, as the scan now files them; each keeps "Undo: back to To decide".
 */
add_action('init', function () {
    if (get_option('kop_facdisc_place_names_v1') || !function_exists('kop_closure_pdo')) {
        return;
    }
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) {
            return;
        }
        update_option('kop_facdisc_place_names_v1', gmdate('c'), false);
        $rows = $pdo->query("SELECT id, mention, decision, detail FROM news_facility_candidates
                             WHERE decision IN ('possible_duplicate','other_era','needs_place','unquoted')")->fetchAll(PDO::FETCH_ASSOC);
        $set = $pdo->prepare("UPDATE news_facility_candidates SET decision = 'not_facility', detail = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?");
        $moved = array();
        foreach ($rows as $r) {
            $detail = json_decode((string) $r['detail'], true) ?: array();
            $e = (array) ($detail['entry'] ?? array());
            if (!kop_facdisc_is_place_name($pdo, $r['mention'], $e['state'] ?? '', $e['city'] ?? '')) {
                continue;
            }
            $detail['dismissed_from'] = $r['decision'];
            $detail['entry']['by'] = 'place name';
            $set->execute(array(wp_json_encode($detail), (int) $r['id']));
            $moved[] = (int) $r['id'] . ' ' . $r['mention'];
        }
        update_option('kop_facdisc_place_names_v1', array('at' => gmdate('c'), 'moved' => $moved), false);
    } catch (Throwable $e) {
        error_log('kop facility discovery place names: ' . $e->getMessage());
    }
}, 41);

/** Create a record for a name the scan held back, with the fields an admin filled in. */
function kop_facdisc_create_by_hand(PDO $pdo, $candidate_id, array $fields, $reviewer) {
    $stmt = $pdo->prepare('SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                             FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id WHERE c.id = ?');
    $stmt->execute(array((int) $candidate_id));
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c || in_array($c['decision'], array('created', 'matched'), true)) {
        throw new RuntimeException('This name already has a record.');
    }
    $detail = json_decode((string) $c['detail'], true) ?: array();
    $entry = array_merge(array(
        'name' => $c['mention'], 'kind' => 'facility', 'sameAs' => null, 'renameOf' => null, 'officialName' => $c['mention'], 'otherNames' => array(),
        'city' => '', 'state' => '', 'country' => '', 'type' => '', 'status' => 'Unknown', 'startYear' => null, 'endYear' => null,
        'operator' => '', 'gender' => '', 'evidence' => '',
    ), $detail['entry'] ?? array());
    foreach (array('officialName', 'city', 'state', 'country', 'type') as $k) {
        if (isset($fields[$k])) {
            $entry[$k] = trim((string) $fields[$k]);
        }
    }
    $entry['state'] = strtoupper($entry['state']);
    if ($entry['officialName'] === '' || ($entry['state'] === '' && $entry['country'] === '')) {
        throw new RuntimeException('A name and a state or country are needed.');
    }
    $news = array('id' => (int) $c['news_id'], 'article_title' => $c['article_title'], 'publication_name' => $c['publication_name'],
        'publication_date' => $c['publication_date'], 'article_url' => $c['article_url']);
    list($decision, $fid, $doc) = kop_facdisc_create($pdo, $entry, $news);
    kop_facdisc_link($pdo, $news['id'], $fid);
    $detail['entry'] = $entry;
    if ($doc) {
        $detail['doc'] = $doc;
    }
    kop_facdisc_record($pdo, $c['mention'], $news, $decision, $fid, $detail, $reviewer);
    kop_facdisc_alias_index($pdo, true);
    kop_facdisc_rematch_closure_reports($pdo);
    do_action('kop_facility_status_changed', $fid);
    return array($decision, $fid);
}

/* ---- Hourly scan ---------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_facility_discovery_hourly')) {
        // Half an hour off the closure scan, so the two share Groq's
        // per-minute allowance rather than meeting in the same minute.
        wp_schedule_event(time() + 1800, 'hourly', 'kop_facility_discovery_hourly');
    }
});

add_action('kop_facility_discovery_hourly', 'kop_facdisc_cron');

function kop_facdisc_cron() {
    if (get_transient('kop_facility_discovery_lock')) {
        return;
    }
    set_transient('kop_facility_discovery_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) {
            return;
        }
        $result = kop_facdisc_scan_batch($pdo, 60, 150);
        if ($result['created'] && function_exists('kop_notify_admins')) {
            $fields = array();
            foreach (array_slice($result['created'], 0, 20) as $i => $item) {
                $e = $item['entry'];
                $fields['Facility ' . ($i + 1)] = $e['officialName'] . ' (' . trim(implode(', ', array_filter(array($e['city'], $e['state'] ?: $e['country'])))) . ')'
                    . ' #' . $item['facility_id'] . ' - from: ' . $item['news']['article_title'];
            }
            $n = count($result['created']);
            kop_notify_admins('new_facility', $n . ' new ' . ($n === 1 ? 'facility' : 'facilities') . ' added from the news', '', $fields);
        }
    } catch (Throwable $e) {
        error_log('kop facility discovery: ' . $e->getMessage());
    } finally {
        delete_transient('kop_facility_discovery_lock');
    }
}

/* ---- Review screen -------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Facilities from News', 'Facilities from News', 'manage_options',
        'kop-facilities-from-news', 'kop_render_facilities_from_news_page');
}, 21);

function kop_render_facilities_from_news_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_closure_pdo();
    echo '<div class="wrap"><h1>Facilities from News</h1>'
        . '<style>.kop-fd-box{margin:0 0 10px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde}.kop-fd-hint{color:#666;font-size:12px}</style>';
    if (!$pdo) {
        echo '<p>The records database is not reachable.</p></div>';
        return;
    }
    kop_facdisc_ensure_tables($pdo);
    $decisions = kop_facdisc_decisions();
    $user = wp_get_current_user()->user_login;
    $base = admin_url('admin.php?page=kop-facilities-from-news');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_fd_action'])) {
        check_admin_referer('kop_facilities_from_news');
        $id = (int) ($_POST['kop_fd_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT c.mention, c.facility_id, n.article_title FROM news_facility_candidates c
                                 LEFT JOIN news_submissions n ON n.id = c.news_id WHERE c.id = ?');
        $stmt->execute(array($id));
        $cand = $stmt->fetch(PDO::FETCH_ASSOC) ?: array('mention' => '', 'facility_id' => 0, 'article_title' => '');
        $article = $cand['article_title'] !== '' && $cand['article_title'] !== null ? '&ldquo;' . esc_html($cand['article_title']) . '&rdquo;' : 'the article';
        try {
            // Messages are built from escaped parts, so they are printed as HTML.
            switch (sanitize_key($_POST['kop_fd_action'])) {
                case 'remove':
                    $was = $cand['facility_id'] ? kop_facility_finder_label($pdo, (int) $cand['facility_id']) : '&ldquo;' . esc_html($cand['mention']) . '&rdquo;';
                    kop_facdisc_remove($pdo, $id, $user);
                    $msg = 'Removed the record for ' . $was . '. The scan will not create it again.';
                    break;
                case 'create':
                    list($decision, $fid) = kop_facdisc_create_by_hand($pdo, $id, wp_unslash($_POST), $user);
                    $msg = $decision === 'created'
                        ? 'Created ' . kop_facility_finder_label($pdo, $fid) . ' and linked ' . $article . ' to it.'
                        : 'That name and place is already in the database as ' . kop_facility_finder_label($pdo, $fid) . ', so ' . $article . ' is linked to it. No new record was made.';
                    break;
                case 'link':
                    $fid = kop_facdisc_link_by_hand($pdo, $id, (int) ($_POST['kop_fd_facility'] ?? 0), $user);
                    $msg = 'Linked ' . $article . ' to ' . kop_facility_finder_label($pdo, $fid) . '. It now lists the article.';
                    break;
                case 'scan':
                    $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($_POST['kop_fd_news'] ?? ''))));
                    $lines = array();
                    $log = static function ($news, $outcome, $decided, $detail) use (&$lines, $decisions) {
                        if ($outcome === 'error') {
                            $lines[] = '#' . $news['id'] . ': failed (' . $detail . ')';
                        }
                        foreach ($decided as $name => $d) {
                            $lines[] = '#' . $news['id'] . ': ' . $name . ' - ' . ($decisions[$d[0]] ?? $d[0]) . ($d[1] ? ' #' . $d[1] : '');
                        }
                    };
                    $r = kop_facdisc_scan_batch($pdo, $ids ? count($ids) : 15, 110, true, $ids, $log);
                    $msg = esc_html(sprintf('Scanned %d articles: %d new facilities, %d closure reports matched, %d failed%s.',
                        $r['counts']['scanned'], $r['counts']['created'], $r['counts']['rematched'] ?? 0, $r['counts']['error'],
                        $r['counts']['error'] ? ' (Groq and Gemini allow a few articles a minute; the hourly run carries on)' : ''));
                    if ($lines) {
                        echo '<div class="notice notice-info"><p>' . implode('<br>', array_map('esc_html', $lines)) . '</p></div>';
                    }
                    break;
                default:
                    $msg = '';
            }
            if ($msg !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    $filter = isset($_GET['fd']) ? sanitize_key($_GET['fd']) : 'recent';
    if ($filter !== 'recent' && !isset($decisions[$filter])) {
        $filter = 'recent';
    }
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_facility_scans s ON s.news_id = n.id
                                   WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();
    $counts = array();
    foreach ($pdo->query('SELECT decision, COUNT(*) AS n FROM news_facility_candidates GROUP BY decision') as $row) {
        $counts[$row['decision']] = (int) $row['n'];
    }
    echo '<p>Facilities the hourly news scan found that were not in the database. A specific youth residential program with a known state or country '
        . 'is added as a new record from what the article says, cited in its notes, placed on its state hub and linked to the article. '
        . 'One already in the database under another spelling is linked to that record. Remove takes out a record the scan created while nobody has edited it.</p>';
    echo '<p style="color:#666">' . $waiting . ' saved articles not scanned yet. The hourly run gets through a handful at a time (the Groq and Gemini free limits), '
        . 'likeliest programs first.</p>';
    echo '<ul class="subsubsub"><li><a href="' . esc_url(add_query_arg('fd', 'recent', $base)) . '"' . ($filter === 'recent' ? ' class="current"' : '') . '>'
        . 'All recent (' . (int) array_sum($counts) . ')</a> | </li>';
    $i = 0;
    foreach ($decisions as $key => $label) {
        echo '<li><a href="' . esc_url(add_query_arg('fd', $key, $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>'
            . esc_html($label) . ' (' . (int) ($counts[$key] ?? 0) . ')</a>' . (++$i < count($decisions) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';
    echo '<form method="post" style="margin:8px 0 16px">';
    wp_nonce_field('kop_facilities_from_news');
    echo '<input type="hidden" name="kop_fd_action" value="scan"><button type="submit" class="button">Scan the next 10 articles now</button> '
        . '<label style="color:#666">or only these article numbers (optional) <input type="text" name="kop_fd_news" style="width:160px"></label></form>';

    $stmt = $pdo->prepare('SELECT c.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                             FROM news_facility_candidates c LEFT JOIN news_submissions n ON n.id = c.news_id
                            WHERE (? = \'recent\' OR c.decision = ?) ORDER BY c.updated_at DESC, c.id DESC LIMIT 300');
    $stmt->execute(array($filter, $filter));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo '<p>Nothing here.</p></div>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th style="width:40px">#</th><th style="width:260px">Name in the article</th>'
        . '<th>What the scan found</th><th style="width:300px">Action</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $detail = json_decode((string) $r['detail'], true) ?: array();
        $e = $detail['entry'] ?? array();
        echo '<tr><td>' . (int) $r['id'] . '</td><td><strong>' . esc_html($r['mention']) . '</strong>'
            . ($filter === 'recent' ? ' <span style="color:#666">(' . esc_html($decisions[$r['decision']] ?? $r['decision']) . ')</span>' : '') . '<br><a href="' . esc_url($r['article_url']) . '" target="_blank" rel="noopener">'
            . esc_html($r['article_title'] ?: 'article #' . (int) $r['news_id']) . '</a><br><span style="color:#666">' . esc_html(trim($r['publication_name'] . ' ' . $r['publication_date'])) . '</span></td><td>';
        if ($r['facility_id']) {
            echo kop_closure_facility_cell($pdo, (int) $r['facility_id']) . '<br>';
        }
        if ($e) {
            $place = trim(implode(', ', array_filter(array($e['city'] ?? '', ($e['state'] ?? '') ?: ($e['country'] ?? '')))));
            echo esc_html(ucfirst($e['kind'] ?? '')) . ': ' . esc_html($e['officialName'] ?? '') . ($place ? ' &middot; ' . esc_html($place) : '')
                . (!empty($e['type']) ? ' &middot; ' . esc_html($e['type']) : '') . (!empty($e['status']) && $e['status'] !== 'Unknown' ? ' &middot; ' . esc_html($e['status']) : '');
            if (!empty($e['evidence'])) {
                echo '<blockquote style="margin:6px 0;padding-left:8px;border-left:3px solid #33A7B5">' . esc_html($e['evidence']) . '</blockquote>';
            }
        }
        echo '</td><td>';
        if ($r['decision'] === 'created') {
            echo '<form method="post">';
            wp_nonce_field('kop_facilities_from_news');
            echo '<input type="hidden" name="kop_fd_action" value="remove"><input type="hidden" name="kop_fd_id" value="' . (int) $r['id'] . '">'
                . '<button type="submit" class="button button-small" onclick="return confirm(\'Remove the record the scan created for this?\')">Remove the record it created</button></form>';
        } elseif ($r['decision'] === 'indigenous_school') {
            echo 'Filed at <a href="' . esc_url(admin_url('admin.php?page=kop-indigenous-schools')) . '">Indigenous Schools</a>, not as a facility.';
        } elseif (in_array($r['decision'], array('possible_duplicate', 'other_era', 'needs_place', 'unquoted', 'provider', 'not_facility', 'removed'), true)) {
            echo '<form method="post" class="kop-fd-box">';
            wp_nonce_field('kop_facilities_from_news');
            echo '<input type="hidden" name="kop_fd_action" value="link"><input type="hidden" name="kop_fd_id" value="' . (int) $r['id'] . '">'
                . '<strong>Already in the database?</strong> Pick it:<br>' . kop_facility_finder_field('kop_fd_facility', $r['facility_id'])
                . ($r['facility_id'] ? '<br><span class="kop-fd-hint">Filled in with the closest record (left column). Search to change it.</span>' : '')
                . '<br><button type="submit" class="button button-small">Link the article to this facility</button></form>';
            echo '<form method="post" class="kop-fd-box"><strong>Not in the database?</strong> Create it:';
            wp_nonce_field('kop_facilities_from_news');
            echo '<input type="hidden" name="kop_fd_action" value="create"><input type="hidden" name="kop_fd_id" value="' . (int) $r['id'] . '">';
            $field = static function ($name, $label, $value, $width) {
                echo '<label style="display:block;margin-bottom:3px">' . esc_html($label) . ' <input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" style="width:' . (int) $width . 'px"></label>';
            };
            $field('officialName', 'Name', $e['officialName'] ?? $r['mention'], 200);
            $field('city', 'City', $e['city'] ?? '', 130);
            $field('state', 'State', $e['state'] ?? '', 40);
            $field('country', 'Country', $e['country'] ?? '', 130);
            echo '<label style="display:block;margin-bottom:4px">Type <select name="type"><option value=""></option>';
            foreach (kop_facdisc_types() as $type) {
                echo '<option' . (($e['type'] ?? '') === $type ? ' selected' : '') . '>' . esc_html($type) . '</option>';
            }
            echo '</select></label><button type="submit" class="button button-small">Create this facility</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
