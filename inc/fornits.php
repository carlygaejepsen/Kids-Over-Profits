<?php
/**
 * Fornits: what the old survivor forum (fornits.com/phpbb, 2001 on) says about
 * each facility, added to the records after review.
 *
 * scripts/fornits-crawl.py copies the treatment-abuse boards; hourly,
 * scripts/fornits-process.py ties each new topic to the facilities it is about
 * (the board's own program, one named in the title or opening post, or one
 * named in three or more posts) and copies a batch file of those topics, with
 * their posts, to ~/kop-import/fornits/ (outside the web root; the repository
 * is public and never holds the posts).
 *
 * Here:
 *   - each batch is loaded into {prefix}kop_fornits_topics, and every topic
 *     becomes a "Fornits discussion" link proposal for each of its facilities
 *     (resourceLinks, kind 'social', "Survivor posts and discussion");
 *   - hourly, Groq and Gemini (free tiers, taking turns) read waiting topics
 *     (kop_fornits_read_batch, kop_fornits_ask) and propose
 *     staff (the Woodbury Facts add_staff change), incidents (customIncidents
 *     lines), survivor accounts (survivorTestimony, published, an admin can
 *     untick "OK to publish") and leads (news or court links to their
 *     queues, a reported closure, or a note). Every quote is checked against
 *     the post it cites; one that is not there is kept but never preselected;
 *   - KOP Tools > Fornits shows one card per facility and kind; Add, Skip and
 *     an exact Undo, as Drive Docs and Woodbury Facts do.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_FORNITS_DB_VERSION', '3');

function kop_fornits_topics_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_fornits_topics';
}

function kop_fornits_items_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_fornits_items';
}

function kop_fornits_ensure_tables() {
    if (get_option('kop_fornits_db') === KOP_FORNITS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $topics = kop_fornits_topics_table();
    $items = kop_fornits_items_table();
    dbDelta("CREATE TABLE {$topics} (
        topic_id BIGINT UNSIGNED NOT NULL,
        board_name VARCHAR(120) NOT NULL DEFAULT '',
        title TEXT NULL,
        url VARCHAR(255) NOT NULL DEFAULT '',
        started VARCHAR(20) NOT NULL DEFAULT '',
        last_post VARCHAR(20) NOT NULL DEFAULT '',
        n_posts INT UNSIGNED NOT NULL DEFAULT 0,
        facilities TEXT NULL,
        mentions TEXT NULL,
        prio TINYINT UNSIGNED NOT NULL DEFAULT 1,
        batch VARCHAR(80) NOT NULL DEFAULT '',
        line INT UNSIGNED NOT NULL DEFAULT 0,
        read_status VARCHAR(12) NOT NULL DEFAULT 'pending',
        read_note TEXT NULL,
        summary TEXT NULL,
        headline VARCHAR(200) NOT NULL DEFAULT '',
        categories VARCHAR(255) NOT NULL DEFAULT '',
        importance TINYINT UNSIGNED NOT NULL DEFAULT 0,
        tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (topic_id),
        KEY read_status (read_status, prio)
    ) {$charset};");
    dbDelta("CREATE TABLE {$items} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pkey VARCHAR(32) NOT NULL,
        topic_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(12) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        also TEXT NULL,
        how VARCHAR(120) NOT NULL DEFAULT '',
        label TEXT NULL,
        value LONGTEXT NULL,
        quote TEXT NULL,
        quote_found TINYINT(1) NOT NULL DEFAULT 0,
        context MEDIUMTEXT NULL,
        summary TEXT NULL,
        categories VARCHAR(255) NOT NULL DEFAULT '',
        importance TINYINT UNSIGNED NOT NULL DEFAULT 0,
        post_n INT UNSIGNED NOT NULL DEFAULT 0,
        author VARCHAR(120) NOT NULL DEFAULT '',
        post_date VARCHAR(20) NOT NULL DEFAULT '',
        preselect TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        applied LONGTEXT NULL,
        applied_fid BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pkey (pkey),
        KEY kind_status (kind, status, facility_id),
        KEY topic_id (topic_id)
    ) {$charset};");
    // Topics read before summaries existed are read again for them (their
    // proposals are not repeated: each has a fixed key).
    $wpdb->query("UPDATE {$topics} SET read_status = 'pending', tries = 0 WHERE read_status = 'read' AND summary IS NULL");
    update_option('kop_fornits_db', KOP_FORNITS_DB_VERSION);
}

/** What a thread is, as the reading tags it. */
function kop_fornits_categories() {
    return array(
        'survivor_account'      => 'Survivor account',
        'parent_account'        => 'Parent account',
        'staff_account'         => 'Former staff account',
        'abuse_allegation'      => 'Abuse',
        'sexual_abuse'          => 'Sexual abuse',
        'death'                 => 'Death',
        'restraint_seclusion'   => 'Restraint or seclusion',
        'medical_neglect'       => 'Medical neglect',
        'staff_names'           => 'Staff named',
        'ownership_business'    => 'Owners and business',
        'legal'                 => 'Lawsuit or criminal case',
        'investigation'         => 'Investigation or licensing',
        'closure_rename'        => 'Closure or new name',
        'news_coverage'         => 'News coverage',
        'program_methods'       => 'Program methods',
        'referral_marketing'    => 'Referrals and marketing',
        'parent_seeking_advice' => 'Parent asking for advice',
        'defending_program'     => 'Defending the program',
        'chatter'               => 'Chatter',
    );
}

/** The importance scale the reading uses. */
function kop_fornits_importance_labels() {
    return array(3 => 'Key evidence', 2 => 'Useful', 1 => 'Opinions only', 0 => 'Chatter');
}

/**
 * Put together what the reading said about a topic across its parts:
 * [summary, categories[], about[fid => [says, importance]]]. The summary is
 * the first part's (a long thread's later parts add one more sentence or two),
 * categories are every part's, and for each facility the most important part
 * says what the thread says about it.
 */
function kop_fornits_merge_reads(array $replies, array $allowed) {
    $cats = kop_fornits_categories();
    $summaries = array();
    $categories = array();
    $about = array();
    foreach ($replies as $data) {
        $t = is_array($data['topic'] ?? null) ? $data['topic'] : array();
        $sum = trim(preg_replace('/\s+/', ' ', (string) ($t['summary'] ?? '')));
        if ($sum !== '') {
            $summaries[] = $sum;
        }
        foreach ((array) ($t['categories'] ?? array()) as $c) {
            $c = strtolower(trim((string) $c));
            if (isset($cats[$c]) && !in_array($c, $categories, true)) {
                $categories[] = $c;
            }
        }
        foreach ((array) ($data['about'] ?? array()) as $a) {
            $fid = (int) ($a['facility_id'] ?? 0);
            if (!is_array($a) || !isset($allowed[$fid])) {
                continue;
            }
            $imp = max(0, min(3, (int) ($a['importance'] ?? 0)));
            $says = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($a['says'] ?? ''))), 0, 400);
            if (!isset($about[$fid]) || $imp > $about[$fid][1] || ($about[$fid][0] === '' && $says !== '')) {
                $about[$fid] = array($says !== '' ? $says : ($about[$fid][0] ?? ''), max($imp, $about[$fid][1] ?? 0));
            }
        }
    }
    $summary = $summaries ? $summaries[0] : '';
    if (count($summaries) > 1) {
        $summary .= ' Later: ' . $summaries[1];
    }
    if (count($categories) > 1) {
        $categories = array_values(array_diff($categories, array('chatter')));
    }
    return array(mb_substr($summary, 0, 900), $categories, $about);
}

function kop_fornits_dir() {
    return defined('KOP_FORNITS_IMPORT_DIR') ? rtrim(KOP_FORNITS_IMPORT_DIR, '/') : dirname(rtrim(ABSPATH, '/')) . '/kop-import/fornits';
}

function kop_fornits_pkey($key) {
    return substr(md5((string) $key), 0, 16);
}

/** What the screen calls each kind of proposal. */
function kop_fornits_kinds() {
    return array(
        'link'      => 'Discussions',
        'staff'     => 'Staff',
        'incident'  => 'Incidents',
        'testimony' => 'Survivor accounts',
        'lead'      => 'Leads',
    );
}

/** One post's own address: SMF starts a topic at any post with topic=<id>.<offset>. */
function kop_fornits_post_url($topic_id, $n) {
    return 'https://www.fornits.com/phpbb/index.php?topic=' . (int) $topic_id . '.' . (int) $n;
}

/** "March 2005" from "2005-03-12T10:00:00". */
function kop_fornits_month($date) {
    $t = strtotime((string) $date);
    return $t ? gmdate('F Y', $t) : '';
}

/* ---- Loading the batches ------------------------------------------------- */

/**
 * Load batch files not yet loaded (or the rest of one a run did not finish).
 * Every topic is stored with where its posts are (batch file and line), and
 * each of its facilities gets a link proposal. Topics read before keep their
 * reading; a topic sent again only takes the new facility list.
 */
