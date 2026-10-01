<?php
/**
 * Documents on survivor-run websites that KOP has no copy of.
 *
 * Surviving Straight Inc. (survivingstraightinc.com), WWASP Survivors
 * (wwaspsurvivors.com), thestraights.net and the New Horizons Alumni
 * Association (nhym-alumni.org) host their own document collections. Like the
 * Unsilenced archive (inc/unsilenced-archive.php), scripts/survivor-archives.py
 * hashes every document their pages link, drops what KOP's media library
 * already holds, and writes per facility and operator what is left:
 *
 *   js/data/survivor-archives/<site>/index.json   {built, label, url, facilities: {id: n}, operators: {id: n}}
 *   js/data/survivor-archives/<site>/f/<id>.json  {files: [{url, name, group}]}
 *   js/data/survivor-archives/<site>/o/<id>.json  the same for an operator
 *
 * The facility and operator pages list them in their Documents section,
 * linked to the site. SCIAD NET (the WWASP Survivor Truth archive) keeps its
 * collection on Google Drive: its documents link to Drive, and its block credits
 * the archive's page. Nothing is copied to this site.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_survivor_archives_sites')) {
    /** Site key => the host its documents must be on. */
    function kop_survivor_archives_sites() {
        return array(
            'ssi'   => 'survivingstraightinc.com',
            'wwasp' => 'wwaspsurvivors.com',
            'straights' => 'thestraights.net',
            'nhym'  => 'nhym-alumni.org',
            'sciad' => 'drive.google.com',
        );
    }
}

if (!function_exists('kop_survivor_archives_hosts')) {
    /**
     * The hosts a site's documents may link to: its own, and for SCIAD NET alone
     * Google Drive and Google Docs, where its collection lives.
     */
    function kop_survivor_archives_hosts($site) {
        if ($site === 'sciad') return array('drive.google.com', 'docs.google.com');
        $sites = kop_survivor_archives_sites();
        return isset($sites[$site]) ? array($sites[$site]) : array();
    }
}

if (!function_exists('kop_survivor_archives_home')) {
    /** The page a site's block links: its home page; for SCIAD NET the archive's own page (the credit). */
    function kop_survivor_archives_home($site) {
        if ($site === 'sciad') return 'https://wwaspsurvivorstruth.com/program-archive/';
        $sites = kop_survivor_archives_sites();
        return kop_survivor_archives_scheme($site) . '://' . ($site === 'nhym' ? 'www.' : '') . ($sites[$site] ?? '') . '/';
    }
}

if (!function_exists('kop_survivor_archives_scheme')) {
    /**
     * The scheme a site's documents must use: https, except thestraights.net,
     * which has no https (its TLS handshake fails), so its links are http.
     */
    function kop_survivor_archives_scheme($site) {
        return $site === 'straights' ? 'http' : 'https';
    }
}

if (!function_exists('kop_survivor_archives_dir')) {
    function kop_survivor_archives_dir() {
        // The test points KOP_SURVIVOR_ARCHIVES_DIR at a fixture.
        return defined('KOP_SURVIVOR_ARCHIVES_DIR') ? KOP_SURVIVOR_ARCHIVES_DIR : dirname(__DIR__) . '/js/data/survivor-archives';
    }
}

if (!function_exists('kop_survivor_archives_index')) {
    /**
     * Site key => {built, label, url, facilities: {id: n}, operators: {id: n}},
     * for the sites that have a build.
     */
    function kop_survivor_archives_index($reload = false) {
        static $index = null;
        if ($index !== null && !$reload) return $index;
        $index = array();
        foreach (kop_survivor_archives_sites() as $site => $host) {
            $path = kop_survivor_archives_dir() . '/' . $site . '/index.json';
            if (!is_readable($path)) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) continue;
            $entry = array(
                'built'      => (string) ($data['built'] ?? ''),
                'label'      => (string) ($data['label'] ?? $host),
                'url'        => kop_survivor_archives_home($site),
                'facilities' => array(),
                'operators'  => array(),
            );
            foreach (array('facilities', 'operators') as $k) {
                foreach ((array) ($data[$k] ?? array()) as $id => $n) {
                    if ((int) $n > 0) $entry[$k][(int) $id] = (int) $n;
                }
            }
            $index[$site] = $entry;
        }
        return $index;
    }
}

if (!function_exists('kop_survivor_archives_facility_counts')) {
    /** Facility id => documents listed from every site, for the page qualification. */
    function kop_survivor_archives_facility_counts() {
        $out = array();
        foreach (kop_survivor_archives_index() as $entry) {
            foreach ($entry['facilities'] as $id => $n) $out[$id] = ($out[$id] ?? 0) + $n;
        }
        return $out;
    }
}

if (!function_exists('kop_survivor_archives_cache_key')) {
    /** Changes with every build, for the page index fingerprints. */
    function kop_survivor_archives_cache_key() {
        $parts = array();
        foreach (kop_survivor_archives_index() as $site => $e) {
            $parts[] = $site . ':' . $e['built'] . ':' . count($e['facilities']) . ':' . array_sum($e['facilities'])
                . ':' . count($e['operators']) . ':' . array_sum($e['operators']);
        }
        return implode('|', $parts);
    }
}

