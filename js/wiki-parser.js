/**
 * Wiki Parser Module
 * Parses Reddit-style markdown for troubled teen industry program wiki entries
 * and extracts structured data.
 */

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
        diagnosesList: '',
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
        allegations: [],
        therapies: [],

        // Miscellaneous text sections
        levelSystemDesc: '',
        structureMisc: '',
        punishmentsMisc: '',
        lawsuitsMisc: '',
        rulesList: '',
        mainComplaints: '',
        otherAllegationsList: '',
        mediaInfo: '',           // Full Media & News section content
        testimoniesMisc: '',     // Full Testimonies section content
        relatedMediaMisc: '',    // Full Related Media section content
        unparsedContent: ''      // Capture any content that wasn't matched by specific patterns
    };

    // Normalize newlines so regex parsing works with Windows CRLF input
    const normalizedMarkdown = markdown.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

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
        const headerPattern = `##\\s*\\*{0,2}\\s*${escapedTitle}\\s*\\*{0,2}\\s*`;
        const regex = new RegExp(
            headerPattern + '\\n([\\s\\S]*?)(?=\\n##|\\n\\*\\*\\*|$)',
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
    let headerMatch = normalizedMarkdown.match(/^#{1,3}\s*\*{0,2}(.+?)\*{0,2}\s*\(([^)]+)\)\s+([^\r\n]+)/m);
    console.log('Header match:', headerMatch);
    if (headerMatch) {
        parsedData.programName = headerMatch[1].trim();
        parsedData.yearsActive = headerMatch[2].trim();
        parsedData.cityState = headerMatch[3].trim();
    }

    // Parse program type
    const typeMatch = normalizedMarkdown.match(/^\s*\*([^*]+)\*\s*$/m);
    if (typeMatch) {
        parsedData.programType = typeMatch[1].trim();
    }

    // Parse History section
    const historySection = getSection(normalizedMarkdown, 'History and Background Information');
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

        // Diagnoses
        const diagnosisSnippets = [];
        const diagnosisPatterns = [
            /diagnoses\/behaviors:\s*([^\n]+)/i,
            /any of the following:\s*([^\.]+)\./i,
            /specializes? in treating\s+([^\.]+?)(?:\.|, but)/i,
            /specialized in treating\s+([^\.]+?)(?:\.|, but)/i,
            /treats?\s+(?:students|residents|clients|girls|boys|young people)[^:]*:\s*([^\.]+)\./i,
            /struggling with\s+([^\.]+?)(?:\.|, and more)/i,
            /who are\s+(?:struggling|dealing)\s+with\s+([^\.]+?)(?:\.|, and)/i
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
        if (diagnosisSnippets.length > 0) {
            const uniqueDiagnoses = Array.from(new Set(diagnosisSnippets));
            parsedData.diagnosesList = uniqueDiagnoses.join('; ');
        }

        // Average stay
        const stayMatch = normalizedHistory.match(/average length of stay[^0-9]*(\d+\s*(?:days?|weeks?|months?|years?)[^,\.\n]*)/i);
        if (stayMatch) {
            parsedData.avgStay = stayMatch[1].trim();
        }

        // Tuition
        const tuitionMatch = normalizedHistory.match(/tuition[^$]*(\$[^\.\n]+)/i);
        if (tuitionMatch) {
            parsedData.tuition = tuitionMatch[1].trim();
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
        'Founders'
    ]);

    if (staffSection && !staffSection.includes('No information is known')) {
        const staffBlocks = staffSection.split(/\n(?=\*\*[^*]+\*\*(?:\s+is|\s+was|\s+formerly))/);

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
                /formerly\s+([^.]+?)(?:\s+of|\s+at|\.|$)/i
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
        'Program Phases'
    ]);

    if (structureSection && !structureSection.includes('No information is known')) {
        // Extract level system description (first sentence mentioning level/phase system)
        const levelDescMatch = structureSection.match(/(?:uses|used|utilizes|utilized|implements|implemented)\s+([^\.]*(?:level|phase|tier|point)[^\.]+)/i);
        if (levelDescMatch) {
            parsedData.levelSystemDesc = levelDescMatch[1].trim();
        }

        // Parse levels as complete blocks with structured data extraction
        // Match bullet points starting with **Name** or **Name:**
        const levelBlockRegex = /-\s+\*\*([^*]+?)\*\*\s*:?\s*([^]*?)(?=\n-\s+\*\*|\n\n(?![\s-])|$)/g;
        let match;
        let lastMatchEnd = 0;

        while ((match = levelBlockRegex.exec(structureSection)) !== null) {
            const levelName = match[1].trim();
            const fullBlock = match[2].trim();
            lastMatchEnd = levelBlockRegex.lastIndex;

            // Extract duration (look for "remain on", "minimum of X days", etc.)
            let duration = '';
            const durationPatterns = [
                /remain on [^\.]+for (\d+\s+(?:days?|weeks?|months?)(?:\s+\([^)]+\))?)/i,
                /minimum of (\d+\s+days?)/i,
                /(\d+\s+(?:days?|weeks?|months?)[^,\.]*(consecutively|not consecutive)?)/i
            ];
            for (const pattern of durationPatterns) {
                const durMatch = fullBlock.match(pattern);
                if (durMatch) {
                    duration = durMatch[1].trim();
                    break;
                }
            }

            // Extract privileges (look for lists of what they CAN do)
            let privileges = [];
            const privText = fullBlock;
            const privMatches = privText.matchAll(/(?:allowed to|able to|can|eligible for|permitted to)\s+([^\.]+?)(?=\.|,\s+(?:and\s+)?(?:allowed|able|eligible|permitted)|$)/gi);
            for (const pm of privMatches) {
                const priv = pm[1].trim();
                if (priv && priv.length < 200) {
                    privileges.push(priv);
                }
            }

            // Extract restrictions (look for what they CANNOT do or MUST do)
            let restrictions = [];
            const restrMatches = fullBlock.matchAll(/(?:must|need to|required to|not able to|cannot|not allowed|restricted from)\s+([^\.]+?)(?=\.|,\s+(?:and\s+)?(?:must|need|required|not|cannot)|$)/gi);
            for (const rm of restrMatches) {
                const restr = rm[1].trim();
                if (restr && restr.length < 200) {
                    restrictions.push(restr);
                }
            }

            parsedData.programLevels.push({
                name: levelName,
                duration: duration,
                privileges: privileges.join(', '),
                restrictions: restrictions.join(', '),
                fullDesc: fullBlock  // Keep the complete block for reference
            });
        }

        // Capture any content AFTER the last level (e.g., points system explanation, education details)
        // This is the "additional notes" that don't fit into structured fields
        if (lastMatchEnd > 0 && lastMatchEnd < structureSection.length) {
            const remainingContent = structureSection.substring(lastMatchEnd).trim();
            if (remainingContent) {
                parsedData.structureMisc = remainingContent;
            }
        }
    }

    // Parse Abuse section
    const abuseSection = getSectionAny(normalizedMarkdown, [
        'Abuse/Neglect Allegations and Lawsuits',
        'Abuse Allegations',
        'Abuse/Neglect Allegations',
        'Allegations and Lawsuits',
        'Abuse Allegations and Lawsuits'
    ]);

    if (abuseSection && !abuseSection.includes('No information is known')) {
        // Main complaints
        const complaintsMatch = abuseSection.match(/main complaints are of ([^\.]+)/i);
        if (complaintsMatch) {
            parsedData.mainComplaints = complaintsMatch[1].trim();
        }

        // Allegations - bulleted list
        let allegationsMatch = abuseSection.match(/reported by survivors included:\s*\n((?:[*-][^\n]+\n?)+)/i);
        if (allegationsMatch) {
            const allegationsList = allegationsMatch[1]
                .split('\n')
                .filter(line => {
                    const trimmed = line.trim();
                    return trimmed.startsWith('*') || trimmed.startsWith('-');
                })
                .map(line => line.replace(/^[*-]\s*/, '').trim())
                .filter(Boolean)
                .join('\n');
            parsedData.otherAllegationsList = allegationsList;
        } else {
            // Allegations - comma-separated paragraph
            const paragraphAllegationsMatch = abuseSection.match(/reported by survivors (?:include|included?)[:\s]+([^\.]+)\./i);
            if (paragraphAllegationsMatch) {
                const allegationsText = paragraphAllegationsMatch[1];
                const allegations = allegationsText
                    .split(/,\s*(?:and\s+)?|(?:\s+and\s+)/)
                    .map(a => a.trim())
                    .filter(Boolean);
                if (allegations.length > 0) {
                    parsedData.otherAllegationsList = allegations.join('\n');
                }
            }
        }

        // Lawsuits
        const lawsuitParagraphs = abuseSection.split('\n\n');
        const parsedLawsuitParagraphs = new Set();

        lawsuitParagraphs.forEach(para => {
            const trimmed = para.trim();
            const lawsuitMatch = trimmed.match(/In (\d{4}),\s*([^s]+?)\s+sued[^a]*alleging\s+([^\.]+)\./i);
            if (lawsuitMatch) {
                const year = lawsuitMatch[1].trim();
                const plaintiff = lawsuitMatch[2].trim();
                const allegations = lawsuitMatch[3].trim();

                let outcome = '';
                const outcomeMatch = trimmed.match(/The lawsuit ([^\.]+)\./i);
                if (outcomeMatch) {
                    outcome = outcomeMatch[1].trim();
                }

                let details = '';
                if (outcomeMatch) {
                    const afterOutcome = trimmed.substring(trimmed.indexOf(outcomeMatch[0]) + outcomeMatch[0].length).trim();
                    details = afterOutcome;
                } else {
                    const afterAllegations = trimmed.substring(trimmed.indexOf(lawsuitMatch[0]) + lawsuitMatch[0].length).trim();
                    details = afterAllegations;
                }

                parsedData.lawsuits.push({ year, plaintiff, allegations, outcome, details });
                parsedLawsuitParagraphs.add(trimmed);
            }
        });

        // DON'T store everything in lawsuitsMisc - let the user edit form fields
        // parsedData.lawsuitsMisc = abuseSection;
    }

    // Parse Rules and Punishments section
    const rulesSection = getSectionAny(normalizedMarkdown, [
        'Rules and Punishments',
        'Rules & Punishments',
        'Rules',
        'Punishments'
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
        'News'
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

    // Parse Survivor Testimonies section
    const testimoniesSection = getSectionAny(normalizedMarkdown, [
        'Survivor Testimonies',
        'Testimonies',
        'Survivor Accounts',
        'Survivor Stories'
    ]);

    if (testimoniesSection && !testimoniesSection.includes('No information is known')) {
        // Parse testimonies - look for quoted text followed by source
        const testimonyBlocks = testimoniesSection.split(/\n\n+/);
        testimonyBlocks.forEach(block => {
            const trimmed = block.trim();
            if (!trimmed) return;

            // Match pattern: "quote" - Source [Link](URL)
            const testimonyMatch = trimmed.match(/"([^"]+)"\s*[-–—]\s*([^[\n]+)(?:\[([^\]]+)\]\(([^)]+)\))?/);
            if (testimonyMatch) {
                parsedData.testimonies.push({
                    quote: testimonyMatch[1].trim(),
                    source: testimonyMatch[2].trim(),
                    url: testimonyMatch[4] ? sanitizeUrl(testimonyMatch[4]) : ''
                });
            }
        });

        // DON'T store everything in testimoniesMisc - let the user edit form fields
        // parsedData.testimoniesMisc = testimoniesSection;
    }

    // Parse Related Media section
    const relatedMediaSection = getSectionAny(normalizedMarkdown, [
        'Related Media',
        'Related Media (Links)',
        'Related Links',
        'External Links'
    ]);

    if (relatedMediaSection && !relatedMediaSection.includes('No information is known')) {
        // Extract links [Title](URL)
        const mediaMatches = relatedMediaSection.matchAll(/\[([^\]]+)\]\(([^)]+)\)/g);
        for (const match of mediaMatches) {
            parsedData.relatedMedia.push({
                title: match[1].trim(),
                url: sanitizeUrl(match[2])
            });
        }

        // DON'T store everything in relatedMediaMisc - let the user edit form fields
        // parsedData.relatedMediaMisc = relatedMediaSection;
    }

    // Collect unparsed sections
    const parsedSectionTitles = [
        'History and Background Information',
        'Founders and Notable Staff',
        'Staff',
        'Notable Staff',
        'Founders',
        'Program Structure',
        'Structure',
        'Program Model',
        'Level System',
        'Level Systems',
        'Phase System',
        'Phases',
        'Program Phases',
        'Abuse/Neglect Allegations and Lawsuits',
        'Abuse Allegations',
        'Abuse/Neglect Allegations',
        'Allegations and Lawsuits',
        'Abuse Allegations and Lawsuits',
        'Rules and Punishments',
        'Rules & Punishments',
        'Rules',
        'Punishments',
        'In the Media & News',
        'In the Media',
        'Media & News',
        'Media and News',
        'News',
        'Survivor Testimonies',
        'Testimonies',
        'Survivor Accounts',
        'Survivor Stories',
        'Related Media',
        'Related Media (Links)',
        'Related Links',
        'External Links'
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

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { parseWikiMarkdown, sanitizeUrl };
} else if (typeof window !== 'undefined') {
    // Expose to global scope for browser usage
    window.parseWikiMarkdown = parseWikiMarkdown;
    window.sanitizeUrl = sanitizeUrl;
}
