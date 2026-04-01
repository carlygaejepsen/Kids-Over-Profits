<?php
/**
 * AI News Article Processor API (Enhanced with Comprehensive Error Handling & Retry Logic)
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

// Enable detailed error logging (set to false in production if too verbose)
define('AI_DEBUG_LOGGING', true);

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
                throw new Exception('Groq API key not configured. Add GROQ_API_KEY or GROK_API_KEY to your .env file. Get a free key at: https://console.groq.com/keys');
            }
            $result = processWithGroq($apiKeys['groq'], $content, $url, $customInstructions);
            break;
        case 'gemini':
            if (empty($apiKeys['gemini'])) {
                throw new Exception('Gemini API key not configured. Add GEMINI_API_KEY to your .env file. Get a free key at: https://aistudio.google.com/app/apikey');
            }
            $result = processWithGemini($apiKeys['gemini'], $content, $url, $customInstructions);
            break;
        case 'huggingface':
            if (empty($apiKeys['huggingface'])) {
                throw new Exception('Hugging Face API key not configured. Add HUGGINGFACE_API_KEY to your .env file. Get a free key at: https://huggingface.co/settings/tokens');
            }
            $result = processWithHuggingFace($apiKeys['huggingface'], $content, $url, $customInstructions);
            break;
        case 'claude':
            if (empty($apiKeys['claude'])) {
                throw new Exception('Claude API key not configured. Add ANTHROPIC_API_KEY to your .env file. Get a key at: https://console.anthropic.com/');
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
    error_log("AI processing error [$provider]: " . $e->getMessage());
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
 * Shared CURL execution with comprehensive error handling
 * 
 * @param resource $ch CURL handle
 * @param string $provider Provider name for logging
 * @param int $maxRetries Number of retry attempts
 * @return array ['response' => string, 'httpCode' => int]
 * @throws Exception on failure
 */
