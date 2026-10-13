<?php
/**
 * Which table an admin form save goes to (api/save-master.php).
 *
 * A project named after a state or country is that place's location profile,
 * unless the form sent another kind of project: a provider, referrer or
 * transporter project named "Montana" is saved to its own table, never over
 * MONTANA's location profile. Matches resolveSaveCategory() in
 * js/data-form-modules/api.js.
 *
 * @return array [category, project name] (a location's name upper-cased)
 */
function kop_save_master_category($project_name, $requested_category, array $location_names) {
    $category = (string) $requested_category;
    if ($project_name === null || $project_name === '') {
        return [$category, $project_name];
    }
    if (in_array($category, kop_save_master_non_location_categories(), true)) {
        return [$category, $project_name];
    }
    $upper = strtoupper(trim((string) $project_name));
    if (in_array($upper, $location_names, true)) {
        return ['locations', $upper];
    }
    return [$category, $project_name];
}

function kop_save_master_non_location_categories() {
    return ['referrers', 'transporters', 'providers', 'wiki'];
}