function kop_fornits_sync($seconds = 20) {
    kop_fornits_ensure_tables();
    $dir = kop_fornits_dir();
    $files = glob($dir . '/batch-*.jsonl.gz') ?: array();
    sort($files);
    $loaded = get_option('kop_fornits_loaded', array());
    $loaded = is_array($loaded) ? $loaded : array();
    $started = time();
    $counts = array('topics' => 0, 'links' => 0);
    global $wpdb;
    $topics = kop_fornits_topics_table();
    $items = kop_fornits_items_table();
    $now = current_time('mysql', true);
    foreach ($files as $path) {
        $name = basename($path);
        $done = (int) ($loaded[$name] ?? 0);
        if ($done < 0) {
            continue; // finished
        }
        $fh = @gzopen($path, 'rb');
        if (!$fh) {
            continue;
        }
        $line = 0;
        $complete = true;
        while (($raw = gzgets($fh)) !== false) {
            $line++;
            if ($line <= $done) {
                continue;
            }
            if (time() - $started > $seconds) {
                $complete = false;
                $line--;
                break;
            }
            $t = json_decode($raw, true);
            if (!is_array($t) || empty($t['topic'])) {
                continue;
            }
            $tid = (int) $t['topic'];
            $facs = array_values(array_filter((array) ($t['facilities'] ?? array()), 'is_array'));
            $prio = 1;
            foreach ($facs as $f) {
                if (array_intersect((array) ($f['how'] ?? array()), array('board', 'title'))) {
                    $prio = 0;
                }
            }
            $row = array(
                'board_name' => mb_substr((string) ($t['board_name'] ?? ''), 0, 120),
                'title'      => (string) ($t['title'] ?? ''),
                'url'        => mb_substr((string) ($t['url'] ?? ''), 0, 255),
                'started'    => mb_substr((string) ($t['started'] ?? ''), 0, 20),
                'last_post'  => mb_substr((string) ($t['last'] ?? ''), 0, 20),
                'n_posts'    => count((array) ($t['posts'] ?? array())),
                'facilities' => wp_json_encode($facs),
                'mentions'   => wp_json_encode(array_values((array) ($t['mentions'] ?? array()))),
                'prio'       => $prio,
                'batch'      => $name,
                'line'       => $line,
            );
            $exists = $wpdb->get_var($wpdb->prepare("SELECT read_status FROM {$topics} WHERE topic_id = %d", $tid));
            if ($exists === null) {
                $row['topic_id'] = $tid;
                $row['created_at'] = $now;
                $wpdb->insert($topics, $row);
            } else {
                $wpdb->update($topics, $row, array('topic_id' => $tid));
            }
            $counts['topics']++;
            foreach ($facs as $f) {
                $counts['links'] += kop_fornits_add_link_item($t, $f, $now);
            }
        }
        gzclose($fh);
        $loaded[$name] = $complete ? -1 : $line;
        update_option('kop_fornits_loaded', $loaded, false);
        if (!$complete) {
            break;
        }
    }
    return $counts;
}

/** The "Fornits discussion" proposal for one topic and facility. Returns 1 when new. */
function kop_fornits_add_link_item(array $t, array $f, $now) {
    global $wpdb;
    $fid = (int) ($f['id'] ?? 0);
    if ($fid <= 0) {
        return 0;
    }
    $how = array_values((array) ($f['how'] ?? array()));
    $also = array_values(array_filter((array) ($f['also'] ?? array()), 'is_array'));
    $posts = (array) ($t['posts'] ?? array());
    $years = array_unique(array_filter(array(substr((string) ($t['started'] ?? ''), 0, 4), substr((string) ($t['last'] ?? ''), 0, 4))));
    // The thread's own title until kop_fornits_title_batch() gives it a headline.
    $label = 'Fornits: ' . trim((string) ($t['title'] ?? '')) . ' (' . implode('-', $years) . ', '
        . count($posts) . ' post' . (count($posts) === 1 ? '' : 's') . ')';
    $first = $posts[0] ?? array();
    $sure = !$also && array_intersect($how, array('board', 'title'));
    return $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . kop_fornits_items_table()
        . ' (pkey, topic_id, kind, facility_id, also, how, label, value, quote, quote_found, context, post_n, author, post_date, preselect, status, created_at)'
        . " VALUES (%s, %d, 'link', %d, %s, %s, %s, %s, %s, 1, '', 0, %s, %s, %d, 'pending', %s)",
        kop_fornits_pkey('link|' . (int) $t['topic'] . '|' . $fid), (int) $t['topic'], $fid, wp_json_encode($also),
        mb_substr(implode(', ', $how), 0, 120), $label,
        wp_json_encode(array('url' => (string) $t['url'], 'board' => (string) ($t['board_name'] ?? ''))),
        mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($first['text'] ?? ''))), 0, 420),
        mb_substr((string) ($first['author'] ?? ''), 0, 120), mb_substr((string) ($first['date'] ?? ''), 0, 20),
        $sure ? 1 : 0, $now)) ? 1 : 0;
}

/** The posts of some topics, read from their batch files in one pass per file. [topic_id => topic]. */
function kop_fornits_load_posts(array $rows) {
    $by_batch = array();
    foreach ($rows as $r) {
        $by_batch[$r['batch']][(int) $r['line']] = (int) $r['topic_id'];
    }
    $out = array();
    foreach ($by_batch as $batch => $lines) {
        $fh = @gzopen(kop_fornits_dir() . '/' . basename($batch), 'rb');
        if (!$fh) {
            continue;
        }
        $line = 0;
        $last = max(array_keys($lines));
        while (($raw = gzgets($fh)) !== false) {
            $line++;
            if (isset($lines[$line])) {
                $t = json_decode($raw, true);
                if (is_array($t) && (int) ($t['topic'] ?? 0) === $lines[$line]) {
                    $out[$lines[$line]] = $t;
                }
            }
            if ($line >= $last) {
                break;
            }
        }
        gzclose($fh);
    }
    return $out;
}

/* ---- Reading a topic with Groq ------------------------------------------ */

/** About 6,000 tokens of posts per request; a longer topic is read in parts. */
function kop_fornits_chunks(array $posts, $limit = 22000) {
    $chunks = array();
    $cur = '';
    foreach ($posts as $p) {
        $text = trim((string) $p['text']);
        if (mb_strlen(preg_replace('/\[quoting[^\]]*\]/', '', $text)) < 40) {
            continue; // "+1", "lol", a bare quote
        }
        $block = '[#' . (int) $p['n'] . '] ' . $p['author'] . ', ' . substr((string) $p['date'], 0, 10) . ":\n"
            . mb_substr($text, 0, 9000) . "\n\n";
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($block) > $limit) {
            $chunks[] = $cur;
            $cur = '';
        }
        $cur .= $block;
    }
    if ($cur !== '') {
        $chunks[] = $cur;
    }
    return array_slice($chunks, 0, 8);
}

function kop_fornits_prompt(array $topic, $posts_text) {
    $facs = array();
    foreach (array_merge((array) $topic['facilities'], (array) ($topic['mentions'] ?? array())) as $f) {
        if (is_array($f) && !empty($f['id'])) {
            $facs[(int) $f['id']] = '- id ' . (int) $f['id'] . ': ' . $f['name'] . (!empty($f['state']) ? ' (' . $f['state'] . ')' : '');
        }
    }
    return "You are reading a thread from Fornits, a forum where survivors of troubled-teen programs, their parents and "
        . "researchers have posted since 2001. Pull out facts about these programs, and only these:\n"
        . implode("\n", $facs) . "\n\n"
        . "Thread: \"" . $topic['title'] . "\" (board: " . $topic['board_name'] . ")\n\n"
        . "Return JSON only, in this shape:\n"
        . "{\"topic\":{\"summary\":\"\",\"categories\":[]},\n"
        . " \"about\":[{\"facility_id\":0,\"says\":\"\",\"importance\":0}],\n"
        . " \"staff\":[{\"facility_id\":0,\"person\":\"\",\"role\":\"\",\"years\":\"\",\"post\":0,\"quote\":\"\"}],\n"
        . " \"incidents\":[{\"facility_id\":0,\"category\":\"death|abuse|sexual_abuse|restraint|seclusion|neglect|runaway|injury|lawsuit|investigation|other\",\"year\":\"\",\"summary\":\"\",\"post\":0,\"quote\":\"\"}],\n"
        . " \"testimony\":[{\"facility_id\":0,\"years\":\"\",\"who\":\"survivor|parent|staff\",\"summary\":\"\",\"post\":0,\"quote\":\"\"}],\n"
        . " \"leads\":[{\"facility_id\":0,\"type\":\"closure|lawsuit|news|investigation|other\",\"year\":\"\",\"summary\":\"\",\"url\":\"\",\"post\":0,\"quote\":\"\"}]}\n\n"
        . "Rules:\n"
        . "- topic.summary: two or three plain sentences on what this thread actually says: who is posting (a survivor, a parent, former staff, "
        . "a program defender), what happened or is claimed, and any names, years, deaths, lawsuits or documents. No opinions of your own.\n"
        . "- topic.categories: every one that fits, from: " . implode(', ', array_keys(kop_fornits_categories())) . ".\n"
        . "- about: one entry per program above that the thread discusses. says: one or two sentences on what the thread says about that program. "
        . "importance: 3 = key evidence (first-hand abuse, a death, named staff, dated incidents, lawsuits, documents); 2 = useful specific facts; "
        . "1 = opinions or second-hand talk; 0 = only a passing mention or chatter.\n"
        . "- facility_id is one of the ids above. Leave out anything about another program.\n"
        . "- post is the [#n] number of the post the fact is in. quote is copied word for word from that post, "
        . "one to three sentences (under 400 characters for staff, incidents and leads; up to 900 for testimony). Never paraphrase in quote.\n"
        . "- staff: named people who worked at or owned the program (director, therapist, staff, owner), with the role the post gives. "
        . "Not posters, students or parents. Not a first name alone.\n"
        . "- incidents: specific harmful events at the program (a death, abuse, restraint, an injury, a runaway, a lawsuit or investigation), "
        . "with the year if the post gives one. Not general opinions.\n"
        . "- testimony: a first-hand account by someone who was held there (or their parent, or a former staff member) describing what happened to them there. "
        . "quote the passage that says the most about what was done to them. One per post at most.\n"
        . "- leads: something worth checking: the program closed or changed name, a lawsuit, a news story (give its url if the post links it), an investigation.\n"
        . "- Ignore arguments between posters, insults, program marketing and anything you are unsure is about these programs.\n"
        . "- Empty lists are fine.\n\n"
        . "Posts:\n" . $posts_text;
}