if (!function_exists('kop_survivor_archives')) {
    /**
     * The documents listed for a facility ('f') or operator ('o'), or for
     * several ids of the same one (an operator page merges its duplicates):
     * a list, one per site, of {site, label, url, count, groups: [{group, files: [{name, url}]}]}.
     * Empty when there are none. Groups keep the site's own order.
     */
    function kop_survivor_archives($kind, $ids) {
        $kind = $kind === 'o' ? 'o' : 'f';
        $out = array();
        foreach (kop_survivor_archives_index() as $site => $entry) {
            $list = $kind === 'o' ? $entry['operators'] : $entry['facilities'];
            $hosts = kop_survivor_archives_hosts($site);
            $groups = array();
            $seen = array();
            foreach (array_unique(array_map('intval', (array) $ids)) as $id) {
                if ($id <= 0 || empty($list[$id])) continue;
                $path = kop_survivor_archives_dir() . '/' . $site . '/' . $kind . '/' . $id . '.json';
                if (!is_readable($path)) continue;
                $data = json_decode((string) file_get_contents($path), true);
                foreach ((array) ($data['files'] ?? array()) as $f) {
                    $url = (string) ($f['url'] ?? '');
                    // Only links to the site itself (SCIAD NET: Google Drive or Docs), over its scheme (https; http for thestraights.net alone).
                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== kop_survivor_archives_scheme($site) || !in_array(preg_replace('/^www\./', '', $host), $hosts, true)) continue;
                    if (isset($seen[$url])) continue;
                    $seen[$url] = true;
                    $groups[trim((string) ($f['group'] ?? ''))][] = array(
                        'name' => trim((string) ($f['name'] ?? '')) !== '' ? trim((string) $f['name']) : basename((string) parse_url($url, PHP_URL_PATH)),
                        'url'  => $url,
                    );
                }
            }
            if (!$groups) continue;
            $count = 0;
            $list_groups = array();
            foreach ($groups as $group => $files) {
                $list_groups[] = array('group' => $group, 'files' => $files);
                $count += count($files);
            }
            $out[] = array('site' => $site, 'label' => $entry['label'], 'url' => $entry['url'],
                           'count' => $count, 'groups' => $list_groups);
        }
        return $out;
    }
}

if (!function_exists('kop_survivor_archives_render')) {
    /**
     * One "From <site>" block per site for a Documents section. A single
     * short group prints open; otherwise every group folds away.
     */
    function kop_survivor_archives_render($archives, $name) {
        $html = '';
        foreach ((array) $archives as $a) {
            if (empty($a['groups'])) continue;
            $count = (int) $a['count'];
            $html .= '<div class="kop-unsilenced-archive kop-survivor-archive">';
            if (($a['site'] ?? '') === 'sciad') {
                // Credit on every block (owner decision 18): the heading links the archive's own page.
                $html .= '<h3 class="kop-fp-subhead">From <a href="' . esc_url($a['url']) . '" target="_blank" rel="noopener">'
                    . esc_html($a['label']) . '</a></h3>';
                $intro = sprintf(
                    '%s %s about %s that Kids Over Profits does not hold a copy of. SCIAD NET keeps %s on Google Drive, where %s.',
                    number_format($count), $count === 1 ? 'document' : 'documents', $name,
                    $count === 1 ? 'it' : 'them', $count === 1 ? 'it opens' : 'they open'
                );
                $html .= '<p class="kop-fp-detail">' . esc_html($intro) . '</p>';
            } else {
                $html .= '<h3 class="kop-fp-subhead">From ' . esc_html($a['label']) . '</h3>';
                $intro = sprintf(
                    '%s %s about %s that %s published and Kids Over Profits does not hold a copy of.',
                    number_format($count), $count === 1 ? 'document' : 'documents', $name, $a['label']
                );
                $html .= '<p class="kop-fp-detail">' . esc_html($intro) . ' They open on <a href="' . esc_url($a['url'])
                    . '" target="_blank" rel="noopener">' . esc_html($a['label']) . '</a>\'s website.</p>';
            }
            $single = count($a['groups']) === 1;
            foreach ($a['groups'] as $g) {
                $n = count($g['files']);
                $list = '<ul class="kop-fp-records kop-unsilenced-files">';
                foreach ($g['files'] as $f) {
                    $list .= '<li><a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">' . esc_html($f['name']) . '</a></li>';
                }
                $list .= '</ul>';
                if ($single && $n <= 25) {
                    $html .= $list;
                    continue;
                }
                $label = $g['group'] !== '' ? $g['group'] : 'Documents';
                $html .= '<details class="kop-unsilenced-group"><summary>' . esc_html($label)
                    . ' <span class="meta">(' . number_format($n) . ')</span></summary>' . $list . '</details>';
            }
            $html .= '</div>';
        }
        return $html;
    }
}
