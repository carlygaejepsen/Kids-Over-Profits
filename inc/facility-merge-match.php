<?php
/**
 * Likely duplicate facility records, for KOP Tools > Merge Duplicates
 * (inc/facility-merge.php). Pure functions over a list of rows, so the
 * offline test can run them against tmp/prod.sqlite.
 *
 * Two records in the same state (or country), in the same city or with one
 * city blank, are a pair when their names:
 *   same words      differ only by punctuation, "The", "Inc", "&"/"and",
 *                   a plural s or word order
 *   spelling        differ by one word spelled slightly differently
 *                   (Sequel / Sequal), or run together (Teen Challenge /
 *                   TeenChallenge)
 *   also known as   one is listed among the other's otherNames
 *   one word        one has a single extra word ("Piney Ridge Center" /
 *                   "Piney Ridge Treatment Center")
 *   short form      one word is an abbreviation of the other's
 * or when both give the same street address.
 *
 * Never a pair:
 *   - a pastNames match: each name of a renamed program is its own record
 *     (Copper Canyon Academy / Sedona Sky Academy; docs/PLAN.md 3.7)
 *   - the extra or changed word is a number, II, a direction, Boys/Girls or
 *     Unit: "Forward In Life" and "Forward In Life II" are two homes
 *   - sibling houses that share everything but the house name ("RMBHS -
 *     Opal House" / "RMBHS - Plata House"): a swapped word that is not a
 *     spelling of the other is never a pair
 * The extra word after a dash ("Morrison Child & Family Services -
 * Counterpoint") usually names one campus of a licensee; those are listed
 * as "check" rather than "likely".
 */

