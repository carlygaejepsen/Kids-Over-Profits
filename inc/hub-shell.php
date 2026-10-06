<?php
/**
 * The frame every hub page (templates/page-hub.php) is assembled in, and the
 * per-hub settings that fill it.
 *
 * A hub is read top to bottom in this order:
 *
 *   1. Orientation   breadcrumb, H1, standfirst, the primary action
 *   2. Editorial     the page's own editor content (a hub can turn it off
 *                    when its routes below say the same thing)
 *   3. Live module   kop_hub_module_for(): the data behind this hub
 *   4. Reading       the articles filed under this hub in
 *                    kop_article_parents(), with a line on each
 *   5. Contribute    where a reader can add to what the hub tracks
 *   6. Footer        updated date, edit link, share buttons
 *
 * Nothing here is required: a hub with no entry in kop_hub_config() gets the
 * orientation, its editor content, its module if it has one, and the footer,
 * which is what every hub printed before.
 *
 * Two plugins write into the content of every page: Easy Table of Contents
 * puts a contents box above the first heading and AddToAny a row of share
 * buttons under the last paragraph. On a hub the first is a box listing two
 * or three headings the reader can already see, and the second lands between
 * the editor text and the live module. The table of contents is switched off
 * on hubs and the share buttons move to the footer.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page slug => settings. Every key is optional.
 *
 *   standfirst     One sentence under the H1 saying what belongs in the hub.
 *                  An excerpt written in the editor wins over this.
 *   actions        array of links (see kop_hub_link()); the first is drawn as
 *                  the primary button, the rest as secondary ones.
 *   content        false to leave the editor content out, for a hub whose
 *                  module carries the same text and links. Default true.
 *   reading        Heading for the list of articles filed under the hub
 *                  (kop_article_children()); leave out for a hub whose editor
 *                  content already links them, or they print twice.
 *   reading_notes  slug => one line for an article with no excerpt.
 *   positions      array('heading' => ..., 'links' => array of links): policy
 *                  positions published elsewhere, listed under the reading.
 *   outside        array('heading', 'intro', 'links'): pages about institutional
 *                  abuse OUTSIDE the troubled teen industry, in their own
 *                  boxed section after the hub's own lists, with the heading
 *                  and intro saying so, so a reader never takes them for TTI.
 *   contribute    array('heading' => ..., 'links' => array of links).
 *   updated        callback returning an updated_at value for this hub.
 *
 * A link is array('label' => ..., 'note' => optional line, and one of
 * 'slug' => a page slug, 'template' => a child template file with 'path' as
 * the fallback, or 'url'). A link whose page cannot be found is dropped.
 */
