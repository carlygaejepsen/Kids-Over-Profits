<?php
/**
 * Shared facility / operator name resolution for news linking.
 *
 * Builds one index of every known name for each facilities_master row so that a
 * news-article mention can be matched to a facility (or an operating company)
 * even when the mention doesn't use the exact unique_name. The names come from:
 *
 *   - facilities_master.unique_name                          (authoritative)
 *   - data.operator.name / .currentName / .otherNames[]      (company rows only)
 *   - data.identification.name / .currentName / .otherNames[] / .pastNames[]
 *
 * The operator/identification "other names" and "past names" are the curated
 * alias lists admins already maintain in the facility editor under
 * "Other Names / DBAs / Former Names". Adding a name there is all it takes to
 * extend matching - no separate alias table to maintain.
 *
 * Ambiguity is handled conservatively: a name (alias) that resolves to two
 * different facilities is dropped rather than guessed. A real unique_name always
 * wins over an alias that collides with it.
 *
 * Used by api/save-news-submission.php (link new articles on save) and
 * api/migrate-news-facilities-to-objects.php (retroactive backfill) so both
 * behave identically.
 */

if (!function_exists('kop_normalize_name_key')) {
    /**
     * Normalize a name into a comparison key so alternate spellings/punctuation
     * collapse together: lowercase, "&" -> "and", drop apostrophes and other
     * punctuation, drop the article "the", and collapse whitespace.
     *
     * So "El Pueblo Boys & Girls Ranch", "El Pueblo Boys and Girls Ranch" and
     * "El Pueblo Boys & Girls' Ranch" all key to "el pueblo boys and girls ranch"
     * and match without anyone curating an alias.
     */
    function kop_normalize_name_key(string $s): string {
        $s = strtolower($s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);   // strip punctuation/apostrophes
        $s = preg_replace('/\bthe\b/', ' ', $s);      // drop the article "the"
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string)$s);
    }
}

if (!function_exists('kop_collect_self_names')) {
    /**
     * Pull every name that identifies THIS facilities_master row out of its
     * decoded json_data. Excludes the unique_name (added separately by the
     * index builder) and does not descend into nested data.facilities[] (those
     * are promoted to their own rows and indexed in their own right).
     *
     * Includes the hidden `matchAliases` lists (data.matchAliases,
     * operator.matchAliases, identification.matchAliases). These are match-only:
     * they feed news linking but no display code renders them, so an admin can
     * add spelling variants / abbreviations an article uses without changing what
     * shows on the facility card.
     *
     * @return string[] trimmed, non-empty name strings
     */
    function kop_collect_self_names($decoded): array {
        if (!is_array($decoded)) return [];

        $out = [];
        $push = static function ($v) use (&$out) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') $out[] = $v;
            }
        };
        $pushList = static function ($list) use ($push) {
            if (is_array($list)) {
                foreach ($list as $v) $push($v);
            }
        };

        $cat = strtolower((string)($decoded['category'] ?? $decoded['data']['category'] ?? ''));
        $isCompany = ($cat === 'companies' || $cat === 'company');
        $isRef = !empty($decoded['__facility_ref']) || !empty($decoded['data']['__facility_ref']);

        // Operator (company identity) names - only when this row is the company's
        // own row, never a promoted facility identity row (whose data.operator is
        // the *parent*, not this row).
        if ($isCompany && !$isRef) {
            $op = $decoded['data']['operator'] ?? $decoded['operator'] ?? null;
            if (is_array($op)) {
                $push($op['name'] ?? null);
                $push($op['currentName'] ?? null);
                $pushList($op['otherNames'] ?? null);
                $pushList($op['matchAliases'] ?? null); // hidden, match-only
            }
        }

        // Facility (identification) names - this facility's own current/former
        // names. Present on facility rows; harmless when absent.
        $ident = $decoded['data']['identification'] ?? $decoded['identification'] ?? null;
        if (is_array($ident)) {
            $push($ident['name'] ?? null);
            $push($ident['currentName'] ?? null);
            $pushList($ident['otherNames'] ?? null);
            $pushList($ident['pastNames'] ?? null);
            $pushList($ident['matchAliases'] ?? null); // hidden, match-only
        }

        // Project-level hidden match aliases (apply to whichever row this is,
        // facility or company). Match-only - never rendered on a card.
        $pushList($decoded['data']['matchAliases'] ?? $decoded['matchAliases'] ?? null);

        return $out;
    }
}

