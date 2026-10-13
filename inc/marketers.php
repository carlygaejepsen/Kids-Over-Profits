<?php
/**
 * Marketing companies on the site: /marketing-companies/ (templates/page-marketer-index.php) lists every
 * record in marketers_master (api/lib-marketers.php), entered on the data forms' Marketers tab. Their own
 * websites and the sites they run link to the archived copy, the live site only through /go/
 * (kop_program_go_hosts() reads marketers_master too); programs and companies they market link to their
 * /facility/ and /operator/ pages when the name is exactly one record's.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/lib-marketers.php';

if (!function_exists('kop_marketers_all')) {
    /**
     * Every marketing company, A to Z: [{id, unique_name, company}]. Records DB (kop_seed_pdo()), the
     * same one api/save-master.php writes; empty when the table does not exist yet (made on first save).
     */
    function kop_marketers_all($pdo = null) {
        $pdo = $pdo ?: (function_exists('kop_seed_pdo') ? kop_seed_pdo() : null);
        if (!$pdo) {
            return array();
        }
        try {
            $rows = $pdo->query('SELECT id, unique_name, json_data FROM marketers_master')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $company = kop_marketer_company_from_json($row['json_data']);
            if ($company['name'] === '') {
                $company['name'] = (string) $row['unique_name'];
            }
            $out[] = array('id' => (int) $row['id'], 'unique_name' => (string) $row['unique_name'], 'company' => $company);
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['company']['name'], $b['company']['name']);
        });
        return $out;
    }
}

if (!function_exists('kop_marketers_url')) {
    /** A web address as typed ("BestSchools.com") made absolute, or '' when it is not one. */
    function kop_marketers_url($text) {
        $text = trim((string) $text);
        if (preg_match('#^https?://\S+$#i', $text)) {
            return $text;
        }
        if (preg_match('#^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$#i', $text)) {
            return 'https://' . $text;
        }
        return '';
    }

    /** One industry website: archived copy first, "live site" through /go/. Plain text when it is not an address. */
    function kop_marketers_site_html($text) {
        $e = function ($s) { return esc_html((string) $s); };
        $url = kop_marketers_url($text);
        if ($url === '') {
            return $e($text);
        }
        $label = preg_match('#^https?://#i', trim($text)) && function_exists('kop_url_label') ? kop_url_label($url) : trim($text);
        if (function_exists('kop_facility_pages_archive_link')) {
            $link = kop_facility_pages_archive_link($url, $label);
            if ($link['go_url'] !== '') {
                return '<a href="' . esc_url($link['url']) . '" rel="nofollow noopener" data-kop-keep-url>' . $e($label) . '</a>'
                    . ' <span class="kop-mkt-muted">(archived copy;'
                    . ' <a href="' . esc_url($link['go_url']) . '" rel="nofollow noreferrer noopener">live site</a>)</span>';
            }
        }
        return '<a href="' . esc_url($url) . '" rel="nofollow noreferrer noopener">' . $e($label) . '</a>';
    }

    /** A source link, worded (kop_url_label()), or the text as typed. */
    function kop_marketers_source_html($text) {
        $url = kop_marketers_url($text);
        if ($url === '') {
            return esc_html($text);
        }
        $label = function_exists('kop_url_label') ? kop_url_label($url) : $text;
        return '<a href="' . esc_url($url) . '" rel="noopener">' . esc_html($label) . '</a>';
    }
}

