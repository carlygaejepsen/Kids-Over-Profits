<?php
/**
 * Trade organizations: associations of programs, consultants, transporters or other businesses in the
 * industry (NATSAP, the OBH Council, IECA...). Their own records in tradeorgs_master
 * ({name, category: 'tradeorgs', data: {tradeOrg}}), never facility records. Entered on the data forms'
 * Trade Organizations tab (js/data-form/tradeorg-form.js, whose createDefaultTradeOrg() this mirrors),
 * listed on /trade-organizations/ (inc/tradeorgs.php). Plain functions, no WordPress, so api/ endpoints
 * can load it. Lists are cleaned the way marketers' are (kop_marketer_clean_list()).
 */

require_once __DIR__ . '/lib-marketers.php';

if (!function_exists('kop_tradeorg_activity_labels')) {
    /** What an organization does for its members: key in tradeOrg.activities => label (same keys in tradeorg-form.js). */
    function kop_tradeorg_activity_labels() {
        return array(
            'standards'       => 'Sets standards or ethical principles for members',
            'accreditation'   => 'Accredits, certifies or inspects members',
            'lobbying'        => 'Lobbies lawmakers or works against regulation',
            'publicRelations' => 'Public relations for its members or the industry',
            'research'        => 'Funds or publishes research',
            'conferences'     => 'Conferences, training and networking',
            'referralNetwork' => 'Connects programs with consultants or other referral sources',
            'memberSeal'      => 'A logo or listing members show parents',
        );
    }

    /** List fields of tradeOrg (string lists). */
    function kop_tradeorg_list_fields() {
        return array(
            'otherNames', 'websites', 'keyPersonnel', 'otherActivities', 'memberFacilities', 'memberCompanies',
            'memberOthers', 'publications', 'lobbying', 'funding', 'affiliations', 'lawsuits', 'sourceUrls', 'socialMedia',
        );
    }

    /** Text fields of tradeOrg. */
    function kop_tradeorg_text_fields() {
        return array('name', 'acronym', 'orgType', 'city', 'state', 'country', 'address', 'founded', 'status', 'notes');
    }

    /** One tradeOrg block with every field present and typed. */
    function kop_tradeorg_normalize($org) {
        $org = is_array($org) ? $org : array();
        $out = array();
        foreach (kop_tradeorg_text_fields() as $field) {
            $out[$field] = trim((string) ($org[$field] ?? ''));
        }
        foreach (kop_tradeorg_list_fields() as $field) {
            $out[$field] = kop_marketer_clean_list($org[$field] ?? array());
        }
        if (!$out['websites'] && !empty($org['website'])) {
            $out['websites'] = kop_marketer_clean_list(array($org['website']));
        }
        $activities = is_array($org['activities'] ?? null) ? $org['activities'] : array();
        $out['activities'] = array();
        foreach (array_keys(kop_tradeorg_activity_labels()) as $key) {
            $out['activities'][$key] = !empty($activities[$key]);
        }
        $out['fieldNotes'] = is_array($org['fieldNotes'] ?? null) ? $org['fieldNotes'] : (object) array();
        return $out;
    }

    /** What a trade organization record keeps: the tradeOrg block and the category tag. */
    function kop_tradeorg_keep_data($data) {
        $data = is_array($data) ? $data : array();
        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['tradeOrg'])) {
            $data = $data['data'];
        }
        $kept = array(
            'tradeOrg' => kop_tradeorg_normalize($data['tradeOrg'] ?? array()),
            'category' => 'tradeorgs',
        );
        if (!empty($data['documentFolderId'])) {
            $kept['documentFolderId'] = (int) $data['documentFolderId'];
        }
        return $kept;
    }

    /** Does a submission's data hold a trade organization? */
    function kop_tradeorg_has_data($data) {
        if (!is_array($data)) {
            return false;
        }
        if (strtolower(trim((string) ($data['category'] ?? ''))) === 'tradeorgs') {
            return true;
        }
        $org = $data['tradeOrg'] ?? null;
        return is_array($org) && trim((string) ($org['name'] ?? '')) !== '';
    }

    /** The organization inside a stored row's json_data (wrapper or bare data). */
    function kop_tradeorg_from_json($json) {
        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return kop_tradeorg_normalize(array());
        }
        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        return kop_tradeorg_normalize($data['tradeOrg'] ?? array());
    }

    /** $new over $old: filled text replaces, lists join, a ticked activity stays ticked. */
    function kop_tradeorg_merge($old, $new) {
        $old = kop_tradeorg_normalize($old);
        $new = kop_tradeorg_normalize($new);
        $out = $old;
        foreach (kop_tradeorg_text_fields() as $field) {
            if ($new[$field] !== '') {
                $out[$field] = $new[$field];
            }
        }
        foreach (kop_tradeorg_list_fields() as $field) {
            $out[$field] = kop_marketer_clean_list(array_merge($old[$field], $new[$field]));
        }
        foreach ($out['activities'] as $key => $on) {
            $out['activities'][$key] = $on || !empty($new['activities'][$key]);
        }
        $out['fieldNotes'] = array_merge((array) $old['fieldNotes'], (array) $new['fieldNotes']);
        return $out;
    }

    /** Every name it goes by: name, acronym, other names. */
    function kop_tradeorg_names($org) {
        $names = array();
        foreach (array_merge(array((string) ($org['name'] ?? ''), (string) ($org['acronym'] ?? '')), (array) ($org['otherNames'] ?? array())) as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Does a membership entry on a facility record ("NATSAP (former member; member from 1999, last listed 2011)")
     * name this organization? Returns 'current', 'former' or '' (not this one). Whole words, any case; an
     * acronym must match in capitals so "aee" in a word is never a hit.
     */
    function kop_tradeorg_membership_match($entry, $org) {
        $entry = trim((string) $entry);
        if ($entry === '') {
            return '';
        }
        foreach (kop_tradeorg_names($org) as $name) {
            $is_acronym = preg_match('/^[A-Z][A-Z&.]{1,9}$/', $name) === 1;
            $pattern = '/(?<![A-Za-z0-9])' . preg_quote($name, '/') . '(?![A-Za-z0-9])/' . ($is_acronym ? '' : 'i');
            if (preg_match($pattern, $entry)) {
                return preg_match('/\b(former|formerly|past|previous|no longer|expelled|left)\b/i', $entry) ? 'former' : 'current';
            }
        }
        return '';
    }
}
