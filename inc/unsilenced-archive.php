<?php
/**
 * Documents from Unsilenced's program archive that KOP has no copy of.
 *
 * Unsilenced (unsilenced.org) keeps its archive in public Google Drive
 * folders. scripts/build-unsilenced-links.py compares every file there with
 * KOP's own holdings (media library md5s, the inspection scrapers) and writes,
 * per facility and operator, the ones KOP lacks:
 *
 *   js/data/unsilenced/index.json   {built, md5_checked, facilities: {id: n}, operators: {id: n}}
 *   js/data/unsilenced/f/<id>.json  {folders: [{name, id}], files: [{id, name, folder}]}
 *   js/data/unsilenced/o/<id>.json  the same for an operator
 *
 * The facility and operator pages list them in their Documents section,
 * linked to Unsilenced's Drive. Nothing is copied to this site.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_unsilenced_dir')) {
    function kop_unsilenced_dir() {
        // The test points KOP_UNSILENCED_DIR at a fixture.
        return defined('KOP_UNSILENCED_DIR') ? KOP_UNSILENCED_DIR : dirname(__DIR__) . '/js/data/unsilenced';
    }
}

if (!function_exists('kop_unsilenced_index')) {
    /** {built, facilities: {id: n}, operators: {id: n}}; empty lists when the build hasn't run. */
    function kop_unsilenced_index($reload = false) {
        static $index = null;
        if ($index !== null && !$reload) return $index;
        $index = array('built' => '', 'facilities' => array(), 'operators' => array());
        $path = kop_unsilenced_dir() . '/index.json';
        if (!is_readable($path)) return $index;
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) return $index;
        $index['built'] = (string) ($data['built'] ?? '');
        foreach (array('facilities', 'operators') as $k) {
            foreach ((array) ($data[$k] ?? array()) as $id => $n) {
                if ((int) $n > 0) $index[$k][(int) $id] = (int) $n;
            }
        }
        return $index;
    }
}

if (!function_exists('kop_unsilenced_cache_key')) {
    /** Changes with every build, for the page index fingerprints. */
    function kop_unsilenced_cache_key() {
        $index = kop_unsilenced_index();
        return $index['built'] . '|' . count($index['facilities']) . '|' . array_sum($index['facilities'])
            . '|' . count($index['operators']) . '|' . array_sum($index['operators']);
    }
}

if (!function_exists('kop_unsilenced_archive')) {
    /**
     * The documents listed for a facility ('f') or operator ('o'), or for
     * several ids of the same one (an operator page merges its duplicates):
     * {count, folders: [{name, url}], groups: [{folder, files: [{name, url}]}]},
     * or null when there are none. Files at the top of Unsilenced's program
     * folder come first, then each subfolder in order.
     */
    function kop_unsilenced_archive($kind, $ids) {
        $kind = $kind === 'o' ? 'o' : 'f';
        $index = kop_unsilenced_index();
        $list = $kind === 'o' ? $index['operators'] : $index['facilities'];

        $folders = array();
        $groups = array();
        $seen = array();
        foreach (array_unique(array_map('intval', (array) $ids)) as $id) {
            if ($id <= 0 || empty($list[$id])) continue;
            $path = kop_unsilenced_dir() . '/' . $kind . '/' . $id . '.json';
            if (!is_readable($path)) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || empty($data['files'])) continue;
            foreach ((array) ($data['folders'] ?? array()) as $f) {
                $fid = (string) ($f['id'] ?? '');
                if (!preg_match('/^[\w-]{10,}$/', $fid) || isset($seen['d' . $fid])) continue;
                $seen['d' . $fid] = true;
                $folders[] = array(
                    'name' => (string) ($f['name'] ?? ''),
                    'url'  => 'https://drive.google.com/drive/folders/' . $fid,
                );
            }
            foreach ($data['files'] as $f) {
                $fid = (string) ($f['id'] ?? '');
                if (!preg_match('/^[\w-]{10,}$/', $fid) || isset($seen[$fid])) continue;
                $seen[$fid] = true;
                $folder = trim((string) ($f['folder'] ?? ''));
                $groups[$folder][] = array(
                    'name' => (string) ($f['name'] ?? ''),
                    'url'  => 'https://drive.google.com/file/d/' . $fid . '/view',
                );
            }
        }
        if (!$groups) return null;
        uksort($groups, function ($a, $b) {
            if ($a === '' || $b === '') return $a === '' ? -1 : 1;
            return strnatcasecmp($a, $b);
        });
        $out = array();
        $count = 0;
        foreach ($groups as $folder => $files) {
            $out[] = array('folder' => $folder, 'files' => $files);
            $count += count($files);
        }
        return array('count' => $count, 'folders' => $folders, 'groups' => $out);
    }
}

if (!function_exists('kop_unsilenced_render')) {
    /**
     * The "From the Unsilenced archive" block for a Documents section.
     * Short lists print open; longer ones, and every subfolder, fold away.
     */
    function kop_unsilenced_render($archive, $name) {
        if (empty($archive['groups'])) return '';
        $count = (int) $archive['count'];
        $html = '<div class="kop-unsilenced-archive">';
        $html .= '<h3 class="kop-fp-subhead">From the Unsilenced archive</h3>';
        $intro = sprintf(
            '%s %s about %s that Unsilenced collected and Kids Over Profits does not hold a copy of.',
            number_format($count), $count === 1 ? 'document' : 'documents', $name
        );
        $html .= '<p class="kop-fp-detail">' . esc_html($intro) . ' They open on '
            . '<a href="https://www.unsilenced.org/program-archive/" target="_blank" rel="noopener">Unsilenced</a>\'s Google Drive.';
        if (!empty($archive['folders'])) {
            $links = array();
            foreach ($archive['folders'] as $f) {
                $links[] = '<a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">' . esc_html($f['name']) . '</a>';
            }
            $html .= ' ' . (count($links) === 1 ? 'Their whole folder: ' : 'Their folders: ') . implode(', ', $links) . '.';
        }
        $html .= '</p>';

        $single = count($archive['groups']) === 1;
        foreach ($archive['groups'] as $g) {
            $n = count($g['files']);
            $list = '<ul class="kop-fp-records kop-unsilenced-files">';
            foreach ($g['files'] as $f) {
                $list .= '<li><a href="' . esc_url($f['url']) . '" target="_blank" rel="noopener">' . esc_html($f['name']) . '</a></li>';
            }
            $list .= '</ul>';
            if ($g['folder'] === '' && ($single ? $n <= 25 : $n <= 10)) {
                $html .= $list;
                continue;
            }
            $label = $g['folder'] !== '' ? $g['folder'] : 'Documents';
            $open = ($g['folder'] === '' && $n <= 25) ? ' open' : '';
            $html .= '<details class="kop-unsilenced-group"' . $open . '><summary>' . esc_html($label)
                . ' <span class="meta">(' . number_format($n) . ')</span></summary>' . $list . '</details>';
        }
        return $html . '</div>';
    }
}