/** Lowercase words only, for the quote check. */
function kop_fornits_norm($s) {
    $s = strtolower(remove_accents(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8')));
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
}

/**
 * Is the quote really in the post? Its first twelve words word for word, or,
 * for a quote of ten words or more whose start was trimmed, its last eight.
 */
function kop_fornits_quote_found($quote, $text) {
    $words = explode(' ', kop_fornits_norm($quote));
    if (count($words) < 4) {
        return false;
    }
    $hay = ' ' . kop_fornits_norm($text) . ' ';
    if (strpos($hay, ' ' . implode(' ', array_slice($words, 0, 12)) . ' ') !== false) {
        return true;
    }
    return count($words) >= 10 && strpos($hay, ' ' . implode(' ', array_slice($words, -8)) . ' ') !== false;
}

/**
 * Calls a day the reading may make, per provider. Gemini's free tier allows
 * about 500 a day on the Flash-Lite models and nothing else on the site uses
 * it, so it goes first. Groq is shared with the closure scan and the news
 * facility discovery, so it only fills in, up to its own cap. Raise either
 * with KOP_FORNITS_GEMINI_CALLS / KOP_FORNITS_DAILY_CALLS.
 */
function kop_fornits_caps() {
    return array(
        'gemini' => defined('KOP_FORNITS_GEMINI_CALLS') ? (int) KOP_FORNITS_GEMINI_CALLS : 450,
        'groq'   => defined('KOP_FORNITS_DAILY_CALLS') ? (int) KOP_FORNITS_DAILY_CALLS : 150,
    );
}

/** Calls made today: [provider => n]. */
function kop_fornits_calls_today() {
    $c = get_option('kop_fornits_calls', array());
    $out = array_fill_keys(array_keys(kop_fornits_caps()), 0);
    if (is_array($c) && ($c['day'] ?? '') === gmdate('Y-m-d')) {
        foreach ($out as $k => $v) {
            $out[$k] = (int) ($c[$k] ?? 0);
        }
    }
    return $out;
}

function kop_fornits_count_call($provider) {
    $c = kop_fornits_calls_today();
    $c[$provider]++;
    update_option('kop_fornits_calls', array('day' => gmdate('Y-m-d')) + $c, false);
}

/**
 * Ask Groq and Gemini in turn (api/ai-providers.php: each call starts with the
 * other one from the call before), each within its daily cap; when the one
 * whose turn it is fails, the other gets the prompt. A provider that is out
 * of calls or rate limited is skipped for the rest of the run. Throws "rate
 * limit" when none is left, so the topic is tried again next run without
 * spending a try.
 */
function kop_fornits_ask($prompt) {
    static $spent = array();
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $caps = kop_fornits_caps();
    $open = array_values(array_filter(kop_ai_alternating_providers(), function ($p) use ($spent, $caps) {
        return empty($spent[$p]) && kop_fornits_calls_today()[$p] < $caps[$p];
    }));
    $errors = array();
    foreach ($open ? kop_ai_turn_order($open) : array() as $p) {
        try {
            kop_fornits_count_call($p);
            return $p === 'gemini' ? kop_ai_gemini($prompt, array('maxTokens' => 3000))
                : kop_ai_groq($prompt, array('maxTokens' => 3000));
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (kop_ai_is_rate_limit($m)) {
                $spent[$p] = true;
            }
            $errors[] = $p . ': ' . $m;
        }
    }
    if ($errors && !array_filter($errors, 'kop_ai_is_rate_limit')) {
        throw new RuntimeException(implode('; ', $errors));
    }
    throw new RuntimeException('Daily rate limit for the Fornits reading reached' . ($errors ? ' (' . implode('; ', $errors) . ')' : '') . '.');
}

/**
 * One tiny request to each provider through the site's own code, for the
 * "Check AI keys" button: [provider => [ok, message]]. Never shows a key.
 */
function kop_fornits_check_ai() {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $keys = kop_ai_api_keys();
    $prompt = 'Return JSON only: {"facility": "<the name>"} for this sentence: I was sent to Thayer Learning Center in 2004.';
    $out = array();
    foreach (array('gemini' => 'GEMINI_API_KEY', 'groq' => 'GROQ_API_KEY') as $p => $env) {
        if (empty($keys[$p])) {
            $out[$p] = array(false, $env . ' is not set: the site cannot see it in .env (check the line is in the same .env as the other keys, spelled exactly, with no spaces around =).');
            continue;
        }
        try {
            $reply = $p === 'gemini' ? kop_ai_gemini($prompt, array('maxTokens' => 200))
                : kop_ai_groq($prompt, array('maxTokens' => 200));
            $data = kop_ai_extract_json((string) $reply);
            $ok = is_array($data) && stripos((string) ($data['facility'] ?? ''), 'thayer') !== false;
            $model = $p === 'gemini' ? (getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite') : (getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
            $out[$p] = array($ok, ($ok ? 'Works' : 'Answered, but not as expected: ' . mb_substr((string) $reply, 0, 160)) . ' (model ' . $model . ').');
        } catch (Throwable $e) {
            $out[$p] = array(false, mb_substr(preg_replace('/key=[^&\s]+/', 'key=...', $e->getMessage()), 0, 300));
        }
    }
    return $out;
}

/**
 * Read one topic. Returns array(outcome, items added, note); outcome is
 * read, error or limit (a rate limit: try the topic again next run).
 */
function kop_fornits_read_topic(array $row, array $topic) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $topic['facilities'] = json_decode((string) $row['facilities'], true) ?: array();
    $topic['mentions'] = json_decode((string) $row['mentions'], true) ?: array();
    $topic['board_name'] = $row['board_name'];
    $allowed = array();
    foreach (array_merge($topic['facilities'], $topic['mentions']) as $f) {
        if (is_array($f) && !empty($f['id'])) {
            $allowed[(int) $f['id']] = $f;
        }
    }
    $posts = array();
    foreach ((array) $topic['posts'] as $p) {
        $posts[(int) $p['n']] = $p;
    }
    $added = 0;
    $replies = array();
    foreach (kop_fornits_chunks($posts) as $chunk) {
        try {
            $reply = kop_fornits_ask(kop_fornits_prompt($topic, $chunk));
        } catch (Throwable $e) {
            $limit = stripos($e->getMessage(), 'rate limit') !== false;
            return array($limit ? 'limit' : 'error', $added, mb_substr($e->getMessage(), 0, 300));
        }
        $data = kop_ai_extract_json((string) $reply);
        if (!is_array($data)) {
            return array('error', $added, 'Unreadable reply: ' . mb_substr((string) $reply, 0, 200));
        }
        $replies[] = $data;
        foreach (array('staff' => 'staff', 'incidents' => 'incident', 'testimony' => 'testimony', 'leads' => 'lead') as $key => $kind) {
            foreach ((array) ($data[$key] ?? array()) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $added += kop_fornits_store_item($kind, $it, $row, $posts, $allowed);
            }
        }
    }
    kop_fornits_store_summary((int) $row['topic_id'], kop_fornits_merge_reads($replies, $allowed));
    return array('read', $added, '');
}

/**
 * Keep a topic's summary, categories and importance, and put each
 * facility's part on its discussion link: the most important links start
 * ticked, chatter starts unticked.
 */
function kop_fornits_store_summary($tid, array $merged) {
    global $wpdb;
    list($summary, $categories, $about) = $merged;
    $cats = implode(',', $categories);
    $top = $about ? max(array_map(function ($a) { return $a[1]; }, $about)) : 0;
    $wpdb->update(kop_fornits_topics_table(), array('summary' => $summary, 'categories' => $cats, 'importance' => $top), array('topic_id' => $tid));
    $links = $wpdb->get_results($wpdb->prepare('SELECT pkey, facility_id, also FROM ' . kop_fornits_items_table()
        . " WHERE topic_id = %d AND kind = 'link' AND status = 'pending'", $tid), ARRAY_A);
    foreach ($links as $l) {
        $a = $about[(int) $l['facility_id']] ?? array('', 0);
        $also = json_decode((string) $l['also'], true) ?: array();
        $wpdb->update(kop_fornits_items_table(), array(
            'summary'    => $a[0] !== '' ? $a[0] : $summary,
            'categories' => $cats,
            'importance' => $a[1],
            'preselect'  => !$also && $a[1] >= 2 ? 1 : 0,
        ), array('pkey' => $l['pkey']));
    }
    // What Groq found in the thread carries its tags too, for the filter.
    $wpdb->query($wpdb->prepare('UPDATE ' . kop_fornits_items_table() . " SET categories = %s WHERE topic_id = %d AND kind <> 'link'", $cats, $tid));
}

/** Clean one thing the model found and store it as a proposal. Returns 1 when stored. */
function kop_fornits_store_item($kind, array $it, array $row, array $posts, array $allowed) {
    global $wpdb;
    $fid = (int) ($it['facility_id'] ?? 0);
    $n = (int) ($it['post'] ?? -1);
    if (!isset($allowed[$fid]) || !isset($posts[$n])) {
        return 0;
    }
    $post = $posts[$n];
    $quote = trim(preg_replace('/\s+/', ' ', (string) ($it['quote'] ?? '')), " \"'");
    $quote = mb_substr($quote, 0, $kind === 'testimony' ? 1200 : 600);
    $found = kop_fornits_quote_found($quote, $post['text']);
    $str = function ($k, $max = 300) use ($it) {
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($it[$k] ?? ''))), 0, $max);
    };
    $year = preg_match('/\b(19[5-9]\d|20[0-2]\d)\b/', $str('year') . ' ' . $str('years'), $m) ? $m[1] : '';
    if ($kind === 'staff') {
        $person = $str('person', 120);
        if (count(preg_split('/\s+/', $person)) < 2) {
            return 0;
        }
        $value = array('person' => $person, 'role' => $str('role', 120), 'years' => $str('years', 40));
        $label = $person . ($value['role'] !== '' ? ', ' . $value['role'] : '') . ($value['years'] !== '' ? ' (' . $value['years'] . ')' : '');
        $vkey = function_exists('kop_wbf_person_key') ? kop_wbf_person_key($person) : strtolower($person);
    } elseif ($kind === 'incident') {
        $cats = array('death', 'abuse', 'sexual_abuse', 'restraint', 'seclusion', 'neglect', 'runaway', 'injury', 'lawsuit', 'investigation', 'other');
        $cat = in_array($str('category', 20), $cats, true) ? $str('category', 20) : 'other';
        $summary = $str('summary', 400);
        if ($summary === '') {
            return 0;
        }
        $value = array('category' => $cat, 'year' => $year, 'summary' => $summary);
        $label = ucfirst(str_replace('_', ' ', $cat)) . ': ' . $summary;
        $vkey = $cat . '|' . ($year !== '' ? $year : kop_fornits_norm($summary));
    } elseif ($kind === 'testimony') {
        if ($quote === '') {
            return 0;
        }
        $who = in_array($str('who', 10), array('survivor', 'parent', 'staff'), true) ? $str('who', 10) : 'survivor';
        $value = array('who' => $who, 'years' => $str('years', 40), 'summary' => $str('summary', 400));
        $label = ucfirst($who) . ' account' . ($value['years'] !== '' ? ' (' . $value['years'] . ')' : '') . ': ' . $value['summary'];
        $vkey = (string) $n;
    } else {
        $types = array('closure', 'lawsuit', 'news', 'investigation', 'other');
        $type = in_array($str('type', 20), $types, true) ? $str('type', 20) : 'other';
        $url = $str('url', 500);
        if ($url !== '' && (!preg_match('#^https?://#i', $url) || strpos($post['text'], preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'))) === false)) {
            $url = ''; // only an address the post really links
        }
        $value = array('type' => $type, 'year' => $year, 'summary' => $str('summary', 400), 'url' => $url);
        $label = ucfirst($type) . ': ' . $value['summary'];
        $vkey = $type . '|' . ($url !== '' ? $url : kop_fornits_norm($value['summary']));
    }
    $context = mb_substr((string) $post['text'], 0, 6000);
    return $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . kop_fornits_items_table()
        . ' (pkey, topic_id, kind, facility_id, also, how, label, value, quote, quote_found, context, post_n, author, post_date, preselect, status, created_at)'
        . " VALUES (%s, %d, %s, %d, '[]', %s, %s, %s, %s, %d, %s, %d, %s, %s, %d, 'pending', %s)",
        kop_fornits_pkey($kind . '|' . (int) $row['topic_id'] . '|' . $fid . '|' . $vkey), (int) $row['topic_id'], $kind, $fid,
        'read by Groq', $label, wp_json_encode($value), $quote, $found ? 1 : 0, $context, $n,
        mb_substr((string) $post['author'], 0, 120), mb_substr((string) $post['date'], 0, 20),
        $found && $kind !== 'testimony' ? 1 : 0, current_time('mysql', true))) ? 1 : 0;
}

