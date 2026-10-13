<?php
/**
 * Cite and print on /facility/ and /operator/ pages, for reporters and
 * researchers who need to quote a record or take it with them.
 *
 *   cite   a box above the page footer: the citation (name, "last updated"
 *          from the record, the page address, the reader's own access date),
 *          Copy, "Print or save as PDF", the reuse licence and the press desk.
 *          Built by js/press-tools.js from the data below, because whole pages
 *          are cached and an access date printed by the server would go stale
 *   print  css/press-tools.css: site header, sidebar, footer, pencils, buttons,
 *          jump links and the map left out, one column, the address of every
 *          outside link written after it. Folded lists print as the reader left
 *          them: opened all at once a large program runs to 90 pages (330 news
 *          articles), so the reader opens what they need first
 *
 * Both page types share the article and footer markup (kop-facility-profile,
 * kop-fp-footer), so one script serves both. Nothing in their templates changes.
 *
 * Also the press desk on /journalists/ (below): the hub frame's module and
 * settings for that page, added through its filters.
 *
 * php scripts/test-press-tools.php checks the citation text, PHP == JS, and
 * renders the press desk.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_press_tools_page')) {
    /**
     * array(kind, name, updated) for the facility or company page being
     * rendered, or null on any other page.
     */
    function kop_press_tools_page() {
        if (function_exists('kop_facility_pages_is_page') && kop_facility_pages_is_page()) {
            $page = $GLOBALS['kop_facility_page'];
            $kind = 'facility';
        } elseif (function_exists('kop_operator_pages_is_page') && kop_operator_pages_is_page()) {
            $page = $GLOBALS['kop_operator_page'];
            $kind = 'company';
        } else {
            return null;
        }
        $name = trim((string) ($page['name'] ?? ''));
        if ($name === '') return null;
        return array(
            'kind'    => $kind,
            'name'    => $name,
            'updated' => trim((string) ($page['updated_label'] ?? '')),
        );
    }
}

if (!function_exists('kop_press_citation')) {
    /**
     * The citation as plain text. $accessed is left out when empty (the
     * script adds the reader's own date).
     *
     *   Kids Over Profits. "Provo Canyon School." Kids Over Profits facility
     *   database, last updated October 3, 2026. https://kidsoverprofits.org/facility/provo-canyon-school/.
     *   Accessed October 13, 2026.
     */
    function kop_press_citation($name, $kind, $url, $updated = '', $accessed = '') {
        $work = $kind === 'company' ? 'Kids Over Profits company database' : 'Kids Over Profits facility database';
        $name = rtrim(trim((string) $name), '.');
        $text = 'Kids Over Profits. "' . $name . '." ' . $work;
        if (trim((string) $updated) !== '') $text .= ', last updated ' . trim((string) $updated);
        $text .= '. ' . trim((string) $url) . '.';
        if (trim((string) $accessed) !== '') $text .= ' Accessed ' . trim((string) $accessed) . '.';
        return $text;
    }
}

if (!function_exists('kop_press_desk_url')) {
    function kop_press_desk_url() {
        $page = get_page_by_path('journalists');
        return $page ? get_permalink($page) : home_url('/journalists/');
    }
}

if (!function_exists('kop_press_tools_enqueue')) {
    function kop_press_tools_enqueue() {
        $info = kop_press_tools_page();
        if (!$info) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        $css = $dir . '/css/press-tools.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-press-tools', $uri . '/css/press-tools.css', array('kop-colors'), filemtime($css));
        }
        $js = $dir . '/js/press-tools.js';
        if (!file_exists($js)) return;
        wp_enqueue_script('kop-press-tools', $uri . '/js/press-tools.js', array(), filemtime($js), true);
        $open_data = get_page_by_path('open-data');
        wp_localize_script('kop-press-tools', 'KOP_PRESS_TOOLS', array(
            'kind'        => $info['kind'],
            'name'        => $info['name'],
            'updated'     => $info['updated'],
            'work'        => $info['kind'] === 'company' ? 'Kids Over Profits company database' : 'Kids Over Profits facility database',
            'license'     => defined('KOP_OPEN_DATA_LICENSE') ? KOP_OPEN_DATA_LICENSE : 'CC BY-SA 4.0',
            'licenseUrl'  => defined('KOP_OPEN_DATA_LICENSE_URL') ? KOP_OPEN_DATA_LICENSE_URL : 'https://creativecommons.org/licenses/by-sa/4.0/',
            'openDataUrl' => $open_data ? get_permalink($open_data) : '',
            'deskUrl'     => kop_press_desk_url(),
            'site'        => home_url('/'),
        ));
    }
    add_action('wp_enqueue_scripts', 'kop_press_tools_enqueue', 25);
}