if (!function_exists('kop_fmerge_words')) {
    /** Lowercase words of a name with the filler words dropped and plurals folded. */
    function kop_fmerge_words($name) {
        $s = mb_strtolower((string) $name);
        $s = str_replace(array("'", "\u{2019}", "\u{2018}", '`'), '', $s);
        $s = str_replace('&', ' and ', $s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = strtolower($t);
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(array('the', 'a', 'an', 'of', 'and', 'at', 'for', 'in', 'on', 'inc', 'llc', 'ltd', 'co', 'corp', 'pllc', 'lp'));
        }
        $out = array();
        foreach (preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (isset($stop[$w])) continue;
            if (strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') $w = substr($w, 0, -1);
            $out[] = $w;
        }
        return $out;
    }
}

if (!function_exists('kop_fmerge_distinct_word')) {
    /** Words that name a different home, not a different spelling of the same one. */
    function kop_fmerge_distinct_word($w) {
        return (bool) preg_match('/^(\d+[a-z]?|i{1,3}|iv|vi{0,3}|ix|x|xi{1,3}|north|south|east|west|northeast|northwest|southeast|southwest|upper|lower|boy|girl|men|women|male|female|unit|annex|phase|cottage|junior|senior|jr|sr|adolescent|adult|day)$/', $w);
    }
}

if (!function_exists('kop_fmerge_generic_word')) {
    /** Words most facility names share (kop_facdisc_near_duplicate()'s list, singular). */
    function kop_fmerge_generic_word($w) {
        static $generic = null;
        if ($generic === null) {
            $generic = array_flip(array('academy', 'school', 'center', 'centre', 'home', 'house', 'program', 'youth', 'girl', 'boy',
                'treatment', 'residential', 'ranch', 'camp', 'lodge', 'facility', 'service', 'family', 'children', 'childrens',
                'juvenile', 'detention', 'county', 'teen', 'therapeutic', 'behavioral', 'health', 'group', 'care', 'rtc', 'hospital'));
        }
        return isset($generic[$w]);
    }
}

if (!function_exists('kop_fmerge_after_dash_words')) {
    /** Words after the last " - " / en dash / em dash / colon in a name. */
    function kop_fmerge_after_dash_words($name) {
        $parts = preg_split('/\s[-\x{2013}\x{2014}]\s|:\s/u', (string) $name);
        if (!$parts || count($parts) < 2) return array();
        return kop_fmerge_words(end($parts));
    }
}

if (!function_exists('kop_fmerge_name_tails')) {
    /** Word keys of what follows a dash or "dba" in a name: the program under its company's name. */
    function kop_fmerge_name_tails($name) {
        $out = array();
        $parts = preg_split('/\s[-\x{2013}\x{2014}]\s|\s(?:dba|d\/b\/a|doing business as)\s/iu', (string) $name);
        for ($i = 1; $parts && $i < count($parts); $i++) {
            $k = implode(' ', kop_fmerge_words(implode(' ', array_slice($parts, $i))));
            if ($k !== '') $out[] = $k;
        }
        return $out;
    }
}

if (!function_exists('kop_fmerge_street_key')) {
    /** Street address reduced to letters and digits, '' unless it has a house number. */
    function kop_fmerge_street_key($address) {
        $s = mb_strtolower(trim((string) $address));
        if ($s === '' || !preg_match('/\d/', $s)) return '';
        $s = preg_replace('/\b(street|st)\b/', 'st', $s);
        $s = preg_replace('/\b(road|rd)\b/', 'rd', $s);
        $s = preg_replace('/\b(avenue|ave)\b/', 'ave', $s);
        $s = preg_replace('/\b(highway|hwy)\b/', 'hwy', $s);
        $s = preg_replace('/\b(drive|dr)\b/', 'dr', $s);
        $s = preg_replace('/\b(lane|ln)\b/', 'ln', $s);
        $s = preg_replace('/\b(boulevard|blvd)\b/', 'blvd', $s);
        $s = preg_replace('/\b(north|n)\b/', 'n', $s);
        $s = preg_replace('/\b(south|s)\b/', 's', $s);
        $s = preg_replace('/\b(east|e)\b/', 'e', $s);
        $s = preg_replace('/\b(west|w)\b/', 'w', $s);
        $s = preg_replace('/[^a-z0-9]/', '', $s);
        return strlen($s) >= 8 ? $s : '';
    }
}

if (!function_exists('kop_fmerge_prepare')) {
    /**
     * One comparable row from a facilities_v2 row {id, unique_name, json_data}
     * (or an already decoded 'doc').
     */
    function kop_fmerge_prepare(array $row) {
        $doc = isset($row['doc']) && is_array($row['doc']) ? $row['doc'] : json_decode((string) ($row['json_data'] ?? ''), true);
        if (!is_array($doc)) return null;
        $idn = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $loc = is_array($doc['location'] ?? null) ? $doc['location'] : array();
        $name = trim((string) ($idn['name'] ?? ''));
        if ($name === '') $name = trim((string) ($row['unique_name'] ?? ''));
        if ($name === '') return null;
        $state = function_exists('kop_facility_state_code') ? kop_facility_state_code($loc['state'] ?? null) : strtoupper((string) ($loc['state'] ?? ''));
        $alts = array();
        foreach ((array) ($idn['otherNames'] ?? array()) as $n) {
            if (is_string($n) && trim($n) !== '') $alts[] = implode(' ', kop_fmerge_words($n));
        }
        $past = array();
        foreach ((array) ($idn['pastNames'] ?? array()) as $n) {
            if (is_array($n)) $n = $n['name'] ?? '';
            if (is_string($n) && trim($n) !== '') $past[] = implode(' ', kop_fmerge_words($n));
        }
        if (!empty($idn['currentName']) && is_string($idn['currentName'])) $past[] = implode(' ', kop_fmerge_words($idn['currentName']));
        $city = mb_strtolower(trim((string) ($loc['city'] ?? '')));
        $city = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w\s]/u', '', $city)));
        $city = preg_replace('/^saint\b/', 'st', $city);
        return array(
            'id'     => (int) $row['id'],
            'name'   => $name,
            'words'  => kop_fmerge_words($name),
            'dash'   => kop_fmerge_after_dash_words($name),
            'tails'  => kop_fmerge_name_tails($name),
            'ops'    => array_values(array_map('intval', (array) ($row['ops'] ?? array()))),
            'alts'   => $alts,
            'past'   => $past,
            'state'  => $state ?: '',
            'place'  => $state ? $state : 'C:' . mb_strtolower(trim((string) ($loc['country'] ?? ''))),
            'city'   => $city,
            'street' => kop_fmerge_street_key($loc['address'] ?? ($loc['street'] ?? '')),
        );
    }
}

