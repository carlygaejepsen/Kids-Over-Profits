<?php
/**
 * The /resources/ list.
 *
 * The page used to carry its links as hand-built editor content, so nothing in
 * the repo knew what it listed and a dead link could only be found by reading
 * the page. The links live here instead, grouped by what somebody arrives
 * needing, and the hub module below renders them as one card style.
 *
 * kop_resources_groups() is what the page shows everybody. It was ported from
 * the live page, so every entry in it is something the site already published.
 *
 * kop_resources_proposed() is a starter set for the three needs the page has
 * never covered: a crisis line, how to report abuse, and where a family in the
 * middle of a placement can turn. Those are rendered only for someone who can
 * edit the page, under a heading that says so, because they are proposals: the
 * site owner decides which belong and what they should say. Moving one into
 * kop_resources_groups() publishes it; deleting it from here drops it.
 *
 * Every entry takes:
 *   name     - the link text
 *   url      - where it goes; omit for a phone-only entry
 *   note     - one line on what it is or when to use it
 *   contact  - a phone number or short instruction, printed in bold
 *   page     - a slug on this site, resolved at render time instead of a URL,
 *              so an internal link cannot rot
 *   archived - true when the live site is gone and the link is a snapshot
 *   links    - extra links as label => URL, printed under the note (meeting
 *              links, a sign-up form); the main url stays the entry's name
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Page slug this list belongs to. */
if (!defined('KOP_RESOURCES_SLUG')) {
    define('KOP_RESOURCES_SLUG', 'resources');
}

/**
 * The published list, in the order it is shown.
 *
 * Ported from the /resources/ page as it stood in September 2026. Two changes
 * were made on the way: the Survivor's Guide link pointed at a /staging/ URL
 * and is now resolved from its slug, and SCIAD, which has been down since
 * November 2024, links to its snapshot rather than to nothing.
 */