// ---------------------------------------------------------------------------
// The press desk: /journalists/
// ---------------------------------------------------------------------------
//
// The page keeps its own editor text (trauma-informed reporting, the media@
// address); under it, through the hub frame's filters, a reporter's way into
// the records: background on one program, the government and court records,
// data to reuse, how the industry works, and the records worth checking on
// any program. Groups are hand-picked in kop_press_desk_groups(); a link whose
// page is not published is dropped (kop_hub_link()).

if (!function_exists('kop_press_desk_groups')) {
    function kop_press_desk_groups() {
        $operator_index = home_url('/operator/');
        $documents      = function_exists('kop_dt_url') ? kop_dt_url() : home_url('/documents/');
        $essential      = function_exists('kop_feat_url') ? kop_feat_url() : home_url('/essential-reporting/');
        return apply_filters('kop_press_desk_groups', array(
            array('title' => 'Background on one program or company', 'links' => array(
                array('label' => 'Facility directory', 'slug' => 'tti-program-index', 'note' => 'Every program on record, open and closed. Each one\'s page gathers its names over time, the company that ran it, serious inspection findings, lawsuits, deaths, staff and news coverage, each with its source, and a citation you can copy or print as a PDF.'),
                array('label' => 'Parent companies', 'url' => $operator_index, 'note' => 'The companies behind the programs: what each has run and where, its history year by year, the people who ran it, and the lawsuits and deaths across its programs.'),
                array('label' => 'Network map', 'slug' => 'network-map', 'note' => 'Who owned what, which staff moved from one program to the next, and which programs changed their names, with the years of each.'),
                array('label' => 'Programs by state', 'slug' => 'where-are-the-kids', 'note' => 'Each state\'s programs, the inspection reports it publishes, its laws and its news.'),
                array('label' => 'Educational consultants', 'slug' => 'referrers-educational-consultants', 'note' => 'The paid advisors who send families to programs, and the programs each has referred to.'),
                array('label' => 'Youth transport companies', 'slug' => 'youth-transport-companies', 'note' => 'The companies hired to take a young person from home to a program.'),
            )),
            array('title' => 'Government and court records', 'links' => array(
                array('label' => 'Inspection reports', 'slug' => 'inspection-reports', 'note' => 'More than 80,000 state licensing inspections from more than 25 states, readable without a records request.'),
                array('label' => 'Serious findings', 'slug' => 'severe-reports', 'note' => 'Deaths, abuse, injurious restraints, suicide attempts and medical neglect, quoted in the agency\'s own words and checked against the report by a person.'),
                array('label' => 'Lawsuits', 'slug' => 'lawsuits', 'note' => 'Cases against programs, their owners and staff: the claims, the court and where each stands.'),
                array('label' => 'Legislation tracker', 'slug' => 'legislative-efforts', 'note' => 'Federal and state bills on youth residential programs, with status, sponsors and last action.'),
                array('label' => 'Research & Reports', 'slug' => 'researchreports', 'note' => 'GAO and congressional investigations, audits and academic studies, with the programs each names.'),
                array('label' => 'In Loving Memory', 'slug' => 'in-loving-memory', 'note' => 'Young people who died in programs, with the date, the cause and the reporting.'),
                array('label' => 'Document archive', 'slug' => 'document-archive', 'note' => 'More than 4,800 documents filed under the program or company they concern, including the programs\' own handbooks, contracts and brochures.'),
                array('label' => 'Documents by type', 'url' => $documents, 'note' => 'One kind of document read across programs: handbooks, staff manuals, enrollment agreements, court filings, depositions.'),
            )),
            array('title' => 'Data and coverage to build on', 'links' => array(
                array('label' => 'Open data', 'slug' => 'open-data', 'note' => 'Every public dataset as CSV and JSON, rebuilt daily: facilities, companies, inspection findings, lawsuits, bills, news and the network map. Free to reuse with credit (CC BY-SA 4.0).'),
                array('label' => 'Essential reporting', 'url' => $essential, 'note' => 'The investigations we point people to first, each with a note on what it found.'),
                array('label' => 'News feed', 'slug' => 'tti-news-feed', 'note' => 'Coverage as it comes out, with long-running stories collected article by article and archived copies where they exist.'),
            )),
            array('title' => 'How the industry works', 'links' => array(
                array('label' => 'History', 'slug' => 'history', 'note' => 'Where the industry came from and how its ownership changed.'),
                array('label' => 'How the industry manages its reputation', 'slug' => 'reputation-management', 'note' => 'Instructions written for AI chatbots, directories run by the programs\' own marketers, and pages built to answer critics.'),
                array('label' => 'Marketing companies', 'slug' => 'marketing-companies', 'note' => 'The firms that build program websites, run "independent" directories and help lines, and sell parents\' inquiries.'),
                array('label' => 'Trade organizations', 'slug' => 'trade-organizations', 'note' => 'NATSAP and the industry\'s other trade groups, and the programs whose records list each one.'),
                array('label' => 'Glossary', 'slug' => 'glossary', 'note' => 'The words programs use for their methods, from handbooks, staff manuals and state records, with the programs that used each.'),
                array('label' => 'Frequently asked questions', 'slug' => 'faq', 'note' => 'Short answers on what the industry is and how it operates, with links to the records.'),
            )),
            array('title' => 'Records worth checking on any program', 'links' => array(
                array('label' => 'Its licence and inspection history', 'slug' => 'inspection-reports', 'note' => 'Which agency licenses it, how often inspectors visited and what they wrote up. Some programs are licensed as schools, and some are not licensed at all.'),
                array('label' => 'Every name it has used', 'slug' => 'tti-program-index', 'note' => 'A program\'s page lists its earlier and later names and the records filed under each, so a search under today\'s name does not miss what happened under an earlier one.'),
                array('label' => 'Who owns it, and who owned it before', 'url' => $operator_index, 'note' => 'The parent company\'s page lists its other programs, past and present, and what happened at them.'),
                array('label' => 'Where its staff worked before', 'slug' => 'network-map', 'note' => 'Program pages list staff with their other roles in the industry; the map shows the moves.'),
                array('label' => 'Lawsuits and deaths', 'slug' => 'lawsuits', 'note' => 'Under every name and every owner, not only the current one.'),
                array('label' => 'Who refers families to it', 'slug' => 'referrers-educational-consultants', 'note' => 'The consultants who recommend it and the transport companies that bring young people there.'),
            )),
        ));
    }
}

