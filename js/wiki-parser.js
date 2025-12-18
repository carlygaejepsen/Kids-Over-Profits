/**
 * Wiki Parser Module
 * Parses Reddit-style markdown for troubled teen industry program wiki entries
 * and extracts structured data.
 */

/**
 * Find best matches from a lookup table for a given text
 * Handles case-insensitive and partial matching
 * @param {string} text - The text to match
 * @param {array} lookupTable - Array of valid values to match against
 * @param {number} maxMatches - Maximum number of matches to return
 * @returns {array} - Array of matched values
 */
function findLookupMatches(text, lookupTable, maxMatches = 5) {
    if (!text || !lookupTable || lookupTable.length === 0) return [];
    
    const lowerText = text.toLowerCase();
    const matches = [];
    
    // First pass: exact matches (case-insensitive)
    lookupTable.forEach(item => {
        if (item.toLowerCase() === lowerText && !matches.includes(item)) {
            matches.push(item);
        }
    });
    
    // Second pass: contains matches
    if (matches.length < maxMatches) {
        lookupTable.forEach(item => {
            if (item.toLowerCase().includes(lowerText) && !matches.includes(item)) {
                matches.push(item);
            }
        });
    }
    
    // Third pass: text contains item (substring of provided text)
    if (matches.length < maxMatches) {
        lookupTable.forEach(item => {
            if (lowerText.includes(item.toLowerCase()) && !matches.includes(item)) {
                matches.push(item);
            }
        });
    }
    
    return matches.slice(0, maxMatches);
}

/**
 * Sanitize and normalize URLs for markdown links
 * @param {string} input - Raw URL input
 * @returns {string} - Sanitized URL
 */
function sanitizeUrl(input) {
    if (!input) return '';
    let url = input.trim();
    if (!url) return '';

    const isRelativePath = url.startsWith('/');
    if (url.startsWith('//')) {
        url = `https:${url}`;
    } else if (!isRelativePath && !/^[a-z]+:\/\//i.test(url)) {
        url = url.startsWith('www.') ? `https://${url}` : `https://${url}`;
    }

    url = url.replace(/\s+/g, '%20');
    url = url.replace(/\(/g, '%28').replace(/\)/g, '%29');
    return url;
}

/**
 * Parse Reddit markdown for TTI program wiki entries
 * @param {string} markdown - The markdown text to parse
 * @returns {object} - Parsed data structure containing all extracted information
 */