function kop_hub_config($slug) {
    $hubs = apply_filters('kop_hub_config', array(
        'law-policy' => array(
            'standfirst' => 'The lawsuits brought against troubled teen programs and the bills written to regulate them, tracked as they move through the courts and legislatures.',
            // The editor text is two paragraphs, one per directory, each
            // ending "Read more here."; the module's two columns now open
            // with those paragraphs and link the same two directories.
            'content'    => false,
            'updated'    => 'kop_hub_law_policy_updated_at',
            'reading'    => 'Background and case analyses',
            'reading_notes' => array(
                'overview'            => 'What the troubled teen industry is, and why it needs oversight.',
                'sicaa'               => 'The Stop Institutional Child Abuse Act and the federal work group on youth residential programs it sets up.',
                'challenges-in-maine' => 'LD 1745, the 2025 Maine law meant to keep youth treatment centers from closing.',
                'doe-v-trails-motions' => 'The motions filed in the Doe v. Trails Carolina cases.',
                'jane-june-doe-v-trails-carolina-et-al-summary' => 'The complaint, filed in the Western District of North Carolina, Asheville Division.',
                'john-doe-v-trails-complaint-summary-defendant-information' => 'The complaint and every defendant it names.',
            ),
            'positions' => array(
                'heading' => 'Positions from legal and medical bodies',
                'links'   => array(
                    array('label' => 'ABA Resolution 605: the Troubled Teen Industry and Institutional Child Abuse',
                          'url'   => 'https://www.americanbar.org/groups/litigation/resources/newsletters/childrens-rights/resolution-605/',
                          'note'  => 'The American Bar Association, 2023. Backs the Stop Institutional Child Abuse Act and similar state and local laws, and rules against restraint, strip searches, isolation and forced silence in youth residential programs.'),
                ),
            ),
            'contribute' => array(
                'heading' => 'Help keep this current',
                'links'   => array(
                    array('label' => 'Submit a lawsuit', 'template' => 'page-submit-lawsuit.php', 'path' => '/submit-lawsuit/',
                          'note' => 'A case against a program or its staff that is not listed yet.'),
                    array('label' => 'Submit legislation', 'template' => 'page-submit-legislation.php', 'path' => '/submit-legislation/',
                          'note' => 'A bill, a hearing or a vote we should be following.'),
                    array('label' => 'Where to report abuse', 'template' => 'page-report-abuse.php', 'path' => '/report-abuse/',
                          'note' => 'The agencies that take reports about a program or a therapist, state by state.'),
                ),
            ),
        ),

        // The editor text links four of the five articles under History, so
        // there is no reading list; the one it leaves out is the action.
        'history' => array(
            'standfirst' => 'How the troubled teen industry grew out of centuries of institutions for controlling children, and how survivors and families have fought back.',
            'actions'    => array(
                array('label' => 'Start with 1919 to 1969', 'slug' => 'tti-history-part-one'),
                array('label' => 'In loving memory', 'slug' => 'in-loving-memory'),
                array('label' => 'The companies behind the programs', 'url' => home_url('/operator/')),
                array('label' => 'Network map', 'slug' => 'network-map'),
            ),
            // Set apart and labelled: these schools are not troubled teen programs.
            'outside' => array(
                'heading' => 'Institutional abuse outside the troubled teen industry',
                'intro'   => 'These are not troubled teen industry programs. They are listed here because their history is sometimes set beside the troubled teen industry\'s; each page says where the two meet and where they do not.',
                'links'   => array(
                    array('label' => 'Indian boarding schools and residential schools', 'slug' => 'indian-boarding-schools',
                          'note'  => 'Government and church schools in the United States and Canada that took Indigenous children from their families. Not part of the troubled teen industry; the page is mostly directions to Indigenous-led organizations.'),
                ),
            ),
        ),

        'survivors' => array(
            'standfirst' => 'For people who went through a troubled teen program: support groups, advice written by other survivors, legal options and places to tell your story.',
            'actions'    => array(
                array('label' => 'Find support', 'slug' => 'resources'),
                // The page was retired for the PDF it wrapped (inc/redirects.php).
                array('label' => 'Guide to legal action', 'url' => 'https://kidsoverprofits.org/wp-content/uploads/2024/08/Survivors-Guide-to-Legal-Action-Against-Troubled-Teen-Industry-Programs.pdf'),
                array('label' => 'Where to report abuse', 'template' => 'page-report-abuse.php', 'path' => '/report-abuse/'),
            ),
            'reading'       => 'Written for survivors',
            'reading_notes' => array(
                'common-survivor-experiences' => 'What many survivors feel after leaving a program: confusion, mistrust and the fear of being sent back.',
                'spiritual-abuse'             => 'How programs misuse religious belief and authority, and the religious trauma that follows.',
                'survivor-resources-nature'   => 'Getting back to green spaces after a wilderness program, on your own terms.',
            ),
            'contribute' => array(
                'heading' => 'Share what you know',
                'links'   => array(
                    array('label' => 'Upload documents anonymously', 'slug' => 'anon-submit',
                          'note' => 'Records, letters or photos from a program. Encrypted before they leave your browser.'),
                    array('label' => 'Tell us about a program', 'template' => 'page-data.php', 'path' => '/tti-data-submission/',
                          'note' => 'Add or correct what we hold on a facility.'),
                ),
            ),
        ),

        // The editor text links the article summaries itself; no reading list.
        'researchreports' => array(
            'standfirst' => 'Government audits, academic studies and investigative reports on youth residential treatment, with our notes on what they found.',
            'actions'    => array(
                array('label' => 'Browse the document archive', 'slug' => 'document-archive'),
                array('label' => 'Severe inspection reports', 'template' => 'page-severe-reports.php', 'path' => '/severe-reports/'),
            ),
            'contribute' => array(
                'heading' => 'Have a report we should read?',
                'links'   => array(
                    array('label' => 'Upload it anonymously', 'slug' => 'anon-submit',
                          'note' => 'Encrypted before it leaves your browser.'),
                ),
            ),
        ),

        'families' => array(
            'standfirst' => 'How to support someone who survived a troubled teen program: what to say, what to do, and what to avoid.',
            'actions'    => array(
                array('label' => 'Common survivor experiences', 'slug' => 'common-survivor-experiences'),
                array('label' => 'Resources', 'slug' => 'resources'),
            ),
        ),

        'where-are-the-kids' => array(
            'standfirst' => 'Troubled teen programs state by state and country by country, with the inspection reports each state publishes.',
            'actions'    => array(
                array('label' => 'Facility directory', 'template' => 'page-tti-program-index.php', 'path' => '/tti-program-index/'),
                array('label' => 'Parent companies', 'url' => home_url('/operator/')),
                array('label' => 'Network map', 'slug' => 'network-map'),
                array('label' => 'Severe inspection reports', 'template' => 'page-severe-reports.php', 'path' => '/severe-reports/'),
            ),
            'contribute' => array(
                'heading' => 'Know a program we are missing?',
                'links'   => array(
                    array('label' => 'Tell us about a program', 'template' => 'page-data.php', 'path' => '/tti-data-submission/',
                          'note' => 'Add a facility or correct what we hold on one.'),
                    array('label' => 'Where to report abuse', 'template' => 'page-report-abuse.php', 'path' => '/report-abuse/',
                          'note' => 'The agencies that take reports about a program, state by state.'),
                ),
            ),
        ),

        'advocates' => array(
            'standfirst' => 'Research, history and program data for anyone organizing against the troubled teen industry.',
            'actions'    => array(
                array('label' => 'Law & Policy', 'slug' => 'law-policy'),
                array('label' => 'Network map', 'slug' => 'network-map'),
                array('label' => 'Glossary', 'template' => 'page-glossary.php', 'path' => '/glossary/'),
            ),
            'contribute' => array(
                'heading' => 'Work with us',
                'links'   => array(
                    array('label' => 'Volunteer', 'slug' => 'volunteer',
                          'note' => 'Research, data entry, writing and outreach.'),
                    array('label' => 'Submit legislation', 'template' => 'page-submit-legislation.php', 'path' => '/submit-legislation/',
                          'note' => 'A bill, a hearing or a vote we should be following.'),
                ),
            ),
        ),

        'journalists' => array(
            'standfirst' => 'Sources and guidance for reporting on the troubled teen industry and interviewing survivors.',
            'actions'    => array(
                array('label' => 'Research & Reports', 'slug' => 'researchreports'),
                array('label' => 'Lawsuits', 'template' => 'page-lawsuits.php', 'path' => '/lawsuits/'),
                array('label' => 'News feed', 'template' => 'page-news-feed.php', 'path' => '/tti-news-feed/'),
            ),
        ),

        'volunteer' => array(
            'standfirst' => 'Help us document the troubled teen industry: submit facility data, documents, news, lawsuits and legislation, or join the team.',
            'actions'    => array(
                array('label' => 'Submit facility data', 'template' => 'page-data.php', 'path' => '/tti-data-submission/'),
                array('label' => 'Upload documents anonymously', 'slug' => 'anon-submit'),
            ),
        ),

        // How the industry manages its reputation (2026-10-02). The editor text
        // carries the findings and links every article itself, so no reading list.
        'reputation-management' => array(
            'standfirst' => 'How troubled teen programs control what a worried parent finds online, from instructions written for AI chatbots to directories run by their own marketer.',
            'actions'    => array(
                array('label' => 'Start with the AI instructions', 'slug' => 'telling-ai-what-to-say'),
                array('label' => 'Inspection reports', 'slug' => 'inspection-reports'),
                array('label' => 'Network map', 'slug' => 'network-map'),
            ),
            'contribute' => array(
                'heading' => 'Send us what you have',
                'links'   => array(
                    array('label' => 'Contact us', 'slug' => 'contact',
                          'note' => 'A review request, a contract clause, a takedown notice or an invoice from a reputation firm.'),
                    array('label' => 'Anonymous document submission', 'slug' => 'anon-submit',
                          'note' => 'Upload documents without giving your name; what you send is encrypted.'),
                ),
            ),
        ),

        // Multi-part investigations are pages filed under this hub in
        // kop_article_parents(); the single posts list below them (inc/hub-posts.php).
        'investigatory-spotlight' => array(
            'reading' => 'Investigations in several parts',
        ),

        'resources' => array(
            'standfirst' => 'Crisis lines, survivor support groups, advocacy organizations and further reading, grouped by what you need.',
            'actions'    => array(
                array('label' => 'Where to report abuse', 'template' => 'page-report-abuse.php', 'path' => '/report-abuse/'),
                array('label' => 'For survivors', 'slug' => 'survivors'),
            ),
        ),
    ));
    return isset($hubs[$slug]) ? $hubs[$slug] : array();
}

