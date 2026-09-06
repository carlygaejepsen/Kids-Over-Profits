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
        }

        return $out;
    }
}

if (!function_exists('kop_collect_match_aliases')) {
    /**
     * Pull the curated, hidden matchAliases for THIS row. These are the names an
     * admin explicitly assigned to this facility, so they are AUTHORITATIVE -
     * they override the ambiguity guard (a curated alias always wins, even if the
     * same text appears on other facilities).
     *
     * @return string[] trimmed, non-empty alias strings
     */
    function kop_collect_match_aliases($decoded): array {
        if (!is_array($decoded)) return [];
        $out = [];
        $pushList = static function ($list) use (&$out) {
            if (is_array($list)) {
                foreach ($list as $v) {
                    if (is_string($v) && trim($v) !== '') $out[] = trim($v);
                }
            }
        };
        $op = $decoded['data']['operator'] ?? $decoded['operator'] ?? null;
        if (is_array($op)) $pushList($op['matchAliases'] ?? null);
        $ident = $decoded['data']['identification'] ?? $decoded['identification'] ?? null;
        if (is_array($ident)) $pushList($ident['matchAliases'] ?? null);
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
        $exactUnique    = []; // normkey(unique_name) => id  (authoritative)
        $curatedTo      = []; // normkey(matchAlias) => id   (authoritative, admin-assigned)
        $aliasTo        = []; // normkey(alias) => id        (auto, ambiguity-checked)
        $aliasAmbiguous = []; // normkey(alias) => true
        $tokens         = []; // token => [id => true]
        $names          = []; // id => unique_name

        $addTokens = static function (string $value, int $id) use (&$tokens) {
            foreach (array_unique(preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $t) {
                $tokens[$t][$id] = true;
            }
        };

        // Curated matchAliases are authoritative: an admin explicitly assigned
        // the target, so they bypass the ambiguity guard entirely (last write
        // wins on the rare chance the same curated alias is put on two rows).
        $addCurated = static function ($alias, int $toId) use (&$curatedTo, $addTokens) {
            $akey = kop_normalize_name_key((string)$alias);
            if ($akey === '') return;
            $curatedTo[$akey] = $toId;
            $addTokens((string)$alias, $toId);
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

            $addAlias = static function ($alias, int $toId) use (&$aliasTo, &$aliasAmbiguous, $addTokens) {
                $akey = kop_normalize_name_key((string)$alias);
                if ($akey === '') return;
                if (isset($aliasTo[$akey]) && $aliasTo[$akey] !== $toId) {
                    $aliasAmbiguous[$akey] = true;
                } else {
                    $aliasTo[$akey] = $toId;
                }
                $addTokens((string)$alias, $toId);
            };

            foreach (kop_collect_self_names($decoded) as $alias) {
                if (kop_normalize_name_key($alias) === $ukey) continue; // already the unique_name
                $addAlias($alias, $id);
            }

            // "Trinity Teen Solutions, Inc." should also answer to "Trinity Teen
            // Solutions" - mentions rarely carry the corporate suffix.
            $bare = trim((string)preg_replace('/[\s,]+(inc|incorporated|llc|l\.l\.c|ltd|corp|corporation|co)\.?$/i', '', $uname));
            if ($bare !== '' && kop_normalize_name_key($bare) !== $ukey) {
                $addAlias($bare, $id);
            }
            foreach (kop_collect_match_aliases($decoded) as $ma) {
                $addCurated($ma, $id);
            }

            // Descend into nested facilities. A facility's own names/aliases live
            // in its PARENT project's data.facilities[] entry, keyed by that
            // facility's facilities_master id (facility_id) - NOT on the promoted
            // row - so without this they're invisible to matching.
            $nested = $decoded['data']['facilities'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $f) {
                    if (!is_array($f)) continue;
                    $fid = isset($f['facility_id']) ? (int)$f['facility_id'] : 0;
                    if ($fid <= 0) continue;

                    $idf = is_array($f['identification'] ?? null) ? $f['identification'] : [];
                    if (!isset($names[$fid]) && !empty($idf['name'])) {
                        $names[$fid] = $idf['name'];
                    }

                    // Auto names (ambiguity-checked).
                    $nestedNames = [];
                    foreach (['name', 'currentName'] as $k) {
                        if (!empty($idf[$k]) && is_string($idf[$k])) $nestedNames[] = $idf[$k];
                    }
                    foreach (['otherNames', 'pastNames'] as $k) {
                        if (!empty($idf[$k]) && is_array($idf[$k])) {
                            foreach ($idf[$k] as $n) if (is_string($n)) $nestedNames[] = $n;
                        }
                    }
                    foreach ($nestedNames as $nm) {
                        $addAlias($nm, $fid);
                    }

                    // Curated matchAliases on the nested entry (authoritative).
                    foreach ([$idf['matchAliases'] ?? null, $f['matchAliases'] ?? null] as $maList) {
                        if (is_array($maList)) {
                            foreach ($maList as $n) if (is_string($n)) $addCurated($n, $fid);
                        }
                    }
                }
            }
        }

        // Compose the exact map in priority order (lowest to highest):
        //   1. auto aliases that aren't ambiguous
        //   2. curated matchAliases (admin-assigned) - override the ambiguity guard
        //   3. unique_names - always win
        $exact = [];
        foreach ($aliasTo as $k => $id) {
            if (!isset($aliasAmbiguous[$k])) $exact[$k] = $id;
        }
        foreach ($curatedTo as $k => $id) {
            $exact[$k] = $id;
        }
        foreach ($exactUnique as $k => $id) {
            $exact[$k] = $id;
        }

        return [
            'exact'     => $exact,
            // A key that a curated alias or unique_name resolves is never ambiguous.
            'ambiguous' => array_diff_key($aliasAmbiguous, $curatedTo, $exactUnique),
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

if (!function_exists('kop_facility_name_variants')) {
    /**
     * Spellings of a mention to try when resolving it, in order: the name as
     * given; the name with a trailing parenthetical dropped ("Hyde School
     * (Bath, Maine)" -> "Hyde School"); and the parenthetical itself when it
     * reads as a name rather than a place ("Maine Youth Center (Long Creek
     * Youth Development Center)" -> "Long Creek Youth Development Center").
     *
     * @return string[] non-empty, de-duplicated
     */
    function kop_facility_name_variants(string $name): array {
        $name = trim($name);
        if ($name === '') return [];
        $out = [$name];
        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/', $name, $m)) {
            $outer = trim($m[1]);
            $inner = trim($m[2]);
            if ($outer !== '') $out[] = $outer;
            // Three or more words and no comma: a name, not "City, State".
            if ($inner !== '' && strpos($inner, ',') === false && str_word_count($inner) >= 3) $out[] = $inner;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('kop_resolve_mention_to_facility')) {
    /**
     * Resolve a free-text mention (as written in facilities_mentioned) to a
     * facility_id, trying each spelling from kop_facility_name_variants().
     */
    function kop_resolve_mention_to_facility(string $name, array $index): ?int {
        foreach (kop_facility_name_variants($name) as $variant) {
            $fid = kop_resolve_name_to_facility($variant, $index);
            if ($fid !== null) return $fid;
        }
        return null;
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
