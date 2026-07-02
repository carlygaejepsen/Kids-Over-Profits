<?php
/**
 * Extract Wiki Fields From Prose (AI)
 *
 * Takes freeform prose about a TTI program/organization and returns the
 * structured wiki-editor fields it can infer, as JSON. Reuses the shared
 * multi-provider AI layer (api/ai-providers.php); defaults to Groq.
 *
 * POST /api/extract-wiki-from-prose.php
 * Body: { prose: string, provider?: string, customInstructions?: string }
 * Returns: { success, data: {field: value, ...}, provider }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

define('SKIP_DB_CONNECTION', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-providers.php';

// Field groups the extractor targets (must match the wiki-editor form ids).
$SCALAR_FIELDS = [
    'programName'    => 'Program / facility / organization name',
    'yearsActive'    => 'Years active, e.g. "1989-present"',
    'cityState'      => 'City, State abbreviation, e.g. "Escalante, UT"',
    'programType'    => 'Program type, e.g. "Residential Treatment Center", "Wilderness Program", "Therapeutic Boarding School"',
    'yearFounded'    => 'Year founded, e.g. "1989"',
    'ageRange'       => 'Age range served, e.g. "13-17"',
    'capacity'       => 'Maximum enrollment / capacity, e.g. "40 students"',
    'ownerName'      => 'Owner / operator individual or company',
    'parentCompany'  => 'Parent company',
    'headquarters'   => 'Headquarters / main office location',
    'rebrand'        => 'Program this is a rebrand or spin-off of',
    'avgStay'        => 'Average length of stay, e.g. "100 days"',
    'tuition'        => 'Tuition / cost, e.g. "$40,000+"',
    'natsapYear'     => 'Year NATSAP membership began, if mentioned',
    'mainAddress'    => 'Street address, if given',
    'accreditingBody'=> 'Accrediting body, e.g. "Joint Commission", "CARF"',
];
$NARRATIVE_FIELDS = [
    'historyNotes'    => 'History and background narrative',
    'levelSystemDesc' => 'Description of the level/point/phase system',
    'structureMisc'   => 'Daily structure, schedule, and program model details',
    'punishmentsMisc' => 'Punishments, consequences, restraints, seclusion, discipline practices',
    'lawsuitsMisc'    => 'Lawsuits, investigations, regulatory actions, criminal cases',
    'mediaInfo'       => 'Media coverage, documentaries, news',
    'testimoniesMisc' => 'Survivor testimonies / firsthand accounts',
    'relatedMediaMisc'=> 'Related media, books, films, podcasts',
];
$DIAGNOSES = [
    'ADHD', 'Autism Spectrum Disorder', 'Bipolar Disorder', 'Depression', 'Anxiety', 'OCD', 'PTSD',
    'Oppositional Defiant Disorder', 'Conduct Disorder', 'Eating Disorder', 'Substance Abuse',
    'Borderline Personality Disorder', 'Psychiatric Disorders', 'Behavioral Disorders',
    'Emotional Disorders', 'Co-occurring Disorders', 'Reactive Attachment Disorder', 'Personality Disorders',
];
$NATSAP_VALUES = ['yes', 'no', 'former', ''];

function kop_build_wiki_prompt(string $prose, string $custom, array $scalar, array $narrative, array $diagnoses): string {
    $lines = [];
    foreach ($scalar as $k => $desc) {
        $lines[] = "  \"$k\": \"\"            // $desc";
    }
    $lines[] = "  \"natsapMember\": \"\"      // one of: yes, no, former, or \"\" if unknown";
    $lines[] = "  \"diagnoses\": []           // subset of the allowed list below";
    foreach ($narrative as $k => $desc) {
        $lines[] = "  \"$k\": \"\"            // $desc (a few sentences of prose)";
    }
    $schema = implode("\n", $lines);
    $diagList = implode(', ', $diagnoses);

    $prompt = <<<PROMPT
You are a careful research assistant for a database documenting the "Troubled Teen Industry" (TTI). Read the prose below about a program or organization and extract ONLY the facts that are explicitly stated.

Return a SINGLE JSON object with exactly these keys (omit nothing; use "" for unknown text fields and [] for unknown lists):

{
$schema
}

Rules:
- Do NOT invent, infer beyond the text, or guess. If a field is not stated, leave it empty.
- Copy dates, names, numbers, and locations exactly as written.
- "diagnoses" must be a subset of EXACTLY these allowed values (match wording exactly): $diagList
- Narrative fields should be concise, neutral, factual prose summarizing what the text says for that topic — not copied verbatim if long. Leave "" if the text says nothing about that topic.
- Output JSON only. No markdown, no commentary.
PROMPT;

    if (trim($custom) !== '') {
        $prompt .= "\n\nAdditional instructions from the editor:\n" . trim($custom);
    }

    $prompt .= "\n\nPROSE:\n\"\"\"\n" . $prose . "\n\"\"\"";
    return $prompt;
}

/** Keep only known keys and coerce types so the client gets a predictable shape. */
function kop_sanitize_wiki_fields(array $parsed, array $scalar, array $narrative, array $diagnoses, array $natsapValues): array {
    $out = [];
    foreach (array_merge(array_keys($scalar), array_keys($narrative)) as $k) {
        $v = $parsed[$k] ?? '';
        $out[$k] = is_scalar($v) ? trim((string) $v) : '';
    }

    $natsap = strtolower(trim((string) ($parsed['natsapMember'] ?? '')));
    $out['natsapMember'] = in_array($natsap, $natsapValues, true) ? $natsap : '';

    $allowed = array_map('strtolower', $diagnoses);
    $diag = [];
    if (isset($parsed['diagnoses']) && is_array($parsed['diagnoses'])) {
        foreach ($parsed['diagnoses'] as $d) {
            if (!is_string($d)) continue;
            $idx = array_search(strtolower(trim($d)), $allowed, true);
            if ($idx !== false) {
                $diag[] = $diagnoses[$idx]; // canonical casing
            }
        }
    }
    $out['diagnoses'] = array_values(array_unique($diag));

    return $out;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$prose = trim((string) ($input['prose'] ?? $input['text'] ?? ''));
$provider = $input['provider'] ?? 'groq';
$custom = (string) ($input['customInstructions'] ?? '');

if ($prose === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please paste some prose to extract from.']);
    exit;
}
if (strlen($prose) > 20000) {
    $prose = substr($prose, 0, 20000) . "\n... [truncated]";
}

try {
    $prompt = kop_build_wiki_prompt($prose, $custom, $SCALAR_FIELDS, $NARRATIVE_FIELDS, $DIAGNOSES);
    $raw = kop_ai_generate($provider, kop_ai_api_keys(), $prompt, ['maxTokens' => 4096]);
    $parsed = kop_ai_extract_json($raw);
    if ($parsed === null) {
        throw new Exception('The AI did not return usable JSON. Try again, or pick a different provider.');
    }
    $data = kop_sanitize_wiki_fields($parsed, $SCALAR_FIELDS, $NARRATIVE_FIELDS, $DIAGNOSES, $NATSAP_VALUES);

    echo json_encode(['success' => true, 'data' => $data, 'provider' => $provider]);
} catch (Exception $e) {
    error_log('extract-wiki-from-prose.php [' . $provider . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
