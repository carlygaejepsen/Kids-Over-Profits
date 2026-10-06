<?php
/**
 * Wiki update drafts (docs/PLAN.md 3.12 step 5): the reviewed additions for
 * each r/troubledteens entry, applied to KOP's copy of the entry on approval.
 *
 * scripts/wiki-drafts.py export writes js/data/reddit-wiki/update-drafts.json:
 * per entry the drafters' ops (append_to_section, add_section,
 * set_header_years; each with its text, source and Opus's note), the past
 * tense pairs for a closed program and the record's conflicts. Only the ops
 * travel: kop_wiki_drafts_build() applies them to the entry's text as it is
 * now (the same rules as the script's apply()), so a draft whose entry was
 * edited since is still reviewable; a past-tense line no longer there is left
 * out and the card says so.
 *
 * The review inbox source inc/review-inbox/wiki-updates.php shows each draft.
 * The reviewer may edit or empty any added line (option kop_wiki_draft_edits)
 * and leave out the past tense. Approve writes the new text into the column
 * the entry is read from (kop_wiki_upd_markdown()); the old text and
 * updated_at are kept (option kop_wiki_draft_old_<id>, not autoloaded) for an
 * exact Undo. Approved entries wait on Ready for Reddit (the whole text with a
 * Copy button: admin-post.php?action=kop_wiki_draft_view&id=) until marked as
 * pasted. State per entry: option kop_wiki_draft_state.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wiki-updates.php';

/** id => draft, from js/data/reddit-wiki/update-drafts.json. */
function kop_wiki_drafts_all() {
    static $all = null;
    if ($all !== null) return $all;
    $file = get_stylesheet_directory() . '/js/data/reddit-wiki/update-drafts.json';
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    $all = array();
    foreach ((array) ($data['entries'] ?? array()) as $id => $d) {
        if (is_array($d)) $all[(int) $id] = $d;
    }
    return $all;
}

function kop_wiki_drafts_state() {
    $s = get_option('kop_wiki_draft_state', array());
    return is_array($s) ? $s : array();
}

function kop_wiki_drafts_set_state($id, $value) {
    $s = kop_wiki_drafts_state();
    if ($value === null) unset($s[(int) $id]);
    else $s[(int) $id] = $value;
    update_option('kop_wiki_draft_state', $s, false);
}

/** The reviewer's changes: id => {op id: text ('' = left out), '_tense' => '' to leave out the past tense, '1' to keep it}. */
function kop_wiki_drafts_edits($id = null) {
    $e = get_option('kop_wiki_draft_edits', array());
    $e = is_array($e) ? $e : array();
    return $id === null ? $e : (array) ($e[(int) $id] ?? array());
}

function kop_wiki_drafts_save_edits($id, array $edits) {
    $all = kop_wiki_drafts_edits();
    if ($edits) $all[(int) $id] = $edits;
    else unset($all[(int) $id]);
    update_option('kop_wiki_draft_edits', $all, false);
}

/* ---- Applying a draft (scripts/wiki-drafts.py apply() and apply_tense()) ---- */

function kop_wiki_drafts_lines($md) {
    return explode("\n", rtrim(str_replace("\r\n", "\n", (string) $md), "\n"));
}

function kop_wiki_drafts_norm($heading) {
    return strtolower(trim(preg_replace('/[#*\s:]+/u', ' ', (string) $heading)));
}

/** Reddit's own footer ("Last revised by", "## Page title"): nothing goes after it. */
function kop_wiki_drafts_footer(array $lines) {
    foreach ($lines as $i => $l) {
        if (preg_match('/^\s*(last revised by\b|#{1,6}\s*page title\s*$)/i', $l)) return $i;
    }
    return count($lines);
}

/** [[start, end, key]] per heading above the footer. */
function kop_wiki_drafts_sections(array $lines) {
    $stop = kop_wiki_drafts_footer($lines);
    $heads = array();
    for ($i = 0; $i < $stop; $i++) {
        if (preg_match('/^\s*#{1,6}\s/', $lines[$i])) $heads[] = $i;
    }
    $out = array();
    foreach ($heads as $n => $i) {
        $out[] = array($i, $heads[$n + 1] ?? $stop, kop_wiki_drafts_norm($lines[$i]));
    }
    return $out;
}

/** Where to add at the end of a section: before its trailing blank lines and separator. */
function kop_wiki_drafts_body_end(array $lines, $start, $end) {
    $j = $end;
    while ($j > $start + 1 && (trim($lines[$j - 1]) === '' || preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $lines[$j - 1]))) $j--;
    return $j;
}

