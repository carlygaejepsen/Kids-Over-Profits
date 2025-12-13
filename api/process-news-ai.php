<?php
/**
 * Claude AI News Article Processor API
 *
 * Endpoint to process news articles using Claude AI
 *
 * POST /api/process-news-ai.php
 * Body: JSON with url or articleText
 *
 * Returns: JSON with extracted article data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load .env file if it exists
function loadEnvFile($path) {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = trim($value, '"\'');

            // Set as environment variable
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Load .env from project root
loadEnvFile(dirname(__DIR__, 2) . '/.env');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$url = $data['url'] ?? '';
$articleText = $data['articleText'] ?? '';
$provider = $data['provider'] ?? 'ollama';

if (empty($url) && empty($articleText)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Either URL or article text is required']);
    exit;
}

try {
    // Load API keys from config if available
    $apiKeys = [];
    $configFile = __DIR__ . '/ai-config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
        // Support multiple API key constants
        if (defined('ANTHROPIC_API_KEY')) $apiKeys['claude'] = ANTHROPIC_API_KEY;
        if (defined('GROQ_API_KEY')) $apiKeys['groq'] = GROQ_API_KEY;
        if (defined('GEMINI_API_KEY')) $apiKeys['gemini'] = GEMINI_API_KEY;
        if (defined('HUGGINGFACE_API_KEY')) $apiKeys['huggingface'] = HUGGINGFACE_API_KEY;
    }

    // Check environment variables as fallback
    if (empty($apiKeys['claude'])) $apiKeys['claude'] = getenv('ANTHROPIC_API_KEY');
    if (empty($apiKeys['groq'])) $apiKeys['groq'] = getenv('GROQ_API_KEY');
    if (empty($apiKeys['gemini'])) $apiKeys['gemini'] = getenv('GEMINI_API_KEY');
    if (empty($apiKeys['huggingface'])) $apiKeys['huggingface'] = getenv('HUGGINGFACE_API_KEY');

    // Fetch article content if URL is provided
    $content = $articleText;
    if (empty($content) && !empty($url)) {
        $content = fetchArticleContent($url);
        if (empty($content)) {
            throw new Exception('Could not fetch article content from URL');
        }
    }

    // Process with selected AI provider
    $result = null;
    switch ($provider) {
        case 'ollama':
            $result = processWithOllama($content, $url);
            break;
        case 'groq':
            if (empty($apiKeys['groq'])) {
                throw new Exception('Groq API key not configured. Add GROQ_API_KEY to api/ai-config.php');
            }
            $result = processWithGroq($apiKeys['groq'], $content, $url);
            break;
        case 'gemini':
            if (empty($apiKeys['gemini'])) {
                throw new Exception('Gemini API key not configured. Add GEMINI_API_KEY to api/ai-config.php');
            }
            $result = processWithGemini($apiKeys['gemini'], $content, $url);
            break;
        case 'huggingface':
            if (empty($apiKeys['huggingface'])) {
                throw new Exception('Hugging Face API key not configured. Add HUGGINGFACE_API_KEY to api/ai-config.php');
            }
            $result = processWithHuggingFace($apiKeys['huggingface'], $content, $url);
            break;
        case 'claude':
            if (empty($apiKeys['claude'])) {
                throw new Exception('Claude API key not configured. Add ANTHROPIC_API_KEY to api/ai-config.php');
            }
            $result = processWithClaude($apiKeys['claude'], $content, $url);
            break;
        default:
            throw new Exception('Invalid AI provider selected');
    }

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    error_log("AI processing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Fetch article content from URL
 */
function fetchArticleContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$html) {
        return '';
    }

    // Basic HTML to text conversion
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    return $text;
}

/**
 * Process article with Claude AI
 */
