<?php
/**
 * One-line help for the submission form's collapsed panels.
 *
 * The panels carried a heading and nothing else, so someone filling the form
 * had to open each one to find out what belonged in it. Each line says what
 * goes in and gives an example, and sits between the panel header and its
 * content so it reads while the panel is still closed.
 *
 * The text lives here rather than in the markup because the public form
 * (templates/data-form-public.php) and the admin form
 * (templates/data-form-admin.php) lay the same panels out differently: the
 * public form collapses them, the admin form uses flat headings. One list
 * keeps the two from drifting, which is what happened to the tooltips in
 * js/field-tooltips.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panel key => help line. Keys are stable identifiers, not headings, so a
 * heading can be reworded without silently dropping its help.
 *
 * @return array<string,string>
 */
function kop_form_help_lines() {
    return array(
        // --- Operator / parent company ---------------------------------------
        'headquarters' => 'Where the company runs its business from, which is often not where the program is. City, state and country are enough: for example Nashville, TN, United States.',
        'key-staff' => 'People who run the company rather than the program: owners, executives and board members. One per row, with a title if you know it, such as "Jane Doe, Chief Executive Officer".',
        'other-parent-companies' => 'Other companies that have owned or controlled this one, including after a sale or rebrand. For example "Sequel Youth and Family Services, 2017 to 2021".',

        // --- Facility identity -----------------------------------------------
        'facility-ownership' => 'The company that owns or operates this facility today. If a program changed hands, put the current owner here and the earlier ones under Other Parent Companies.',
        'other-names' => 'Every other name this program has used, including names it operated under before a rebrand. Survivors often know a program only by its old name, so this is how they find it.',
        'known-referrers' => 'Educational consultants, therapists, transport services and programs that send children here. Name the person or company, for example "Smith Educational Consulting".',
        'website-links' => 'Web addresses for the program: its own site, its social media, and directory listings. Paste the full address, starting with https.',
        'international-program' => 'Fill this in only if the program operates outside the United States. It records the country, and whether children are sent there from elsewhere.',

        // --- Location ---------------------------------------------------------
        'address-details' => 'The street address where children actually live, as precisely as you can. A rural program may only have a road and a county, which is still worth recording.',
        'operating-dates' => 'When the program opened and, if it has closed, when it closed. An approximate year is useful: enter 2003 rather than leaving it blank.',

        // --- Staff -------------------------------------------------------------
        'key-staff-positions' => 'People who work at this facility: directors, clinicians, teachers and line staff. Include the role and the years they were there if you know them.',

        // --- Oversight ----------------------------------------------------------
        'current-accreditations' => 'Bodies that currently accredit or endorse the program, such as NATSAP, CARF or the Joint Commission. Accreditation is not oversight, but it shows who vouches for the program.',
        'past-accreditations' => 'Accreditations and memberships the program used to hold, and lost or let lapse. Losing an accreditation often follows an investigation, so the dates matter.',
        'licensing' => 'The licence the program holds, who issues it, and its number if you have it. For example "Residential Treatment Center, Utah Office of Licensing".',

        // --- Evidence ------------------------------------------------------------
        'news-media' => 'News articles, investigations and broadcasts about this program. Paste the full web address; an archived copy is better than a link that may disappear.',
        'official-documentation' => 'Documents produced by an agency: inspection reports, licensing files, investigation findings, records obtained by request.',
        'legal-compliance' => 'Lawsuits, criminal cases, settlements and regulatory actions involving the program or its staff. A case name and court are enough to start.',
        'business-property' => 'Business records: incorporation filings, property deeds, bankruptcy records and anything showing who owns the land or the company.',
        'other-resources' => 'Anything that does not fit the categories above: survivor accounts, books, documentaries, academic research, archived copies of the program\'s own materials.',

        // --- What the program does -------------------------------------------------
        'standard-treatment-types' => 'Tick the kinds of treatment the program says it provides, such as wilderness therapy or residential treatment. These are the labels used across the database.',
        'custom-treatment-types' => 'A kind of treatment this program offers that is not in the standard list. Use the program\'s own words, for example "equine-assisted trauma work".',
        'standard-philosophies' => 'The approach the program is built on, such as behaviour modification or a level system. Tick everything that applies; most programs combine several.',
        'custom-philosophies' => 'An approach this program names that is not in the standard list, in the program\'s own words.',

        // --- Harm --------------------------------------------------------------------
        'standard-incident-types' => 'Kinds of harm that have been reported at this program, such as restraint injuries, deaths or escapes. Tick what has been reported, and put the evidence under News & Media or Official Documentation.',
        'custom-incidents' => 'A kind of incident reported at this program that is not in the standard list. Describe it plainly and briefly.',

        // --- Mental health providers (outside the TTI) ------------------------------
        'provider-care-types' => 'The levels of care this provider offers, such as an acute psychiatric unit, a partial hospitalization program (PHP) or an intensive outpatient program (IOP). Tick every one that applies.',
        'provider-tti-practices' => 'TTI methods reported at this provider even though it is not a TTI program, such as a level system, seclusion or cutting off family contact. Tick what has been reported.',
        'provider-tti-referrals' => 'Where this provider sends children: the TTI programs it refers to, the transport companies it uses, and any agreements with them. For example "discharges to Provo Canyon School".',
    );
}

/**
 * Print the help line for a panel, if there is one.
 *
 * Placed between the panel header and its content so it is readable while
 * the panel is collapsed; that was the point of adding it.
 *
 * @param string $key   Key from kop_form_help_lines().
 * @param string $class Wrapper class: the collapsing panels use the default,
 *                      the admin form's flat headings pass 'section-help'.
 */
function kop_form_panel_help($key, $class = 'sub-section-help') {
    $lines = kop_form_help_lines();
    if (empty($lines[$key])) {
        return;
    }
    echo '<p class="' . esc_attr($class) . '">' . esc_html($lines[$key]) . '</p>';
}