function kop_wiki_drafts_find(array $lines, $name) {
    $want = kop_wiki_drafts_norm($name);
    $secs = kop_wiki_drafts_sections($lines);
    foreach ($secs as $s) if ($s[2] === $want) return $s;
    foreach ($secs as $s) {
        if ($want !== '' && (strpos($s[2], $want) !== false || strpos($want, $s[2]) !== false) && strlen($s[2]) > 3) return $s;
    }
    return null;
}

/**
 * KOP's copies came from markdown_output/, converted back from the rendered
 * Reddit pages, not Reddit's own source: undo what the conversion broke so the
 * text pastes onto Reddit as it rendered there. Same rules as
 * scripts/wiki-drafts.py reddit_format(): bold opened with a space ("** Name**"),
 * a lost bullet ("***Lower Form:**" -> "* **Lower Form:**"), the header's
 * missing space before "(years)", and Reddit's page footer ("Last revised by",
 * "## Page title") dropped with the separators before it.
 */
function kop_wiki_drafts_reddit_format($md) {
    $lines = kop_wiki_drafts_lines($md);
    $lines = array_slice($lines, 0, kop_wiki_drafts_footer($lines));
    while ($lines && (trim(end($lines)) === '' || preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', end($lines)))) array_pop($lines);
    foreach ($lines as $n => $line) {
        $line = preg_replace('/^\*\*\*(?=\S)/u', '* **', $line);
        $line = preg_replace('/(^|[\s(\[])\*\* +(?=\S)/u', '$1**', $line);
        if ($n === 0) $line = preg_replace('/\*\*\(/', '** (', $line, 1);
        $lines[$n] = $line;
    }
    return implode("\n", $lines) . "\n";
}

/** Past-tense pairs: each old line must be there once. -> [md, changed ids, errors]. */
function kop_wiki_drafts_apply_tense($md, array $pairs) {
    $lines = kop_wiki_drafts_lines($md);
    $changed = array();
    $errors = array();
    foreach ($pairs as $p) {
        $hits = array_keys($lines, (string) $p['old'], true);
        if (count($hits) !== 1) {
            $errors[] = $p['id'] . ': ' . (!$hits ? 'the line is no longer in the entry' : 'the line is in the entry more than once');
            continue;
        }
        $lines[$hits[0]] = (string) $p['new'];
        $changed[] = $p['id'];
    }
    return array(implode("\n", $lines) . "\n", $changed, $errors);
}

/** Additions. -> [md, applied ids, errors]. */
function kop_wiki_drafts_apply($md, array $ops) {
    $lines = kop_wiki_drafts_lines($md);
    $applied = array();
    $errors = array();
    foreach ($ops as $op) {
        if (($op['verdict'] ?? '') === 'dropped') continue;
        $kind = (string) ($op['op'] ?? '');
        $text = trim((string) ($op['text'] ?? ''), "\n");
        $new = $text !== '' ? explode("\n", $text) : array();
        $id = (string) ($op['id'] ?? '');
        if ($kind === 'set_header_years') {
            $first = $lines[0];
            $years = (string) $op['years'];
            $changed = preg_replace_callback('/(\*\*\s*)\(([^)]*)\)/', function ($m) use ($years) { return $m[1] . '(' . $years . ')'; }, $first, 1);
            if ($changed === $first && strpos($first, '(') === false) {
                $changed = preg_replace_callback('/^(#+\s*\*\*.*?\*\*)/', function ($m) use ($years) { return $m[1] . '(' . $years . ')'; }, $first, 1);
            }
            if ($changed === $first) {
                $errors[] = $id . ': the header has no years to set';
                continue;
            }
            $lines[0] = $changed;
        } elseif ($kind === 'append_to_section') {
            $s = kop_wiki_drafts_find($lines, (string) ($op['section'] ?? ''));
            if (!$s) {
                $errors[] = $id . ': the entry has no section "' . ($op['section'] ?? '') . '"';
                continue;
            }
            $at = kop_wiki_drafts_body_end($lines, $s[0], $s[1]);
            array_splice($lines, $at, 0, array_merge(array(''), $new));
        } elseif ($kind === 'add_section') {
            $s = kop_wiki_drafts_find($lines, (string) ($op['after_section'] ?? ''));
            $at = $s ? kop_wiki_drafts_body_end($lines, $s[0], $s[1]) : kop_wiki_drafts_body_end($lines, 0, kop_wiki_drafts_footer($lines));
            array_splice($lines, $at, 0, array_merge(array('', '---', '', trim((string) $op['heading']), ''), $new));
        } else {
            $errors[] = $id . ': unknown change "' . $kind . '"';
            continue;
        }
        $applied[] = $id;
    }
    return array(implode("\n", $lines) . "\n", $applied, $errors);
}

/**
 * Every line of $base is still in $draft, in order (the header may change only
 * its years). -> [problems, added line numbers in $draft (0-based)].
 */
function kop_wiki_drafts_check($base, $draft, $header_changed) {
    $o = kop_wiki_drafts_lines($base);
    $d = kop_wiki_drafts_lines($draft);
    $problems = array();
    $from = 0;
    if ($header_changed) {
        $strip = function ($l) { return preg_replace('/\([^)]*\)/', '()', $l, 1); };
        if ($strip($o[0]) !== $strip($d[0])) $problems[] = 'the header changed beyond its years';
        $o = array_slice($o, 1);
        $from = 1;
    }
    $j = 0;
    $added = array();
    for ($i = $from; $i < count($d); $i++) {
        if ($j < count($o) && $d[$i] === $o[$j]) $j++;
        else $added[] = $i;
    }
    if ($j < count($o)) $problems[] = 'line ' . ($j + 1 + $from) . ' of the entry would be lost or changed: ' . mb_substr($o[$j], 0, 80);
    return array($problems, $added);
}

/**
 * The draft for an entry as it would be approved now.
 * -> [md, base (the entry now), added (draft line numbers), tensed (draft line
 *     numbers), applied op ids, tense ids, errors, stale (the entry changed since drafting)]
 */
function kop_wiki_drafts_build($id, $current_md, $edits = null) {
    $d = kop_wiki_drafts_all()[(int) $id] ?? null;
    if (!$d) throw new RuntimeException('There is no draft for this entry.');
    $edits = $edits === null ? kop_wiki_drafts_edits($id) : $edits;
    $base = implode("\n", kop_wiki_drafts_lines($current_md)) . "\n";
    $stale = sha1(rtrim(str_replace("\r\n", "\n", (string) $current_md), "\n")) !== (string) ($d['base_sha1'] ?? '');
    $tense = (string) ($edits['_tense'] ?? '1') !== '1' ? array() : (array) ($d['tense'] ?? array());
    // Corrections of existing lines the owner asked for (a misspelled name) go first, then the past tense.
    $fixes = (array) ($d['fixes'] ?? array());
    list($fixed_md, $fix_ids, $fix_errors) = kop_wiki_drafts_apply_tense($base, $fixes);
    list($tensed_md, $tense_ids, $errors) = kop_wiki_drafts_apply_tense($fixed_md, $tense);
    $errors = array_merge($fix_errors, $errors);
    $tense = array_merge($fixes, $tense);
    $tense_ids = array_merge($fix_ids, $tense_ids);
    $ops = array();
    foreach ((array) ($d['ops'] ?? array()) as $op) {
        if (isset($edits[$op['id']])) {
            if (trim((string) $edits[$op['id']]) === '') continue;
            if (($op['op'] ?? '') === 'set_header_years') $op['years'] = trim((string) $edits[$op['id']]);
            else $op['text'] = str_replace("\r\n", "\n", (string) $edits[$op['id']]);
        }
        $ops[] = $op;
    }
    list($md, $applied, $errs) = kop_wiki_drafts_apply($tensed_md, $ops);
    $errors = array_merge($errors, $errs);
    $header = (bool) array_filter($ops, function ($o) use ($applied) { return ($o['op'] ?? '') === 'set_header_years' && in_array($o['id'], $applied, true); });
    list($problems, $added) = kop_wiki_drafts_check($tensed_md, $md, $header);
    if ($header && !in_array(0, $added, true)) array_unshift($added, 0);
    $new_lines = kop_wiki_drafts_lines($md);
    $tensed = array();
    foreach ($tense as $p) {
        if (!in_array($p['id'], $tense_ids, true)) continue;
        $at = array_search((string) $p['new'], $new_lines, true);
        if ($at !== false) $tensed[] = $at;
    }
    $count = count(array_filter($added, function ($i) use ($new_lines) {
        return trim($new_lines[$i]) !== '' && !preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $new_lines[$i]);
    }));
    // Line numbers above stay right: the format only drops the footer at the end.
    $md = kop_wiki_drafts_reddit_format($md);
    $last = count(kop_wiki_drafts_lines($md)) - 1;
    $added = array_values(array_filter($added, function ($i) use ($last) { return $i <= $last; }));
    return array(
        'md' => $md, 'base' => $base, 'added' => $added, 'count' => $count, 'tensed' => $tensed,
        'applied' => $applied, 'tense_ids' => $tense_ids, 'errors' => array_merge($errors, $problems), 'problems' => $problems, 'stale' => $stale,
    );
}

/* ---- Approve, undo ------------------------------------------------------------ */

/** The entry's row and the column its text is read from. */
function kop_wiki_drafts_row(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT id, program_name, city_state, years_active, original_markdown, generated_markdown, submitted_by,
            submission_notes, updated_at, json_data FROM wiki_submissions WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('That wiki entry is gone.');
    $md = kop_wiki_upd_markdown($row);
    $col = trim((string) $row['original_markdown']) !== '' && $md === trim((string) $row['original_markdown']) ? 'original_markdown' : 'generated_markdown';
    $row['markdown'] = (string) $row[$col];
    $row['column'] = $col;
    return $row;
}

function kop_wiki_drafts_approve(PDO $pdo, $id, $by) {
    $id = (int) $id;
    $row = kop_wiki_drafts_row($pdo, $id);
    $b = kop_wiki_drafts_build($id, $row['markdown']);
    if ($b['problems']) throw new RuntimeException('Not approved: ' . implode('; ', $b['problems']) . '.');
    if (!$b['count'] && !$b['tensed']) throw new RuntimeException('Nothing is left to add: every line was left out. Set it aside instead.');
    update_option('kop_wiki_draft_old_' . $id, array('column' => $row['column'], 'text' => $row['markdown'], 'updated_at' => $row['updated_at']), false);
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE wiki_submissions SET ' . $row['column'] . ' = ?, updated_at = ? WHERE id = ?')->execute(array($b['md'], $now, $id));
    kop_wiki_drafts_set_state($id, array('view' => 'ready', 'by' => (string) $by, 'at' => gmdate('c'), 'sha1' => sha1($b['md']),
        'lines' => count($b['added']), 'tensed' => count($b['tensed'])));
    return $b;
}

/** Puts back the text the entry had, if nobody changed it since. */
function kop_wiki_drafts_undo(PDO $pdo, $id) {
    $id = (int) $id;
    $state = kop_wiki_drafts_state()[$id] ?? null;
    $old = get_option('kop_wiki_draft_old_' . $id, null);
    if (!$state || !is_array($old)) {
        kop_wiki_drafts_set_state($id, null);
        return;
    }
    $row = kop_wiki_drafts_row($pdo, $id);
    if (sha1((string) $row[$old['column']]) !== (string) $state['sha1']) {
        throw new RuntimeException('The entry was edited after it was approved, so Undo would lose that edit. Change it in the wiki editor instead.');
    }
    $pdo->prepare('UPDATE wiki_submissions SET ' . $old['column'] . ' = ?, updated_at = ? WHERE id = ?')->execute(array($old['text'], $old['updated_at'], $id));
    delete_option('kop_wiki_draft_old_' . $id);
    kop_wiki_drafts_set_state($id, null);
}

/** The entry's Reddit address: its page, else the page of its title (js/data/reddit-wiki/page-urls.json). */
function kop_wiki_drafts_reddit_url(array $entry) {
    if (!empty($entry['page'])) return 'https://www.reddit.com/r/troubledteens/wiki/' . trim($entry['page'], '/') . '/';
    static $titles = null;
    if ($titles === null) {
        $f = get_stylesheet_directory() . '/js/data/reddit-wiki/page-urls.json';
        $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
        $titles = (array) ($j['titles'] ?? array());
    }
    return (string) ($titles[trim((string) ($entry['program_name'] ?? ''))] ?? '');
}

/** Reddit's edit screen for a page address. */
function kop_wiki_drafts_reddit_edit_url($url) {
    return preg_match('#^(https://www\.reddit\.com/r/troubledteens/wiki/)(.+?)/?$#', (string) $url, $m) ? $m[1] . 'edit/' . $m[2] : '';
}

function kop_wiki_drafts_view_url($id) {
    return admin_url('admin-post.php?action=kop_wiki_draft_view&id=' . (int) $id);
}

/* ---- The whole entry, changes marked, with Copy ---------------------------- */

add_action('admin_post_kop_wiki_draft_view', 'kop_wiki_drafts_view_page');

function kop_wiki_drafts_view_page() {
    if (!current_user_can('manage_options')) wp_die('Admins only.', 403);
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $pdo = kop_wiki_upd_pdo();
        $row = kop_wiki_drafts_row($pdo, $id);
        $state = kop_wiki_drafts_state()[$id] ?? null;
        if ($state && in_array($state['view'], array('ready', 'posted'), true)) {
            // Approved: the entry's text is the update; mark what it added against the text it replaced.
            $old = get_option('kop_wiki_draft_old_' . $id, array());
            $md = $row['markdown'];
            list(, $added) = kop_wiki_drafts_check((string) ($old['text'] ?? ''), $md, true);
            $b = array('md' => $md, 'added' => $added, 'tensed' => array(), 'errors' => array(), 'stale' => false);
        } else {
            $b = kop_wiki_drafts_build($id, $row['markdown']);
        }
    } catch (Throwable $e) {
        wp_die(esc_html($e->getMessage()), 404);
    }
    $entries = kop_wiki_upd_entries($pdo);
    $reddit = kop_wiki_drafts_reddit_url($entries[$id] ?? $row);
    $edit = kop_wiki_drafts_reddit_edit_url($reddit);
    $added = array_flip($b['added']);
    $tensed = array_flip($b['tensed']);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc_html($row['program_name']) . ': wiki update</title>';
    echo '<link rel="stylesheet" href="' . esc_url(get_stylesheet_directory_uri() . '/css/colors.css') . '"><style>
        body{margin:0;padding:16px;background:var(--kop-sand,#F2EEDF);color:var(--kop-midnight-blue,#000435);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
        main{max-width:960px;margin:0 auto;background:#fff;border:1px solid #d7d1bd;border-radius:8px;padding:16px 20px}
        h1{font-size:1.25rem;margin:0 0 .25rem;color:var(--kop-midnight-blue,#000435)}
        .bar{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin:.5rem 0 1rem}
        .bar a{color:var(--kop-navy-blue,#000080)}
        button.copy{background:var(--kop-teal-fill,#24757F);color:#fff;border:0;border-radius:6px;padding:.45rem .9rem;font:inherit;cursor:pointer}
        button.copy:hover,button.copy:focus,button.copy:active{background:var(--kop-midnight-blue,#000435);color:#fff}
        .key{font-size:.85rem;color:var(--kop-text-muted,#4A5568)}
        .key span{padding:0 .3rem;border-radius:3px}
        .md{white-space:pre-wrap;overflow-wrap:anywhere;font:13px/1.55 ui-monospace,Consolas,monospace;border-top:1px solid #e5e0cc;padding-top:.75rem}
        .md div{min-height:1.55em;padding:0 .35rem}
        .add{background:#e3f4ee;border-left:3px solid var(--kop-chartreuse-ink,#5C7401)}
        .tense{background:var(--kop-soft-pastel-yellow,#FFF5CB);border-left:3px solid var(--kop-orange-ink,#A3570D)}
        .warn{background:var(--kop-soft-pastel-yellow,#FFF5CB);border:1px solid var(--kop-orange-ink,#A3570D);border-radius:6px;padding:.5rem .75rem;margin:.5rem 0}
        textarea{position:absolute;left:-9999px}
    </style></head><body><main>';
    echo '<h1>' . esc_html($row['program_name']) . '</h1>';
    echo '<div class="bar"><button type="button" class="copy" id="copy">Copy the whole entry</button><span id="copied" class="key" role="status"></span>';
    if ($reddit !== '') echo '<a href="' . esc_url($reddit) . '" target="_blank" rel="noopener">The page on Reddit</a>';
    if ($edit !== '') echo '<a href="' . esc_url($edit) . '" target="_blank" rel="noopener">Edit it on Reddit</a>';
    echo '</div>';
    echo '<p class="key"><span class="add">Green</span> lines are added' . ($tensed ? '; <span class="tense">yellow</span> lines are put in the past tense' : '') . '. Copy takes the whole text, ready to paste over the page on Reddit.</p>';
    if (!empty($b['stale'])) echo '<p class="warn">The entry was edited after this draft was made. The additions were placed by section heading; check that they sit where they belong.</p>';
    foreach ((array) $b['errors'] as $err) echo '<p class="warn">' . esc_html($err) . '</p>';
    echo '<div class="md">';
    foreach (kop_wiki_drafts_lines($b['md']) as $i => $line) {
        $cls = isset($added[$i]) ? ' class="add"' : (isset($tensed[$i]) ? ' class="tense"' : '');
        echo '<div' . $cls . '>' . esc_html($line) . '</div>';
    }
    echo '</div><textarea id="text" readonly aria-hidden="true" tabindex="-1">' . esc_textarea($b['md']) . '</textarea></main>';
    echo '<script>document.getElementById("copy").addEventListener("click",function(){var t=document.getElementById("text"),s=document.getElementById("copied");'
        . 'function done(ok){s.textContent=ok?"Copied.":"Could not copy: select the text and copy it by hand.";}'
        . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).then(function(){done(true);},function(){t.select();done(document.execCommand("copy"));});}'
        . 'else{t.select();done(document.execCommand("copy"));}});</script></body></html>';
    exit;
}
