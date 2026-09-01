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
                $r = kop_ai_curl('https://api.anthropic.com/v1/messages', [
                    'model'      => $opts['claudeModel'] ?? 'claude-3-5-sonnet-20241022',
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ], ['Content-Type: application/json', 'x-api-key: ' . $apiKeys['claude'], 'anthropic-version: 2023-06-01']);
                if ($r['httpCode'] !== 200) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Claude API error (HTTP ' . $r['httpCode'] . '): ' . ($e['error']['message'] ?? 'failed'));
                }
                $d = json_decode($r['response'], true);
                if (!isset($d['content'][0]['text'])) throw new Exception('Invalid Claude response.');
                return $d['content'][0]['text'];

            case 'gemini':
                if (empty($apiKeys['gemini'])) {
                    throw new Exception('Gemini API key not configured. Add GEMINI_API_KEY to your .env file.');
                }
                $r = kop_ai_curl(
                    'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKeys['gemini']),
                    [
                        'contents'         => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['temperature' => $temp, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json'],
                    ],
                    ['Content-Type: application/json']
                );
                if ($r['httpCode'] !== 200) {
                    $e = json_decode($r['response'], true);
                    throw new Exception('Gemini API error (HTTP ' . $r['httpCode'] . '): ' . ($e['error']['message'] ?? 'failed'));
                }
                $d = json_decode($r['response'], true);
                $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text === null) throw new Exception('Invalid Gemini response.');
                return $text;

            case 'huggingface':
                if (empty($apiKeys['huggingface'])) {
                    throw new Exception('Hugging Face API key not configured. Add HUGGINGFACE_API_KEY to your .env file.');
                }
                $r = kop_ai_curl(
                    'https://api-inference.huggingface.co/models/' . ($opts['hfModel'] ?? 'mistralai/Mistral-7B-Instruct-v0.3'),
                    ['inputs' => $prompt, 'parameters' => ['max_new_tokens' => $maxTokens, 'temperature' => $temp, 'return_full_text' => false]],
                    ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKeys['huggingface']]
                );
                if ($r['httpCode'] !== 200) throw new Exception('Hugging Face API error (HTTP ' . $r['httpCode'] . ').');
                $d = json_decode($r['response'], true);
                $text = $d[0]['generated_text'] ?? ($d['generated_text'] ?? null);
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
