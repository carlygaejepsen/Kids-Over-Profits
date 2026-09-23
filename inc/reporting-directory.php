<?php
/**
 * The reporting directory: where somebody reports an abusive therapist or an
 * abusive program, state by state.
 *
 * The data is js/data/reporting/directory.json, built from the per-state files
 * by scripts/build-reporting-directory.js. Nothing here writes; the file is
 * the source of truth and a correction is a commit, so that every number on
 * the page can be traced to the source it came from and the day a human
 * checked it.
 *
 * Rendered server-side on purpose. Somebody arrives here needing a phone
 * number, sometimes on a school Chromebook with a filter in front of it, and
 * the page has to work when the JavaScript does not. js/report-abuse.js only
 * upgrades the state picker from a form submit to an instant switch.
 *
 * Entry points:
 *   kop_reporting_directory()            the whole decoded file, or null
 *   kop_reporting_state($state)          one state's record by name, slug or abbreviation
 *   kop_reporting_render_page($state)    the /report-abuse/ body
 *   kop_reporting_render_state_block()   the compact block a state hub embeds
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_REPORTING_SLUG')) {
    define('KOP_REPORTING_SLUG', 'report-abuse');
}

/** Path to the built directory. */
function kop_reporting_data_path() {
    return get_stylesheet_directory() . '/js/data/reporting/directory.json';
}

/**
 * The stylesheet, for pages that embed the block rather than being the page.
 *
 * page-report-abuse.php enqueues it itself, early enough. A state hub calls
 * the renderer from the middle of its body, which is far too late for
 * wp_enqueue_style to reach wp_head, so the state case is hooked here
 * instead and decided from the page template.
 */
function kop_reporting_enqueue_styles() {
    if (!is_singular('page')) {
        return;
    }
    $template = (string) get_post_meta(get_queried_object_id(), '_wp_page_template', true);
    if (basename($template) !== 'page-state.php') {
        return;
    }
    /* Nothing to style if this state has no entry. */
    $state = kop_state_slug_to_name(get_post_field('post_name', get_queried_object_id()));
    if (!$state || !kop_reporting_state($state)) {
        return;
    }
    $path = get_stylesheet_directory() . '/css/report-abuse.css';
    if (file_exists($path)) {
        wp_enqueue_style(
            'kop-report-abuse',
            get_stylesheet_directory_uri() . '/css/report-abuse.css',
            array('kop-colors'),
            filemtime($path)
        );
    }
}
add_action('wp_enqueue_scripts', 'kop_reporting_enqueue_styles', 20);

/**
 * The decoded directory, or null when the file is missing or unreadable.
 * Decoded once per request.
 */
function kop_reporting_directory() {
    static $data = false;
    if ($data !== false) {
        return $data;
    }
    $data = null;
    $path = kop_reporting_data_path();
    if (is_readable($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded) && !empty($decoded['states'])) {
            $data = $decoded;
        }
    }
    return $data;
}

/**
 * Resolve "Utah", "utah", "UT" or "ut" to that state's record.
 *
 * @return array|null
 */
function kop_reporting_state($state) {
    $directory = kop_reporting_directory();
    if (!$directory || !is_string($state) || $state === '') {
        return null;
    }
    $needle = strtolower(trim($state));
    foreach ($directory['states'] as $record) {
        if ($needle === strtolower($record['abbr'])
            || $needle === strtolower($record['state'])
            || $needle === $record['slug']) {
            return $record;
        }
    }
    return null;
}

/**
 * Deep link to one state's block on the reporting page, or '' when that state
 * is not covered or the page does not exist on this install.
 *
 * For somewhere like a facility page, which wants to point at the right state
 * without carrying the whole directory.
 */
function kop_reporting_state_url($state) {
    $record = kop_reporting_state($state);
    if (!$record) {
        return '';
    }
    $page = get_page_by_path(KOP_REPORTING_SLUG);
    if (!$page) {
        return '';
    }
    return (string) add_query_arg('state', $record['slug'], get_permalink($page));
}

/** Categories in render order, as the build wrote them. */
function kop_reporting_categories() {
    $directory = kop_reporting_directory();
    return $directory ? $directory['categories'] : array();
}