/**
 * Read waiting topics until $seconds pass or Groq says the rate limit is
 * reached. Topics whose facility comes from the board or the title go first.
 */
function kop_fornits_read_batch($limit = 30, $seconds = 150) {
    global $wpdb;
    kop_fornits_ensure_tables();
    $topics = kop_fornits_topics_table();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$topics} WHERE read_status = 'pending' AND tries < 3
        ORDER BY prio, n_posts DESC, topic_id LIMIT %d", $limit), ARRAY_A);
    $counts = array('read' => 0, 'error' => 0, 'limit' => 0, 'items' => 0);
    if (!$rows) {
        return $counts;
    }
    $started = time();
    $posts = kop_fornits_load_posts($rows);
    foreach ($rows as $row) {
        $tid = (int) $row['topic_id'];
        if (!isset($posts[$tid])) {
            $wpdb->update($topics, array('read_status' => 'error', 'read_note' => 'Posts not found in ' . $row['batch'],
                'read_at' => current_time('mysql', true)), array('topic_id' => $tid));
            $counts['error']++;
            continue;
        }
        list($outcome, $added, $note) = kop_fornits_read_topic($row, $posts[$tid]);
        $counts[$outcome]++;
        $counts['items'] += $added;
        if ($outcome === 'limit') {
            break; // no try spent: the next run starts here
        }
        $tries = (int) $row['tries'] + ($outcome === 'error' ? 1 : 0);
        $wpdb->update($topics, array(
            'read_status' => $outcome === 'read' ? 'read' : ($tries >= 3 ? 'error' : 'pending'),
            'read_note'   => $note, 'tries' => $tries, 'read_at' => current_time('mysql', true),
        ), array('topic_id' => $tid));
        if (time() - $started > $seconds) {
            break;
        }
    }
    return $counts;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_fornits_hourly')) {
        wp_schedule_event(time() + 900, 'hourly', 'kop_fornits_hourly');
    }
});

add_action('kop_fornits_hourly', 'kop_fornits_cron');

function kop_fornits_cron() {
    if (get_transient('kop_fornits_lock')) {
        return;
    }
    set_transient('kop_fornits_lock', 1, 15 * MINUTE_IN_SECONDS);
    try {
        kop_fornits_sync(60);
        $c = kop_fornits_read_batch(30, 150);
        $c['titled'] = kop_fornits_title_batch(5);
        update_option('kop_fornits_last_run', array('at' => current_time('mysql', true)) + $c, false);
    } catch (Throwable $e) {
        error_log('kop fornits: ' . $e->getMessage());
    } finally {
        delete_transient('kop_fornits_lock');
    }
}

/* ---- Headlines ------------------------------------------------------------ */

/**
 * Forum thread titles ("Re: anyone?", "HELP!!!", "my story") say nothing on a
 * facility page, so each read topic gets a plain headline written from its
 * summary, twenty topics to a request (no posts are read again). The
 * discussion links take it: pending proposals at once, and a link already on
 * a record when its label there is still the one Fornits put (a label a
 * person changed is kept). Returns the number of topics titled.
 */
function kop_fornits_title_batch($max_calls = 5, $per_call = 20) {
    global $wpdb;
    kop_fornits_ensure_tables();
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $topics = kop_fornits_topics_table();
    $done = 0;
    for ($call = 0; $call < $max_calls; $call++) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT topic_id, title, board_name, summary, started, last_post FROM {$topics}
            WHERE read_status = 'read' AND headline = '' AND summary IS NOT NULL AND summary <> ''
            ORDER BY importance DESC, topic_id LIMIT %d", $per_call), ARRAY_A);
        if (!$rows) {
            break;
        }
        try {
            $reply = kop_fornits_ask(kop_fornits_title_prompt($rows));
        } catch (Throwable $e) {
            break; // rate limit or both providers down: the next run goes on
        }
        $data = kop_ai_extract_json((string) $reply);
        $got = array();
        foreach ((array) ($data['titles'] ?? array()) as $t) {
            if (is_array($t) && (int) ($t['id'] ?? 0) > 0) {
                $got[(int) $t['id']] = kop_fornits_clean_headline((string) ($t['title'] ?? ''));
            }
        }
        if (!$got) {
            break; // an unreadable reply: try again next run rather than mark the topics titled
        }
        foreach ($rows as $r) {
            $tid = (int) $r['topic_id'];
            $own = mb_substr(trim((string) $r['title']), 0, 200);
            // A topic the reply left out keeps its own title, so it is not asked for again.
            $headline = ($got[$tid] ?? '') !== '' ? $got[$tid] : $own;
            $wpdb->update($topics, array('headline' => $headline !== '' ? $headline : '-'), array('topic_id' => $tid));
            if ($headline !== '' && $headline !== $own) {
                kop_fornits_retitle_links($r, $headline);
                $done++;
            }
        }
    }
    return $done;
}

function kop_fornits_title_prompt(array $rows) {
    $list = '';
    foreach ($rows as $r) {
        $years = array_unique(array_filter(array(substr((string) $r['started'], 0, 4), substr((string) $r['last_post'], 0, 4))));
        $list .= '- id ' . (int) $r['topic_id'] . ' | board: ' . $r['board_name'] . ' | thread title: "' . trim((string) $r['title'])
            . '" | ' . implode('-', $years) . "\n  summary: " . trim((string) $r['summary']) . "\n";
    }
    return "These are threads from Fornits, a forum where survivors of troubled-teen programs, their parents and researchers "
        . "have posted since 2001. Their own titles are often useless (\"Re: help\", \"anyone?\"). Write a plain headline for each, "
        . "for a list of sources on a program's page.\n\n"
        . "Rules:\n"
        . "- Under 90 characters. Say who is posting (survivor, parent, former staff, program defender) when the summary says, "
        . "the program by name, and what the thread is about, with the year or years if the summary gives them. "
        . "Example: \"Former student describes isolation room at Cross Creek Manor (2003)\".\n"
        . "- Only what the summary says. Never a poster's name or username, never the name of a young person.\n"
        . "- When the thread is only chatter, say so: \"Forum chatter about <program>\".\n"
        . "- No quotation marks, no ending full stop, no emojis.\n\n"
        . "Return JSON only: {\"titles\":[{\"id\":0,\"title\":\"\"}]}\n\n"
        . "Threads:\n" . $list;
}

function kop_fornits_clean_headline($s) {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)), " \t\"'\u{201C}\u{201D}");
    return mb_substr(rtrim($s, '.'), 0, 200);
}

/** A review row whose topic has a headline of its own (not its thread title, not the '-' placeholder). */
function kop_fornits_has_headline(array $r) {
    $h = (string) ($r['topic_headline'] ?? '');
    return $h !== '' && $h !== '-' && $h !== trim((string) ($r['topic_title'] ?? ''));
}

/** Put a topic's headline on its discussion links, in the queue and on the records. */
function kop_fornits_retitle_links(array $topic, $headline) {
    global $wpdb;
    $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_fornits_items_table() . " WHERE topic_id = %d AND kind = 'link'",
        (int) $topic['topic_id']), ARRAY_A);
    foreach ((array) $items as $r) {
        $new = $r;
        $new['label'] = kop_fornits_headline_label((string) $r['label'], (string) $topic['title'], $headline);
        if ($new['label'] === null) {
            continue;
        }
        $done = json_decode((string) $r['applied'], true);
        if ($r['status'] === 'applied' && ($done['via'] ?? '') === 'link' && (int) $r['applied_fid'] > 0) {
            try {
                kop_fornits_relabel_record_link((int) $r['applied_fid'], (string) $done['url'], kop_fornits_link_label($r), kop_fornits_link_label($new));
            } catch (Throwable $e) {
                error_log('kop fornits retitle #' . (int) $r['applied_fid'] . ': ' . $e->getMessage());
                continue;
            }
        }
        $wpdb->update(kop_fornits_items_table(), array('label' => $new['label']), array('pkey' => $r['pkey']));
    }
}

