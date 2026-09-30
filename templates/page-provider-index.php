<?php
/**
 * Template Name: Provider Index
 * Description: Public directory of mental health providers outside the TTI
 * (psychiatric wards, PHP/IOP, day schools, respite, outpatient) that use TTI
 * practices or refer children into it. Records come from providers_master
 * (the data form's "providers" category, or a Woodbury report);
 * js/provider-index.js draws the cards from api/get-providers-only.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<div class="provider-directory-container">
    <div class="directory-header">
        <h1>Mental Health Provider Directory</h1>
        <p>Psychiatric hospitals, partial hospitalization and intensive outpatient programs, day schools, respite care and
            outpatient practices outside the troubled teen industry that use its practices or refer young people into it.</p>
    </div>

    <div class="controls" data-kop-bug-feature="provider-index/search" data-kop-bug-label="Provider Search">
        <input type="text" id="searchInput" placeholder="Search name, location, care type, or details...">
        <select id="statusFilter">
            <option value="">All Locations</option>
        </select>
        <button type="button" id="clearSearch" onclick="window.clearSearch && window.clearSearch()">Clear Search</button>
    </div>

    <div id="alphabet-filter" class="provider-alpha-filter"></div>

    <div id="providers-container">
        <div class="provider-loading">
            <p>Loading provider directory...</p>
        </div>
    </div>
</div>

<?php
get_footer();
