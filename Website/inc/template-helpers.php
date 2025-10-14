<?php
/**
 * Theme helper functions.
 *
 * @package Kids_Over_Profits
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kidsoverprofits_get_facility_report_sort_options')) {
    /**
     * Retrieve the sort options for facility report pages.
     *
     * @param string $slug Page slug.
     * @return array
     */
    function kidsoverprofits_get_facility_report_sort_options($slug = '') {
        $default_options = array(
            array(
                'value' => '',
                'label' => __('Default Order (A-Z)', 'kidsoverprofits'),
            ),
            array(
                'value' => 'name',
                'label' => __('Sort by Name', 'kidsoverprofits'),
            ),
            array(
                'value' => 'violations-only',
                'label' => __('Facilities with Violations Only', 'kidsoverprofits'),
            ),
            array(
                'value' => 'violations-desc',
                'label' => __('Most Violations First', 'kidsoverprofits'),
            ),
            array(
                'value' => 'recent-inspection',
                'label' => __('Most Recent Inspection', 'kidsoverprofits'),
            ),
        );

        if ('ut-reports' === $slug) {
            return array(
                array(
                    'value' => '',
                    'label' => __('Sort by Name (A-Z)', 'kidsoverprofits'),
                ),
                array(
                    'value' => 'violations-desc',
                    'label' => __('Sort by Most Violations', 'kidsoverprofits'),
                ),
                array(
                    'value' => 'recent-inspection',
                    'label' => __('Sort by Most Recent Inspection', 'kidsoverprofits'),
                ),
                array(
                    'value' => 'violations-only',
                    'label' => __('Show Only with Violations', 'kidsoverprofits'),
                ),
            );
        }

        return $default_options;
    }
}

if (!function_exists('kidsoverprofits_get_facility_report_intro')) {
    /**
     * Retrieve the introductory paragraph for facility report pages.
     *
     * @param string $slug Page slug.
     * @return string
     */
    function kidsoverprofits_get_facility_report_intro($slug = '') {
        $intros = array(
            'ca-reports' => 'California has made a large volume of inspection data available to the public at <a href="https://www.ccld.dss.ca.gov/carefacilitysearch/">https://www.ccld.dss.ca.gov/carefacilitysearch/</a>. We have sorted these reports and highlighted violations in orange. Click on a facility box for details. This page is updated at the beginning of every month.',
            'az-reports' => 'All reports have been sourced directly from Arizona DHS\'s official website at <a href="https://azcarecheck.azdhs.gov/">https://azcarecheck.azdhs.gov/</a>. We have sorted these reports and highlighted violations in orange. This page is updated at the beginning of every month. Click a facility box to read details.',
            'ct-reports' => 'All reports have been sourced directly from Connecticut DCF’s official website at <a href="https://licensefacilities.dcf.ct.gov/listing_CCF.asp">https://licensefacilities.dcf.ct.gov/listing_CCF.asp</a>. We have sorted these reports and highlighted violations in orange. This page is updated at the beginning of every month. Click a facility box to read details.',
            'tx-reports' => 'All reports have been sourced directly from Texas Health and Human Services at <a href="https://childcare.hhs.texas.gov/Public/ChildCareSearch">https://childcare.hhs.texas.gov/Public/ChildCareSearch</a>. We have sorted these reports and highlighted violations in orange. This page is updated at the beginning of every month. Click a facility box to read details.',
            'wa-reports' => 'All reports have been sourced directly from Washington Department of Health at <a href="https://fortress.wa.gov/doh/facilitysearch/">https://fortress.wa.gov/doh/facilitysearch/</a>. We have sorted these reports and highlighted violations in orange. This page is updated at the beginning of every month. Click a facility box to read details.',
            'ut-reports' => 'All reports have been sourced directly from Utah Child Care Licensing at <a href="https://ccl.utah.gov/ccl/#/facilities">https://ccl.utah.gov/ccl/#/facilities</a>. We have sorted these reports and highlighted violations in orange. This page is updated at the beginning of every month. Click a facility box to read details.',
        );

        return $intros[$slug] ?? '';
    }
}
