<?php
/**
 * Trade organizations on the site: /trade-organizations/ (templates/page-tradeorg-index.php) lists every
 * record in tradeorgs_master (api/lib-tradeorgs.php), entered on the data forms' Trade Organizations tab.
 * Each also lists the programs on file whose records name it under memberships or accreditations
 * (kop_tradeorgs_members(): current and former, read from facilities_v2 at render time, so a record
 * edit shows at once). Websites link to the archived copy, the live site only through /go/.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/lib-tradeorgs.php';

if (!function_exists('kop_tradeorgs_all')) {
    /** Every trade organization, A to Z: [{id, unique_name, org}]. Empty until the table exists. */
    function kop_tradeorgs_all($pdo = null) {
        $pdo = $pdo ?: (function_exists('kop_seed_pdo') ? kop_seed_pdo() : null);
        if (!$pdo) {
            return array();
        }
        try {
            $rows = $pdo->query('SELECT id, unique_name, json_data FROM tradeorgs_master')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $org = kop_tradeorg_from_json($row['json_data']);
            if ($org['name'] === '') {
                $org['name'] = (string) $row['unique_name'];
            }
            $out[] = array('id' => (int) $row['id'], 'unique_name' => (string) $row['unique_name'], 'org' => $org);
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['org']['name'], $b['org']['name']);
        });
        return $out;
    }
}

