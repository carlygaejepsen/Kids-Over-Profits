<?php
/**
 * Canonical vocabulary for news_submissions.tags.
 *
 * Tags come from the AI extractor, the news processor form, the WordPress post
 * importer and the discovery cron, so the same idea arrives under many names
 * ("youth detention", "juvenile detention", "Juvenile Justice"). Every writer
 * and the news feed run tags through kop_news_tag_canonical() so one idea is
 * one tag.
 *
 * Two layers:
 *   - synonyms: lowercase variant => canonical label (applied on save and on display)
 *   - excluded: generic terms that fit nearly every article; kept in the
 *     database but hidden from the feed's tags and filters
 *
 * To merge a new variant, add it to kop_news_tag_synonyms(). To rewrite tags
 * already stored, run api/normalize-news-tags.php as an admin.
 */

if (!function_exists('kop_news_tag_synonyms')) {
    function kop_news_tag_synonyms(): array {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $groups = [
            // Topics
            'Juvenile Justice' => [
                'juvenile detention', 'youth detention', 'youth detention center',
                'juvenile detention center', 'detention center', 'juvenile hall',
                'youth prison', 'juvenile jail', 'youth justice', 'youth custody',
                'juvenile corrections', 'youth corrections',
            ],
            'Escape' => [
                'escapes', 'escaped', 'escape attempt', 'escape attempts',
                'runaway', 'runaways', 'absconded', 'elopement',
            ],
            'Riot' => [
                'riots', 'uprising', 'uprisings', 'disturbance', 'disturbances', 'melee',
            ],
            'Death' => [
                'deaths', 'child death', 'teen death', 'youth death', 'student death',
                'wrongful death', 'death in custody', 'coroner inquiry', 'inquest',
            ],
            'Self-Harm' => ['self harm', 'selfharm'],
            'Arrest' => ['arrests', 'arrested', 'staff arrest'],
            'Trial' => [
                'trials', 'criminal trial', 'mistrial', 'court verdict', 'verdict',
                'sentencing', 'sentenced', 'conviction',
            ],
            'Settlement' => ['settlements', 'lawsuit settlement'],
            'Closure' => [
                'closures', 'facility closure', 'school closure', 'program closure',
                'regulatory closure', 'shutdown',
            ],
            'Licensing' => [
                'license suspension', 'license revoked', 'license revocation',
                'licensing issues', 'licensing violations', 'unlicensed facilities',
                'unlicensed facility',
            ],
            'Corporate Governance' => [
                'corporate oversight', 'shareholder rights', 'fiduciary duty',
            ],
            'Legislation' => ['legislature', 'bill passed', 'new law', 'state law'],
            'Faith-Based Programs' => [
                'faith-based program', 'faith-based programs', 'faith-based residential program',
                'faith-based therapy', 'faith-based facility', 'faith-based facilities',
                'christian residential facility', 'christian program', 'religious program',
            ],
            'Human Trafficking' => ['trafficking', 'child trafficking', 'sex trafficking'],
            'Kidnapping' => ['abduction', 'abductions', 'kidnappings'],
            'LGBTQ+ Rights' => ['lgbtq rights', 'lgbt rights', 'lgbtq', 'lgbtq+', 'lgbtq youth'],
            'Survivor Stories' => ['survivor story', 'survivor testimony', 'memoir'],
            'Documentary' => ['documentaries', 'netflix'],
            'Private Schools' => ['private school'],
            'Staff Misconduct' => ['therapist misconduct', 'employee misconduct'],
            'Safety Violations' => ['safety concerns', 'safety violation'],
            'Adoption' => ['adoption trauma', 'adoptee', 'adoptees'],
            'Immigration' => ['immigrant children', 'unaccompanied minors'],
            'Disability Rights' => [
                'disability law center', 'developmental disabilities', 'disabilities',
                'disability',
            ],
            'Crisis Response' => ['mobile crisis teams'],
            'Wilderness Therapy' => ['wilderness program', 'wilderness programs'],
            'Sexual Misconduct' => ['sexual misconduct allegations'],
            'Family Law' => ['divorce', 'child support', 'visitation rights', 'custody dispute'],
            'Child Protective Services' => [
                'child protection', 'department of child services', 'cps', 'dcs', 'dcfs',
            ],
            'Foster Care' => ['state custody', 'foster home', 'foster homes'],
            'Group Home' => ['group homes', 'care home', 'care homes'],
            'Oversight' => [
                'lack of oversight', 'regulatory oversight', 'government inspection',
                'state oversight', 'inspection', 'inspections', 'systemic failure',
                'reporting failures', 'council accountability',
            ],

            // Generic terms: mapped onto an excluded label so the feed hides them.
            'Sexual Abuse' => [
                'child sexual abuse', 'child sex abuse', 'sex abuse', 'csa',
            ],
            'Abuse' => ['abuse investigation', 'alleged abuse'],
            'Residential Treatment' => [
                'residential facility', 'residential facilities', 'residential treatment centers',
                'youth residential treatment', 'teen residential facility', 'teen treatment',
                'teen treatment programs', 'youth treatment', 'youth facility',
            ],
            'Troubled Teen Industry' => ['troubled-teen industry'],
            'Reform School' => ['reform schools'],
            'Restraint' => ['physical restraint', 'restraints'],
            'Neglect' => ['medical neglect'],

            // Locations (article_location becomes a tag; state codes and
            // sub-state regions fold into the state or country).
            'Missouri' => ['southwest missouri'],
            'Virginia' => ['goochland county'],
            'California' => ['san diego county'],
            'Kentucky' => ['kentucky politics'],
            'India' => ['karnataka'],
            'Canada' => ['ontario', 'british columbia', 'alberta', 'quebec'],
            'Australia' => ['south australia', 'queensland', 'new south wales'],
            'United Kingdom' => ['uk', 'england', 'scotland', 'wales', 'northern ireland', 'great britain'],
        ];

        $states = kop_news_tag_state_codes();
        foreach ($states as $code => $name) {
            $groups[$name][] = strtolower($code);
        }

        $map = [];
        foreach ($groups as $canonical => $variants) {
            $map[strtolower($canonical)] = $canonical;
            foreach ($variants as $v) {
                $map[$v] = $canonical;
            }
        }
        return $map;
    }
}