function parseWikiMarkdown(markdown) {
    console.log('parseWikiMarkdown called');

    // Initialize data structure
    const parsedData = {
        // Basic info
        programName: '',
        yearsActive: '',
        cityState: '',
        programType: '',
        yearFounded: '',
        ownerName: '',
        ownerLink: '',
        ageRange: '',
        
        // Advertised Student Profile - checklist format
        selectedDiagnoses: [],  // Array of all profile items (diagnoses, behaviors, circumstances)
        customDiagnoses: '',    // Custom/unique profile items not in common lists
        
        capacity: '',
        campusSize: '',
        avgStay: '',
        tuition: '',
        natsapMember: '',
        natsapYear: '',
        mainAddress: '',
        addressLink: '',
        accreditingBody: '',
        accreditingBodyLink: '',
        historyMisc: '',

        // Structured data arrays
        staffMembers: [],
        programLevels: [],
        punishments: [],
        lawsuits: [],
        newsArticles: [],
        testimonies: [],
        relatedMedia: [],
        campuses: [],
        ownershipChanges: [],
        rules: [],
        therapies: [],

        // Allegations - checklist format
        selectedAllegations: [],  // Array of selected common complaint types from lookup table
        customAllegations: '',    // Custom/unique allegations not in common list

        // Miscellaneous text sections
        levelSystemDesc: '',
        structureMisc: '',
        punishmentsMisc: '',
        lawsuitsMisc: '',
        rulesList: '',
        mainComplaints: '',
        mediaInfo: '',           // Full Media & News section content
        testimoniesMisc: '',     // Full Testimonies section content
        relatedMediaMisc: '',    // Full Related Media section content
        unparsedContent: ''      // Capture any content that wasn't matched by specific patterns
    };

    // Normalize newlines so regex parsing works with Windows CRLF input
    let normalizedMarkdown = markdown.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

    // Remove specific boilerplate text as requested by user
    normalizedMarkdown = normalizedMarkdown.replace(/\nthis text should always be removed\s*/g, '');
    normalizedMarkdown = normalizedMarkdown.replace(/SaveCancelLast revised by \[shroomskillet\]\(\/user\/shroomskillet\/\)## Page titleSaveCancel/g, '');
    normalizedMarkdown = normalizedMarkdown.replace(/SaveCancel/g, '');
    normalizedMarkdown = normalizedMarkdown.replace(/\nLast revised by \[shroomskillet\]\(\/user\/shroomskillet\/\)## Page title/g, '');
    // Normalize user signature
    normalizedMarkdown = normalizedMarkdown.replace(/- \[shroomskillet\]\(\/user\/shroomskillet\/\)/g, '- [Signal-Strain9810](/user/Signal-Strain9810/)');


    // Common acronym expansions for TTI industry companies
    const companyAcronyms = {
        'UHS': 'Universal Health Services',
        'CRC': 'CRC Health Group',
        'AAC': 'American Addiction Centers',
        'BHC': 'Behavioral Healthcare Corporation',
        'CEDU': 'CEDU Education',
        'WWASP': 'World Wide Association of Specialty Programs',
        'PCS': 'Provo Canyon School',
        'NWA': 'Northwest Academy',
        'SLS': 'Sequel Logan Services',
        'YFA': 'Youth for America'
    };

    // COMPREHENSIVE LOOKUP TABLES FOR BETTER FORM FIELD MATCHING
    
    // Clinical diagnoses and behavioral health conditions
    const clinicalDiagnoses = [
        'ADHD', 'ADD', 'Attention Deficit Hyperactivity Disorder',
        'Autism Spectrum Disorder', 'ASD', 'Asperger Syndrome',
        'Bipolar Disorder', 'Bipolar I', 'Bipolar II',
        'Major Depression', 'Depressive Disorder', 'Depression',
        'Generalized Anxiety Disorder', 'Anxiety Disorder', 'Anxiety',
        'Obsessive-Compulsive Disorder', 'OCD',
        'Post-Traumatic Stress Disorder', 'PTSD', 'Trauma',
        'Attachment Disorder', 'Reactive Attachment Disorder', 'RAD',
        'Mood Disorder', 'Mood Disturbance',
        'Conduct Disorder', 'Oppositional Defiant Disorder', 'ODD',
        'Learning Disorder', 'Learning Disability', 'Learning Disabilities',
        'Dyslexia', 'Non-Verbal Learning Disorder', 'NVLD',
        'Borderline Personality Disorder', 'BPD',
        'Schizoaffective Disorder', 'Schizophrenia', 'Psychosis',
        'Eating Disorder', 'Anorexia Nervosa', 'Anorexia', 'Bulimia Nervosa', 'Bulimia',
        'Substance Use Disorder', 'Substance Abuse', 'Drug Abuse', 'Alcohol Abuse',
        'Behavioral Addiction', 'Internet Addiction', 'Gaming Addiction',
        'Self-Harm', 'Self-Injurious Behavior', 'Cutting',
        'Suicidal Ideation', 'Suicidality', 'Suicide Risk',
        'Fetal Alcohol Spectrum Disorder', 'FAS', 'Fetal Alcohol Effects',
        'Sensory Processing Disorder', 'SPD',
        'Oppositional Behavior', 'Defiance', 'Defiant Behavior'
    ];

    // Behavioral issues and challenges (non-clinical)
    const behavioralIssues = [
        'defiance', 'oppositional behavior', 'rule-breaking', 'law-breaking',
        'aggression', 'violent behavior', 'anger management issues',
        'depression', 'depressed mood', 'sadness', 'hopelessness',
        'anxiety', 'anxious behavior', 'social anxiety', 'panic',
        'self-harm', 'cutting', 'self-injurious behavior', 'suicidal thoughts',
        'substance abuse', 'drug use', 'alcohol use', 'smoking',
        'eating disorders', 'food restriction', 'binge eating',
        'running away', 'elopement', 'truancy', 'school refusal',
        'academic struggles', 'failing grades', 'school failure',
        'social problems', 'peer conflict', 'bullying', 'social isolation',
        'family conflict', 'parent-child conflict', 'family dysfunction',
        'sexual acting out', 'inappropriate sexual behavior', 'sexually inappropriate behavior',
        'lying', 'dishonesty', 'manipulation', 'deceit', 'manipulative behavior',
        'stealing', 'theft', 'stealing behavior',
        'emotional dysregulation', 'emotional instability', 'mood swings',
        'disrespect', 'disrespectful behavior', 'poor attitude',
        'motivation issues', 'lack of motivation', 'apathy',
        'attachment issues', 'attachment', 'inability to bond', 'trust issues',
        'blame', 'blaming others', 'lack of accountability',
        'impulsive behavior', 'impulsivity', 'poor impulse control',
        'adoption issues', 'adoption', 'adopted child issues',
        'trafficking', 'sex trafficking', 'human trafficking', 'trafficking victim'
    ];

    // Common phase/stage/level/tier system structures
    const commonPhaseStructures = [
        'Entry/Foundation/Intermediate/Advanced',
        'Foundation/Intermediate/Advanced/Leadership',
        'Level 1/2/3/4',
        'Phase 1/2/3/4',
        'Bronze/Silver/Gold/Platinum',
        'White/Green/Blue/Black',
        'Stage 1/2/3/4',
        'Beginner/Intermediate/Advanced/Expert',
        'Green/Yellow/Orange/Red/Black',
        'Points-based system',
        'Rank-based system',
        'Privilege-based system',
        'Tier 1/2/3/4/5',
        'Step 1/2/3/4'
    ];

    // Common complaints and allegations in TTI
    const commonComplaints = [
        'physical abuse', 'corporal punishment', 'excessive punishment',
        'sexual abuse', 'sexual exploitation', 'sexual misconduct',
        'emotional abuse', 'psychological abuse', 'verbal abuse', 'emotional/verbal abuse',
        'neglect', 'medical neglect', 'failure to treat illness',
        'overmedication', 'inappropriate medication', 'medication abuse',
        'restraint abuse', 'excessive restraints', 'violent restraints', 'violent and excessive restraints',
        'solitary confinement', 'isolation', 'lock-down',
        'sleep deprivation', 'forced sleep deprivation',
        'food deprivation', 'withholding food', 'starvation',
        'humiliation', 'degradation', 'public shaming', 'humiliation tactics',
        'intimidation', 'threats', 'coercion', 'fear-based practices',
        'withholding medical care', 'refusing treatment',
        'inadequate supervision', 'lack of supervision', 'improper supervision', 'improper supervision of students',
        'untrained staff', 'unqualified staff', 'inadequate staff training', 'undertrained/unqualified staff',
        'fraudulent marketing', 'deceptive advertising', 'false claims', 'deceptive/fraudulent marketing practices',
        'human rights violations', 'civil rights violations',
        'illegal practices', 'non-compliant practices',
        'unsanitary conditions', 'unsafe facilities',
        'failure to follow mandated reporting',
        'educational neglect', 'lack of education',
        'lack of mental health services', 'inadequate therapy',
        'high staff turnover', 'lack of continuity of care',
        'punitive punishments'
    ];

    // Normalization map: alternate terms → CHECKBOX VALUE
    // Only maps variations to their canonical checkbox form
    const allegationNormalizationMap = {
        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "physical abuse"
        // ═══════════════════════════════════════════════════════════
        'corporal punishment': 'physical abuse',
        'excessive punishment': 'physical abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "emotional abuse"
        // ═══════════════════════════════════════════════════════════
        'emotional/verbal abuse': 'emotional abuse',

        // Note: "psychological abuse" and "verbal abuse" are their OWN checkboxes

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "sexual abuse"
        // ═══════════════════════════════════════════════════════════
        'sexual exploitation': 'sexual abuse',
        'sexual misconduct': 'sexual abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "medical neglect"
        // ═══════════════════════════════════════════════════════════
        'failure to treat illness': 'medical neglect',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "withholding medical care"
        // ═══════════════════════════════════════════════════════════
        'refusing treatment': 'withholding medical care',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "overmedication"
        // ═══════════════════════════════════════════════════════════
        'inappropriate medication': 'overmedication',
        'medication abuse': 'overmedication',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "restraint abuse"
        // ═══════════════════════════════════════════════════════════
        'violent restraints': 'restraint abuse',
        'violent and excessive restraints': 'restraint abuse',

        // Note: "excessive restraints" is its OWN checkbox

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "solitary confinement"
        // ═══════════════════════════════════════════════════════════
        'lock-down': 'solitary confinement',

        // Note: "isolation" is its OWN checkbox

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "sleep deprivation"
        // ═══════════════════════════════════════════════════════════
        'forced sleep deprivation': 'sleep deprivation',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "food deprivation"
        // ═══════════════════════════════════════════════════════════
        'withholding food': 'food deprivation',
        'starvation': 'food deprivation',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "humiliation"
        // ═══════════════════════════════════════════════════════════
        'degradation': 'humiliation',
        'public shaming': 'humiliation',
        'humiliation tactics': 'humiliation',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "intimidation"
        // ═══════════════════════════════════════════════════════════
        'threats': 'intimidation',
        'coercion': 'intimidation',
        'fear-based practices': 'intimidation',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "inadequate supervision"
        // ═══════════════════════════════════════════════════════════
        'lack of supervision': 'inadequate supervision',
        'improper supervision': 'inadequate supervision',
        'improper supervision of students': 'inadequate supervision',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "untrained staff"
        // ═══════════════════════════════════════════════════════════
        'unqualified staff': 'untrained staff',
        'inadequate staff training': 'untrained staff',
        'undertrained/unqualified staff': 'untrained staff',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "fraudulent marketing"
        // ═══════════════════════════════════════════════════════════
        'deceptive advertising': 'fraudulent marketing',
        'false claims': 'fraudulent marketing',
        'deceptive/fraudulent marketing practices': 'fraudulent marketing',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "unsanitary conditions"
        // ═══════════════════════════════════════════════════════════
        'unsafe facilities': 'unsanitary conditions',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "educational neglect"
        // ═══════════════════════════════════════════════════════════
        'lack of education': 'educational neglect'
    };

    // Normalization function
    function normalizeAllegation(allegation) {
        const lower = allegation.toLowerCase().trim();
        return allegationNormalizationMap[lower] || allegation;
    }

    // Standard checkbox values from the form (canonical terms)
    const standardAllegations = [
        'physical abuse',
        'emotional abuse',
        'psychological abuse',
        'sexual abuse',
        'verbal abuse',
        'medical neglect',
        'educational neglect',
        'food deprivation',
        'sleep deprivation',
        'withholding medical care',
        'restraint abuse',
        'excessive restraints',
        'solitary confinement',
        'isolation',
        'humiliation',
        'intimidation',
        'overmedication',
        'inadequate supervision',
        'untrained staff',
        'fraudulent marketing',
        'unsanitary conditions'
    ];

    // Standard diagnosis checkbox values from the form (clinical diagnoses)
    const standardDiagnoses = [
        'ADHD',
        'Autism Spectrum Disorder',
        'Bipolar Disorder',
        'Depression',
        'Anxiety',
        'OCD',
        'PTSD',
        'Oppositional Defiant Disorder',
        'Conduct Disorder',
        'Eating Disorder',
        'Substance Abuse',
        'Borderline Personality Disorder',
        'Psychiatric Disorders',
        'Behavioral Disorders',
        'Emotional Disorders',
        'Co-occurring Disorders'
    ];

    // Standard behavior checkbox values from the form (behavioral issues)
    const standardBehaviors = [
        'defiance',
        'aggression',
        'self-harm',
        'running away',
        'truancy',
        'academic struggles',
        'social problems',
        'family conflict',
        'sexually inappropriate behavior',
        'lying',
        'manipulation',
        'stealing',
        'emotional dysregulation',
        'impulsive behavior',
        'attachment',
        'adoption',
        'trafficking'
    ];

    // Diagnosis normalization map: alternate terms → CHECKBOX VALUE
    // Only maps variations to their canonical checkbox form
    const diagnosisNormalizationMap = {
        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "ADHD"
        // ═══════════════════════════════════════════════════════════
        'add': 'ADHD',
        'attention deficit disorder': 'ADHD',
        'attention deficit hyperactivity disorder': 'ADHD',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Autism Spectrum Disorder"
        // ═══════════════════════════════════════════════════════════
        'autism': 'Autism Spectrum Disorder',
        'asd': 'Autism Spectrum Disorder',
        'asperger\'s': 'Autism Spectrum Disorder',
        'aspergers': 'Autism Spectrum Disorder',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Bipolar Disorder"
        // ═══════════════════════════════════════════════════════════
        'bipolar': 'Bipolar Disorder',
        'manic depression': 'Bipolar Disorder',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Depression"
        // ═══════════════════════════════════════════════════════════
        'major depression': 'Depression',
        'major depressive disorder': 'Depression',
        'mdd': 'Depression',
        'depressive disorder': 'Depression',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Anxiety"
        // ═══════════════════════════════════════════════════════════
        'anxiety disorder': 'Anxiety',
        'generalized anxiety disorder': 'Anxiety',
        'gad': 'Anxiety',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "OCD"
        // ═══════════════════════════════════════════════════════════
        'obsessive compulsive disorder': 'OCD',
        'obsessive-compulsive disorder': 'OCD',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "PTSD"
        // ═══════════════════════════════════════════════════════════
        'post traumatic stress disorder': 'PTSD',
        'post-traumatic stress disorder': 'PTSD',
        'trauma': 'PTSD',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Oppositional Defiant Disorder"
        // ═══════════════════════════════════════════════════════════
        'odd': 'Oppositional Defiant Disorder',
        'oppositional defiance': 'Oppositional Defiant Disorder',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Conduct Disorder"
        // ═══════════════════════════════════════════════════════════
        // (no alternate terms yet)

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Eating Disorder"
        // ═══════════════════════════════════════════════════════════
        'anorexia': 'Eating Disorder',
        'bulimia': 'Eating Disorder',
        'eating disorders': 'Eating Disorder',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Substance Abuse"
        // ═══════════════════════════════════════════════════════════
        'drug abuse': 'Substance Abuse',
        'addiction': 'Substance Abuse',
        'substance use disorder': 'Substance Abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Borderline Personality Disorder"
        // ═══════════════════════════════════════════════════════════
        'bpd': 'Borderline Personality Disorder',
        'borderline': 'Borderline Personality Disorder',

        // General disorder categories
        'psychiatric': 'Psychiatric Disorders',
        'psychiatric disorders': 'Psychiatric Disorders',
        'behavioral': 'Behavioral Disorders',
        'behavioral disorders': 'Behavioral Disorders',
        'emotional': 'Emotional Disorders',
        'emotional disorders': 'Emotional Disorders',
        'co-occurring disorders': 'Co-occurring Disorders',
        'cooccurring disorders': 'Co-occurring Disorders',
        'co occurring disorders': 'Co-occurring Disorders'
    };

    // Normalization function for diagnoses
    function normalizeDiagnosis(diagnosis) {
        const lower = diagnosis.toLowerCase().trim();
        return diagnosisNormalizationMap[lower] || diagnosis;
    }

    // Behavior normalization map: alternate terms → CHECKBOX VALUE
    const behaviorNormalizationMap = {
        'oppositional behavior': 'defiance',
        'defiant behavior': 'defiance',
        'aggressive behavior': 'aggression',
        'violence': 'aggression',
        'violent behavior': 'aggression',
        'cutting': 'self-harm',
        'self harm': 'self-harm',
        'self injury': 'self-harm',
        'self-injurious behavior': 'self-harm',
        'elopement': 'running away',
        'runaway': 'running away',
        'school refusal': 'truancy',
        'skipping school': 'truancy',
        'school struggles': 'academic struggles',
        'academic problems': 'academic struggles',
        'failing grades': 'academic struggles',
        'peer problems': 'social problems',
        'social issues': 'social problems',
        'social isolation': 'social problems',
        'bullying': 'social problems',
        'family problems': 'family conflict',
        'parent-child conflict': 'family conflict',
        'sexual acting out': 'sexually inappropriate behavior',
        'inappropriate sexual behavior': 'sexually inappropriate behavior',
        'dishonesty': 'lying',
        'deceit': 'lying',
        'manipulative behavior': 'manipulation',
        'manipulative': 'manipulation',
        'theft': 'stealing',
        'mood swings': 'emotional dysregulation',
        'emotional instability': 'emotional dysregulation',
        'impulsivity': 'impulsive behavior',
        'poor impulse control': 'impulsive behavior',
        'attachment issues': 'attachment',
        'inability to bond': 'attachment',
        'trust issues': 'attachment',
        'adoption issues': 'adoption',
        'adopted child issues': 'adoption',
        'adopted': 'adoption',
        'sex trafficking': 'trafficking',
        'human trafficking': 'trafficking',
        'trafficking victim': 'trafficking'
    };

    // Normalization function for behaviors
    function normalizeBehavior(behavior) {
        const lower = behavior.toLowerCase().trim();
        return behaviorNormalizationMap[lower] || behavior;
    }

    // Common diagnoses and behavioral issues to look for
    const commonDiagnoses = clinicalDiagnoses.concat(behavioralIssues);

    // Common abuse allegations
    const abuseAllegations = commonComplaints;

    // Lawsuit and legal keywords
    const lawsuitKeywords = [
        'sued', 'lawsuit', 'litigation', 'settlement', 'plaintiff', 'defendant',
        'allegations', 'complaint', 'civil suit', 'class action', 'damages',
        'criminal charges', 'indictment', 'conviction', 'investigation',
        'non-compliant', 'violations', 'citations', 'regulatory action'
    ];

    // Expand known acronyms to full company names
    const expandAcronym = (name) => {
        if (!name) return name;
        const trimmed = name.trim();
        const upperName = trimmed.toUpperCase();
        if (companyAcronyms[upperName]) {
            return companyAcronyms[upperName];
        }
        for (const [acronym, fullName] of Object.entries(companyAcronyms)) {
            if (trimmed.toUpperCase().startsWith(acronym + ' ') || trimmed.toUpperCase() === acronym) {
                return fullName;
            }
        }
        return trimmed;
    };

    const escapeRegex = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const getSection = (source, sectionTitle) => {
        const escapedTitle = escapeRegex(sectionTitle);
        const headerPattern = `#{1,6}\\s*\\*{0,2}\\s*${escapedTitle}\\s*\\*{0,2}\\s*`;
        const regex = new RegExp(
            headerPattern + '\\n([\\s\\S]*?)(?=\\n#{1,6}\\s|\\n\\*\\*\\*|$)',
            'i'
        );

        const match = source.match(regex);
        let result = match ? match[1].trim() : '';
        result = result.replace(/^\*\*\*|(?:\r\n|\n|\r)?\*\*\*$/g, '').trim();

        console.log(`getSection("${sectionTitle}"):`, result ? `Found (${result.length} chars)` : 'Not found');
        return result;
    };

    const getSectionAny = (source, titles) => {
        for (const title of titles) {
            const section = getSection(source, title);
            if (section) return section;
        }
        return '';
    };

    // Parse header
    // 1. Try standard TTI format: ## **Name** (Years) Location
    let headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*\(([^)]+)\)\s+([^\r\n]+)/m);
    
    if (headerMatch) {
        parsedData.programName = headerMatch[1].trim();
        parsedData.yearsActive = headerMatch[2].trim();
        parsedData.cityState = headerMatch[3].trim();
    } else {
        // 2. Try simpler format: ## **Name** (Years)
        headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*\(([^)]+)\)/m);
        if (headerMatch) {
            parsedData.programName = headerMatch[1].trim();
            parsedData.yearsActive = headerMatch[2].trim();
        } else {
            // 3. Try simplest format: ## Name
            // We search for the first header that isn't a known section header
            const allHeaders = normalizedMarkdown.matchAll(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*$/gm);
            for (const match of allHeaders) {
                const candidate = match[1].trim();
                const invalidTitles = [
                    'History', 'Background', 'Staff', 'Founders', 'Program Structure', 
                    'Structure', 'Rules', 'Punishments', 'Media', 'News', 'Testimonies', 
                    'Related Media', 'Links', 'Page title'
                ];
                
                // If it doesn't contain invalid titles and is reasonably short (program name)
                if (!invalidTitles.some(t => candidate.toLowerCase().includes(t.toLowerCase())) && candidate.length < 100) {
                     parsedData.programName = candidate;
                     break; // Found the first valid-looking program name
                }
            }
        }
    }
    console.log('Header match result:', parsedData.programName);

    // Parse program type
    const typeMatch = normalizedMarkdown.match(/^\s*\*([^*]+)\*\s*$/m);
    if (typeMatch) {
        parsedData.programType = typeMatch[1].trim();
    }

    // Parse History section
    const historySection = getSectionAny(normalizedMarkdown, [
        'History and Background Information',
        'History/Background Information',
        'Hisory and Background Information',
        'History and Bakcground Information',
        'Background Information'
    ]);
    if (historySection && !historySection.includes('No information is known')) {
        const normalizedHistory = historySection.replace(/[\u2013\u2014]/g, '-');

        // Year founded
        const yearFoundedMatch = normalizedHistory.match(/(?:founded|opened|started|established|began)\s+(?:in\s+)?(\d{4})/i);
        if (yearFoundedMatch) {
            parsedData.yearFounded = yearFoundedMatch[1].trim();
        }

        // Owner
        const ownerPatternsWithLinks = [
            /is\s+(?:an?|the)?\s*\[([^\]]+)\]\(([^)]+)\)\s+(?:[^.\n]*?(?:program|school|facility|center|behavior))/i,
            /owned by \[([^\]]+)\]\(([^)]+)\)/i,
            /operated by \[([^\]]+)\]\(([^)]+)\)/i,
            /run by \[([^\]]+)\]\(([^)]+)\)/i,
            /part of \[([^\]]+)\]\(([^)]+)\)/i,
            /was\s+(?:an?|the)?\s*\[([^\]]+)\]\(([^)]+)\)\s+(?:[^.\n]*?(?:program|school|facility|center))/i
        ];

        let ownerCaptured = false;
        for (const pattern of ownerPatternsWithLinks) {
            const match = normalizedHistory.match(pattern);
            if (match) {
                parsedData.ownerName = match[1].trim();
                parsedData.ownerLink = sanitizeUrl(match[2]);
                ownerCaptured = true;
                break;
            }
        }

        if (!ownerCaptured) {
            const ownerTextPatterns = [
                /owned by ([^.\n]+)/i,
                /operated by ([^.\n]+)/i,
                /run by ([^.\n]+)/i,
                /part of ([^.\n]+)/i,
                /was\s+(?:an?|the)?\s*([^.\n]+?)\s+(?:behavior|residential|therapeutic|treatment)[^.\n]*program/i
            ];
            for (const pattern of ownerTextPatterns) {
                const match = normalizedHistory.match(pattern);
                if (match) {
                    parsedData.ownerName = match[1].trim();
                    break;
                }
            }
        }

        // Ownership changes
        const priorOwnerLinkMatch = normalizedHistory.match(
            /prior to (?:being )?(?:purchased|acquired|bought) by ([^\s,]+(?:\s+[^\s,]+)?)\s+in\s+(\d{4})[^.]*?owned by \[([^\]]+)\]\(([^)]+)\)/i
        );

        if (priorOwnerLinkMatch) {
            parsedData.ownershipChanges.push({
                year: priorOwnerLinkMatch[2].trim(),
                previous: priorOwnerLinkMatch[3].trim(),
                previousLink: priorOwnerLinkMatch[4].trim(),
                newOwner: expandAcronym(priorOwnerLinkMatch[1]),
                newOwnerLink: ''
            });
        } else {
            const priorOwnerTextMatch = normalizedHistory.match(
                /prior to (?:being )?(?:purchased|acquired|bought) by ([^\s,]+(?:\s+[^\s,]+)?)\s+in\s+(\d{4})[^.]*?owned by ([^.\n\[]+)/i
            );
            if (priorOwnerTextMatch) {
                parsedData.ownershipChanges.push({
                    year: priorOwnerTextMatch[2].trim(),
                    previous: expandAcronym(priorOwnerTextMatch[3]),
                    previousLink: '',
                    newOwner: expandAcronym(priorOwnerTextMatch[1]),
                    newOwnerLink: ''
                });
            }
        }

        // Alternative ownership change pattern
        const purchasedInYearMatch = normalizedHistory.match(
            /in\s+(\d{4})[^.]*?(?:was\s+)?(?:purchased|acquired|bought)\s+by\s+\[([^\]]+)\]\(([^)]+)\)/i
        );
        if (purchasedInYearMatch && parsedData.ownershipChanges.length === 0) {
            parsedData.ownershipChanges.push({
                year: purchasedInYearMatch[1].trim(),
                previous: '',
                previousLink: '',
                newOwner: expandAcronym(purchasedInYearMatch[2]),
                newOwnerLink: purchasedInYearMatch[3].trim()
            });
        }

        // Age range
        const agePatterns = [
            /\((\d{1,2})\s*-\s*(\d{1,2})\)/i,
            /aged?\s+(?:between\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
            /ages?\s+(?:between\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
            /age range\s+(?:of\s+)?(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
            /serves\s+[^.\n]*?ages?\s+(\d{1,2})\s*(?:-|to)\s*(\d{1,2})/i,
            /aged?\s+(\d{1,2})\s*\+/i
        ];
        for (const pattern of agePatterns) {
            const match = normalizedHistory.match(pattern);
            if (match) {
                parsedData.ageRange = match[2] ? `${match[1]}-${match[2]}` : `${match[1]}+`;
                break;
            }
        }

        // Diagnoses - comprehensive extraction with lookup matching
        const diagnosisSnippets = [];
        const combinedDisordersPattern = /psychiatric[\s,\/&-]+behavioral[\s,\/&-]+emotional[\s,\/&-]+(?:and\s+)?co[-\s]?occurring\s+disorders/i;
        if (combinedDisordersPattern.test(normalizedHistory)) {
            ['Psychiatric Disorders', 'Behavioral Disorders', 'Emotional Disorders', 'Co-occurring Disorders'].forEach(category => {
                if (!parsedData.selectedDiagnoses.includes(category)) {
                    parsedData.selectedDiagnoses.push(category);
                }
            });
        }
        const diagnosisPatterns = [
            /diagnoses\/behaviors:\s*([^\n]+)/i,
            /any of the following:\s*([^\.]+)\./i,
            /such as\s+([^\.]+?)(?:\.|The program|The cost|The average|The facility|The campus)/i,
            /including\s+([^\.]+?)(?:\.|The program|The cost|The average|The facility|The campus)/i,
            /specializes? in treating\s+([^\.]+?)(?:\.|, but)/i,
            /specialized in treating\s+([^\.]+?)(?:\.|, but)/i,
            /treats?\s+(?:students|residents|clients|girls|boys|young people)[^:]*:\s*([^\.]+)\./i,
            /struggling with\s+(?:a variety of |various )?(?:challenges such as )?([^\.]+?)(?:\.|The program|The cost|The average|The facility|The campus)/i,
            /who (?:struggle|are struggling|deal|are dealing)\s+with\s+(?:a variety of )?(?:challenges such as )?([^\.]+?)(?:\.|The program|The cost|The average|The facility|The campus)/i,
            /marketed (?:as|to)[^\.]*?for[^\.]*?who struggle with\s+([^\.]+?)(?:\.|The program)/i
        ];
        diagnosisPatterns.forEach(pattern => {
            const match = normalizedHistory.match(pattern);
            if (match && match[1]) {
                const cleaned = match[1].replace(/["*_]/g, '').replace(/\s+/g, ' ').trim();
                if (cleaned) {
                    diagnosisSnippets.push(cleaned.replace(/\.$/, ''));
                }
            }
        });
        
        // Extract individual diagnoses from snippets and match against lookup tables
        if (diagnosisSnippets.length > 0) {
            const extractedDiagnoses = [];
            
            diagnosisSnippets.forEach(snippet => {
                // Split by common delimiters
                const items = snippet.split(/,\s*(?:and\s+)?|;\s*/);
                items.forEach(item => {
                    const trimmed = item.trim();
                    // Filter out empty strings, markdown links, and URLs
                    if (trimmed && !trimmed.startsWith('[') && !trimmed.includes('http')) {
                        extractedDiagnoses.push(trimmed);
                    }
                });
            });
            
            // Match extracted items against standard checkbox values with normalization
            const customDiagnosisList = [];
            const customBehaviorList = [];

            extractedDiagnoses.forEach(item => {
                // Try normalizing the item
                let normalized = normalizeDiagnosis(item);
                if (normalized === item) {
                    // If diagnosis normalization didn't change it, try behavior normalization
                    normalized = normalizeBehavior(item);
                }
                const lowerItem = normalized.toLowerCase();

                // Check if it matches any standard profile item
                const allStandardItems = [...standardDiagnoses, ...standardBehaviors];
                const matchedItem = allStandardItems.find(standard =>
                    standard.toLowerCase() === lowerItem
                );

                if (matchedItem) {
                    // Add to profile list if not already present
                    if (!parsedData.selectedDiagnoses.includes(matchedItem)) {
                        parsedData.selectedDiagnoses.push(matchedItem);
                    }
                } else {
                    // Determine if it's more likely a clinical diagnosis or behavior
                    const clinicalKeywords = ['disorder', 'syndrome', 'disability', 'condition', 'disease'];
                    const isClinical = clinicalKeywords.some(keyword =>
                        normalized.toLowerCase().includes(keyword)
                    );

                    if (isClinical) {
                        customDiagnosisList.push(normalized);
                    } else {
                        customBehaviorList.push(normalized);
                    }
                }
            });
            
            // Store custom profile items
            const combinedCustomProfileItems = [...customDiagnosisList, ...customBehaviorList];
            if (combinedCustomProfileItems.length > 0) {
                parsedData.customDiagnoses = combinedCustomProfileItems.join(', ');
            }
        }

        // Capacity/Enrollment
        const capacityPatterns = [
            /maximum enrollment of ([^.,\n]+?)(?:\.|,|$| and)/i,
            /capacity (?:of|for) ([^.,\n]+?)(?:\.|,|$| and)/i,
            /(?:can accommodate|accommodates) (?:up to )?([^.,\n]+?)(?:\.|,|$| and)/i,
            /serves (?:up to )?([^.,\n]+?)(?:\.|,|$| and)/i,
            /enrollment (?:is|was) ([^.,\n]+?)(?:\.|,|$| and)/i
        ];
        for (const pattern of capacityPatterns) {
            const match = normalizedHistory.match(pattern);
            if (match) {
                parsedData.capacity = match[1].trim();
                break;
            }
        }

        // Campus size/details
        const campusPatterns = [
            /(?:situated|located) on (\d+[^\.]+?acres[^\.]*?)(?:\.|and is)/i,
            /campus (?:is|spans) (\d+[^\.]+?acres[^\.]*?)(?:\.|and)/i,
            /(\d+(?:-|\s+to\s+)\d+\s+acres)[^\.]*?campus/i
        ];
        for (const pattern of campusPatterns) {
            const match = normalizedHistory.match(pattern);
            if (match) {
                parsedData.campusSize = match[1].trim();
                break;
            }
        }

        // Average stay
        const stayMatch = normalizedHistory.match(/average length of stay[^0-9]*(\d+\s*(?:days?|weeks?|months?|years?)[^,\.\n]*)/i);
        if (stayMatch) {
            parsedData.avgStay = stayMatch[1].trim();
        }

        // Tuition
        // 1. Look for explicit "Unknown" / "Undisclosed"
        const tuitionUnknownMatch = normalizedHistory.match(/(?:tuition|cost)[^.\n]*?(?:is|was|remains)\s+(unknown|undisclosed|not listed|not public)/i);
        if (tuitionUnknownMatch) {
            parsedData.tuition = "Unknown"; // Normalize to standard "Unknown"
        } else {
            // 2. Look for currency amount (ensure we don't cross sentence boundaries)
            // Matches $xx,xxx or $xx,xxx.xx
            const tuitionMatch = normalizedHistory.match(/(?:tuition|cost)[^.$\n]*?(\$[\d,]+(?:\.\d{2})?)/i);
            if (tuitionMatch) {
                parsedData.tuition = tuitionMatch[1].trim();
            }
        }

        // NATSAP membership
        const natsapPatterns = [
            { pattern: /is\s+(?:a\s+)?(?:current\s+)?NATSAP\s+member/i, value: 'yes' },
            { pattern: /has\s+been\s+(?:a\s+)?NATSAP\s+member\s+since\s+(\d{4})/i, value: 'yes', yearGroup: 1 },
            { pattern: /NATSAP\s+member\s+since\s+(\d{4})/i, value: 'yes', yearGroup: 1 },
            { pattern: /is\s+(?:a\s+)?former\s+NATSAP\s+member/i, value: 'former' },
            { pattern: /was\s+(?:a\s+)?NATSAP\s+member/i, value: 'former' },
            { pattern: /is\s+not\s+(?:a\s+)?NATSAP\s+member/i, value: 'no' },
            { pattern: /not\s+(?:a\s+)?NATSAP\s+member/i, value: 'no' }
        ];

        for (const { pattern, value, yearGroup } of natsapPatterns) {
            const natsapMatch = normalizedHistory.match(pattern);
            if (natsapMatch) {
                parsedData.natsapMember = value;
                if (yearGroup && natsapMatch[yearGroup]) {
                    parsedData.natsapYear = natsapMatch[yearGroup];
                }
                break;
            }
        }

        // Address
        const addressMatch = normalizedHistory.match(/located\s+(?:at|as|in)\s+\[([^\]]+)\]\(([^)]+)\)/i);
        if (addressMatch) {
            parsedData.mainAddress = addressMatch[1];
            parsedData.addressLink = sanitizeUrl(addressMatch[2]);
        } else {
            const addressTextMatch = normalizedHistory.match(/located\s+(?:at|as|in)\s+([^\.\n]+)/i);
            if (addressTextMatch) {
                parsedData.mainAddress = addressTextMatch[1].trim();
            }
        }

        // Accrediting body
        const accreditMatch = normalizedHistory.match(/accredited through the \[([^\]]+)\]\(([^)]+)\)/i);
        if (accreditMatch) {
            parsedData.accreditingBody = accreditMatch[1];
            parsedData.accreditingBodyLink = sanitizeUrl(accreditMatch[2]);
        }

        // DON'T store everything in historyMisc - let the user edit form fields
        // historyMisc should only contain content that truly couldn't be parsed
        // For now, leave it empty and rely on structured fields
        // parsedData.historyMisc = historySection;
    }

    // Parse Staff section
    const staffSection = getSectionAny(normalizedMarkdown, [
        'Founders and Notable Staff',
        'Staff',
        'Notable Staff',
        'Founders',
        'Founders and Leadership',
        'Founders and Notable Employees',
        'Founders & Notable Employees',
        'Founder & Notable Staff',
        'Founders & Notable Staff',
        'Notable Employees',
        'WWASP Owners and Staff',
        'Founders and Important People',
        'Founders and Notable Figures',
        'Founders & Notable Members',
        'Founders and Employees'
    ]);

    if (staffSection && !staffSection.includes('No information is known')) {
        const staffBlocks = staffSection.split(/\n(?=\*\*[^*]+\*\*(?:\s*is|\s*was|\s*formerly))/);

        staffBlocks.forEach(block => {
            const trimmed = block.trim();
            if (!trimmed) return;

            const nameMatch = trimmed.match(/^\*\*([^*]+)\*\*/);
            if (!nameMatch) return;

            const name = nameMatch[1].trim();
            const bioText = trimmed.substring(nameMatch[0].length).trim();

            const isFormer = /formerly|former|ex-/i.test(bioText);

            let role = '';
            const rolePatterns = [
                /is\s+(?:the\s+)?([^.]+?)(?:\s+of|\s+at|\.|$)/i,
                /was\s+(?:the\s+)?([^.]+?)(?:\s+of|\s+at|\.|$)/i,
                /formerly\s+([^.]+?)(?:\s+of|\s+at|\.|$)/i,
                /worked\s+as\s+(?:the\s+)?([^.]+?)(?:\s+of|\s+at|\.|$)/i
            ];

            for (const pattern of rolePatterns) {
                const roleMatch = bioText.match(pattern);
                if (roleMatch) {
                    role = roleMatch[1].trim();
                    break;
                }
            }

            const previousRoles = [];
            const prevRoleMatches = bioText.matchAll(/(?:prior|previous|formerly)[^.]*?(?:worked|served|employed)\s+(?:as|at)\s+([^.,]+)/gi);
            for (const match of prevRoleMatches) {
                previousRoles.push({ role: match[1].trim(), employer: '' });
            }

            parsedData.staffMembers.push({
                name,
                role,
                bio: bioText,
                previousRoles,
                isFormer
            });
        });
    }

    // Parse Program Structure section
    const structureSection = getSectionAny(normalizedMarkdown, [
        'Program Structure',
        'Structure',
        'Program Model',
        'Level System',
        'Level Systems',
        'Phase System',
        'Phases',
        'Program Phases',
        'Program Structure & Rules',
        'Seminar Structure'
    ]);

    if (structureSection && !structureSection.includes('No information is known')) {
        // Extract level system description (first sentence or paragraph mentioning level/phase system)
        const levelDescMatch = structureSection.match(/(?:uses|used|utilizes|utilized|implements|implemented)\s+([^\.]*(?:level|phase|tier|point)[^\.]+)/i);
        if (levelDescMatch) {
            parsedData.levelSystemDesc = levelDescMatch[1].trim();
        }

        // Parse levels/phases/stages - Match pattern: - **Level Name:** Description
        // This matches bullet points with bold level names followed by descriptions
        const levelMatches = structureSection.matchAll(/-\s+\*\*([^*:]+?)\*\*\s*:?\s*([\s\S]*?)(?=\n-\s+\*\*|\n\nAs stated|\n\nThere is also|\n##|\n\*\*\*|$)/g);
        
        for (const match of levelMatches) {
            const levelName = match[1].trim();
            const levelDescription = match[2].trim();
            
            // Store the level with name and complete description
            parsedData.programLevels.push({
                name: levelName,
                description: levelDescription
            });
        }

        // Parse special punishment levels like "Back to Basics" or "Frozen"
        // Match: There is also a level called **Name** (also called "AltName") which is used as punishment...
        const punishmentPattern = /There is also a (?:level|phase|stage) called\s+[""]?\*\*([^*"]+)\*\*[""]?([\s\S]*?)(?=\n\n(?:As stated|There is also|##)|$)/i;
        const punishmentMatch = structureSection.match(punishmentPattern);
        
        if (punishmentMatch) {
            const name = punishmentMatch[1].trim();
            const fullDescription = punishmentMatch[2].trim();
            
            parsedData.punishments.push({
                name: name,
                description: fullDescription
            });
        }

        // Store any miscellaneous structure content for preservation
        parsedData.structureMisc = structureSection;
    }

    // Parse Abuse section
    const abuseSection = getSectionAny(normalizedMarkdown, [
        'Abuse/Neglect Allegations and Lawsuits',
        'Abuse Allegations',
        'Abuse/Neglect Allegations',
        'Allegations and Lawsuits',
        'Abuse Allegations and Lawsuits',
        'Abuse Allegations and Death',
        'Abuse Allegations, Lawsuits, and Death',
        'Abuse Allegations, Deaths, and Lawsuits',
        'Abuse Allegations and Investigations',
        'Abuse Allegations and Rebranding',
        'Abuse and Closure',
        'Abuse, Deaths, and Investigations',
        'Abuse and Investigations',
        'Abuse and Death',
        'Abuse and Lawsuits',
        'Abuse, Rebrands, and the Fire',
        'Abuse Allegations and Red Flags',
        'Abuse',
        'Abuse and Violence',
        'Controversy, Abuse, and Deaths',
        'Abuse/Neglect Allegations',
        'Abuse and Neglect Allegations'
    ]);

    if (abuseSection && !abuseSection.includes('No information is known')) {
        // Store the FULL abuse section text for preservation
        parsedData.lawsuitsMisc = abuseSection;

        // Main complaints - extract the summary statement
        const complaintsMatch = abuseSection.match(/(?:main|primary|common) complaints (?:are|include) (?:of )?([^\.]+)/i);
        if (complaintsMatch) {
            parsedData.mainComplaints = complaintsMatch[1].trim();
        }

        // Allegations - extract and match against common complaints checklist
        let extractedAllegations = [];
        
        // Try bulleted list format
        const allegationsListMatch = abuseSection.match(/(?:Allegations?|Reports?|Complaints?)[^:]*(?:include|included|reported|documented)[:\s]*\n((?:[*-]\s+[^\n]+\n?)+)/i);
        if (allegationsListMatch) {
            extractedAllegations = allegationsListMatch[1]
                .split('\n')
                .filter(line => line.trim().startsWith('*') || line.trim().startsWith('-'))
                .map(line => line.replace(/^[*-]\s*/, '').trim())
                .filter(Boolean);
        }
        
        // Try comma-separated format if bulleted list not found
        if (extractedAllegations.length === 0) {
            // Try the specific "Allegations of abuse and neglect that have been reported by survivors include" pattern
            let commaSeparatedMatch = abuseSection.match(/Allegations? of (?:abuse and neglect|abuse|neglect) that have been reported by survivors include ([^\.]+)\./i);
            
            // Fallback to generic comma-separated pattern
            if (!commaSeparatedMatch) {
                commaSeparatedMatch = abuseSection.match(/(?:Allegations?|Reports?|Complaints?)[^:]*(?:include|included)[:\s]+([^\.]+)\./i);
            }
            
            if (commaSeparatedMatch) {
                const allegationsText = commaSeparatedMatch[1].trim();
                extractedAllegations = allegationsText
                    .split(/,\s*(?:and\s+)?/)
                    .map(a => a.trim())
                    .filter(Boolean);
            }
        }
        
        // Match extracted allegations against standard checkbox values with normalization
        if (extractedAllegations.length > 0) {
            const customList = [];

            extractedAllegations.forEach(allegation => {
                // Normalize the allegation to standard terminology
                const normalized = normalizeAllegation(allegation);
                const lowerNormalized = normalized.toLowerCase();

                // Check if the normalized allegation matches a standard checkbox value
                const matchedStandard = standardAllegations.find(standard =>
                    standard.toLowerCase() === lowerNormalized
                );

                if (matchedStandard) {
                    // Add the standard form if not already present
                    if (!parsedData.selectedAllegations.includes(matchedStandard)) {
                        parsedData.selectedAllegations.push(matchedStandard);
                    }
                } else {
                    // Add to custom allegations (use normalized form)
                    customList.push(normalized);
                }
            });
            
            // Store custom allegations
            if (customList.length > 0) {
                parsedData.customAllegations = customList.join(', ');
            }
        }

        // Parse paragraph-by-paragraph for lawsuits and incidents
        const paragraphs = abuseSection.split(/\n+/);
        
        paragraphs.forEach(para => {
            const trimmed = para.trim();
            if (!trimmed || trimmed.length < 10) return;

            // Remove leading bullet points
            const cleanText = trimmed.replace(/^[*•-]\s*/, '');

            // 1. Year-first patterns: "In 2005, ..." or "2005: ..." or "**2005** ..."
            const yearFirstMatch = cleanText.match(/^(?:In\s+)?(\d{4})[:,-]?\s+(.+)/i);
            
            if (yearFirstMatch) {
                const year = yearFirstMatch[1];
                const description = yearFirstMatch[2].trim();
                const lowerDesc = description.toLowerCase();

                // Decide if it's a lawsuit or general incident
                if (lowerDesc.includes('sued') || lowerDesc.includes('lawsuit') || lowerDesc.includes('settlement') || lowerDesc.includes('complaint')) {
                    parsedData.lawsuits.push({
                        name: `${year} Lawsuit`,
                        description: description
                    });
                } else {
                    parsedData.lawsuits.push({
                        name: `${year} Incident`,
                        description: description
                    });
                }
                return; 
            }

            // 2. Generic lawsuit detection (contains year + legal keywords)
            // e.g. "A lawsuit was filed in 2005 by..."
            const genericLawsuitMatch = cleanText.match(/(\d{4})/);
            if (genericLawsuitMatch) {
                const year = genericLawsuitMatch[1];
                const lowerText = cleanText.toLowerCase();
                
                if (lawsuitKeywords.some(kw => lowerText.includes(kw))) {
                     parsedData.lawsuits.push({
                        name: `${year} Lawsuit`,
                        description: cleanText
                    });
                    return;
                }
            }

            // Regulatory/Compliance violations
            const complianceMatch = cleanText.match(/(?:was|were)\s+found\s+[""]?(?:non-compliant|in violation)[""]?[^\.]*?(\d+)\s+times[^\.]*?between\s+(\d{4})\s+and\s+(\d{4})[^\.]*?(?:for|regarding|concerning)\s+([^\.]+)/i);
            if (complianceMatch) {
                parsedData.allegations.push({
                    type: 'Regulatory Violations',
                    description: `Found non-compliant ${complianceMatch[1]} times between ${complianceMatch[2]} and ${complianceMatch[3]} for: ${complianceMatch[4]}`,
                    year: complianceMatch[2]
                });
            }
        });

        // Parse investigative reports (DLC, state agencies, etc.)
        const reportMatch = abuseSection.match(/The\s+([^']+?)'s\s+(?:finished\s+|official\s+)?(?:\[report\]\(([^)]+)\)|report)[^,]*,\s*(?:which\s+they\s+)?released\s+in\s+(\d{4})[^,]*,\s*(?:confirmed|documented|found)\s+([^\.]+)/i);
        if (reportMatch) {
            const organization = reportMatch[1].trim();
            const reportUrl = reportMatch[2] ? sanitizeUrl(reportMatch[2]) : '';
            const year = reportMatch[3];
            const findings = reportMatch[4].trim();

            // Look for bulleted findings
            const findingsListMatch = abuseSection.match(/(?:These\s+instances\s+included|Findings\s+included|The\s+report\s+documented)[:\s]*\n((?:[*-]\s+[^\n]+\n?)+)/i);
            let detailsList = '';
            if (findingsListMatch) {
                const findingItems = findingsListMatch[1]
                    .split('\n')
                    .filter(line => line.trim().startsWith('*') || line.trim().startsWith('-'))
                    .map(line => line.replace(/^[*-]\s*/, '').trim())
                    .filter(Boolean);
                detailsList = findingItems.join('\n');
            }

            parsedData.lawsuits.push({
                year: year,
                plaintiff: organization,
                allegations: findings,
                outcome: 'Investigation report published',
                details: detailsList,
                reportUrl: reportUrl
            });
        }
    }

    // Parse Rules and Punishments section
    const rulesSection = getSectionAny(normalizedMarkdown, [
        'Rules and Punishments',
        'Rules & Punishments',
        'Rules',
        'Punishments',
        'Rules and Consequences'
    ]);

    if (rulesSection && !rulesSection.includes('No information is known')) {
        // Extract rules (bulleted list)
        const ruleMatches = rulesSection.matchAll(/^[*-]\s+(.+)$/gm);
        for (const match of ruleMatches) {
            const ruleText = match[1].trim();
            // Only add if it doesn't look like a punishment (doesn't contain punishment keywords)
            if (!ruleText.match(/punishment|consequence|discipline/i)) {
                parsedData.rules.push(ruleText);
            }
        }

        // Extract punishments (look for structured punishment descriptions)
        const punishmentPatterns = rulesSection.split('\n\n');
        punishmentPatterns.forEach(block => {
            const trimmed = block.trim();
            // Match patterns like "**Name:** description" or "**Name** - description"
            const punishmentMatch = trimmed.match(/^\*\*([^*:]+?)(?::|–|-)\*\*\s*(.+)$/m);
            if (punishmentMatch) {
                parsedData.punishments.push({
                    name: punishmentMatch[1].trim(),
                    description: punishmentMatch[2].trim()
                });
            }
        });

        // DON'T store everything in punishmentsMisc - let the user edit form fields
        // parsedData.punishmentsMisc = rulesSection;
    }

    // Parse In the Media & News section
    const mediaSection = getSectionAny(normalizedMarkdown, [
        'In the Media & News',
        'In the Media',
        'Media & News',
        'Media and News',
        'News',
        'In the Media'
    ]);

    if (mediaSection && !mediaSection.includes('No information is known')) {
        // Extract news articles with links [Title](URL)
        const articleMatches = mediaSection.matchAll(/\[([^\]]+)\]\(([^)]+)\)/g);
        for (const match of articleMatches) {
            parsedData.newsArticles.push({
                title: match[1].trim(),
                url: sanitizeUrl(match[2])
            });
        }

        // DON'T store everything in mediaInfo - let the user edit form fields
        // parsedData.mediaInfo = mediaSection;
    }

    // Parse Survivor/Parent Testimonials section
    const testimoniesSection = getSectionAny(normalizedMarkdown, [
        'Survivor/Parent Testimonials',
        'Survivor Testimonials',
        'Survivor Testimonies',
        'Testimonies',
        'Survivor Accounts',
        'Survivor Stories',
        'Survivor/Parent/Ex-Staff Testimonies',
        'Survivor/Parent Testimonies'
    ]);

    if (testimoniesSection && !testimoniesSection.includes('No information is known')) {
        // Split by double newlines to preserve multi-line testimonies
        let testimonyBlocks = testimoniesSection.split(/\n\n+/);
        
        // Handle case where testimonies are a tight bulleted list (single newlines)
        const expandedBlocks = [];
        testimonyBlocks.forEach(block => {
            // If block contains multiple lines starting with "**" or "* **", it's likely a list
            // Check if it has multiple date-like headers
            const dateHeaders = block.match(/\*\*[\d/]+\s*[:(]/g);
            if (dateHeaders && dateHeaders.length > 1) {
                // Split this block by newlines
                const lines = block.split(/\n+/);
                lines.forEach(line => {
                    if (line.trim().length > 10) expandedBlocks.push(line);
                });
            } else {
                expandedBlocks.push(block);
            }
        });
        
        expandedBlocks.forEach(block => {
            const trimmed = block.trim();
            if (!trimmed || trimmed.length < 10) return;

            // Pattern 1: Standard "**Date: (Type)** Quote" (flexible whitespace)
            // Matches: **9/9/2021: (SURVIVOR)** "Quote..."
            // Using [\s\S] for quote capture to handle multi-line content
            const stdMatch = trimmed.match(/^\*\*([^:]+?):?\s*\(([^)]+)\)\*\*\s*"?([\s\S]+?)"?\s*[-–—]\s*(?:\[([^\]]+)\]\(([^)]+)\)|([^[(\n]+))/);
            
            if (stdMatch) {
                parsedData.testimonies.push({
                    date: stdMatch[1].trim(),
                    type: stdMatch[2].trim(),
                    quote: stdMatch[3].trim(),
                    source: (stdMatch[4] || stdMatch[6] || '').trim(),
                    url: stdMatch[5] ? sanitizeUrl(stdMatch[5]) : ''
                });
                return;
            }

            // Pattern 2: "**Date** (Type) Quote" (no colon, maybe no quotes)
            const noColonMatch = trimmed.match(/^\*\*([^*]+)\*\*\s*\(([^)]+)\)\s*"?([\s\S]+?)"?\s*[-–—]\s*(?:\[([^\]]+)\]\(([^)]+)\)|([^[(\n]+))/);
            
            if (noColonMatch) {
                parsedData.testimonies.push({
                    date: noColonMatch[1].trim(),
                    type: noColonMatch[2].trim(),
                    quote: noColonMatch[3].trim(),
                    source: (noColonMatch[4] || noColonMatch[6] || '').trim(),
                    url: noColonMatch[5] ? sanitizeUrl(noColonMatch[5]) : ''
                });
                return;
            }

            // Pattern 3: Simple "Quote" - Source (Fallback)
            const simpleMatch = trimmed.match(/"([\s\S]+?)"\s*[-–—]\s*(?:\[([^\]]+)\]\(([^)]+)\)|([^[(\n]+))/);
            
            if (simpleMatch) {
                parsedData.testimonies.push({
                    date: '',
                    type: '',
                    quote: simpleMatch[1].trim(),
                    source: (simpleMatch[2] || simpleMatch[4] || '').trim(),
                    url: simpleMatch[3] ? sanitizeUrl(simpleMatch[3]) : ''
                });
            }
        });

        // DON'T store everything in testimoniesMisc - let the user edit form fields
        // parsedData.testimoniesMisc = testimoniesSection;
        
        // Scan testimonies for abuse allegations keywords
        const testimoniesLower = testimoniesSection.toLowerCase();
        commonComplaints.forEach(complaint => {
            const complaintLower = complaint.toLowerCase();
            if (testimoniesLower.includes(complaintLower)) {
                const normalized = normalizeAllegation(complaint);
                if (normalized && !parsedData.selectedAllegations.includes(normalized)) {
                    parsedData.selectedAllegations.push(normalized);
                }
            }
        });
        
        console.log('Allegations detected from testimonies:', parsedData.selectedAllegations);
    }

    // Parse Related Media section
    const relatedMediaSection = getSectionAny(normalizedMarkdown, [
        'Related Media',
        'Related Media (Links)',
        'Related Links',
        'External Links',
        'Related External Resources'
    ]);

    if (relatedMediaSection && !relatedMediaSection.includes('No information is known')) {
        // Split into lines to parse each entry
        const lines = relatedMediaSection.split('\n').filter(line => line.trim());

        lines.forEach(line => {
            const trimmed = line.trim();

            // Match pattern: [Title](URL) (Source, Date)
            const withSourceMatch = trimmed.match(/\[([^\]]+)\]\(([^)]+)\)\s*\(([^,]+),\s*([^)]+)\)/);
            if (withSourceMatch) {
                parsedData.relatedMedia.push({
                    title: withSourceMatch[1].trim(),
                    url: sanitizeUrl(withSourceMatch[2]),
                    source: withSourceMatch[3].trim(),
                    date: withSourceMatch[4].trim()
                });
                return;
            }

            // Match simple pattern: [Title](URL)
            const simpleMatch = trimmed.match(/\[([^\]]+)\]\(([^)]+)\)/);
            if (simpleMatch) {
                parsedData.relatedMedia.push({
                    title: simpleMatch[1].trim(),
                    url: sanitizeUrl(simpleMatch[2]),
                    source: '',
                    date: ''
                });
            }
        });

        // DON'T store everything in relatedMediaMisc - let the user edit form fields
        // parsedData.relatedMediaMisc = relatedMediaSection;
    }

    // Collect unparsed sections
    const parsedSectionTitles = [
        'History and Background Information',
        'History/Background Information',
        'Hisory and Background Information',
        'History and Bakcground Information',
        'Background Information',
        'Founders and Notable Staff',
        'Staff',
        'Notable Staff',
        'Founders',
        'Founders and Leadership',
        'Founders and Notable Employees',
        'Founders & Notable Employees',
        'Founder & Notable Staff',
        'Founders & Notable Staff',
        'Notable Employees',
        'WWASP Owners and Staff',
        'Founders and Important People',
        'Founders and Notable Figures',
        'Founders & Notable Members',
        'Founders and Employees',
        'Program Structure',
        'Structure',
        'Program Model',
        'Level System',
        'Level Systems',
        'Phase System',
        'Phases',
        'Program Phases',
        'Program Structure & Rules',
        'Seminar Structure',
        'Abuse/Neglect Allegations and Lawsuits',
        'Abuse Allegations',
        'Abuse/Neglect Allegations',
        'Allegations and Lawsuits',
        'Abuse Allegations and Lawsuits',
        'Abuse Allegations and Death',
        'Abuse Allegations, Lawsuits, and Death',
        'Abuse Allegations, Deaths, and Lawsuits',
        'Abuse Allegations and Investigations',
        'Abuse Allegations and Rebranding',
        'Abuse and Closure',
        'Abuse, Deaths, and Investigations',
        'Abuse and Investigations',
        'Abuse and Death',
        'Abuse and Lawsuits',
        'Abuse, Rebrands, and the Fire',
        'Abuse Allegations and Red Flags',
        'Abuse',
        'Abuse and Violence',
        'Controversy, Abuse, and Deaths',
        'Abuse/Neglect Allegations',
        'Abuse and Neglect Allegations',
        'Rules and Punishments',
        'Rules & Punishments',
        'Rules',
        'Punishments',
        'Rules and Consequences',
        'In the Media & News',
        'In the Media',
        'Media & News',
        'Media and News',
        'News',
        'In the Media',
        'Survivor/Parent Testimonials',
        'Survivor Testimonials',
        'Survivor Testimonies',
        'Testimonies',
        'Survivor Accounts',
        'Survivor Stories',
        'Survivor/Parent/Ex-Staff Testimonies',
        'Survivor/Parent Testimonies',
        'Related Media',
        'Related Media (Links)',
        'Related Links',
        'External Links',
        'Related External Resources'
    ];

    // Split markdown into sections
    const sectionPattern = /##\s*\*{0,2}\s*([^\n*]+?)\s*\*{0,2}\s*\n([\s\S]*?)(?=\n##|\n\*\*\*|$)/g;
    const unparsedSections = [];
    let match;

    while ((match = sectionPattern.exec(normalizedMarkdown)) !== null) {
        const sectionTitle = match[1].trim();
        const sectionContent = match[2].trim();

        // Check if this section was parsed
        const wasParsed = parsedSectionTitles.some(title =>
            sectionTitle.toLowerCase().includes(title.toLowerCase()) ||
            title.toLowerCase().includes(sectionTitle.toLowerCase())
        );

        if (!wasParsed && sectionContent && !sectionContent.includes('No information is known')) {
            unparsedSections.push(`## ${sectionTitle}\n${sectionContent}`);
        }
    }

    if (unparsedSections.length > 0) {
        parsedData.unparsedContent = unparsedSections.join('\n\n');
        console.log(`Found ${unparsedSections.length} unparsed section(s)`);
    }

    console.log('Parsing complete:', parsedData);
    return parsedData;
}

