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

function isIgnoredWikiMetaLink(title, url, lineText = '') {
    const normalizedTitle = String(title || '').trim().toLowerCase();
    const normalizedUrl = sanitizeUrl(url).toLowerCase();
    const normalizedLine = String(lineText || '').trim().toLowerCase();

    if (!normalizedUrl) return false;

    // Any Reddit user/profile link is a meta-link, not real content.
    const isUserLink = /\/(?:u|user)\/[^/]+\/?$/.test(normalizedUrl)
        || normalizedUrl.startsWith('https://www.reddit.com/user/')
        || normalizedUrl.startsWith('https://reddit.com/user/');

    if (!isUserLink) return false;

    // When the surrounding line is editorial boilerplate, always ignore.
    if (normalizedLine.includes('last revised by')
        || normalizedLine.includes('please contact')
        || normalizedLine.includes('submitted directly to wiki')
        || normalizedLine.includes('have been added yet')
        || normalizedLine.includes('to share, please')) {
        return true;
    }

    // A link whose label looks like a username (u/Handle) is also meta.
    return /^u\//.test(normalizedTitle);
}

function escapeRegExp(text) {
    return String(text || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function maskMarkdownLinks(text) {
    const links = [];
    const maskedText = String(text || '').replace(/\[[^\]]+\]\([^\)]+\)/g, (match) => {
        const token = `__MD_LINK_${links.length}__`;
        links.push(match);
        return token;
    });

    return { maskedText, links };
}

function unmaskMarkdownLinks(text, links) {
    return String(text || '').replace(/__MD_LINK_(\d+)__/g, (_, index) => links[Number(index)] || '');
}

function getFirstSentence(text) {
    const { maskedText, links } = maskMarkdownLinks(text);
    const match = maskedText.match(/^(.+?[.!?])(?:\s|$)/);
    return unmaskMarkdownLinks(match ? match[1] : maskedText, links).trim();
}

function removeFirstSentence(text) {
    const firstSentence = getFirstSentence(text);
    if (!firstSentence) return String(text || '').trim();

    const source = String(text || '');
    if (source.startsWith(firstSentence)) {
        return source.slice(firstSentence.length).trim();
    }

    return source.trim();
}

function cleanStaffRoleCandidate(candidate, programName) {
    let cleaned = String(candidate || '').trim();
    if (!cleaned) return '';

    cleaned = cleaned
        .replace(/^the\s+/i, '')
        .replace(/\s*,?\s+(?:from|until|since|beginning|starting|between)\b[\s\S]*$/i, '')
        .trim();

    if (programName) {
        const escapedProgramName = escapeRegExp(programName);
        cleaned = cleaned
            .replace(new RegExp(`\\s+(?:of|at)\\s+${escapedProgramName}\\b.*$`, 'i'), '')
            .trim();
    }

    cleaned = cleaned.replace(/\s+/g, ' ').trim();

    if (/^(?:married|wife|husband|mother|father|daughter|son|sister|brother|partner)\b/i.test(cleaned)) {
        return '';
    }

    return cleaned;
}

