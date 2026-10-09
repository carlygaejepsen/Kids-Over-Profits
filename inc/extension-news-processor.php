<?php
/**
 * The News Processor inside the "Send to KOP" extension, for signed-in
 * reviewers (browser-extension/send-to-kop/, popup's admin panel):
 *
 *   POST /wp-json/kop/v1/extension/process  {url, text, instructions}
 *        The processor's AI read (api/lib-news-ai.php buildPrompt), Groq and
 *        Gemini in turn (kop_ai_generate_alternating), over the page text the
 *        extension read from the tab, so paywalled and script-drawn articles work.
 *   GET  /wp-json/kop/v1/extension/archive?url=
 *        The newest Wayback Machine copy of the page, if there is one.
 *   GET  /wp-json/kop/v1/extension/news-form
 *        The choices the panel offers (article types, content warnings) and
 *        whether this account may publish at once.
 *
 * Saving goes through /extension/submit with full=1 (inc/source-submissions.php,
 * kop_ext_news_full_fields()); publish=1 files it approved, admins only.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The processor's article types and content warnings (js/news-processor.js has the same lists). */
function kop_ext_news_form_choices() {
    return array(
        'types' => array(
            array('value' => 'general', 'label' => 'General news'),
            array('value' => 'lawsuit', 'label' => 'Lawsuit'),
            array('value' => 'event', 'label' => 'Specific event'),
            array('value' => 'expose', 'label' => 'Exposé or survivor account'),
            array('value' => 'arrest', 'label' => 'Staff arrest'),
            array('value' => 'closure', 'label' => 'Facility closure'),
            array('value' => 'corporate', 'label' => 'Corporate change'),
        ),
        'warnings' => array(
            'Physical Restraint', 'Chemical Restraint', 'Seclusion', 'Humiliating Punishments',
            'Child Sexual Abuse', 'Child-on-Child CSA', 'Graphic Descriptions of Assaults or Injuries',
            'Peer Violence', 'Child Death', 'Suicide', 'Self-harm', 'Substance Abuse',
            'Victim Blaming', 'Spiritual Abuse', 'Racism', 'Homophobia', 'Transphobia',
            'Hate Crimes', 'Slurs', 'Autism-Specific Abuse', 'Food Restriction',
            'Unsanitary Conditions', 'Medical Neglect', 'Eating Disorders', 'Conversion Therapy',
            'Forced Labor', 'Involuntary Transport', 'Law Enforcement Abuse',
        ),
        // Per-type details, as the processor's section 4 (json_data keys).
        'details' => array(
            'lawsuit'   => array('plaintiffs' => 'Plaintiffs', 'defendants' => 'Defendants', 'legalRep' => 'Legal representation', 'dateFiled' => 'Date filed', 'jurisdiction' => 'Jurisdiction'),
            'arrest'    => array('staffMemberName' => 'Staff member', 'arrestFacilityName' => 'Facility', 'misconductDates' => 'Dates of misconduct', 'charges' => 'Charges', 'caseStatus' => 'Case status'),
            'closure'   => array('closureFacilityName' => 'Facility', 'closureLocation' => 'Location', 'closureDate' => 'Closure date', 'closureContext' => 'Why it closed'),
            'corporate' => array('corporateFacilityNames' => 'Facilities', 'corporateLocation' => 'Location', 'keyPersonnel' => 'Key people', 'ownership' => 'Ownership'),
        ),
    );
}

function kop_ext_rest_news_form() {
    return array_merge(kop_ext_news_form_choices(), array(
        'can_publish' => current_user_can('manage_options'),
        'user'        => wp_get_current_user()->display_name,
    ));
}

/** Flatten what the AI answered into the panel's field names. */
function kop_ext_news_ai_fields(array $d) {
    $list = function ($v) {
        if (is_string($v)) $v = preg_split('/\s*[\n;]\s*/', $v);
        if (!is_array($v)) return array();
        $out = array();
        foreach ($v as $item) {
            if (is_array($item)) $item = $item['name'] ?? '';
            $item = trim((string) $item);
            if ($item !== '' && strcasecmp($item, 'null') !== 0) $out[] = $item;
        }
        return array_values(array_unique($out));
    };
    $str = function ($v) {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return strcasecmp($v, 'null') === 0 ? '' : $v;
    };
    $choices = kop_ext_news_form_choices();
    $type = strtolower($str($d['articleType'] ?? ''));
    $types = array_column($choices['types'], 'value');
    $out = array(
        'title'            => $str($d['title'] ?? ''),
        'author'           => $str($d['author'] ?? ''),
        'published'        => $str($d['publicationDate'] ?? ''),
        'site_name'        => $str($d['publicationName'] ?? ''),
        'location'         => $str($d['location'] ?? ''),
        'tags'             => function_exists('kop_news_tags_normalize') ? kop_news_tags_normalize($list($d['tags'] ?? array())) : $list($d['tags'] ?? array()),
        'facilities'       => $list($d['facilities'] ?? array()),
        'staff'            => $list($d['staff'] ?? array()),
        'survivors'        => $list($d['survivors'] ?? array()),
        'summary'          => $str($d['summary'] ?? ''),
        'alternate_title'  => $str($d['alternateTitle'] ?? ''),
        'content_warnings' => array_values(array_intersect($choices['warnings'], $list($d['contentWarnings'] ?? array()))),
        'article_type'     => in_array($type, $types, true) ? $type : 'general',
        'details'          => array(),
    );
    $specific = is_array($d['typeSpecificData'] ?? null) ? $d['typeSpecificData'] : array();
    foreach ($specific as $group) {
        if (!is_array($group)) continue;
        foreach ($group as $k => $v) {
            $v = $str(is_array($v) ? implode(', ', $v) : $v);
            if ($v !== '') $out['details'][$k] = $v;
        }
    }
    return $out;
}

