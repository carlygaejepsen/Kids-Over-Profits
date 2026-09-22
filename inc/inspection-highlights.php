<?php
/**
 * Inspection highlights: find the most serious findings in the scraped
 * inspection reports (docs/FIX-PLAN-2026-09.md item 14).
 *
 * Three steps, all in this file and none of them needing WordPress:
 *
 *   extract  one adapter per state turns a report row into findings, each
 *            with the state's own words, the standard cited and whatever
 *            severity or substantiation the state recorded
 *   score    a finding is matched, sentence by sentence, against categories
 *            of harm; negated and hypothetical mentions are dropped, and the
 *            state's own signal scales the result
 *   store    candidates go to inspection_highlights as pending; a re-run
 *            adds new ones and never touches a row a person has reviewed
 *
 * Three gates keep the queue short (owner rules, 2026-09-21):
 *
 *   substantiated  only findings the state itself confirmed are queued: a
 *                  Texas citation, a California deficiency, or a California
 *                  complaint the analyst substantiated. Inconclusive and
 *                  partly substantiated reports count only where the
 *                  substantiated verdict sits with the sentence quoted.
 *   peers          an assault by another child is not queued (physical or
 *                  sexual); the categories are about what adults did.
 *   elopement      a child running away is not queued on its own, only when
 *                  the same finding records a death or a serious injury.
 *
 * Nothing here publishes anything. A candidate names a facility and
 * describes harm, so it stays pending until an admin approves it.
 *
 * Callers: api/scan-inspection-highlights.php (cron and admin) and
 * scripts/test-inspection-highlights.php (offline, SQLite mirror).
 */

