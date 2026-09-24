<?php
/**
 * Check the PHP glossary build and the Glossary Editor's overlay, offline.
 *
 * - inc/glossary-build.php builds js/data/glossary/glossary.md into exactly
 *   the glossary.json scripts/build-glossary.js wrote (run the Node build
 *   first if glossary.md changed).
 * - Every entry survives the editor form unchanged: its fields, saved as they
 *   are, give back the same markdown paragraph, and a fresh write-out of the
 *   same fields parses to the same entry.
 * - Saved changes apply to glossary.md as they should: edit, add, move,
 *   delete, a change the repo has caught up with, one the repo overtook, and
 *   a broken cross-reference refused by the build.
 *
 * Usage: php scripts/test-glossary-build.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', true);

function get_stylesheet_directory() {
    return dirname(__DIR__);
}
function add_action() {
}
function apply_filters($name, $value) {
    return $value;
}

require dirname(__DIR__) . '/inc/glossary.php';
require dirname(__DIR__) . '/inc/glossary-editor.php';

$failures = 0;
function check($ok, $label) {
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL  $label\n";
    }
    return $ok;
}

/** First path where two decoded structures differ, or ''. */
function first_diff($a, $b, $path = '') {
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) {
                return "$path/$k missing on one side";
            }
            $d = first_diff($a[$k], $b[$k], "$path/$k");
            if ($d !== '') {
                return $d;
            }
        }
        return '';
    }
    return $a === $b ? '' : "$path: " . var_export($a, true) . ' vs ' . var_export($b, true);
}

$md = kop_glossary_repo_markdown();
$json = json_decode(file_get_contents(get_stylesheet_directory() . '/js/data/glossary/glossary.json'), true);

/* 1. Parity with the Node build. */
$built = kop_glossary_build($md);
check(!$built['errors'], 'glossary.md builds: ' . implode('; ', array_slice($built['errors'], 0, 3)));
$diff = $built['data'] ? first_diff($built['data'], $json) : 'no data';
check($diff === '', "PHP build matches glossary.json (stale json? run node scripts/build-glossary.js): $diff");

/* 2. The paragraph split and join give the file back. */
check(implode("\n\n", kop_glossary_paragraphs($md)) . "\n" === str_replace("\r\n", "\n", $md), 'paragraph split/join reproduces glossary.md');
$same = kop_glossary_apply_edits($md, array());
check($same['markdown'] === str_replace("\r\n", "\n", $md), 'no changes leaves glossary.md as it is');

/* 3. Every entry through the form. */
$src = kop_glossary_build($md, true);
$entries = kop_glossary_entries_by_id($src['data']);
$fields_of = function ($source) {
    $o = kop_glossary_parse_entry($source, true);
    return array(
        'term'     => $o['term'],
        'note'     => $o['note'],
        'aka_list' => implode("\n", $o['aka']),
        'text'     => $o['text_raw'],
        'used'     => kop_glossary_tag_lines($o['used']),
        'reported' => kop_glossary_tag_lines($o['reported']),
    );
};
$churn = 0;
$lossy = 0;
foreach ($entries as $id => $e) {
    $orig = kop_glossary_parse_entry($e['source'], true);
    $fields = $fields_of($e['source']);
    $kept = kop_glossary_entry_markdown($fields, $orig);
    if ($kept['errors'] || $kept['markdown'] !== $e['source']) {
        if (++$churn <= 5) {
            echo "  unchanged save of $id: " . ($kept['errors'] ? implode('; ', $kept['errors']) : $kept['markdown']) . "\n";
        }
    }
    $fresh = kop_glossary_entry_markdown($fields, null);
    $a = kop_glossary_parse_entry($fresh['markdown'] ?: '**x**: x');
    $b = kop_glossary_parse_entry($e['source']);
    if ($fresh['errors'] || first_diff($a, $b) !== '') {
        if (++$lossy <= 5) {
            echo "  fresh write-out of $id: " . ($fresh['errors'] ? implode('; ', $fresh['errors']) : first_diff($a, $b)) . "\n";
        }
    }
}
check($churn === 0, "saving an entry unchanged gives back its markdown ($churn of " . count($entries) . ' differ)');
check($lossy === 0, "a fresh write-out of every entry parses the same ($lossy of " . count($entries) . ' differ)');

/* 4. Changes. */
$op = function ($target, $markdown, $container = null, $date = '2099-01-01') {
    static $n = 0;
    return array('id' => 'op' . (++$n), 'target' => $target, 'markdown' => $markdown, 'container' => $container,
        'term' => '', 'user' => 'test', 'time' => '', 'date' => $date);
};
$find = function ($data, $pred) {
    foreach (kop_glossary_all_entries($data) as $e) {
        if ($pred($e)) {
            return $e;
        }
    }
    return null;
};
$bust = null;
foreach ($entries as $e) {
    if (!$e['used'] && !$e['reported'] && $e['aka'] === array() && $e['note'] === '' && count($e['container']) === 1) {
        $bust = $e;
        break;
    }
}
check($bust !== null, 'found a plain entry to edit');