if (!function_exists('kop_fmerge_pair_reason')) {
    /**
     * Why $a and $b look like one facility: {code, label, level: likely|check}
     * or null. Both from kop_fmerge_prepare().
     */
    function kop_fmerge_pair_reason(array $a, array $b) {
        $A = $a['words'];
        $B = $b['words'];
        if (!$A || !$B) return null;
        $ka = implode(' ', $A);
        $kb = implode(' ', $B);
        // A renamed program: two records on purpose.
        if (in_array($kb, $a['past'], true) || in_array($ka, $b['past'], true)) return null;

        $same_city = $a['city'] === '' || $b['city'] === '' || $a['city'] === $b['city'];
        if ($a['street'] !== '' && $a['street'] === $b['street']) {
            if ($ka === $kb) return array('code' => 'same', 'label' => 'Same name and street address', 'level' => 'likely');
            // Different names at one address are often separate units of a campus.
            $shared = array_intersect($A, $B);
            if ($shared) return array('code' => 'address', 'label' => 'Same street address', 'level' => 'check');
        }
        if (!$same_city) return null;

        // Listed as each other's other name: under different parent companies
        // that is usually a renamed program (Three Springs / Sequel TSI), kept apart.
        if (in_array($kb, $a['alts'], true) || in_array($ka, $b['alts'], true)) {
            if ($a['ops'] && $b['ops'] && array_intersect($a['ops'], $b['ops'])) {
                return array('code' => 'aka', 'label' => 'One is listed as the other\'s other name, same parent company', 'level' => 'check');
            }
            return null;
        }
        // "CERTS - Moonridge Academy" / "Moonridge Academy", "Therapy Associates
        // dba Star Guides" / "Star Guides": the other name with its company in front.
        if ((count($B) >= 2 && in_array($kb, $a['tails'], true)) || (count($A) >= 2 && in_array($ka, $b['tails'], true))) {
            return array('code' => 'prefix', 'label' => 'Same name with a company name in front', 'level' => 'likely');
        }
        $sa = array_unique($A);
        $sb = array_unique($B);
        sort($sa);
        sort($sb);
        if ($sa === $sb) {
            if (count($A) === 1 && $a['city'] === '' && $b['city'] === '') return null;
            return array('code' => 'same', 'label' => 'Same name, written differently', 'level' => 'likely');
        }
        if (str_replace(' ', '', $ka) === str_replace(' ', '', $kb)) {
            return array('code' => 'spacing', 'label' => 'Same name, spaced differently', 'level' => 'likely');
        }
        $only_a = array_values(array_diff($sa, $sb));
        $only_b = array_values(array_diff($sb, $sa));
        // One extra word.
        if ((count($only_a) === 1 && !$only_b) || (count($only_b) === 1 && !$only_a)) {
            $w = $only_a ? $only_a[0] : $only_b[0];
            $longer = $only_a ? $a : $b;
            if (min(count($sa), count($sb)) < 2 && strlen($w) < 3) return null;
            if (min(count($sa), count($sb)) < 1) return null;
            if (kop_fmerge_distinct_word($w)) return null;
            // A word after a dash usually names one house of a licensee.
            if (in_array($w, $longer['dash'], true) && count($longer['dash']) <= 2) {
                return array('code' => 'subunit', 'label' => 'One adds "' . $w . '" after a dash (could be one campus of the other)', 'level' => 'check');
            }
            // "The Children's Home" / "Cherokee Home for Children": the shorter
            // name is only generic words, so the extra word may be the whole name.
            $shorter = $only_a ? $sb : $sa;
            $named = array_filter($shorter, function ($x) { return !kop_fmerge_generic_word($x); });
            return array('code' => 'word', 'label' => 'One word different: "' . $w . '"',
                'level' => count($shorter) >= 2 && $named ? 'likely' : 'check');
        }
        // One word changed: only a spelling or a short form of the same word.
        if (count($only_a) === 1 && count($only_b) === 1) {
            $x = $only_a[0];
            $y = $only_b[0];
            if (kop_fmerge_distinct_word($x) || kop_fmerge_distinct_word($y)) return null;
            $short = min(strlen($x), strlen($y));
            if ($short >= 4 && levenshtein($x, $y) <= ($short < 7 ? 1 : 2)) {
                return array('code' => 'spelling', 'label' => 'Spelled differently: "' . $x . '" / "' . $y . '"', 'level' => 'likely');
            }
            if ($short >= 2 && count($sa) >= 2 && (strpos($x, $y) === 0 || strpos($y, $x) === 0)) {
                return array('code' => 'short', 'label' => 'Short form: "' . $x . '" / "' . $y . '"', 'level' => 'check');
            }
        }
        return null;
    }
}

if (!function_exists('kop_fmerge_find_pairs')) {
    /**
     * Every likely pair among the rows, each {a, b, reason}, a with the lower id.
     * $dismissed: "lo:hi" => true for pairs marked not the same.
     */
    function kop_fmerge_find_pairs(array $rows, array $dismissed = array()) {
        $by_place = array();
        foreach ($rows as $row) {
            $p = kop_fmerge_prepare($row);
            if ($p !== null) $by_place[$p['place']][] = $p;
        }
        $pairs = array();
        foreach ($by_place as $list) {
            usort($list, function ($x, $y) { return $x['id'] <=> $y['id']; });
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $key = $list[$i]['id'] . ':' . $list[$j]['id'];
                    if (isset($dismissed[$key])) continue;
                    $why = kop_fmerge_pair_reason($list[$i], $list[$j]);
                    if ($why !== null) {
                        $pairs[] = array('a' => $list[$i]['id'], 'b' => $list[$j]['id'], 'reason' => $why);
                    }
                }
            }
        }
        return $pairs;
    }
}