/** "Fornits: <thread title> (2004, 3 posts)..." with the headline in place of the title; null when the label is not that. */
function kop_fornits_headline_label($label, $title, $headline) {
    $old = 'Fornits: ' . trim((string) $title) . ' (';
    return strpos((string) $label, $old) === 0 ? 'Fornits: ' . $headline . ' (' . substr((string) $label, strlen($old)) : null;
}

/** Swap the label of the link to $url in a document, only while it is still $from. True when changed. */
function kop_fornits_relabel_doc(array &$doc, $url, $from, $to) {
    $key = kop_gdl_url_key($url);
    $changed = false;
    foreach ((array) ($doc['resourceLinks'] ?? array()) as $i => $l) {
        if (is_array($l) && kop_gdl_url_key($l['url'] ?? '') === $key && (string) ($l['label'] ?? '') === $from) {
            $doc['resourceLinks'][$i]['label'] = $to;
            $changed = true;
        }
    }
    return $changed;
}

function kop_fornits_relabel_record_link($fid, $url, $from, $to) {
    $opts = kop_wbf_opts();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $url, $from, $to, $opts) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            return;
        }
        $doc = $stored['doc'];
        if (kop_fornits_relabel_doc($doc, $url, $from, $to)) {
            kop_wbf_save($doc, $opts);
        }
    });
}

/* ---- Changing a record --------------------------------------------------- */

function kop_fornits_rows(array $pkeys) {
    global $wpdb;
    $pkeys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, $pkeys)));
    if (!$pkeys) {
        return array();
    }
    $in = implode(',', array_fill(0, count($pkeys), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare('SELECT i.*, t.title AS topic_title, t.headline AS topic_headline, t.url AS topic_url, t.board_name FROM '
        . kop_fornits_items_table() . ' i LEFT JOIN ' . kop_fornits_topics_table() . " t ON t.topic_id = i.topic_id WHERE i.pkey IN ({$in})", $pkeys), ARRAY_A);
}

/** "Fornits forum, post by X, March 2005: <url>", for notes and citations. */
function kop_fornits_cite(array $r) {
    $when = kop_fornits_month($r['post_date']);
    return 'Fornits forum, post by ' . ($r['author'] !== '' ? $r['author'] : 'a member') . ($when ? ', ' . $when : '')
        . ': ' . kop_fornits_post_url($r['topic_id'], $r['post_n']);
}

/** "Fornits: <title> (2004-2006, 12 posts). <what it says about this facility>" */
function kop_fornits_link_label(array $r) {
    $label = (string) $r['label'];
    $says = trim((string) ($r['summary'] ?? ''));
    if ($says !== '') {
        $label .= '. ' . (mb_strlen($says) > 300 ? rtrim(mb_substr($says, 0, 297)) . '...' : $says);
    }
    return $label;
}

/** Where a lead goes by default. */
function kop_fornits_lead_target(array $v) {
    if ($v['url'] !== '' && in_array($v['type'], array('news', 'lawsuit'), true)) {
        return $v['type'];
    }
    return $v['type'] === 'closure' ? 'closed' : 'note';
}

function kop_fornits_lead_targets() {
    return array('news' => 'News queue', 'lawsuit' => 'Lawsuits queue', 'closed' => 'Mark the facility closed', 'note' => 'A note on the record');
}

/**
 * Apply one proposal to a document. Returns what was done, for Undo; throws
 * when the record already has it. Staff, incident lines, closures and notes
 * go through Woodbury Facts' change (kop_wbf_doc_apply) with a Fornits
 * citation, so its exact undo applies.
 */
function kop_fornits_doc_apply(array &$doc, array $r, $target) {
    $v = json_decode((string) $r['value'], true) ?: array();
    $evidence = wp_json_encode(array(array('cite' => 'Fornits forum, post by ' . ($r['author'] !== '' ? $r['author'] : 'a member')
        . (kop_fornits_month($r['post_date']) ? ', ' . kop_fornits_month($r['post_date']) : ''),
        'url' => kop_fornits_post_url($r['topic_id'], $r['post_n']))));
    $wbf = function ($op, $path, $value, $label) use (&$doc, $evidence) {
        return array('via' => 'wbf') + kop_wbf_doc_apply($doc, array('op' => $op, 'path' => $path, 'value' => wp_json_encode($value),
            'label' => $label, 'evidence' => $evidence, 'extra' => ''));
    };

    if ($r['kind'] === 'link') {
        $url = (string) ($v['url'] ?? '');
        $key = kop_gdl_url_key($url);
        foreach ((array) ($doc['resourceLinks'] ?? array()) as $l) {
            if (is_array($l) && kop_gdl_url_key($l['url'] ?? '') === $key) {
                throw new RuntimeException('Already on the record.');
            }
        }
        $doc['resourceLinks'][] = array('url' => $url, 'label' => kop_fornits_link_label($r), 'kind' => 'social',
            'source' => 'Fornits forum' . (!empty($v['board']) ? ', board "' . $v['board'] . '"' : ''));
        return array('via' => 'link', 'url' => $url);
    }
    if ($r['kind'] === 'staff') {
        $role = trim(($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : ''));
        return $wbf('add_staff', 'staff.notableStaff', array('name' => $v['person'], 'role' => $role,
            'pastJobs' => ''), 'Staff: ' . $v['person']);
    }
    if ($r['kind'] === 'incident') {
        $labels = array('death' => 'Death', 'abuse' => 'Abuse', 'sexual_abuse' => 'Sexual abuse', 'restraint' => 'Restraint',
            'seclusion' => 'Seclusion', 'neglect' => 'Neglect', 'runaway' => 'Runaway', 'injury' => 'Injury',
            'lawsuit' => 'Lawsuit', 'investigation' => 'Investigation', 'other' => 'Incident');
        $when = $v['year'] !== '' ? $v['year'] : 'Reported ' . kop_fornits_month($r['post_date']);
        $line = $when . ': ' . ($labels[$v['category']] ?? 'Incident') . ': ' . rtrim($v['summary'], '.') . '. (' . kop_fornits_cite($r) . ')';
        return $wbf('add_list', 'criticalIncidents.customIncidents', $line, 'Incident');
    }
    if ($r['kind'] === 'testimony') {
        $id = 'fornits-' . (int) $r['topic_id'] . '-' . (int) $r['post_n'];
        foreach ((array) ($doc['survivorTestimony'] ?? array()) as $t) {
            if (is_array($t) && ($t['id'] ?? '') === $id) {
                throw new RuntimeException('Already on the record.');
            }
        }
        $doc['survivorTestimony'][] = array('id' => $id, 'text' => (string) $r['quote'], 'source' => kop_fornits_cite($r),
            'date' => substr((string) $r['post_date'], 0, 10), 'movedFrom' => '', 'publish' => true);
        return array('via' => 'testimony', 'id' => $id);
    }
    // A lead kept on the record: a closure or a note.
    if ($target === 'closed') {
        return $wbf('set_closed', 'operatingPeriod.status', array('endYear' => $v['year'] !== '' ? (int) $v['year'] : null),
            'Reported closed on Fornits: ' . $v['summary']);
    }
    $line = 'Fornits lead (' . $v['type'] . '): ' . rtrim($v['summary'], '.') . '.' . ($v['url'] !== '' ? ' ' . $v['url'] : '') . ' (' . kop_fornits_cite($r) . ')';
    return $wbf('add_list', 'notes', $line, 'Lead');
}

function kop_fornits_doc_undo(array &$doc, array $done) {
    if (($done['via'] ?? '') === 'link') {
        $key = kop_gdl_url_key($done['url']);
        $doc['resourceLinks'] = array_values(array_filter((array) ($doc['resourceLinks'] ?? array()), function ($l) use ($key) {
            return !is_array($l) || kop_gdl_url_key($l['url'] ?? '') !== $key;
        }));
    } elseif (($done['via'] ?? '') === 'testimony') {
        $doc['survivorTestimony'] = array_values(array_filter((array) ($doc['survivorTestimony'] ?? array()), function ($t) use ($done) {
            return !is_array($t) || ($t['id'] ?? '') !== $done['id'];
        }));
    } else {
        kop_wbf_doc_undo($doc, $done);
    }
}

/** Send a lead's link to the news or lawsuits queue (no admin emails). */
function kop_fornits_queue_add(array $r, $target, $facility_name, $reviewer) {
    $v = json_decode((string) $r['value'], true) ?: array();
    if (($v['url'] ?? '') === '') {
        throw new RuntimeException('This lead has no link to send; keep it as a note instead.');
    }
    $pdo = kop_gdl_queue_pdo();
    $p = array('url' => $v['url'], 'title' => $v['summary'] !== '' ? $v['summary'] : $v['url'],
        'type' => $target === 'news' ? 'article' : 'lawsuit', 'site_name' => (string) parse_url($v['url'], PHP_URL_HOST),
        'facility' => (string) $facility_name);
    $dupes = kop_ext_find_duplicates($pdo, $p);
    if ($dupes) {
        throw new RuntimeException('Already in the ' . $dupes[0]['type'] . ' records (#' . (int) $dupes[0]['id'] . ').');
    }
    $note = 'Linked in a Fornits post (' . kop_fornits_cite($r) . ')' . ($r['quote'] !== '' ? "\n\n\"" . $r['quote'] . '"' : '');
    $quiet = function () { return false; };
    add_filter('kop_notify_admins_enabled', $quiet);
    try {
        $submitter = mb_substr($reviewer . ' (Fornits import)', 0, 255);
        $id = $target === 'news' ? kop_ext_insert_news($pdo, $p, $submitter, $note) : kop_ext_insert_lawsuit($pdo, $p, $submitter, $note);
    } finally {
        remove_filter('kop_notify_admins_enabled', $quiet);
    }
    return array('via' => 'queue', 'target' => $target, 'id' => (int) $id);
}

/**
 * Add proposals. $targets maps pkey => lead target; $fid, when given, is the
 * record everything goes on instead of each row's own facility.
 */