function executeCurlRequest($ch, $provider, $maxRetries = 2) {
    $attempt = 0;
    $lastError = null;
    
    while ($attempt < $maxRetries) {
        $attempt++;
        
        if (AI_DEBUG_LOGGING) {
            error_log("[$provider] Attempt $attempt of $maxRetries");
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        // Check for CURL errors first (network issues)
        if ($curlError || $curlErrno !== 0) {
            $lastError = "Connection error: $curlError (errno: $curlErrno)";
            error_log("[$provider] $lastError");
            
            // Retry on transient network errors
            if ($attempt < $maxRetries && isTransientCurlError($curlErrno)) {
                error_log("[$provider] Retrying after transient error...");
                sleep(1); // Brief delay before retry
                continue;
            }
            
            throw new Exception("[$provider] $lastError");
        }
        
        // Check for HTTP errors
        if ($response === false) {
            $lastError = "Empty response from API";
            error_log("[$provider] $lastError (HTTP $httpCode)");
            
            if ($attempt < $maxRetries) {
                error_log("[$provider] Retrying after empty response...");
                sleep(1);
                continue;
            }
            
            throw new Exception("[$provider] $lastError");
        }
        
        // Log response for debugging
        if (AI_DEBUG_LOGGING) {
            error_log("[$provider] HTTP $httpCode - Response length: " . strlen($response));
            if ($httpCode !== 200) {
                error_log("[$provider] Error response: " . substr($response, 0, 500));
            }
        }
        
        // Success - return response and HTTP code
        return [
            'response' => $response,
            'httpCode' => $httpCode
        ];
    }
    
    // All retries exhausted
    throw new Exception("[$provider] Request failed after $maxRetries attempts. Last error: $lastError");
}

/**
 * Check if CURL error is transient and worth retrying
 */
function isTransientCurlError($errno) {
    // See: https://curl.se/libcurl/c/libcurl-errors.html
    $transientErrors = [
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        28, // CURLE_OPERATION_TIMEDOUT
        52, // CURLE_GOT_NOTHING
        56, // CURLE_RECV_ERROR
    ];
    
    return in_array($errno, $transientErrors);
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

    $result = executeCurlRequest($ch, 'Claude');
    curl_close($ch);
    
    $response = $result['response'];
    $httpCode = $result['httpCode'];

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'API request failed';
        throw new Exception("Claude API error (HTTP $httpCode): $errorMsg");
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['content'][0]['text'])) {
        error_log("[Claude] Unexpected response structure: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Claude API - missing content.text');
    }

    $aiResponse = $responseData['content'][0]['text'];

    return parseAIResponse($aiResponse, 'Claude');
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

    try {
        $result = executeCurlRequest($ch, 'Ollama', 1); // No retry for Ollama (local service)
        curl_close($ch);
        
        $response = $result['response'];
        $httpCode = $result['httpCode'];
    } catch (Exception $e) {
        curl_close($ch);
        
        // Provide helpful error message for Ollama connection failures
        if (strpos($e->getMessage(), 'Connection error') !== false) {
            throw new Exception('Ollama is not running or not accessible at http://127.0.0.1:11434. Please:\n1. Install Ollama from https://ollama.com/download\n2. Start Ollama (run "ollama serve" in terminal)\n3. Install a model (run "ollama pull llama3.2")');
        }
        
        throw $e;
    }

    if ($httpCode !== 200) {
        error_log("[Ollama] HTTP $httpCode response: " . substr($response, 0, 500));
        throw new Exception("Ollama error (HTTP $httpCode). Is Ollama running with a model installed?");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['response'])) {
        error_log("[Ollama] Unexpected response structure: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Ollama - missing "response" field');
    }

    return parseAIResponse($responseData['response'], 'Ollama');
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

    $result = executeCurlRequest($ch, 'Groq');
    curl_close($ch);
    
    $response = $result['response'];
    $httpCode = $result['httpCode'];

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'API request failed';
        
        // Check for common Groq errors
        if ($httpCode === 401) {
            throw new Exception("Groq API key is invalid or expired. Please check your GROQ_API_KEY in .env file. Get a new key at: https://console.groq.com/keys");
        } elseif ($httpCode === 429) {
            throw new Exception("Groq rate limit exceeded. Please wait a moment and try again.");
        }
        
        throw new Exception("Groq API error (HTTP $httpCode): $errorMsg");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        error_log("[Groq] Unexpected response structure: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Groq - missing choices.message.content');
    }

    return parseAIResponse($responseData['choices'][0]['message']['content'], 'Groq');
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
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ]
    ];

    $ch = curl_init();
    // Use gemini-2.0-flash-lite (free tier, fast, generous quota)
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=$apiKey");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $result = executeCurlRequest($ch, 'Gemini');
    curl_close($ch);
    
    $response = $result['response'];
    $httpCode = $result['httpCode'];

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "API request failed";
        
        // Check for common Gemini errors
        if ($httpCode === 400 && strpos($errorMsg, 'API_KEY_INVALID') !== false) {
            throw new Exception("Gemini API key is invalid. Please check your GEMINI_API_KEY in .env file. Get a new key at: https://aistudio.google.com/app/apikey");
        }
        
        error_log("[Gemini] HTTP $httpCode error response: " . substr($response, 0, 500));
        throw new Exception("Gemini API error (HTTP $httpCode): $errorMsg");
    }

    $responseData = json_decode($response, true);

    // Check for blocked content (TTI articles may trigger safety filters)
    if (isset($responseData['promptFeedback']['blockReason'])) {
        $blockReason = $responseData['promptFeedback']['blockReason'];
        error_log("[Gemini] Content blocked: $blockReason. Feedback: " . json_encode($responseData['promptFeedback']));
        throw new Exception("Gemini blocked the request due to content safety: $blockReason. This article may contain sensitive content that Gemini won't process. Try a different AI provider.");
    }

    // Check for content filtering on response
    if (isset($responseData['candidates'][0]['finishReason']) && 
        $responseData['candidates'][0]['finishReason'] === 'SAFETY') {
        error_log("[Gemini] Response blocked by safety filter: " . json_encode($responseData['candidates'][0]));
        throw new Exception("Gemini blocked the response due to content safety filters. This article may contain sensitive content. Try a different AI provider.");
    }

    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("[Gemini] Unexpected response structure: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Gemini - no text in response. The content may have been filtered.');
    }

    return parseAIResponse($responseData['candidates'][0]['content']['parts'][0]['text'], 'Gemini');
}

/**
 * Process article with Hugging Face (Free Tier)
 */