function kop_ext_rest_news_process(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) $p = array();
    $url  = esc_url_raw(trim((string) ($p['url'] ?? '')));
    $text = trim(wp_strip_all_tags((string) ($p['text'] ?? '')));
    $instructions = sanitize_textarea_field((string) ($p['instructions'] ?? ''));

    $dir = get_stylesheet_directory() . '/api/';
    require_once $dir . 'ai-providers.php';
    require_once $dir . 'news-tags.php';
    require_once $dir . 'lib-news-ai.php';

    if (mb_strlen($text) < 300 && $url !== '') {
        // The tab gave too little (a viewer page, a PDF): read the address as the processor page does.
        require_once $dir . 'lib-article-fetch.php';
        try {
            $fetched = trim((string) fetchArticleContent($url));
            if (mb_strlen($fetched) > mb_strlen($text)) $text = $fetched;
        } catch (Throwable $e) {
            // fall through to the length check
        }
    }
    if (mb_strlen($text) < 300) {
        return new WP_Error('kop_no_text', 'The page has too little text to read (' . mb_strlen($text) . ' characters). Open the article itself, or paste its text into the notes box and try again.', array('status' => 422));
    }
    if (strlen($text) > 20000) $text = mb_strcut($text, 0, 20000, 'UTF-8') . '... [truncated]';

    try {
        $raw = kop_ai_generate_alternating(buildPrompt($text, $url, $instructions), array('maxTokens' => 3000));
        $data = parseAIResponse($raw, 'auto');
    } catch (Throwable $e) {
        error_log('kop extension news AI failed: ' . $e->getMessage());
        $msg = stripos($e->getMessage(), 'rate limit') !== false
            ? 'The AI services are out of calls for now. Try again in a few minutes, or fill the fields by hand.'
            : 'The AI could not read this article. Fill the fields by hand, or try again.';
        return new WP_Error('kop_ai_failed', $msg, array('status' => 502));
    }
    return array('fields' => kop_ext_news_ai_fields($data));
}

function kop_ext_rest_news_archive(WP_REST_Request $req) {
    $url = esc_url_raw(trim((string) $req['url']));
    if ($url === '') return new WP_Error('kop_bad_url', 'No address given.', array('status' => 400));
    require_once get_stylesheet_directory() . '/api/lib-news-archive.php';
    if (kop_news_is_archive_url($url)) {
        return array('archive_url' => $url, 'original' => kop_news_unwrap_archive_url($url), 'timestamp' => '');
    }
    $res = wp_remote_get('https://archive.org/wayback/available?url=' . rawurlencode($url), array(
        'timeout' => 12, 'user-agent' => 'KidsOverProfits/1.0 (+https://kidsoverprofits.org)',
    ));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return new WP_Error('kop_wayback_down', 'The Wayback Machine did not answer. Try again, or paste an archive link by hand.', array('status' => 502));
    }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    $snap = $body['archived_snapshots']['closest'] ?? null;
    if (!is_array($snap) || empty($snap['available']) || empty($snap['url'])) {
        return array('archive_url' => '', 'timestamp' => '', 'save_url' => 'https://web.archive.org/save/' . $url);
    }
    return array(
        'archive_url' => preg_replace('#^http://#', 'https://', (string) $snap['url']),
        'timestamp'   => (string) ($snap['timestamp'] ?? ''),
        'save_url'    => 'https://web.archive.org/save/' . $url,
    );
}

add_action('rest_api_init', function () {
    $can = function () {
        return current_user_can('edit_posts');
    };
    register_rest_route('kop/v1', '/extension/process', array(
        'methods' => 'POST', 'permission_callback' => $can, 'callback' => 'kop_ext_rest_news_process',
    ));
    register_rest_route('kop/v1', '/extension/archive', array(
        'methods' => 'GET', 'permission_callback' => $can, 'callback' => 'kop_ext_rest_news_archive',
        'args' => array('url' => array('required' => true)),
    ));
    register_rest_route('kop/v1', '/extension/news-form', array(
        'methods' => 'GET', 'permission_callback' => $can, 'callback' => 'kop_ext_rest_news_form',
    ));
});
