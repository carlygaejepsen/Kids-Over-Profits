<?php
/**
 * Shared helpers for AI lawsuit extraction.
 *
 * Used by:
 *   - api/extract-lawsuit-from-document.php  (admin: registers attachment, FileBird)
 *   - api/extract-lawsuit-suggestion.php     (public: rate-limited, no attachment)
 *
 * Both endpoints implement the same three-action protocol (upload / chunk /
 * finalize); everything protocol-independent lives here.
 */

if (!defined('ABSPATH')) { exit; }

if (!function_exists('kop_resolve_secret')) {
    function kop_resolve_secret($name) {
        $v = getenv($name);
        if ($v !== false && $v !== '') return $v;
        if (!empty($_ENV[$name])) return $_ENV[$name];
        if (!empty($_SERVER[$name])) return $_SERVER[$name];
        if (defined($name)) { $c = constant($name); if (!empty($c)) return $c; }
        return '';
    }
}

/**
 * Sanitize extracted document text and split it into overlapping chunks.
 * Character-based (mb_substr): byte-based substr can cut a multibyte character
 * in half, making the chunk invalid UTF-8 — json_encode of the AI request body
 * then fails and the extraction aborts.
 *
 * @return array{chunks: string[], length: int}
 */
function kop_lawsuit_chunk_text(string $doc_text, int $max_chunks = 10): array {
    $doc_text = str_replace("\0", '', $doc_text);
    $doc_text = iconv('UTF-8', 'UTF-8//IGNORE', $doc_text) ?: $doc_text;
    $doc_text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $doc_text);

    $chunks     = [];
    $chunk_size = 12000;
    $overlap    = 300;
    $offset     = 0;
    $len        = mb_strlen($doc_text, 'UTF-8');
    while ($offset < $len && count($chunks) < $max_chunks) {
        $chunks[] = mb_substr($doc_text, $offset, $chunk_size, 'UTF-8');
        $offset  += $chunk_size - $overlap;
    }
    if ($offset < $len) {
        error_log("lawsuit-extraction: document truncated at $max_chunks chunks (" . ($len - $offset) . ' chars dropped)');
    }
    return ['chunks' => $chunks, 'length' => $len];
}

/** Pick the Groq model for a chunk call. */
function kop_lawsuit_pick_model(int $total_chunks): string {
    // Groq retires models out from under us (llama-3.3-70b-versatile went 404
    // Sep 2026, llama-3.1-8b-instant days later), so hardcode only one default
    // and let .env override: GROQ_MODEL for everything, GROQ_MODEL_CHUNK to use
    // a cheaper model for multi-chunk documents.
    $default = getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b';
    if ($total_chunks > 1) {
        return getenv('GROQ_MODEL_CHUNK') ?: $default;
    }
    return $default;
}

// =============================================================================
// Groq API helper
// =============================================================================
function kop_groq_call(string $key, string $model, string $text, int $chunk_num = 1, int $total = 1): array {
    $prompt  = kop_lawsuit_extraction_prompt();
    $context = ($total > 1)
        ? "NOTE: This is chunk {$chunk_num} of {$total} of a longer document. Extract whatever fields you can find in this portion.\n\n"
        : '';

    $body = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'user', 'content' => "{$context}DOCUMENT TEXT:\n{$text}\n\n{$prompt}"],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 4096,
    ]);

    if ($body === false) {
        return ['ok' => false, 'data' => null, 'error' => 'json_encode failed: ' . json_last_error_msg()];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'data' => null, 'error' => "Network error: {$curl_err}"];
    }

    $decoded = json_decode($response, true);

    if ($http_code !== 200) {
        $msg = $decoded['error']['message'] ?? "HTTP {$http_code}";
        if ($http_code === 429) $msg = 'Groq rate limit — please wait a moment and retry.';
        if ($http_code === 401) $msg = 'Groq API key is invalid. Check GROQ_API_KEY in .env.';
        return ['ok' => false, 'data' => null, 'error' => "Groq error: {$msg}"];
    }

    $raw_text = $decoded['choices'][0]['message']['content'] ?? '';
    if ($raw_text === '') {
        return ['ok' => false, 'data' => null, 'error' => 'Groq returned an empty response.'];
    }

    $raw_text = preg_replace('/^```json\s*/i', '', trim($raw_text));
    $raw_text = preg_replace('/```\s*$/', '', $raw_text);

    $parsed = json_decode($raw_text, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'data' => null, 'error' => 'Could not parse Groq JSON. Preview: ' . substr($raw_text, 0, 200)];
    }

    return ['ok' => true, 'data' => $parsed, 'error' => ''];
}


// =============================================================================
// Merge + normalize helpers
// =============================================================================
function kop_merge_chunk_extractions(array $chunks): array {
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'outcome', 'settlement_amount', 'summary'];
    $array_fields  = ['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls'];

    $merged = [];
    foreach ($string_fields as $f) {
        foreach ($chunks as $chunk) {
            $v = trim((string)($chunk[$f] ?? ''));
            if ($v !== '') { $merged[$f] = $v; break; }
        }
    }
    foreach ($array_fields as $f) {
        $all = [];
        foreach ($chunks as $chunk) {
            $v = $chunk[$f] ?? [];
            if (is_array($v)) {
                foreach ($v as $item) {
                    if (is_string($item) && trim($item) !== '') $all[] = trim($item);
                }
            }
        }
        $merged[$f] = array_values(array_unique($all));
    }
    return $merged;
}

