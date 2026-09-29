<?php
/**
 * Open data: bulk downloads of everything the site publishes, for advocates,
 * researchers and journalists (the /open-data/ page, templates/page-open-data.php).
 *
 * A daily build writes every dataset as CSV and JSON into
 * uploads/kop-open-data/, bundles them into one ZIP, and writes a
 * manifest.json the page reads (row counts, sizes, checksums, build time).
 * The full text of every inspection report is its own gzipped JSON Lines
 * download; at about 300 MB raw it is built a slice at a time, appending
 * gzip members to one file, so a WP-Cron request that gets cut off simply
 * resumes on the next run.
 *
 * Every dataset names its columns. Nothing is exported with SELECT *, so a
 * private column added to a table later is never published by accident.
 * Kept out on purpose: submitter and reviewer fields, unapproved or
 * unpublished records, unpublished survivor testimony, referrer contact
 * details, survivors named in news records, and the internal journalist list.
 *
 * Build it now from the command line with scripts/build-open-data.php.
 * Licensed CC BY-SA 4.0 (DATA-LICENSE.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_OPEN_DATA_SLUG', 'open-data');
define('KOP_OPEN_DATA_LICENSE', 'CC BY-SA 4.0');
define('KOP_OPEN_DATA_LICENSE_URL', 'https://creativecommons.org/licenses/by-sa/4.0/');
define('KOP_OPEN_DATA_SOURCE_URL', 'https://github.com/carlygaejepsen/Kids-Over-Profits');
define('KOP_OPEN_DATA_FULLTEXT', 'inspection-reports-full-text.jsonl.gz');
define('KOP_OPEN_DATA_ZIP', 'kids-over-profits-open-data.zip');
// Report text scraped from state PDFs can carry broken UTF-8; substitute rather than lose the row.
define('KOP_OPEN_DATA_JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

/** array('path' => dir, 'url' => base url) of the published folder. */
function kop_open_data_dir() {
    $uploads = wp_upload_dir(null, false);
    return array(
        'path' => rtrim($uploads['basedir'], '/\\') . '/kop-open-data',
        'url'  => rtrim($uploads['baseurl'], '/') . '/kop-open-data',
    );
}

/** The last build's manifest, or null before the first build. */
function kop_open_data_manifest() {
    $file = kop_open_data_dir()['path'] . '/manifest.json';
    if (!is_readable($file)) return null;
    $manifest = json_decode((string) file_get_contents($file), true);
    return is_array($manifest) ? $manifest : null;
}

/* ---- Sources ------------------------------------------------------------ */

/**
 * Rows from a query in id order, a batch at a time, so a large table never
 * sits in memory whole. $fetch($after_id, $limit) returns the next batch.
 */
function kop_open_data_batches(callable $fetch, $id_column = 'id', $limit = 1000) {
    $after = 0;
    while (true) {
        $rows = $fetch($after, $limit);
        if (!$rows) return;
        foreach ($rows as $row) {
            yield $row;
        }
        $after = (int) end($rows)[$id_column];
        if (count($rows) < $limit) return;
    }
}

/** Keyset batches from the records database (api/config.php). */
function kop_open_data_pdo_rows(PDO $pdo, $select, $from_where, $id_column = 'id', $limit = 1000) {
    $where_joiner = stripos($from_where, ' WHERE ') !== false ? ' AND ' : ' WHERE ';
    return kop_open_data_batches(static function ($after, $limit) use ($pdo, $select, $from_where, $where_joiner, $id_column) {
        $stmt = $pdo->prepare("SELECT $select FROM $from_where{$where_joiner}$id_column > ? ORDER BY $id_column LIMIT " . (int) $limit);
        $stmt->execute(array($after));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, preg_replace('/^.*\./', '', $id_column), $limit);
}

/** Keyset batches from the WordPress database. */
function kop_open_data_wpdb_rows($select, $table, $id_column = 'id', $limit = 1000) {
    global $wpdb;
    return kop_open_data_batches(static function ($after, $limit) use ($wpdb, $select, $table, $id_column) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT $select FROM $table WHERE $id_column > %d ORDER BY $id_column LIMIT %d",
            $after,
            $limit
        ), ARRAY_A);
    }, $id_column, $limit);
}

