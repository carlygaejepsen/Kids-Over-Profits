<?php
/**
 * AI News Article Processor API
 *
 * Endpoint to process news articles using AI (Ollama, Groq, Gemini, etc.)
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

// Load config.php which handles .env and wp-config loading
define('SKIP_DB_CONNECTION', true);
require_once __DIR__ . '/config.php';

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
$customInstructions = $data['customInstructions'] ?? '';

if (empty($url) && empty($articleText)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Either URL or article text is required']);
    exit;
}

try {
    // Load API keys from environment variables (set via .env file loaded in config.php)
    $apiKeys = [
        'claude' => getenv('ANTHROPIC_API_KEY'),
        'groq' => getenv('GROQ_API_KEY') ?: getenv('GROK_API_KEY'),
        'gemini' => getenv('GEMINI_API_KEY'),
        'huggingface' => getenv('HUGGINGFACE_API_KEY')
    ];

    // Fetch article content if URL is provided
    $content = $articleText;
    if (empty($content) && !empty($url)) {
        $content = fetchArticleContent($url);
        if (empty($content)) {
            throw new Exception('Could not fetch article content from URL');
        }
    }

    // Truncate content to ~20,000 characters to avoid token limits (approx 5k tokens)
    if (strlen($content) > 20000) {
        $content = substr($content, 0, 20000) . "... [truncated]";
    }

    // Process with selected AI provider
    $result = null;
    switch ($provider) {
        case 'ollama':
            $result = processWithOllama($content, $url, $customInstructions);
            break;
        case 'groq':
            if (empty($apiKeys['groq'])) {
                throw new Exception('Groq API key not configured. Add GROQ_API_KEY to your .env file');
            }
            $result = processWithGroq($apiKeys['groq'], $content, $url, $customInstructions);
            break;
        case 'gemini':
            if (empty($apiKeys['gemini'])) {
                throw new Exception('Gemini API key not configured. Add GEMINI_API_KEY to your .env file');
            }
            $result = processWithGemini($apiKeys['gemini'], $content, $url, $customInstructions);
            break;
        case 'huggingface':
            if (empty($apiKeys['huggingface'])) {
                throw new Exception('Hugging Face API key not configured. Add HUGGINGFACE_API_KEY to your .env file');
            }
            $result = processWithHuggingFace($apiKeys['huggingface'], $content, $url, $customInstructions);
            break;
        case 'claude':
            if (empty($apiKeys['claude'])) {
                throw new Exception('Claude API key not configured. Add ANTHROPIC_API_KEY to your .env file');
            }
            $result = processWithClaude($apiKeys['claude'], $content, $url, $customInstructions);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("CURL Error fetching $url: $curlError");
    }

    if ($httpCode !== 200) {
        error_log("HTTP Error fetching $url: Status Code $httpCode");
        return '';
    }

    if (!$html) {
        error_log("Empty response body from $url");
        return '';
    }

    // Remove scripts, styles, and other non-content elements
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
    $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', "", $html);
    $html = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', "", $html);

    // Basic HTML to text conversion
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if (empty($text)) {
        error_log("Content empty after stripping tags for $url. HTML length: " . strlen($html));
    }

    return $text;
}

/**
 * Process article with Claude AI
 */
