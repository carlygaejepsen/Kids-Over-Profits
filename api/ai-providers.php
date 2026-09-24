<?php
/**
 * Shared multi-provider AI text generation.
 *
 * Given a provider name + a prompt, returns the model's raw text output.
 * Keeps provider/transport details (endpoints, auth headers, response shapes)
 * in one place so extraction endpoints only have to write a prompt and parse
 * the result. Mirrors the providers already used by process-news-ai.php.
 *
 * Providers: groq (default), claude, gemini, huggingface, ollama.
 * API keys come from environment variables (loaded by config.php from .env).
 */

if (!function_exists('kop_ai_api_keys')) {

    function kop_ai_api_keys(): array {
        return [
            'claude'      => getenv('ANTHROPIC_API_KEY'),
            'groq'        => getenv('GROQ_API_KEY') ?: getenv('GROK_API_KEY'),
            'gemini'      => getenv('GEMINI_API_KEY'),
            'huggingface' => getenv('HUGGINGFACE_API_KEY'),
        ];
    }

    function kop_ai_curl(string $url, array $payload, array $headers, int $timeout = 90): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new Exception('Connection error: ' . $err);
        }
        return ['response' => $response, 'httpCode' => $httpCode];
    }

    /**
     * Generate text from a provider for a prompt. $opts may set: maxTokens,
     * temperature, and per-provider model overrides (groqModel, claudeModel, …).
     */
    function kop_ai_generate(string $provider, array $apiKeys, string $prompt, array $opts = []): string {
        $maxTokens = $opts['maxTokens'] ?? 4096;
        $temp = $opts['temperature'] ?? 0.1;

        switch ($provider) {
            case 'groq':
                if (empty($apiKeys['groq'])) {
                    throw new Exception('Groq API key not configured. Add GROQ_API_KEY to your .env file (https://console.groq.com/keys).');
                }
                $r = kop_ai_curl('https://api.groq.com/openai/v1/chat/completions', [
                    // llama-3.3-70b-versatile went 404 for this account Sep 2026;
                    // GROQ_MODEL in .env overrides for future retirements.
                    'model'           => $opts['groqModel'] ?? (getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b'),
                    'messages'        => [['role' => 'user', 'content' => $prompt]],
                    'temperature'     => $temp,
                    'max_tokens'      => $maxTokens,
                    'response_format' => ['type' => 'json_object'],
                ], ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKeys['groq']]);
                if ($r['httpCode'] === 401) throw new Exception('Groq API key is invalid or expired.');
                if ($r['httpCode'] === 429) throw new Exception('Groq rate limit exceeded. Wait a moment and retry.');
                if ($r['httpCode'] !== 200) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Groq API error (HTTP ' . $r['httpCode'] . '): ' . ($e['error']['message'] ?? 'failed'));
                }
                $d = json_decode($r['response'], true);
                if (!isset($d['choices'][0]['message']['content'])) throw new Exception('Invalid Groq response.');
                return $d['choices'][0]['message']['content'];

            case 'claude':
                if (empty($apiKeys['claude'])) {
                    throw new Exception('Claude API key not configured. Add ANTHROPIC_API_KEY to your .env file (https://console.anthropic.com/).');
                }
                // Claude Opus 5 thinks by default (thinking counts against
                // max_tokens, hence the floor) and rejects temperature.
                // fallbacks "default" re-runs a request the safety
                // classifier declines on the model Anthropic recommends.
                $r = kop_ai_curl('https://api.anthropic.com/v1/messages', [
                    'model'         => $opts['claudeModel'] ?? (getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5'),
                    'max_tokens'    => max($maxTokens, 16000),
                    'output_config' => ['effort' => 'low'],
                    'fallbacks'     => 'default',
                    'messages'      => [['role' => 'user', 'content' => $prompt]],
                ], ['Content-Type: application/json', 'x-api-key: ' . $apiKeys['claude'], 'anthropic-version: 2023-06-01', 'anthropic-beta: server-side-fallback-2026-07-01'], 120);
                if ($r['httpCode'] !== 200) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Claude API error (HTTP ' . $r['httpCode'] . '): ' . ($e['error']['message'] ?? 'failed'));
                }
                $d = json_decode($r['response'], true);
                if (($d['stop_reason'] ?? '') === 'refusal') {
                    throw new Exception('Claude declined this request (refusal: ' . ($d['stop_details']['category'] ?? 'unspecified') . '). Try a different provider.');
                }
                $text = '';
                foreach ($d['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
                }
                if ($text === '') throw new Exception('Invalid Claude response (stop_reason: ' . ($d['stop_reason'] ?? 'none') . ').');
                return $text;

            case 'gemini':
                if (empty($apiKeys['gemini'])) {
                    throw new Exception('Gemini API key not configured. Add GEMINI_API_KEY to your .env file.');
                }
                // gemini-1.5-flash is long retired. Gemini 3 models are tuned
                // for the default temperature, and their thinking counts
                // against maxOutputTokens.
                $geminiModel = $opts['geminiModel'] ?? (getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite');
                $r = kop_ai_curl(
                    'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($geminiModel) . ':generateContent?key=' . urlencode($apiKeys['gemini']),
                    [
                        'contents'         => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['maxOutputTokens' => max($maxTokens, 16384), 'responseMimeType' => 'application/json'],
                    ],
                    ['Content-Type: application/json']
                );
                if ($r['httpCode'] !== 200) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Gemini API error (HTTP ' . $r['httpCode'] . '): ' . ($e['error']['message'] ?? 'failed'));
                }
                $d = json_decode($r['response'], true);
                $text = '';
                foreach ($d['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (isset($part['text']) && empty($part['thought'])) $text .= $part['text'];
                }
                if ($text === '') throw new Exception('Invalid Gemini response.');
                return $text;

            case 'huggingface':
                if (empty($apiKeys['huggingface'])) {
                    throw new Exception('Hugging Face API key not configured. Add HUGGINGFACE_API_KEY to your .env file.');
                }
                // The old api-inference.huggingface.co endpoint is gone; the
                // router's chat completions API is what process-news-ai.php uses.
                $r = kop_ai_curl(
                    'https://router.huggingface.co/v1/chat/completions',
                    [
                        'model'       => $opts['hfModel'] ?? 'meta-llama/Llama-3.1-8B-Instruct:fastest',
                        'messages'    => [['role' => 'user', 'content' => $prompt]],
                        'max_tokens'  => $maxTokens,
                        'temperature' => $temp,
                    ],
                    ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKeys['huggingface']]
                );
                if ($r['httpCode'] !== 200) throw new Exception('Hugging Face API error (HTTP ' . $r['httpCode'] . ').');
                $d = json_decode($r['response'], true);
                $text = $d['choices'][0]['message']['content'] ?? null;
                if ($text === null) throw new Exception('Invalid Hugging Face response.');
                return $text;

            case 'ollama':
                $r = kop_ai_curl('http://127.0.0.1:11434/api/generate', [
                    'model'  => $opts['ollamaModel'] ?? 'llama3.2',
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json',
                ], ['Content-Type: application/json'], 120);
                if ($r['httpCode'] !== 200) throw new Exception('Ollama error (HTTP ' . $r['httpCode'] . '). Is Ollama running with a model installed?');
                $d = json_decode($r['response'], true);
                if (!isset($d['response'])) throw new Exception('Invalid Ollama response.');
                return $d['response'];

            default:
                throw new Exception('Invalid AI provider selected.');
        }
    }

    /**
     * Extract a JSON object from an AI text response, tolerating code fences and
     * surrounding prose. Returns null if no valid JSON object is found.
     */
    function kop_ai_extract_json(string $text): ?array {
        $t = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $t, $m)) {
            $t = trim($m[1]);
        }
        $decoded = json_decode($t, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($t, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
