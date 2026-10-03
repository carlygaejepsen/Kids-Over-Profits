<?php
/**
 * Helpers for the review inbox source checks in this folder (loaded by
 * scripts/test-review-inbox.php with them; defines no source of its own).
 */

if (!function_exists('kop_rinbox_test_wants')) {
    /** True when this run checks $source (no --source, or that one). */
    function kop_rinbox_test_wants($source) {
        $only = (string) ($GLOBALS['only'] ?? '');
        return $only === '' || $only === $source;
    }
}

if (!function_exists('kop_rinbox_test_copy_tables')) {
    /** Copy tables from the mirror into the scratch copy, where they are not there yet. */
    function kop_rinbox_test_copy_tables(array $tables) {
        $pdo = $GLOBALS['pdo'];
        $mirror = (string) ($GLOBALS['mirror'] ?? '');
        if ($mirror === '' || !file_exists($mirror)) return;
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS rinbox_src');
        try {
            foreach (array_unique($tables) as $t) {
                if ($pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn()) continue;
                $sql = $pdo->query("SELECT sql FROM rinbox_src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
                if (!$sql) continue;
                $pdo->exec($sql);
                $pdo->exec("INSERT INTO main.`$t` SELECT * FROM rinbox_src.`$t`");
            }
        } finally {
            $pdo->exec('DETACH DATABASE rinbox_src');
        }
    }
}

if (!function_exists('kop_rinbox_test_options_persist')) {
    /** Whether update_option() stores in this harness (some checks need it). */
    function kop_rinbox_test_options_persist() {
        update_option('kop_rinbox_test_probe', 'stored');
        $ok = get_option('kop_rinbox_test_probe') === 'stored';
        unset($GLOBALS['kop_test_options']['kop_rinbox_test_probe']);
        return $ok;
    }
}

if (!function_exists('kop_rinbox_test_with_wpdb_writes')) {
    /**
     * Run $fn with a $wpdb whose query() runs its SQL on the scratch copy (the
     * harness's query() does nothing), then put the harness's $wpdb back.
     */
    function kop_rinbox_test_with_wpdb_writes(callable $fn) {
        $saved = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class($GLOBALS['pdo']) extends wpdb {
            private $db;
            public $insert_id = 0;
            public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
            public function query($sql) {
                if (preg_match('/^\s*(CREATE|ALTER)\b/i', $sql)) return 0;
                $n = $this->db->exec($sql);
                if (preg_match('/^\s*INSERT\b/i', $sql)) $this->insert_id = (int) $this->db->lastInsertId();
                return $n;
            }
            public function insert($table, array $data) {
                $n = parent::insert($table, $data);
                $this->insert_id = (int) $this->db->lastInsertId();
                return $n;
            }
        };
        try {
            return $fn();
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }
}

if (!function_exists('kop_rinbox_test_skip')) {
    function kop_rinbox_test_skip($label, $why) {
        echo "SKIP $label  ($why)\n";
    }
}

// MySQL's named locks (kop_v2_with_write_lock) on SQLite.
$GLOBALS['pdo']->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$GLOBALS['pdo']->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