function processWithClaude($apiKey, $content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
function processWithOllama($content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

    $requestData = [
        'model' => 'llama3.2', // or 'mistral', 'gemma2' etc.
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:11434/api/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
function processWithGroq($apiKey, $content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
function processWithGemini($apiKey, $content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

    $requestData = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init();
    // Use gemini-2.0-flash-lite (free tier, fast)
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=$apiKey");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Gemini connection error: $curlError");
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "API request failed (HTTP $httpCode)";
        error_log("Gemini API error response: " . substr($response, 0, 500));
        throw new Exception("Gemini API error: $errorMsg");
    }

    $responseData = json_decode($response, true);

    // Check for blocked content
    if (isset($responseData['promptFeedback']['blockReason'])) {
        throw new Exception("Gemini blocked the request: " . $responseData['promptFeedback']['blockReason']);
    }

    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Gemini unexpected response: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Gemini - no text in response');
    }

    return parseAIResponse($responseData['candidates'][0]['content']['parts'][0]['text']);
}

/**
 * Process article with Hugging Face (Free Tier)
 */
function processWithHuggingFace($apiKey, $content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

    // Use the Inference API with a text-generation model
    $requestData = [
        'inputs' => $prompt,
        'parameters' => [
            'max_new_tokens' => 4096,
            'temperature' => 0.1,
            'return_full_text' => false
        ]
    ];

    $ch = curl_init();
    // Use the Inference API endpoint with Mistral (more reliable for structured output)
    curl_setopt($ch, CURLOPT_URL, 'https://api-inference.huggingface.co/models/mistralai/Mistral-7B-Instruct-v0.3');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Hugging Face connection error: $curlError");
    }

    // Handle 503 (model loading)
    if ($httpCode === 503) {
        $errorData = json_decode($response, true);
        $estimatedTime = $errorData['estimated_time'] ?? 20;
        throw new Exception("Hugging Face model is loading. Please try again in about {$estimatedTime} seconds.");
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error'] ?? "API request failed (HTTP $httpCode)";
        error_log("Hugging Face API error response: " . substr($response, 0, 500));
        throw new Exception("Hugging Face API error: $errorMsg");
    }

    $responseData = json_decode($response, true);

    // The inference API returns an array with generated_text
    if (isset($responseData[0]['generated_text'])) {
        return parseAIResponse($responseData[0]['generated_text']);
    }

    // Fallback for different response formats
    if (is_array($responseData) && isset($responseData['generated_text'])) {
        return parseAIResponse($responseData['generated_text']);
    }

    error_log("Hugging Face unexpected response: " . substr($response, 0, 500));
    throw new Exception('Invalid response from Hugging Face - unexpected format');
}

/**
 * Build prompt for all AI providers
 */
function buildPrompt($content, $url = '', $customInstructions = '') {
    $prompt = "You are an expert at processing news articles about the Troubled Teen Industry with trauma-sensitive protocols.\n\n";

    $prompt .= "TRAUMA-SENSITIVE STYLE GUIDE (MANDATORY):\n";
    $prompt .= "1. NEUTRALITY: Use strictly factual, journalistic language. Avoid sensationalist adjectives (e.g., 'horror', 'nightmare', 'shocking') unless directly quoting.\n";
    $prompt .= "2. NO VICTIM BLAMING: Never imply the child's behavior justified the abuse. Focus on the facility's actions and policies.\n";
    $prompt .= "3. SURVIVOR-CENTERED: Use 'survivor' for living individuals. Use 'victim' only for those who died or in legal contexts where it is the technical term.\n";
    $prompt .= "4. GRAPHIC CONTENT: Do not minimize abuse, but do not gratuitously describe graphic details. Focus on the *types* of abuse (e.g., 'physical restraint', 'sexual misconduct') rather than anatomical or bloody details.\n";
    $prompt .= "5. SUICIDE REPORTING (CRITICAL): If a suicide is mentioned, state that it was a 'death by suicide'. NEVER describe the specific method, mechanics, or location details of the act. This is a strict safety requirement.\n";
    $prompt .= "6. SUMMARY: The summary must be a dry, factual recounting of the allegations or events. It should read like a legal brief or a database entry, not a tabloid story.\n\n";

    if (!empty($customInstructions)) {
        $prompt .= "ADDITIONAL USER INSTRUCTIONS (CRITICAL - PLEASE FOLLOW):\n$customInstructions\n\n";
    }

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
    $prompt .= "  \"location\": \"City, State (or Country) where the main events took place\",\n";
    $prompt .= "  \"tags\": [\"list of 3-5 keywords/themes e.g. 'Wilderness Therapy', 'Transport', 'Abuse', 'Lawsuit'\"],\n";
    $prompt .= "  \"facilities\": [\"list of facilities/companies mentioned\"],\n";
    $prompt .= "  \"staff\": [\"list of staff/owners mentioned\"],\n";
    $prompt .= "  \"survivors\": [\"list of survivors and victims mentioned (use initials or pseudonyms if provided)\"],\n";
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