function kop_fornits_apply(array $rows, array $targets, $fid, $reviewer) {
    global $wpdb;
    $results = array();
    $now = current_time('mysql', true);
    $mark = function ($r, $status, $applied, $applied_fid) use ($wpdb, $reviewer, $now) {
        $wpdb->update(kop_fornits_items_table(), array('status' => $status, 'applied' => wp_json_encode($applied),
            'applied_fid' => (int) $applied_fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
    };
    $pdo = kop_closure_pdo();
    $name_of = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?');
        $st->execute(array((int) $id));
        return (string) $st->fetchColumn();
    };
    if ($fid > 0 && $name_of($fid) === '') {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    $by_fid = array();
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        $to = $fid > 0 ? (int) $fid : (int) $r['facility_id'];
        $target = '';
        if ($r['kind'] === 'lead') {
            $valid = kop_fornits_lead_targets();
            $target = (string) ($targets[$r['pkey']] ?? '');
            $target = isset($valid[$target]) ? $target : kop_fornits_lead_target(json_decode((string) $r['value'], true) ?: array());
            if (in_array($target, array('news', 'lawsuit'), true)) {
                try {
                    $mark($r, 'applied', kop_fornits_queue_add($r, $target, $to ? $name_of($to) : '', $reviewer), $to);
                    $results[$r['pkey']] = array('ok' => true);
                } catch (Throwable $e) {
                    $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage(), 'keep' => true);
                }
                continue;
            }
        }
        if ($to <= 0) {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Pick the facility first.', 'keep' => true);
            continue;
        }
        $by_fid[$to][] = array($r, $target);
    }
    if (!$by_fid) {
        return $results;
    }
    $opts = kop_wbf_opts();
    foreach ($by_fid as $to => $list) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($to, $list, $opts, $mark, &$results) {
            $stored = kop_facility_load($to, $opts);
            if (!$stored) {
                throw new RuntimeException("Facility #{$to} does not exist.");
            }
            $doc = $stored['doc'];
            $applied = array();
            foreach ($list as list($r, $target)) {
                $trial = $doc;
                try {
                    $done = kop_fornits_doc_apply($trial, $r, $target);
                    $doc = $trial;
                    $applied[] = array($r, $done);
                    $results[$r['pkey']] = array('ok' => true);
                } catch (RuntimeException $e) {
                    $mark($r, 'rejected', array('reason' => $e->getMessage()), 0);
                    $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
                }
            }
            if ($applied) {
                kop_wbf_save($doc, $opts);
                foreach ($applied as list($r, $done)) {
                    $mark($r, 'applied', $done, $to);
                }
            }
        });
    }
    return $results;
}

function kop_fornits_undo(array $rows, $reviewer) {
    global $wpdb;
    $results = array();
    $back = function ($r) use ($wpdb, $reviewer) {
        $wpdb->update(kop_fornits_items_table(), array('status' => 'pending', 'applied' => null, 'applied_fid' => 0,
            'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
    };
    $by_fid = array();
    foreach ($rows as $r) {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if ($r['status'] === 'rejected') {
            $back($r);
            $results[$r['pkey']] = array('ok' => true);
        } elseif ($r['status'] !== 'applied') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Nothing to undo.');
        } elseif (($done['via'] ?? '') === 'queue') {
            try {
                kop_gdl_queue_remove(kop_gdl_queue_pdo(), $done);
                $back($r);
                $results[$r['pkey']] = array('ok' => true);
            } catch (Throwable $e) {
                $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            }
        } else {
            $by_fid[(int) $r['applied_fid']][] = array($r, $done);
        }
    }
    if ($by_fid) {
        $opts = kop_wbf_opts();
        foreach ($by_fid as $fid => $list) {
            kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, $back, &$results) {
                $stored = kop_facility_load($fid, $opts);
                if (!$stored) {
                    throw new RuntimeException("Facility #{$fid} does not exist.");
                }
                $doc = $stored['doc'];
                foreach ($list as list($r, $done)) {
                    kop_fornits_doc_undo($doc, $done);
                }
                kop_wbf_save($doc, $opts);
                foreach ($list as list($r, $done)) {
                    $back($r);
                    $results[$r['pkey']] = array('ok' => true);
                }
            });
        }
    }
    return $results;
}