/**
 * Generate markdown from parsed data structure
 * Reconstructs the wiki entry from form field data
 * @param {object} data - Parsed data structure with all form fields
 * @returns {string} - Markdown text that can be saved back to wiki
 */
function generateWikiMarkdown(data) {
    const lines = [];

    // Header with program name, years, and location
    if (data.programName) {
        const yearsActive = data.yearsActive ? ` (${data.yearsActive})` : '';
        const cityState = data.cityState ? ` ${data.cityState}` : '';
        lines.push(`## **${data.programName}**${yearsActive}${cityState}\n`);
    }

    // Program type
    if (data.programType) {
        lines.push(`*${data.programType}*\n`);
    }

    // History and Background Information
    if (
        data.yearFounded || data.ownerName || data.ageRange || 
        data.selectedDiagnoses.length > 0 || data.customDiagnoses ||
        data.capacity || data.campusSize || data.avgStay || data.tuition ||
        data.natsapMember || data.mainAddress || data.accreditingBody ||
        data.ownershipChanges.length > 0
    ) {
        lines.push('## History and Background Information\n');

        // Build history paragraph
        let historyText = '';

        if (data.yearFounded) {
            historyText += `Founded in ${data.yearFounded}. `;
        }

        if (data.ownerName) {
            if (data.ownerLink) {
                historyText += `Is a [${data.ownerName}](${data.ownerLink}) program. `;
            } else {
                historyText += `Owned/operated by ${data.ownerName}. `;
            }
        }

        if (data.ownershipChanges.length > 0) {
            data.ownershipChanges.forEach(change => {
                if (change.previous && change.previousLink) {
                    historyText += `Prior to being purchased by ${change.newOwner} in ${change.year}, was owned by [${change.previous}](${change.previousLink}). `;
                } else if (change.previous) {
                    historyText += `Prior to being purchased by ${change.newOwner} in ${change.year}, was owned by ${change.previous}. `;
                } else if (change.newOwnerLink) {
                    historyText += `In ${change.year}, was purchased by [${change.newOwner}](${change.newOwnerLink}). `;
                } else {
                    historyText += `In ${change.year}, was purchased by ${change.newOwner}. `;
                }
            });
        }

        if (data.ageRange) {
            historyText += `Serves ages ${data.ageRange}. `;
        }

        // Generate advertised student profile
        if (data.selectedDiagnoses.length > 0 || data.customDiagnoses) {
            const allProfileItems = [...data.selectedDiagnoses];
            
            // Add custom profile items
            if (data.customDiagnoses) {
                const customItems = data.customDiagnoses.split(',').map(d => d.trim()).filter(Boolean);
                allProfileItems.push(...customItems);
            }
            
            if (allProfileItems.length > 0) {
                historyText += `Marketed for students who struggle with a variety of challenges such as ${allProfileItems.join(', ')}. `;
            }
        }

        if (data.capacity) {
            historyText += `Maximum enrollment: ${data.capacity}. `;
        }

        if (data.campusSize) {
            historyText += `Campus size: ${data.campusSize}. `;
        }

        if (data.avgStay) {
            historyText += `Average length of stay: ${data.avgStay}. `;
        }

        if (data.tuition) {
            historyText += `Tuition: ${data.tuition}. `;
        }

        if (data.natsapMember) {
            if (data.natsapMember === 'yes' && data.natsapYear) {
                historyText += `NATSAP member since ${data.natsapYear}. `;
            } else if (data.natsapMember === 'yes') {
                historyText += `Is a current NATSAP member. `;
            } else if (data.natsapMember === 'former') {
                historyText += `Is a former NATSAP member. `;
            }
        }

        if (data.mainAddress) {
            if (data.addressLink) {
                historyText += `Located at [${data.mainAddress}](${data.addressLink}). `;
            } else {
                historyText += `Located at ${data.mainAddress}. `;
            }
        }

        if (data.accreditingBody) {
            if (data.accreditingBodyLink) {
                historyText += `Accredited through the [${data.accreditingBody}](${data.accreditingBodyLink}). `;
            } else {
                historyText += `Accredited through ${data.accreditingBody}. `;
            }
        }

        if (historyText) {
            lines.push(historyText.trim() + '\n');
        }
    }

    // Staff section
    if (data.staffMembers && data.staffMembers.length > 0) {
        lines.push('## Founders and Notable Staff\n');
        data.staffMembers.forEach(staff => {
            let staffLine = `**${staff.name}** `;
            if (staff.role) {
                staffLine += `is ${staff.role}`;
            }
            if (staff.bio) {
                // Ensure bio starts with proper spacing
                let bio = staff.bio.trim();
                // If we have a role, add a period if needed
                if (staff.role && !staffLine.endsWith('.')) {
                    staffLine += '.';
                }
                // Add bio with space
                if (staff.role) {
                    staffLine += ` ${bio}`;
                } else {
                    // If no role, bio might start with "is" or other verb
                    staffLine += bio;
                }
            }
            lines.push(staffLine);
        });
        lines.push('');
    }

    // Program Structure section
    if (data.programLevels && data.programLevels.length > 0) {
        lines.push('## Program Structure\n');

        if (data.levelSystemDesc) {
            lines.push(`The program ${data.levelSystemDesc}.\n`);
        }

        data.programLevels.forEach(level => {
            // Output format: - **Level Name:** Description
            lines.push(`- **${level.name}:** ${level.description}`);
        });
        lines.push('');
    }

    // Punishments section (if separate) or add to Program Structure
    if (data.punishments && data.punishments.length > 0) {
        if (!data.programLevels || data.programLevels.length === 0) {
            lines.push('## Punishments\n');
        } else {
            // Add punishments to existing Program Structure section
            lines.push('');  // Add spacing
        }
        data.punishments.forEach(punishment => {
            lines.push(`There is also a level called **${punishment.name}** ${punishment.description}`);
        });
        lines.push('');
    }

    // Rules section
    if (data.rules && data.rules.length > 0) {
        lines.push('## Rules\n');
        data.rules.forEach(rule => {
            lines.push(`- ${rule}`);
        });
        lines.push('');
    }

    // Allegations and Lawsuits
    if (
        data.mainComplaints || data.selectedAllegations.length > 0 || 
        data.customAllegations || data.lawsuits.length > 0
    ) {
        lines.push('## Abuse/Neglect Allegations and Lawsuits\n');

        if (data.mainComplaints) {
            lines.push(`Main complaints are of ${data.mainComplaints}.\n`);
        }

        // Generate allegations list from selected common + custom
        if (data.selectedAllegations.length > 0 || data.customAllegations) {
            const allAllegations = [...data.selectedAllegations];
            
            // Add custom allegations
            if (data.customAllegations) {
                const customItems = data.customAllegations.split(',').map(a => a.trim()).filter(Boolean);
                allAllegations.push(...customItems);
            }
            
            if (allAllegations.length > 0) {
                lines.push('Allegations of abuse and neglect that have been reported by survivors include ' + 
                    allAllegations.join(', ') + '.\n');
            }
        }

        // Add lawsuits/incidents
        if (data.lawsuits && data.lawsuits.length > 0) {
            data.lawsuits.forEach(lawsuit => {
                // Extract year from name if it starts with a year
                const yearMatch = lawsuit.name.match(/^(\d{4})/);
                if (yearMatch) {
                    lines.push(`In ${yearMatch[1]}, ${lawsuit.description}`);
                } else {
                    lines.push(`**${lawsuit.name}**: ${lawsuit.description}`);
                }
                lines.push('');
            });
        }
    }

    // Media & News
    if (data.newsArticles && data.newsArticles.length > 0) {
        lines.push('## In the Media & News\n');
        data.newsArticles.forEach(article => {
            lines.push(`- [${article.title}](${article.url})`);
        });
        lines.push('');
    }

    // Testimonies
    if (data.testimonies && data.testimonies.length > 0) {
        lines.push('## Survivor/Parent Testimonials\n');
        data.testimonies.forEach(testimony => {
            let testimonyLine = '';
            if (testimony.date && testimony.type) {
                testimonyLine = `**${testimony.date}: (${testimony.type})**`;
            }
            if (testimony.quote) {
                testimonyLine += ` "${testimony.quote}"`;
            }
            if (testimony.source) {
                if (testimony.url) {
                    testimonyLine += ` – [${testimony.source}](${testimony.url})`;
                } else {
                    testimonyLine += ` – ${testimony.source}`;
                }
            }
            if (testimonyLine) {
                lines.push(testimonyLine);
            }
        });
        lines.push('');
    }

    // Related Media
    if (data.relatedMedia && data.relatedMedia.length > 0) {
        lines.push('## Related Media\n');
        data.relatedMedia.forEach(media => {
            let mediaLine = `[${media.title}](${media.url})`;
            if (media.source && media.date) {
                mediaLine += ` (${media.source}, ${media.date})`;
            } else if (media.source) {
                mediaLine += ` (${media.source})`;
            }
            lines.push(mediaLine);
        });
        lines.push('');
    }

    // Unparsed content (preserve anything we couldn't parse)
    if (data.unparsedContent) {
        lines.push(data.unparsedContent);
        lines.push('');
    }

    return lines.join('\n').trim();
}