function processWithClaude($apiKey, $content, $url = '') {
    $prompt = "You are an expert at processing news articles about the Troubled Teen Industry with trauma-sensitive protocols.\n\n";

    if (!empty($url)) {
        $prompt .= "Article URL: $url\n\n";
    }

    $prompt .= "Article Content:\n$content\n\n";
    $prompt .= "Please analyze this article and extract the following information in JSON format:\n\n";
    $prompt .= "{\n";
    $prompt .= "  \"title\": \"article title\",\n";
    $prompt .= "  \"author\": \"author name\",\n";
    $prompt .= "  \"publicationDate\": \"YYYY-MM-DD format\",\n";
    $prompt .= "  \"publicationName\": \"publication name\",\n";
    $prompt .= "  \"facilities\": [\"list of facilities/companies mentioned\"],\n";
    $prompt .= "  \"staff\": [\"list of staff/owners mentioned\"],\n";
    $prompt .= "  \"survivors\": [\"list of survivors mentioned (use initials or pseudonyms if provided)\"],\n";
    $prompt .= "  \"summary\": \"2-3 sentence trauma-sensitive summary using factual, neutral language\",\n";
    $prompt .= "  \"alternateTitle\": \"alternate title if original is sensationalist (or null)\",\n";
    $prompt .= "  \"contentWarnings\": [\"relevant warnings from: Physical Restraint, Chemical Restraint, Seclusion, Humiliating Punishments, Child Sexual Abuse, Child-on-Child CSA, Graphic Descriptions of Assaults or Injuries, Peer Violence, Child Death, Suicide, Self-harm, Substance Abuse, Victim Blaming, Spiritual Abuse, Racism, Homophobia, Transphobia, Hate Crimes, Slurs, Autism-Specific Abuse, Food Restriction, Unsanitary Conditions, Medical Neglect, Eating Disorders, Conversion Therapy, Forced Labor, Involuntary Transport, Law Enforcement Abuse\"],\n";
    $prompt .= "  \"articleType\": \"lawsuit|event|expose|arrest|closure|corporate|general\",\n";
    $prompt .= "  \"typeSpecificData\": {\n";
    $prompt .= "    \"for lawsuit\": {\"plaintiffs\": \"\", \"defendants\": \"\", \"legalRep\": \"\", \"dateFiled\": \"YYYY-MM-DD\", \"jurisdiction\": \"\", \"pressReleases\": \"\"},\n";
    $prompt .= "    \"for arrest\": {\"staffMemberName\": \"\", \"arrestFacilityName\": \"\", \"misconductDates\": \"\", \"charges\": \"\", \"caseStatus\": \"\"},\n";
    $prompt .= "    \"for closure\": {\"closureFacilityName\": \"\", \"closureLocation\": \"\", \"closureDate\": \"YYYY-MM-DD\", \"closureContext\": \"\"},\n";
    $prompt .= "    \"for corporate\": {\"corporateFacilityNames\": \"\", \"corporateLocation\": \"\", \"keyPersonnel\": \"\", \"ownership\": \"\"}\n";
    $prompt .= "  }\n";
    $prompt .= "}\n\n";
    $prompt .= "IMPORTANT:\n";
    $prompt .= "- Use trauma-sensitive language in the summary\n";
    $prompt .= "- Only include content warnings that are clearly present in the article\n";
    $prompt .= "- Detect the article type based on the content\n";
    $prompt .= "- Only fill typeSpecificData for the detected article type\n";
    $prompt .= "- Return ONLY valid JSON, no additional text\n";

    $requestData = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 4096,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'API request failed';
        throw new Exception("Claude API error: $errorMsg");
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['content'][0]['text'])) {
        throw new Exception('Invalid response from Claude API');
    }

    $aiResponse = $responseData['content'][0]['text'];

    // Extract JSON from response (in case there's extra text)
    if (preg_match('/\{.*\}/s', $aiResponse, $matches)) {
        $aiResponse = $matches[0];
    }

    $extractedData = json_decode($aiResponse, true);

    if (!$extractedData) {
        throw new Exception('Could not parse AI response as JSON');
    }

    return $extractedData;
}

/**
 * Process article with Ollama (Local, Free)
 */
function processWithOllama($content, $url = '') {
    $prompt = buildPrompt($content, $url);

    $requestData = [
        'model' => 'llama3.2', // or 'mistral', 'gemma2' etc.
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Ollama is not running. Please start Ollama (run "ollama serve" in terminal) and install a model (run "ollama pull llama3.2")');
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['response'])) {
        throw new Exception('Invalid response from Ollama');
    }

    return parseAIResponse($responseData['response']);
}

/**
 * Process article with Groq (Free Tier)
 */
function processWithGroq($apiKey, $content, $url = '') {
    $prompt = buildPrompt($content, $url);

    $requestData = [
        'model' => 'llama-3.3-70b-versatile', // Fast and free
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 4096
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'API request failed';
        throw new Exception("Groq API error: $errorMsg");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response from Groq');
    }

    return parseAIResponse($responseData['choices'][0]['message']['content']);
}

/**
 * Process article with Google Gemini (Free Tier)
 */
function processWithGemini($apiKey, $content, $url = '') {
    $prompt = buildPrompt($content, $url);

    $requestData = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 4096
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=$apiKey");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'API request failed';
        throw new Exception("Gemini API error: $errorMsg");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid response from Gemini');
    }

    return parseAIResponse($responseData['candidates'][0]['content']['parts'][0]['text']);
}

/**
 * Process article with Hugging Face (Free Tier)
 */
