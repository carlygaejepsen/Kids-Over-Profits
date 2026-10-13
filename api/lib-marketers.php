<?php
/**
 * Marketing companies: firms that market troubled teen programs (websites, search, "independent" directories,
 * help lines, lead selling, reputation work, instructions for AI chatbots). Their own records in marketers_master
 * ({name, category: 'marketers', data: {marketerCompany}}), never facility records. Entered on the data forms'
 * Marketers tab (js/data-form/marketer-form.js, whose createDefaultMarketerCompany() this mirrors), listed on
 * /marketing-companies/ (inc/marketers.php). Plain functions, no WordPress, so api/ endpoints can load it.
 */

if (!function_exists('kop_marketer_service_labels')) {
    /** What a marketer does for programs: key in marketerCompany.services => label (same keys in marketer-form.js). */
    function kop_marketer_service_labels() {
        return [
            'websiteDesign'        => 'Builds program websites',
            'seo'                  => 'Search engine optimization',
            'cityPages'            => 'Pages for many towns a program is not in',
            'directories'          => 'Runs "independent" directories or ranking sites',
            'helpLine'             => 'Help lines, admissions or placement services',
            'leadGeneration'       => 'Sells or trades parents\' inquiries',
            'aiInstructions'       => 'Instructions for AI chatbots (llms.txt, hidden page text)',
            'reputationManagement' => 'Reputation management (reviews, answering critics)',
            'advertising'          => 'Paid advertising (search, social media)',
            'publicRelations'      => 'Public relations and press',
        ];
    }

    /** List fields of marketerCompany (string lists). */
    function kop_marketer_list_fields() {
        return [
            'otherNames', 'websites', 'parentCompanies', 'keyPersonnel', 'otherServices', 'sitesRun',
            'clientFacilities', 'clientCompanies', 'affiliations', 'lawsuits', 'sourceUrls', 'socialMedia',
        ];
    }

    /** Text fields of marketerCompany. */
    function kop_marketer_text_fields() {
        return ['name', 'city', 'state', 'country', 'address', 'founded', 'status', 'notes'];
    }

    function kop_marketer_clean_list($value) {
        if (is_string($value)) {
            $value = preg_split('/\r?\n/', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                // {url, displayText} or {name} shapes from other forms
                $item = $item['url'] ?? $item['name'] ?? $item['value'] ?? '';
            }
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** One marketerCompany block with every field present and typed. */
    function kop_marketer_normalize_company($company) {
        $company = is_array($company) ? $company : [];
        $out = [];
        foreach (kop_marketer_text_fields() as $field) {
            $out[$field] = trim((string) ($company[$field] ?? ''));
        }
        foreach (kop_marketer_list_fields() as $field) {
            $out[$field] = kop_marketer_clean_list($company[$field] ?? []);
        }
        if (!$out['websites'] && !empty($company['website'])) {
            $out['websites'] = kop_marketer_clean_list([$company['website']]);
        }
        $services = is_array($company['services'] ?? null) ? $company['services'] : [];
        $out['services'] = [];
        foreach (array_keys(kop_marketer_service_labels()) as $key) {
            $out['services'][$key] = !empty($services[$key]);
        }
        $out['fieldNotes'] = is_array($company['fieldNotes'] ?? null) ? $company['fieldNotes'] : (object) [];
        return $out;
    }

    /**
     * What a marketer record keeps: the company block and the category tag. The form sends its whole
     * state (empty operator, facility and referrer blocks), which has no place here.
     */
    function kop_marketer_keep_data($data) {
        $data = is_array($data) ? $data : [];
        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['marketerCompany'])) {
            $data = $data['data'];
        }
        $kept = [
            'marketerCompany' => kop_marketer_normalize_company($data['marketerCompany'] ?? []),
            'category'        => 'marketers',
        ];
        if (!empty($data['documentFolderId'])) {
            $kept['documentFolderId'] = (int) $data['documentFolderId'];
        }
        return $kept;
    }

    /** Does a submission's data hold a marketer? */
    function kop_marketer_has_data($data) {
        if (!is_array($data)) {
            return false;
        }
        if (strtolower(trim((string) ($data['category'] ?? ''))) === 'marketers') {
            return true;
        }
        $company = $data['marketerCompany'] ?? null;
        return is_array($company) && trim((string) ($company['name'] ?? '')) !== '';
    }

    /** The company inside a stored row's json_data (wrapper or bare data). */
    function kop_marketer_company_from_json($json) {
        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return kop_marketer_normalize_company([]);
        }
        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        return kop_marketer_normalize_company($data['marketerCompany'] ?? []);
    }

    /** $new over $old: filled text replaces, lists join, a ticked service stays ticked. */
    function kop_marketer_merge_company($old, $new) {
        $old = kop_marketer_normalize_company($old);
        $new = kop_marketer_normalize_company($new);
        $out = $old;
        foreach (kop_marketer_text_fields() as $field) {
            if ($new[$field] !== '') {
                $out[$field] = $new[$field];
            }
        }
        foreach (kop_marketer_list_fields() as $field) {
            $out[$field] = kop_marketer_clean_list(array_merge($old[$field], $new[$field]));
        }
        foreach ($out['services'] as $key => $on) {
            $out['services'][$key] = $on || !empty($new['services'][$key]);
        }
        $out['fieldNotes'] = array_merge((array) $old['fieldNotes'], (array) $new['fieldNotes']);
        return $out;
    }

    /** The company's names: its name, then its other names. */
    function kop_marketer_names($company) {
        $names = [];
        foreach (array_merge([(string) ($company['name'] ?? '')], (array) ($company['otherNames'] ?? [])) as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }
}
