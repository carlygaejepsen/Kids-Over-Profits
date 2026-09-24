<?php
/**
 * The TTI glossary: the language of the Troubled Teen Industry, from program
 * handbooks, staff manuals, state records and survivor accounts.
 *
 * The data is js/data/glossary/glossary.json, built from glossary.md by
 * scripts/build-glossary.js. Edits saved in wp-admin (Glossary Editor,
 * inc/glossary-editor.php) are applied over glossary.md and rebuilt on the
 * server until they are committed; kop_glossary_data() returns that version
 * when there is one.
 *
 * Rendered server-side, every entry, so the page is readable, searchable with
 * the browser's own find, and linkable (/glossary/#bust) with no JavaScript.
 * ?program=<slug> and ?q=<words> filter on the server too; js/glossary.js
 * only makes the same two filters instant.
 *
 * Entry points:
 *   kop_glossary_data()                  the whole decoded file, or null
 *   kop_glossary_render_page($program, $query, $base_url)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_GLOSSARY_SLUG')) {
    define('KOP_GLOSSARY_SLUG', 'glossary');
}

/** Path to the built glossary. */
function kop_glossary_data_path() {
    return get_stylesheet_directory() . '/js/data/glossary/glossary.json';
}

/** The decoded glossary, or null when the file is missing or unreadable. */
function kop_glossary_data() {
    static $data = false;
    if ($data !== false) {
        return $data;
    }
    $data = null;
    $live = function_exists('kop_glossary_live_data') ? kop_glossary_live_data() : null;
    if ($live) {
        $data = $live;
        return $data;
    }
    $path = kop_glossary_data_path();
    if (!is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (is_array($decoded) && !empty($decoded['sections'])) {
        $data = $decoded;
    }
    return $data;
}

/**
 * The glossary's light markdown as HTML: **Term** is a link to that entry
 * (the build has already checked it names one), *words* is emphasis. Escaped
 * first, so nothing in the file reaches the page as markup.
 *
 * $ref_base is prefixed to every #anchor: empty on the full page, the page
 * URL on a filtered one, where the entry referred to may not be shown.
 */
function kop_glossary_inline($text, $ref_base = '') {
    $data = kop_glossary_data();
    $refs = $data && !empty($data['refs']) ? $data['refs'] : array();
    /* A section's own name (the intro names them) links to the section. */
    $sections = array();
    foreach (($data['sections'] ?? array()) as $section) {
        $sections[$section['title']] = $section['id'];
    }
    $html = esc_html((string) $text);
    $html = preg_replace_callback('/\*\*(.+?)\*\*/u', function ($m) use ($refs, $sections, $ref_base) {
        $name = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $key  = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (isset($refs[$key])) {
            return '<a class="kop-gl-ref" href="' . esc_url($ref_base . '#' . $refs[$key]) . '">' . $m[1] . '</a>';
        }
        if (isset($sections[$name])) {
            return '<a class="kop-gl-ref" href="' . esc_url($ref_base . '#' . $sections[$name]) . '">' . $m[1] . '</a>';
        }
        return '<strong>' . $m[1] . '</strong>';
    }, $html);
    $html = preg_replace('/\*(.+?)\*/u', '<em>$1</em>', $html);
    return $html;
}

/** Plain lower-case text of an entry, for the search filter. */
function kop_glossary_entry_haystack($entry) {
    $parts = array($entry['term'], $entry['note'], $entry['text']);
    foreach ($entry['aka'] as $aka) {
        $parts[] = $aka;
    }
    foreach (array_merge($entry['used'], $entry['reported']) as $tag) {
        $parts[] = $tag['program'];
        if (!empty($tag['note'])) {
            $parts[] = $tag['note'];
        }
    }
    $text = str_replace('*', '', implode(' ', $parts));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/** Program slugs an entry is tagged with. */
function kop_glossary_entry_programs($entry) {
    $slugs = array();
    foreach (array_merge($entry['used'], $entry['reported']) as $tag) {
        $slugs[$tag['slug']] = true;
    }
    return array_keys($slugs);
}

/** Does the entry pass the filters? Every word of $query must appear. */
function kop_glossary_entry_matches($entry, $program, $words) {
    if ($program !== '' && !in_array($program, kop_glossary_entry_programs($entry), true)) {
        return false;
    }
    if ($words) {
        $hay = kop_glossary_entry_haystack($entry);
        foreach ($words as $word) {
            if (strpos($hay, $word) === false) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Glossary entries for the site search (inc/global-search.php, search.php):
 * a phrase found in the term or an aka ranks first, then one found in the
 * definition. Each item: term, url (the entry's anchor on /glossary/), text
 * (the definition, markdown stripped) and section.
 */
function kop_glossary_search($phrase, $limit = 5) {
    $data = kop_glossary_data();
    $phrase = trim((string) $phrase);
    if (!$data || $phrase === '') {
        return array();
    }
    $lower = function ($s) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $s, 'UTF-8') : strtolower((string) $s);
    };
    $needle = $lower($phrase);
    $page = get_page_by_path(KOP_GLOSSARY_SLUG);
    $base = $page ? get_permalink($page) : home_url('/' . KOP_GLOSSARY_SLUG . '/');

    $hits = array();
    $walk = function ($node, $section) use (&$walk, &$hits, $needle, $lower, $base) {
        foreach ($node['entries'] as $entry) {
            $names = $lower($entry['term'] . ' ' . implode(' ', $entry['aka']));
            $rank = $lower($entry['term']) === $needle ? 0
                : (strpos($names, $needle) !== false ? 1
                : (strpos(kop_glossary_entry_haystack($entry), $needle) !== false ? 2 : -1));
            if ($rank < 0) {
                continue;
            }
            $hits[] = array(
                'rank'    => $rank,
                'term'    => $entry['term'] . ($entry['note'] !== '' ? ' (' . $entry['note'] . ')' : ''),
                'url'     => $base . '#' . $entry['id'],
                'text'    => str_replace('*', '', $entry['text']),
                'section' => $section,
            );
        }
        foreach ($node['groups'] as $group) {
            $walk($group, $group['title']);
        }
    };
    foreach ($data['sections'] as $section) {
        $walk($section, $section['title']);
    }
    usort($hits, function ($a, $b) {
        return $a['rank'] - $b['rank'] ?: strcasecmp($a['term'], $b['term']);
    });
    return array_slice($hits, 0, $limit);
}

/**
 * Glossary program names spelled differently from the facility record they
 * mean. Only unambiguous ones: a name that covers several places (CEDU,
 * Straight, Teen Challenge, Vista) is left without a link rather than
 * pointed at one of them.
 */
function kop_glossary_program_aliases() {
    return apply_filters('kop_glossary_program_aliases', array(
        'Allendale'                  => 'Allendale Association',
        "\u{00C9}lan"                    => 'Elan School',
        'Island View'                => 'Island View RTC',
        'Judge Rotenberg Center'     => 'Judge Rotenberg Educational Center',
        'Maple Lake Academy'         => "Maple Lake Academy, LLC \u{2013} Girls\u{2019} Home",
        'Second Nature'              => 'Second Nature Wilderness Program',
        "Shodair Children's Hospital" => 'Shodair RTC',
        'Spring Creek Lodge'         => 'Spring Creek Lodge Academy',
        'Triangle Cross Ranch'       => 'Triangle Cross Boys Ranch',
        'Trinity Teen Solutions'     => 'Trinity Teen Solutions, Inc.',
    ));
}

/**
 * Glossary names for a company, as its record in kop_operators is named, for
 * the names the operator lookup would not find on its own.
 */
function kop_glossary_company_aliases() {
    return apply_filters('kop_glossary_company_aliases', array(
        'WWASP programs' => 'WWASPS',
    ));
}

/**
 * Program group headings about a company whose name is not itself a
 * glossary program ("Three Springs programs"). Heading => company name.
 */
function kop_glossary_group_companies() {
    return apply_filters('kop_glossary_group_companies', array(
        'WWASP and affiliated programs' => 'WWASPS',
        'Three Springs programs'        => 'Three Springs Inc.',
    ));
}

/**
 * Program slug => profile URL (the hand-written Facility Profile when there
 * is one, else the generated /facility/ page), for every glossary program
 * whose record has a page. Programs without one are simply absent.
 */
function kop_glossary_program_urls() {
    static $urls = null;
    if ($urls !== null) {
        return $urls;
    }
    $urls = array();
    $data = kop_glossary_data();
    if (!$data || !function_exists('kop_facility_page_url_for_name')) {
        return $urls;
    }
    $aliases = kop_glossary_program_aliases();
    foreach ($data['programs'] as $program) {
        $name = isset($aliases[$program['name']]) ? $aliases[$program['name']] : $program['name'];
        $url = (string) kop_facility_page_url_for_name($name);
        // A company rather than one facility ("CEDU", "WWASP programs"):
        // its parent company page (inc/operator-pages.php).
        if ($url === '' && function_exists('kop_operator_page_url_for_name')) {
            $companies = kop_glossary_company_aliases();
            $url = (string) kop_operator_page_url_for_name(isset($companies[$program['name']]) ? $companies[$program['name']] : $program['name']);
        }
        if ($url !== '') {
            $urls[$program['slug']] = $url;
        }
    }
    return $urls;
}

/** The program list's display name for a slug, or ''. */
function kop_glossary_program_name($slug) {
    $data = kop_glossary_data();
    foreach (($data['programs'] ?? array()) as $program) {
        if ($program['slug'] === $slug) {
            return $program['name'];
        }
    }
    return '';
}

/** Every entry in the file, in reading order. */
function kop_glossary_all_entries($data) {
    $out = array();
    $walk = function ($node) use (&$walk, &$out) {
        foreach ($node['entries'] as $entry) {
            $out[] = $entry;
        }
        foreach ($node['groups'] as $group) {
            $walk($group);
        }
    };
    foreach ($data['sections'] as $section) {
        $walk($section);
    }
    return $out;
}

/** "12 terms used at Spring Ridge Academy matching "phase"". Kept in step with js/glossary.js. */
function kop_glossary_status_text($shown, $program, $query) {
    $text = $shown === 1 ? '1 term' : number_format($shown) . ' terms';
    if ($program !== '') {
        $text .= ' tagged ' . kop_glossary_program_name($program);
    }
    if ($query !== '') {
        $text .= ' matching “' . $query . '”';
    }
    return $text;
}

/**
 * One program tag. With a profile, the name opens the profile and a small
 * filter button beside it shows every term tagged with the program; without
 * one, the whole chip is the filter.
 */
function kop_glossary_render_tag($tag, $modifier, $page_url) {
    $urls = kop_glossary_program_urls();
    $filter_url = add_query_arg('program', $tag['slug'], $page_url) . '#kop-gl-results';
    $label = esc_html($tag['program']);
    if (!empty($tag['note'])) {
        $label .= ' <span class="kop-gl-tag-note">' . esc_html($tag['note']) . '</span>';
    }
    if (!isset($urls[$tag['slug']])) {
        printf(
            '<a class="kop-gl-tag kop-gl-tag-filter%s" data-program="%s" href="%s">%s</a> ',
            esc_attr($modifier),
            esc_attr($tag['slug']),
            esc_url($filter_url),
            $label
        );
        return;
    }
    printf(
        '<span class="kop-gl-tag kop-gl-tag--profile%s" data-program="%s">'
        . '<a class="kop-gl-tag-name" href="%s">%s</a>'
        . '<a class="kop-gl-tag-filter" data-program="%s" href="%s" aria-label="%s" title="%s">'
        . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M1.5 2.5h13l-5 6v5l-3-1.5v-3.5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>'
        . '</a></span> ',
        esc_attr($modifier),
        esc_attr($tag['slug']),
        esc_url($urls[$tag['slug']]),
        $label,
        esc_attr($tag['slug']),
        esc_url($filter_url),
        esc_attr('Show every term tagged ' . $tag['program']),
        esc_attr('Show every term tagged ' . $tag['program'])
    );
}

/** One entry. */
function kop_glossary_render_entry($entry, $page_url, $ref_base, $show) {
    $id = $entry['id'];
    ?>
    <div class="kop-gl-entry" id="<?php echo esc_attr($id); ?>"
         data-programs="<?php echo esc_attr(implode(' ', kop_glossary_entry_programs($entry))); ?>"
         data-search="<?php echo esc_attr(kop_glossary_entry_haystack($entry)); ?>"<?php echo $show ? '' : ' hidden'; ?>>
        <dt class="kop-gl-term">
            <dfn><?php echo esc_html($entry['term']); ?></dfn>
            <?php if ($entry['note'] !== '') : ?>
                <span class="kop-gl-qualifier">(<?php echo esc_html($entry['note']); ?>)</span>
            <?php endif; ?>
            <a class="kop-gl-anchor" href="<?php echo esc_url($ref_base . '#' . $id); ?>" aria-label="<?php echo esc_attr('Link to ' . $entry['term']); ?>">#</a>
        </dt>
        <dd class="kop-gl-def">
            <?php if (!empty($entry['aka'])) : ?>
                <p class="kop-gl-aka"><span>Also called</span> <?php echo esc_html(implode(', ', $entry['aka'])); ?></p>
            <?php endif; ?>
            <p><?php echo kop_glossary_inline($entry['text'], $ref_base); ?></p>
            <?php
            $rows = array(
                array('Used at', $entry['used'], ''),
                array('Reportedly used at', $entry['reported'], ' kop-gl-tag--reported'),
            );
            foreach ($rows as $row) :
                if (empty($row[1])) {
                    continue;
                }
                ?>
                <p class="kop-gl-tags">
                    <span class="kop-gl-tags-label"><?php echo esc_html($row[0]); ?></span>
                    <?php foreach ($row[1] as $tag) {
                        kop_glossary_render_tag($tag, $row[2], $page_url);
                    } ?>
                </p>
            <?php endforeach; ?>
            <?php /* Shown by js/glossary.js, which owns the form they open. */ ?>
            <p class="kop-gl-fb-row" hidden>
                <button type="button" class="kop-gl-fb" data-kind="used_at">My facility used this too</button>
                <button type="button" class="kop-gl-fb" data-kind="correction">Suggest a correction</button>
            </p>
        </dd>
    </div>
    <?php
}

/**
 * Entries, notes and subgroups of one section or group. Returns how many
 * entries passed the filters, so the caller can hide an empty block.
 */
function kop_glossary_render_block($node, $level, $page_url, $ref_base, $program, $words) {
    $filtered = ($program !== '' || $words);
    ob_start();
    $shown = 0;

    if (!empty($node['sources'])) {
        echo '<p class="kop-gl-sources"><span>Sources</span> ' . kop_glossary_inline($node['sources'], $ref_base) . '</p>';
    }
    foreach ($node['notes'] as $note) {
        echo '<p class="kop-gl-note"' . ($filtered ? ' hidden' : '') . '>' . kop_glossary_inline($note, $ref_base) . '</p>';
    }
    if (!empty($node['entries'])) {
        echo '<dl class="kop-gl-list">';
        foreach ($node['entries'] as $entry) {
            $show = kop_glossary_entry_matches($entry, $program, $words);
            $shown += $show ? 1 : 0;
            kop_glossary_render_entry($entry, $page_url, $ref_base, $show);
        }
        echo '</dl>';
    }
    foreach ($node['groups'] as $group) {
        $tag = 'h' . min(6, $level + 1);
        ob_start();
        $n = kop_glossary_render_block($group, $level + 1, $page_url, $ref_base, $program, $words);
        $inner = ob_get_clean();
        $shown += $n;
        printf(
            '<section class="kop-gl-group kop-gl-group--l%d" id="%s" aria-labelledby="%s-h"%s><%s class="kop-gl-group-title" id="%s-h">%s</%s>%s%s</section>',
            $level + 1,
            esc_attr('g-' . $group['id']),
            esc_attr('g-' . $group['id']),
            $n ? '' : ' hidden',
            $tag,
            esc_attr('g-' . $group['id']),
            esc_html($group['title']),
            $tag,
            kop_glossary_group_profiles($group['title']),
            $inner
        );
    }
    $html = ob_get_clean();
    echo $html;
    return $shown;
}

/**
 * "Program profile" links under a group heading, for each program with a
 * profile whose name the heading contains ("Island View and Elevations RTC"
 * links both). '' when none.
 */
function kop_glossary_group_profiles($title) {
    $data = kop_glossary_data();
    $urls = kop_glossary_program_urls();
    $links = array();
    foreach (($data['programs'] ?? array()) as $program) {
        if (isset($urls[$program['slug']]) && stripos($title, $program['name']) !== false) {
            $links[] = '<a href="' . esc_url($urls[$program['slug']]) . '">' . esc_html($program['name']) . '</a>';
        }
    }
    $companies = kop_glossary_group_companies();
    if (isset($companies[$title]) && function_exists('kop_operator_page_url_for_name')) {
        $url = kop_operator_page_url_for_name($companies[$title]);
        if ($url !== '') {
            array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html($companies[$title]) . '</a>');
        }
    }
    if (!$links) {
        return '';
    }
    $label = count($links) === 1 ? 'Profile' : 'Profiles';
    return '<p class="kop-gl-profiles"><span>' . $label . '</span> ' . implode('<span aria-hidden="true"> &middot; </span>', $links) . '</p>';
}

/** First letters of a section's entries, for the A-Z strip. */
function kop_glossary_letters($section) {
    $letters = array();
    foreach ($section['entries'] as $entry) {
        $plain = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $entry['term']) : $entry['term'];
        $first = strtoupper(substr(preg_replace('/^(the\s+)|[^A-Za-z0-9]/i', '', (string) $plain), 0, 1));
        $key = ctype_alpha($first) ? $first : '#';
        if (!isset($letters[$key])) {
            $letters[$key] = $entry['id'];
        }
    }
    return $letters;
}

/**
 * The page body under the title.
 *
 * @param string $program  a program slug from the file, or ''
 * @param string $query    free-text search, already unslashed
 * @param string $page_url the page's own URL, for filter links
 */
function kop_glossary_render_page($program, $query, $page_url) {
    $data = kop_glossary_data();
    if (!$data) {
        echo '<p class="kop-gl-empty">The glossary is being updated. Please check back shortly.</p>';
        return;
    }

    if ($program !== '' && kop_glossary_program_name($program) === '') {
        $program = '';
    }
    $query = trim(preg_replace('/\s+/', ' ', (string) $query));
    $lower = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $words = $lower === '' ? array() : explode(' ', $lower);
    $filtered = ($program !== '' || $words);
    $ref_base = $filtered ? $page_url : '';

    $shown = 0;
    foreach (kop_glossary_all_entries($data) as $entry) {
        $shown += kop_glossary_entry_matches($entry, $program, $words) ? 1 : 0;
    }
    $program_count = count($data['programs']);
    $updated = !empty($data['updated']) ? strtotime($data['updated'] . ' 12:00:00') : 0;
    ?>
    <div class="kop-gl" data-total="<?php echo (int) $data['count']; ?>"
         data-feedback="<?php echo esc_url(function_exists('rest_url') ? rest_url('kop/v1/glossary-feedback') : ''); ?>">

        <div class="kop-gl-intro">
            <?php foreach ($data['intro'] as $para) : ?>
                <p><?php echo kop_glossary_inline($para, $ref_base); ?></p>
            <?php endforeach; ?>
            <p class="kop-gl-meta">
                <?php echo esc_html(number_format((int) $data['count'])); ?> terms
                <span aria-hidden="true">&middot;</span>
                <?php echo esc_html($program_count); ?> programs
                <?php if ($updated) : ?>
                    <span aria-hidden="true">&middot;</span>
                    Updated <time datetime="<?php echo esc_attr($data['updated']); ?>"><?php echo esc_html(date_i18n('F j, Y', $updated)); ?></time>
                <?php endif; ?>
            </p>
        </div>

        <form class="kop-gl-filter" method="get" action="<?php echo esc_url($page_url); ?>" role="search" id="kop-gl-results">
            <div class="kop-gl-field kop-gl-field--search">
                <label for="kop-gl-q">Search the glossary</label>
                <input type="search" id="kop-gl-q" name="q" value="<?php echo esc_attr($query); ?>"
                       placeholder="A word, a phrase, a program" autocomplete="off">
            </div>
            <div class="kop-gl-field">
                <label for="kop-gl-program">Program</label>
                <select id="kop-gl-program" name="program">
                    <option value="">All programs</option>
                    <?php foreach ($data['programs'] as $p) : ?>
                        <option value="<?php echo esc_attr($p['slug']); ?>"<?php
                            $kop_gl_urls = kop_glossary_program_urls();
                            if (isset($kop_gl_urls[$p['slug']])) {
                                echo ' data-profile="' . esc_url($kop_gl_urls[$p['slug']]) . '"';
                            }
                            selected($program, $p['slug']); ?>>
                            <?php echo esc_html($p['name'] . ' (' . $p['count'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="kop-gl-actions">
                <button type="submit" class="kop-gl-go">Filter</button>
                <a class="kop-gl-clear" href="<?php echo esc_url($page_url); ?>"<?php echo $filtered ? '' : ' hidden'; ?>>Show all</a>
            </div>
            <p class="kop-gl-status" role="status" aria-live="polite"><?php echo esc_html($filtered ? kop_glossary_status_text($shown, $program, $query) : ''); ?></p>
            <?php $kop_gl_urls = kop_glossary_program_urls(); ?>
            <a class="kop-gl-profile-link"<?php echo ($program !== '' && isset($kop_gl_urls[$program])) ? ' href="' . esc_url($kop_gl_urls[$program]) . '"' : ' hidden'; ?>><?php
                echo esc_html($program !== '' ? 'Open the ' . kop_glossary_program_name($program) . ' profile' : '');
            ?></a>
        </form>

        <div class="kop-gl-layout">
            <nav class="kop-gl-toc" aria-label="Glossary sections">
                <details>
                    <summary>Contents</summary>
                    <ol>
                        <?php foreach ($data['sections'] as $section) : ?>
                            <li>
                                <a href="#<?php echo esc_attr($section['id']); ?>"><?php echo esc_html($section['title']); ?></a>
                                <?php if (!empty($section['groups'])) : ?>
                                    <ol>
                                        <?php foreach ($section['groups'] as $group) : ?>
                                            <li><a href="#<?php echo esc_attr('g-' . $group['id']); ?>"><?php echo esc_html($group['title']); ?></a></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
                <?php if (function_exists('kop_donate_widget')) {
                    kop_donate_widget('kop-gl-donate');
                } ?>
            </nav>

            <div class="kop-gl-main">
                <?php
                foreach ($data['sections'] as $section) :
                    ob_start();
                    $n = kop_glossary_render_block($section, 2, $page_url, $ref_base, $program, $words);
                    $inner = ob_get_clean();
                    $letters = (count($section['entries']) > 40) ? kop_glossary_letters($section) : array();
                    ?>
                    <section class="kop-gl-section" id="<?php echo esc_attr($section['id']); ?>" aria-labelledby="<?php echo esc_attr($section['id']); ?>-h"<?php echo $n ? '' : ' hidden'; ?>>
                        <h2 class="kop-gl-section-title" id="<?php echo esc_attr($section['id']); ?>-h"><?php echo esc_html($section['title']); ?></h2>
                        <?php if ($letters) : ?>
                            <nav class="kop-gl-letters" aria-label="<?php echo esc_attr($section['title'] . ' by letter'); ?>"<?php echo $filtered ? ' hidden' : ''; ?>>
                                <?php foreach ($letters as $letter => $id) : ?>
                                    <a href="#<?php echo esc_attr($id); ?>"><?php echo esc_html($letter); ?></a>
                                <?php endforeach; ?>
                            </nav>
                        <?php endif; ?>
                        <?php echo $inner; // Built from escaped parts above. ?>
                    </section>
                <?php endforeach; ?>
                <p class="kop-gl-none"<?php echo ($filtered && !$shown) ? '' : ' hidden'; ?>>No terms match. Try fewer words, or <a href="<?php echo esc_url($page_url); ?>">show the whole glossary</a>.</p>
            </div>
        </div>
    </div>
    <?php
}
