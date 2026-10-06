<?php
/**
 * Plain-language summaries (inc/highlight-summaries.php): which findings are
 * picked as hard to read (the three examples the owner pasted, a clear one
 * that must be left alone, and the share picked among the mirror's approved
 * findings), the AI's instruction and what is accepted from its answer, and
 * how an approved summary prints. The queue itself (approve, edit, write
 * again, out of date) is checked by
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=highlight-summaries
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-highlight-summaries.php [--db=tmp/prod.sqlite] [--list]
 */

// Just enough WordPress for the two files: they register hooks and escape output.
define('ABSPATH', dirname(__DIR__) . '/');
function add_action() {}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';
require_once dirname(__DIR__) . '/inc/highlight-summaries.php';

$args = getopt('', array('db::', 'list'));
$failures = 0;
$checks = 0;
function check($ok, $label, $detail = '') {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
$why = function ($t) { return json_encode(kop_hs_reasons($t)); };

// The examples the owner called confusing.
$nc = "Spoke with FC #3 again and this time she reported staff #1 slammed her head into the wall and staff #2 pulled her by hair and pushed her back on couch [...] FC #3 reported that both staff #1 and staff #2 assaulted her Interviews on 7/22/26 and 7/27/26 the Director reported: She was aware that FC #3 reported that staff [...]";
$mn = "Given that the SP did not intervene to protect the AV as multiple youth chased him/her, sprayed him/her with water, and physically assaulted the AV including punching the AV in the stomach causing the AV to bend over and grab his/her stomach and another occasion when the AV was hit with a skateboard in the groin";
$ca = "On April 16, 2024, the Department received a complaint alleging a Blissful Living Group Home, Inc. (BLGHI) staff (S1) (Reference Confidential Names form LIC811 dated 11/4/24) sexually abused a client in care. [...] A client alleged that while at her home, S1 sexually abused him by kissing him on the lips and fondling his genitals over his clothing. [...] Under Health and Safety Code (HSC) section 1548(f)(1)(F)(2), a violation the Department determines results in sexual abuse as defined in Section 11165.1 of the Penal Code to a child receiving care through a Short Term Residential Therapeutic Program (STRTP) there shall be a civil penalty of $2,500.";
check(kop_hs_confusing($nc), 'picked: NC former clients, numbered staff, sentences run together', $why($nc));
check(kop_hs_confusing($mn), 'picked: MN one very long sentence', $why($mn));
check(kop_hs_confusing($ca), 'picked: CA statute citations, abbreviations and gaps', $why($ca));

// Text that reads fine is left alone.
$clear = array(
    'The operation failed to seek follow-up medical care with a general practice pediatrician as recommended by Texas Children\'s Hospital, for a child in care.',
    'A staff member slapped a child across the face during an argument in the dining room. The allegation is substantiated.',
    'On May 3, 2023, a resident was taken to the emergency room after staff found her unresponsive. She was treated for an overdose and returned the next day.',
);
foreach ($clear as $t) check(!kop_hs_confusing($t), 'left alone: ' . substr($t, 0, 50), $why($t));
// One thing alone is not enough: a gap or a code does not make a short finding hard to read.
check(!kop_hs_confusing('A staff member hit a child. [...] The state found the allegation substantiated.'), 'left alone: a single gap');
check(!kop_hs_confusing('Staff 1 hit Child 1 in the face and Child 1 was taken to the ER.'), 'left alone: two numbered people and a known abbreviation');
check(kop_hs_confusing("A result that the state\u{FFFD}s own report garbled \u{FFFD}\u{FFFD} so that no sentence can be read."), 'picked: broken characters');
check(!kop_hs_confusing('CONTINUED ON THE NEXT PAGE A child was hit by a staff member in the hallway.'), 'left alone: shouted boilerplate is not an abbreviation');

// Who is on the page of the mirror.
$db = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (file_exists($db)) {
    $pdo = new PDO('sqlite:' . $db);
    $rows = $pdo->query("SELECT id, state, excerpt FROM inspection_highlights WHERE status = 'approved'")->fetchAll(PDO::FETCH_ASSOC);
    $picked = array_values(array_filter($rows, function ($r) { return kop_hs_confusing($r['excerpt']); }));
    $share = $rows ? count($picked) / count($rows) : 0;
    check($rows && $share > 0.03 && $share < 0.25, 'only a minority of approved findings is picked', count($picked) . ' of ' . count($rows));
    echo count($picked) . ' of ' . count($rows) . " approved findings are picked\n";
    if (isset($args['list'])) foreach ($picked as $r) echo "  #{$r['id']} {$r['state']} " . $why($r['excerpt']) . "\n";
}

// The instruction, and what an answer must be to be kept.
$p = kop_hs_prompt($nc);
check(strpos($p, 'Never use a person\'s name') !== false && strpos($p, 'Use only what the text says') !== false && strpos($p, 'UNCLEAR') !== false, 'the instruction forbids names and invention and allows UNCLEAR');
check(strpos(explode('Text:', $p)[1], 'FC #3') === false && strpos($p, '[Former client 3]') !== false, 'the AI reads the labels spelled out');
$good = 'A girl in the program said two staff members hurt her. The director said she knew about the report.';
check(kop_hs_clean_summary($good) === $good, 'a plain answer is kept');
check(kop_hs_clean_summary("Summary: \"A girl in the program said two staff members hurt her.\" The director knew.") === 'A girl in the program said two staff members hurt her. The director knew.', 'a label and quotation marks are removed');
foreach (array(
    'UNCLEAR' => 'UNCLEAR', 'empty' => '', 'codes' => 'S1 hurt C1 in the hallway and was fired.', 'FC' => 'FC #3 said she was hurt by staff.',
    'brackets' => 'A [redacted] hurt a child in the program.', 'link' => 'Read more at https://example.com about this child.',
    'chatter' => 'Here is a summary: a staff member hurt a child.', 'AI' => 'As an AI I cannot tell what happened to the child.',
    'short' => 'Staff hurt her.', 'long' => str_repeat('A staff member hurt a child in the program. ', 20),
) as $what => $bad) {
    try { kop_hs_clean_summary($bad); check(false, "refused: $what"); } catch (RuntimeException $e) { check(true, "refused: $what"); }
}

// Answers the AI wrapped in data, or with the text's numbered people copied in, are repaired, not thrown away.
check(kop_hs_clean_summary('{ summary: The state found that a staff member punched a child in the program. }') === 'The state found that a staff member punched a child in the program.', 'an answer wrapped in { summary: ... } is unwrapped');
check(kop_hs_clean_summary('{"summary": "A staff member hit a child in the program, the state said."}') === 'A staff member hit a child in the program, the state said.', 'a JSON answer is unwrapped');
check(kop_hs_clean_summary('[Staff 1] hit [Child 2] in the hallway and the state found it true.') === 'a staff member hit a child in the program in the hallway and the state found it true.', 'copied person brackets become plain words');
check(kop_hs_clean_summary("```\nA staff member hit a child in the program and the state found it true.\n```") === 'A staff member hit a child in the program and the state found it true.', 'a code fence is removed');
$tries = 0;
$GLOBALS['kop_hs_ai'] = function ($prompt) use (&$tries) { $tries++; return $tries === 1 ? 'S1 hit C1 in the hallway and was fired.' : 'A staff member hit a child in the program and was fired.'; };
check(kop_hs_draft($nc) === 'A staff member hit a child in the program and was fired.' && $tries === 2, 'an answer refused for its form gets one more try with the reason');
$tries = 0;
$GLOBALS['kop_hs_ai'] = function ($prompt) use (&$tries) { $tries++; return 'UNCLEAR'; };
try { kop_hs_draft($nc); check(false, 'UNCLEAR is final'); } catch (RuntimeException $e) { check($tries === 1, 'UNCLEAR is final: no second try'); }
unset($GLOBALS['kop_hs_ai']);

// How an approved summary prints.
$html = kop_hs_plain_html('A staff member hit a child & ran <b>away</b>.');
check(strpos($html, 'In plain words:') !== false && strpos($html, '&amp;') !== false && strpos($html, '<b>') === false && strpos($html, 'state\'s own wording') !== false, 'an approved summary prints escaped, with the note that the state\'s wording follows');
check(kop_hs_plain_html('  ') === '', 'no summary prints nothing');
check(kop_hs_hash("text\n") === kop_hs_hash('text') && kop_hs_hash('a') !== kop_hs_hash('b'), 'a summary is tied to the exact excerpt');

echo "Total: $checks checks, $failures failed.\n";
exit($failures ? 1 : 0);