if (!function_exists('kop_ih_scanner_version')) {

    /** Bump when the rules change; the scanner then looks at every report again. */
    function kop_ih_scanner_version() {
        return 3;
    }

    /** Candidates scoring below this are not queued. */
    function kop_ih_min_score() {
        return 30;
    }

    /** A finding at or above this is severe: the ones the site highlights, most recent first. */
    function kop_ih_severe_score() {
        return 70;
    }

    /**
     * The states write dates as text in several ways ("10/02/2023",
     * "April 25, 2025", "9/13/2023 - 9/14/2023", "3/23/25"). Returns Y-m-d for
     * the first date in the text, or null. A date more than a year ahead or
     * before 1990 is a scraping fault and is not trusted.
     */
    function kop_ih_parse_date($text) {
        $text = trim((string) $text);
        $y = $m = $d = 0;
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $p)) {
            list(, $y, $m, $d) = $p;
        } elseif (preg_match('#\b(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})\b#', $text, $p)) {
            list(, $m, $d, $y) = $p;
            if (strlen($y) === 2) $y = ((int) $y > 70 ? 1900 : 2000) + (int) $y;
        } elseif (preg_match('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? (\d{1,2}),? (\d{4})\b/i', $text, $p)) {
            $m = 1 + array_search(strtolower($p[1]), array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'), true);
            $d = $p[2];
            $y = $p[3];
        } else {
            return null;
        }
        $y = (int) $y; $m = (int) $m; $d = (int) $d;
        if (!checkdate($m, $d, $y) || $y < 1990 || $y > (int) gmdate('Y') + 1) return null;
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Categories of harm, worst first. Patterns match inside one sentence.
     * 'requires' is a second pattern the same sentence must also match;
     * 'exclude' is one it must not.
     */
    function kop_ih_categories() {
        return array(
            'death' => array(
                'label'    => 'Death',
                'weight'   => 100,
                // Form names, risk ratings, animals, and deaths outside the facility's care.
                'exclude'  => '\b(?:Accident,? or Death|Death Report|Death Certificate|(?:homicid|suicid)\w+ (?:risk|ideations?|thoughts?|plans?)|risk (?:of|for) (?:homicide|suicide|death)|(?:dead|deceased) (?:bird|animal|rodent|mouse|mice|rat|insect|bug|fish|cat|dog)s?|death of (?:a |the |their |his |her )?(?:parent|mother|father|family member|grandparent|grandmother|grandfather|relative|sibling|brother|sister|pet|friend))\b',
                'patterns' => array(
                    '\b(?:died|dies|death|deceased|fatal(?:ly|ity|ities)?|passed away|homicide|killed|found (?:dead|deceased|unresponsive)|(?:completed|died by|committed) suicide)\b',
                ),
            ),
            'sexual_abuse' => array(
                'label'    => 'Sexual abuse',
                'weight'   => 90,
                'exclude'  => kop_ih_peer_pattern(),
                'patterns' => array(
                    '\bsexual(?:ly)? (?:abus\w+|assault\w*|misconduct|contact|relationships?|activity|intercourse|acts?|exploit\w+|harass\w+|inappropriate|touch\w*)',
                    '\b(?:rape[ds]?|raping|molest\w+|sodomi\w+|fondl\w+|grop(?:ed|ing)|sexting)\b',
                    '\b(?:sex|sexual relations) with (?:a |the |another )?(?:child|client|resident|minor|youth|student)',
                    '\binappropriate(?:ly)? (?:sexual|touch\w*|relationship)',
                    '\bnude (?:photo|picture|image|video)s?\b',
                ),
            ),
            'physical_abuse' => array(
                'label'    => 'Physical abuse or assault',
                'weight'   => 80,
                // Another child, or a child assaulting staff, is not staff assaulting a child.
                'exclude'  => kop_ih_peer_pattern() . '|\b(?:assault\w*|aggress\w*|attack\w*|violen\w+)\s+(?:against|on|toward|towards)\s+(?:the |a |an |facility |program )?(?:staff|personnel|employees?|caregivers?|nurses?)\b',
                'patterns' => array(
                    // Staff by role, or by the labels the states use: S1 (California), E1 (Arizona).
                    '\b(?:staff|caregiver|employee|counselor|supervisor|houseparent|house parent|administrator|teacher|[SE]\d{1,2})s?\b[^.]{0,80}\b(?:hit|hitting|struck|punch\w*|slapp\w+|kick\w*|chok\w+|shov\w+|threw|thrown|slamm\w+|dragg\w+|assault\w*|beat|beating|spank\w+|whipp\w+|pinch\w+|bit)\b',
                    '\bphysical(?:ly)? (?:abus\w+|assault\w*)',
                    '\bcorporal punishment\b',
                ),
            ),
            'restraint_injury' => array(
                'label'    => 'Restraint or seclusion causing injury',
                'weight'   => 75,
                'patterns' => array(
                    '\b(?:restrain\w*|seclu\w+|physical holds?|prone|take-?downs?|emergency behavior intervention|EBI|personal restraint)\b',
                ),
                'requires' => '\b(?:injur\w+|bruis\w+|fractur\w+|broken?|bleed\w*|blood\w*|concussion|abrasions?|rug burns?|carpet burns?|swollen|swelling|lacerat\w+|unconscious|(?:could not|couldn\'t|unable to) breathe|dislocat\w+|sprain\w*|scratch\w*|marks?)\b',
            ),
            'self_harm' => array(
                'label'    => 'Suicide attempt or self-harm',
                'weight'   => 65,
                // A ligature hazard in the building is a physical plant finding, not an event.
                'exclude'  => '\banti-?ligature\b|\bligature[- ](?:risk|point|hazard|resistant|free)s?\b',
                'patterns' => array(
                    '\b(?:suicide attempts?|attempt\w* (?:to commit )?suicide|suicidal (?:gesture|attempt)s?|self[- ]harm\w*|self[- ]injur\w+|ligatures?)\b',
                    '\b(?:cut|cutting|hang(?:ed|ing)?|strangl\w+) (?:him|her|them)sel(?:f|ves)\b',
                    '\b(?:ingest\w+|swallow\w+|overdos\w+)\b',
                ),
            ),
            'medical_neglect' => array(
                'label'    => 'Medical neglect',
                'weight'   => 60,
                'patterns' => array(
                    '\b(?:fail\w+|did not|didn\'t|neglected|refus\w+) to (?:seek|obtain|provide|get|administer|give)\b[^.]{0,40}\b(?:medical|treatment|medications?|prescri\w+)\b',
                    '\b(?:medical neglect|denied (?:medical|medications?)|medication errors?|wrong (?:medication|dose|dosage)|missed (?:dose|medication)s?)\b',
                    '\bdelay\w* (?:in )?(?:seeking |obtaining |getting )?(?:medical|treatment)\b',
                    '\b(?:was|were) not (?:given|administered|provided) (?:his |her |their |the )?(?:prescribed )?medications?\b',
                ),
            ),
            'hospitalization' => array(
                'label'    => 'Hospitalisation',
                'weight'   => 55,
                'patterns' => array(
                    '\b(?:hospitali[sz]\w+|emergency (?:room|department)|urgent care|ambulance|paramedics?|EMS|life[- ]?flight\w*|stitches|sutures)\b',
                    '\b(?:taken|transported|sent|admitted|rushed|brought) to (?:the |a |an )?(?:local |nearest |psychiatric |children\'s )?(?:hospital|ER)\b',
                    '\bcalled 9-?1-?1\b',
                ),
            ),
            'missing' => array(
                'label'    => 'Child missing or ran away',
                'weight'   => 45,
                'patterns' => array(
                    '\b(?:abscond\w*|ran away|run ?aways?|running away|AWOL|elope[ds]?|elopements?|unauthorized (?:absences?|departures?))\b',
                    '\bmissing (?:child|client|resident|youth)\b',
                    '\b(?:child|children|clients?|residents?|youths?|minors?)\b[^.,;]{0,30}\b(?:went|was|were|reported|found) missing\b',
                    '\bmissing from (?:the |their )?(?:facility|operation|home|campus|placement|care|premises)\b',
                    '\bleft (?:the )?(?:facility|campus|premises|property|home|grounds) without\b',
                    '\bwhereabouts (?:were |was |are |is )?unknown\b',
                ),
            ),
            'police' => array(
                'label'    => 'Police involvement',
                'weight'   => 40,
                'patterns' => array(
                    '\b(?:police|law enforcement|sheriff\w*|arrest\w*|detectives?|criminal charges?|charged with)\b',
                ),
                // A sentence that only lists the investigator's sources is not police involvement.
                'exclude'  => '\b(?:reports?|records?|interview\w*|footage|documents?|documentation)\b',
            ),
        );
    }

    /** Words that, shortly before a match in the same sentence, mean it did not happen. */
    function kop_ih_negation_pattern() {
        return '\b(?:no|not|never|without|none|neither|nor|denied|denies|deny|unfounded|unsubstantiated|ruled out|free (?:of|from)|absence of|lack of evidence|did not|didn\'t|does not|was not|wasn\'t|were not|weren\'t|cannot|could not be)\b';
    }

    /** Words that mean the sentence talks about a possibility, a rule or a plan, not an event. */
    function kop_ih_hypothetical_pattern() {
        return '\b(?:risk of|at risk|potential(?:ly)?|possib\w+|could|can|may|might|would|should|shall|must|lead(?:s|ing)? to|in (?:the )?(?:case|event) of|if|prevent\w*|polic(?:y|ies)|procedures?|training on|trained (?:on|in)|how to|requires?|required to report|hop(?:ed|es|ing)|wish\w*|threat\w*|claim\w*|jok\w+|histor(?:y|ies) of)\b';
    }

    /**
     * A whole sentence that is not about an event at the facility, whatever
     * words it holds: a policy or plan being quoted, an instruction, a list
     * of training topics. Arizona surveyors quote policies and intake
     * histories at length; Connecticut letters tell the program what to submit.
     */
    function kop_ih_noise_pattern() {
        return '\b(?:polic(?:y|ies)|procedures?|handbook|manual|guidelines?|protocols?|(?:treatment|service|care|safety) plan)\b[^.]{0,60}\b(?:stated?|states|reads?|indicated?|says|said|require[sd]?|titled|outlin\w+|includ\w+)\b'
            // An instruction or a consequence, allowing for a list marker such as "a)" or "3." in front.
            . '|^(?:\W*[a-z0-9]{1,2}[).]\s*)?\W*(?:in the event|if|when|should|unless|staff (?:are|is|will|shall|must) (?:to )?|the (?:facility|provider|program|operation|agency|licensee) (?:will|shall|must)|submit (?:a|an|the)|please|(?:the |this )?deficient practice)\b'
            . '|\b(?:trainings?|certificat\w+|curricul\w+|courses?)\b[^.]{0,100}\b(?:Reporting|Prevention|Recogni\w+|Awareness|Intervention)\b'
            // A statute or rule being quoted.
            . '|\b(?:A\.R\.S\.|A\.A\.C\.|R9-\d+[\w.\-]*|WAC \d+|R\d{3}-\d+[\w()\-]*|statute|regulation|rule)\b[^.]{0,40}\b(?:states?|requires?|provides?|defines?)\b'
            . '|\b(?:any|a) person who\b|\breasonably believes\b|\bincidents that must be reported\b'
            // An intake history (a list after "history of") or what happened at an earlier placement.
            . '|\bhistor(?:y|ies) of\b[^.]*,[^.]*,'
            . '|\b(?:previous|prior|former|last) (?:group home|placement|facility|home|foster home|program|school|provider)\b';
    }

    /** How far back from a match the negation and hypothetical cues are looked for. */
    function kop_ih_cue_window() {
        return 60;
    }

    /**
     * A sentence in which the other party is a child: "by another child",
     * "between the residents", "C1 ... with C2" (California labels clients
     * C1, C2 and staff S1, S2). Such a sentence is not physical or sexual
     * abuse for the queue, whatever the verb.
     */
    function kop_ih_peer_pattern() {
        // A client label: "C1", "Y2", "Client #2", "Child 1 (C1)", each maybe followed by a
        // form reference in brackets. Staff are S1, S2 and never match.
        $label = '(?:(?:Client|Child|Youth|Resident|Minor) ?#?\s?\d+(?:\s*\([CYR]\d+\))?|\b[CYR]\d+\b)(?:\s*\([^)]{0,60}\))?';
        $second = '(?:(?:Client|Child|Youth|Resident|Minor) ?)?\(?' . $label;
        $verb = '(?:hit|hitting|struck|punch|slapp|kick|chok|shov|assault|attack|fought|fight|beat|touch|grop|fondl|rape|raping|molest|sexual|engag|had sex)';
        $noun = '(?:child|children|client|clients|resident|residents|youth|youths|minor|minors|student|students|peer|peers|kids?|roommates?)';
        $other = '(?:another|other|fellow|younger|older) ' . $noun;
        $plural = '(?:children|clients|residents|youths?|minors|students|peers|kids)';
        // "by another child" is the other child acting; "the staff hit another resident" is not.
        return '\b(?:by|with|from|between) ' . $other . '\b'
            . '|\b' . $noun . '\s+(?:\w+\s+){0,3}' . $verb . '\w*\s+(?:\w+\s+){0,2}' . $other . '\b'
            . '|\b' . $other . '\s+(?:\w+\s+){0,2}' . $verb
            . '|\b(?:two|three|four|five|several|multiple) ' . $plural . '\s+(?:\w+\s+){0,3}(?:engag|had sex|sexual|fought|fight|assault)'
            . '|\b(?:by|with|from) (?:a |his |her |their |the )?peers?\b'
            . '|\b(?:each other|one another)\b'
            . '|\bpeer[- ]?(?:to|on)[- ]?peer\b'
            . '|\b(?:child|resident|client|youth|minor|student)[- ]?(?:to|on)[- ]?(?:child|resident|client|youth|minor|student)\b'
            . '|\bbetween (?:the |two |three |four |several |multiple |two of the |the other )?' . $plural . '\b'
            . '|\bbetween ' . $second
            // "C1 was assaulted by Client #2", "C1 hit C2", "C1 and C2 engaged in": two clients joined by the verb.
            . '|' . $label . '[\])]?\s+(?:\w+\s+){0,4}' . $verb . '\w*[^.]{0,40}?\s(?:by|with|on|against|toward|towards|and)\s+' . $second
            . '|' . $label . '[\])]?\s+(?:\w+\s+){0,2}' . $verb . '\w*\s+' . $second
            . '|' . $label . '[\])]?\s+and\s+' . $second . '[\])]?\s+(?:\w+\s+){0,3}' . $verb;
    }

    /** The ways a state says an allegation did not hold up. */
    function kop_ih_unsubstantiated_pattern() {
        return '\bun-?substantiated\b|\bunfounded\b|\bnot substantiated\b'
            . '|\b(?:cannot|can ?not|could not|couldn\'t|unable to|not|never|(?:has|have|had) not) be(?:en)? substantiated\b'
            . '|\b(?:unable|insufficient(?: evidence)?|not enough(?: evidence)?|fail\w*) to substantiate\b'
            . '|\b(?:did|does|do) not substantiate\b';
    }

    /**
     * What one sentence says about the allegation: 'substantiated',
     * 'unsubstantiated', or null when it says nothing. A sentence that says
     * both is read as unsubstantiated, so it can never carry a finding.
     */
    function kop_ih_sentence_verdict($sentence) {
        if (preg_match('/' . kop_ih_unsubstantiated_pattern() . '/iu', $sentence)) return 'unsubstantiated';
        if (preg_match('/\bsubstantiated\b/iu', $sentence)) return 'substantiated';
        return null;
    }

    /** How many sentences after a quoted one its verdict may stand. */
    function kop_ih_verdict_window() {
        return 3;
    }

    /**
     * Whether the sentence at $i is covered by a substantiated verdict: the
     * first verdict at or after it, within the window, says substantiated.
     */
    function kop_ih_verdict_near(array $verdicts, $i) {
        for ($j = $i; $j <= $i + kop_ih_verdict_window(); $j++) {
            if (!isset($verdicts[$j])) continue;
            return $verdicts[$j] === 'substantiated';
        }
        return false;
    }

    /** An injury serious enough for a runaway finding to stay in the queue. */
    function kop_ih_serious_injury_pattern() {
        return '\b(?:serious(?:ly)? (?:injur\w+|hurt)|(?:severe|significant|substantial|critical|life[- ]threatening) (?:physical |bodily )?injur\w+'
            . '|fractur\w+|broken (?:arm|leg|bone|nose|jaw|wrist|ankle|rib|hand|foot|collarbone|skull)s?|concussion|unconscious|lacerat\w+'
            . '|stitches|sutures|surgery|(?:hit|struck) by a (?:car|vehicle|truck|train)|hypothermia|frostbite|drown\w*)\b';
    }

    /** Whether a sentence has a match of $pattern with no negation or hypothetical cue shortly before it. */
    function kop_ih_sentence_has($sentence, $pattern) {
        if (!preg_match_all('/' . $pattern . '/iu', $sentence, $m, PREG_OFFSET_CAPTURE)) return false;
        $neg = '/' . kop_ih_negation_pattern() . '/iu';
        $hyp = '/' . kop_ih_hypothetical_pattern() . '/iu';
        foreach ($m[0] as $hit) {
            $start = max(0, $hit[1] - kop_ih_cue_window());
            $before = substr($sentence, $start, $hit[1] - $start);
            if (preg_match($neg, $before) || preg_match($hyp, $before)) continue;
            return $hit[0];
        }
        return false;
    }

    /**
     * Collapse whitespace; the text is otherwise kept as the state wrote it.
     * Text that is not UTF-8 (a scraper that kept Windows bytes) is converted,
     * because the /u patterns silently match nothing on invalid input.
     */
    function kop_ih_clean_text($text) {
        $text = (string) $text;
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $text = str_replace(array("\xE2\x80\x8B", "\xC2\xA0"), array('', ' '), $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Sentences of a text, kept verbatim. Common abbreviations do not end one. */
    function kop_ih_split_sentences($text) {
        $text = kop_ih_clean_text($text);
        if ($text === '') return array();
        $guard = preg_replace('/\b(Mr|Mrs|Ms|Dr|St|No|Inc|Sec|approx|vs|etc|a\.m|p\.m|[A-Z])\./u', '$1<<DOT>>', $text);
        // A list number opening a sentence ("1. A review of ...") stays with its sentence.
        $guard = preg_replace('/(^|[.!?] )(\d{1,2})\.(?= [A-Z])/u', '$1$2<<DOT>>', (string) $guard);
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(*])/u', (string) $guard);
        $out = array();
        foreach ((array) $parts as $p) {
            $p = trim(str_replace('<<DOT>>', '.', $p));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /**
     * Categories one sentence matches, after dropping negated and
     * hypothetical mentions. Returns category key => matched words.
     */
    function kop_ih_match_sentence($sentence) {
        $found = array();
        foreach (kop_ih_categories() as $key => $cat) {
            if (!empty($cat['requires']) && !preg_match('/' . $cat['requires'] . '/iu', $sentence)) continue;
            if (!empty($cat['exclude']) && preg_match('/' . $cat['exclude'] . '/iu', $sentence)) continue;
            foreach ($cat['patterns'] as $pattern) {
                $words = kop_ih_sentence_has($sentence, $pattern);
                if ($words === false) continue;
                $found[$key] = $words;
                break;
            }
        }
        return $found;
    }

    // -----------------------------------------------------------------------
    // Extract: one adapter per state
    // -----------------------------------------------------------------------

    /** States with an adapter. The others are left for the text adapter to come. */
    function kop_ih_supported_states() {
        return array('TX', 'CA', 'UT', 'AZ', 'CT');
    }

    /**
     * How far a cited deficiency with no severity or outcome attached is
     * trusted: fully when the state was investigating a complaint or an
     * incident (it looked and confirmed), a little less on a routine
     * inspection. Texas has its own risk levels and California its outcomes.
     */
    function kop_ih_citation_factor($investigation) {
        return $investigation ? 1.0 : 0.85;
    }

    /**
     * Findings in one report. $row needs id, facility_id, report_id,
     * report_date and categories_json, plus raw_content for the states that
     * keep the findings in the report text. Each finding:
     *   text               the state's words, verbatim apart from whitespace
     *   standard           the rule cited, when the state names one
     *   state_label        the state's severity or substantiation, as written
     *   factor             0..1, how much the state's signal backs the finding
     *   corrected_on_site  true, false, or null when the state does not say
     *   kind               citation | complaint | evaluation
     */
    function kop_ih_extract($state, array $row) {
        $data = json_decode((string) ($row['categories_json'] ?? ''), true);
        if (!is_array($data)) return array();
        switch (strtoupper((string) $state)) {
            case 'TX': return kop_ih_extract_tx($data);
            case 'CA': return kop_ih_extract_ca($data);
            case 'UT': return kop_ih_extract_ut($data, (string) ($row['raw_content'] ?? ''));
            case 'AZ': return kop_ih_extract_az($data);
            case 'CT': return kop_ih_extract_ct($data, (string) ($row['raw_content'] ?? ''));
        }
        return array();
    }

    /**
     * Utah: the report text is one line per rule cited, "R501-19-4(4)(a)-(j):
     * Supervision and ratio requirements - The provider was out of compliance
     * with ... by ...", sometimes followed by a line noting a repeat. Checklist
     * lines (census, licensor) are not findings. An investigation or focus
     * inspection is the Office of Licensing confirming an incident.
     */
    function kop_ih_extract_ut(array $data, $raw) {
        $type = (string) ($data['Inspection Type'] ?? '');
        $factor = kop_ih_citation_factor((bool) preg_match('/investigation|focus|complaint|incident/i', $type));
        $label = $type !== '' ? 'Out of compliance, ' . strtolower($type) : 'Out of compliance';
        $findings = array();
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $line = kop_ih_clean_text($line);
            if ($line === '' || preg_match('/^Checklist \d+:/i', $line)) continue;
            if (preg_match('/^(R\d{3}-[\w()\-]+):\s*(.*)$/u', $line, $m)) {
                $title = $m[2];
                $text = $m[2];
                if (preg_match('/^(.*?)\s+(?:\x{2014}|\x{2013}|-)\s+(.*)$/u', $m[2], $d)) {
                    $title = $d[1];
                    $text = $d[2];
                }
                $findings[] = array(
                    'text' => $text, 'standard' => kop_ih_clean_text($m[1] . ' ' . $title), 'state_label' => $label,
                    'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
                );
            } elseif ($findings) {
                $findings[count($findings) - 1]['text'] .= ' ' . $line;
            }
        }
        return $findings;
    }

    /**
     * Arizona: each deficiency has the rule, the surveyor's evidence
     * statement ("Based on record review and interview, the administrator
     * failed to ...") and numbered findings. Residents are R1, R2 and
     * employees E1, E2. A complaint inspection is the Department confirming
     * what was reported.
     */
    function kop_ih_extract_az(array $data) {
        $type = (string) ($data['inspection_type'] ?? '');
        $factor = kop_ih_citation_factor((bool) preg_match('/complaint/i', $type));
        $label = 'Deficiency cited' . ($type !== '' ? ', ' . strtolower(str_replace(';', ' and ', $type)) : '');
        $out = array();
        foreach ((array) ($data['deficiencies'] ?? array()) as $d) {
            if (!is_array($d)) continue;
            $text = kop_ih_clean_text(str_replace(array("\\'a7", '\\\'a7'), '§', ($d['evidence'] ?? '') . ' ' . ($d['findings'] ?? '')));
            if ($text === '') continue;
            $rule = kop_ih_clean_text($d['rule'] ?? '');
            if (mb_strlen($rule) > 160) {
                $cut = mb_substr($rule, 0, 160);
                $space = mb_strrpos($cut, ' ');
                $rule = rtrim($space > 100 ? mb_substr($cut, 0, $space) : $cut, ' ,;:') . ' ...';
            }
            $out[] = array(
                'text' => $text, 'standard' => $rule, 'state_label' => $label,
                'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Connecticut: a DCF field visit form carries an "Areas of regulatory
     * non-compliance identified during this visit" section, and a licensing
     * letter lists "the areas of non-compliance are as follows". The section
     * is split at each regulation cited (17a-145-63, 17a-101). The scraped
     * JSON for these reports is unreliable (it split ratios like 3:12 as
     * fields), so the text is read from the report itself.
     */
    function kop_ih_extract_ct(array $data, $raw) {
        $raw = (string) $raw;
        if ($raw === '' && !empty($data['full_report_content'])) $raw = (string) $data['full_report_content'];
        $section = '';
        $investigation = false;
        if (preg_match('/Areas of regulatory non-?compliance identified during this visit:?\s*(.*?)(?:Please submit a|Regulatory Consultant|A COPY OF THIS SUMMARY|$)/isu', $raw, $m)) {
            $section = $m[1];
        } elseif (preg_match('/areas of non-?compliance are as follows:?\s*(.*?)(?:DCF licensing has determined|Sincerely|Please review the areas|$)/isu', $raw, $m)) {
            $section = $m[1];
        } elseif (preg_match('/Area Needing Attention\s*(.*?)(?:Sincerely|$)/isu', $raw, $m)) {
            $section = $m[1];
        }
        $section = kop_ih_clean_text(preg_replace('/[\x{2022}\x{25CF}\x{F0B7}]|\bo(?= [A-Z])/u', ' ', $section));
        if ($section === '' || preg_match('/^\W*(?:not applicable|none(?: noted| identified)?|n\/a)\b/iu', $section)) return array();
        if (preg_match('/complaint|investigat|incident/iu', $raw)) $investigation = true;
        $factor = kop_ih_citation_factor($investigation);
        $parts = preg_split('/(?=(?:Section |Sec\. )?\b17a-\d[\d\-]*\.?\s)/u', $section, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 30) continue;
            $standard = '';
            if (preg_match('/^(?:Section |Sec\. )?(17a-\d[\d\-]*\.?\s+[^.:]{0,80})/u', $part, $s)) $standard = rtrim($s[1], '. ');
            $out[] = array(
                'text' => $part, 'standard' => $standard, 'state_label' => 'Non-compliance cited by DCF',
                'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Texas: every row is one citation, with HHSC's own risk level. A
     * citation is a deficiency the inspector found, so it is substantiated
     * by nature; there is no complaint outcome to read.
     */
    function kop_ih_extract_tx(array $data) {
        $text = kop_ih_clean_text($data['Deficiency Narrative'] ?? '');
        if ($text === '') return array();
        $level = trim((string) ($data['Standard Risk Level'] ?? ''));
        $factors = array('high' => 1.0, 'medium high' => 0.85, 'medium' => 0.7, 'medium low' => 0.5, 'low' => 0.4);
        $corrected = strtolower(trim((string) ($data['Corrected at Inspection'] ?? '')));
        return array(array(
            'text'              => $text,
            'standard'          => kop_ih_clean_text($data['Standard Number / Description'] ?? ''),
            'state_label'       => $level !== '' ? 'Risk level: ' . $level : '',
            'factor'            => $factors[strtolower($level)] ?? 0.6,
            'corrected_on_site' => $corrected === 'yes' ? true : ($corrected === 'no' ? false : null),
            'kind'              => 'citation',
        ));
    }

    /** California form boilerplate that follows the analyst's text. */
    function kop_ih_ca_strip_boilerplate($text) {
        $text = kop_ih_clean_text($text);
        $text = (string) preg_replace('/^\d{1,2}(?=\D)/u', '', $text);
        $text = (string) preg_replace('/^continuation of LIC\s*\S*\s*/iu', '', $text);
        $cut = preg_split('/(?:(?:Un)?[Ss]ubstantiated\s*)?Estimated Days of Completion|SUPERVISORS NAME:|LICENSING EVALUATOR NAME:|STATE OF CALIFORNIA - HEALTH AND HUMAN SERVICES|This report must be available at/u', $text, 2);
        return trim((string) $cut[0]);
    }

    /**
     * California: a complaint investigation is one finding. The scraped
     * complaint_status is wrong for about one report in ten (a report that
     * substantiates one allegation and not another is filed as
     * unsubstantiated), so the outcome is read from the analyst's text.
     * Only a substantiated complaint is queued. A report that substantiates
     * one allegation and not another is queued only for a sentence the
     * substantiated verdict covers (kop_ih_verdict_near); an inconclusive
     * one never is. A facility evaluation counts only when its narrative
     * cites a deficiency.
     */
    function kop_ih_extract_ca(array $data) {
        $findings = kop_ih_ca_strip_boilerplate($data['investigation_findings'] ?? '');
        $narrative = kop_ih_ca_strip_boilerplate($data['narrative'] ?? '');
        $is_complaint = array_key_exists('investigation_findings', $data) || array_key_exists('complaint_status', $data);
        $text = trim($findings . ' ' . $narrative);
        if ($text === '') return array();

        if (!$is_complaint) {
            if (!preg_match('/deficienc(?:y|ies) (?:was|were|is|are) (?:being )?(?:cited|observed|issued)|(?:is|was|are|were) (?:being )?cited|Type A\b/iu', $text)) return array();
            return array(array(
                'text' => $text, 'standard' => '', 'state_label' => 'Deficiency cited at inspection',
                'factor' => 0.7, 'corrected_on_site' => null, 'kind' => 'evaluation',
            ));
        }

        $unsub = '/' . kop_ih_unsubstantiated_pattern() . '/iu';
        $has_sub = (bool) preg_match('/\bsubstantiated\b/iu', (string) preg_replace($unsub, ' ', $text));
        $has_unsub = (bool) preg_match($unsub, $text);
        $status = strtolower(trim((string) ($data['complaint_status'] ?? '')));

        $require_verdict = false;
        if ($has_sub && $has_unsub) {
            $label = 'Substantiated (one of several allegations)'; $factor = 1.0; $require_verdict = true;
        } elseif ($has_sub) {
            $label = 'Substantiated'; $factor = 1.0;
        } elseif ($has_unsub) {
            return array();
        } elseif ($status === 'substantiated') {
            $label = 'Substantiated'; $factor = 1.0;
        } else {
            return array();
        }

        $standard = '';
        if (preg_match_all('/\b(?:8\d{4}|1\d{5})(?:\([a-z0-9]+\))+/iu', $text, $m)) {
            $standard = implode(', ', array_slice(array_values(array_unique($m[0])), 0, 6));
        }
        return array(array(
            'text' => $text, 'standard' => $standard, 'state_label' => $label,
            'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'complaint',
            'require_verdict' => $require_verdict,
        ));
    }

    // -----------------------------------------------------------------------
    // Score
    // -----------------------------------------------------------------------

    /** Longest excerpt stored, in characters. Longer ones end at a sentence. */
    function kop_ih_excerpt_limit() {
        return 900;
    }

    /**
     * Score one finding. Returns null when no sentence describes harm, or the
     * candidate: category (the worst), categories, score 0..100, excerpt.
     * The excerpt is the matching sentences in the state's words, in order,
     * joined by " [...] " where text between them is left out.
     *
     * A finding with require_verdict set (a California report that
     * substantiates some allegations and not others) keeps only the
     * sentences a substantiated verdict covers. A finding whose only
     * categories are the child going missing, and the police looking, is
     * dropped unless the text records a serious injury; a death or a
     * hospital visit is its own category and keeps it anyway.
     */
    function kop_ih_score_finding(array $finding) {
        $cats = kop_ih_categories();
        $hits = array();
        $matched = array();
        $sentences = kop_ih_split_sentences($finding['text']);
        $verdicts = array();
        if (!empty($finding['require_verdict'])) {
            foreach ($sentences as $i => $sentence) {
                $v = kop_ih_sentence_verdict($sentence);
                if ($v !== null) $verdicts[$i] = $v;
            }
        }
        foreach ($sentences as $i => $sentence) {
            // A sentence that itself says the allegation failed is not a finding; nor is a quoted policy or an instruction.
            if (preg_match('/' . kop_ih_unsubstantiated_pattern() . '/iu', $sentence)) continue;
            if (preg_match('/' . kop_ih_noise_pattern() . '/iu', $sentence)) continue;
            if (!empty($finding['require_verdict']) && !kop_ih_verdict_near($verdicts, $i)) continue;
            $found = kop_ih_match_sentence($sentence);
            if (!$found) continue;
            $top = 0;
            foreach ($found as $key => $words) {
                $hits[$key] = true;
                $top = max($top, $cats[$key]['weight']);
            }
            $matched[] = array('i' => $i, 'weight' => $top, 'text' => $sentence);
        }
        if (!$hits) return null;
        if (!array_diff(array_keys($hits), array('missing', 'police'))) {
            $injured = false;
            foreach ($sentences as $sentence) {
                if (kop_ih_sentence_has($sentence, kop_ih_serious_injury_pattern()) !== false) { $injured = true; break; }
            }
            if (!$injured) return null;
        }

        $weights = array();
        foreach (array_keys($hits) as $key) $weights[$key] = $cats[$key]['weight'];
        arsort($weights);
        $primary = array_key_first($weights);
        $raw = $weights[$primary] + min(15, 5 * (count($weights) - 1));
        $score = (int) round(min(100, $raw * (float) $finding['factor']));

        // Worst sentences first until the limit, then back in document order.
        usort($matched, static function ($a, $b) {
            return $b['weight'] <=> $a['weight'] ?: $a['i'] <=> $b['i'];
        });
        $kept = array();
        $len = 0;
        foreach ($matched as $s) {
            $l = strlen($s['text']);
            if ($kept && $len + $l > kop_ih_excerpt_limit()) continue;
            $kept[] = $s;
            $len += $l;
        }
        usort($kept, static function ($a, $b) { return $a['i'] <=> $b['i']; });
        $excerpt = '';
        $prev = null;
        foreach ($kept as $s) {
            if ($prev !== null) $excerpt .= ($s['i'] === $prev + 1) ? ' ' : ' [...] ';
            $excerpt .= $s['text'];
            $prev = $s['i'];
        }

        return array(
            'category'          => $primary,
            'categories'        => array_keys($weights),
            'score'             => $score,
            'excerpt'           => $excerpt,
            'standard'          => (string) $finding['standard'],
            'state_label'       => (string) $finding['state_label'],
            'corrected_on_site' => $finding['corrected_on_site'],
            'kind'              => (string) $finding['kind'],
        );
    }

    /** Candidates for one report row, each with a key that is stable across runs. */
    function kop_ih_candidates($state, array $row) {
        $out = array();
        foreach (kop_ih_extract($state, $row) as $n => $finding) {
            $c = kop_ih_score_finding($finding);
            if ($c === null || $c['score'] < kop_ih_min_score()) continue;
            $c['text_hash'] = substr(sha1($finding['text']), 0, 16);
            $c['finding_key'] = $n . ':' . $c['text_hash'];
            $out[] = $c;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Store
    // -----------------------------------------------------------------------

    function kop_ih_is_sqlite(PDO $pdo) {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /** Create the two tables when missing. Safe to call on every run. */
    function kop_ih_ensure_tables(PDO $pdo) {
        if (kop_ih_is_sqlite($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_highlights (
                id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INTEGER NOT NULL, facility_id INTEGER NOT NULL,
                finding_key TEXT NOT NULL, text_hash TEXT NOT NULL, state TEXT NOT NULL, category TEXT NOT NULL, categories TEXT NOT NULL,
                score INTEGER NOT NULL, finding_date TEXT, excerpt TEXT NOT NULL, standard TEXT, state_label TEXT, kind TEXT,
                corrected_on_site INTEGER, status TEXT NOT NULL DEFAULT 'pending', reviewed_by TEXT,
                reviewed_at TEXT, review_note TEXT, scanner_version INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (report_id, finding_key))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_highlight_scans (
                report_id INTEGER PRIMARY KEY, scanner_version INTEGER NOT NULL, scanned_at TEXT DEFAULT CURRENT_TIMESTAMP)");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_highlights (
            id int(11) NOT NULL AUTO_INCREMENT,
            report_id int(11) NOT NULL COMMENT 'inspection_reports.id',
            facility_id int(11) NOT NULL COMMENT 'inspection_facilities.id',
            finding_key varchar(40) NOT NULL COMMENT 'Finding index and text hash; stable across runs',
            text_hash char(16) NOT NULL COMMENT 'Hash of the finding text; the scrapers store some reports twice',
            state char(2) NOT NULL,
            category varchar(40) NOT NULL COMMENT 'Worst category of harm matched',
            categories varchar(255) NOT NULL COMMENT 'Every category matched, comma separated',
            score tinyint unsigned NOT NULL,
            finding_date date DEFAULT NULL COMMENT 'The report''s date, parsed; the reports table holds it as text',
            excerpt text NOT NULL COMMENT 'The state''s own words',
            standard varchar(500) DEFAULT NULL,
            state_label varchar(120) DEFAULT NULL COMMENT 'Severity or substantiation as the state recorded it',
            kind varchar(20) DEFAULT NULL,
            corrected_on_site tinyint(1) DEFAULT NULL,
            status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by varchar(100) DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            review_note varchar(500) DEFAULT NULL,
            scanner_version int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY report_finding (report_id, finding_key),
            KEY status_score (status, score),
            KEY status_date (status, finding_date),
            KEY facility_text (facility_id, text_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // The first production batch was saved before finding_date existed.
        if (!$pdo->query("SHOW COLUMNS FROM inspection_highlights LIKE 'finding_date'")->fetch()) {
            $pdo->exec("ALTER TABLE inspection_highlights
                ADD COLUMN finding_date date DEFAULT NULL COMMENT 'The report''s date, parsed; the reports table holds it as text' AFTER score,
                ADD KEY status_date (status, finding_date)");
        }
        kop_ih_backfill_dates($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_highlight_scans (
            report_id int(11) NOT NULL,
            scanner_version int(11) NOT NULL,
            scanned_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Fill finding_date on rows that have none. Touches no other field, whatever the status. */
    function kop_ih_backfill_dates(PDO $pdo) {
        $rows = $pdo->query('SELECT h.id, r.report_date FROM inspection_highlights h
            JOIN inspection_reports r ON r.id = h.report_id WHERE h.finding_date IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $set = $pdo->prepare('UPDATE inspection_highlights SET finding_date = ? WHERE id = ?');
        $filled = 0;
        foreach ($rows as $row) {
            $date = kop_ih_parse_date($row['report_date']);
            if ($date === null) continue;
            $set->execute(array($date, (int) $row['id']));
            $filled++;
        }
        return $filled;
    }

    /**
     * The approved severe findings, most recent first: what the site
     * highlights. Dated rows come before undated ones; on one day the worse
     * finding leads. Runs on MySQL and SQLite; pass the limit as an integer.
     */
    function kop_ih_recent_severe_sql($limit) {
        return "SELECT h.id, h.report_id, h.state, h.category, h.categories, h.score, h.finding_date, h.excerpt,
                   h.standard, h.state_label, f.facility_name, r.report_date, r.report_url, r.report_id AS source_report_id
            FROM inspection_highlights h
            JOIN inspection_facilities f ON f.id = h.facility_id
            JOIN inspection_reports r ON r.id = h.report_id
            WHERE h.status = 'approved' AND h.score >= " . (int) kop_ih_severe_score() . "
            ORDER BY (h.finding_date IS NULL) ASC, h.finding_date DESC, h.score DESC, h.id DESC
            LIMIT " . (int) $limit;
    }

    /**
     * Every approved severe finding, for the Severe Reports page and the flags
     * in the state trackers: same order as above, all of them, optionally one
     * state or one category. Returns array(sql, params) for a prepared query;
     * $limit 0 means no limit.
     */
    function kop_ih_severe_query($state = '', $category = '', $limit = 0, $offset = 0) {
        $where = "h.status = 'approved' AND h.score >= " . (int) kop_ih_severe_score();
        $params = array();
        if ($state !== '') { $where .= ' AND h.state = ?'; $params[] = strtoupper($state); }
        if ($category !== '' && isset(kop_ih_categories()[$category])) {
            // categories is a comma list. Four plain comparisons match a whole
            // entry on MySQL and SQLite alike (|| is OR on one and concat on the other).
            $where .= ' AND (h.categories = ? OR h.categories LIKE ? OR h.categories LIKE ? OR h.categories LIKE ?)';
            array_push($params, $category, $category . ',%', '%,' . $category, '%,' . $category . ',%');
        }
        $sql = "SELECT h.id, h.report_id, h.state, h.category, h.categories, h.score, h.finding_date, h.excerpt,
                   h.standard, h.state_label, h.corrected_on_site, f.facility_name, r.report_date, r.report_url,
                   r.report_id AS source_report_id
            FROM inspection_highlights h
            JOIN inspection_facilities f ON f.id = h.facility_id
            JOIN inspection_reports r ON r.id = h.report_id
            WHERE $where
            ORDER BY (h.finding_date IS NULL) ASC, h.finding_date DESC, h.score DESC, h.id DESC";
        if ($limit > 0) $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return array($sql, $params);
    }

    /**
     * What a tracker page looks for to flag a report: the first run of the
     * excerpt (up to the first gap), lower-cased with every space removed, so
     * line breaks and markup in the viewer cannot break the match.
     */
    function kop_ih_flag_needle($excerpt) {
        $parts = explode(' [...] ', (string) $excerpt, 2);
        $squashed = (string) preg_replace('/\s+/u', '', mb_strtolower($parts[0]));
        return mb_substr($squashed, 0, 160);
    }

    /**
     * Save one report's candidates. New ones are inserted as pending. A
     * pending one is refreshed; an approved or rejected one is left exactly
     * as the reviewer left it. A pending row the rules no longer produce is
     * removed. Returns counts: added, refreshed, kept, dropped.
     */
    function kop_ih_store(PDO $pdo, $state, array $row, array $candidates) {
        $counts = array('added' => 0, 'refreshed' => 0, 'kept' => 0, 'dropped' => 0, 'duplicate' => 0);
        $report_id = (int) $row['id'];
        $stmt = $pdo->prepare('SELECT id, finding_key, status FROM inspection_highlights WHERE report_id = ?');
        $stmt->execute(array($report_id));
        $existing = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) $existing[$e['finding_key']] = $e;

        foreach ($candidates as $c) {
            $values = array(
                $c['category'], implode(',', $c['categories']), $c['score'], $c['excerpt'],
                $c['standard'] !== '' ? substr($c['standard'], 0, 500) : null,
                $c['state_label'] !== '' ? $c['state_label'] : null, $c['kind'],
                $c['corrected_on_site'] === null ? null : (int) $c['corrected_on_site'],
                kop_ih_scanner_version(), kop_ih_parse_date($row['report_date'] ?? ''),
            );
            if (!isset($existing[$c['finding_key']])) {
                // The scrapers hold some reports under two ids; the first row scanned keeps the finding.
                $dup = $pdo->prepare('SELECT id FROM inspection_highlights WHERE facility_id = ? AND text_hash = ? AND report_id <> ? LIMIT 1');
                $dup->execute(array((int) $row['facility_id'], $c['text_hash'], $report_id));
                if ($dup->fetchColumn()) { $counts['duplicate']++; continue; }
                $pdo->prepare('INSERT INTO inspection_highlights
                    (category, categories, score, excerpt, standard, state_label, kind, corrected_on_site, scanner_version, finding_date,
                     report_id, facility_id, finding_key, text_hash, state, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array_merge($values, array($report_id, (int) $row['facility_id'], $c['finding_key'], $c['text_hash'], strtoupper($state), 'pending')));
                $counts['added']++;
            } elseif ($existing[$c['finding_key']]['status'] === 'pending') {
                $pdo->prepare('UPDATE inspection_highlights SET category=?, categories=?, score=?, excerpt=?, standard=?,
                    state_label=?, kind=?, corrected_on_site=?, scanner_version=?, finding_date=? WHERE id=?')
                    ->execute(array_merge($values, array((int) $existing[$c['finding_key']]['id'])));
                $counts['refreshed']++;
            } else {
                $counts['kept']++;
            }
            unset($existing[$c['finding_key']]);
        }
        foreach ($existing as $e) {
            if ($e['status'] !== 'pending') { $counts['kept']++; continue; }
            $pdo->prepare('DELETE FROM inspection_highlights WHERE id = ?')->execute(array((int) $e['id']));
            $counts['dropped']++;
        }
        return $counts;
    }

    /**
     * Scan up to $limit reports not yet seen at this scanner version.
     * With $apply false nothing is written and the candidates are returned
     * for a dry run. Returns scanned, remaining, the store counts, and
     * (dry run only) the candidates.
     */
    function kop_ih_scan(PDO $pdo, $limit = 2000, $apply = false, array $states = array()) {
        $states = $states ? array_values(array_intersect(array_map('strtoupper', $states), kop_ih_supported_states())) : kop_ih_supported_states();
        $result = array('scanned' => 0, 'remaining' => 0, 'added' => 0, 'refreshed' => 0, 'kept' => 0, 'dropped' => 0, 'duplicate' => 0, 'candidates' => array());
        $seen = array();
        if (!$states) return $result;
        if ($apply) kop_ih_ensure_tables($pdo);

        $in = implode(',', array_fill(0, count($states), '?'));
        $has_scans = $apply || kop_ih_table_exists($pdo, 'inspection_highlight_scans');
        $join = $has_scans ? 'LEFT JOIN inspection_highlight_scans s ON s.report_id = r.id AND s.scanner_version = ' . (int) kop_ih_scanner_version() : '';
        $where = "f.state IN ($in)" . ($has_scans ? ' AND s.report_id IS NULL' : '');

        $count = $pdo->prepare("SELECT COUNT(*) FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id $join WHERE $where");
        $count->execute($states);
        $pending = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT r.id, r.facility_id, r.report_id, r.report_date, r.categories_json, r.raw_content, f.state, f.facility_name
            FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id $join
            WHERE $where ORDER BY r.id ASC LIMIT " . (int) $limit);
        $stmt->execute($states);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mark = null;
        if ($apply) {
            $mark = $pdo->prepare(kop_ih_is_sqlite($pdo)
                ? 'INSERT OR REPLACE INTO inspection_highlight_scans (report_id, scanner_version) VALUES (?, ?)'
                : 'INSERT INTO inspection_highlight_scans (report_id, scanner_version) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE scanner_version = VALUES(scanner_version)');
            $pdo->beginTransaction();
        }
        foreach ($rows as $row) {
            $candidates = kop_ih_candidates($row['state'], $row);
            if ($apply) {
                foreach (kop_ih_store($pdo, $row['state'], $row, $candidates) as $k => $v) $result[$k] += $v;
                $mark->execute(array((int) $row['id'], kop_ih_scanner_version()));
            } else {
                foreach ($candidates as $c) {
                    $dup_key = (int) $row['facility_id'] . ':' . $c['text_hash'];
                    if (isset($seen[$dup_key])) { $result['duplicate']++; continue; }
                    $seen[$dup_key] = true;
                    $result['candidates'][] = $c + array(
                        'report_row' => (int) $row['id'], 'state' => $row['state'],
                        'finding_date' => kop_ih_parse_date($row['report_date']),
                        'facility_name' => $row['facility_name'], 'report_date' => $row['report_date'],
                    );
                }
            }
            $result['scanned']++;
        }
        if ($apply) $pdo->commit();
        $result['remaining'] = max(0, $pending - $result['scanned']);
        return $result;
    }

    function kop_ih_table_exists(PDO $pdo, $table) {
        try {
            $pdo->query('SELECT 1 FROM ' . preg_replace('/\W/', '', $table) . ' LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** A page a person can open for the report: the scraped link, or California's facility page. */
    function kop_ih_source_url(array $row) {
        $url = trim((string) ($row['report_url'] ?? ''));
        if ($url !== '') return $url;
        if (($row['state'] ?? '') === 'CA' && preg_match('/^(\d{6,})-/', (string) ($row['source_report_id'] ?? ''), $m)) {
            return 'https://www.ccld.dss.ca.gov/carefacilitysearch/FacDetail/' . $m[1];
        }
        return '';
    }

    /** An excerpt cut to fit a card, at a word, with the cut marked. */
    function kop_ih_card_excerpt($excerpt, $limit = 320) {
        $excerpt = trim((string) $excerpt);
        if (mb_strlen($excerpt) <= $limit) return $excerpt;
        $cut = mb_substr($excerpt, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $limit * 0.6) $cut = mb_substr($cut, 0, $space);
        return rtrim($cut, " ,;:") . ' [...]';
    }
}

// ---------------------------------------------------------------------------
// On the site (WordPress): the home page and the inspection reports hub
// ---------------------------------------------------------------------------

if (function_exists('get_transient') && !function_exists('kop_ih_site_highlights')) {

    /**
     * Approved severe findings, most recent first. Empty until the scan has
     * run and someone has approved something, so the pages can call it
     * unconditionally. A missing table is remembered for ten minutes only.
     */
    function kop_ih_site_highlights($limit) {
        global $wpdb;
        if (!kop_ih_site_ready()) return array();
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(kop_ih_recent_severe_sql($limit), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        return (array) $rows;
    }

    /** True once the highlights table exists in its current shape. A "no" is remembered for ten minutes only. */
    function kop_ih_site_ready() {
        global $wpdb;
        $ready = get_transient('kop_inspection_highlights_ready_v1');
        if ($ready === false) {
            $suppress = $wpdb->suppress_errors(true);
            $ready = $wpdb->get_var("SHOW COLUMNS FROM inspection_highlights LIKE 'finding_date'") ? 'yes' : 'no';
            $wpdb->suppress_errors($suppress);
            set_transient('kop_inspection_highlights_ready_v1', $ready, $ready === 'yes' ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
        }
        return $ready === 'yes';
    }

    /** Where every approved severe finding is listed. */
    function kop_ih_severe_page_url($finding_id = 0) {
        return home_url('/severe-reports/') . ($finding_id ? '#finding-' . (int) $finding_id : '');
    }

    /** All approved severe findings (one state or category when given), most recent first. */
    function kop_ih_site_severe($state = '', $category = '', $limit = 0, $offset = 0) {
        global $wpdb;
        if (!kop_ih_site_ready()) return array();
        list($sql, $params) = kop_ih_severe_query($state, $category, $limit, $offset);
        $sql = str_replace('?', '%s', $sql);
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);
        $wpdb->suppress_errors($suppress);
        return (array) $rows;
    }

    /** How many approved severe findings there are, per state code; 'all' holds the total. */
    function kop_ih_site_severe_counts() {
        global $wpdb;
        $counts = array('all' => 0);
        if (!kop_ih_site_ready()) return $counts;
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS n FROM inspection_highlights
            WHERE status = 'approved' AND score >= " . (int) kop_ih_severe_score() . ' GROUP BY state', ARRAY_A);
        $wpdb->suppress_errors($suppress);
        foreach ((array) $rows as $row) {
            $counts[strtoupper($row['state'])] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }
        return $counts;
    }

    /** Cards for the "demand attention" grid, in the markup the hand-featured cards use. */
    function kop_ih_render_cards(array $rows, array $tracker_slugs) {
        $categories = kop_ih_categories();
        foreach ($rows as $row) {
            $tracker = strtolower($row['state']) . '-reports';
            $date = $row['finding_date'] ? date_i18n('F j, Y', strtotime($row['finding_date'] . ' 12:00:00')) : trim((string) $row['report_date']);
            $source = kop_ih_source_url($row);
            $label = $categories[$row['category']]['label'] ?? '';
            ?>
                <div class="kop-flagged-card kop-flagged-finding">
                    <h3><?php echo esc_html($row['facility_name']); ?>
                        <span class="kop-flagged-state"><?php echo esc_html($row['state']); ?></span></h3>
                    <div class="kop-flagged-date"><?php echo $date !== '' ? 'Inspected ' . esc_html($date) : ''; ?><?php echo $date !== '' && $label !== '' ? ' &middot; ' : ''; ?><?php echo esc_html($label); ?></div>
                    <blockquote class="kop-flagged-quote"><?php echo esc_html(kop_ih_card_excerpt($row['excerpt'])); ?></blockquote>
                    <div class="kop-flagged-source">From the state's report<?php echo $row['state_label'] ? '. ' . esc_html($row['state_label']) : ''; ?></div>
                    <div class="kop-flagged-links">
                        <?php if ($source !== ''): ?>
                            <a href="<?php echo esc_url($source); ?>" target="_blank" rel="noopener noreferrer">State source</a>
                        <?php endif; ?>
                        <?php if (in_array($tracker, $tracker_slugs, true)): ?>
                            <a href="/<?php echo esc_attr($tracker); ?>"><?php echo esc_html(strtoupper($row['state'])); ?> tracker</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(kop_ih_severe_page_url($row['id'])); ?>">Full finding</a>
                    </div>
                </div>
            <?php
        }
    }

    /** The line under the grid that leads to the whole list. Prints nothing while the list is empty. */
    function kop_ih_render_all_link() {
        $counts = kop_ih_site_severe_counts();
        if ($counts['all'] < 1) return;
        echo '<p class="kop-flagged-all"><a href="' . esc_url(kop_ih_severe_page_url()) . '">See all '
            . esc_html(number_format($counts['all'])) . ' severe ' . ($counts['all'] === 1 ? 'report' : 'reports') . '</a></p>';
    }
}
