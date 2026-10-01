<?php
/**
 * Shared multi-provider AI text generation.
 *
 * Given a provider name + a prompt, returns the model's raw text output.
 * Keeps provider/transport details (endpoints, auth headers, response shapes)
 * in one place so extraction endpoints only have to write a prompt and parse
 * the result. Mirrors the providers already used by process-news-ai.php.
 *
 * Providers: groq, claude, gemini, huggingface, ollama. The site's own AI
 * work goes through kop_ai_generate_alternating(), which takes turns between
 * Groq and Gemini (both free tiers, so each carries half the load) and falls
 * through to the other when the one whose turn it is fails.
 * API keys come from environment variables (loaded by config.php from .env),
 * or $_ENV/$_SERVER/constants.
 */

if (!function_exists('kop_ai_api_keys')) {

    function kop_ai_secret(string $name): string {
        $v = getenv($name);
        if ($v !== false && $v !== '') return $v;
        if (!empty($_ENV[$name])) return (string) $_ENV[$name];
        if (!empty($_SERVER[$name])) return (string) $_SERVER[$name];
        if (defined($name) && constant($name)) return (string) constant($name);
        return '';
    }

    function kop_ai_api_keys(): array {
        return [
            'claude'      => kop_ai_secret('ANTHROPIC_API_KEY'),
            'groq'        => kop_ai_secret('GROQ_API_KEY') ?: kop_ai_secret('GROK_API_KEY'),
            'gemini'      => kop_ai_secret('GEMINI_API_KEY'),
            'huggingface' => kop_ai_secret('HUGGINGFACE_API_KEY'),
        ];
    }

    /** The alternating providers that have a key, in their fixed order. */
    function kop_ai_alternating_providers(): array {
        $keys = kop_ai_api_keys();
        return array_values(array_filter(['groq', 'gemini'], function ($p) use ($keys) { return !empty($keys[$p]); }));
    }

    /**
     * The next turn number, shared by every caller on the site: a WordPress
     * option where WordPress is loaded, else a counter file in the temp dir
     * (process-news-ai.php runs without WordPress).
     */
    function kop_ai_next_turn(): int {
        if (function_exists('get_option') && function_exists('update_option')) {
            $n = (int) get_option('kop_ai_turn', 0) + 1;
            update_option('kop_ai_turn', $n, false);
            return $n;
        }
        $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'kop-ai-turn';
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return mt_rand();
        }
        flock($fh, LOCK_EX);
        $n = (int) stream_get_contents($fh) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $n);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $n;
    }

    /**
     * Groq and Gemini (those with a key) in the order this call tries them:
     * each call starts with the other one from the call before. $providers
     * narrows or reorders the pair.
     */
    function kop_ai_turn_order(?array $providers = null): array {
        $have = kop_ai_alternating_providers();
        if ($providers !== null) {
            $have = array_values(array_intersect($providers, $have));
        }
        if (count($have) < 2) {
            return $have;
        }
        $shift = kop_ai_next_turn() % count($have);
        return array_merge(array_slice($have, $shift), array_slice($have, 0, $shift));
    }

    /**
     * The gap between back-to-back calls a page should wait, in ms. Taking
     * turns, each provider sees every other call, so the gap one free tier
     * needs ($one_provider_ms) is split between the providers with a key.
     */
    function kop_ai_pace_ms(int $one_provider_ms = 65000): int {
        return (int) ceil($one_provider_ms / max(1, count(kop_ai_alternating_providers())));
    }

    /** True when an error message says the provider is out of calls for now. */
    function kop_ai_is_rate_limit(string $message): bool {
        foreach (['rate limit', 'quota', 'HTTP 429', 'RESOURCE_EXHAUSTED'] as $needle) {
            if (stripos($message, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * Groq, falling back to the smaller model on a rate limit (token limits
     * are per model, so it usually still has room) or when Groq could not
     * produce valid JSON (HTTP 400 "Failed to validate JSON", which a second
     * model usually gets past). $opts['groqModel'] goes first.
     */
    function kop_ai_groq(string $prompt, array $opts = []): string {
        $models = array_values(array_unique(array_filter([
            $opts['groqModel'] ?? null, getenv('GROQ_MODEL') ?: null, 'openai/gpt-oss-120b', 'openai/gpt-oss-20b',
        ])));
        foreach ($models as $i => $model) {
            try {
                return kop_ai_generate('groq', kop_ai_api_keys(), $prompt, ['groqModel' => $model] + $opts);
            } catch (Throwable $e) {
                $retry = stripos($e->getMessage(), 'rate limit') !== false || stripos($e->getMessage(), 'validate JSON') !== false;
                if ($i === count($models) - 1 || !$retry) {
                    throw $e;
                }
            }
        }
        throw new Exception('No Groq model to try');
    }

    /** Gemini, spaced within one process to the free tier's 15 calls a minute. */
    function kop_ai_gemini(string $prompt, array $opts = []): string {
        static $last = 0.0;
        $wait = $last + 4.5 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        $last = microtime(true);
        return kop_ai_generate('gemini', kop_ai_api_keys(), $prompt, $opts);
    }

    /**
     * The site's AI call: Groq and Gemini take turns, and when the one whose
     * turn it is fails, the other gets the same prompt. Throws the errors of
     * every provider tried, joined, so a message still says "rate limit"
     * when one of them was out of calls. $opts as kop_ai_generate, plus
     * 'providers' to narrow the pair. The provider that answered is left in
     * $GLOBALS['kop_ai_last_provider'].
     */
    function kop_ai_generate_alternating(string $prompt, array $opts = []): string {
        $order = kop_ai_turn_order($opts['providers'] ?? null);
        if (!$order) {
            throw new Exception('No AI key configured. Add GROQ_API_KEY or GEMINI_API_KEY to the .env file.');
        }
        $errors = [];
        foreach ($order as $p) {
            try {
                $text = $p === 'gemini' ? kop_ai_gemini($prompt, $opts) : kop_ai_groq($prompt, $opts);
                $GLOBALS['kop_ai_last_provider'] = $p;
                return $text;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new Exception(implode(' | ', $errors));
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
                if ($r['httpCode'] === 429) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Gemini rate limit exceeded (HTTP 429): ' . ($e['error']['message'] ?? 'quota'));
                }
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