/** Split a channel list into category key => channels, in render order. */
function kop_reporting_group_by_category($channels) {
    $grouped = array();
    foreach (kop_reporting_categories() as $category) {
        $matching = array_values(array_filter($channels, function ($channel) use ($category) {
            return $channel['category'] === $category['key'];
        }));
        if ($matching) {
            $grouped[$category['key']] = array('category' => $category, 'channels' => $matching);
        }
    }
    return $grouped;
}

/**
 * How an anonymous report is treated, as a label and a tone. Readers deserve
 * to know before they file: a board that will not act on an anonymous
 * complaint is a board that will waste the one attempt somebody had in them.
 */
function kop_reporting_anonymous_label($value) {
    switch ($value) {
        case 'allowed':
            return array('label' => 'Takes anonymous reports', 'tone' => 'yes');
        case 'discouraged':
            return array('label' => 'Anonymous reports carry less weight', 'tone' => 'maybe');
        case 'not-allowed':
            return array('label' => 'Will not act on an anonymous report', 'tone' => 'no');
        default:
            return null;
    }
}

/** A phone number as a tel: href. */
function kop_reporting_tel_href($phone) {
    $digits = preg_replace('/[^0-9+]/', '', (string) $phone);
    return $digits === '' ? '' : 'tel:' . $digits;
}

/** The domain of a URL, for printing a source link compactly. */
function kop_reporting_host($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? preg_replace('/^www\./', '', $host) : $url;
}

/**
 * One channel card.
 *
 * `$heading_level` lets the state hub embed these a level deeper than the
 * dedicated page does without the document growing a broken heading order.
 */