if (!function_exists('kop_news_tag_state_codes')) {
    function kop_news_tag_state_codes(): array {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];
    }
}

if (!function_exists('kop_news_tag_countries')) {
    /** Non-US places the feed files under "Location" rather than "Topics". */
    function kop_news_tag_countries(): array {
        return [
            'Australia', 'Canada', 'China', 'Costa Rica', 'Dominican Republic', 'Haiti',
            'Hungary', 'India', 'Ireland', 'Italy', 'Jamaica', 'Japan', 'Mexico',
            'New Zealand', 'Samoa', 'South Africa', 'Ukraine', 'United Kingdom',
        ];
    }
}

if (!function_exists('kop_news_tag_excluded')) {
    /** Lowercase tags hidden from the feed: they fit nearly every article. */
    function kop_news_tag_excluded(): array {
        return [
            // Generic abuse terms (specific types belong in content warnings)
            'abuse', 'child abuse', 'teen abuse', 'youth abuse',
            'physical abuse', 'sexual abuse', 'emotional abuse', 'psychological abuse',
            'verbal abuse', 'mental abuse', 'spiritual abuse', 'medical abuse',
            'neglect', 'medical neglect', 'educational neglect',
            'restraint', 'seclusion', 'isolation',
            'assault', 'sexual assault', 'physical assault',
            'trauma', 'ptsd', 'mistreatment',
            // Generic TTI/facility terms
            'boarding school', 'boarding schools',
            'troubled teen', 'troubled teens', 'troubled teen industry', 'tti',
            'residential treatment', 'residential treatment center', 'rtc',
            'therapeutic boarding school', 'treatment center', 'treatment facility',
            'behavioral health', 'mental health', 'mental health treatment',
            'reform', 'reform school', 'boot camp',
            'facility', 'program', 'institution',
            'baltimore city facilities', 'city facilities',
            // Generic people terms
            'adolescent', 'adolescents', 'teenager', 'teenagers', 'teen', 'teens',
            'youth', 'children', 'child', 'minor', 'minors', 'juvenile', 'juveniles',
            'survivor', 'survivors', 'victim', 'victims', 'student', 'students',
            // Generic news/legal terms
            'abuse allegations', 'allegations', 'misconduct',
            'investigation', 'report', 'news', 'article', 'lawsuit', 'lawsuit filed',
            'accountability', 'justice', 'legal', 'crime', 'criminal',
            'administration', 'leadership',
            // Generic location/policy terms
            'usa', 'us', 'u.s.', 'united states', 'america', 'national', 'unknown',
            'child welfare', 'system',
            'policy', 'regulation', 'bill', 'law',
            'safety', 'health', 'protection', 'security',
            // Specific topics better suited for content warnings
            'psychotropic medication', 'unsanitary conditions',
        ];
    }
}

