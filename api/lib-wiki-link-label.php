<?php
/**
 * The name to show for what a wiki entry is linked to
 * (wiki_submissions.facility_unique_name). The column holds one of:
 *
 *   a program's unique_name   -> its facilities_v2 name ("Aspen Education Group #2" -> "Aspen Education Group")
 *   a company's name          -> itself
 *   "consultant:12", "provider:3", "transporter:7"
 *                             -> that record's name (inc/wiki-updates.php kop_wiki_upd_pick())
 *
 * Every editor that prints a link shows this name, never the stored value.
 * Plain PDO, so the api/ endpoints and inc/ code share it.
 */

if (!function_exists('kop_wiki_link_labels')) {
    /** The table behind a consultant/provider/transporter token kind, prefixed or not, or ''. */
    function kop_wiki_link_kind_table(PDO $pdo, $kind) {
        static $found = array();
        $bases = array('consultant' => 'referrers_master', 'provider' => 'providers_master', 'transporter' => 'transporters_master');
        if (!isset($bases[$kind])) return '';
        if (isset($found[$kind])) return $found[$kind];
        $prefixes = array();
        if (isset($GLOBALS['wpdb']->prefix) && is_string($GLOBALS['wpdb']->prefix)) $prefixes[] = $GLOBALS['wpdb']->prefix;
        if (isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix'])) $prefixes[] = $GLOBALS['table_prefix'];
        $prefixes[] = 'wpdl_';
        $prefixes[] = '';
        foreach (array_unique($prefixes) as $p) {
            try {
                $pdo->query('SELECT 1 FROM `' . $p . $bases[$kind] . '` LIMIT 1');
                return $found[$kind] = $p . $bases[$kind];
            } catch (Throwable $e) {
                // Not under this name.
            }
        }
        return $found[$kind] = '';
    }

    /**
     * stored value => name to show, for several links at once (one query per
     * kind). A value with no record behind it is shown as it is.
     */
    function kop_wiki_link_labels(PDO $pdo, array $values) {
        $out = array();
        $programs = array();
        $tokens = array();
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '' || isset($out[$v])) continue;
            $out[$v] = $v;
            if (preg_match('/^(consultant|provider|transporter):(\d+)$/', $v, $m)) $tokens[$m[1]][(int) $m[2]] = $v;
            else $programs[] = $v;
        }
        foreach (array_chunk($programs, 200) as $chunk) {
            try {
                $st = $pdo->prepare('SELECT unique_name, name FROM facilities_v2 WHERE unique_name IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (trim((string) $r['name']) !== '' && isset($out[$r['unique_name']])) $out[$r['unique_name']] = trim((string) $r['name']);
                }
            } catch (Throwable $e) {
                // No facilities_v2 on this copy: the stored names stand.
            }
        }
        foreach ($tokens as $kind => $ids) {
            $table = kop_wiki_link_kind_table($pdo, $kind);
            if ($table === '') continue;
            $list = array_keys($ids);
            $st = $pdo->prepare("SELECT id, unique_name FROM `{$table}` WHERE id IN (" . implode(',', array_fill(0, count($list), '?')) . ')');
            $st->execute($list);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (trim((string) $r['unique_name']) !== '') $out[$ids[(int) $r['id']]] = trim((string) $r['unique_name']);
            }
        }
        return $out;
    }

    /** The name to show for one stored link ('' for none). */
    function kop_wiki_link_label(PDO $pdo, $value) {
        $value = trim((string) $value);
        return $value === '' ? '' : (kop_wiki_link_labels($pdo, array($value))[$value] ?? $value);
    }
}