/**
 * Get lookup tables for form field autocomplete/dropdown suggestions
 * @returns {object} - Object containing all lookup tables
 */
function getLookupTables() {
    return {
        clinicalDiagnoses: [
            'ADHD', 'ADD', 'Attention Deficit Hyperactivity Disorder',
            'Autism Spectrum Disorder', 'ASD', 'Asperger Syndrome',
            'Bipolar Disorder', 'Bipolar I', 'Bipolar II',
            'Major Depression', 'Depressive Disorder', 'Depression',
            'Generalized Anxiety Disorder', 'Anxiety Disorder', 'Anxiety',
            'Obsessive-Compulsive Disorder', 'OCD',
            'Post-Traumatic Stress Disorder', 'PTSD', 'Trauma',
            'Attachment Disorder', 'Reactive Attachment Disorder', 'RAD',
            'Mood Disorder', 'Mood Disturbance',
            'Conduct Disorder', 'Oppositional Defiant Disorder', 'ODD',
            'Learning Disorder', 'Learning Disability', 'Learning Disabilities',
            'Dyslexia', 'Non-Verbal Learning Disorder', 'NVLD',
            'Borderline Personality Disorder', 'BPD',
            'Schizoaffective Disorder', 'Schizophrenia', 'Psychosis',
            'Eating Disorder', 'Anorexia Nervosa', 'Anorexia', 'Bulimia Nervosa', 'Bulimia',
            'Substance Use Disorder', 'Substance Abuse', 'Drug Abuse', 'Alcohol Abuse',
            'Behavioral Addiction', 'Internet Addiction', 'Gaming Addiction',
            'Self-Harm', 'Self-Injurious Behavior', 'Cutting',
            'Suicidal Ideation', 'Suicidality', 'Suicide Risk',
            'Fetal Alcohol Spectrum Disorder', 'FAS', 'Fetal Alcohol Effects',
            'Sensory Processing Disorder', 'SPD',
            'Oppositional Behavior', 'Defiance', 'Defiant Behavior'
        ],
        behavioralIssues: [
            'Defiance', 'Oppositional behavior', 'Rule-breaking', 'Law-breaking',
            'Aggression', 'Violent behavior', 'Anger management issues',
            'Depression', 'Depressed mood', 'Sadness', 'Hopelessness',
            'Anxiety', 'Anxious behavior', 'Social anxiety', 'Panic',
            'Self-harm', 'Cutting', 'Self-injurious behavior', 'Suicidal thoughts',
            'Substance abuse', 'Drug use', 'Alcohol use', 'Smoking',
            'Eating disorders', 'Food restriction', 'Binge eating',
            'Running away', 'Elopement', 'Truancy', 'School refusal',
            'Academic struggles', 'Failing grades', 'School failure',
            'Social problems', 'Peer conflict', 'Bullying', 'Social isolation',
            'Family conflict', 'Parent-child conflict', 'Family dysfunction',
            'Sexual acting out', 'Inappropriate sexual behavior', 'Sexually inappropriate behavior',
            'Lying', 'Dishonesty', 'Manipulation', 'Deceit',
            'Stealing', 'Theft', 'Stealing behavior',
            'Emotional dysregulation', 'Emotional instability', 'Mood swings',
            'Disrespect', 'Disrespectful behavior', 'Poor attitude',
            'Motivation issues', 'Lack of motivation', 'Apathy',
            'Attachment issues', 'Attachment', 'Inability to bond', 'Trust issues',
            'Blame', 'Blaming others', 'Lack of accountability',
            'Impulsive behavior', 'Impulsivity', 'Poor impulse control',
            'Adoption issues', 'Adoption', 'Adopted child issues',
            'Trafficking', 'Sex trafficking', 'Human trafficking', 'Trafficking victim'
        ],
        commonPhaseStructures: [
            'Entry/Foundation/Intermediate/Advanced',
            'Foundation/Intermediate/Advanced/Leadership',
            'Level 1/2/3/4',
            'Phase 1/2/3/4',
            'Bronze/Silver/Gold/Platinum',
            'White/Green/Blue/Black',
            'Stage 1/2/3/4',
            'Beginner/Intermediate/Advanced/Expert',
            'Green/Yellow/Orange/Red/Black',
            'Points-based system',
            'Rank-based system',
            'Privilege-based system',
            'Tier 1/2/3/4/5',
            'Step 1/2/3/4'
        ],
        commonComplaints: [
            'Physical abuse', 'Corporal punishment', 'Excessive punishment',
            'Sexual abuse', 'Sexual exploitation', 'Sexual misconduct',
            'Emotional abuse', 'Psychological abuse', 'Verbal abuse', 'Emotional/verbal abuse',
            'Neglect', 'Medical neglect', 'Failure to treat illness',
            'Overmedication', 'Inappropriate medication', 'Medication abuse',
            'Restraint abuse', 'Excessive restraints', 'Violent restraints', 'Violent and excessive restraints',
            'Solitary confinement', 'Isolation', 'Lock-down',
            'Sleep deprivation', 'Forced sleep deprivation',
            'Food deprivation', 'Withholding food', 'Starvation',
            'Humiliation', 'Degradation', 'Public shaming', 'Humiliation tactics',
            'Intimidation', 'Threats', 'Coercion', 'Fear-based practices',
            'Withholding medical care', 'Refusing treatment',
            'Inadequate supervision', 'Lack of supervision', 'Improper supervision', 'Improper supervision of students',
            'Untrained staff', 'Unqualified staff', 'Inadequate staff training', 'Undertrained/unqualified staff',
            'Fraudulent marketing', 'Deceptive advertising', 'False claims', 'Deceptive/fraudulent marketing practices',
            'Unlawful detention', 'False imprisonment',
            'Human rights violations', 'Civil rights violations',
            'Illegal practices', 'Non-compliant practices',
            'Unsanitary conditions', 'Unsafe facilities',
            'Failure to follow mandated reporting',
            'Educational neglect', 'Lack of education',
            'Lack of mental health services', 'Inadequate therapy',
            'High staff turnover', 'Lack of continuity of care',
            'Punitive punishments'
        ]
    };
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { parseWikiMarkdown, generateWikiMarkdown, getLookupTables, findLookupMatches, sanitizeUrl };
} else if (typeof window !== 'undefined') {
    // Expose to global scope for browser usage
    window.parseWikiMarkdown = parseWikiMarkdown;
    window.generateWikiMarkdown = generateWikiMarkdown;
    window.getLookupTables = getLookupTables;
    window.findLookupMatches = findLookupMatches;
    window.sanitizeUrl = sanitizeUrl;
}