/* Edit in place. */
$fields = $fields_of($bust['source']);
$fields['text'] .= ' An added sentence.';
$fields['reported'] = "Test Academy\nOther Ranch (as \"the thing\")";
$edit = kop_glossary_entry_markdown($fields, kop_glossary_parse_entry($bust['source'], true));
check(!$edit['errors'], 'edit serialises: ' . implode('; ', $edit['errors']));
check(strpos($edit['markdown'], 'Reportedly used at: *Test Academy, Other Ranch (as "the thing")*') !== false, 'tags written in the file style');
$r = kop_glossary_apply_edits($md, array($o1 = $op($bust['source'], $edit['markdown'])));
check($r['status'][$o1['id']] === 'applied', 'edit applies');
check(strpos($r['markdown'], "updated: 2099-01-01\n") !== false, 'the updated date follows the newest change');
$b = kop_glossary_build($r['markdown']);
$got = $b['data'] ? $find($b['data'], function ($e) use ($bust) { return $e['id'] === $bust['id']; }) : null;
check($got && substr($got['text'], -18) === 'An added sentence.', 'edited text is live');
check($got && count($got['reported']) === 2 && $got['reported'][1]['note'] === 'as "the thing"', 'tags with a note are live');
check($b['data'] && $b['data']['count'] === $json['count'], 'entry count unchanged by an edit');

/* The repo catches up: the change clears. */
$caught = kop_glossary_apply_edits($r['markdown'], array($o1));
check($caught['status'][$o1['id']] === 'in_repo', 'a committed edit reads as already in the repo');

/* The repo overtook it: not applied. */
$other = str_replace($bust['source'], $bust['source'] . ' Changed in git.', $md);
$over = kop_glossary_apply_edits($other, array($o1));
check($over['status'][$o1['id']] === 'stale', 'an edit to a paragraph changed in git is out of date');
check($over['markdown'] === $other, 'an out-of-date edit changes nothing');

/* Add a new entry into a group, with a cross-reference. */
$group = null;
foreach ($src['data']['sections'] as $s) {
    if ($s['groups']) {
        $group = array($s['title'], $s['groups'][0]['title']);
        break;
    }
}
$new = kop_glossary_entry_markdown(array('term' => 'Zzyzx test term', 'note' => '', 'aka_list' => "Test alias\nWith, a comma",
    'text' => 'a test. See **' . $bust['term'] . '**.', 'used' => 'Test Academy', 'reported' => ''), null);
check(!$new['errors'], 'new entry serialises: ' . implode('; ', $new['errors']));
$r = kop_glossary_apply_edits($md, array($o2 = $op(null, $new['markdown'], $group)));
$b = kop_glossary_build($r['markdown'], true);
check(!$b['errors'], 'glossary with an added entry builds: ' . implode('; ', $b['errors']));
$got = $b['data'] ? $find($b['data'], function ($e) { return $e['term'] === 'Zzyzx test term'; }) : null;
check($got && $got['container'] === $group, 'added entry lands in its group');
check($got && $got['aka'] === array('Test alias', 'With, a comma'), 'an aka with a comma survives');
check($got && $got['text'] === 'A test. See **' . $bust['term'] . '**.', 'added entry text, capitalised as the build does');
check($b['data'] && $b['data']['count'] === $json['count'] + 1, 'count goes up by one');
check($b['data'] && isset($b['data']['refs'][kop_glossary_lower($bust['term'])]), 'the new cross-reference resolves');

/* Move an entry to another section. */
$target = array($src['data']['sections'][count($src['data']['sections']) - 1]['title']);
$r = kop_glossary_apply_edits($md, array($op($bust['source'], $bust['source'], $target)));
$b = kop_glossary_build($r['markdown'], true);
$got = $b['data'] ? $find($b['data'], function ($e) use ($bust) { return $e['id'] === $bust['id']; }) : null;
check($got && $got['container'] === $target, 'moved entry lands in the new section');

/* Delete, and a delete that would leave a dead link. */
$linked = null;
foreach ($entries as $e) {
    if (in_array($e['id'], $json['refs'], true)) {
        $linked = $e;
        break;
    }
}
$r = kop_glossary_apply_edits($md, array($op($linked['source'], null)));
$b = kop_glossary_build($r['markdown']);
check($b['errors'] && strpos(implode("\n", $b['errors']), 'does not name an entry') !== false, 'deleting a linked entry is refused by the build');
$r = kop_glossary_apply_edits($md, array($o3 = $op($bust['source'], null)));
$b = kop_glossary_build($r['markdown']);
$linked_bust = in_array($bust['id'], $json['refs'], true);
if (!$linked_bust) {
    check(!$b['errors'] && $b['data']['count'] === $json['count'] - 1, 'deleting an unlinked entry works');
}
$gone = kop_glossary_apply_edits($r['markdown'], array($o3));
check($gone['status'][$o3['id']] === 'in_repo', 'a delete the repo already made reads as already in the repo');

/* Form checks. */
$bad = kop_glossary_entry_markdown(array('term' => 'X', 'note' => 'a note', 'aka_list' => 'Y', 'text' => 't', 'used' => '', 'reported' => ''), null);
check((bool) $bad['errors'], 'a qualifier and aka together are refused');
$bad = kop_glossary_entry_markdown(array('term' => 'X', 'note' => '', 'aka_list' => '', 'text' => 't', 'used' => 'Acme, Ltd.', 'reported' => ''), null);
check((bool) $bad['errors'], 'a program with a comma is refused');
$bad = kop_glossary_entry_markdown(array('term' => 'X', 'note' => '', 'aka_list' => '', 'text' => "one\n\ntwo", 'used' => '', 'reported' => ''), null);
check(!$bad['errors'] && strpos($bad['markdown'], "\n") === false, 'a definition typed as two paragraphs is joined into one');

if ($failures) {
    echo "\n$failures check(s) failed.\n";
    exit(1);
}
echo 'OK: PHP build matches glossary.json (' . $json['count'] . " entries); every entry round-trips through the form; overlay changes apply.\n";