/** Newest public record update shown by the Law & Policy module. */
function kop_hub_law_policy_updated_at() {
    $pdo = function_exists('kop_hub_pdo') ? kop_hub_pdo() : null;
    if (!$pdo instanceof PDO) {
        return '';
    }
    try {
        $stmt = $pdo->query(
            "SELECT MAX(updated_at) FROM (
                SELECT updated_at FROM lawsuits WHERE publication_status IN ('approved','published')
                UNION ALL
                SELECT updated_at FROM legislation WHERE publication_status IN ('approved','published')
            ) AS public_records"
        );
        return (string) ($stmt ? $stmt->fetchColumn() : '');
    } catch (Throwable $e) {
        return '';
    }
}

/** URL of the published page using a child template, or the fallback path. */
function kop_hub_page_url($template, $fallback) {
    $url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template($template) : '';
    return $url ? $url : home_url($fallback);
}

/** A config link resolved to array('label', 'url', 'note'), or null. */
function kop_hub_link($link) {
    if (empty($link['label'])) {
        return null;
    }
    $url = '';
    if (!empty($link['url'])) {
        $url = $link['url'];
    } elseif (!empty($link['slug'])) {
        $page = function_exists('kop_article_page') ? kop_article_page($link['slug']) : null;
        $url  = $page ? $page['url'] : '';
    } elseif (!empty($link['template'])) {
        $url = kop_hub_page_url($link['template'], isset($link['path']) ? $link['path'] : '/');
    }
    if ($url === '') {
        return null;
    }
    return array(
        'label' => $link['label'],
        'url'   => $url,
        'note'  => isset($link['note']) ? $link['note'] : '',
    );
}