function kop_resources_groups() {
    return apply_filters('kop_resources_groups', array(
        array(
            'heading' => 'Taking legal action',
            'intro'   => 'What a survivor needs to know before looking for a lawyer.',
            'entries' => array(
                array(
                    'name' => "A Survivor's Guide to Legal Action Against Troubled Teen Industry Programs",
                    'page' => 'a-survivors-guide-to-legal-action-against-troubled-teen-industry-programs',
                    'note' => 'By Shannon Saul. Statutes of limitation, what evidence matters, and what to expect. Read it here or download it.',
                ),
                array(
                    'name' => 'Five Facts About the Troubled Teen Industry',
                    'url'  => 'https://www.americanbar.org/groups/litigation/resources/newsletters/childrens-rights/five-facts-about-troubled-teen-industry/',
                    'note' => 'The American Bar Association, for a lawyer who has not worked one of these cases before.',
                ),
            ),
        ),
        array(
            'heading' => 'Survivor support',
            'intro'   => 'Places survivors talk to each other. All of them are run by survivors.',
            'entries' => array(
                array(
                    'name'    => 'Survivors Unrestrained',
                    'url'     => 'https://survivorsunrestrained.org/',
                    'contact' => 'Support groups on Zoom, Tuesdays and Thursdays at 7 PM Eastern',
                    'note'    => 'Peer support run by survivors, for survivors. Non-survivors are asked not to attend.',
                    // Same meetings the For Survivors page lists. The links there
                    // go through Facebook's click tracker; these are the plain
                    // Zoom addresses underneath.
                    'links'   => array(
                        'Tuesday Zoom link'  => 'https://zoom.us/j/91523602861?pwd=AHbDTaTFhnqdnRkdpi70mDTbrKMG0T.1',
                        'Thursday Zoom link' => 'https://zoom.us/j/95881522258?pwd=AxWkZL5HfkQqOxJtmbcmhVxbVNUEAX.1',
                    ),
                ),
                array(
                    'name' => 'The TTI Survivor Community',
                    'url'  => 'https://www.facebook.com/groups/forsurvivorsbysurvivors/',
                    'note' => 'Facebook group, for survivors by survivors.',
                ),
                array(
                    'name' => 'Survivorland',
                    'url'  => 'https://www.facebook.com/groups/ttisurvivorland',
                    'note' => 'Facebook group.',
                ),
                array(
                    'name' => 'WWASP Survivors',
                    'url'  => 'https://www.facebook.com/groups/wwaspsurvivors',
                    'note' => 'Facebook group for survivors of the WWASP programs.',
                ),
                array(
                    'name' => 'Survivors Unrestrained group',
                    'url'  => 'https://www.facebook.com/groups/958743149118428',
                    'note' => 'Facebook group.',
                ),
                array(
                    'name' => 'r/troubledteens',
                    'url'  => 'https://www.reddit.com/r/troubledteens/',
                    'note' => 'The subreddit. Most of the program research on this site started there.',
                ),
            ),
        ),
        array(
            'heading' => 'Advocacy organisations',
            'intro'   => 'Groups working on legislation, oversight and public pressure.',
            'entries' => array(
                array(
                    'name' => 'Unsilenced',
                    'url'  => 'https://unsilenced.org',
                    'note' => 'Litigation support, policy work and survivor advocacy.',
                ),
                array(
                    'name' => 'Breaking Code Silence',
                    'url'  => 'https://www.breakingcodesilence.org',
                    'note' => 'Research, awareness campaigns and survivor storytelling.',
                ),
                array(
                    'name' => 'Institutional Child Abuse Prevention and Advocacy (ICAPA) Network',
                    'url'  => 'https://icapanetwork.org/',
                    'note' => 'Coalition work across institutional settings, not only the teen industry.',
                ),
                array(
                    'name' => 'Project Silent No More',
                    'url'  => 'https://silentnomoretti.wixsite.com/my-site',
                    'note' => 'Survivor-led advocacy.',
                ),
                array(
                    'name' => 'New Beginnings Girls Academy Survivors',
                    'url'  => 'https://www.newbeginningsgirlsacademy.com/',
                    'note' => 'Survivors of one program, organised.',
                ),
            ),
        ),
        array(
            'heading' => 'Research and reading',
            'intro'   => 'Where the documented history of the industry is kept.',
            'entries' => array(
                array(
                    'name' => 'Reports, studies and records',
                    'page' => 'researchreports',
                    'note' => 'The library on this site: government reports, academic studies and hearings, with the programs each one names.',
                ),
                array(
                    'name' => 'Troubled Teens Wiki',
                    'url'  => 'https://www.reddit.com/r/troubledteens/wiki/index/',
                    'note' => 'Program-by-program entries written and sourced by survivors.',
                ),
                array(
                    'name' => 'Surviving Straight Inc.',
                    'url'  => 'https://survivingstraightinc.com/',
                    'note' => 'The record of Straight, Inc. and the programs that came out of it.',
                ),
                array(
                    'name' => 'WWASP Survivors',
                    'url'  => 'https://wwaspsurvivors.com/',
                    'note' => 'Documents and testimony from the WWASP network.',
                ),
                array(
                    'name' => 'SCIAD',
                    'url'  => 'https://web.archive.org/web/20221007171605/https://www.sciad.net/',
                    'note' => 'The Survivors of Institutional Abuse database. The site has been down since November 2024; this is the last snapshot.',
                    'archived' => true,
                ),
            ),
        ),
        array(
            'heading' => 'Art, film and memoir',
            'intro'   => 'Survivor work that reaches people a report does not.',
            'entries' => array(
                array(
                    'name' => 'Joe vs Elan School',
                    'url'  => 'http://elan.school',
                    'note' => 'Joe Nobody\'s webcomic about the Elan School, drawn from his own placement.',
                ),
                array(
                    'name' => 'The Program: Cons, Cults and Kidnapping',
                    'url'  => 'https://www.netflix.com/tudum/articles/the-program-cons-cults-kidnapping-release-date-trailer-news',
                    'note' => "Katherine Kubler's documentary series about the Academy at Ivy Ridge.",
                ),
            ),
        ),
        array(
            'heading' => 'Survivors of individual programs',
            'intro'   => 'Accounts kept by the survivors of one program. Useful if you are researching that program, or looking for the people who were there with you.',
            'entries' => array(
                array('name' => 'Elevations RTC Survivors', 'url' => 'https://www.instagram.com/elevationsrtc/', 'note' => 'Instagram.'),
                array('name' => 'Spring Ridge Academy Survivors', 'url' => 'https://www.instagram.com/breakingcodesilencesra/', 'note' => 'Instagram.'),
                array('name' => 'Maple Lake Academy Survivors', 'url' => 'https://www.instagram.com/maple_lake_academy_survivors', 'note' => 'Instagram.'),
                array('name' => 'La Europa Academy Survivors', 'url' => 'https://www.instagram.com/laeuropaacademy', 'note' => 'Instagram.'),
                array('name' => 'Montana Academy Survivors', 'url' => 'https://www.instagram.com/montana.academy.survivors', 'note' => 'Instagram.'),
                array('name' => 'Cedar Ridge and Makana Leadership Academy Survivors', 'url' => 'https://www.instagram.com/makanacedarridgesurvivors/', 'note' => 'Instagram.'),
                array('name' => 'Catalyst RTC Survivors', 'url' => 'https://www.instagram.com/catalystrtc', 'note' => 'Instagram.'),
                array('name' => 'Re-Creation Retreat Past Peers', 'url' => 'https://www.instagram.com/pastpeers', 'note' => 'Instagram, and also on Facebook.'),
                array('name' => 'Re-Creation Retreat Past Peers on Facebook', 'url' => 'https://www.facebook.com/profile.php?id=61568768994728', 'note' => 'Facebook.'),
                array('name' => 'Positive Peer Cult', 'url' => 'https://www.facebook.com/people/Positivepeercult/61582626375371/', 'note' => 'Facebook.'),
                array('name' => '@uwunisom', 'url' => 'https://www.tiktok.com/@uwunisom', 'note' => 'TikTok.'),
                array('name' => '@whatkyrakept', 'url' => 'https://www.tiktok.com/@whatkyrakept', 'note' => 'TikTok.'),
                array('name' => '@trailseasoning', 'url' => 'https://www.tiktok.com/@trailseasoning', 'note' => 'TikTok.'),
                array('name' => '@this.is.me.surviving2', 'url' => 'https://www.tiktok.com/@this.is.me.surviving2', 'note' => 'TikTok.'),
                array('name' => '@exposterchild', 'url' => 'https://www.tiktok.com/@exposterchild', 'note' => 'TikTok.'),
                array('name' => '@positivepeercult_', 'url' => 'https://www.tiktok.com/@positivepeercult_', 'note' => 'TikTok.'),
            ),
        ),
        array(
            'heading' => 'Petitions',
            'intro'   => 'Open campaigns you can sign.',
            'entries' => array(
                array(
                    'name' => 'Investigate the death of Taylor Goodridge at Diamond Ranch Academy',
                    'url'  => 'https://www.change.org/p/justice-for-taylor-goodridge-ag-garland-investigate-her-death-at-diamond-ranch-academy',
                    'note' => 'Addressed to the U.S. Attorney General.',
                ),
            ),
        ),
    ));
}