function kop_normalize_lawsuit_extraction(array $raw): array {
    $array_fields  = ['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls'];
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'summary', 'outcome', 'settlement_amount'];

    $out = [];
    foreach ($array_fields as $f) {
        $v = $raw[$f] ?? [];
        if (is_string($v)) {
            $v = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $v))));
        } elseif (is_array($v)) {
            $v = array_values(array_filter(array_map(static fn($x) => is_string($x) ? trim($x) : '', $v), static fn($x) => $x !== ''));
        } else {
            $v = [];
        }
        $out[$f] = $v;
    }
    foreach ($string_fields as $f) {
        $out[$f] = is_string($raw[$f] ?? '') ? trim($raw[$f] ?? '') : '';
    }

    $filing = $raw['filing_date'] ?? '';
    if (is_string($filing) && $filing !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filing)) {
        $ts     = strtotime($filing);
        $filing = $ts ? date('Y-m-d', $ts) : '';
    }
    $out['filing_date'] = is_string($filing) ? $filing : '';

    if ($out['jurisdiction'] !== '') {
        $out['jurisdiction'] = strcasecmp($out['jurisdiction'], 'federal') === 0
            ? 'Federal'
            : ucwords(strtolower($out['jurisdiction']));
    }

    return $out;
}


// =============================================================================
// Text extraction helpers
// =============================================================================
function kop_extract_document_text(string $path, string $mime_type): string {
    if ($mime_type === 'text/plain') return (string)@file_get_contents($path);
    if ($mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') return kop_extract_docx_text($path);
    return kop_extract_pdf_text($path);
}

function kop_extract_pdf_text(string $path): string {
    foreach (['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $bin) {
        $which = trim((string)@shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null'));
        $exe   = ($which !== '') ? $which : $bin;
        if (@is_executable($exe)) {
            $out = @shell_exec(escapeshellarg($exe) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null');
            if ($out !== null && strlen(trim($out)) > 50) return $out;
            break;
        }
    }

    $raw = @file_get_contents($path);
    if (!$raw) return '';

    if (function_exists('gzuncompress')) {
        $raw = preg_replace_callback('/stream\r?\n(.*?)\r?\nendstream/s', static function ($m) {
            $d = @gzuncompress($m[1]);
            return 'stream' . "\n" . ($d !== false ? $d : $m[1]) . "\nendstream";
        }, $raw);
    }

    $text = '';
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $b = $block[1];
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $b, $tj, PREG_SET_ORDER);
            foreach ($tj as $m) $text .= kop_pdf_unescape($m[1]) . ' ';
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $b, $TJ, PREG_SET_ORDER);
            foreach ($TJ as $m) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $m[1], $ss, PREG_SET_ORDER);
                foreach ($ss as $s) $text .= kop_pdf_unescape($s[1]);
                $text .= ' ';
            }
        }
    }
    return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
}

function kop_pdf_unescape(string $s): string {
    return str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',' '], $s);
}

function kop_extract_docx_text(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml = str_replace(['</w:p>', '<w:br/>', '</w:r>'], ["\n", ' ', ' '], $xml);
    return trim(strip_tags($xml));
}


// =============================================================================
// Extraction prompt
// =============================================================================
function kop_lawsuit_extraction_prompt(): string {
    return <<<PROMPT
You are an expert legal-research assistant. Read the attached legal complaint or court filing and extract structured information for a Troubled Teen Industry accountability database.

Apply trauma-sensitive language: factual and neutral, never sensationalist, no graphic injury details, never describe suicide methods.

Return ONLY valid JSON matching this exact schema (omit fields you can't determine):

{
  "case_name": "e.g. Doe v. Provo Canyon School",
  "case_number": "docket number as printed on the filing",
  "court": "full court name as printed, e.g. 'United States District Court for the District of Utah'",
  "jurisdiction": "Federal OR the US state name (e.g. 'Utah'). Federal courts always = Federal.",
  "filing_date": "YYYY-MM-DD",
  "plaintiffs": ["list of named plaintiffs, one per entry"],
  "defendants": ["list of named defendants, one per entry"],
  "facilities_mentioned": ["TTI facilities/programs named in the complaint"],
  "staff_mentioned": ["individual staff, owners, or therapists named"],
  "organizations_mentioned": ["parent companies, referrers, accreditation bodies, etc."],
  "claims": ["one short slug per claim, e.g. 'physical_abuse', 'wrongful_death', 'fraud', 'negligence', 'sexual_abuse', 'civil_rights_violation', 'breach_of_contract'"],
  "summary": "3-5 sentence factual summary of the allegations using neutral language",
  "tags": ["3-6 short keyword tags, e.g. 'wilderness therapy', 'restraint', 'class action'"]
}

Rules:
- Use exactly the field names above.
- Arrays must be JSON arrays of strings, not comma-joined strings.
- Use ISO dates (YYYY-MM-DD). If only month/year is shown, leave filing_date blank.
- If a value cannot be determined, omit the field or use an empty string/array — do NOT guess.
- Do not include legal citations or paragraph numbers in the summary.
- Return ONLY the JSON object, no prose, no markdown fences.
PROMPT;
}
