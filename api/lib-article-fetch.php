<?php
/**
 * Fetch a news article's text, falling back to the Wayback Machine when the
 * page looks paywalled or blocked. Shared by the AI news processor
 * (api/process-news-ai.php) and the closure scan (api/lib-closure-reports.php).
 */

if (!function_exists('fetchArticleContent')) {
    /**
     * Fetch article content from URL, falling back to archive.org Wayback Machine
     * when the original response looks paywalled or blocked.
     *
     * Fallback triggers on:
     *   - HTTP 401 / 402 / 403 / 451
     *   - Empty body or body < 500 chars after strip
     *   - Body containing common paywall keywords ("subscribe to read", etc.)
     *
     * archive.org's `wayback/available` API returns the closest existing snapshot
     * for a given URL; we fetch that snapshot's HTML and strip it the same way.
     * Returns the longest meaningful text we could find.
     */
    function fetchArticleContent($url) {
        $original = fetchUrlAsText($url);
        $originalText = $original['text'];

        if (looksPaywalled($originalText, $original['httpCode'])) {
            error_log(sprintf(
                "Paywall/blocked content detected for %s (httpCode=%d, textLen=%d) — trying archive.org",
                $url, $original['httpCode'], strlen($originalText)
            ));
            $archived = fetchFromArchiveOrg($url);
            if (!empty($archived) && strlen($archived) > strlen($originalText)) {
                error_log(sprintf("archive.org returned %d chars (vs %d original) for %s", strlen($archived), strlen($originalText), $url));
                return $archived;
            }
        }

        return $originalText;
    }
}

if (!function_exists('fetchUrlAsText')) {
    /**
     * Single-attempt HTTP fetch + HTML-to-text strip. Returns
     *   ['text' => ..., 'httpCode' => int, 'curlError' => string]
     * so the caller can decide whether to retry / try a fallback source.
     */
    function fetchUrlAsText($url) {
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

        if (!$html) {
            return ['text' => '', 'httpCode' => $httpCode, 'curlError' => $curlError];
        }

        // Remove scripts, styles, and other non-content elements
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', "", $html);
        $html = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', "", $html);

        // Strip the Wayback Machine toolbar wrapper if present — it adds nav text
        // that confuses the AI ("PLAYBACK FAILED", "save page", etc.).
        $html = preg_replace('/<div\s+id="wm-ipp[^"]*"[\s\S]*?<\/div>\s*<\/div>\s*<\/div>/i', '', $html);

        // Basic HTML to text conversion
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return ['text' => $text, 'httpCode' => $httpCode, 'curlError' => $curlError];
    }
}

if (!function_exists('looksPaywalled')) {
    /**
     * Heuristic: does the response look like a paywall / access-denied page?
     *
     * Considers HTTP status, body length, and common paywall-prompt language.
     * Tuned conservatively — false-positives just trigger an archive.org lookup,
     * which adds ~1s. False-negatives let paywalled stubs through to the LLM,
     * producing useless extractions.
     */
    function looksPaywalled($text, $httpCode) {
        if (in_array((int)$httpCode, [401, 402, 403, 451], true)) return true;
        if (empty($text) || strlen($text) < 500) return true;

        $lower = strtolower(substr($text, 0, 5000));
        $signals = [
            'subscribe to read',
            'subscribers only',
            'subscriber-only',
            'sign in to continue',
            'sign up to continue',
            'create a free account',
            'create an account to continue',
            'to continue reading',
            'log in to read this story',
            'log in to continue reading',
            'you have reached your monthly limit',
            'this article is for subscribers',
            'become a subscriber',
            'unlock this article',
            'unlock the full story',
            'access the full article',
            'register to read',
            'support local journalism',          // common upsell stub
            'enable javascript to view',
            'please enable javascript'
        ];
        foreach ($signals as $needle) {
            if (strpos($lower, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('fetchFromArchiveOrg')) {
    /**
     * Look up a URL in the archive.org Wayback Machine. Returns the snapshot's
     * stripped text, or '' if no usable snapshot exists.
     *
     * Two-step process:
     *   1. GET https://archive.org/wayback/available?url={url}
     *      → JSON with archived_snapshots.closest.url (the snapshot to fetch)
     *   2. Fetch that snapshot URL via fetchUrlAsText()
     */
    function fetchFromArchiveOrg($url) {
        $apiUrl = 'https://archive.org/wayback/available?url=' . urlencode($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KOP-news-processor/1.0 (+https://kidsoverprofits.org)');
        $json = curl_exec($ch);
        $apiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$json || $apiHttpCode !== 200) {
            error_log("archive.org availability HTTP $apiHttpCode for $url");
            return '';
        }

        $data = json_decode($json, true);
        $snapshot = $data['archived_snapshots']['closest'] ?? null;
        if (!$snapshot || empty($snapshot['url']) || empty($snapshot['available'])) {
            error_log("archive.org has no snapshot for $url");
            return '';
        }

        $snapshotUrl = $snapshot['url'];
        // Prefer the "id_" variant which serves the raw original HTML without the
        // Wayback toolbar wrapper — cleaner extraction.
        $snapshotUrl = preg_replace('#/web/(\d+)/#', '/web/$1id_/', $snapshotUrl, 1);

        $result = fetchUrlAsText($snapshotUrl);
        if ($result['httpCode'] !== 200) {
            error_log("archive.org snapshot HTTP " . $result['httpCode'] . " for $snapshotUrl");
            return '';
        }

        return $result['text'];
    }
}