/**
 * Proposed entries, shown only to someone who can edit the page.
 *
 * The three needs the page has never answered. Everything here is a national
 * United States service, because the page has no international list yet; that
 * is one of the decisions waiting. Check every number before publishing one:
 * a wrong crisis number is worse than no number.
 */
function kop_resources_proposed() {
    return apply_filters('kop_resources_proposed', array(
        array(
            'heading' => 'If you need help right now',
            'intro'   => 'Free, confidential and open around the clock.',
            'entries' => array(
                array(
                    'name'    => '988 Suicide and Crisis Lifeline',
                    'url'     => 'https://988lifeline.org/',
                    'contact' => 'Call or text 988',
                    'note'    => 'For anyone in crisis, not only someone who is suicidal.',
                ),
                array(
                    'name'    => 'Crisis Text Line',
                    'url'     => 'https://www.crisistextline.org/',
                    'contact' => 'Text HOME to 741741',
                    'note'    => 'Trained counsellors by text, if a call is not safe or possible.',
                ),
                array(
                    'name'    => 'The Trevor Project',
                    'url'     => 'https://www.thetrevorproject.org/get-help/',
                    'contact' => 'Call 1-866-488-7386, or text START to 678-678',
                    'note'    => 'Crisis support for LGBTQ young people.',
                ),
                array(
                    'name'    => 'Trans Lifeline',
                    'url'     => 'https://translifeline.org/',
                    'contact' => 'Call 1-877-565-8860',
                    'note'    => 'Peer support run by trans people. It does not call the police on a caller.',
                ),
                array(
                    'name'    => 'National Runaway Safeline',
                    'url'     => 'https://www.1800runaway.org/',
                    'contact' => 'Call or text 1-800-786-2929',
                    'note'    => 'For a young person who has run, is thinking about it, or has been thrown out.',
                ),
                array(
                    'name'    => 'RAINN National Sexual Assault Hotline',
                    'url'     => 'https://hotline.rainn.org/',
                    'contact' => 'Call 1-800-656-4673',
                    'note'    => 'Sexual assault support, including assault that happened years ago.',
                ),
            ),
        ),
        array(
            'heading' => 'Reporting abuse in a program',
            'intro'   => 'A report can go to more than one place at once, and usually should.',
            'entries' => array(
                array(
                    'name'    => 'Childhelp National Child Abuse Hotline',
                    'url'     => 'https://www.childhelphotline.org/',
                    'contact' => 'Call or text 1-800-422-4453',
                    'note'    => 'Will talk through what to report and where, including anonymously.',
                ),
                array(
                    'name' => 'Your state child protection line',
                    'url'  => 'https://www.childwelfare.gov/topics/responding/reporting/how/',
                    'note' => 'The federal directory of state reporting numbers. Report to the state the program is in, not the state you live in.',
                ),
                array(
                    'name' => 'The state licensing agency',
                    'page' => 'inspection-reports',
                    'note' => 'The agency that inspects the program is the one that can suspend its licence. This page lists the state trackers and what each agency publishes.',
                ),
                array(
                    'name' => 'Your state Protection and Advocacy agency',
                    'url'  => 'https://www.ndrn.org/about/ndrn-member-agencies/',
                    'note' => 'The P&A network has legal authority to investigate abuse of disabled people in institutions, which covers most of these programs.',
                ),
                array(
                    'name' => 'Tell this project',
                    'page' => 'tti-data-submission',
                    'note' => 'Anonymous. It does not reach any authority, but it goes into the public record of the program.',
                ),
            ),
        ),
        array(
            'heading' => 'For families considering or in a placement',
            'intro'   => 'What to read before signing, and what to do if your child is already there.',
            'entries' => array(
                array(
                    'name' => 'Look the program up first',
                    'page' => 'location-index',
                    'note' => 'Every program this project has a record of, by place: who owns it, what it has been called, its inspections, lawsuits and deaths.',
                ),
                array(
                    'name' => 'Families',
                    'page' => 'families',
                    'note' => 'The questions to ask a program, and what the answers usually mean.',
                ),
                array(
                    'name' => 'Unsilenced: for parents',
                    'url'  => 'https://unsilenced.org/',
                    'note' => 'Advocates who talk to parents who have already placed a child and want them out.',
                ),
            ),
        ),
    ));
}