function extractStaffPreviousRoles(bioText) {
    const { maskedText, links } = maskMarkdownLinks(bioText);
    const previousRoles = [];
    const seen = new Set();
    const addPreviousRole = (role = '', employer = '') => {
        const entry = {
            role: unmaskMarkdownLinks(role, links).trim(),
            employer: unmaskMarkdownLinks(employer, links).trim()
        };

        if (!entry.role && !entry.employer) return;

        const key = `${entry.role}||${entry.employer}`.toLowerCase();
        if (seen.has(key)) return;
        seen.add(key);
        previousRoles.push(entry);
    };

    const patterns = [
        {
            pattern: /(?:prior|previous|formerly|like\s+(?:his|her)\s+(?:husband|wife))[^.]*?\b(?:worked|served|was employed)\s+as\s+(.+?)(?=(?:\s+(?:at|for|with|from|until|before|after|when|since)\b|[.;]|$))/gi,
            target: 'role'
        },
        {
            pattern: /(?:prior|previous|formerly|like\s+(?:his|her)\s+(?:husband|wife))[^.]*?\b(?:worked|served|was employed)\s+at\s+(.+?)(?=(?:\s+(?:from|until|before|after|when|since|in)\b|[.;]|$))/gi,
            target: 'employer'
        },
        {
            pattern: /(?:prior|previous|formerly|like\s+(?:his|her)\s+(?:husband|wife))[^.]*?\b(?:worked|served|was employed)\s+for\s+(.+?)(?=(?:\s+(?:from|until|before|after|when|since|in)\b|[.;]|$))/gi,
            target: 'employer'
        }
    ];

    patterns.forEach(({ pattern, target }) => {
        for (const match of maskedText.matchAll(pattern)) {
            const value = (match[1] || '').trim();
            if (!value) continue;
            if (target === 'role') {
                addPreviousRole(value, '');
            } else {
                addPreviousRole('', value);
            }
        }
    });

    return previousRoles;
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
        entryType: '',
        programName: '',
        yearsActive: '',
        cityState: '',
        programType: '',
        yearFounded: '',
        headquarters: '',
        parentCompany: '',
        parentCompanyLink: '',
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

        // Round-trip flags: set true when a section is imported verbatim into the
        // matching *Misc text. The generator substitutes (not appends) that text so
        // the re-derived structured prose isn't emitted alongside it (duplication).
        // The browser editor recomputes these from DOM edit state; setting them here
        // keeps headless paths (batch import, regenerate-from-record) correct too.
        historyNotesIsImported: false,
        staffMiscIsImported: false,
        structureMiscIsImported: false,
        lawsuitsMiscIsImported: false,
        testimoniesMiscIsImported: false,
        relatedMediaMiscIsImported: false,
        staffMisc: '',
        
        // Affiliations and History
        affiliations: [],
        rebrand: '',
        rebrandLink: '',
        relatedPrograms: [], // Programs listed in tables (e.g. WWASP affiliates)

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

    // Check for placeholder text indicating empty/uncreated page
    if (normalizedMarkdown.trim() === 'Reddit Wiki Entry Page has not yet been created.' || 
        normalizedMarkdown.includes('Reddit Wiki Entry Page has not yet been created.')) {
        parsedData.isEmpty = true;
    }

    // Remove specific boilerplate text as requested by user
    normalizedMarkdown = normalizedMarkdown.replace(/\nthis text should always be removed\s*/g, '');
    normalizedMarkdown = normalizedMarkdown.replace(/SaveCancelLast revised by \[(?:shroomskillet|Signal-Strain9810)\]\(\/(?:user|u)\/(?:shroomskillet|Signal-Strain9810)\/?\)## Page titleSaveCancel/gi, '');
    normalizedMarkdown = normalizedMarkdown.replace(/SaveCancel/g, '');
    normalizedMarkdown = normalizedMarkdown.replace(/\nLast revised by \[(?:shroomskillet|Signal-Strain9810)\]\(\/(?:user|u)\/(?:shroomskillet|Signal-Strain9810)\/?\)(?:## Page title)?/gi, '');
    // Normalize user signature
    normalizedMarkdown = normalizedMarkdown.replace(/- \[shroomskillet\]\(\/user\/shroomskillet\/\)/g, '- [Signal-Strain9810](/user/Signal-Strain9810/)');
    normalizedMarkdown = normalizedMarkdown.replace(/^\s*Last revised by \[(?:shroomskillet|Signal-Strain9810)\]\(\/(?:user|u)\/(?:shroomskillet|Signal-Strain9810)\/?\)\s*$/gim, '');
    // Generic Reddit wiki footer/editor chrome, regardless of which username
    // appears (pages exist signed by rjm2013 and others, and some carry a bare
    // "Last revised by" with no link at all). Also drop the "## Page title"
    // header the wiki editor appends after the footer.
    normalizedMarkdown = normalizedMarkdown.replace(/^\s*Last revised by\s*(?:\[[^\]]*\]\([^)]*\))?\s*$/gim, '');
    normalizedMarkdown = normalizedMarkdown.replace(/^#{1,6}\s*Page title\s*$/gim, '');
    // Unwrap empty markdown links ("[NATSAP]()") left behind by authoring
    // mistakes — keep the label, drop the dead link syntax.
    normalizedMarkdown = normalizedMarkdown.replace(/\[([^\]]+)\]\(\s*\)/g, '$1');


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
        'Personality Disorders', 'Borderline Personality Disorder', 'BPD',
        'Histrionic Personality Disorder', 'Narcissistic Personality Disorder',
        'Schizoaffective Disorder', 'Schizophrenia', 'Psychosis', 'Psychotic Disorders',
        'Eating Disorder', 'Anorexia Nervosa', 'Anorexia', 'Bulimia Nervosa', 'Bulimia',
        'Emotionally Disturbed Youth', 'IEP (Individualized Education Plan)',
        'Substance Use Disorder', 'Substance Abuse', 'Drug Abuse', 'Alcohol Abuse',
        'Drug Addiction', 'Alcoholism', 'Chemical Dependency',
        'Dysthymia', 'Identity Disorder', 'Cognitive Disorder', 'Relational Disorders',
        'Aspergers', 'Asperger Syndrome', 'Reactive Attachment Disorder', 'RAD'
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
        'trafficking', 'sex trafficking', 'human trafficking', 'trafficking victim',
        'low self-esteem', 'negative self-talk', 'identity issues', 'executive functioning issues',
        'failure to launch', 'risky behaviors', 'angry', 'deceitful', 'entitled', 
        'ill-tempered', 'adrift', 'isolated', 'loving and loveable', 'academic underachievement',
        'difficulty with interpersonal relationships', 'sexually reactive behaviors',
        'destruction of property', 'disrespect of authority', 'school avoidance',
        'school problems', 'poor peer choices', 'accountability and responsibility issues',
        'integrity', 'runaway and curfew', 'minimal legal issues',
        'chronic stress', 'inconsistent care from caregivers', 'neurodiverse'
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
        'Step 1/2/3/4',
        'Search & Rescue/The Village/Academic',
        'E&O/Evaluation & Observation',
        'Orientation/Round-Up/Mustang/Maverick/Greenhorn/Rider/Wrangler',
        'Homeless/Discovery/Student/Supervisor/Manager/Director/Graduate/Post-Graduate',
        'Orientation/Pre-team/Team/Advanced Team/Achievement/Advanced Achievement/Senior/Advanced Senior',
        'Trust Stages',
        'Trust Levels',
        'Humility/Accountability/Service/Leadership',
        'Work Crew',
        'E&O',
        'Evaluation & Observation',
        'Trust of Care/Trust of Control/Trust of Self/Interdependence',
        'Spark/Elevate/Launch'
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
        'excessive force': 'physical abuse',
        'violent behavior by staff': 'physical abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "emotional abuse"
        // ═══════════════════════════════════════════════════════════
        'emotional/verbal abuse': 'emotional abuse',

        // Note: "psychological abuse" and "verbal abuse" are their OWN checkboxes
        'gaslighting': 'psychological abuse',
        'brainwashing': 'psychological abuse',
        'mind control': 'psychological abuse',
        'coercive control': 'psychological abuse',
        'simulated death therapy': 'psychological abuse',
        'vague rules': 'psychological abuse',
        'contradictory rules': 'psychological abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "sexual abuse"
        // ═══════════════════════════════════════════════════════════
        'sexual exploitation': 'sexual abuse',
        'sexual misconduct': 'sexual abuse',
        'sexual harassment': 'sexual abuse',
        'grooming': 'sexual abuse',
        'strip searches': 'sexual abuse',
        'person searches': 'sexual abuse',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "medical neglect"
        // ═══════════════════════════════════════════════════════════
        'failure to treat illness': 'medical neglect',
        'denial of medical treatment': 'medical neglect',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "withholding medical care"
        // ═══════════════════════════════════════════════════════════
        'refusing treatment': 'withholding medical care',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "overmedication"
        // ═══════════════════════════════════════════════════════════
        'inappropriate medication': 'overmedication',
        'medication abuse': 'overmedication',
        'forced medication': 'overmedication',
        'sedation by medication': 'overmedication',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "restraint abuse"
        // ═══════════════════════════════════════════════════════════
        'violent restraints': 'restraint abuse',
        'violent and excessive restraints': 'restraint abuse',
        'gooseneck hold': 'restraint abuse',

        // Note: "excessive restraints" is its OWN checkbox

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "solitary confinement"
        // ═══════════════════════════════════════════════════════════
        'lock-down': 'solitary confinement',
        'seclusion': 'solitary confinement',

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
        'restricted diet': 'food deprivation',

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
        'fear tactics': 'intimidation',

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
        'spectrum-like traits': 'Autism Spectrum Disorder',
        'level 1 autism': 'Autism Spectrum Disorder',

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
        'mood disorder': 'Depression',
        'mood disorders': 'Depression',
        'dysthymia': 'Depression',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Anxiety"
        // ═══════════════════════════════════════════════════════════
        'anxiety disorder': 'Anxiety',
        'generalized anxiety disorder': 'Anxiety',
        'gad': 'Anxiety',
        'social anxiety': 'Anxiety',
        'social phobias': 'Anxiety',
        'panic': 'Anxiety',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "OCD"
        // ═══════════════════════════════════════════════════════════
        'obsessive compulsive disorder': 'OCD',
        'obsessive-compulsive disorder': 'OCD',
        'obsessive compulsive tendencies': 'OCD',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "PTSD"
        // ═══════════════════════════════════════════════════════════
        'post traumatic stress disorder': 'PTSD',
        'post-traumatic stress disorder': 'PTSD',
        'trauma': 'PTSD',
        'complex trauma': 'PTSD',
        'c-ptsd': 'PTSD',
        'cptsd': 'PTSD',

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
        'anorexia nervosa': 'Eating Disorder',
        'bulimia nervosa': 'Eating Disorder',
        'eating disorders': 'Eating Disorder',

        // ═══════════════════════════════════════════════════════════
        // → CHECKBOX: "Substance Abuse"
        // ═══════════════════════════════════════════════════════════
        'drug abuse': 'Substance Abuse',
        'addiction': 'Substance Abuse',
        'substance use disorder': 'Substance Abuse',
        'substance experimentation': 'Substance Abuse',
        'chemical dependency': 'Substance Abuse',

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
        'excessive anger': 'aggression',
        'verbal aggression': 'aggression',
        'cutting': 'self-harm',
        'self harm': 'self-harm',
        'self injury': 'self-harm',
        'self-injurious behavior': 'self-harm',
        'suicidal gestures': 'self-harm',
        'elopement': 'running away',
        'runaway': 'running away',
        'leaving home without permission': 'running away',
        'school refusal': 'truancy',
        'skipping school': 'truancy',
        'school struggles': 'academic struggles',
        'academic problems': 'academic struggles',
        'failing grades': 'academic struggles',
        'declining academic performance': 'academic struggles',
        'peer problems': 'social problems',
        'social issues': 'social problems',
        'social isolation': 'social problems',
        'bullying': 'social problems',
        'withdrawal': 'social problems',
        'isolation from family and friends': 'social problems',
        'making and maintaining relationships': 'social problems',
        'being easily influenced by others': 'social problems',
        'family problems': 'family conflict',
        'parent-child conflict': 'family conflict',
        'relationship problems with family': 'family conflict',
        'sexual acting out': 'sexually inappropriate behavior',
        'inappropriate sexual behavior': 'sexually inappropriate behavior',
        'risky sexual activity': 'sexually inappropriate behavior',
        'dishonesty': 'lying',
        'deceit': 'lying',
        'dishonest communication': 'lying',
        'frequent dishonest communication': 'lying',
        'manipulative behavior': 'manipulation',
        'manipulative': 'manipulation',
        'theft': 'stealing',
        'mood swings': 'emotional dysregulation',
        'emotional instability': 'emotional dysregulation',
        'temper tantrums': 'emotional dysregulation',
        'poor emotional regulation': 'emotional dysregulation',
        'impulsivity': 'impulsive behavior',
        'poor impulse control': 'impulsive behavior',
        'instant gratification': 'impulsive behavior',
        'acting out impulsively': 'impulsive behavior',
        'attachment issues': 'attachment',
        'inability to bond': 'attachment',
        'trust issues': 'attachment',
        'adoption issues': 'adoption',
        'adopted child issues': 'adoption',
        'adopted': 'adoption',
        'sex trafficking': 'trafficking',
        'human trafficking': 'trafficking',
        'trafficking victim': 'trafficking',
        'overuse of social media': 'internet addiction',
        'technology issues': 'internet addiction',
        'video game addiction': 'gaming addiction',
        'negative internal dialogue': 'low self-esteem',
        'poor self-esteem': 'low self-esteem',
        'externalizing blame': 'blaming others',
        'school failure': 'academic struggles',
        'academic underachievement': 'academic struggles',
        'academic challenges': 'academic struggles',
        'disrespect of authority': 'defiance',
        'destruction of property': 'aggression',
        'sexually reactive behaviors': 'sexually inappropriate behavior',
        'emotional challenges': 'emotional disorders',
        'behavioral challenges': 'behavioral disorders',
        'school avoidance': 'truancy'
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
        // Handle both "## **Title**" and "##**Title**" (no space between # and *)
        const headerRegex = new RegExp(`(#{1,6})\\s*\\*{0,2}\\s*${escapedTitle}\\s*\\*{0,2}\\s*\\n`, 'i');
        const match = source.match(headerRegex);
        let result = '';
        if (match) {
            // The section ends at the next heading of the SAME or HIGHER level
            // (a "### Photos" subsection stays inside its "## Related Media"
            // parent, while pages that use "###" for their main sections still
            // terminate at the next "###"). Standalone ***/--- separators do NOT
            // terminate a section: index-style pages put one between every
            // block, and a separator-stop truncated those sections to nothing.
            const level = match[1].length;
            const bodyStart = match.index + match[0].length;
            const rest = source.slice(bodyStart);
            const nextHeader = rest.match(new RegExp(`\\n#{1,${level}}(?:[ \\t]|\\*)`));
            result = (nextHeader ? rest.slice(0, nextHeader.index) : rest).trim();
            // Strip standalone *** or --- separators at start/end of captured content
            result = result.replace(/^(?:\*\*\*|---)\s*$|(?:\r\n|\n|\r)?(?:\*\*\*|---)\s*$/gm, '').trim();
        }

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

    // True only when a section is NOTHING BUT the "No information is known"
    // stub. A section that merely contains the phrase mid-content ("No
    // information is known about the level system. However, ...") still holds
    // real content and must be imported, not discarded.
    const isNoInfoSection = (text) => {
        const t = String(text || '').trim();
        if (!t) return true;
        return /^no information is known/i.test(t) && t.length < 120;
    };

    // Parse header
    // 1. Try standard TTI format: ## **Name** (Years) Location
    let headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*\(([^)]+)\)\s+([^\r\n]+)/m);
    
    if (headerMatch) {
        parsedData.programName = headerMatch[1].trim().replace(/:$/, '');
        parsedData.yearsActive = headerMatch[2].trim();
        parsedData.cityState = headerMatch[3].trim();
        // Some pages jam the program type onto the header line as trailing
        // italics: "## **Name**(Years) City, ST*Program Type*". Split it out so
        // the type doesn't pollute the location field.
        const inlineType = parsedData.cityState.match(/^(.*?)\s*\*([^*]+)\*\s*$/);
        if (inlineType) {
            parsedData.cityState = inlineType[1].trim();
            parsedData.programType = inlineType[2].trim();
        }
    } else {
        // 2. Try simpler format: ## **Name** (Years)
        headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*\(([^)]+)\)/m);
        if (headerMatch) {
            parsedData.programName = headerMatch[1].trim().replace(/:$/, '');
            parsedData.yearsActive = headerMatch[2].trim();
        } else if ((headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*\*([^*\n]+?)\*\*\s*[-–—]\s*\*([^*\n]+?)\*/m))) {
            // 2b. Consultant-style header: # **Name**-*Location*
            parsedData.programName = headerMatch[1].trim().replace(/:$/, '');
            parsedData.cityState = headerMatch[2].trim();
        } else {
            // 3. Try simplest format: ## Name
            // We search for the first header that isn't a known section header
            const allHeaders = normalizedMarkdown.matchAll(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*$/gm);
            for (const match of allHeaders) {
                const candidate = match[1].trim().replace(/:$/, '');
                const invalidTitles = [
                    'History', 'Background', 'Staff', 'Founders', 'Program Structure', 
                    'Structure', 'Rules', 'Punishments', 'Media', 'News', 'Testimonies', 
                    'Related Media', 'Links', 'Page title'
                ];
                
                // If it doesn't contain invalid titles and is reasonably short (program name)
                if (!invalidTitles.some(t => candidate.toLowerCase().includes(t.toLowerCase())) && candidate.length < 100) {
                     parsedData.programName = candidate.replace(/\*+/g, ' ').replace(/\s+/g, ' ').trim();
                     break; // Found the first valid-looking program name
                }
            }
        }
    }

    // 4. Stub pages ("Lakeland" followed only by a footer) carry the program
    // name as a bare first line with no header markup at all.
    if (!parsedData.programName) {
        const firstLine = normalizedMarkdown.split('\n').map(l => l.trim()).find(Boolean);
        if (firstLine
            && firstLine.length < 80
            && !/^[#|[\-]/.test(firstLine)
            && !/^(?:\*\*\*|---)/.test(firstLine)
            && !firstLine.includes('.')) {
            parsedData.programName = firstLine.replace(/[*_]/g, '').trim();
        }
    }
    console.log('Header match result:', parsedData.programName);

    // Parse program type — first try the standalone *Type* line
    const typeMatch = normalizedMarkdown.match(/^\s*\*([^*]+)\*\s*$/m);
    if (typeMatch && !parsedData.programType) {
        parsedData.programType = typeMatch[1].trim();
    }

    // A previously GENERATED page can carry unfilled template tokens in its
    // header ("# **Name** ([Years Active]) [City, ST]" / "*[Program Type]*").
    // Re-importing must treat those as empty, not as literal values.
    const isTemplateToken = (v) => /^\[[^\]]*\]$/.test(String(v || '').trim());
    ['programName', 'yearsActive', 'cityState', 'programType'].forEach((key) => {
        if (isTemplateToken(parsedData[key])) parsedData[key] = '';
    });

    // Fallback: extract program type from history text if not found
    if (!parsedData.programType) {
        const typeFromHistory = normalizedMarkdown.match(/marketed as (?:an?|the)\s+([^.]+?)\s+(?:for|program|that|who|serving|located)/i);
        if (typeFromHistory) {
            parsedData.programType = typeFromHistory[1].trim();
        } else {
            const typeFromHistory2 = normalizedMarkdown.match(/(?:is|was)\s+(?:an?|the)\s+(Residential Treatment Center|Therapeutic Boarding School|Wilderness Program|Behavior Modification Program|Behavioral Health Facility|Outdoor Therapeutic Program|Group Home|Boot Camp|Youth Treatment Center|Psychiatric Residential Treatment Facility)/i);
            if (typeFromHistory2) {
                parsedData.programType = typeFromHistory2[1].trim();
            }
        }
    }

    // Parse History section
    const historySection = getSectionAny(normalizedMarkdown, [
        'History and Background Information',
        'History/Background Information',
        'Hisory and Background Information',
        'History and Bakcground Information',
        'Background Information'
    ]);
    if (historySection && !isNoInfoSection(historySection)) {
        const normalizedHistory = historySection.replace(/[\u2013\u2014]/g, '-');
        // Link-inlined copy for keyword-based extractions: "[text](url)" -> "text".
        // A markdown link sitting next to a keyword (e.g. "[NATSAP](...) member")
        // otherwise breaks regexes that expect the keyword and its neighbor to be
        // adjacent. Extractions that need the URL keep using normalizedHistory.
        const historyPlain = normalizedHistory.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1');

        // Year founded. Allow an optional month between the verb and the year so
        // "opened in March of 2007" is captured, rather than falling through to a
        // later unrelated year (e.g. a sub-program "founded in 2014").
        const yearFoundedMatch = historyPlain.match(/(?:founded|opened|started|established|began)\s+(?:in\s+)?(?:(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+(?:of\s+)?)?(\d{4})/i);
        if (yearFoundedMatch) {
            parsedData.yearFounded = yearFoundedMatch[1].trim();
        }

        const headquartersPatterns = [
            /(?:headquarters|main office)\s+(?:is|are)\s+(?:located|based)\s+(?:at|in)\s+(.+?)(?:\.|$)/i,
            /(?:company|organization|group|association)\s+(?:is|was)\s+based\s+in\s+(.+?)(?:\.|$)/i,
            /headquartered\s+in\s+(.+?)(?:\.|$)/i
        ];

        for (const pattern of headquartersPatterns) {
            const match = historyPlain.match(pattern);
            if (match) {
                parsedData.headquarters = match[1].trim().replace(/\s+/g, ' ');
                break;
            }
        }

        // Owner
        const ownerPatternsWithLinks = [
            /is\s+(?:an?|the)?\s*\[([^\]]+)\]\(([^)]+)\)\s+(?:[^.\n]*?(?:program|school|facility|center|behavior))/i,
            // "owned/operated/run by [a subsidiary of] [Name](url)" — captures the
            // linked entity even when an intermediary phrase precedes the link.
            /(?:owned|operated|run)(?:\s+and\s+(?:owned|operated|run))*\s+by\s+(?:a\s+(?:subsidiary|division|part|unit)\s+of\s+)?(?:the\s+)?\[([^\]]+)\]\(([^)]+)\)/i,
            /owned by \[([^\]]+)\]\(([^)]+)\)/i,
            /operated by \[([^\]]+)\]\(([^)]+)\)/i,
            /run by \[([^\]]+)\]\(([^)]+)\)/i,
            /part of \[([^\]]+)\]\(([^)]+)\)/i,
            /was\s+(?:an?|the)?\s*\[([^\]]+)\]\(([^)]+)\)\s+(?:[^.\n]*?(?:program|school|facility|center))/i
        ];

        // Skip an owner match that is negated just before it, e.g.
        // "is not technically owned by [WWASP]" — that's the opposite of ownership.
        const isNegatedBefore = (text, idx) => {
            const before = text.slice(Math.max(0, idx - 20), idx).toLowerCase();
            return /\bnot\b|n't\b|\bnever\b|\bno longer\b/.test(before);
        };

        // "is a [WWASP]-affiliated program" means affiliated, not owned — the
        // linked entity is a modifier, not the operator. Skip those matches.
        const isAffiliationContext = (matchText) => /\b(?:affiliated|associated)\b/i.test(matchText || '');

        let ownerCaptured = false;
        for (const pattern of ownerPatternsWithLinks) {
            const match = normalizedHistory.match(pattern);
            if (match && !isNegatedBefore(normalizedHistory, match.index) && !isAffiliationContext(match[0])) {
                parsedData.ownerName = match[1].trim();
                parsedData.ownerLink = sanitizeUrl(match[2]);
                ownerCaptured = true;
                break;
            }
        }

        const parentCompanyWithLinkMatch = normalizedHistory.match(/(?:subsidiary of|parent company (?:is|was)|division of)\s+\[([^\]]+)\]\(([^)]+)\)/i);
        if (parentCompanyWithLinkMatch) {
            parsedData.parentCompany = parentCompanyWithLinkMatch[1].trim();
            parsedData.parentCompanyLink = sanitizeUrl(parentCompanyWithLinkMatch[2]);
        } else {
            const parentCompanyTextMatch = normalizedHistory.match(/(?:subsidiary of|parent company (?:is|was)|division of)\s+([^.\n\[]+)/i);
            if (parentCompanyTextMatch) {
                parsedData.parentCompany = parentCompanyTextMatch[1].trim();
            }
        }

        if (!ownerCaptured) {
            // Reject captures that are clearly not a name — bare articles/pronouns
            // or sentence fragments (e.g. "founded as a Mormon militaristic").
            const isPlausibleOwnerName = (value) => {
                const text = (value || '').trim();
                if (text.length < 3) return false;
                if (/^(?:a|an|the|its|their|his|her|this|that|part|one)$/i.test(text)) return false;
                if (/\b(?:founded|opened|established|originally|behavior[- ]?modification)\b/i.test(text)) return false;
                return true;
            };
            const ownerTextPatterns = [
                /owned by ([^.\n\[]+)/i,
                /operated by ([^.\n\[]+)/i,
                /run by ([^.\n\[]+)/i,
                /part of ([^.\n\[]+)/i
            ];
            for (const pattern of ownerTextPatterns) {
                const match = normalizedHistory.match(pattern);
                if (match && isPlausibleOwnerName(match[1]) && !isNegatedBefore(normalizedHistory, match.index)) {
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

        // Alternative ownership change patterns — capture all occurrences
        // Pattern: "in YEAR...was purchased/acquired/sold/taken over by [Name](link)"
        const ownerChangeWithLinkPattern = /(?:in\s+(\d{4})[^.]*?(?:was\s+)?(?:purchased|acquired|bought|sold|taken over)\s+(?:by|to)\s+\[([^\]]+)\]\(([^)]+)\))|(?:(?:was\s+)?(?:purchased|acquired|bought|sold|taken over)\s+(?:by|to)\s+\[([^\]]+)\]\(([^)]+)\)[^.]*?in\s+(\d{4}))/gi;
        let ownerChangeMatch;
        while ((ownerChangeMatch = ownerChangeWithLinkPattern.exec(normalizedHistory)) !== null) {
            const year = (ownerChangeMatch[1] || ownerChangeMatch[6] || '').trim();
            const name = (ownerChangeMatch[2] || ownerChangeMatch[4] || '').trim();
            const link = (ownerChangeMatch[3] || ownerChangeMatch[5] || '').trim();
            if (name && !parsedData.ownershipChanges.some(o => o.newOwner === name)) {
                parsedData.ownershipChanges.push({
                    year,
                    previous: '',
                    previousLink: '',
                    newOwner: expandAcronym(name),
                    newOwnerLink: link
                });
            }
        }

        // Pattern: "in YEAR...was purchased/sold/taken over by Name" (no link)
        const ownerChangeTextPattern = /(?:in\s+(\d{4})[^.]*?(?:was\s+)?(?:purchased|acquired|bought|sold|taken over)\s+(?:by|to)\s+(?:the\s+)?([^.,\[\n]+))|(?:(?:was\s+)?(?:purchased|acquired|bought|sold|taken over)\s+(?:by|to)\s+(?:the\s+)?([^.,\[\n]+?)\s+in\s+(\d{4}))/gi;
        while ((ownerChangeMatch = ownerChangeTextPattern.exec(normalizedHistory)) !== null) {
            const year = (ownerChangeMatch[1] || ownerChangeMatch[4] || '').trim();
            const name = (ownerChangeMatch[2] || ownerChangeMatch[3] || '').trim();
            if (name && !parsedData.ownershipChanges.some(o => o.newOwner === expandAcronym(name))) {
                parsedData.ownershipChanges.push({
                    year,
                    previous: '',
                    previousLink: '',
                    newOwner: expandAcronym(name),
                    newOwnerLink: ''
                });
            }
        }

        // Pattern: "became [Name]" or "renamed [Name]" (rebrand-style ownership changes)
        const renamedPattern = /(?:in\s+(\d{4})[^.]*?)?(?:became|was renamed|changed (?:its )?name to)\s+(?:the\s+)?([^.,\[\n]+?)(?:\.|,|\s+in\s+)/gi;
        while ((ownerChangeMatch = renamedPattern.exec(normalizedHistory)) !== null) {
            const year = (ownerChangeMatch[1] || '').trim();
            const name = ownerChangeMatch[2].trim();
            if (name && name.length > 3 && !parsedData.ownershipChanges.some(o => o.newOwner === name)) {
                parsedData.ownershipChanges.push({
                    year,
                    previous: '',
                    previousLink: '',
                    newOwner: name,
                    newOwnerLink: ''
                });
            }
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
            const match = historyPlain.match(pattern);
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
            /marketed (?:as|to)[^\.]*?for[^\.]*?who struggle with\s+([^\.]+?)(?:\.|The program)/i,
            /enroll(?:s)?\s+(?:teens|students|residents|adolescents|youth)\s+(?:with|who have)\s+(?:a\s+)?history\s+of[:\s]+([^\.]+)\./i
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
            /maximum enrollment of ([^.,\n]+?)(?:\.|,|$|\s+(?:and|who|which|that|between|divided|depending|across|spread|spanning)\b)/i,
            /capacity (?:of|for) ([^.,\n]+?)(?:\.|,|$|\s+(?:and|who|which|that|between|divided|depending|across|spread|spanning)\b)/i,
            /(?:can accommodate|accommodates) (?:up to )?([^.,\n]+?)(?:\.|,|$|\s+(?:and|who|which|that|between|divided|depending|across|spread|spanning)\b)/i,
            /serves (?:up to )?([^.,\n]+?)(?:\.|,|$|\s+(?:and|who|which|that|between|divided|depending|across|spread|spanning)\b)/i,
            /enrollment (?:is|was) ([^.,\n]+?)(?:\.|,|$| and)/i
        ];
        for (const pattern of capacityPatterns) {
            const match = historyPlain.match(pattern);
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

        // Average stay — handles "between X and Y months", "X-Y months", "X months"
        const stayBetweenMatch = historyPlain.match(/average length of stay[^0-9]*?((?:between\s+)?\d+\s*(?:and|to|-)\s*\d+\s*(?:days?|weeks?|months?|years?))/i);
        const staySingleMatch = historyPlain.match(/average length of stay[^0-9]*(\d+\s*(?:days?|weeks?|months?|years?)[^,\.\n]*)/i);
        const stayMatch = stayBetweenMatch || staySingleMatch;
        if (stayMatch) {
            parsedData.avgStay = stayMatch[1].trim();
        }

        // Tuition
        // 1. Look for explicit "Unknown" / "Undisclosed"
        const tuitionUnknownMatch = historyPlain.match(/(?:tuition|cost)[^.\n]*?(?:is|was|remains)\s+(?:presently\s+|currently\s+)?(unknown|undisclosed|not listed|not public|not known)/i);
        if (tuitionUnknownMatch) {
            parsedData.tuition = "Unknown"; // Normalize to standard "Unknown"
        } else {
            // 2. Look for currency amount (ensure we don't cross sentence boundaries)
            // Matches $xx,xxx or $xx,xxx.xx
            const tuitionMatch = historyPlain.match(/(?:tuition|cost)[^.$\n]*?(\$[\d,]*\d(?:\.\d{2})?)/i);
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
            const natsapMatch = historyPlain.match(pattern);
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

        // Accrediting body — handle "accredited by/through (the) [Name](url)",
        // tolerating a stray quote after "accredited" (e.g. '"accredited" by ...').
        const accreditMatch = normalizedHistory.match(/accredited["']?\s+(?:by|through)\s+(?:the\s+)?\[([^\]]+)\]\(([^)]+)\)/i);
        if (accreditMatch) {
            parsedData.accreditingBody = accreditMatch[1].trim();
            parsedData.accreditingBodyLink = sanitizeUrl(accreditMatch[2]);
        } else {
            // Fallback for an accreditor named without a link.
            const accreditTextMatch = normalizedHistory.match(/accredited["']?\s+(?:by|through)\s+(?:the\s+)?([^.\n\[]+)/i);
            if (accreditTextMatch) {
                parsedData.accreditingBody = accreditTextMatch[1].trim();
            }
        }

        // Rebrand/Legacy
        const rebrandKeywords = '(?:rebrand of|spin-?off of|formerly known as|previously known as|successor to|reopened\\/rebranded as|integrated into|merged with|merged into|clone of)';
        let rebrandName = '';
        let rebrandLink = '';
        // Optional run of editorial descriptors between the keyword and the name
        // (e.g. "the notoriously abusive ", "the now-closed "). Matching these
        // flexibly keeps the link form working so we don't fall through to the
        // text form and capture raw "[Name](url" fragments.
        const rebrandQualifiers = '(?:(?:the|a|an|notorious(?:ly)?|infamous|disgraced|abusive|confirmedly|formerly|former|now-closed|closed|and|program)\\s+)*';
        const rebrandMatch = normalizedHistory.match(new RegExp(rebrandKeywords + '\\s+' + rebrandQualifiers + '\\[([^\\]]+)\\]\\(([^)]+)\\)', 'i'));
        if (rebrandMatch) {
            rebrandName = rebrandMatch[1].trim();
            rebrandLink = sanitizeUrl(rebrandMatch[2]);
        } else {
             // Unlinked name: capture until the next clause boundary (period,
             // comma, "[", closing paren, or a connective). Excluding "[" stops
             // it from swallowing a malformed markdown link.
             const rebrandTextMatch = normalizedHistory.match(new RegExp(rebrandKeywords + '\\s+' + rebrandQualifiers + '([^.)\\[]+?)(?:\\)|,|\\s+(?:was|is|which|except|but)\\b|\\.|$)', 'i'));
             if (rebrandTextMatch) {
                 rebrandName = rebrandTextMatch[1].trim().replace(/[,\s]+$/, '');
             }
        }

        // A program can't be a rebrand of itself. Drop self-references, which
        // arise when another program is described as a clone/rebrand of THIS one
        // (e.g. "[Equinox], which is a clone of Solstice" on the Solstice page).
        const progCore = (parsedData.programName || '').toLowerCase()
            .replace(/\b(rtc|academy|school|programs?|hospital|center|inc\.?|llc|ranch)\b/g, '').trim();
        const rebrandCore = rebrandName.toLowerCase();
        const isSelfReference = progCore.length > 2 && rebrandCore &&
            (rebrandCore.includes(progCore) || progCore.includes(rebrandCore));
        if (rebrandName && !isSelfReference) {
            parsedData.rebrand = rebrandName;
            if (rebrandLink) parsedData.rebrandLink = rebrandLink;
        }

        // Affiliations
        const affiliationMatches = normalizedHistory.matchAll(/(?:affiliated with|partnered with)\s+\[([^\]]+)\]\(([^)]+)\)/gi);
        for (const match of affiliationMatches) {
            parsedData.affiliations.push({
                name: match[1].trim(),
                link: sanitizeUrl(match[2])
            });
        }
        // Text affiliations (fallback)
        if (parsedData.affiliations.length === 0) {
             const affTextMatch = normalizedHistory.match(/(?:affiliated with|partnered with)\s+([^\.]+)/i);
             if (affTextMatch) {
                 parsedData.affiliations.push({
                     name: affTextMatch[1].trim(),
                     link: ''
                 });
             }
        }

        // Preserve the full history section so it survives the parse→generate round-trip.
        // Structured fields (year, owner, etc.) are still extracted above for form inputs,
        // but historyMisc feeds the generation template and prevents content loss.
        parsedData.historyMisc = historySection;
        parsedData.historyNotesIsImported = true;
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

    if (staffSection && !isNoInfoSection(staffSection)) {
        // Accepts "**Name**", "*** Name:***" (bold-italic with optional colon),
        // and an optional leading bullet. Names never span lines.
        const staffEntryPattern = /(?:^|\n)[ \t]*(?:[-•]\s+)?\*{2,3}\s*([^*\n]+?)\s*:?\s*\*{2,3}[ \t]*(?:\(([^)]*)\)\s*)?([\s\S]*?)(?=\n[ \t]*(?:[-•]\s+)?\*{2,3}\s*[^*\n]+?\s*:?\s*\*{2,3}[ \t]*(?:\([^)]*\)\s*)?(?:is |was |formerly |previously |worked |served |has |co-founded |founded |also |does |currently )|\s*$)/gi;

        for (const blockMatch of staffSection.matchAll(staffEntryPattern)) {
            let name = (blockMatch[1] || '').trim();
            let staffDates = blockMatch[2] ? blockMatch[2].trim() : '';
            const bioText = (blockMatch[3] || '').trim();

            // Split a parenthetical embedded in the bold name itself, e.g.
            // "***Jane Doe (COO 2008-2018):**" -> name + dates.
            const nameParen = name.match(/^(.+?)\s*\(([^)]*)\)$/);
            if (nameParen) {
                name = nameParen[1].trim();
                if (!staffDates) staffDates = nameParen[2].trim();
            }
            name = name.replace(/:$/, '').trim();

            if (!name || !bioText) continue;
            // Skip sub-bullets that are attributes of the previous person, not
            // a new staff member ("***Role at X:**", "***Current Role:**").
            if (/^(?:role(?:s)?\b|current role|previous role|pre-|post-|note[s:]?$|source[s:]?$)/i.test(name)) continue;

            const isFormer = /formerly|former|ex-|passed away|death|no longer/i.test(bioText)
                || /^worked\b/i.test(bioText)       // "worked as..." implies past
                || /^previously\s+worked\b/i.test(bioText)
                || /^served\b/i.test(bioText)       // "served as..." implies past
                || /\d{4}\s*-\s*\d{4}/.test(staffDates); // date range like (1930-2002)

            let role = '';
            const introSentence = getFirstSentence(bioText);
            const rolePatterns = [
                /worked\s+as\s+(.+?)(?:\.|$)/i,
                /worked\s+in\s+(.+?)(?:\.|$)/i,
                /served\s+as\s+(.+?)(?:\.|$)/i,
                /(?:is|was)\s+(.+?)(?:\.|$)/i,
                /formerly\s+(.+?)(?:\.|$)/i
            ];

            for (const pattern of rolePatterns) {
                const roleMatch = introSentence.match(pattern);
                if (roleMatch) {
                    const candidate = cleanStaffRoleCandidate(roleMatch[1], parsedData.programName);
                    if (!candidate) continue;
                    role = candidate;
                    break;
                }
            }

            const previousRoles = extractStaffPreviousRoles(removeFirstSentence(bioText));

            parsedData.staffMembers.push({
                name,
                role,
                bio: bioText,
                previousRoles,
                isFormer
            });
        }

        // Preserve the full staff section verbatim, like the other sections.
        // Staff was the only section rebuilt purely from structured entries, so
        // any bio the entry regex misparsed was silently lost or reworded on
        // round-trip. The generator substitutes this text when imported.
        parsedData.staffMisc = staffSection;
        parsedData.staffMiscIsImported = true;
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

    if (structureSection && !isNoInfoSection(structureSection)) {
        // Extract level system description (first sentence or paragraph mentioning level/phase system)
        const levelDescMatch = structureSection.match(/(?:uses|used|utilizes|utilized|implements|implemented)\s+([^\.]*(?:level|phase|tier|point)[^\.]+)/i);
        if (levelDescMatch) {
            parsedData.levelSystemDesc = levelDescMatch[1].trim();
        }

        // Parse levels/phases/stages
        // Matches bullet or non-bullet formats:
        //   - **Level Name:** Description
        //   ***Level Name:***description*          (bold+italic, seen in Reddit wikis)
        //   **Level Name** — Description
        const levelMatches = structureSection.matchAll(/(?:^|\n)\s*(?:-\s+)?\*{2,3}\s*([^*:]+?)\s*(?::?\s*)\*{2,3}\s*([\s\S]*?)(?=\n\s*(?:-\s+)?\*{2,3}[^*]|\n\nAs stated|\n\nThere is also|\n##|\n\*\*\*\s*$|$)/g);
        
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
        parsedData.structureMiscIsImported = true;
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
        'Closure',
        'Closure and Rebranding',
        'The Death of Taylor Goodridge', // Specific significant events
        'Abuse, Deaths, and Investigations',
        'Abuse and Investigations',
        'Abuse and Death',
        'Abuse and Lawsuits',
        'Abuse, Rebrands, and the Fire',
        'Abuse Allegations and Red Flags',
        'WWASP Program Red Flags',
        'Red Flags',
        'Abuse',
        'Abuse and Violence',
        'Controversy, Abuse, and Deaths',
        'Abuse/Neglect Allegations',
        'Abuse and Neglect Allegations',
        'Controversies'
    ]);

    if (abuseSection && !isNoInfoSection(abuseSection)) {
        // Store the FULL abuse section text for preservation
        parsedData.lawsuitsMisc = abuseSection;
        parsedData.lawsuitsMiscIsImported = true;

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

            // Remove leading bullet points and optional quotes
            const cleanText = trimmed.replace(/^[*•"'-]\s*"?/, '').replace(/"$/, '').trim();

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

    if (rulesSection && !isNoInfoSection(rulesSection)) {
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
            // Match patterns like:
            //   **Name:** description          (colon inside bold)
            //   **Name** - description          (dash after bold)
            //   ***Name:*** description         (bold+italic)
            //   ** Name:**  description         (space inside bold)
            //   - **Name:** description         (with bullet)
            const punishmentMatch = trimmed.match(
                /^(?:[*•-]\s*)?\*{2,3}\s*([^*:]+?)\s*(?::|–|-)\s*\*{2,3}\s*(.+)$/m
            ) || trimmed.match(
                /^(?:[*•-]\s*)?\*{2,3}\s*([^*]+?)\s*\*{2,3}\s*(?::|–|[-])\s*(.+)$/m
            );
            if (punishmentMatch) {
                parsedData.punishments.push({
                    name: punishmentMatch[1].trim(),
                    description: punishmentMatch[2].trim()
                });
            }
        });

        // Preserve full section text to prevent content loss during round-trip
        parsedData.punishmentsMisc = rulesSection;
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

    if (mediaSection && !isNoInfoSection(mediaSection)) {
        // Extract news articles with links [Title](URL)
        const articleMatches = mediaSection.matchAll(/\[([^\]]+)\]\(([^)]+)\)/g);
        for (const match of articleMatches) {
            if (isIgnoredWikiMetaLink(match[1], match[2], mediaSection)) {
                continue;
            }
            parsedData.newsArticles.push({
                title: match[1].trim(),
                url: sanitizeUrl(match[2])
            });
        }

        // Preserve full section text to prevent content loss during round-trip
        parsedData.mediaInfo = mediaSection;
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

    if (testimoniesSection && !isNoInfoSection(testimoniesSection)) {
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

            // Attribution helper: extracts "- [Source](URL)" or "- Source" from end of text
            // Uses GREEDY quote capture so internal dashes don't truncate the quote
            const extractAttribution = (text) => {
                // Match the LAST "- [Source](URL)" or "- Source text" at end of string
                const attrMatch = text.match(/^([\s\S]+)\s+[-–—]\s+(?:\[([^\]]+)\]\(([^)]+)\)|([A-Z][^[(\n]*))$/);
                if (attrMatch) {
                    return {
                        body: attrMatch[1].trim(),
                        source: (attrMatch[2] || attrMatch[4] || '').trim(),
                        url: attrMatch[3] ? sanitizeUrl(attrMatch[3]) : ''
                    };
                }
                return null;
            };

            // Pattern 1: Standard "**Date: (Type)** Quote" (flexible whitespace)
            // Matches: **9/9/2021: (SURVIVOR)** "Quote..." - [Source](URL)
            const stdMatch = trimmed.match(/^\*\*([^:]+?):?\s*\(([^)]+)\)\*\*\s*"?([\s\S]+)"?\s*$/);

            if (stdMatch) {
                const attr = extractAttribution(stdMatch[3]);
                if (attr) {
                    parsedData.testimonies.push({
                        date: stdMatch[1].trim(),
                        type: stdMatch[2].trim(),
                        quote: attr.body.replace(/^"|"$/g, '').trim(),
                        source: attr.source,
                        url: attr.url
                    });
                    return;
                }
            }

            // Pattern 2: "**Date** (Type) Quote" (no colon, maybe no quotes)
            const noColonMatch = trimmed.match(/^\*\*([^*]+)\*\*\s*\(([^)]+)\)\s*"?([\s\S]+)"?\s*$/);

            if (noColonMatch) {
                const attr = extractAttribution(noColonMatch[3]);
                if (attr) {
                    parsedData.testimonies.push({
                        date: noColonMatch[1].trim(),
                        type: noColonMatch[2].trim(),
                        quote: attr.body.replace(/^"|"$/g, '').trim(),
                        source: attr.source,
                        url: attr.url
                    });
                    return;
                }
            }

            // Pattern 3: Simple "Quote" - Source (Fallback)
            // Use greedy capture to avoid truncation at internal dashes
            const simpleMatch = trimmed.match(/^"([\s\S]+)"\s*$/);
            if (simpleMatch) {
                const attr = extractAttribution(simpleMatch[0]);
                if (attr) {
                    parsedData.testimonies.push({
                        date: '',
                        type: '',
                        quote: attr.body.replace(/^"|"$/g, '').trim(),
                        source: attr.source,
                        url: attr.url
                    });
                    return;
                }
            }
            // Pattern 3b: Unquoted text with attribution
            const unquotedAttr = extractAttribution(trimmed);
            if (unquotedAttr && unquotedAttr.body.length > 20) {
                parsedData.testimonies.push({
                    date: '',
                    type: '',
                    quote: unquotedAttr.body.replace(/^"|"$/g, '').trim(),
                    source: unquotedAttr.source,
                    url: unquotedAttr.url
                });
            }
        });

        // Preserve full section text to prevent content loss during round-trip
        parsedData.testimoniesMisc = testimoniesSection;
        parsedData.testimoniesMiscIsImported = true;

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

    // Treat the section as absent when every non-empty line is placeholder
    // boilerplate or a contact user-link (e.g. the Miss_Nobody89 "please contact"
    // sentence that getPlaceholder() emits).
    const relatedMediaLines = (relatedMediaSection || '').split('\n').map(l => l.trim()).filter(Boolean);
    const allPlaceholder = relatedMediaLines.length > 0 && relatedMediaLines.every(l =>
        /no related media links/i.test(l)
        || /please contact/i.test(l)
        || /have been added yet/i.test(l)
        || /\/(?:u|user)\/[^\s)]+/i.test(l)
    );

    if (relatedMediaSection && !isNoInfoSection(relatedMediaSection) && !allPlaceholder) {
        // Split into lines to parse each entry
        const lines = relatedMediaSection.split('\n').filter(line => line.trim());

        // A "Related Media" line that carries a news source AND date (the
        // "[Title](URL) (Source, Date)" pattern) is really press coverage, so
        // route it to the "In the Media" section (newsArticles) rather than
        // Related Media. Plain resource links (the facility website, survivor
        // sites, etc.) stay in Related Media. When anything is promoted we let
        // the generator rebuild both sections from this split structured data —
        // keeping the verbatim section text would re-emit the news under Related
        // Media and duplicate it against In the Media.
        let promotedToNews = false;
        const addItem = (item) => {
            if (item.source && item.date) {
                parsedData.newsArticles.push(item);
                promotedToNews = true;
            } else {
                parsedData.relatedMedia.push(item);
            }
        };

        lines.forEach(line => {
            const trimmed = line.trim();

            // Match pattern: [Title](URL) (Source, Date)
            const withSourceDateMatch = trimmed.match(/\[([^\]]+)\]\(([^)]+)\)\s*\(([^,]+),\s*([^)]+)\)/);
            if (withSourceDateMatch) {
                if (isIgnoredWikiMetaLink(withSourceDateMatch[1], withSourceDateMatch[2], trimmed)) {
                    return;
                }
                addItem({
                    title: withSourceDateMatch[1].trim(),
                    url: sanitizeUrl(withSourceDateMatch[2]),
                    source: withSourceDateMatch[3].trim(),
                    date: withSourceDateMatch[4].trim()
                });
                return;
            }

            // Match pattern: [Title](URL) (annotation) — parenthetical without comma
            // e.g., [Title](URL) (November 2014) or [Title](URL) (*website created by survivor*)
            const withAnnotationMatch = trimmed.match(/\[([^\]]+)\]\(([^)]+)\)\s*\(([^)]+)\)/);
            if (withAnnotationMatch) {
                if (isIgnoredWikiMetaLink(withAnnotationMatch[1], withAnnotationMatch[2], trimmed)) {
                    return;
                }
                const annotation = withAnnotationMatch[3].replace(/^\*|\*$/g, '').trim();
                // Check if annotation looks like a date (contains a year)
                const yearMatch = annotation.match(/\d{4}/);
                addItem({
                    title: withAnnotationMatch[1].trim(),
                    url: sanitizeUrl(withAnnotationMatch[2]),
                    source: yearMatch ? '' : annotation,
                    date: yearMatch ? annotation : ''
                });
                return;
            }

            // Match simple pattern: [Title](URL)
            const simpleMatch = trimmed.match(/\[([^\]]+)\]\(([^)]+)\)/);
            if (simpleMatch) {
                if (isIgnoredWikiMetaLink(simpleMatch[1], simpleMatch[2], trimmed)) {
                    return;
                }
                addItem({
                    title: simpleMatch[1].trim(),
                    url: sanitizeUrl(simpleMatch[2]),
                    source: '',
                    date: ''
                });
            }
        });

        // Always preserve the verbatim section (and substitute it on
        // regenerate): it keeps subsection structure ("### Photos"), item
        // annotations, and dates that a rebuild from structured items would
        // lose. Items promoted to In the Media above still populate the
        // newsArticles FIELDS; the generator suppresses re-rendering any
        // promoted article whose URL already appears in this verbatim text, so
        // nothing is emitted twice.
        parsedData.relatedMediaMisc = relatedMediaSection;
        parsedData.relatedMediaMiscIsImported = true;
    }

    // Parse Campuses/Locations
    const locationSection = getSectionAny(normalizedMarkdown, ['Locations', 'Facilities', 'Campuses']);
    if (locationSection && !isNoInfoSection(locationSection)) {
        // Split by bold state/region headers or just lines
        const lines = locationSection.split('\n');
        let currentRegion = '';
        
        lines.forEach(line => {
            const trimmed = line.trim();
            if (!trimmed) return;

            // Region header e.g. "** California**"
            const regionMatch = trimmed.match(/^\*\*\s*([^*]+?)\s*\*\*$/);
            if (regionMatch) {
                currentRegion = regionMatch[1].trim();
                return;
            }

            // Campus entry e.g. "*Yorba Linda, CA (*1989-1990*)"
            // or "** Elan One**"
            const campusMatch = trimmed.match(/^(?:[*•-]\s*)?(?:\*\*\s*)?([^*]+?)(?:\s*\*\*|$)/);
            if (campusMatch) {
                const name = campusMatch[1].trim();
                if (name && name !== currentRegion) {
                    // Try to extract dates if present in the same line
                    const dateMatch = trimmed.match(/\(([^)]+)\)/);
                    parsedData.campuses.push({
                        name: name,
                        location: currentRegion,
                        yearsActive: dateMatch ? dateMatch[1].trim() : ''
                    });
                }
            }
        });
    }

    // Parse Program Tables. Operator pages split their programs into two tables —
    // "Open <Company> Programs" and "Closed <Company> Programs" — and other pages
    // use "WWASP Programs", "Active/Closed Programs", "Related/Affiliated Programs"
    // etc. Find EVERY such section by pattern (not a fixed company list), parse its
    // table, and tag each program open/closed from its heading so the split
    // round-trips. The accumulated bodies feed the unparsed-overlap check below.

    // Parse one section's first markdown table into program entries.
    const parseProgramTable = (sectionText) => {
        const out = [];
        // Scan only up to the first heading line: a heading marks the next
        // (sub)section, and rows past it belong to a DIFFERENT table whose
        // open/closed status comes from its own heading. Without this cutoff a
        // page-title-level section (whose body spans the whole document) would
        // swallow every table on the page under one status.
        let lines = sectionText.split('\n');
        const boundary = lines.findIndex(l => /^#{1,6}[ \t*]/.test(l.trim()));
        if (boundary !== -1) lines = lines.slice(0, boundary);
        // A row of only dashes/colons/pipes is a markdown alignment separator
        const isSeparatorRow = (t) => t.startsWith('|') && /^[|\s:\-]+$/.test(t) && t.includes('-');
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line.startsWith('|') || isSeparatorRow(line)) continue;
            // Split a table row into cells, dropping ONLY the boundary empties
            // produced by the leading/trailing pipes. Filtering out all empty
            // cells (the old behavior) shifted every column after an empty
            // cell one position left, mis-assigning the row's data.
            const splitRow = (rowText) => {
                const parts = rowText.split('|').map(c => c.trim());
                if (parts.length && parts[0] === '') parts.shift();
                if (parts.length && parts[parts.length - 1] === '') parts.pop();
                return parts;
            };

            // The first non-separator "|" line is the header row. Reddit wiki
            // index tables frequently omit the |---| separator row entirely, so
            // header detection must not depend on it: accept a row whose cells
            // look like column titles (contains a "name" header).
            const headers = splitRow(line).map(h => h.replace(/\*+/g, '').trim().toLowerCase());
            const looksLikeHeader = headers.some(h => h.includes('name'));
            const nextLine = (lines[i + 1] || '').trim();
            if (!looksLikeHeader && !isSeparatorRow(nextLine)) continue;

            const columnMap = {};
            headers.forEach((h, idx) => {
                if (!h) return;
                if (h.includes('name')) columnMap.name = idx;
                else if (h.includes('year') || h.includes('active') || h.includes('date')) columnMap.years = idx;
                else if (h.includes('location')) columnMap.location = idx;
                else if (h.includes('heal') || h.includes('information')) columnMap.heal = idx;
                else if (h.includes('reopened') || h.includes('rebrand') || h.includes('status')) columnMap.reopened = idx;
                else if (h.includes('type')) columnMap.type = idx;
                else if (h.includes('abuse')) columnMap.abuse = idx;
                else if (h.includes('death')) columnMap.deaths = idx;
                else if (h.includes('warning')) columnMap.warning = idx;
            });
            for (let j = i + 1; j < lines.length; j++) {
                const dataLine = lines[j].trim();
                if (!dataLine.startsWith('|')) continue;
                const cells = splitRow(dataLine);

                const nameCell = cells[columnMap.name !== undefined ? columnMap.name : 0] || '';
                const nameMatch = nameCell.match(/\[([^\]]+)\]\(([^)]+)\)/);
                const name = (nameMatch ? nameMatch[1] : nameCell).replace(/\*\*/g, '').trim();
                const link = nameMatch ? sanitizeUrl(nameMatch[2]) : '';
                // A row with just a program name (all other cells empty) is
                // still a valid entry; only skip rows with no real name at all.
                if (!name || /^[-\s]+$/.test(name) || name.includes('---') || name.toLowerCase() === 'program name') continue;

                const entry = {
                    name,
                    link,
                    yearsActive: columnMap.years !== undefined ? (cells[columnMap.years] || '') : '',
                    location: columnMap.location !== undefined ? (cells[columnMap.location] || '') : '',
                    type: columnMap.type !== undefined ? (cells[columnMap.type] || '') : '',
                    reopened: columnMap.reopened !== undefined ? (cells[columnMap.reopened] || '') : ''
                };
                const healCell = columnMap.heal !== undefined ? (cells[columnMap.heal] || '') : '';
                const healLinkCount = (healCell.match(/\]\(/g) || []).length;
                if (healLinkCount > 1) {
                    // Multiple links in one cell — keep the raw cell so none are lost.
                    entry.healInfo = healCell;
                } else if (healCell && healCell.includes('http')) {
                    const healMatch = healCell.match(/\(([^)]+)\)/);
                    if (healMatch) entry.healLink = sanitizeUrl(healMatch[1]);
                } else if (healCell && healCell !== '-' && !/^[-\s]+$/.test(healCell)) {
                    // Plain text in the HEAL column (often a location misfiled by
                    // the source table) — preserve it rather than dropping it.
                    entry.healInfo = healCell;
                }
                if (columnMap.abuse !== undefined) entry.reportedAbuse = cells[columnMap.abuse];
                if (columnMap.deaths !== undefined) entry.reportedDeaths = cells[columnMap.deaths];
                if (columnMap.warning !== undefined) entry.warningLevel = cells[columnMap.warning];

                out.push(entry);
            }
            break; // first table in the section only
        }
        return out;
    };

    // Discover all program-listing headings (anything CONTAINING "Programs" —
    // index pages use "Active Programs in Utah", operator pages "Open X
    // Programs", the watchlist "The Program Watchlist"), excluding prose
    // sections like "Program Structure". Sections without a table are skipped
    // below, so a loose match here is safe.
    const programHeadings = [];
    const headingScan = /^#{1,6}[ \t]*\*{0,2}[ \t]*([^\n]+?)[ \t]*\*{0,2}[ \t]*$/gm;
    let hMatch;
    while ((hMatch = headingScan.exec(normalizedMarkdown)) !== null) {
        const heading = hMatch[1].replace(/\*+/g, ' ').replace(/\s+/g, ' ').trim();
        if (!/\bprograms?\b|\bwatchlist\b/i.test(heading)) continue;
        if (/program\s+structure|program\s+model|level\s+system|daily\s+(?:schedule|program)/i.test(heading)) continue;
        if (!programHeadings.includes(heading)) programHeadings.push(heading);
    }
    if (programHeadings.length === 0) {
        programHeadings.push('Related Programs', 'Affiliated Programs', 'Active Programs', 'Closed Programs');
    }

    // Consume only the TABLE lines of each program section. The surrounding
    // prose ("Essential Information" blocks on state index pages, "Below is a
    // list..." lead-ins) is NOT consumed here, so the unparsed-content salvage
    // below preserves it instead of dropping it.
    let programTableSections = '';
    const seenProgramKeys = new Set();
    for (const heading of programHeadings) {
        const body = getSection(normalizedMarkdown, heading);
        if (!body || !body.includes('|')) continue;
        const tableLines = body.split('\n').filter(l => l.trim().startsWith('|')).join('\n');
        if (tableLines) {
            programTableSections += (programTableSections ? '\n\n' : '') + tableLines;
        }
        const status = /\bclosed\b/i.test(heading)
            ? 'closed'
            : (/\bopen\b|\bactive\b|\bcurrent\b/i.test(heading) ? 'open' : '');
        for (const entry of parseProgramTable(body)) {
            const key = entry.name.toLowerCase() + '|' + (entry.yearsActive || '');
            if (seenProgramKeys.has(key)) continue;
            seenProgramKeys.add(key);
            if (status) entry.status = status;
            parsedData.relatedPrograms.push(entry);
        }
    }


    // Decide which "## ..." sections were actually consumed into a field/array,
    // so anything left over (e.g. a separate "Closure and Rebranding", "Deaths",
    // or "Controversies" section) is preserved in unparsedContent rather than
    // silently dropped. We compare normalized CONTENT against the section bodies
    // captured above. This replaces the old static title allow-list, which marked
    // unhandled-but-listed titles (like "Closure") as "parsed" and dropped them,
    // and which could also drop a real section that merely shared a title keyword
    // with one already consumed elsewhere.
    const normForOverlap = (s) => String(s || '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .toLowerCase()
        .replace(/[*_`#>~]/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
    // locationSection is intentionally NOT consumed: campuses are extracted from
    // it as structured data, but the generator only produces a one-line summary
    // from those, so the section itself must survive via the salvage below.
    const consumedBodies = [
        historySection, staffSection, structureSection, abuseSection, rulesSection,
        mediaSection, testimoniesSection, relatedMediaSection, programTableSections
    ].map(normForOverlap).filter(Boolean);

    // Salvage at the PARAGRAPH level: a section may be only partially consumed
    // (e.g. the table of a program-index section is parsed into relatedPrograms
    // while its surrounding prose is not), so comparing whole sections would
    // either drop the remainder or duplicate the consumed part. A block is
    // "consumed" when it exactly equals, or is contained in, a consumed body.
    // The length floor on the substring case avoids a short distinct block being
    // swallowed just because its few words appear inside a longer consumed body.
    // The containment floor matches the salvage minimum below (12): any block
    // long enough to be salvaged must also be long enough to be recognized as
    // already-consumed, otherwise shortish consumed blocks (e.g. a lone
    // "[Title](url)" line) get re-salvaged as duplicates.
    const isConsumedBlock = (normBlock) => !normBlock || consumedBodies.some(body =>
        body === normBlock || (normBlock.length >= 12 && body.includes(normBlock))
    );

    const salvageBlocks = (bodyText) => {
        const kept = [];
        for (const block of String(bodyText || '').split(/\n{2,}/)) {
            const trimmed = block.trim();
            if (!trimmed) continue;
            if (/^(?:\*{3,}|-{3,})$/.test(trimmed)) continue; // layout separators
            if (/^no information is known/i.test(trimmed) && trimmed.length < 120) continue;
            const normBlock = normForOverlap(trimmed);
            if (normBlock.length < 12) continue; // slugs/fragments
            if (isConsumedBlock(normBlock)) continue;
            kept.push(trimmed);
        }
        return kept;
    };

    const unparsedSections = [];

    // Tokenize the document by heading lines (any level) so every block of
    // content belongs to exactly ONE region: the preamble before the first
    // heading, or the span between one heading and the next. This handles
    // pages whose main sections are "###" as well as "##", without ever
    // double-counting a block.
    const headingTokens = [];
    const headingTokenScan = /^(#{1,6})[ \t]*\*{0,2}[ \t]*([^\n]*?)[ \t]*\*{0,2}[ \t]*$/gm;
    let tokenMatch;
    while ((tokenMatch = headingTokenScan.exec(normalizedMarkdown)) !== null) {
        headingTokens.push({
            level: tokenMatch[1].length,
            title: tokenMatch[2].replace(/\*+/g, ' ').replace(/\s+/g, ' ').trim(),
            start: tokenMatch.index,
            bodyStart: tokenMatch.index + tokenMatch[0].length
        });
    }

    const dropTypeLine = (text) => typeMatch
        ? text.split('\n').filter(line => line.trim() !== typeMatch[0].trim()).join('\n')
        : text;

    const pageTitleNorm = normForOverlap(parsedData.programName);
    const isPageTitleHeading = (token) => {
        if (token.level === 1) return true;
        const tNorm = normForOverlap(token.title);
        return !!pageTitleNorm && tNorm.startsWith(pageTitleNorm);
    };

    const preambleEnd = headingTokens.length > 0 ? headingTokens[0].start : normalizedMarkdown.length;
    const preambleKept = salvageBlocks(dropTypeLine(normalizedMarkdown.slice(0, preambleEnd)));
    if (preambleKept.length > 0) {
        unparsedSections.push(preambleKept.join('\n\n'));
    }

    headingTokens.forEach((token, idx) => {
        const bodyEnd = idx + 1 < headingTokens.length ? headingTokens[idx + 1].start : normalizedMarkdown.length;
        const body = dropTypeLine(normalizedMarkdown.slice(token.bodyStart, bodyEnd));
        const kept = salvageBlocks(body);
        if (kept.length === 0) return;
        if (isPageTitleHeading(token) || !token.title) {
            // Content sitting directly under the page-title header — keep it,
            // but don't re-emit the title as a section heading.
            unparsedSections.push(kept.join('\n\n'));
        } else {
            unparsedSections.push(`${'#'.repeat(Math.max(2, token.level))} ${token.title}\n${kept.join('\n\n')}`);
        }
    });

    if (unparsedSections.length > 0) {
        parsedData.unparsedContent = unparsedSections.join('\n\n');
        console.log(`Found ${unparsedSections.length} unparsed section(s)`);
    }

    console.log('Parsing complete:', parsedData);
    if (!parsedData.entryType) {
        const programNameLower = parsedData.programName.toLowerCase();
        const programTypeLower = parsedData.programType.toLowerCase();
        const hasProgramsTable = parsedData.relatedPrograms.length > 0;
        const hasOrgOnlyFields = !!(parsedData.headquarters || parsedData.parentCompany || parsedData.parentCompanyLink);
        const hasFacilitySignals = !!(
            parsedData.cityState ||
            parsedData.mainAddress ||
            parsedData.ageRange ||
            parsedData.capacity ||
            parsedData.avgStay ||
            parsedData.tuition ||
            parsedData.natsapMember ||
            parsedData.selectedDiagnoses.length > 0
        );
        // "Health" alone (e.g. "Behavioral Health Hospital") is a facility type;
        // only "Healthcare"/"Health Services" reads as a company.
        const nameLooksLikeOrganization = /association|company|corporation|corp\.|group|health(?:care|\s+services)|holdings|institute|network|partners|services|collective|natsap|ieca|wwasp|uhs/.test(programNameLower);
        const typeLooksLikeOrganization = /organization|association|company|corporation|group|operator|network|membership/.test(programTypeLower);

        // A facility that merely names its parent company is still a facility, so
        // org-only fields only imply an organization when there are no facility
        // signals (city, address, age range, capacity, tuition, diagnoses, etc.).
        parsedData.entryType = (typeLooksLikeOrganization || (hasOrgOnlyFields && !hasFacilitySignals) || (hasProgramsTable && !hasFacilitySignals) || (nameLooksLikeOrganization && !hasFacilitySignals))
            ? 'organization'
            : 'facility';
    }

    return parsedData;
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
// NOTE: generateWikiMarkdown intentionally lives only in wiki-generation.js.
// This module previously shipped a second, divergent copy whose output depended
// on script load order; callers must use the wiki-generation.js version so there
// is a single source of truth.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { parseWikiMarkdown, getLookupTables, findLookupMatches, sanitizeUrl };
} else if (typeof window !== 'undefined') {
    // Expose to global scope for browser usage
    window.parseWikiMarkdown = parseWikiMarkdown;
    window.getLookupTables = getLookupTables;
    window.findLookupMatches = findLookupMatches;
    window.sanitizeUrl = sanitizeUrl;
}