if (!function_exists('kop_marketers_render_public')) {
    function kop_marketers_render_public() {
        $marketers = kop_marketers_all();
        if (!$marketers) {
            echo '<p class="kop-mkt-empty">No marketing companies are listed yet.</p>';
            return;
        }
        $e = function ($s) { return esc_html((string) $s); };
        $labels = kop_marketer_service_labels();

        // Programs they market: one batch, linked only when the name is exactly one record's
        $program_names = array();
        foreach ($marketers as $m) {
            foreach ($m['company']['clientFacilities'] as $name) {
                $program_names[] = $name;
            }
        }
        $program_links = $program_names && function_exists('kop_facility_link_resolve') ? kop_facility_link_resolve($program_names) : array();
        $program_html = function ($name) use ($program_links, $e) {
            $hit = $program_links[trim($name)] ?? null;
            return $hit && $hit['status'] === 'linked'
                ? '<a href="' . esc_url($hit['url']) . '">' . $e($name) . '</a>'
                : $e($name);
        };
        $company_html = function ($name) use ($e) {
            $url = function_exists('kop_operator_page_url_for_name') ? kop_operator_page_url_for_name($name) : '';
            return $url !== '' ? '<a href="' . esc_url($url) . '">' . $e($name) . '</a>' : $e($name);
        };
        $list = function ($title, array $items, $render) {
            if (!$items) {
                return;
            }
            echo '<h3 class="kop-mkt-list-title">' . esc_html($title) . '</h3><ul class="kop-mkt-list">';
            foreach ($items as $item) {
                echo '<li>' . $render($item) . '</li>';
            }
            echo '</ul>';
        };

        echo '<p class="kop-mkt-count">' . count($marketers) . ' marketing compan' . (count($marketers) === 1 ? 'y' : 'ies') . '.</p>';
        if (count($marketers) > 6) {
            echo '<nav class="kop-mkt-jump" aria-label="Companies"><ul>';
            foreach ($marketers as $m) {
                echo '<li><a href="#kop-mkt-' . (int) $m['id'] . '">' . $e($m['company']['name']) . '</a></li>';
            }
            echo '</ul></nav>';
        }

        foreach ($marketers as $m) {
            $c = $m['company'];
            echo '<article class="kop-mkt-company" id="kop-mkt-' . (int) $m['id'] . '">';
            echo '<h2 class="kop-mkt-name">' . $e($c['name']) . '</h2>';

            $place = implode(', ', array_filter(array($c['city'], $c['state'], $c['country'])));
            $meta = array_filter(array(
                $place,
                $c['founded'] !== '' ? 'founded ' . $c['founded'] : '',
                $c['status'],
            ));
            if ($meta) {
                echo '<p class="kop-mkt-meta">' . $e(implode(' · ', $meta)) . '</p>';
            }
            $others = array_values(array_diff($c['otherNames'], array($c['name'])));
            if ($others) {
                echo '<p class="kop-mkt-fact"><strong>Also known as:</strong> ' . $e(implode('; ', $others)) . '</p>';
            }
            if ($c['parentCompanies']) {
                echo '<p class="kop-mkt-fact"><strong>Part of:</strong> ' . $e(implode('; ', $c['parentCompanies'])) . '</p>';
            }

            $services = array();
            foreach ($labels as $key => $label) {
                if (!empty($c['services'][$key])) {
                    $services[] = $label;
                }
            }
            $list('What they do for programs', array_merge($services, $c['otherServices']), $e);
            $list('Directories, help lines and review sites they run', $c['sitesRun'], 'kop_marketers_site_html');
            $list('Programs they market', $c['clientFacilities'], $program_html);
            $list('Companies they market', $c['clientCompanies'], $company_html);
            $list('Owners and key people', $c['keyPersonnel'], $e);
            $list('Their websites', $c['websites'], 'kop_marketers_site_html');
            $list('Industry affiliations', $c['affiliations'], $e);
            $list('Lawsuits', $c['lawsuits'], 'kop_marketers_source_html');

            if ($c['notes'] !== '') {
                echo '<div class="kop-mkt-notes">' . wpautop($e($c['notes'])) . '</div>';
            }
            if ($c['sourceUrls']) {
                echo '<p class="kop-mkt-sources"><strong>Sources:</strong> '
                    . implode('; ', array_map('kop_marketers_source_html', $c['sourceUrls'])) . '</p>';
            }
            echo '</article>';
        }
    }
}

if (!function_exists('kop_marketers_ensure_table')) {
    /** marketers_master, the same columns as the other master tables (api/save-master.php kop_ensure_master_table()). */
    function kop_marketers_ensure_table(PDO $pdo) {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->exec($sqlite
            ? 'CREATE TABLE IF NOT EXISTS marketers_master (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_name TEXT NOT NULL UNIQUE, json_data TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)'
            : 'CREATE TABLE IF NOT EXISTS marketers_master (
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

if (!function_exists('kop_marketers_apply_seed')) {
    /**
     * seeds/marketers.json into marketers_master: each record not on file (by name) is added; a record
     * already there is never changed, so edits made in the data form stay. Returns the names added.
     */
    function kop_marketers_apply_seed(PDO $pdo, $file = null) {
        $file = $file ?: get_stylesheet_directory() . '/seeds/marketers.json';
        $seed = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($seed) || !is_array($seed['records'] ?? null)) {
            return array();
        }
        kop_marketers_ensure_table($pdo);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM marketers_master WHERE unique_name = ?');
        $insert = $pdo->prepare('INSERT INTO marketers_master (unique_name, json_data) VALUES (?, ?)');
        $added = array();
        foreach ($seed['records'] as $record) {
            $company = kop_marketer_normalize_company($record);
            $name = $company['name'];
            if ($name === '') {
                continue;
            }
            $exists->execute(array($name));
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            $payload = array(
                'name'     => $name,
                'category' => 'marketers',
                'data'     => array('marketerCompany' => $company, 'category' => 'marketers'),
                'timestamp' => gmdate('c'),
                '_source'  => 'seed',
            );
            $insert->execute(array($name, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            $added[] = $name;
        }
        return $added;
    }

    /** Once per seed version, on any request, so a deploy fills the page without anyone opening wp-admin. */
    function kop_marketers_maybe_apply_seed() {
        $file = get_stylesheet_directory() . '/seeds/marketers.json';
        $seed = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $version = is_array($seed) ? (string) ($seed['version'] ?? '') : '';
        if ($version === '' || get_option('kop_marketers_seed_version') === $version) {
            return;
        }
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) {
            return;
        }
        try {
            kop_marketers_apply_seed($pdo, $file);
            update_option('kop_marketers_seed_version', $version);
        } catch (Throwable $e) {
            error_log('Marketers seed failed: ' . $e->getMessage());
        }
    }
    add_action('init', 'kop_marketers_maybe_apply_seed', 30);
}