/**
 * Resolve one entry to a URL, or '' when it has none (a phone-only entry, or
 * an internal page that does not exist on this install).
 */
function kop_resources_entry_url($entry) {
    if (!empty($entry['page'])) {
        $page = get_page_by_path($entry['page']);
        return $page ? (string) get_permalink($page) : '';
    }
    return !empty($entry['url']) ? (string) $entry['url'] : '';
}

/** Render one group's card. */
function kop_resources_render_group($group) {
    $entries = array();
    foreach ($group['entries'] as $entry) {
        $url = kop_resources_entry_url($entry);
        // An entry that is only an internal link to a page this install does
        // not have would be a dead card, so it is dropped.
        if ($url === '' && empty($entry['contact'])) {
            continue;
        }
        $entry['resolved_url'] = $url;
        $entries[] = $entry;
    }
    if (!$entries) {
        return;
    }
    ?>
    <div class="kop-res-group">
        <h3 class="kop-res-h"><?php echo esc_html($group['heading']); ?></h3>
        <?php if (!empty($group['intro'])) : ?>
            <p class="kop-res-intro"><?php echo esc_html($group['intro']); ?></p>
        <?php endif; ?>
        <ul class="kop-res-list">
            <?php foreach ($entries as $entry) : ?>
                <li class="kop-res-item">
                    <?php if ($entry['resolved_url'] !== '') : ?>
                        <a class="kop-res-name" href="<?php echo esc_url($entry['resolved_url']); ?>"<?php
                            echo empty($entry['page']) ? ' target="_blank" rel="noopener"' : '';
                        ?>><?php echo esc_html($entry['name']); ?></a>
                    <?php else : ?>
                        <span class="kop-res-name"><?php echo esc_html($entry['name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['contact'])) : ?>
                        <span class="kop-res-contact"><?php echo esc_html($entry['contact']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['archived'])) : ?>
                        <span class="kop-res-archived">Archived copy</span>
                    <?php endif; ?>
                    <?php if (!empty($entry['note'])) : ?>
                        <span class="kop-res-note"><?php echo esc_html($entry['note']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['links']) && is_array($entry['links'])) : ?>
                        <span class="kop-res-links">
                            <?php foreach ($entry['links'] as $link_label => $link_url) : ?>
                                <a href="<?php echo esc_url($link_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($link_label); ?></a>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/**
 * Hub module for /resources/: the published groups, then the proposed ones for
 * an editor. Registered on 'kop_hub_modules' the same way the research library
 * registers itself.
 */
function kop_hub_module_resources() {
    $groups = kop_resources_groups();
    if (!$groups) {
        return;
    }
    $proposed = current_user_can('edit_pages') ? kop_resources_proposed() : array();
    ?>
    <section class="kop-hub-module kop-resources" aria-label="Resources">
        <h2 class="kop-hub-h">Where to turn
            <span class="kop-hub-count"><?php
                echo (int) array_sum(array_map(function ($group) { return count($group['entries']); }, $groups));
            ?> resources</span>
        </h2>
        <div class="kop-res-groups">
            <?php foreach ($groups as $group) { kop_resources_render_group($group); } ?>
        </div>

        <?php if ($proposed) : ?>
            <div class="kop-res-review">
                <h2 class="kop-hub-h">Proposed, not published yet <span class="kop-hub-count">editors only</span></h2>
                <p class="kop-res-intro">
                    Nobody else can see this block. These are the three needs the page has never covered.
                    Check every number, then move the entries you want into <code>kop_resources_groups()</code>
                    in <code>inc/resources-list.php</code>, and delete the rest.
                </p>
                <div class="kop-res-groups">
                    <?php foreach ($proposed as $group) { kop_resources_render_group($group); } ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

add_filter('kop_hub_modules', 'kop_resources_register_hub_module');
function kop_resources_register_hub_module($modules) {
    $modules[KOP_RESOURCES_SLUG] = 'kop_hub_module_resources';
    return $modules;
}

/**
 * Drop the hand-built link groups from the page's own content.
 *
 * Every link they hold is in kop_resources_groups() now, so leaving the blocks
 * in would print the whole page twice. The blocks themselves stay in the
 * editor untouched: delete them there and this filter becomes a no-op. The
 * same approach as kop_research_strip_legacy_grid(), and for the same reason.
 *
 * Runs at priority 8, before do_blocks() at 9, so the markup still parses.
 */
function kop_resources_strip_legacy_lists($content) {
    if (!is_singular('page') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (get_post_field('post_name', get_the_ID()) !== KOP_RESOURCES_SLUG) {
        return $content;
    }
    if (strpos($content, '<!-- wp:group') === false) {
        return $content;
    }

    $kept = array();
    foreach (parse_blocks($content) as $block) {
        if (isset($block['blockName']) && $block['blockName'] === 'core/group') {
            continue;
        }
        $kept[] = $block;
    }
    return serialize_blocks($kept);
}
add_filter('the_content', 'kop_resources_strip_legacy_lists', 8);