if (!function_exists('kop_hub_module_press_desk')) {
    /** The press desk's groups, drawn like the hub's reading list. */
    function kop_hub_module_press_desk() {
        foreach (kop_press_desk_groups() as $i => $group) {
            $links = function_exists('kop_hub_links') ? kop_hub_links($group['links']) : array();
            if (!$links) continue;
            $hid = 'kop-press-desk-h' . $i;
            ?>
            <section class="kop-hub-reading kop-press-desk" aria-labelledby="<?php echo esc_attr($hid); ?>">
                <h2 class="kop-hub-h" id="<?php echo esc_attr($hid); ?>"><?php echo esc_html($group['title']); ?></h2>
                <ul class="kop-hub-reading-list">
                    <?php foreach ($links as $link) : ?>
                        <li>
                            <div class="kop-hub-reading-item">
                                <div class="kop-hub-reading-body">
                                    <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                                    <?php if ($link['note'] !== '') : ?>
                                        <p><?php echo esc_html($link['note']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php
        }
    }
}

if (!function_exists('kop_press_desk_modules')) {
    function kop_press_desk_modules($modules) {
        $modules['journalists'] = 'kop_hub_module_press_desk';
        return $modules;
    }
    add_filter('kop_hub_modules', 'kop_press_desk_modules');
}

if (!function_exists('kop_press_desk_hub_config')) {
    function kop_press_desk_hub_config($hubs) {
        $hubs['journalists'] = array_merge(isset($hubs['journalists']) ? $hubs['journalists'] : array(), array(
            'standfirst' => 'For reporters: background on every program and company we track, the government and court records behind it, and how to cite us and reach us.',
            'actions'    => array(
                array('label' => 'Look up a program', 'slug' => 'tti-program-index'),
                array('label' => 'Open data', 'slug' => 'open-data'),
                array('label' => 'Essential reporting', 'url' => function_exists('kop_feat_url') ? kop_feat_url() : ''),
                array('label' => 'Documents by type', 'url' => function_exists('kop_dt_url') ? kop_dt_url() : ''),
            ),
            'contribute' => array(
                'heading' => 'Send us what you have',
                'links'   => array(
                    array('label' => 'Send documents anonymously', 'slug' => 'anon-submit',
                          'note' => 'Records, contracts or photos, without an account; files are encrypted when they reach the server.'),
                    array('label' => 'Send a link', 'url' => home_url('/send/'),
                          'note' => 'An article, lawsuit or bill we are missing.'),
                    array('label' => 'Contact us', 'slug' => 'contact',
                          'note' => 'A correction, or a question the records do not answer.'),
                ),
            ),
        ));
        return $hubs;
    }
    add_filter('kop_hub_config', 'kop_press_desk_hub_config');
}