if (!function_exists('kop_build_facility_alias_index')) {
    /**
     * Build the name -> facility_id index from facilities_master.
     *
     * @return array{
     *   exact: array<string,int>,      lower(name) => facility_id (uniques + non-ambiguous aliases)
     *   ambiguous: array<string,bool>, lower(name) => true for aliases that resolve to >1 facility
     *   tokens: array<string,array<int,bool>>, token => set of facility_ids (for fuzzy)
     *   names: array<int,string>       facility_id => unique_name (for display/audit)
     * }
     */
    function kop_build_facility_alias_index(PDO $pdo): array {
        $exactUnique    = []; // normkey(unique_name) => id (authoritative)
        $aliasTo        = []; // normkey(alias) => id
        $aliasAmbiguous = []; // normkey(alias) => true
        $tokens         = []; // token => [id => true]
        $names          = []; // id => unique_name

        $addTokens = static function (string $value, int $id) use (&$tokens) {
            foreach (array_unique(preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $t) {
                $tokens[$t][$id] = true;
            }
        };

        $rows = $pdo->query("SELECT id, unique_name, json_data FROM facilities_master")->fetchAll();
        foreach ($rows as $r) {
            $id    = (int)$r['id'];
            $uname = trim((string)$r['unique_name']);
            $names[$id] = $r['unique_name'];

            $ukey = kop_normalize_name_key($uname);
            if ($ukey !== '') {
                $exactUnique[$ukey] = $id;
                $addTokens($uname, $id);
            }

            $decoded = json_decode((string)$r['json_data'], true);
            foreach (kop_collect_self_names($decoded) as $alias) {
                $akey = kop_normalize_name_key($alias);
                if ($akey === '' || $akey === $ukey) continue;
                if (isset($aliasTo[$akey]) && $aliasTo[$akey] !== $id) {
                    $aliasAmbiguous[$akey] = true;
                } else {
                    $aliasTo[$akey] = $id;
                }
                $addTokens($alias, $id);
            }
        }

        // Compose the exact map: non-ambiguous aliases first, then overlay the
        // authoritative unique_names so a real name always beats a colliding alias.
        $exact = [];
        foreach ($aliasTo as $k => $id) {
            if (!isset($aliasAmbiguous[$k])) $exact[$k] = $id;
        }
        foreach ($exactUnique as $k => $id) {
            $exact[$k] = $id;
        }

        return [
            'exact'     => $exact,
            // A key that is also a unique_name is not ambiguous - the name wins.
            'ambiguous' => array_diff_key($aliasAmbiguous, $exactUnique),
            'tokens'    => $tokens,
            'names'     => $names,
        ];
    }
}

if (!function_exists('kop_resolve_name_to_facility')) {
    /**
     * Resolve a single mention name to a facility_id via exact name / alias.
     * Returns null when there's no confident match.
     */
    function kop_resolve_name_to_facility(string $name, array $index): ?int {
        $key = kop_normalize_name_key($name);
        if ($key === '' || isset($index['ambiguous'][$key])) return null;
        return $index['exact'][$key] ?? null;
    }
}

if (!function_exists('kop_news_stopwords')) {
    /**
     * Generic facility / place / org words that are never distinctive enough to
     * fuzzy-link on by themselves, even if they appear in only one name.
     *
     * @return array<string,int> word => 1 (for isset lookups)
     */
    function kop_news_stopwords(): array {
        static $stop = null;
        if ($stop !== null) return $stop;
        $stop = array_flip(explode(' ',
            'the of and for at in on a an to st saint mount mt fort ft los las san santa ' .
            'academy academies school schools college colleges institute university center centre centers ctr ' .
            'home homes house houses ranch ranches camp camps lodge manor hall place places retreat retreats ' .
            'sanctuary foundation foundations ministries ministry church chapel mission missions program programs ' .
            'project projects services service solutions systems group groups associates partners holdings enterprises ' .
            'company corporation corp inc llc ltd lp ' .
            'residential treatment behavioral behavioural mental health healthcare therapeutic therapy wilderness ' .
            'outdoor adventure boarding recovery rehabilitation rehab care wellness counseling psychiatric hospital ' .
            'clinic transitional transition transitions intervention learning education educational development ' .
            'achievement boys girls youth teen teens teenage adolescent adolescents children child kids young men ' .
            'women family families ' .
            'mountain mountains valley valleys lake lakes river rivers creek brook hill hills ridge ridges peak peaks ' .
            'summit summits vista vistas view views meadow meadows springs spring falls canyon canyons mesa butte ' .
            'oak oaks pine pines cedar cedars willow willows birch aspen maple elm rose sun sunrise sunset dawn star ' .
            'stars sky desert prairie forest woods woodland garden gardens field fields ' .
            'north south east west northern southern eastern western central upper lower new old great grand high ' .
            'highland highlands point pathways pathway journey journeys bridge bridges gateway gateways crossroads ' .
            'hope faith grace christian catholic baptist'
        ));
        return $stop;
    }
}

if (!function_exists('kop_fuzzy_resolve_name')) {
    /**
     * Resolve a mention name to a single facility via distinctive tokens - a word
     * that belongs to exactly one facility and isn't a generic stopword. Returns
     * ['facility_id' => int, 'token' => string] or null when nothing matched or
     * the distinctive tokens point at more than one facility (ambiguous).
     */
    function kop_fuzzy_resolve_name(string $name, array $index, ?array $stopwords = null): ?array {
        if ($stopwords === null) $stopwords = kop_news_stopwords();
        $tokenOwners = $index['tokens'] ?? [];
        $toks = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = []; // facility_id => token that selected it
        foreach (array_unique($toks) as $t) {
            if (strlen($t) < 4) continue;
            if (ctype_digit($t)) continue;
            if (isset($stopwords[$t])) continue;
            if (!isset($tokenOwners[$t]) || count($tokenOwners[$t]) !== 1) continue;
            $fid = array_key_first($tokenOwners[$t]);
            $candidates[$fid] = $t;
        }
        if (count($candidates) === 1) {
            $fid = array_key_first($candidates);
            return ['facility_id' => (int)$fid, 'token' => $candidates[$fid]];
        }
        return null;
    }
}