if (!function_exists('kop_tradeorgs_members')) {
    /**
     * Programs whose facilities_v2 record names $org under memberships or accreditations:
     * ['current' => [{id, name, state, url, entry}], 'former' => [...]], A to Z.
     */
    function kop_tradeorgs_members(array $org) {
        global $wpdb;
        $out = array('current' => array(), 'former' => array());
        $names = kop_tradeorg_names($org);
        if (!$names || !isset($wpdb)) {
            return $out;
        }
        $likes = array();
        $args = array();
        foreach ($names as $n) {
            $likes[] = 'json_data LIKE %s';
            $args[] = '%' . $wpdb->esc_like($n) . '%';
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, name, state, json_data FROM facilities_v2 WHERE ' . implode(' OR ', $likes), $args), ARRAY_A);
        foreach ((array) $rows as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            if (!is_array($doc)) {
                continue;
            }
            $entries = array();
            foreach ((array) ($doc['memberships'] ?? array()) as $e) {
                $entries[] = $e;
            }
            $acc = $doc['accreditations'] ?? array();
            if (is_array($acc)) {
                foreach (array('current', 'past') as $k) {
                    foreach ((array) ($acc[$k] ?? array()) as $e) {
                        $entries[] = $k === 'past' && is_string($e) ? 'former: ' . $e : $e;
                    }
                }
            }
            $best = '';
            $text = '';
            foreach ($entries as $e) {
                $e = is_array($e) ? (string) ($e['name'] ?? '') : (string) $e;
                $hit = kop_tradeorg_membership_match($e, $org);
                if ($hit === 'current' || ($hit === 'former' && $best === '')) {
                    $best = $hit;
                    $text = $e;
                }
            }
            if ($best === '') {
                continue;
            }
            $id = (int) $row['id'];
            $out[$best][] = array(
                'id'    => $id,
                'name'  => trim((string) $row['name']),
                'state' => trim((string) $row['state']),
                'url'   => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($id) : '',
                'entry' => $text,
            );
        }
        foreach ($out as &$list) {
            usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        unset($list);
        return $out;
    }
}

if (!function_exists('kop_tradeorgs_transport_members')) {
    /** Transport companies whose records name $org among their affiliations: [{name, entry}], A to Z. */
    function kop_tradeorgs_transport_members(array $org) {
        global $wpdb;
        $table = function_exists('kop_transporters_table') ? kop_transporters_table() : 'transporters_master';
        $names = kop_tradeorg_names($org);
        if (!$names || !isset($wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results("SELECT unique_name, json_data FROM `{$table}`", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $p = json_decode((string) $row['json_data'], true);
            $d = is_array($p) && isset($p['data']) && is_array($p['data']) ? $p['data'] : (is_array($p) ? $p : array());
            $co = is_array($d['transporterCompany'] ?? null) ? $d['transporterCompany'] : array();
            foreach ((array) ($co['affiliations'] ?? array()) as $a) {
                $a = is_array($a) ? (string) ($a['name'] ?? '') : (string) $a;
                if (kop_tradeorg_membership_match($a, $org) !== '') {
                    $name = trim((string) ($co['name'] ?? '')) ?: (string) $row['unique_name'];
                    $out[$name] = array('name' => $name, 'entry' => $a);
                    break;
                }
            }
        }
        ksort($out, SORT_FLAG_CASE | SORT_STRING);
        return array_values($out);
    }
}

if (!function_exists('kop_tradeorgs_render_public')) {
    function kop_tradeorgs_render_public() {
        $orgs = kop_tradeorgs_all();
        if (!$orgs) {
            echo '<p class="kop-tro-empty">No trade organizations are listed yet.</p>';
            return;
        }
        $e = function ($s) { return esc_html((string) $s); };
        $labels = kop_tradeorg_activity_labels();
        $site = function_exists('kop_marketers_site_html') ? 'kop_marketers_site_html' : $e;
        $source = function_exists('kop_marketers_source_html') ? 'kop_marketers_source_html' : $e;

        $program_names = array();
        foreach ($orgs as $o) {
            foreach ($o['org']['memberFacilities'] as $name) {
                $program_names[] = $name;
            }
        }
        $program_links = $program_names && function_exists('kop_facility_link_resolve') ? kop_facility_link_resolve($program_names) : array();
        $program_html = function ($name) use ($program_links, $e) {
            $hit = $program_links[trim($name)] ?? null;
            return $hit && $hit['status'] === 'linked' ? '<a href="' . esc_url($hit['url']) . '">' . $e($name) . '</a>' : $e($name);
        };
        $company_html = function ($name) use ($e) {
            $url = function_exists('kop_operator_page_url_for_name') ? kop_operator_page_url_for_name($name) : '';
            return $url !== '' ? '<a href="' . esc_url($url) . '">' . $e($name) . '</a>' : $e($name);
        };
        $list = function ($title, array $items, $render) {
            if (!$items) {
                return;
            }
            echo '<h3 class="kop-tro-list-title">' . esc_html($title) . '</h3><ul class="kop-tro-list">';
            foreach ($items as $item) {
                echo '<li>' . $render($item) . '</li>';
            }
            echo '</ul>';
        };
        $member_html = function ($m) use ($e) {
            $name = $m['url'] !== '' ? '<a href="' . esc_url($m['url']) . '">' . $e($m['name']) . '</a>' : $e($m['name']);
            return $name . ($m['state'] !== '' ? ' <span class="kop-tro-muted">' . $e($m['state']) . '</span>' : '');
        };

        echo '<p class="kop-tro-count">' . count($orgs) . ' trade organization' . (count($orgs) === 1 ? '' : 's') . '.</p>';
        if (count($orgs) > 6) {
            echo '<nav class="kop-tro-jump" aria-label="Organizations"><ul>';
            foreach ($orgs as $o) {
                echo '<li><a href="#kop-tro-' . (int) $o['id'] . '">' . $e($o['org']['acronym'] !== '' ? $o['org']['acronym'] : $o['org']['name']) . '</a></li>';
            }
            echo '</ul></nav>';
        }

        foreach ($orgs as $o) {
            $c = $o['org'];
            echo '<article class="kop-tro-org" id="kop-tro-' . (int) $o['id'] . '">';
            echo '<h2 class="kop-tro-name">' . $e($c['name']) . ($c['acronym'] !== '' && strcasecmp($c['acronym'], $c['name']) ? ' <span class="kop-tro-acronym">(' . $e($c['acronym']) . ')</span>' : '') . '</h2>';

            $place = implode(', ', array_filter(array($c['city'], $c['state'], $c['country'])));
            $meta = array_filter(array($c['orgType'], $place, $c['founded'] !== '' ? 'founded ' . $c['founded'] : '', $c['status']));
            if ($meta) {
                echo '<p class="kop-tro-meta">' . $e(implode(' · ', $meta)) . '</p>';
            }
            $others = array_values(array_diff($c['otherNames'], array($c['name'], $c['acronym'])));
            if ($others) {
                echo '<p class="kop-tro-fact"><strong>Also known as:</strong> ' . $e(implode('; ', $others)) . '</p>';
            }

            $activities = array();
            foreach ($labels as $key => $label) {
                if (!empty($c['activities'][$key])) {
                    $activities[] = $label;
                }
            }
            $list('What it does', array_merge($activities, $c['otherActivities']), $e);
            $list('Leaders and key people', $c['keyPersonnel'], $e);
            $list('Lobbying and testimony', $c['lobbying'], $e);
            $list('Funding', $c['funding'], $e);
            $list('Publications', $c['publications'], $e);

            // Members: the records that say so, then names typed on this record
            $members = kop_tradeorgs_members($c);
            foreach (array('current' => 'Programs on file that list it as a membership', 'former' => 'Programs on file that list it as a former membership') as $kind => $title) {
                $n = count($members[$kind]);
                if (!$n) {
                    continue;
                }
                echo '<details class="kop-tro-members"' . ($n <= 12 ? ' open' : '') . '><summary>' . esc_html($title) . ' (' . $n . ')</summary><ul class="kop-tro-list kop-tro-cols">';
                foreach ($members[$kind] as $m) {
                    echo '<li>' . $member_html($m) . '</li>';
                }
                echo '</ul></details>';
            }
            // Typed members the records above already show are left out
            $on_file = array();
            foreach (array_merge($members['current'], $members['former']) as $m) {
                $on_file[strtolower($m['name'])] = true;
            }
            $typed = array_values(array_filter($c['memberFacilities'], function ($n) use ($on_file) { return !isset($on_file[strtolower(trim($n))]); }));
            $list($on_file ? 'Other member programs' : 'Member programs', $typed, $program_html);
            // Transport companies whose records list it (inc/utilities.php kop_transporters_table())
            $transport = kop_tradeorgs_transport_members($c);
            if ($transport) {
                $index = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-transporter-index.php') : '';
                $list('Transport companies on file that list it', $transport, function ($t) use ($e, $index) {
                    $label = $e($t['name']) . (preg_match('/\(([^)]+)\)/', $t['entry'], $m) ? ' <span class="kop-tro-muted">(' . $e($m[1]) . ')</span>' : '');
                    return $index !== '' ? '<a href="' . esc_url($index) . '">' . $label . '</a>' : $label;
                });
            }
            $list('Member companies', $c['memberCompanies'], $company_html);
            $list('Other members (consultants, transporters, marketers)', $c['memberOthers'], $e);

            $list('Its websites', $c['websites'], $site);
            $list('Affiliations', $c['affiliations'], $e);
            $list('Lawsuits', $c['lawsuits'], $source);
            if ($c['notes'] !== '') {
                echo '<div class="kop-tro-notes">' . wpautop($e($c['notes'])) . '</div>';
            }
            if ($c['sourceUrls']) {
                echo '<p class="kop-tro-sources"><strong>Sources:</strong> ' . implode('; ', array_map($source, $c['sourceUrls'])) . '</p>';
            }
            echo '</article>';
        }
    }
}

if (!function_exists('kop_tradeorgs_ensure_table')) {
    /** tradeorgs_master, the same columns as the other master tables. */
    function kop_tradeorgs_ensure_table(PDO $pdo) {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->exec($sqlite
            ? 'CREATE TABLE IF NOT EXISTS tradeorgs_master (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_name TEXT NOT NULL UNIQUE, json_data TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)'
            : 'CREATE TABLE IF NOT EXISTS tradeorgs_master (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                unique_name VARCHAR(255) NOT NULL,
                json_data LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_name (unique_name),
                KEY updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

if (!function_exists('kop_tradeorgs_apply_seed')) {
    /** seeds/tradeorgs.json into tradeorgs_master: records not on file are added, one on file is never changed. */
    function kop_tradeorgs_apply_seed(PDO $pdo, $file = null) {
        $file = $file ?: get_stylesheet_directory() . '/seeds/tradeorgs.json';
        $seed = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($seed) || !is_array($seed['records'] ?? null)) {
            return array();
        }
        kop_tradeorgs_ensure_table($pdo);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM tradeorgs_master WHERE unique_name = ?');
        $insert = $pdo->prepare('INSERT INTO tradeorgs_master (unique_name, json_data) VALUES (?, ?)');
        $added = array();
        foreach ($seed['records'] as $record) {
            $org = kop_tradeorg_normalize($record);
            if ($org['name'] === '') {
                continue;
            }
            $exists->execute(array($org['name']));
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            $payload = array('name' => $org['name'], 'category' => 'tradeorgs', 'timestamp' => gmdate('c'), '_source' => 'seed',
                'data' => array('tradeOrg' => $org, 'category' => 'tradeorgs'));
            $insert->execute(array($org['name'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            $added[] = $org['name'];
        }
        return $added;
    }

    /** Once per seed version, on any request. */
    function kop_tradeorgs_maybe_apply_seed() {
        $file = get_stylesheet_directory() . '/seeds/tradeorgs.json';
        $seed = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $version = is_array($seed) ? (string) ($seed['version'] ?? '') : '';
        if ($version === '' || get_option('kop_tradeorgs_seed_version') === $version) {
            return;
        }
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) {
            return;
        }
        try {
            kop_tradeorgs_apply_seed($pdo, $file);
            update_option('kop_tradeorgs_seed_version', $version);
        } catch (Throwable $e) {
            error_log('Trade organizations seed failed: ' . $e->getMessage());
        }
    }
    add_action('init', 'kop_tradeorgs_maybe_apply_seed', 30);
}
