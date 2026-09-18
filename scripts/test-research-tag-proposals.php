<?php
/**
 * Offline test for the matcher in api/propose-research-facility-tags.php.
 *
 *   php scripts/test-research-tag-proposals.php [--db=tmp/prod.sqlite]
 *
 * The script itself needs WordPress and the library's PDFs, so only its two
 * pure functions are exercised here: the name index built from facilities_v2
 * and the phrase matcher. What matters is that industry prose ("residential
 * treatment centers", "boys ranch", "the academy") proposes nothing, while a
 * program named in a sentence proposes exactly itself.
 *
 * Runs against a small synthetic set of facilities always, and additionally
 * against the SQLite mirror of production when one is there
 * (scripts/sync-prod-sqlite.py writes tmp/prod.sqlite), which is where a
 * regression in the noise rules would show up. Read-only throughout.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

require ABSPATH . 'api/facility-aliases.php';   // kop_normalize_name_key()

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (ABSPATH . 'tmp/prod.sqlite');

/** $wpdb stand-in: one facilities_v2 query, answered from an array. */
class KOP_Tag_Test_Wpdb {
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function get_results($query, $format = null) { return $this->rows; }
}

/** $wpdb stand-in reading the real facilities_v2 out of the mirror. */
class KOP_Tag_Mirror_Wpdb {
    private $pdo;
    public function __construct($path) { $this->pdo = new PDO('sqlite:' . $path); }
    public function get_results($query, $format = null) {
        return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** Pull one function's source out of the script and define it here. */
function kop_tag_test_define($function_name) {
    static $src = null;
    if ($src === null) {
        $src = file_get_contents(ABSPATH . 'api/propose-research-facility-tags.php');
    }
    $start = strpos($src, "function $function_name(");
    if ($start === false) {
        throw new Exception("api/propose-research-facility-tags.php has no $function_name()");
    }
    $depth = 0;
    for ($j = strpos($src, '{', $start); $j < strlen($src); $j++) {
        if ($src[$j] === '{') {
            $depth++;
        } elseif ($src[$j] === '}') {
            $depth--;
            if ($depth === 0) {
                eval(substr($src, $start, $j - $start + 1));
                return;
            }
        }
    }
    throw new Exception("unbalanced braces in $function_name()");
}

foreach (array('kop_prft_generic_names', 'kop_prft_name_index', 'kop_prft_matches') as $fn) {
    kop_tag_test_define($fn);
}

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, json_encode($got), json_encode($want));
}

// --- A synthetic library ----------------------------------------------------

$facility = function ($id, $name, array $other = array()) {
    return array(
        'id'        => $id,
        'name'      => $name,
        'json_data' => json_encode(array('identification' => array('otherNames' => $other))),
    );
};

$GLOBALS['wpdb'] = new KOP_Tag_Test_Wpdb(array(
    $facility(1, 'Provo Canyon School', array('Provo Canyon Boys School')),
    $facility(2, 'Discovery Ranch'),
    $facility(3, 'Discovery Ranch for Girls'),
    $facility(4, 'Elk Mountain Academy'),
    $facility(5, 'The Academy'),              // generic: never matched on
    $facility(6, 'Boys Ranch'),               // generic
    $facility(7, 'Hyde'),                     // one short word
    $facility(8, 'Turning Point'),            // two words, ten characters: allowed
    $facility(9, 'Turning Point'),            // the same name twice: ambiguous, dropped
));

$index = kop_prft_name_index();
$keys = array_keys($index);
sort($keys);

echo "-- The index --\n";
check('a facility and its alias are both indexed', in_array('provo canyon school', $keys, true) && in_array('provo canyon boys school', $keys, true), true);
check('a generic name is left out', in_array('the academy', $keys, true) || in_array('academy', $keys, true), false);
check('another generic name is left out', in_array('boys ranch', $keys, true), false);
check('a one-word name is left out', in_array('hyde', $keys, true), false);
check('a name two facilities share is dropped', in_array('turning point', $keys, true), false);
check('both Discovery Ranches are indexed', in_array('discovery ranch', $keys, true) && in_array('discovery ranch for girls', $keys, true), true);

echo "\n-- The matcher --\n";
check('a program named in prose', kop_prft_matches('In 2004 a boy died at Provo Canyon School in Utah.', $index), array(1 => 'provo canyon school'));
check('case and punctuation do not matter', kop_prft_matches('reports about provo canyon school, and its owner', $index), array(1 => 'provo canyon school'));
check('an alias resolves to the facility', kop_prft_matches('then the Provo Canyon Boys School', $index), array(1 => 'provo canyon boys school'));
check('the longer name wins over the name inside it', kop_prft_matches('The facility, then Discovery Ranch for Girls, was licensed.', $index), array(3 => 'discovery ranch for girls'));
check('the shorter name still matches on its own', kop_prft_matches('A complaint about Discovery Ranch was filed.', $index), array(2 => 'discovery ranch'));
check('two programs in one sentence', kop_prft_matches('Both Provo Canyon School and Elk Mountain Academy appear.', $index), array(4 => 'elk mountain academy', 1 => 'provo canyon school'));
check('industry prose proposes nothing', kop_prft_matches(
    'Residential treatment centers and therapeutic boarding schools are licensed by the states, and youth services vary. The academy model, boot camps and wilderness therapy all appear.',
    $index
), array());
check('a bare generic phrase proposes nothing', kop_prft_matches('The boys ranch and the group home both reported restraints.', $index), array());
check('a name inside a longer unrelated word does not count', kop_prft_matches('Discovery Ranching Limited is a cattle company.', $index), array());
check('empty text', kop_prft_matches('', $index), array());

// --- The real library, when the mirror is here ------------------------------

echo "\n-- Against the production mirror --\n";
if (!file_exists($db_path)) {
    echo "SKIP no mirror at $db_path (run scripts/sync-prod-sqlite.py)\n";
} elseif (!extension_loaded('pdo_sqlite')) {
    echo "SKIP pdo_sqlite is not loaded\n";
} else {
    $GLOBALS['wpdb'] = new KOP_Tag_Mirror_Wpdb($db_path);
    $real = kop_prft_name_index();
    printf("     %d names indexed from facilities_v2\n", count($real));
    check('the real index is not empty', count($real) > 1000, true);

    $prose = str_repeat(
        'Residential treatment centers, therapeutic boarding schools and wilderness programs across the states '
        . 'reported restraints, seclusion and staff turnover during the period under review. ',
        200
    );
    $noise = kop_prft_matches($prose, $real);
    check('40 KB of industry prose proposes nothing', $noise, array());
    if ($noise) {
        printf("     would have proposed: %s\n", implode('; ', array_slice(array_values($noise), 0, 10)));
    }
    check('a real program is still found', array_values(kop_prft_matches('a death at Provo Canyon School', $real)), array('provo canyon school'));
}

echo $failures ? "\n$failures FAILURES\n" : "\nresearch tag proposals: PASS\n";
exit($failures ? 1 : 0);