/** The resolved links of a list, missing pages dropped. */
function kop_hub_links($links) {
    return array_values(array_filter(array_map('kop_hub_link', (array) $links)));
}

/** Primary and secondary buttons under the standfirst. */
function kop_hub_actions($slug) {
    $config  = kop_hub_config($slug);
    $actions = kop_hub_links(isset($config['actions']) ? $config['actions'] : array());
    if (!$actions) {
        return;
    }
    echo '<div class="kop-hub-actions">';
    foreach ($actions as $i => $a) {
        printf(
            '<a class="kop-hub-action%s" href="%s">%s</a>',
            $i === 0 ? ' kop-hub-action--primary' : '',
            esc_url($a['url']),
            esc_html($a['label'])
        );
    }
    echo '</div>';
}

/**
 * The articles filed under this hub, one level down, each with its excerpt
 * or the hub's note on it. Index pages are listed but not the pages under
 * them: those are one click further and listed on the index page itself.
 */
function kop_hub_reading($slug) {
    $config = kop_hub_config($slug);
    if (empty($config['reading']) || !function_exists('kop_article_children')) {
        return;
    }
    $notes = isset($config['reading_notes']) ? $config['reading_notes'] : array();
    $items = array();
    foreach (kop_article_children($slug) as $child) {
        $page = kop_article_page($child);
        if (!$page) {
            continue;
        }
        $post    = get_page_by_path($child);
        $excerpt = ($post && has_excerpt($post)) ? get_the_excerpt($post) : '';
        $items[] = array(
            'id'    => $post ? (int) $post->ID : 0,
            'title' => $page['title'],
            'url'   => $page['url'],
            'note'  => $excerpt !== '' ? $excerpt : (isset($notes[$child]) ? $notes[$child] : ''),
            // The article's featured image, drawn beside its link; an
            // article without one keeps the plain text row.
            'thumb' => $post ? get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy', 'alt' => '')) : '',
        );
    }
    if (!$items) {
        return;
    }
    ?>
    <section class="kop-hub-reading" aria-labelledby="kop-hub-reading-h">
        <h2 class="kop-hub-h" id="kop-hub-reading-h"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('cfg:hub:' . $slug, 'hub settings') : ''; ?>><?php echo esc_html($config['reading']); ?></h2>
        <ul class="kop-hub-reading-list">
            <?php foreach ($items as $item) : ?>
                <li<?php echo $item['id'] ? function_exists('kop_ie_attr') ? kop_ie_attr('post:' . $item['id'] . ':page', $item['title']) : '' : ''; ?>>
                    <div class="kop-hub-reading-item<?php echo $item['thumb'] ? ' has-thumb' : ''; ?>">
                        <?php if ($item['thumb']) : ?>
                            <a class="kop-hub-reading-thumb" href="<?php echo esc_url($item['url']); ?>" tabindex="-1" aria-hidden="true"><?php echo $item['thumb']; // phpcs:ignore WordPress.Security.EscapeOutput -- core markup ?></a>
                        <?php endif; ?>
                        <div class="kop-hub-reading-body">
                            <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a>
                            <?php if ($item['note'] !== '') : ?>
                                <p><?php echo esc_html($item['note']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

/** Policy positions published elsewhere, drawn like the reading list. */
function kop_hub_positions($slug) {
    $config = kop_hub_config($slug);
    if (empty($config['positions']['links'])) {
        return;
    }
    $links = kop_hub_links($config['positions']['links']);
    if (!$links) {
        return;
    }
    $heading = !empty($config['positions']['heading']) ? $config['positions']['heading'] : 'Policy positions';
    ?>
    <section class="kop-hub-reading kop-hub-positions" aria-labelledby="kop-hub-positions-h"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('cfg:hub:' . $slug, 'hub settings') : ''; ?>>
        <h2 class="kop-hub-h" id="kop-hub-positions-h"><?php echo esc_html($heading); ?></h2>
        <ul class="kop-hub-reading-list kop-hub-positions-list">
            <?php foreach ($links as $link) : ?>
                <li>
                    <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                    <?php if ($link['note'] !== '') : ?>
                        <p><?php echo esc_html($link['note']); ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

/**
 * Pages about institutional abuse outside the troubled teen industry, boxed
 * apart from the hub's own lists, the heading and intro saying they are not
 * TTI programs.
 */
function kop_hub_outside($slug) {
    $config = kop_hub_config($slug);
    if (empty($config['outside']['links'])) {
        return;
    }
    $links = kop_hub_links($config['outside']['links']);
    if (!$links) {
        return;
    }
    $heading = !empty($config['outside']['heading']) ? $config['outside']['heading'] : 'Outside the troubled teen industry';
    $intro = isset($config['outside']['intro']) ? trim((string) $config['outside']['intro']) : '';
    ?>
    <section class="kop-hub-reading kop-hub-outside" aria-labelledby="kop-hub-outside-h"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('cfg:hub:' . $slug, 'hub settings') : ''; ?>>
        <p class="kop-hub-outside-tag">Not the troubled teen industry</p>
        <h2 class="kop-hub-h" id="kop-hub-outside-h"><?php echo esc_html($heading); ?></h2>
        <?php if ($intro !== '') : ?>
            <p class="kop-hub-outside-intro"><?php echo esc_html($intro); ?></p>
        <?php endif; ?>
        <ul class="kop-hub-reading-list kop-hub-outside-list">
            <?php foreach ($links as $link) : ?>
                <li>
                    <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                    <?php if ($link['note'] !== '') : ?>
                        <p><?php echo esc_html($link['note']); ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

/** Where a reader can add to what this hub tracks. */
function kop_hub_contribute($slug) {
    $config = kop_hub_config($slug);
    if (empty($config['contribute']['links'])) {
        return;
    }
    $links = kop_hub_links($config['contribute']['links']);
    if (!$links) {
        return;
    }
    $heading = !empty($config['contribute']['heading']) ? $config['contribute']['heading'] : 'Get involved';
    ?>
    <section class="kop-hub-contribute" aria-labelledby="kop-hub-contribute-h"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('cfg:hub:' . $slug, 'hub settings') : ''; ?>>
        <h2 class="kop-hub-h" id="kop-hub-contribute-h"><?php echo esc_html($heading); ?></h2>
        <ul>
            <?php foreach ($links as $link) : ?>
                <li>
                    <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                    <?php if ($link['note'] !== '') : ?>
                        <span><?php echo esc_html($link['note']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

/** True while a hub page is being served. */
function kop_hub_is_current() {
    return is_singular('page') && get_page_template_slug() === 'templates/page-hub.php';
}

/**
 * The contents box lists headings the reader can already see; not on hubs.
 * Easy Table of Contents (2.0.88) runs the legacy hook, then discards its
 * answer and returns the new hook's, so the new one is the one that counts;
 * both are hooked in case a later version reads the legacy one again.
 */
add_filter('ez_toc_maybe_apply_the_content_filter', 'kop_hub_no_toc', 99);
add_filter('eztoc_maybe_apply_the_content_filter', 'kop_hub_no_toc', 99);
function kop_hub_no_toc($apply) {
    return kop_hub_is_current() ? false : $apply;
}

/**
 * The editor content with AddToAny's automatic share row left off; the
 * footer prints the buttons once instead (kop_hub_share()). AddToAny's own
 * "sharing disabled" switch would also dequeue the script its share menu
 * needs, so the content filter is lifted for this one call.
 */
function kop_hub_the_content() {
    $priority = has_filter('the_content', 'A2A_SHARE_SAVE_add_to_content');
    if ($priority !== false) {
        remove_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $priority);
    }
    the_content();
    if ($priority !== false) {
        add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $priority);
    }
}

/** Share buttons for the footer, when AddToAny is active. */
function kop_hub_share() {
    if (shortcode_exists('addtoany')) {
        echo '<div class="kop-hub-share">' . do_shortcode('[addtoany]') . '</div>';
    }
}