function processWithHuggingFace($apiKey, $content, $url = '', $customInstructions = '') {
    $prompt = buildPrompt($content, $url, $customInstructions);

    // Use the chat completions API format (current HF Inference API standard)
    $requestData = [
        'model' => 'mistralai/Mistral-7B-Instruct-v0.3',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 4096,
        'temperature' => 0.1
    ];

    $ch = curl_init();
    // Use the HF Inference API chat completions endpoint
    curl_setopt($ch, CURLOPT_URL, 'https://router.huggingface.co/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $result = executeCurlRequest($ch, 'HuggingFace');
    curl_close($ch);

    $response = $result['response'];
    $httpCode = $result['httpCode'];

    // Handle 503 (model loading/cold start)
    if ($httpCode === 503) {
        $errorData = json_decode($response, true);
        $estimatedTime = $errorData['estimated_time'] ?? 20;
        error_log("[HuggingFace] Model is loading. Estimated time: {$estimatedTime}s");
        throw new Exception("Hugging Face model is loading (cold start). Please try again in about {$estimatedTime} seconds. Tip: Use Groq for faster results.");
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = "API request failed";
        if (isset($errorData['error'])) {
            $errorMsg = is_array($errorData['error']) ? json_encode($errorData['error']) : $errorData['error'];
        } elseif (isset($errorData['message'])) {
            $errorMsg = is_array($errorData['message']) ? json_encode($errorData['message']) : $errorData['message'];
        }

        // Check for common Hugging Face errors
        if ($httpCode === 401) {
            throw new Exception("Hugging Face API key is invalid or expired. Please check your HUGGINGFACE_API_KEY in .env file. Get a new key at: https://huggingface.co/settings/tokens");
        }

        error_log("[HuggingFace] HTTP $httpCode error response: " . substr($response, 0, 500));
        throw new Exception("Hugging Face API error (HTTP $httpCode): $errorMsg");
    }

    $responseData = json_decode($response, true);

    // Chat completions format returns choices[].message.content
    $generatedText = null;

    if (isset($responseData['choices'][0]['message']['content'])) {
        $generatedText = $responseData['choices'][0]['message']['content'];
    } elseif (isset($responseData[0]['generated_text'])) {
        // Fallback for legacy text-generation format
        $generatedText = $responseData[0]['generated_text'];
    } elseif (is_array($responseData) && isset($responseData['generated_text'])) {
        $generatedText = $responseData['generated_text'];
    }

    if ($generatedText === null) {
        error_log("[HuggingFace] Unexpected response structure: " . substr($response, 0, 500));
        throw new Exception('Invalid response from Hugging Face - unexpected format. Response: ' . substr($response, 0, 200));
    }

    return parseAIResponse($generatedText, 'HuggingFace');
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
    $prompt .= "- Return ONLY valid JSON, no additional text or markdown formatting\n";
    $prompt .= "- Do not wrap the JSON in code blocks (```json)\n";

    return $prompt;
}

/**
 * Parse AI response (extract JSON and decode) with improved error handling
 * 
 * @param string $aiResponse Raw AI response
 * @param string $provider Provider name for logging
 * @return array Parsed JSON data
 * @throws Exception on parse failure
 */
function parseAIResponse($aiResponse, $provider) {
    // Log raw response for debugging
    if (AI_DEBUG_LOGGING) {
        error_log("[$provider] Raw response length: " . strlen($aiResponse));
        error_log("[$provider] Response preview: " . substr($aiResponse, 0, 200));
    }
    
    // Remove markdown code fences if present
    $aiResponse = preg_replace('/```json\s*/', '', $aiResponse);
    $aiResponse = preg_replace('/```\s*$/', '', $aiResponse);
    $aiResponse = trim($aiResponse);
    
    // Try to extract JSON from response (in case there's extra text)
    // Use a more conservative regex that looks for complete JSON objects
    if (preg_match('/(\{(?:[^{}]|(?1))*\})/s', $aiResponse, $matches)) {
        $jsonString = $matches[0];
    } else {
        $jsonString = $aiResponse;
    }
    
    $extractedData = json_decode($jsonString, true);
    $jsonError = json_last_error();
    
    if ($extractedData === null || $jsonError !== JSON_ERROR_NONE) {
        $jsonErrorMsg = json_last_error_msg();
        error_log("[$provider] JSON parse error: $jsonErrorMsg");
        error_log("[$provider] Attempted to parse: " . substr($jsonString, 0, 500));
        
        throw new Exception("[$provider] Could not parse AI response as JSON. Error: $jsonErrorMsg. Response preview: " . substr($aiResponse, 0, 200));
    }
    
    // Validate that we got the expected structure
    if (!isset($extractedData['title']) && !isset($extractedData['summary'])) {
        error_log("[$provider] Parsed JSON but missing expected fields: " . json_encode(array_keys($extractedData)));
        throw new Exception("[$provider] AI returned JSON but it's missing expected fields (title, summary, etc.). The AI may not have understood the prompt.");
    }
    
    if (AI_DEBUG_LOGGING) {
        error_log("[$provider] Successfully parsed JSON with " . count($extractedData) . " fields");
    }
    
    return $extractedData;
}