function kop_reporting_render_channel($channel, $heading_level = 'h3') {
    $tag       = in_array($heading_level, array('h3', 'h4'), true) ? $heading_level : 'h3';
    $anonymous = isset($channel['anonymous']) ? kop_reporting_anonymous_label($channel['anonymous']) : null;
    ?>
    <article class="kop-rep-card" id="<?php echo esc_attr($channel['id']); ?>">
        <header class="kop-rep-card-head">
            <<?php echo $tag; ?> class="kop-rep-card-name"><?php echo esc_html($channel['name']); ?></<?php echo $tag; ?>>
            <?php if (!empty($channel['profession'])) : ?>
                <span class="kop-rep-tag"><?php echo esc_html($channel['profession']); ?></span>
            <?php endif; ?>
        </header>

        <p class="kop-rep-can"><?php echo esc_html($channel['what_it_can_do']); ?></p>

        <?php if (!empty($channel['what_it_cannot_do'])) : ?>
            <p class="kop-rep-cannot"><?php echo esc_html($channel['what_it_cannot_do']); ?></p>
        <?php endif; ?>

        <?php if (!empty($channel['who_to_report'])) : ?>
            <p class="kop-rep-line"><span class="kop-rep-label">Use this when</span><?php echo esc_html($channel['who_to_report']); ?></p>
        <?php endif; ?>

        <?php if (!empty($channel['how'])) : ?>
            <p class="kop-rep-line"><span class="kop-rep-label">How</span><?php echo esc_html($channel['how']); ?></p>
        <?php endif; ?>

        <?php if (!empty($channel['deadline'])) : ?>
            <p class="kop-rep-line kop-rep-deadline"><span class="kop-rep-label">Deadline</span><?php echo esc_html($channel['deadline']); ?></p>
        <?php endif; ?>

        <?php if (!empty($channel['note'])) : ?>
            <p class="kop-rep-note"><?php echo esc_html($channel['note']); ?></p>
        <?php endif; ?>

        <ul class="kop-rep-contact">
            <?php if (!empty($channel['phone'])) : ?>
                <li class="kop-rep-phone">
                    <a href="<?php echo esc_url(kop_reporting_tel_href($channel['phone'])); ?>"><?php echo esc_html($channel['phone']); ?></a>
                    <?php if (!empty($channel['phone_note'])) : ?>
                        <span class="kop-rep-phone-note"><?php echo esc_html($channel['phone_note']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
            <?php if (!empty($channel['complaint_url'])) : ?>
                <li><a class="kop-rep-action" href="<?php echo esc_url($channel['complaint_url']); ?>" target="_blank" rel="noopener">File a complaint</a></li>
            <?php endif; ?>
            <?php if (!empty($channel['info_url'])) : ?>
                <li><a href="<?php echo esc_url($channel['info_url']); ?>" target="_blank" rel="noopener">More about this body</a></li>
            <?php endif; ?>
            <?php if (!empty($channel['email'])) : ?>
                <li><a href="mailto:<?php echo esc_attr($channel['email']); ?>"><?php echo esc_html($channel['email']); ?></a></li>
            <?php endif; ?>
            <?php if (!empty($channel['mail'])) : ?>
                <li class="kop-rep-mail"><?php echo esc_html($channel['mail']); ?></li>
            <?php endif; ?>
        </ul>

        <footer class="kop-rep-card-foot">
            <?php if ($anonymous) : ?>
                <span class="kop-rep-anon kop-rep-anon-<?php echo esc_attr($anonymous['tone']); ?>"><?php echo esc_html($anonymous['label']); ?></span>
            <?php endif; ?>
            <?php if (!empty($channel['mandatory_reporter'])) : ?>
                <span class="kop-rep-anon kop-rep-anon-yes">Mandatory reporters use this channel</span>
            <?php endif; ?>
            <span class="kop-rep-verified">
                Checked <?php echo esc_html(date_i18n('j F Y', strtotime($channel['verified_on']))); ?>
                <?php if (!empty($channel['sources'])) : ?>
                    &middot; source<?php echo count($channel['sources']) > 1 ? 's' : ''; ?>:
                    <?php foreach ($channel['sources'] as $i => $source) : ?>
                        <a href="<?php echo esc_url($source); ?>" target="_blank" rel="noopener"><?php echo esc_html(kop_reporting_host($source)); ?></a><?php echo $i + 1 < count($channel['sources']) ? ', ' : ''; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </span>
        </footer>
    </article>
    <?php
}

/** A set of channels, grouped under their category headings. */
function kop_reporting_render_groups($channels, $heading_level = 'h3') {
    foreach (kop_reporting_group_by_category($channels) as $group) {
        ?>
        <section class="kop-rep-group" data-category="<?php echo esc_attr($group['category']['key']); ?>">
            <h2 class="kop-rep-group-h"><?php echo esc_html($group['category']['label']); ?></h2>
            <p class="kop-rep-group-blurb"><?php echo esc_html($group['category']['blurb']); ?></p>
            <div class="kop-rep-cards">
                <?php foreach ($group['channels'] as $channel) { kop_reporting_render_channel($channel, $heading_level); } ?>
            </div>
        </section>
        <?php
    }
}

/**
 * The state picker. A real form, so it works without JavaScript; the script
 * turns it into an instant switch and hides the button.
 */
function kop_reporting_render_picker($selected_slug) {
    $directory = kop_reporting_directory();
    if (!$directory) {
        return;
    }
    ?>
    <form class="kop-rep-picker" method="get" action="<?php echo esc_url(get_permalink()); ?>">
        <label for="kop-rep-state">Which state is the program in?</label>
        <div class="kop-rep-picker-row">
            <select name="state" id="kop-rep-state">
                <option value="">Choose a state</option>
                <?php foreach ($directory['states'] as $record) : ?>
                    <option value="<?php echo esc_attr($record['slug']); ?>"<?php selected($selected_slug, $record['slug']); ?>>
                        <?php echo esc_html($record['state']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="kop-rep-go">Show me</button>
        </div>
        <p class="kop-rep-picker-hint">
            Report to the state the program is in, not the state you live in. The agency with the power to act is the one where the facility sits.
        </p>
    </form>
    <?php
}

/**
 * The compact block a state hub page embeds: that state's channels only, with
 * a link through to the full page. Prints nothing for a state with no file.
 */
function kop_reporting_render_state_block($state_name) {
    $record = kop_reporting_state($state_name);
    if (!$record) {
        return;
    }
    $page = get_page_by_path(KOP_REPORTING_SLUG);
    ?>
    <section class="kop-rep kop-rep-embed" aria-label="Reporting abuse in <?php echo esc_attr($record['state']); ?>">
        <h2 class="kop-rep-embed-h">Reporting abuse in <?php echo esc_html($record['state']); ?></h2>
        <?php if (!empty($record['note'])) : ?>
            <p class="kop-rep-state-note"><?php echo esc_html($record['note']); ?></p>
        <?php endif; ?>
        <?php kop_reporting_render_groups($record['channels'], 'h4'); ?>
        <?php if ($page) : ?>
            <p class="kop-rep-more">
                <a href="<?php echo esc_url(add_query_arg('state', $record['slug'], get_permalink($page))); ?>">
                    National reporting channels, and the same list for other states
                </a>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * The /report-abuse/ page body.
 *
 * @param string $state_slug Slug from ?state=, or '' for none chosen.
 */
function kop_reporting_render_page($state_slug = '') {
    $directory = kop_reporting_directory();
    if (!$directory) {
        echo '<p class="kop-rep-missing">The reporting directory has not been built yet. Run <code>node scripts/build-reporting-directory.js</code>.</p>';
        return;
    }

    $record   = $state_slug !== '' ? kop_reporting_state($state_slug) : null;
    $resources = get_page_by_path('resources');
    ?>
    <div class="kop-rep" data-selected="<?php echo esc_attr($record ? $record['slug'] : ''); ?>">

        <div class="kop-rep-intro">
            <p class="kop-rep-lede">
                A report can go to more than one place at once, and usually should. The licensing board can take a
                therapist's licence. The state licensor can act against the program. Child protection and the police
                handle the crime. They do not talk to each other reliably, so telling one is not telling the others.
            </p>
            <?php if ($resources) : ?>
                <p class="kop-rep-crisis">
                    If you need help right now rather than a place to report, the
                    <a href="<?php echo esc_url(get_permalink($resources)); ?>">Resources page</a> lists the crisis lines.
                </p>
            <?php endif; ?>
        </div>

        <?php kop_reporting_render_picker($record ? $record['slug'] : ''); ?>

        <?php if ($record) : ?>
            <section class="kop-rep-state" id="state">
                <h2 class="kop-rep-state-h"><?php echo esc_html($record['state']); ?></h2>
                <?php if (!empty($record['note'])) : ?>
                    <p class="kop-rep-state-note"><?php echo esc_html($record['note']); ?></p>
                <?php endif; ?>
                <?php kop_reporting_render_groups($record['channels']); ?>
            </section>
        <?php elseif ($state_slug !== '') : ?>
            <p class="kop-rep-missing">
                There is no entry for that state yet. The national channels below take a report from anywhere.
            </p>
        <?php endif; ?>

        <section class="kop-rep-national" id="national">
            <h2 class="kop-rep-state-h">Wherever the program is</h2>
            <?php if (!empty($directory['national']['note'])) : ?>
                <p class="kop-rep-state-note"><?php echo esc_html($directory['national']['note']); ?></p>
            <?php endif; ?>
            <?php kop_reporting_render_groups($directory['national']['channels']); ?>
        </section>

        <?php
        $coverage = $directory['coverage'];
        if (!empty($coverage['missing'])) :
            $submit = get_page_by_path('tti-data-submission');
        ?>
            <section class="kop-rep-coverage">
                <h2 class="kop-rep-state-h">What is not here yet</h2>
                <p>
                    <?php echo (int) $coverage['covered']; ?> of <?php echo (int) $coverage['total']; ?> states
                    have been researched and checked. The rest are being worked through. Nothing goes on this page
                    without a source and the date somebody confirmed it, because a wrong number costs more than a
                    missing one.
                </p>
                <?php if ($submit) : ?>
                    <p>
                        If you know the right channel in a state that is missing, or you have found a number here that
                        has changed, <a href="<?php echo esc_url(get_permalink($submit)); ?>">tell us</a>.
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <p class="kop-rep-disclaimer">
            This is a directory, not legal advice. Which channel fits your situation, and what a deadline means for
            your case, are questions for a lawyer. The Resources page lists where to start looking for one.
        </p>
    </div>
    <?php
}
