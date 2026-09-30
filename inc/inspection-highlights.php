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
 * Five gates keep the queue short (owner rules, 2026-09-21 and 2026-09-28):
 *
 *   substantiated  only findings the state itself confirmed are queued: a
 *                  Texas citation, a California deficiency, or a California
 *                  complaint the analyst substantiated. Inconclusive and
 *                  partly substantiated reports count only where the
 *                  substantiated verdict sits with the sentence quoted.
 *   peers          a fight between children is not queued; the categories
 *                  are about what adults did. A sexual assault is queued
 *                  whoever committed it.
 *   sexual         sexual activity, contact or talk counts only when an
 *                  adult took part (kop_ih_adult_took_part); children with
 *                  each other, or left unsupervised, are not queued.
 *   medication     a single medication error is not queued, only a pattern
 *                  of them (kop_ih_repeated_pattern); a delay or refusal of
 *                  medical care always is.
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
        return 5;
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
     * 'exclude' is one it must not. 'conditional' holds groups of patterns
     * that count only when the sentence also matches the group's 'requires'
     * (a pattern) or passes its 'test' (a function of the sentence).
     */
    function kop_ih_categories() {
        return array(
            'death' => array(
                'label'    => 'Death',
                'weight'   => 100,
                // Form names, risk ratings, animals, and deaths outside the facility's care.
                'exclude'  => '\b(?:Accident,? or Death|Death Report|Death Certificate|Civil Penalty Assessment\W+Death|(?:homicid|suicid)\w+ (?:risk|ideations?|thoughts?|plans?)|risk (?:of|for) (?:homicide|suicide|death)|(?:dead|deceased) (?:bird|animal|rodent|mouse|mice|rat|insect|bug|fish|cat|dog)s?|death of (?:a |the |their |his |her )?(?:parent|mother|father|family member|grandparent|grandmother|grandfather|relative|sibling|brother|sister|pet|friend)|(?:parent|mother|father|mom|dad|grandparent|grandmother|grandfather|relative|sibling|brother|sister|aunt|uncle|cousin|friend)s?\b[^.]{0,20}\b(?:died|passed away|deceased|death|committed suicide))\b',
                'patterns' => array(
                    // Not "staff completed suicide awareness training", "a completed suicide screening".
                    '\b(?:died|dies|death|deceased|fatal(?:ly|ity|ities)?|passed away|homicide|killed|found (?:dead|deceased|unresponsive)|(?:completed|died by|committed) suicide(?! (?:awareness|prevention|screening|assessment|risk|training|evaluation|precaution|watch|protocol|polic|plan|check|form|education)))\b',
                ),
            ),
            'sexual_abuse' => array(
                'label'    => 'Sexual abuse',
                'weight'   => 90,
                // Assault counts whoever did it, another child included (owner rule, 2026-09-28).
                'patterns' => array(
                    // Not "sexually abusive behavior", a screening a program must run at admission.
                    '\bsexual(?:ly)? (?:abus(?!ive behaviou?rs?)\w+|assault\w*|exploit\w+)',
                    '\b(?:rape[ds]?|raping|molest\w+|sodomi\w+)\b',
                    '\b(?:non-? ?consensual|unwanted)\b[^.]{0,30}\bsex',
                    '\bsex\w*\b[^.]{0,40}\b(?:against (?:his|her|their) will|without (?:his|her|their )?consent)',
                    '\bforc\w+\b[^.]{0,30}\bsex',
                ),
                // Sexual activity, contact or talk counts only when an adult took part:
                // children with each other, or a child left unsupervised, is not severe.
                'conditional' => array(array(
                    'test'     => 'kop_ih_adult_took_part',
                    'patterns' => array(
                        '\bsexual(?:ly)? (?:misconduct|contact|relationships?|activit\w+|intercourse|acts?|harass\w+|inappropriate|touch\w*|behaviou?rs?|interactions?|comments?|conversations?|advances?|remarks?|language|explicit|active)',
                        '\b(?:fondl\w+|grop(?:ed|ing)|sexting|oral sex|had sex)\b',
                        '\b(?:sex|sexual relations) with (?:a |the |another )?(?:child|client|resident|minor|youth|student)',
                        '\binappropriate(?:ly)? (?:sexual|touch\w*|relationship)',
                        '\bnude (?:photo|picture|image|video)s?\b',
                    ),
                )),
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
            // A suicide attempt and self-harm are told apart (owner, 2026-09-30):
            // cutting or swallowing an object is not labelled a suicide attempt.
            'suicide_attempt' => array(
                'label'    => 'Suicide attempt',
                'weight'   => 70,
                // A ligature hazard in the building is a physical plant finding, not an event.
                'exclude'  => '\banti-?ligature\b|\bligature[- ](?:risk|point|hazard|resistant|free)s?\b',
                'patterns' => array(
                    '\b(?:suicide attempts?|attempt\w* (?:to commit |to die by )?suicide|attempted to (?:kill|end the life of) (?:him|her|them)sel(?:f|ves)|suicidal (?:gesture|attempt)s?|ligatures?)\b',
                    '\b(?:hang(?:ed|ing)?|strangl\w+) (?:him|her|them)sel(?:f|ves)\b|\battempt\w* to hang\b',
                ),
            ),
            'self_harm' => array(
                'label'    => 'Self-harm',
                'weight'   => 65,
                'patterns' => array(
                    '\b(?:self[- ]harm\w*|self[- ]injur\w+|self[- ]inflict\w*)\b',
                    '\b(?:cut|cutting|burn(?:ed|ing|t)?) (?:him|her|them)sel(?:f|ves)\b',
                    '\b(?:ingest\w+|swallow\w+|overdos\w+)\b',
                ),
            ),
            'medical_neglect' => array(
                'label'    => 'Medical neglect',
                'weight'   => 60,
                'patterns' => array(
                    '\b(?:fail\w+|did not|didn\'t|neglected|refus\w+) to (?:seek|obtain|get|provide|arrange)\b[^.]{0,40}\b(?:medical|treatment|doctor|physician)\b',
                    '\b(?:not|never) (?:\w+ )?(?:received?|given|provided|taken to|seen by) (?:any |a |the )?(?:medical|treatment|doctor|physician|dentist)\b',
                    '\b(?:medical neglect|denied (?:medical|medications?)|withh[eo]ld\w* (?:\w+ )?medications?)\b',
                    '\bdelay\w* (?:in )?(?:seeking |obtaining |getting )?(?:medical|treatment)\b',
                ),
                // A single medication error is not queued, only a pattern of them (owner rule, 2026-09-28).
                'conditional' => array(array(
                    'requires' => kop_ih_repeated_pattern(),
                    'patterns' => array(
                        '\b(?:fail\w+|did not|didn\'t|neglected) to (?:administer|give|provide|fill)\b[^.]{0,40}\b(?:medications?|prescri\w+|doses?)\b',
                        // Not a missing log, count or entry: that is paperwork.
                        '\b(?:medication errors?|(?:wrong|incorrect) (?:medications?|doses?|dosages?)|missed (?:a |the |their |his |her )?(?:doses?|dosages?|medications?)|missing (?:a |the )?(?:doses?|dosages?)|doses? omitted)\b(?! (?:counts?|logs?|records?|entr(?:y|ies)|documentation))',
                        '\b(?:was|were) not (?:given|administered|provided) (?:his |her |their |the )?(?:prescribed )?medications?\b',
                    ),
                )),
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
                // A record, log or signature that is missing is paperwork.
                'exclude'  => '\b(?:records?|logs?|counts?|documents?|documentation|forms?|signatures?|entr(?:y|ies)|files?|paperwork)\s+(?:\w+\s+){0,2}(?:was|were|is|are)\s+missing\b',
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

    /**
     * Whether an adult took part in the sexual act the sentence describes:
     * staff (S1 in California, E1 in Arizona), a volunteer, a resource
     * parent, an adult in care. The adult must be the actor ("S1 touched
     * C1", "sexual contact with a staff member"), not the one who failed to
     * supervise, found out, or told the investigator ("staff were unaware",
     * "a caregiver failed to supervise children resulting in ...",
     * "discovered by staff", "staff stated that a client touched ...").
     */
    function kop_ih_adult_took_part($sentence) {
        $adult = '\b(?:staff(?: members?)?|caregivers?|employees?|volunteers?|adults?|counselors?|supervisors?|house ?parents?|administrators?|teachers?|therapists?|directors?|owners?|nurses?|coach(?:es)?|mentors?|(?:foster|resource) (?:parent|mother|father)s?|personnel|[SE]\d{1,2}|(?:unidentified|unknown|adult|older) (?:male|female|man|woman)|m[ae]n|wom[ae]n)\b';
        $act = '\b(?:sex\w*|touch\w*|kiss\w*|fondl\w+|grop\w+|intercourse|massag\w+|nude|naked|propositi\w+|groom\w+|flirt\w*|private (?:parts|areas?)|genital\w*|relationships?)\b';
        $block = '/\b(?:supervis|unaware|aware|monitor|allow|permit|fail|result|led to|lead to|while|when|check|asleep|slept|sleep|prevent|protect|separat|interven|report(?!edly)|notif|inform|discover|observ|caught|witness|walk|notic|saw|seen|learn|look|oblivious|redirect|stat(?:ed|es)|said|told|interview|describ|explain|indicat|confirm)\w*|\./iu';
        if (!preg_match_all('/' . $adult . '/iu', $sentence, $adults, PREG_OFFSET_CAPTURE)) return false;
        if (!preg_match_all('/' . $act . '/iu', $sentence, $acts, PREG_OFFSET_CAPTURE)) return false;
        foreach ($adults[0] as $a) {
            $a_end = $a[1] + strlen($a[0]);
            // The adult first: "a staff member engaged in sexual contact".
            foreach ($acts[0] as $x) {
                if ($x[1] < $a_end || $x[1] - $a_end > 140) continue;
                if (!preg_match($block, substr($sentence, $a_end, $x[1] - $a_end))) return true;
            }
            // The act first, the adult after a preposition: "sexual contact with a staff member".
            if (!preg_match('/\b(?:with|by|toward|towards|from|between) (?:a |an |the |another |one |their |his |her )?(?:former |male |female |overnight |night |facility |program |adult )?$/iu', substr($sentence, 0, $a[1]), $prep, PREG_OFFSET_CAPTURE)) continue;
            $p = $prep[0][1];
            foreach ($acts[0] as $x) {
                $x_end = $x[1] + strlen($x[0]);
                if ($x_end > $p || $p - $x_end > 40) continue;
                if (!preg_match($block, substr($sentence, $x_end, $p - $x_end))) return true;
            }
        }
        return false;
    }

    /**
     * More than one: plural errors or doses, a count, several dates or a
     * date range, more than one child. Tells a pattern of medication errors
     * from a single one.
     */
    function kop_ih_repeated_pattern() {
        $month = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?';
        return '\b(?:medication errors|missed (?:doses|medications)|(?:wrong|incorrect) medications|doses omitted)\b'
            . '|\b(?:multiple|several|repeated(?:ly)?|numerous|many|various|recurr\w+|ongoing|pattern of|consecutive|\d+|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)\s+(?:\w+\s+){0,3}(?:errors|doses|occasions|times|days|dates|shifts|nights|mornings|evenings|children|clients|residents|youths|minors|medications|prescriptions|incidents|incidences|instances|weeks|months)\b'
            // A duration ("for a month"), an ordinal ("the second medication error"), other occasions.
            . '|\bfor (?:a|one|\w+|several|multiple) (?:full |whole )?(?:days?|weeks?|months?)\b|\bfor a duration\b'
            . '|\b(?:2nd|3rd|\d+th|second|third|fourth|fifth) (?:\w+ ){0,2}(?:errors?|incidents?|times?|occasions?|over ?dos\w+|doses?)\b'
            . '|\bother (?:occasions|incidents?|dates|days)\b'
            // More than one child.
            . '|\b(?:to|for) (?:\w+ ){0,2}(?:residents|clients|children|youths|minors)\b'
            . '|\bon the following dates\b'
            . '|\b(?:children|clients|residents|youths)(?:\x{2019}|\')?s?\b[^.]{0,30}\bmedications?\b'
            . '|\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\s*(?:,|and|-|through|to)\s*\d{1,2}\/\d{1,2}'
            . '|\b' . $month . ' \d{1,2}\s*(?:-|through|to|and)\s*(?:' . $month . ' )?\d{1,2}\b';
    }

    /** Words that, shortly before a match in the same sentence, mean it did not happen. */
    function kop_ih_negation_pattern() {
        return '\b(?:no|not|never|without|none|neither|nor|denied|denies|deny|unfounded|unsubstantiated|ruled out|free (?:of|from)|absence of|lack of evidence|did not|didn\'t|does not|was not|wasn\'t|were not|weren\'t|cannot|could not be)\b';
    }

    /** Words that mean the sentence talks about a possibility, a rule or a plan, not an event. */
    function kop_ih_hypothetical_pattern() {
        return '\b(?:risk of|at risk|potential(?:ly)?|possib\w+|could|can|may|might|would|should|shall|must|lead(?:s|ing)? to|in (?:the )?(?:case|event) (?:of|that)|if|prevent\w*|polic(?:y|ies)|procedures?|training on|trained (?:on|in)|how to|requires?|required to report|hop(?:ed|es|ing)|wish\w*|threat\w*|claim\w*|jok\w+|histor(?:y|ies) of)\b';
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
            . '|\b(?:trainings?|certificat\w+|curricul\w+|courses?)\b[^.]{0,100}\b(?:Reporting|Prevention|Recogni\w+|Awareness|Intervention|[Ii]dentification|Harassment|Trafficking|Elimination)\b'
            // The topic before the word: "completed suicide awareness and prevention training".
            . '|\b(?:awareness|prevention|intervention|recognition|screening|de-?escalation|first aid|CPR)\b[^.]{0,40}\b(?:trainings?|courses?|curricul\w+|classes|modules?|certificat\w+|materials?)\b'
            // What staff are trained to do: "training's to help clients who engage in self injurious behaviors".
            . '|\btrain\w*\b[^.]{0,20}\bto (?:help|handle|manage|respond to|recogni[sz]e|support|work with|deal with|de-?escalate|identify|prevent)\b'
            . '|\b(?:training|educational|teaching|lesson) (?:materials?|videos?|modules?|plans?|content|slides|binders?|handouts?)\b'
            // A prevention plan and what it covers; a definition of a reportable event.
            . '|\b(?:suicide|self[- ]harm|crisis|safety) (?:prevention|response) (?:plan|program|polic\w+|protocol)s?\b'
            . '|\bdefine[sd]?\b[^.]{0,80}\bas\b|\bis defined as\b|\bare any (?:event|incident|occurrence)s?\b'
            // A drill, and the federal Prison Rape Elimination Act (PREA) named as a program.
            . '|\b(?:mock|drills?)\b|\bPrison Rape Elimination\b|\bPREA\b'
            // A definition being quoted: "Medication error" means ...
            . '|["\x{201D}]\s*means\b'
            // A statute or rule being quoted.
            . '|\b(?:A\.R\.S\.|A\.A\.C\.|R9-\d+[\w.\-]*|WAC \d+|R\d{3}-\d+[\w()\-]*|statute|regulation|rule)\b[^.]{0,40}\b(?:states?|requires?|provides?|defines?)\b'
            . '|\b(?:any|a) person who\b|\breasonably believes\b|\bincidents that must be reported\b'
            // An intake history (a list after "history of") or what happened at an earlier placement.
            . '|\bhistor(?:y|ies) of\b[^.]*,[^.]*,'
            . '|\b(?:previous|prior|former|last) (?:group home|placement|facility|home|foster home|program|school|provider)\b'
            // A record review's lists: diagnoses, a history line, an admission assessment (North Carolina's bullets).
            . '|\bdiagnos(?:is|es)\b|\bdiagnosed with\b[^.]*,|^\W*(?:hx|history|past)\b'
            . '|\b(?:admission|intake|initial|clinical|comprehensive|psychiatric|psychological) (?:assessment|evaluation)s?\b[^.]{0,80}\b(?:documented|revealed|showed|indicated|noted|listed)\b'
            // Rule text: what a provider shall do, a list of the crimes a statute names.
            . '|\bshall\b|\bArticle \d+\w*, |\b(?:G\.S\.|NCAC|General Statutes)\b';
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
        return '\bun-?substantiated\b|\bunfounded\b|\bnot substantiated\b|\binconclusive\b|\bnot (?:been )?determined\b'
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
        // A bullet (" -On 1/31/26 ...", " - 17 year old male") is a sentence of its own.
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(*])|\s[-\x{2022}]\s?(?=[A-Z0-9"])/u', (string) $guard);
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
            // Patterns that count only when the sentence also meets the group's condition.
            foreach (isset($found[$key]) ? array() : ($cat['conditional'] ?? array()) as $group) {
                $met = isset($group['test']) ? call_user_func($group['test'], $sentence) : preg_match('/' . $group['requires'] . '/iu', $sentence);
                if (!$met) continue;
                foreach ($group['patterns'] as $pattern) {
                    $words = kop_ih_sentence_has($sentence, $pattern);
                    if ($words === false) continue;
                    $found[$key] = $words;
                    break 2;
                }
            }
        }
        return $found;
    }

    // -----------------------------------------------------------------------
    // Extract: one adapter per state
    // -----------------------------------------------------------------------

    /**
     * States with an adapter. Washington waits for its scraper (the PDF
     * columns come out interleaved) and Nevada stores no finding text.
     * Oregon's site visit findings are almost all the rule's own wording
     * run together with what the licensor saw (a dry run on 2026-09-28
     * queued 90, nearly every one a quoted rule), so it is left out.
     */
    function kop_ih_supported_states() {
        return array('TX', 'CA', 'UT', 'AZ', 'CT', 'NC', 'GA', 'MN', 'AR', 'FL');
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
            case 'NC': return kop_ih_extract_nc($data, (string) ($row['raw_content'] ?? ''));
            case 'GA': return kop_ih_extract_ga($data, (string) ($row['raw_content'] ?? ''));
            case 'MN': return kop_ih_extract_mn($data, (string) ($row['raw_content'] ?? ''));
            case 'AR': return kop_ih_extract_ar($data, (string) ($row['raw_content'] ?? ''));
            case 'FL': return kop_ih_extract_fl($data, (string) ($row['raw_content'] ?? ''));
        }
        return array();
    }

    /**
     * A statement of deficiencies as PDF text (North Carolina, Georgia, the
     * federal CMS-2567 form Arkansas uses): each deficiency opens with a tag
     * line ("V 132 ...", "1001 Severity : A ...", "N 123 ..."), quotes the
     * rule, then "This Rule is not met as evidenced by:" and the surveyor's
     * findings, which run to the next tag. Page headers and footers repeat
     * in the middle of findings and are dropped, as are lines in capitals
     * only (the facility's name and address on every page).
     *
     * Returns array(text, tag line) for each deficiency; $tag is a pattern
     * for one tag line.
     */
    function kop_ih_sod_sections($raw, $tag) {
        $junk = '/^\W*(?:PRINTED:|FORM APPROVED|Division of Health Service Re|DEPARTMENT OF HEALTH|CENTERS FOR MEDICARE|STATEMENT OF DEFICIENCIES|STATEMENT OF|AND PLAN OF|\([Xx]\d\)|A\. BUILDING|B\. WING|NAME OF PROVIDER|STREET ADDRESS|PREFIX\b|TAG\b|SUMMARY (?:STATEMENT|OF STATEMENT)|NUMBER\s*$|DEFICIENCY\)|STATE FORM|If continuation sheet|LABORATORY DIRECTOR|TITLE\s*$|FORM CMS|Any deficiency statement ending|other safeguards provide|following the date of survey|days following the date|program participation|Event ID|Facility ID|RECEIVED\b|DHSR)|Continued [Ff]rom page|^\d{1,2}\/\d{1,2}\/\d{4} \d{1,2}:\d{2}:\d{2} [AP]M\b/u';
        $out = array();
        $current = null;
        $tag_line = '';
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/' . $tag . '/u', $line) && !preg_match('/Continued [Ff]rom page/u', $line)) {
                if ($current !== null) $out[] = array($current, $tag_line);
                $current = null;
                $tag_line = $line;
                continue;
            }
            if (preg_match($junk, $line) || !preg_match('/\p{Ll}/u', $line)) continue;
            if ($current === null) {
                if (preg_match('/\bnot met as evidenced by:?\s*(.*)$/iu', $line, $m)) $current = $m[1];
                continue;
            }
            $current .= ' ' . $line;
        }
        if ($current !== null) $out[] = array($current, $tag_line);
        $clean = array();
        foreach ($out as $s) {
            $text = kop_ih_clean_text(str_replace('|', ' ', $s[0]));
            if (mb_strlen($text) < 40) continue;
            $clean[] = array($text, kop_ih_clean_text(preg_replace('/\s+[\[{|]?\s*V\s?\d{3}\s*$/u', '', $s[1])));
        }
        return $clean;
    }

    /** A standard cut to fit its column, at a word. */
    function kop_ih_short_standard($text, $limit = 160) {
        $text = kop_ih_clean_text($text);
        if (mb_strlen($text) <= $limit) return $text;
        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space > 100 ? mb_substr($cut, 0, $space) : $cut, ' ,;:') . ' ...';
    }

    /**
     * The North Carolina tags about harm: allegations against staff (V 131,
     * V 132), the residential treatment rules on scope and supervision
     * (V 289 to V 296, V 314, V 315), incident response and reporting
     * (V 366, V 367), and protection from abuse, neglect, restraint and
     * seclusion (V 512 to V 525). The other tags (treatment plans, training,
     * policies, medication records) quote a child's history at length and
     * are left out.
     */
    function kop_ih_nc_harm_tag($tag) {
        if (!preg_match('/V\s?(\d{3})/u', (string) $tag, $m)) return false;
        $n = (int) $m[1];
        return in_array($n, array(131, 132, 314, 315, 366, 367), true) || ($n >= 289 && $n <= 296) || ($n >= 512 && $n <= 525);
    }

    /**
     * North Carolina: DHSR statements of deficiencies (and the plans of
     * correction filed against them, which repeat them). A Type A1 or A2
     * violation is the Division's finding of serious harm or neglect; a
     * complaint survey is the Division confirming what was reported, even
     * where the complaint itself was not substantiated but a deficiency was.
     */
    function kop_ih_extract_nc(array $data, $raw) {
        $type = trim((string) ($data['inspection_type'] ?? ''));
        $complaint = (bool) preg_match('/complaint|compaint/i', $type);
        $out = array();
        foreach (kop_ih_sod_sections($raw, '^\W{0,3}V\s?\d{3}\b') as $s) {
            list($text, $tag) = $s;
            if (!kop_ih_nc_harm_tag($tag)) continue;
            $label = 'Deficiency cited' . ($type !== '' ? ', ' . strtolower(preg_replace('/^MHLCS\s+/i', '', $type)) . ' survey' : '');
            if (preg_match('/\bType (A[12]?|B)\b(?: rule)? violation/u', $text, $t)) {
                $factor = $t[1] === 'B' ? 0.9 : 1.0;
                $label .= ', Type ' . $t[1] . ' violation';
            } else {
                // Without the Division's own severity a deficiency ranks below a Type A or B.
                $factor = kop_ih_citation_factor($complaint) * 0.85;
            }
            $out[] = array(
                'text' => $text, 'standard' => kop_ih_short_standard($tag), 'state_label' => $label,
                'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Georgia: DHS/ORCC statements of deficiencies, one tag per rule with
     * the surveyor's severity letter. The letters follow the federal grid:
     * G and above record actual harm or immediate jeopardy, D to F the
     * potential for more than minimal harm, A to C minimal harm.
     */
    function kop_ih_extract_ga(array $data, $raw) {
        $type = trim((string) ($data['survey_type'] ?? ''));
        $out = array();
        foreach (kop_ih_sod_sections($raw, '^\d{4} Severity :') as $s) {
            list($text, $tag) = $s;
            $severity = preg_match('/Severity : (\w+)/u', $tag, $m) ? strtoupper($m[1]) : '';
            if ($severity === '0') continue;
            $factor = $severity >= 'G' ? 1.0 : ($severity >= 'D' ? 0.85 : 0.7);
            $label = 'Deficiency cited' . ($severity !== '' ? ', severity ' . $severity : '') . ($type !== '' ? ', ' . strtolower($type) . ' survey' : '');
            $out[] = array(
                'text' => $text, 'standard' => '', 'state_label' => $label,
                'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Arkansas: the collection is mostly the facilities' own incident
     * notices and police call logs, which are not the state's findings.
     * Only the federal surveys (CMS-2567) of the psychiatric residential
     * treatment facilities are read.
     */
    function kop_ih_extract_ar(array $data, $raw) {
        $doc = (string) ($data['doc_type'] ?? '');
        if (!preg_match('/Survey|IOC Complaint/i', $doc)) return array();
        $out = array();
        foreach (kop_ih_sod_sections($raw, '^\W{0,3}[A-Z]\s?\d{3,4}\b') as $s) {
            $out[] = array(
                'text' => $s[0], 'standard' => kop_ih_short_standard($s[1]), 'state_label' => 'Deficiency cited, ' . strtolower($doc),
                'factor' => kop_ih_citation_factor((bool) preg_match('/complaint/i', $doc)), 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Minnesota: two kinds of document. A maltreatment investigation
     * memorandum is queued only when its disposition says maltreatment was
     * determined, and then only its conclusion (the sentences that say it
     * was not determined, for another allegation, are set aside by the
     * scorer). A correction order or notice lists "Violation:" paragraphs,
     * each a citation.
     */
    function kop_ih_extract_mn(array $data, $raw) {
        $raw = (string) $raw;
        if (preg_match('/MALTREATMENT INVESTIGATION MEMORANDUM/iu', $raw) || ($data['doc_type'] ?? '') === 'Maltreatment Finding') {
            if (!preg_match('/Disposition:\s*([^\r\n]+)/u', $raw, $d)) return array();
            $disposition = kop_ih_clean_text($d[1]);
            if (!preg_match('/\b(?:Maltreatment (?:was )?determined|Substantiated)\b/iu', $disposition)) return array();
            $section = preg_match('/\bConclusions?:\s*(.*?)(?:\bB\. Responsibility|\bAction Taken by|\bCertification:|$)/isu', $raw, $c) ? $c[1] : '';
            if (trim($section) === '') return array();
            return array(array(
                'text' => kop_ih_clean_text($section), 'standard' => '',
                'state_label' => kop_ih_short_standard($disposition, 110),
                'factor' => 1.0, 'corrected_on_site' => null, 'kind' => 'complaint',
            ));
        }
        $factor = kop_ih_citation_factor((bool) preg_match('/licensing investigation|maltreatment/iu', $raw));
        $out = array();
        if (!preg_match_all('/\bViolation:\s*(.*?)(?=\bCorrective Action|\b(?:Rule|Statute|Rules|Statutes) Violated:|\bCitation:|\n\s*\d{1,2}\.\s|$)/su', $raw, $m, PREG_SET_ORDER)) return array();
        foreach ($m as $v) {
            $text = kop_ih_clean_text($v[1]);
            if (mb_strlen($text) < 40) continue;
            $out[] = array(
                'text' => $text, 'standard' => '', 'state_label' => 'Violation cited by DHS',
                'factor' => $factor, 'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
    }

    /**
     * Florida: the Department of Juvenile Justice compliance reviews rate
     * each indicator ("2.09 Performance Plan Development ... Failed
     * Compliance") and explain the rating underneath. Only indicators rated
     * Failed or Limited are findings. The research evaluations (SPEP), PREA
     * audits and the AHCA rows (rule text only) carry no finding narrative.
     */
    function kop_ih_extract_fl(array $data, $raw) {
        if (!preg_match('/^QI\b/i', (string) ($data['report_type'] ?? ''))) return array();
        $raw = (string) preg_replace('/^.*(?:Department of Juvenile Justice .*Compliance Report|Office of Accountability and Program Support Page|Page \d+ of \d+).*$/mu', '', (string) $raw);
        $blocks = preg_split('/^(?=\d\.\d{2} \S)/mu', $raw);
        $out = array();
        foreach ((array) $blocks as $block) {
            if (!preg_match('/^(\d\.\d{2}) ([^\r\n]+)/u', $block, $h)) continue;
            if (!preg_match('/\b(Failed|Limited) Compliance\b/u', mb_substr($block, 0, 400), $r)) continue;
            $text = kop_ih_clean_text(preg_replace('/^[^\r\n]*\r?\n/u', '', $block));
            $text = (string) preg_replace('/^(?:\(Critical\)\s*)?(?:(?:Failed|Limited|Satisfactory) Compliance\s*)+/u', '', $text);
            if (mb_strlen($text) < 60) continue;
            $out[] = array(
                'text' => $text, 'standard' => kop_ih_short_standard('Indicator ' . $h[1] . ' ' . preg_replace('/\s*(?:Failed|Limited|Satisfactory) Compliance.*$/u', '', $h[2])),
                'state_label' => $r[1] . ' compliance, DJJ review', 'factor' => $r[1] === 'Failed' ? 1.0 : 0.85,
                'corrected_on_site' => null, 'kind' => 'citation',
            );
        }
        return $out;
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

    /** The rows kop_ih_severe_tally() counts: state and kinds of harm of every approved severe finding. */
    function kop_ih_severe_tally_sql() {
        return "SELECT state, categories FROM inspection_highlights
            WHERE status = 'approved' AND score >= " . (int) kop_ih_severe_score();
    }

    /**
     * Approved severe findings counted by state and kind of harm, for the
     * Severe Reports page's state and kind tiles. A finding counts once under
     * each kind in its categories list, and once in 'all'. Returns
     * array('all' => array('all' => n, kind => n, ...), 'TX' => array(...), ...).
     */
    function kop_ih_severe_tally(array $rows) {
        $known = kop_ih_categories();
        $tally = array('all' => array('all' => 0));
        foreach ($rows as $row) {
            $state = strtoupper((string) $row['state']);
            if (!isset($tally[$state])) $tally[$state] = array('all' => 0);
            $tally['all']['all']++;
            $tally[$state]['all']++;
            foreach (array_unique(array_filter(array_map('trim', explode(',', (string) $row['categories'])))) as $kind) {
                if (!isset($known[$kind])) continue;
                $tally['all'][$kind] = ($tally['all'][$kind] ?? 0) + 1;
                $tally[$state][$kind] = ($tally[$state][$kind] ?? 0) + 1;
            }
        }
        return $tally;
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
        $counts = array('added' => 0, 'refreshed' => 0, 'kept' => 0, 'dropped' => 0, 'duplicate' => 0, 'relabelled' => 0);
        $report_id = (int) $row['id'];
        $stmt = $pdo->prepare('SELECT id, finding_key, status, category, categories FROM inspection_highlights WHERE report_id = ?');
        $stmt->execute(array($report_id));
        $existing = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) $existing[$e['finding_key']] = $e;

        foreach ($candidates as $c) {
            $values = array(
                $c['category'], implode(',', $c['categories']), $c['score'], $c['excerpt'],
                // Cut to the column widths (varchar(500), varchar(120)) by character, never mid-letter.
                $c['standard'] !== '' ? mb_substr($c['standard'], 0, 500) : null,
                $c['state_label'] !== '' ? mb_substr($c['state_label'], 0, 120) : null, $c['kind'],
                $c['corrected_on_site'] === null ? null : (int) $c['corrected_on_site'],
                kop_ih_scanner_version(), kop_ih_parse_date($row['report_date'] ?? ''),
            );
            if (!isset($existing[$c['finding_key']])) {
                // The scrapers hold some reports under two ids; the first row scanned keeps the finding.
                $dup = $pdo->prepare('SELECT id FROM inspection_highlights WHERE facility_id = ? AND text_hash = ? AND report_id <> ? LIMIT 1');
                $dup->execute(array((int) $row['facility_id'], $c['text_hash'], $report_id));
                if ($dup->fetchColumn()) { $counts['duplicate']++; continue; }
                // Nor is one already folded into an approved finding of the same day (kop_ih_merge_same_day).
                if (kop_ih_same_day_covers($pdo, (int) $row['facility_id'], $values[9], $c['excerpt'])) { $counts['duplicate']++; continue; }
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
                // A reviewed finding keeps its status and text. Only the split of
                // self-harm from suicide attempts (scanner 5) may move its label.
                $e = $existing[$c['finding_key']];
                $split = array('self_harm', 'suicide_attempt');
                if (in_array($e['category'], $split, true) && in_array($c['category'], $split, true)
                    && ($e['category'] !== $c['category'] || $e['categories'] !== $values[1])) {
                    $pdo->prepare('UPDATE inspection_highlights SET category=?, categories=? WHERE id=?')
                        ->execute(array($c['category'], $values[1], (int) $e['id']));
                    $counts['relabelled']++;
                }
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

    // -----------------------------------------------------------------------
    // Merge: one entry per facility per day
    // -----------------------------------------------------------------------

    /** Text reduced for comparing: lower-cased, letters and digits only. */
    function kop_ih_squash_text($text) {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $text));
    }

    /**
     * The parts of an excerpt: one per finding folded into it (kept apart by
     * a blank line), each a list of runs of the state's text (kept apart by
     * " [...] " where text between them is left out).
     */
    function kop_ih_excerpt_parts($excerpt) {
        $parts = array();
        foreach (preg_split('/\n\s*\n/u', trim((string) $excerpt)) as $part) {
            $segments = array_values(array_filter(array_map('trim', explode(' [...] ', $part)), 'strlen'));
            if ($segments) $parts[] = $segments;
        }
        return $parts;
    }

    /**
     * Several excerpts as one: the first one's text first, then whatever the
     * others add, each as its own paragraph. A run of text already there
     * (the same words, whatever the spacing or punctuation) is left out; a run
     * that holds an earlier one, like a longer cut of the same sentence,
     * replaces it.
     */
    function kop_ih_combine_excerpts(array $excerpts) {
        $parts = array();
        foreach ($excerpts as $excerpt) {
            foreach (kop_ih_excerpt_parts($excerpt) as $segments) {
                $added = array();
                foreach ($segments as $segment) {
                    $key = kop_ih_squash_text($segment);
                    if ($key === '') continue;
                    $covered = false;
                    foreach ($parts as $p => $kept) {
                        foreach ($kept as $s => $old) {
                            if (strpos($old['key'], $key) !== false) { $covered = true; break 2; }
                            if (strpos($key, $old['key']) !== false) unset($parts[$p][$s]);
                        }
                    }
                    foreach ($added as $old) {
                        if (strpos($old['key'], $key) !== false) { $covered = true; break; }
                    }
                    if (!$covered) $added[] = array('key' => $key, 'text' => $segment);
                }
                if ($added) $parts[] = $added;
            }
        }
        $out = array();
        foreach ($parts as $kept) {
            if ($kept) $out[] = implode(' [...] ', array_column($kept, 'text'));
        }
        return implode("\n\n", $out);
    }

    /** Whether $excerpt adds nothing to $existing: every run of it is already there. */
    function kop_ih_excerpt_covered($existing, $excerpt) {
        return kop_ih_squash_text(kop_ih_combine_excerpts(array($existing, $excerpt)))
            === kop_ih_squash_text(kop_ih_combine_excerpts(array($existing)));
    }

    /**
     * What makes two facility records one facility: the same state and the
     * same license number (program_name in California, Minnesota, Texas and
     * some of Utah). The California scraper has made a second record for
     * some licenses, named only by the number. A shared name alone is not
     * enough: two group homes can be called the same.
     * Returns the keys for one inspection_facilities row.
     */
    function kop_ih_facility_keys(array $fac) {
        $state = strtoupper(trim((string) ($fac['state'] ?? '')));
        $keys = array();
        foreach (array('program_name', 'facility_name') as $col) {
            $license = trim((string) ($fac[$col] ?? ''));
            if (preg_match('/^\d{6,}$/', $license)) $keys[] = 'l|' . $state . '|' . $license;
        }
        return array_values(array_unique($keys));
    }

    /** Every facility record that is the same facility as $facility_id, itself included. */
    function kop_ih_facility_twins(PDO $pdo, $facility_id) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM inspection_facilities WHERE id = ?');
            $stmt->execute(array((int) $facility_id));
            $fac = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $fac = false;
        }
        if (!$fac) return array((int) $facility_id);
        $license = '';
        foreach (kop_ih_facility_keys($fac) as $key) {
            if (strpos($key, 'l|') === 0) $license = substr($key, strrpos($key, '|') + 1);
        }
        $ids = array();
        if ($license !== '') {
            $stmt = $pdo->prepare('SELECT id FROM inspection_facilities WHERE state = ? AND (program_name = ? OR facility_name = ?)');
            $stmt->execute(array($fac['state'], $license, $license));
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids[] = (int) $facility_id;
        return array_values(array_unique($ids));
    }

    /** Whether an approved finding of this facility (under any of its records) on this day already holds the excerpt. */
    function kop_ih_same_day_covers(PDO $pdo, $facility_id, $date, $excerpt) {
        if ($date === null || $date === '') return false;
        $twins = kop_ih_facility_twins($pdo, $facility_id);
        $stmt = $pdo->prepare("SELECT excerpt FROM inspection_highlights WHERE facility_id IN (" . implode(',', $twins) . ") AND finding_date = ? AND status = 'approved'");
        $stmt->execute(array($date));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
            if (kop_ih_excerpt_covered($existing, $excerpt)) return true;
        }
        return false;
    }

    /** Distinct non-empty values, joined and cut to a column's width. */
    function kop_ih_join_distinct(array $values, $width) {
        $seen = array();
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v !== '' && !isset($seen[mb_strtolower($v)])) $seen[mb_strtolower($v)] = $v;
        }
        if (!$seen) return null;
        return mb_substr(implode('; ', $seen), 0, $width);
    }

    /**
     * One entry per facility per calendar day on the site (owner rule,
     * 2026-09-29). Approved severe findings of one facility dated the same day
     * become one: the worst (then the oldest) keeps its id and takes in the
     * others' text, kinds of harm, citations and state labels; the others are
     * deleted. A finding whose text is already there only goes. The store
     * then never queues the deleted ones again (kop_ih_same_day_covers).
     *
     * A facility held under two records with one license (kop_ih_facility_keys) counts as one;
     * the finding under the record with a real name is the one kept.
     *
     * $facility_id 0 looks at every facility. With $apply false nothing is
     * written. Returns one entry per merge: keep, merged ids, identical ids,
     * facility, date, and the combined excerpt.
     */
    function kop_ih_merge_same_day(PDO $pdo, $apply = true, $facility_id = 0) {
        $where = "h.status = 'approved' AND h.score >= " . (int) kop_ih_severe_score() . ' AND h.finding_date IS NOT NULL';
        if ($facility_id) $where .= ' AND h.facility_id IN (' . implode(',', kop_ih_facility_twins($pdo, $facility_id)) . ')';
        $rows = $pdo->query("SELECT h.*, f.state AS fac_state, f.facility_name AS fac_name, f.program_name AS fac_program
            FROM inspection_highlights h LEFT JOIN inspection_facilities f ON f.id = h.facility_id
            WHERE $where ORDER BY h.id ASC")->fetchAll(PDO::FETCH_ASSOC);

        // One facility per set of records sharing a license number (union-find over their keys).
        $parent = array();
        $find = static function ($x) use (&$parent) {
            while ($parent[$x] !== $x) $x = $parent[$x] = $parent[$parent[$x]];
            return $x;
        };
        foreach ($rows as $row) {
            $keys = kop_ih_facility_keys(array('state' => $row['fac_state'] ?? $row['state'], 'facility_name' => $row['fac_name'], 'program_name' => $row['fac_program']));
            $keys[] = 'id|' . $row['facility_id'];
            foreach ($keys as $key) if (!isset($parent[$key])) $parent[$key] = $key;
            $root = $find($keys[0]);
            foreach ($keys as $key) $parent[$find($key)] = $root;
        }
        $groups = array();
        foreach ($rows as $row) {
            $groups[$find('id|' . $row['facility_id']) . '|' . $row['finding_date']][] = $row;
        }
        // Worst first, then the one under a named record, then the oldest.
        foreach ($groups as &$group) {
            usort($group, static function ($a, $b) {
                $named = static function ($r) { return preg_match('/^\d+$/', trim((string) $r['fac_name'])) ? 0 : 1; };
                return (int) $b['score'] <=> (int) $a['score'] ?: $named($b) <=> $named($a) ?: (int) $a['id'] <=> (int) $b['id'];
            });
        }
        unset($group);

        $cats = kop_ih_categories();
        $merges = array();
        foreach ($groups as $rows) {
            if (count($rows) < 2) continue;
            $keep = $rows[0];
            $excerpt = kop_ih_combine_excerpts(array($keep['excerpt']));
            $identical = array();
            $merged = array();
            $kinds = array_filter(explode(',', (string) $keep['categories']));
            $corrected = array($keep['corrected_on_site']);
            foreach (array_slice($rows, 1) as $row) {
                $combined = kop_ih_combine_excerpts(array($excerpt, $row['excerpt']));
                if (kop_ih_squash_text($combined) === kop_ih_squash_text($excerpt)) {
                    $identical[] = (int) $row['id'];
                } else {
                    $merged[] = (int) $row['id'];
                    $excerpt = $combined;
                }
                $kinds = array_merge($kinds, array_filter(explode(',', (string) $row['categories'])));
                $corrected[] = $row['corrected_on_site'];
            }
            // Kinds of harm worst first, as the scanner lists them.
            $kinds = array_values(array_unique($kinds));
            usort($kinds, static function ($a, $b) use ($cats) {
                return ($cats[$b]['weight'] ?? 0) <=> ($cats[$a]['weight'] ?? 0);
            });
            $gone = array_merge($merged, $identical);
            $note = trim(trim((string) $keep['review_note']) . ' Same-day findings merged in: #' . implode(', #', $gone) . '.');
            $merges[] = array(
                'keep' => (int) $keep['id'], 'merged' => $merged, 'identical' => $identical,
                'facility_id' => (int) $keep['facility_id'], 'date' => $keep['finding_date'], 'excerpt' => $excerpt,
            );
            if (!$apply) continue;

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE inspection_highlights SET excerpt = ?, categories = ?, score = ?, standard = ?, state_label = ?,
                    corrected_on_site = ?, review_note = ? WHERE id = ?')->execute(array(
                    $excerpt,
                    implode(',', $kinds),
                    max(array_map('intval', array_column($rows, 'score'))),
                    kop_ih_join_distinct(array_column($rows, 'standard'), 500),
                    kop_ih_join_distinct(array_column($rows, 'state_label'), 120),
                    // Corrected at the inspection only when every one of them was.
                    count(array_unique(array_map('strval', $corrected))) === 1 ? $keep['corrected_on_site'] : 0,
                    mb_substr($note, 0, 500),
                    (int) $keep['id'],
                ));
                $pdo->exec('DELETE FROM inspection_highlights WHERE id IN (' . implode(',', array_map('intval', $gone)) . ')');
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        return $merges;
    }

    /**
     * Scan up to $limit reports not yet seen at this scanner version.
     * With $apply false nothing is written and the candidates are returned
     * for a dry run; $rescan_all (dry runs) looks at every report, scanned
     * or not. Returns scanned, remaining, the store counts, and
     * (dry run only) the candidates.
     */
    function kop_ih_scan(PDO $pdo, $limit = 2000, $apply = false, array $states = array(), $rescan_all = false) {
        $states = $states ? array_values(array_intersect(array_map('strtoupper', $states), kop_ih_supported_states())) : kop_ih_supported_states();
        $result = array('scanned' => 0, 'remaining' => 0, 'added' => 0, 'refreshed' => 0, 'kept' => 0, 'dropped' => 0, 'duplicate' => 0, 'relabelled' => 0, 'candidates' => array());
        $seen = array();
        if (!$states) return $result;
        if ($apply) kop_ih_ensure_tables($pdo);

        $in = implode(',', array_fill(0, count($states), '?'));
        $has_scans = !$rescan_all && ($apply || kop_ih_table_exists($pdo, 'inspection_highlight_scans'));
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

    /**
     * The URL of the report's own document, as the state trackers link it as
     * the official report: the PDF (North Carolina, Florida, Arkansas), the
     * statement of deficiencies (Georgia) or the scraped link (Minnesota).
     * Empty for the states whose reports share a page or have none (Texas,
     * California, Utah, Arizona, Connecticut), which the trackers match by
     * text instead.
     */
    function kop_ih_document_url($report_url, $categories_json) {
        $data = json_decode((string) $categories_json, true);
        foreach (array('pdf_url', 'sod_url') as $key) {
            if (is_array($data) && is_string($data[$key] ?? null) && trim($data[$key]) !== '') return trim($data[$key]);
        }
        $url = trim((string) $report_url);
        return preg_match('#^https?://#i', $url) ? $url : '';
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

    /** An excerpt as escaped HTML: one paragraph per finding merged into it. */
    function kop_ih_excerpt_html($excerpt) {
        $out = '';
        foreach (preg_split('/\n\s*\n/u', trim((string) $excerpt)) as $part) {
            if (trim($part) !== '') $out .= '<p>' . htmlspecialchars(trim($part), ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return $out;
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

    function kop_ih_site_severe_tally() {
        global $wpdb;
        if (!kop_ih_site_ready()) return kop_ih_severe_tally(array());
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(kop_ih_severe_tally_sql(), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        return kop_ih_severe_tally((array) $rows);
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
                    <blockquote class="kop-flagged-quote"><?php echo kop_ih_excerpt_html(kop_ih_card_excerpt($row['excerpt'])); ?></blockquote>
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