add_action('wp_ajax_kop_fornits_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_fornits', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? array_slice($_POST['keys'], 0, 400) : array();
    $user = wp_get_current_user()->user_login;
    try {
        if ($act === 'check_ai') {
            $lines = array();
            foreach (kop_fornits_check_ai() as $p => list($ok, $msg)) {
                $lines[] = ucfirst($p) . ': ' . ($ok ? '' : 'NOT WORKING. ') . $msg;
            }
            wp_send_json_success(array('message' => implode('  |  ', $lines)));
        }
        if ($act === 'read_now') {
            kop_fornits_sync(20);
            $c = kop_fornits_read_batch(6, 60);
            wp_send_json_success(array('message' => 'Read ' . $c['read'] . ' topics, ' . $c['items'] . ' new proposals'
                . ($c['limit'] ? '; the daily limit is reached, the hourly run carries on' : '') . ($c['error'] ? ', ' . $c['error'] . ' errors' : '') . '.'));
        }
        $rows = kop_fornits_rows($keys);
        if (!$rows) {
            throw new RuntimeException('Nothing selected.');
        }
        if ($act === 'apply') {
            $targets = array();
            foreach ((array) ($_POST['targets'] ?? array()) as $k => $v) {
                $targets[preg_replace('/[^a-f0-9]/', '', (string) $k)] = sanitize_key($v);
            }
            $fid = (int) ($_POST['fid'] ?? 0);
            $results = kop_fornits_apply($rows, $targets, $fid, $user);
            $to = $fid ?: (int) $rows[0]['facility_id'];
            $url = $to && function_exists('kop_facility_page_url') ? kop_facility_page_url($to) : '';
            wp_send_json_success(array('results' => $results, 'url' => $url));
        }
        if ($act === 'reject') {
            $now = current_time('mysql', true);
            foreach ($rows as $r) {
                if ($r['status'] === 'pending') {
                    $GLOBALS['wpdb']->update(kop_fornits_items_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                        'reviewed_by' => $user, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
                }
            }
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        if ($act === 'undo') {
            wp_send_json_success(array('results' => kop_fornits_undo($rows, $user)));
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Review screen ------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Fornits', 'Fornits', 'manage_options', 'kop-fornits', 'kop_render_fornits_page');
}, 21);

function kop_render_fornits_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_fornits_ensure_tables();
    $sync = kop_fornits_sync(15);
    $items = kop_fornits_items_table();
    $topics = kop_fornits_topics_table();
    $kinds = kop_fornits_kinds();
    $kind = isset($_GET['fn_kind'], $kinds[$_GET['fn_kind']]) ? $_GET['fn_kind'] : 'link';
    $state = in_array($_GET['fn_state'] ?? '', array('applied', 'rejected'), true) ? $_GET['fn_state'] : 'pending';
    $q = isset($_GET['fn_q']) ? trim(sanitize_text_field(wp_unslash($_GET['fn_q']))) : '';
    $cats = kop_fornits_categories();
    $cat = isset($_GET['fn_cat'], $cats[$_GET['fn_cat']]) ? $_GET['fn_cat'] : '';
    $min = isset($_GET['fn_min']) && $_GET['fn_min'] !== '' ? max(0, min(3, (int) $_GET['fn_min'])) : -1;
    $paged = max(1, (int) ($_GET['fn_page'] ?? 1));
    $per = 15;
    $base = admin_url('admin.php?page=kop-fornits');

    $t = $wpdb->get_row("SELECT COUNT(*) AS n, SUM(read_status = 'read') AS done, SUM(read_status = 'error') AS err FROM {$topics}", ARRAY_A);
    $last = get_option('kop_fornits_last_run', array());

    echo '<div class="wrap kop-fn"><h1>Fornits</h1>';
    if ($sync['topics']) {
        echo '<div class="notice notice-info"><p>Loaded ' . (int) $sync['topics'] . ' topics from new batches (' . (int) $sync['links'] . ' new discussion links).</p></div>';
    }
    echo '<p>What the old Fornits survivor forum (2001 on) says about each facility. Its treatment-abuse boards are copied a topic at a time; '
        . 'every topic about a facility is offered as a discussion link for its page. The AI (Gemini, then Groq) reads each thread: a short summary, what it says about each facility, '
        . 'categories, how much it matters (key evidence, useful, opinions only, chatter), and the staff, incidents, survivor accounts and leads in it. '
        . 'The most important threads come first and start ticked; threads not read yet say so. '
        . 'Every quote below was checked against the post it came from; one marked <em>not found in the post</em> starts unticked.</p>'
        . '<ol class="kop-fn-how"><li><strong>Pick a tab</strong> and read down a card: one card per facility.</li>'
        . '<li><strong>Untick</strong> anything wrong, then <strong>Add checked</strong>. Discussion links appear under "Survivor posts and discussion" on the facility page; '
        . 'staff and incidents go on the record citing the post; survivor accounts go into the record\'s testimony <em>unpublished</em> '
        . '(tick "OK to publish" on the record when you want one shown).</li>'
        . '<li><strong>Wrong facility?</strong> Pick the right one in the box under the card and click <em>Add checked to that record</em>.</li>'
        . '<li><strong>Changed your mind?</strong> The <em>Added</em> view has Undo.</li></ol>';
    echo '<p class="kop-fn-progress">Topics loaded: <strong>' . (int) $t['n'] . '</strong> &middot; read by the AI: <strong>' . (int) $t['done'] . '</strong>'
        . ' &middot; calls today: ' . implode(', ', array_map(function ($p, $n) { return ucfirst($p) . ' ' . $n . ' of ' . kop_fornits_caps()[$p]; },
            array_keys(kop_fornits_calls_today()), kop_fornits_calls_today()))
        . ((int) $t['err'] ? ' &middot; could not read: ' . (int) $t['err'] : '')
        . (!empty($last['at']) ? ' &middot; last hourly run ' . esc_html(get_date_from_gmt($last['at'], 'M j, g:i a')) . ': read ' . (int) $last['read']
            . ($last['limit'] ? ' (stopped at the daily limit)' : '') : '')
        . ' <button type="button" class="button kop-fn-read">Read a few more now</button> <button type="button" class="button kop-fn-check-ai">Check AI keys</button>'
        . ' <span class="kop-fn-read-out" aria-live="polite"></span></p>';
    if (!(int) $t['n']) {
        echo '<div class="notice notice-warning"><p>No topics yet. <code>scripts/fornits-process.py</code> uploads batches to <code>'
            . esc_html(kop_fornits_dir()) . '</code> every hour while the crawl runs.</p></div>';
    }

    echo '<ul class="subsubsub">';
    $i = 0;
    foreach ($kinds as $k => $label) {
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$items} WHERE kind = %s AND status = 'pending'", $k));
        echo '<li><a href="' . esc_url(add_query_arg(array('fn_kind' => $k), $base)) . '"' . ($kind === $k && $state === 'pending' ? ' class="current"' : '') . '>'
            . esc_html($label) . ' <span class="count">(' . $n . ')</span></a> | </li>';
    }
    foreach (array('applied' => 'Added', 'rejected' => 'Skipped or already there') as $k => $label) {
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$items} WHERE kind = %s AND status = %s", $kind, $k));
        echo '<li><a href="' . esc_url(add_query_arg(array('fn_kind' => $kind, 'fn_state' => $k), $base)) . '"' . ($state === $k ? ' class="current"' : '') . '>'
            . esc_html($label . ' (' . $kinds[$kind] . ')') . ' <span class="count">(' . $n . ')</span></a>' . (++$i < 2 ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="get" class="kop-fn-filter"><input type="hidden" name="page" value="kop-fornits">'
        . '<input type="hidden" name="fn_kind" value="' . esc_attr($kind) . '"><input type="hidden" name="fn_state" value="' . esc_attr($state) . '">'
        . '<input type="search" name="fn_q" value="' . esc_attr($q) . '" placeholder="Words, person or poster" style="width:240px" aria-label="Search"> '
        . '<select name="fn_cat" aria-label="Category"><option value="">Every category</option>';
    foreach ($cats as $k => $label) {
        echo '<option value="' . esc_attr($k) . '"' . selected($cat, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <select name="fn_min" aria-label="Importance"><option value="">Any importance</option>';
    foreach (array(3 => 'Key evidence only', 2 => 'Useful or better', 1 => 'Hide chatter', 0 => 'Read by the AI') as $k => $label) {
        echo '<option value="' . $k . '"' . selected($min, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <button class="button">Show</button>'
        . ($q !== '' || $cat !== '' || $min >= 0 ? ' <a href="' . esc_url(add_query_arg(array('fn_kind' => $kind, 'fn_state' => $state), $base)) . '">Clear</a>' : '') . '</form>';

    // Every query below reads the items as i.
    $where = $wpdb->prepare('i.kind = %s AND i.status = %s', $kind, $state);
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= $wpdb->prepare(' AND (i.label LIKE %s OR i.quote LIKE %s OR i.author LIKE %s OR i.summary LIKE %s)', $like, $like, $like, $like);
    }
    if ($cat !== '') {
        $where .= $wpdb->prepare(' AND FIND_IN_SET(%s, i.categories)', $cat);
    }
    if ($min >= 0) {
        // "Read by the AI" (0): every row the reading has tagged.
        $where .= $min > 0 ? $wpdb->prepare(' AND i.importance >= %d', $min) : " AND i.categories <> ''";
    }
    $card = $state === 'applied' ? 'applied_fid' : 'facility_id';
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT i.{$card}) FROM {$items} i WHERE {$where}");
    $cards = $wpdb->get_col("SELECT i.{$card} FROM {$items} i WHERE {$where} GROUP BY i.{$card} ORDER BY MAX(i.importance) DESC, COUNT(*) DESC LIMIT " . (($paged - 1) * $per) . ", {$per}");
    if (!$cards) {
        echo '<p>Nothing here' . ($state === 'pending' && (int) $t['n'] > (int) $t['done'] ? ' yet: Groq reads more topics every hour.' : '.') . '</p>';
        kop_fornits_render_assets();
        echo '</div>';
        return;
    }
    $in = implode(',', array_map('intval', $cards));
    $rows = $wpdb->get_results("SELECT i.*, t.title AS topic_title, t.headline AS topic_headline, t.url AS topic_url, t.board_name, t.summary AS topic_summary, t.read_status
        FROM {$items} i LEFT JOIN {$topics} t ON t.topic_id = i.topic_id
        WHERE {$where} AND i.{$card} IN ({$in}) ORDER BY i.importance DESC, i.preselect DESC, i.post_date", ARRAY_A);
    $by = array();
    foreach ($rows as $r) {
        $by[(int) $r[$card]][] = $r;
    }

    echo '<div class="kop-fn-bar">';
    if ($state === 'pending') {
        echo '<button type="button" class="button button-primary kop-fn-all">Add everything ticked on this page</button> ';
    }
    echo '<span class="kop-fn-progress-all" aria-live="polite"></span></div>';
    foreach ($cards as $c) {
        if (!empty($by[(int) $c])) {
            kop_fornits_render_card($by[(int) $c], (int) $c, $state);
        }
    }
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        echo '<p class="kop-fn-pager">Page ' . $paged . ' of ' . $pages . ' (' . $total . ' facilities) &middot; ';
        for ($n = 1; $n <= $pages; $n++) {
            $url = add_query_arg(array('fn_kind' => $kind, 'fn_state' => $state, 'fn_page' => $n, 'fn_q' => $q !== '' ? $q : null,
                'fn_cat' => $cat !== '' ? $cat : null, 'fn_min' => $min >= 0 ? $min : null), $base);
            echo $n === $paged ? '<strong>' . $n . '</strong> ' : '<a href="' . esc_url($url) . '">' . $n . '</a> ';
        }
        echo '</p>';
    }
    kop_fornits_render_assets();
    echo '</div>';
}

function kop_fornits_render_card(array $rows, $fid, $state) {
    $pending = $state === 'pending';
    echo '<div class="kop-fn-card" data-fid="' . (int) $fid . '"><div class="kop-fn-head">';
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    $label = $fid && $pdo && function_exists('kop_facility_finder_label') ? kop_facility_finder_label($pdo, $fid) : ($fid ? '#' . $fid : 'Sent to the queues');
    $page = $fid && function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
    echo '<h2>' . $label . ($page ? ' <a class="kop-fn-small" href="' . esc_url($page) . '" target="_blank" rel="noopener">facility page</a>' : '') . '</h2></div>';
    echo '<table class="widefat kop-fn-table"><tbody>';
    foreach ($rows as $r) {
        kop_fornits_render_row($r, $pending);
    }
    echo '</tbody></table><div class="kop-fn-actions">';
    if ($pending) {
        echo '<button type="button" class="button button-primary" data-act="apply">Add checked</button> '
            . '<button type="button" class="button" data-act="reject">Skip checked</button>'
            . '<div class="kop-fn-other"><strong>Another record:</strong> ' . kop_facility_finder_field('', '', ' class="kop-fn-fid"')
            . ' <button type="button" class="button" data-act="apply" data-other="1">Add checked to that record</button></div>';
    } else {
        echo '<button type="button" class="button" data-act="undo">' . ($state === 'applied' ? 'Undo checked' : 'Put checked back to review') . '</button>';
    }
    echo ' <span class="kop-fn-result" aria-live="polite"></span></div></div>';
}

function kop_fornits_render_row(array $r, $pending) {
    $v = json_decode((string) $r['value'], true) ?: array();
    $also = json_decode((string) $r['also'], true) ?: array();
    $checked = $pending && (int) $r['preselect'];
    $post_url = kop_fornits_post_url($r['topic_id'], $r['post_n']);
    echo '<tr data-key="' . esc_attr($r['pkey']) . '"><td class="kop-fn-check"><input type="checkbox" class="kop-fn-pick"' . ($checked ? ' checked' : '') . ' aria-label="Select"></td>';
    echo '<td class="kop-fn-what">';
    if ($r['kind'] === 'link') {
        echo '<a href="' . esc_url($v['url'] ?? $r['topic_url']) . '" target="_blank" rel="noopener noreferrer nofollow"><strong>' . esc_html($r['label']) . '</strong></a>';
        if ((string) $r['categories'] !== '') {
            $imp = kop_fornits_importance_labels()[(int) $r['importance']] ?? '';
            echo '<div class="kop-fn-imp kop-fn-imp-' . (int) $r['importance'] . '">' . esc_html($imp) . '</div>';
        } else {
            echo '<div class="kop-fn-muted"><em>Not read by the AI yet.</em></div>';
        }
        echo '<div class="kop-fn-muted">Board: ' . esc_html($r['board_name']) . ' &middot; matched by ' . esc_html($r['how']) . '</div>';
    } else {
        echo '<strong>' . esc_html($r['label']) . '</strong>'
            . '<div class="kop-fn-muted">In <a href="' . esc_url($r['topic_url']) . '" target="_blank" rel="noopener noreferrer nofollow">'
                . esc_html(kop_fornits_has_headline($r) ? $r['topic_headline'] : $r['topic_title']) . '</a>'
                . (kop_fornits_has_headline($r) ? ' (thread "' . esc_html($r['topic_title']) . '")' : '') . '</div>';
    }
    kop_fornits_render_tags((string) $r['categories']);
    if ($also) {
        echo '<div class="kop-fn-warn">The same name is on other records: ' . esc_html(implode(', ', array_map(function ($a) {
            return $a['name'] . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . ' #' . (int) $a['id'];
        }, array_slice($also, 0, 5)))) . '. Check it is this one; starts unticked.</div>';
    }
    if ($pending && $r['kind'] === 'lead') {
        $default = kop_fornits_lead_target($v + array('url' => '', 'type' => 'other'));
        echo '<label class="kop-fn-muted">Goes to <select class="kop-fn-target">';
        foreach (kop_fornits_lead_targets() as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($default, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        if (!empty($v['url'])) {
            echo '<div class="kop-fn-muted">Link: <a href="' . esc_url($v['url']) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html($v['url']) . '</a></div>';
        }
    }
    if (!$pending) {
        $done = json_decode((string) $r['applied'], true) ?: array();
        if (!empty($done['reason'])) {
            echo '<div class="kop-fn-muted">' . esc_html($done['reason']) . '</div>';
        }
    }
    echo '</td><td class="kop-fn-ev">';
    if ($r['kind'] === 'link' && trim((string) $r['summary']) !== '') {
        echo '<p class="kop-fn-summary">' . esc_html($r['summary']) . '</p>';
        if (trim((string) $r['topic_summary']) !== '' && $r['topic_summary'] !== $r['summary']) {
            echo '<p class="kop-fn-muted"><strong>The thread:</strong> ' . esc_html($r['topic_summary']) . '</p>';
        }
    } elseif ($r['kind'] !== 'link' && trim((string) ($r['topic_summary'] ?? '')) !== '') {
        echo '<p class="kop-fn-muted"><strong>The thread:</strong> ' . esc_html($r['topic_summary']) . '</p>';
    }
    echo '<div class="kop-fn-muted"><a href="' . esc_url($post_url) . '" target="_blank" rel="noopener noreferrer nofollow">' . ($r['kind'] === 'link' ? 'Opening post' : 'Post ' . ((int) $r['post_n'] + 1)) . '</a> by '
        . esc_html($r['author']) . ', ' . esc_html(kop_fornits_month($r['post_date'])) . '</div>';
    if ($r['quote'] !== '') {
        if ($r['kind'] === 'link' && trim((string) $r['summary']) !== '') {
            echo '<details><summary>Opening words</summary><blockquote>' . esc_html($r['quote']) . '</blockquote></details>';
        } else {
            echo '<blockquote>' . esc_html($r['quote']) . '</blockquote>';
        }
    }
    if ($r['kind'] !== 'link' && !(int) $r['quote_found']) {
        echo '<div class="kop-fn-warn">Quote not found in the post: read the post before adding.</div>';
    }
    if ($r['kind'] !== 'link' && $r['context'] !== '') {
        echo '<details><summary>Whole post</summary><div class="kop-fn-post">' . nl2br(esc_html($r['context'])) . '</div></details>';
    }
    echo '</td></tr>';
}

function kop_fornits_render_tags($categories) {
    $cats = kop_fornits_categories();
    $tags = array_filter(explode(',', $categories), function ($c) use ($cats) { return isset($cats[$c]); });
    if ($tags) {
        echo '<div class="kop-fn-tags">';
        foreach ($tags as $c) {
            echo '<span class="kop-fn-tag">' . esc_html($cats[$c]) . '</span> ';
        }
        echo '</div>';
    }
}

function kop_fornits_render_assets() {
    $nonce = wp_create_nonce('kop_fornits');
    ?>
    <style>
        .kop-fn-how { margin-left: 20px; max-width: 900px; }
        .kop-fn-filter { margin: 8px 0 12px; }
        .kop-fn-bar { position: sticky; top: 32px; z-index: 5; background: #f0f0f1; padding: 8px 0; }
        .kop-fn-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #33A7B5; margin: 0 0 18px; padding: 10px 14px; max-width: 1400px; }
        .kop-fn-card.kop-fn-done { border-left-color: #B2E102; opacity: .75; }
        .kop-fn-head h2 { margin: 4px 0 6px; font-size: 1.25em; }
        .kop-fn-small { font-size: 12px; font-weight: 400; margin-left: 8px; }
        .kop-fn-table td { vertical-align: top; }
        .kop-fn-check { width: 28px; }
        .kop-fn-what { width: 38%; }
        .kop-fn-ev blockquote { margin: 2px 0 6px; padding-left: 8px; border-left: 3px solid #33A7B5; color: #1d2327; }
        .kop-fn-post { max-height: 320px; overflow: auto; background: #f6f7f7; padding: 8px; color: #1d2327; }
        .kop-fn-muted { color: #4A5568; font-size: 12px; }
        .kop-fn-warn { color: #9a4a00; font-size: 12px; margin-top: 2px; }
        .kop-fn-target { font-size: 12px; margin-left: 4px; }
        .kop-fn-actions { margin-top: 8px; }
        .kop-fn-other { margin: 8px 0 4px; padding: 8px; background: #f6f7f7; border: 1px solid #dcdcde; }
        .kop-fn-result.ok, .kop-fn-read-out.ok { color: #007017; }
        .kop-fn-result.err, .kop-fn-read-out.err { color: #d63638; }
        tr.kop-fn-gone td { opacity: .45; }
        .kop-fn-summary { margin: 0 0 6px; color: #1d2327; font-size: 13px; }
        .kop-fn-tags { margin-top: 4px; }
        .kop-fn-tag { display: inline-block; margin: 2px 4px 0 0; padding: 1px 7px; border-radius: 10px; background: #F2EEDF; color: #000435; font-size: 11px; }
        .kop-fn-imp { display: inline-block; margin-top: 4px; padding: 1px 7px; border-radius: 3px; font-size: 11px; font-weight: 600; background: #f0f0f1; color: #1d2327; }
        .kop-fn-imp-3 { background: #000435; color: #fff; }
        .kop-fn-imp-2 { background: #B6E3D4; color: #000435; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        function post(data) {
            var body = new URLSearchParams();
            body.append('action', 'kop_fornits_act');
            body.append('nonce', nonce);
            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
                else if (v && typeof v === 'object') Object.keys(v).forEach(function (kk) { body.append(k + '[' + kk + ']', v[kk]); });
                else if (v !== undefined && v !== null) body.append(k, v);
            });
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
        }

        function run(card, act, btn) {
            var out = card.querySelector('.kop-fn-result');
            var keys = [], targets = {};
            card.querySelectorAll('tr[data-key]').forEach(function (tr) {
                if (tr.classList.contains('kop-fn-gone') || !tr.querySelector('.kop-fn-pick').checked) return;
                keys.push(tr.dataset.key);
                var sel = tr.querySelector('.kop-fn-target');
                if (sel) targets[tr.dataset.key] = sel.value;
            });
            if (!keys.length) { out.className = 'kop-fn-result err'; out.textContent = 'Tick at least one row.'; return Promise.resolve(); }
            var data = { act: act, keys: keys, targets: targets, fid: '' };
            if (btn && btn.dataset.other) {
                var box = card.querySelector('.kop-fn-fid');
                data.fid = box && box.value ? box.value : '';
                if (!(+data.fid)) { out.className = 'kop-fn-result err'; out.textContent = 'Pick the record first.'; return Promise.resolve(); }
            }
            out.className = 'kop-fn-result'; out.textContent = 'Working...';
            return post(data).then(function (res) {
                if (!res.success) { out.className = 'kop-fn-result err'; out.textContent = res.data || 'Failed.'; return; }
                var ok = 0, problems = [];
                Object.keys(res.data.results || {}).forEach(function (k) {
                    var r = res.data.results[k];
                    var tr = card.querySelector('tr[data-key="' + k + '"]');
                    if (r.ok) { ok++; if (tr) tr.classList.add('kop-fn-gone'); }
                    else { problems.push(r.error); if (tr && !r.keep) tr.classList.add('kop-fn-gone'); }
                });
                var words = { apply: 'Added ' + ok + '.', reject: 'Skipped ' + ok + '.', undo: 'Undone: ' + ok + '.' }[act];
                var uniq = problems.filter(function (p, i) { return problems.indexOf(p) === i; });
                out.className = 'kop-fn-result ' + (uniq.length && !ok ? 'err' : 'ok');
                out.textContent = words + (uniq.length ? ' Not done (' + problems.length + '): ' + uniq.join(' ') : '');
                if (res.data.url && ok && act === 'apply') {
                    var a = document.createElement('a'); a.href = res.data.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' Open its page';
                    out.appendChild(a);
                }
                if (!card.querySelector('tr[data-key]:not(.kop-fn-gone)')) card.classList.add('kop-fn-done');
            }).catch(function () { out.className = 'kop-fn-result err'; out.textContent = 'Network error; try again.'; });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.kop-fn-card [data-act]');
            if (btn) { run(btn.closest('.kop-fn-card'), btn.dataset.act, btn); return; }
            var read = e.target.closest('.kop-fn-read');
            if (read) {
                var out = document.querySelector('.kop-fn-read-out');
                read.disabled = true; out.className = 'kop-fn-read-out'; out.textContent = 'Reading (up to a minute)...';
                post({ act: 'read_now' }).then(function (res) {
                    read.disabled = false;
                    out.className = 'kop-fn-read-out ' + (res.success ? 'ok' : 'err');
                    out.textContent = res.success ? res.data.message + ' Reload to see them.' : (res.data || 'Failed.');
                }).catch(function () { read.disabled = false; out.className = 'kop-fn-read-out err'; out.textContent = 'Network error.'; });
                return;
            }
            var chk = e.target.closest('.kop-fn-check-ai');
            if (chk) {
                var o = document.querySelector('.kop-fn-read-out');
                chk.disabled = true; o.className = 'kop-fn-read-out'; o.textContent = 'Asking each provider...';
                post({ act: 'check_ai' }).then(function (res) {
                    chk.disabled = false;
                    o.className = 'kop-fn-read-out ' + (res.success && res.data.message.indexOf('NOT WORKING') < 0 ? 'ok' : 'err');
                    o.textContent = res.success ? res.data.message : (res.data || 'Failed.');
                }).catch(function () { chk.disabled = false; o.className = 'kop-fn-read-out err'; o.textContent = 'Network error.'; });
                return;
            }
            var all = e.target.closest('.kop-fn-all');
            if (!all) return;
            var cards = Array.prototype.slice.call(document.querySelectorAll('.kop-fn-card:not(.kop-fn-done)'));
            var progress = document.querySelector('.kop-fn-progress-all');
            all.disabled = true;
            var i = 0;
            (function next() {
                if (i >= cards.length) { all.disabled = false; progress.textContent = 'Done.'; return; }
                progress.textContent = 'Card ' + (i + 1) + ' of ' + cards.length + '...';
                run(cards[i++], 'apply', null).then(next);
            })();
        });
    })();
    </script>
    <?php
}