function processWithHuggingFace($apiKey, $content, $url = '') {
    $prompt = buildPrompt($content, $url);

    $requestData = [
        'inputs' => $prompt,
        'parameters' => [
            'max_new_tokens' => 4096,
            'temperature' => 0.1,
            'return_full_text' => false
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api-inference.huggingface.co/models/meta-llama/Llama-3.2-3B-Instruct');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error'] ?? 'API request failed';
        throw new Exception("Hugging Face API error: $errorMsg");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData[0]['generated_text'])) {
        throw new Exception('Invalid response from Hugging Face');
    }

    return parseAIResponse($responseData[0]['generated_text']);
}

/**
 * Build prompt for all AI providers
 */
function buildPrompt($content, $url = '') {
    $prompt = "You are an expert at processing news articles about the Troubled Teen Industry with trauma-sensitive protocols.\n\n";

    if (!empty($url)) {
        $prompt .= "Article URL: $url\n\n";
    }

    $prompt .= "Article Content:\n$content\n\n";
    $prompt .= "Please analyze this article and extract the following information in JSON format:\n\n";
    $prompt .= "{\n";
    $prompt .= "  \"title\": \"article title\",\n";
    $prompt .= "  \"author\": \"author name\",\n";
    $prompt .= "  \"publicationDate\": \"YYYY-MM-DD format\",\n";
    $prompt .= "  \"publicationName\": \"publication name\",\n";
    $prompt .= "  \"facilities\": [\"list of facilities/companies mentioned\"],\n";
    $prompt .= "  \"staff\": [\"list of staff/owners mentioned\"],\n";
    $prompt .= "  \"survivors\": [\"list of survivors mentioned (use initials or pseudonyms if provided)\"],\n";
    $prompt .= "  \"summary\": \"2-3 sentence trauma-sensitive summary using factual, neutral language\",\n";
    $prompt .= "  \"alternateTitle\": \"alternate title if original is sensationalist (or null)\",\n";
    $prompt .= "  \"contentWarnings\": [\"relevant warnings from: Physical Restraint, Chemical Restraint, Seclusion, Humiliating Punishments, Child Sexual Abuse, Child-on-Child CSA, Graphic Descriptions of Assaults or Injuries, Peer Violence, Child Death, Suicide, Self-harm, Substance Abuse, Victim Blaming, Spiritual Abuse, Racism, Homophobia, Transphobia, Hate Crimes, Slurs, Autism-Specific Abuse, Food Restriction, Unsanitary Conditions, Medical Neglect, Eating Disorders, Conversion Therapy, Forced Labor, Involuntary Transport, Law Enforcement Abuse\"],\n";
    $prompt .= "  \"articleType\": \"lawsuit|event|expose|arrest|closure|corporate|general\",\n";
    $prompt .= "  \"typeSpecificData\": {\n";
    $prompt .= "    \"for lawsuit\": {\"plaintiffs\": \"\", \"defendants\": \"\", \"legalRep\": \"\", \"dateFiled\": \"YYYY-MM-DD\", \"jurisdiction\": \"\", \"pressReleases\": \"\"},\n";
    $prompt .= "    \"for arrest\": {\"staffMemberName\": \"\", \"arrestFacilityName\": \"\", \"misconductDates\": \"\", \"charges\": \"\", \"caseStatus\": \"\"},\n";
    $prompt .= "    \"for closure\": {\"closureFacilityName\": \"\", \"closureLocation\": \"\", \"closureDate\": \"YYYY-MM-DD\", \"closureContext\": \"\"},\n";
    $prompt .= "    \"for corporate\": {\"corporateFacilityNames\": \"\", \"corporateLocation\": \"\", \"keyPersonnel\": \"\", \"ownership\": \"\"}\n";
    $prompt .= "  }\n";
    $prompt .= "}\n\n";
    $prompt .= "IMPORTANT:\n";
    $prompt .= "- Use trauma-sensitive language in the summary\n";
    $prompt .= "- Only include content warnings that are clearly present in the article\n";
    $prompt .= "- Detect the article type based on the content\n";
    $prompt .= "- Only fill typeSpecificData for the detected article type\n";
    $prompt .= "- Return ONLY valid JSON, no additional text\n";

    return $prompt;
}

/**
 * Parse AI response (extract JSON and decode)
 */
function parseAIResponse($aiResponse) {
    // Extract JSON from response (in case there's extra text)
    if (preg_match('/\{.*\}/s', $aiResponse, $matches)) {
        $aiResponse = $matches[0];
    }

    $extractedData = json_decode($aiResponse, true);

    if (!$extractedData) {
        throw new Exception('Could not parse AI response as JSON');
    }

    return $extractedData;
}