if (!function_exists('kop_news_tag_case')) {
    /** Title-case a tag while keeping short acronyms and known brand spellings. */
    function kop_news_tag_case(string $tag): string {
        static $overrides = [
            'wwasp' => 'WWASP',
            'maclaren' => 'MacLaren',
            'of' => 'of', 'and' => 'and', 'the' => 'the', 'for' => 'for', 'in' => 'in', 'on' => 'on',
        ];
        $words = explode(' ', $tag);
        $out = [];
        foreach ($words as $i => $word) {
            $lower = strtolower($word);
            if (isset($overrides[$lower]) && ($i > 0 || !ctype_lower($overrides[$lower][0]))) {
                $out[] = $overrides[$lower];
            } elseif (strlen($word) <= 4 && strtoupper($word) === $word) {
                // All-caps acronyms (TTI, UHS, ACHC) stay as written.
                $out[] = $word;
            } else {
                // Capitalize after hyphens too: "Faith-Based", "Self-Harm".
                $out[] = implode('-', array_map('ucfirst', explode('-', $lower)));
            }
        }
        return implode(' ', $out);
    }
}

if (!function_exists('kop_news_tag_canonical')) {
    /**
     * Canonical spelling of one tag, or '' for an empty one. Does not drop
     * excluded tags; see kop_news_tag_display() for that.
     */
    function kop_news_tag_canonical($tag): string {
        $tag = (string) $tag;
        // Non-breaking and other Unicode hyphens (the AI emits U+2011) -> '-'.
        $tag = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $tag) ?? $tag;
        $tag = preg_replace('/\s+/u', ' ', trim($tag)) ?? trim($tag);
        if ($tag === '') {
            return '';
        }
        $lower = strtolower($tag);
        $map = kop_news_tag_synonyms();
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        return kop_news_tag_case($tag);
    }
}

if (!function_exists('kop_news_tag_display')) {
    /** Canonical tag for the feed, or null when it is generic and hidden. */
    function kop_news_tag_display($tag): ?string {
        $canonical = kop_news_tag_canonical($tag);
        if ($canonical === '' || in_array(strtolower($canonical), kop_news_tag_excluded(), true)) {
            return null;
        }
        return $canonical;
    }
}

if (!function_exists('kop_news_tags_normalize')) {
    /**
     * Canonicalize and dedupe a tag list for storage (order kept, first
     * spelling wins). Excluded tags are kept; the feed hides them.
     */
    function kop_news_tags_normalize($tags): array {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : preg_split('/\r?\n/', $tags);
        }
        if (!is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $canonical = kop_news_tag_canonical($tag);
            if ($canonical !== '' && !isset($out[strtolower($canonical)])) {
                $out[strtolower($canonical)] = $canonical;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('kop_news_tag_prompt_vocabulary')) {
    /** Preferred topic labels, listed in the AI extraction prompt. */
    function kop_news_tag_prompt_vocabulary(): array {
        return [
            'Wilderness Therapy', 'Juvenile Justice', 'Transport', 'Forced Labor', 'Foster Care',
            'Group Home', 'Child Protective Services', 'Family Law',
            'Faith-Based Programs', 'Conversion Therapy', 'Human Trafficking', 'Kidnapping',
            'Death', 'Suicide', 'Self-Harm', 'Escape', 'Riot', 'Arrest', 'Trial', 'Settlement',
            'Closure', 'Licensing', 'Oversight', 'Legislation', 'Corporate Governance',
            'Staff Misconduct', 'Sexual Misconduct', 'Safety Violations', 'Survivor Stories',
            'Documentary', 'Adoption', 'Immigration', 'Disability Rights', 'LGBTQ+ Rights',
            'Private Schools', 'Private Prisons', 'Medicaid',
        ];
    }
}