function kop_open_data_table_exists(PDO $pdo, $table) {
    try {
        $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function kop_open_data_json_list($value) {
    $list = is_array($value) ? $value : json_decode((string) $value, true);
    return is_array($list) ? $list : array();
}

/** Names out of a list that may hold strings or {name: ...} objects. */
function kop_open_data_names($list) {
    $names = array();
    foreach ((array) $list as $item) {
        if (is_array($item)) $item = $item['name'] ?? ($item['text'] ?? '');
        $item = trim((string) $item);
        if ($item !== '') $names[] = $item;
    }
    return array_values(array_unique($names));
}

/* ---- Datasets ----------------------------------------------------------- */

/**
 * Every dataset, key => array(title, description, group, rows => callable
 * returning an iterable of rows, or null when its source is missing). A row
 * is an associative array; list and object values are written to CSV as
 * "a; b" or as JSON text, and kept as they are in the JSON file.
 */
function kop_open_data_datasets() {
    global $wpdb;
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    $p = $wpdb->prefix;

    $sets = array();

    /* Facilities and operators ------------------------------------------ */

    $sets['facilities'] = array(
        'title'       => 'Facilities',
        'group'       => 'Facilities and operators',
        'description' => 'Every program, school, ranch, wilderness program and residential facility in the database: names, status, type, address, years open, operators and accreditations. The JSON file also carries the full facility record (published survivor testimony only).',
        'rows'        => static function () use ($wpdb, $p) {
            $operators = array();
            foreach ((array) $wpdb->get_results(
                "SELECT l.facility_id, o.name, l.relationship FROM {$p}kop_operator_facilities l
                 JOIN {$p}kop_operators o ON o.id = l.operator_id ORDER BY l.sort_order", ARRAY_A) as $link) {
                $operators[(int) $link['facility_id']][] = $link['name'] . ($link['relationship'] !== '' && $link['relationship'] !== 'current' ? ' (' . $link['relationship'] . ')' : '');
            }
            $pages = function_exists('kop_facility_pages_index') ? kop_facility_pages_index()['ids'] : array();

            foreach (kop_open_data_wpdb_rows('id, name, status, facility_type, state, city, country, start_year, end_year, json_data', 'facilities_v2') as $row) {
                $data = json_decode((string) $row['json_data'], true);
                if (!is_array($data)) $data = array();
                if (function_exists('kop_facility_testimony_redact')) $data = kop_facility_testimony_redact($data);
                unset($data['provenance'], $data['legacy']);
                $ident = $data['identification'] ?? array();
                $loc = $data['location'] ?? array();
                $accred = $data['accreditations'] ?? array();
                $id = (int) $row['id'];
                yield array(
                    'id'             => $id,
                    'name'           => (string) $row['name'],
                    'other_names'    => kop_open_data_names(array_merge((array) ($ident['otherNames'] ?? array()), (array) ($ident['pastNames'] ?? array()))),
                    'status'         => (string) $row['status'],
                    'facility_type'  => (string) $row['facility_type'],
                    'street'         => (string) ($loc['street'] ?? ''),
                    'city'           => (string) $row['city'],
                    'state'          => (string) $row['state'],
                    'zip'            => (string) ($loc['zip'] ?? ''),
                    'country'        => (string) $row['country'],
                    'start_year'     => (string) $row['start_year'],
                    'end_year'       => (string) $row['end_year'],
                    'operators'      => $operators[$id] ?? array(),
                    'accreditations' => kop_open_data_names($accred['current'] ?? array()),
                    'past_accreditations' => kop_open_data_names($accred['past'] ?? array()),
                    'page_url'       => isset($pages[$id]['slug']) ? kop_facility_pages_url_for_slug($pages[$id]['slug']) : '',
                    'record'         => $data,
                );
            }
        },
        'json_only'   => array('record'),
    );

    $sets['operators'] = array(
        'title'       => 'Operators and parent companies',
        'group'       => 'Facilities and operators',
        'description' => 'The companies, chains and organizations that own or run facilities, with how many facilities each is linked to. The JSON file carries the full operator record.',
        'rows'        => static function () use ($wpdb, $p) {
            $counts = array();
            foreach ((array) $wpdb->get_results("SELECT operator_id, COUNT(*) AS n FROM {$p}kop_operator_facilities GROUP BY operator_id", ARRAY_A) as $c) {
                $counts[(int) $c['operator_id']] = (int) $c['n'];
            }
            foreach (kop_open_data_wpdb_rows('id, name, json_data', "{$p}kop_operators") as $row) {
                $data = json_decode((string) $row['json_data'], true);
                $operator = is_array($data) && isset($data['operator']) && is_array($data['operator']) ? $data['operator'] : array();
                if (function_exists('kop_facility_testimony_redact')) $operator = kop_facility_testimony_redact($operator);
                $id = (int) $row['id'];
                yield array(
                    'id'             => $id,
                    'name'           => (string) $row['name'],
                    'status'         => (string) ($operator['status'] ?? ''),
                    'founded'        => (string) ($operator['founded'] ?? ''),
                    'facility_count' => $counts[$id] ?? 0,
                    'page_url'       => function_exists('kop_operator_page_url') ? kop_operator_page_url($id) : '',
                    'record'         => $operator,
                );
            }
        },
        'json_only'   => array('record'),
    );

    $sets['operator-facilities'] = array(
        'title'       => 'Operator to facility links',
        'group'       => 'Facilities and operators',
        'description' => 'Which operator runs or ran which facility. Join on operator_id and facility_id.',
        'rows'        => static function () use ($wpdb, $p) {
            return (array) $wpdb->get_results("SELECT operator_id, facility_id, relationship FROM {$p}kop_operator_facilities ORDER BY operator_id, sort_order", ARRAY_A);
        },
    );

    $sets['facility-addresses'] = array(
        'title'       => 'Facility addresses over time',
        'group'       => 'Facilities and operators',
        'description' => 'Physical addresses and which facility stood at each, with years where known. One address can host several programs over the years.',
        'rows'        => static function () use ($wpdb, $p) {
            return (array) $wpdb->get_results(
                "SELECT a.id AS address_id, a.street, a.city, a.state, a.zip, a.country, fa.facility, fa.role, fa.from_year, fa.to_year
                 FROM {$p}kop_facility_addresses fa JOIN {$p}kop_addresses a ON a.id = fa.address_id
                 ORDER BY a.state, a.city, a.id", ARRAY_A);
        },
    );

    if ($pdo && kop_open_data_table_exists($pdo, 'referrers_master')) {
        $sets['referrers'] = array(
            'title'       => 'Referrers',
            'group'       => 'Facilities and operators',
            'description' => 'Educational consultants and others who refer families to programs. Contact details are left out: this documents referral activity, it is not a listing service.',
            'rows'        => static function () use ($pdo) {
                foreach (kop_open_data_pdo_rows($pdo, 'id, unique_name, json_data', 'referrers_master') as $row) {
                    $data = json_decode((string) $row['json_data'], true);
                    if (!is_array($data)) continue;
                    $data = kop_open_data_strip_keys($data, array('phone', 'email', 'phoneNumber', 'emailAddress', 'fax'));
                    if (function_exists('kop_facility_testimony_redact')) $data = kop_facility_testimony_redact($data);
                    yield array(
                        'id'     => (int) $row['id'],
                        'name'   => (string) ($data['name'] ?? $row['unique_name']),
                        'record' => $data['data'] ?? $data,
                    );
                }
            },
            'json_only'   => array('record'),
        );
    }

    /* Inspections -------------------------------------------------------- */

    if ($pdo && kop_open_data_table_exists($pdo, 'inspection_facilities')) {
        $sets['inspection-facilities'] = array(
            'title'       => 'Licensed facilities from state inspection records',
            'group'       => 'State inspections',
            'description' => 'Facilities as the state licensing agencies list them, from the state inspection trackers.',
            'rows'        => static function () use ($pdo) {
                return kop_open_data_pdo_rows($pdo,
                    'id, state, facility_name, full_address, phone, program_category, program_name, executive_director, bed_capacity, license_exp_date, relicense_visit_date, action',
                    'inspection_facilities');
            },
        );
        $sets['inspection-reports'] = array(
            'title'       => 'Inspection reports index',
            'group'       => 'State inspections',
            'description' => 'One row per inspection or complaint report: facility, date, summary, the short fields the state records (report type, visit date, citations, risk level) and a link to the state\'s copy. Narratives and the full text of every report are the separate download at the top.',
            'rows'        => static function () use ($pdo) {
                foreach (kop_open_data_pdo_rows($pdo,
                    'r.id, r.facility_id, f.state, f.facility_name, r.report_id, r.report_date, r.report_url, r.content_length, r.summary, r.categories_json',
                    'inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id', 'r.id') as $row) {
                    $row['fields'] = kop_open_data_short_fields($row['categories_json']);
                    unset($row['categories_json']);
                    yield $row;
                }
            },
        );
    }

    if ($pdo && function_exists('kop_ih_severe_query') && kop_open_data_table_exists($pdo, 'inspection_highlights')) {
        $sets['severe-findings'] = array(
            'title'       => 'Severe findings',
            'group'       => 'State inspections',
            'description' => 'Serious findings from inspection reports (abuse, restraint injuries, sexual abuse, deaths and more) that an admin has reviewed and approved, as on the Severe Reports page.',
            'rows'        => static function () use ($pdo) {
                $labels = function_exists('kop_ih_categories') ? kop_ih_categories() : array();
                list($sql, $params) = kop_ih_severe_query();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $docs = $pdo->prepare('SELECT report_url, categories_json FROM inspection_reports WHERE id = ?');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $kinds = array();
                    foreach (array_filter(array_map('trim', explode(',', (string) $row['categories']))) as $kind) {
                        $kinds[] = $labels[$kind]['label'] ?? $kind;
                    }
                    $url = (string) $row['report_url'];
                    if (function_exists('kop_ih_document_url')) {
                        $docs->execute(array((int) $row['report_id']));
                        $doc = $docs->fetch(PDO::FETCH_ASSOC);
                        if ($doc) $url = kop_ih_document_url($doc['report_url'], $doc['categories_json']);
                    }
                    yield array(
                        'id'                => (int) $row['id'],
                        'state'             => (string) $row['state'],
                        'facility_name'     => (string) $row['facility_name'],
                        'finding_date'      => (string) $row['finding_date'],
                        'kinds_of_harm'     => $kinds,
                        'excerpt'           => (string) $row['excerpt'],
                        'standard'          => (string) $row['standard'],
                        'state_label'       => (string) $row['state_label'],
                        'corrected_on_site' => (string) $row['corrected_on_site'],
                        'report_date'       => (string) $row['report_date'],
                        'report_url'        => $url,
                    );
                }
            },
        );
    }

    /* Courts, lawmakers and the press ----------------------------------- */

    if ($pdo && kop_open_data_table_exists($pdo, 'lawsuits')) {
        $sets['lawsuits'] = array(
            'title'       => 'Lawsuits',
            'group'       => 'Lawsuits, legislation and news',
            'description' => 'Published lawsuits against programs, operators and staff: court, parties, claims, outcome, settlement and source links.',
            'rows'        => static function () use ($pdo) {
                foreach (kop_open_data_pdo_rows($pdo,
                    'id, case_name, case_number, court, jurisdiction, filing_date, status, plaintiffs, defendants, facilities_mentioned, staff_mentioned, organizations_mentioned, claims, outcome, settlement_amount, summary, source_urls, document_urls, tags, published_at',
                    "lawsuits WHERE publication_status = 'published'") as $row) {
                    foreach (array('plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'source_urls', 'document_urls', 'tags') as $f) {
                        $row[$f] = kop_open_data_json_list($row[$f]);
                    }
                    yield $row;
                }
            },
        );
        if (kop_open_data_table_exists($pdo, 'lawsuit_facility_links')) {
            $sets['lawsuit-facilities'] = array(
                'title'       => 'Lawsuit to facility links',
                'group'       => 'Lawsuits, legislation and news',
                'description' => 'Which facilities each published lawsuit involves. facility_id matches the facilities dataset.',
                'rows'        => static function () use ($pdo) {
                    return $pdo->query("SELECT l.lawsuit_id, l.facility_id, l.link_type FROM lawsuit_facility_links l
                        JOIN lawsuits s ON s.id = l.lawsuit_id WHERE s.publication_status = 'published'
                        ORDER BY l.lawsuit_id, l.facility_id")->fetchAll(PDO::FETCH_ASSOC);
                },
            );
        }
    }

    if ($pdo && kop_open_data_table_exists($pdo, 'legislation')) {
        $sets['legislation'] = array(
            'title'       => 'Legislation',
            'group'       => 'Lawsuits, legislation and news',
            'description' => 'Published bills on youth residential treatment and the TTI: sponsors, status, last action, our position and links to the text.',
            'rows'        => static function () use ($pdo) {
                foreach (kop_open_data_pdo_rows($pdo,
                    'id, bill_number, bill_title, jurisdiction, chamber, session_year, bill_type, sponsors, status, introduced_date, last_action_date, last_action_text, subject_tags, summary, full_text_url, official_url, position, facilities_affected, tags, published_at',
                    "legislation WHERE publication_status = 'published'") as $row) {
                    foreach (array('sponsors', 'subject_tags', 'facilities_affected', 'tags') as $f) {
                        $row[$f] = kop_open_data_json_list($row[$f]);
                    }
                    yield $row;
                }
            },
        );
    }

    if ($pdo && kop_open_data_table_exists($pdo, 'news_submissions')) {
        $sets['news'] = array(
            'title'       => 'News coverage index',
            'group'       => 'Lawsuits, legislation and news',
            'description' => 'Approved news coverage of the TTI: headline, outlet, author, date, link, location, our summary and the facilities and staff it names. Article text belongs to the publishers and is not included.',
            'rows'        => static function () use ($pdo) {
                foreach (kop_open_data_pdo_rows($pdo,
                    'id, article_title, alternate_title, author, publication_name, publication_date, article_url, article_type, article_location, tags, facilities_mentioned, staff_mentioned, content_warnings, summary',
                    "news_submissions WHERE status IN ('approved','published')") as $row) {
                    foreach (array('tags', 'staff_mentioned', 'content_warnings') as $f) {
                        $row[$f] = kop_open_data_json_list($row[$f]);
                    }
                    $row['facilities_mentioned'] = kop_open_data_names(kop_open_data_json_list($row['facilities_mentioned']));
                    yield $row;
                }
            },
        );
        if (kop_open_data_table_exists($pdo, 'news_facility_links')) {
            $sets['news-facilities'] = array(
                'title'       => 'News to facility links',
                'group'       => 'Lawsuits, legislation and news',
                'description' => 'Which facilities each approved article covers. facility_id matches the facilities dataset.',
                'rows'        => static function () use ($pdo) {
                    return $pdo->query("SELECT l.news_id, l.facility_id, l.link_type FROM news_facility_links l
                        JOIN news_submissions n ON n.id = l.news_id WHERE n.status IN ('approved','published')
                        ORDER BY l.news_id, l.facility_id")->fetchAll(PDO::FETCH_ASSOC);
                },
            );
        }
    }

    /* Network map, glossary, reporting directory ------------------------ */

    $graph_file = get_stylesheet_directory() . '/js/data/network/graph.json';
    if (is_readable($graph_file)) {
        $graph = static function () use ($graph_file) {
            static $g = null;
            if ($g === null) $g = json_decode((string) file_get_contents($graph_file), true) ?: array();
            return $g;
        };
        $sets['network-nodes'] = array(
            'title'       => 'Network map: people and organizations',
            'group'       => 'Network map',
            'description' => 'Every person, facility, parent company and organization on the network map, with chain, status and NATSAP membership.',
            'rows'        => static function () use ($graph) {
                foreach ((array) ($graph()['nodes'] ?? array()) as $n) {
                    yield array(
                        'id'          => (string) ($n['id'] ?? ''),
                        'name'        => (string) ($n['name'] ?? ''),
                        'aliases'     => (array) ($n['aliases'] ?? array()),
                        'kind'        => (string) ($n['kind'] ?? ''),
                        'status'      => (string) ($n['status'] ?? ''),
                        'chain'       => (string) ($n['chain'] ?? ''),
                        'regions'     => (array) ($n['regions'] ?? array()),
                        'natsap'      => !empty($n['natsap']),
                        'dates'       => (string) ($n['dates'] ?? ''),
                        'facility_id' => $n['facilityId'] ?? '',
                        'connections' => (int) ($n['degree'] ?? 0),
                    );
                }
            },
        );
        $sets['network-edges'] = array(
            'title'       => 'Network map: connections',
            'group'       => 'Network map',
            'description' => 'Every connection on the network map: who worked where, in what role, which company owns what, rebrands and staff moves. source and target match the node ids.',
            'rows'        => static function () use ($graph) {
                foreach ((array) ($graph()['edges'] ?? array()) as $e) {
                    yield array(
                        'id'        => (string) ($e['id'] ?? ''),
                        'source'    => (string) ($e['source'] ?? ''),
                        'target'    => (string) ($e['target'] ?? ''),
                        'category'  => (string) ($e['category'] ?? ''),
                        'roles'     => (array) ($e['roles'] ?? array()),
                        'direction' => (string) ($e['direction'] ?? ''),
                    );
                }
            },
        );
    }

    $glossary_file = get_stylesheet_directory() . '/js/data/glossary/glossary.json';
    if (is_readable($glossary_file)) {
        $sets['glossary'] = array(
            'title'       => 'TTI glossary',
            'group'       => 'Reference',
            'description' => 'The language of the Troubled Teen Industry: terms, what they mean, and which programs used them.',
            'copy'        => $glossary_file,
        );
    }

    $directory_file = get_stylesheet_directory() . '/js/data/reporting/directory.json';
    if (is_readable($directory_file)) {
        $sets['reporting-directory'] = array(
            'title'       => 'Where to report abuse, by state',
            'group'       => 'Reference',
            'description' => 'The licensing boards, child-protection agencies and other channels for reporting an abusive program or therapist in each state, with sources and check dates.',
            'copy'        => $directory_file,
        );
    }

    return $sets;
}

/**
 * The short fields of a report's categories_json (report type, dates,
 * citation numbers, risk level). The narratives and the copy of the report
 * text some states keep there go in the full-text download instead; with
 * them the index would be over 100 MB.
 */
function kop_open_data_short_fields($categories_json) {
    $short = array();
    foreach (kop_open_data_json_list($categories_json) as $k => $v) {
        if (is_scalar($v) && strlen((string) $v) <= 300) $short[$k] = $v;
    }
    return $short;
}

function kop_open_data_strip_keys($value, array $keys) {
    if (!is_array($value)) return $value;
    foreach ($value as $k => $v) {
        if (is_string($k) && in_array($k, $keys, true)) {
            unset($value[$k]);
            continue;
        }
        $value[$k] = kop_open_data_strip_keys($v, $keys);
    }
    return $value;
}

/* ---- Writing ------------------------------------------------------------ */

/** A value as one CSV cell: lists of plain values as "a; b", anything else nested as JSON. */
function kop_open_data_csv_cell($value) {
    if (is_bool($value)) return $value ? 'yes' : 'no';
    if (is_array($value)) {
        $flat = true;
        foreach ($value as $k => $v) {
            if (!is_int($k) || is_array($v)) { $flat = false; break; }
        }
        return $flat ? implode('; ', array_map('strval', $value)) : wp_json_encode($value, KOP_OPEN_DATA_JSON_FLAGS);
    }
    return (string) $value;
}

/** Write a file through a temp name so a download never sees half of it. */
function kop_open_data_finish($tmp, $final) {
    if (file_exists($final)) unlink($final);
    return rename($tmp, $final);
}

function kop_open_data_file_entry($dir, $name, $format) {
    $file = $dir . '/' . $name;
    return array(
        'name'   => $name,
        'format' => $format,
        'bytes'  => (int) filesize($file),
        'sha256' => hash_file('sha256', $file),
    );
}

/**
 * Stream one dataset into <key>.csv and <key>.json. Columns come from the
 * first row; json_only columns stay out of the CSV.
 */
function kop_open_data_write_dataset($dir, $key, array $spec) {
    if (isset($spec['copy'])) {
        $name = $key . '.json';
        copy($spec['copy'], "$dir/$name.tmp");
        kop_open_data_finish("$dir/$name.tmp", "$dir/$name");
        $decoded = json_decode((string) file_get_contents("$dir/$name"), true);
        $count = 0;
        if (is_array($decoded) && isset($decoded['count']) && is_int($decoded['count'])) {
            $count = $decoded['count'];                     // glossary.json: number of entries
        } elseif (is_array($decoded)) {
            foreach (array('entries', 'terms', 'states', 'items') as $list_key) {
                if (isset($decoded[$list_key]) && is_array($decoded[$list_key])) { $count = count($decoded[$list_key]); break; }
            }
        }
        return array('rows' => $count, 'files' => array(kop_open_data_file_entry($dir, $name, 'json')));
    }

    $json_only = array_flip($spec['json_only'] ?? array());
    $csv = fopen("$dir/$key.csv.tmp", 'wb');
    $json = fopen("$dir/$key.json.tmp", 'wb');
    fwrite($json, "[\n");
    $columns = null;
    $count = 0;
    foreach (call_user_func($spec['rows']) as $row) {
        if ($columns === null) {
            $columns = array_values(array_filter(array_keys($row), static function ($c) use ($json_only) { return !isset($json_only[$c]); }));
            fputcsv($csv, $columns, ',', '"', '');
        }
        $line = array();
        foreach ($columns as $c) $line[] = kop_open_data_csv_cell($row[$c] ?? '');
        fputcsv($csv, $line, ',', '"', '');
        fwrite($json, ($count ? ",\n" : '') . wp_json_encode($row, KOP_OPEN_DATA_JSON_FLAGS));
        $count++;
    }
    fwrite($json, "\n]\n");
    fclose($csv);
    fclose($json);
    kop_open_data_finish("$dir/$key.csv.tmp", "$dir/$key.csv");
    kop_open_data_finish("$dir/$key.json.tmp", "$dir/$key.json");

    return array('rows' => $count, 'files' => array(
        kop_open_data_file_entry($dir, "$key.csv", 'csv'),
        kop_open_data_file_entry($dir, "$key.json", 'json'),
    ));
}

/** The README that goes inside the ZIP. */
function kop_open_data_readme(array $manifest) {
    $lines = array(
        'Kids Over Profits open data',
        '===========================',
        '',
        'Built ' . $manifest['generated_at'] . ' from ' . home_url('/'),
        '',
        'License: ' . KOP_OPEN_DATA_LICENSE . ' (' . KOP_OPEN_DATA_LICENSE_URL . ')',
        'Credit "Kids Over Profits (https://kidsoverprofits.org)", say what you changed,',
        'and share what you build from it under the same license. News articles, court',
        'filings and state records linked from these files belong to their authors.',
        '',
        'Every dataset is here as CSV (opens in Excel or Google Sheets) and JSON.',
        'In the CSV files a list is written as "a; b; c"; nested records are JSON text.',
        'The JSON files of facilities, operators and referrers also carry the full record.',
        '',
        'Datasets',
        '--------',
    );
    foreach ($manifest['datasets'] as $key => $d) {
        $lines[] = sprintf('%s (%d rows): %s', $key, $d['rows'], $d['description']);
    }
    $lines[] = '';
    $lines[] = 'The full text of every inspection report is a separate download on ' . home_url('/' . KOP_OPEN_DATA_SLUG . '/');
    $lines[] = 'Source code: ' . KOP_OPEN_DATA_SOURCE_URL;
    return implode("\n", $lines) . "\n";
}

/**
 * Build every dataset, the ZIP and the manifest. Returns the manifest.
 * The inspection full text is built separately (kop_open_data_build_fulltext).
 */
function kop_open_data_build() {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $dir = kop_open_data_dir()['path'];
    if (!wp_mkdir_p($dir)) {
        return new WP_Error('kop_open_data_dir', 'Could not create ' . $dir);
    }
    if (!file_exists("$dir/index.html")) @file_put_contents("$dir/index.html", '');

    $previous = kop_open_data_manifest();
    $manifest = array(
        'generated_at' => gmdate('c'),
        'license'      => KOP_OPEN_DATA_LICENSE,
        'license_url'  => KOP_OPEN_DATA_LICENSE_URL,
        'source'       => home_url('/'),
        'source_code'  => KOP_OPEN_DATA_SOURCE_URL,
        'datasets'     => array(),
        'errors'       => array(),
        'zip'          => null,
        'full_text'    => $previous['full_text'] ?? null,
    );

    foreach (kop_open_data_datasets() as $key => $spec) {
        try {
            $written = kop_open_data_write_dataset($dir, $key, $spec);
            $manifest['datasets'][$key] = array(
                'title'       => $spec['title'],
                'group'       => $spec['group'],
                'description' => $spec['description'],
                'rows'        => $written['rows'],
                'files'       => $written['files'],
            );
        } catch (Throwable $e) {
            // One broken source must not take the rest down. The old files, if
            // any, stay published; the error is logged, not shown on the page.
            $manifest['errors'][$key] = $e->getMessage();
            if (isset($previous['datasets'][$key])) $manifest['datasets'][$key] = $previous['datasets'][$key];
            error_log('kop open data: ' . $key . ': ' . $e->getMessage());
        }
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open("$dir/" . KOP_OPEN_DATA_ZIP . '.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('README.txt', kop_open_data_readme($manifest));
            foreach ($manifest['datasets'] as $d) {
                foreach ($d['files'] as $f) $zip->addFile("$dir/{$f['name']}", $f['name']);
            }
            $zip->close();
            kop_open_data_finish("$dir/" . KOP_OPEN_DATA_ZIP . '.tmp', "$dir/" . KOP_OPEN_DATA_ZIP);
            $manifest['zip'] = kop_open_data_file_entry($dir, KOP_OPEN_DATA_ZIP, 'zip');
        }
    }

    kop_open_data_save_manifest($manifest);
    kop_open_data_save_edition($manifest);
    return $manifest;
}

function kop_open_data_save_manifest(array $manifest) {
    $dir = kop_open_data_dir()['path'];
    file_put_contents("$dir/manifest.json.tmp", wp_json_encode($manifest, JSON_PRETTY_PRINT | KOP_OPEN_DATA_JSON_FLAGS));
    kop_open_data_finish("$dir/manifest.json.tmp", "$dir/manifest.json");
}

/**
 * Append the next slice of inspection report text to the full-text download,
 * for up to $seconds (0 = until done). One JSON object per line, gzipped.
 * Each slice is its own gzip member in the same file, which gzip, zcat and
 * every gzip library read as one stream. Returns true once the file is done.
 */
function kop_open_data_build_fulltext($seconds = 20) {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo || !kop_open_data_table_exists($pdo, 'inspection_reports') || !function_exists('gzopen')) {
        return true;
    }
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $dir = kop_open_data_dir()['path'];
    if (!wp_mkdir_p($dir)) return true;
    $tmp = "$dir/" . KOP_OPEN_DATA_FULLTEXT . '.tmp';

    // Where the last slice stopped sits in a file beside the partial download,
    // so the two can never disagree; a missing either one starts over.
    $state_file = "$tmp.state.json";
    $state = is_readable($state_file) ? json_decode((string) file_get_contents($state_file), true) : null;
    if (!is_array($state) || !isset($state['after'], $state['rows']) || !file_exists($tmp)) {
        if (file_exists($tmp)) unlink($tmp);
        $state = array('after' => 0, 'rows' => 0, 'started' => time());
    }

    // Each slice goes to its own part file and is appended only once it is
    // closed, so a request killed mid-slice leaves no broken gzip member.
    $part = "$tmp.part";
    $stop = $seconds > 0 ? microtime(true) + $seconds : INF;
    $gz = gzopen($part, 'wb6');
    $after = $state['after'];
    $rows_in_slice = 0;
    $stmt = $pdo->prepare(
        'SELECT r.id, r.facility_id, f.state, f.facility_name, r.report_id, r.report_date, r.report_url, r.summary, r.categories_json, r.raw_content
         FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id
         WHERE r.id > ? ORDER BY r.id LIMIT 200');
    $done = false;
    while (microtime(true) < $stop) {
        $stmt->execute(array($after));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $done = true; break; }
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['facility_id'] = (int) $row['facility_id'];
            $row['fields'] = kop_open_data_json_list($row['categories_json']);
            unset($row['categories_json']);
            gzwrite($gz, wp_json_encode($row, KOP_OPEN_DATA_JSON_FLAGS) . "\n");
            $after = $row['id'];
            $rows_in_slice++;
        }
        if (count($rows) < 200) { $done = true; break; }
    }
    gzclose($gz);

    if ($rows_in_slice > 0) {
        $in = fopen($part, 'rb');
        $out = fopen($tmp, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }
    if (file_exists($part)) unlink($part);
    $state['after'] = $after;
    $state['rows'] += $rows_in_slice;

    if (!$done) {
        file_put_contents($state_file, wp_json_encode($state));
        return false;
    }

    kop_open_data_finish($tmp, "$dir/" . KOP_OPEN_DATA_FULLTEXT);
    if (file_exists($state_file)) unlink($state_file);
    $manifest = kop_open_data_manifest() ?: array('datasets' => array());
    $manifest['full_text'] = kop_open_data_file_entry($dir, KOP_OPEN_DATA_FULLTEXT, 'jsonl.gz') + array(
        'rows'         => $state['rows'],
        'generated_at' => gmdate('c'),
    );
    kop_open_data_save_manifest($manifest);
    kop_open_data_save_edition_fulltext($manifest);
    return true;
}

/* ---- Editions ------------------------------------------------------------ */

/*
 * Frozen monthly copies, so a finding can be cited against the exact files it
 * came from. The first build of each month copies the ZIP into
 * editions/<YYYY-MM>/ with an edition.json (build time, rows per dataset,
 * checksum); the first full-text build of each quarter adds that file to the
 * month it lands in. An edition is never written over once it exists.
 */

function kop_open_data_editions_dir() {
    return kop_open_data_dir()['path'] . '/editions';
}

function kop_open_data_edition_url($edition, $name) {
    return kop_open_data_dir()['url'] . '/editions/' . rawurlencode($edition) . '/' . rawurlencode($name);
}

/** Every edition, newest first: array of edition.json contents. */
function kop_open_data_editions() {
    $editions = array();
    foreach (glob(kop_open_data_editions_dir() . '/*/edition.json') ?: array() as $file) {
        $edition = json_decode((string) file_get_contents($file), true);
        if (is_array($edition) && !empty($edition['edition'])) $editions[] = $edition;
    }
    usort($editions, static function ($a, $b) { return strcmp($b['edition'], $a['edition']); });
    return $editions;
}

function kop_open_data_write_edition_json($dir, array $edition) {
    file_put_contents("$dir/edition.json.tmp", wp_json_encode($edition, JSON_PRETTY_PRINT | KOP_OPEN_DATA_JSON_FLAGS));
    kop_open_data_finish("$dir/edition.json.tmp", "$dir/edition.json");
}

/** The month's edition from this build's ZIP, unless the month has one already. */
function kop_open_data_save_edition(array $manifest) {
    if (empty($manifest['zip'])) return;
    $month = gmdate('Y-m', strtotime($manifest['generated_at']));
    $dir = kop_open_data_editions_dir() . '/' . $month;
    if (file_exists("$dir/edition.json") || !wp_mkdir_p($dir)) return;

    $src = kop_open_data_dir()['path'];
    copy("$src/" . KOP_OPEN_DATA_ZIP, "$dir/" . KOP_OPEN_DATA_ZIP . '.tmp');
    kop_open_data_finish("$dir/" . KOP_OPEN_DATA_ZIP . '.tmp', "$dir/" . KOP_OPEN_DATA_ZIP);
    copy("$src/manifest.json", "$dir/manifest.json");

    $rows = array();
    foreach ($manifest['datasets'] as $key => $d) $rows[$key] = (int) $d['rows'];
    kop_open_data_write_edition_json($dir, array(
        'edition'      => $month,
        'generated_at' => $manifest['generated_at'],
        'license'      => KOP_OPEN_DATA_LICENSE,
        'rows'         => $rows,
        'zip'          => kop_open_data_file_entry($dir, KOP_OPEN_DATA_ZIP, 'zip'),
        'full_text'    => null,
    ));
}

/**
 * Add the inspection full text to the current month's edition when no
 * edition from this quarter has it yet: at about 70 MB a copy, a monthly
 * copy would add most of a gigabyte a year.
 */
function kop_open_data_save_edition_fulltext(array $manifest) {
    if (empty($manifest['full_text'])) return;
    $now = strtotime($manifest['full_text']['generated_at']);
    $quarter = static function ($ts) {
        return gmdate('Y', $ts) . '-Q' . (int) ceil(gmdate('n', $ts) / 3);
    };
    foreach (kop_open_data_editions() as $edition) {
        if (!empty($edition['full_text']) && $quarter(strtotime($edition['edition'] . '-01')) === $quarter($now)) return;
    }
    $dir = kop_open_data_editions_dir() . '/' . gmdate('Y-m', $now);
    $edition = is_readable("$dir/edition.json") ? json_decode((string) file_get_contents("$dir/edition.json"), true) : null;
    if (!is_array($edition)) return;   // the month's ZIP edition comes first; the next full-text build retries

    copy(kop_open_data_dir()['path'] . '/' . KOP_OPEN_DATA_FULLTEXT, "$dir/" . KOP_OPEN_DATA_FULLTEXT . '.tmp');
    kop_open_data_finish("$dir/" . KOP_OPEN_DATA_FULLTEXT . '.tmp', "$dir/" . KOP_OPEN_DATA_FULLTEXT);
    $edition['full_text'] = kop_open_data_file_entry($dir, KOP_OPEN_DATA_FULLTEXT, 'jsonl.gz') + array(
        'rows'         => (int) $manifest['full_text']['rows'],
        'generated_at' => $manifest['full_text']['generated_at'],
    );
    kop_open_data_write_edition_json($dir, $edition);
}

/* ---- Schedule ----------------------------------------------------------- */

/**
 * Daily through WP-Cron: the datasets and ZIP every day, the inspection full
 * text once a week. The full text runs a slice per request and books the next
 * slice a minute later until it is done, so no single request runs long.
 */
function kop_open_data_cron() {
    if (get_transient('kop_open_data_lock')) return;
    set_transient('kop_open_data_lock', 1, 30 * MINUTE_IN_SECONDS);
    try {
        $manifest = kop_open_data_manifest();
        $age = $manifest ? time() - strtotime($manifest['generated_at']) : PHP_INT_MAX;
        if ($age > 20 * HOUR_IN_SECONDS) {
            kop_open_data_build();
        }
        $manifest = kop_open_data_manifest();
        $built = !empty($manifest['full_text']['generated_at']) ? strtotime($manifest['full_text']['generated_at']) : 0;
        $midway = file_exists(kop_open_data_dir()['path'] . '/' . KOP_OPEN_DATA_FULLTEXT . '.tmp.state.json');
        if ($midway || time() - $built > WEEK_IN_SECONDS) {
            if (!kop_open_data_build_fulltext(20)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'kop_open_data_cron');
            }
        }
    } finally {
        delete_transient('kop_open_data_lock');
    }
}
add_action('kop_open_data_cron', 'kop_open_data_cron');

function kop_open_data_schedule() {
    if (!wp_next_scheduled('kop_open_data_daily')) {
        // The first run is right away, so the page has files soon after deploy.
        wp_schedule_event(time(), 'daily', 'kop_open_data_daily');
    }
}
add_action('init', 'kop_open_data_schedule', 30);
add_action('kop_open_data_daily', 'kop_open_data_cron');

/* ---- Footer -------------------------------------------------------------- */

/**
 * "Open source. Open data." under the copyright line on every page. Kadence
 * prints the customizer's footer HTML at the default priority; this follows it.
 */
function kop_open_data_footer_line() {
    $page = get_page_by_path(KOP_OPEN_DATA_SLUG);
    $data = $page && $page->post_status === 'publish'
        ? '<a href="' . esc_url(get_permalink($page)) . '">Download our data</a>'
        : 'Our data';
    printf(
        '<p class="kop-footer-open">Kids Over Profits is <a href="%s">open source</a>. %s, free to reuse under <a href="%s" rel="license">CC BY-SA 4.0</a>.</p>',
        esc_url(KOP_OPEN_DATA_SOURCE_URL),
        $data,
        esc_url(KOP_OPEN_DATA_LICENSE_URL)
    );
}
add_action('kadence_footer_html', 'kop_open_data_footer_line', 20);

/* ---- Page helpers ------------------------------------------------------- */

function kop_open_data_size($bytes) {
    $bytes = (int) $bytes;
    if ($bytes >= 1048576) return number_format_i18n($bytes / 1048576, $bytes >= 10485760 ? 0 : 1) . ' MB';
    if ($bytes >= 1024) return number_format_i18n($bytes / 1024) . ' KB';
    return number_format_i18n($bytes) . ' bytes';
}

function kop_open_data_file_url($name) {
    return kop_open_data_dir()['url'] . '/' . rawurlencode($name);
}
