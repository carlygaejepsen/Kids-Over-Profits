/**
 * Wiki Generation Module
 * Handles the generation of Reddit-formatted markdown from form data
 */

/**
 * Main function to generate wiki markdown from collected form data
 * @param {object} formData - Object containing all form field values and arrays
 * @returns {string} - Generated markdown text
 */
function generateWikiMarkdown(formData) {
    const programName = formData.programName || '[Program Name]';

    // Helper: Create markdown link
    const createLink = (text, url) => {
        if (!text) return '';
        const safeUrl = sanitizeUrl(url);
        if (!safeUrl) return escapeMarkdown(text);
        return `[${escapeMarkdown(text)}](${safeUrl})`;
    };

    // Helper: Join items with "and" for natural sentences
    const joinWithAnd = (items) => {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    };

    // Helper: Ensure trailing period for fragments
    const ensureSentence = (text) => {
        const trimmed = (text || '').trim();
        if (!trimmed) return '';
        return /[.!?]"?$/.test(trimmed) ? trimmed : `${trimmed}.`;
    };

    // Helper: Pad bolded staff names with a space if they run directly into text
    const ensureBoldNameSpacing = (text) => {
        if (!text) return text;
        return text.replace(/\*\*([^*]+)\*\*([^\s*])/g, '**$1** $2');
    };

    // Helper: Determine verb tense for staff members
    const roleVerb = (staffMember) => {
        if (!staffMember) return 'is';
        if (staffMember.isFormer) return 'was';
        const value = (staffMember.role || '').toLowerCase();
        return /\b(former|previous|ex[\s-])/.test(value) ? 'was' : 'is';
    };

    // Helper: Check if content is effectively empty or just boilerplate
    const isEffectivelyEmpty = (text) => {
        if (!text) return true;
        const trimmed = text.trim();
        if (trimmed.length === 0) return true;
        const lower = trimmed.toLowerCase();
        return lower === 'no information is known' || 
               lower === 'no information available' ||
               lower.startsWith('no information is known about');
    };

    // --- Build History Section ---
    let historySection = '';

    if (formData.historyNotes && !isEffectivelyEmpty(formData.historyNotes)) {
        // Use full imported/custom history section
        historySection = formData.historyNotes.trim();
    } else {
        const historySentences = [];

        const descriptorParts = [];
        if (formData.programType) descriptorParts.push(`a ${escapeMarkdown(formData.programType)}`);
        if (formData.yearFounded) descriptorParts.push(`founded in ${escapeMarkdown(formData.yearFounded)}`);
        if (formData.cityState) descriptorParts.push(`based in ${escapeMarkdown(formData.cityState)}`);
        if (descriptorParts.length > 0) {
            historySentences.push(`${escapeMarkdown(programName)} is ${joinWithAnd(descriptorParts)}.`);
        }

        if (formData.ownerName) {
            historySentences.push(`The program is owned and operated by ${createLink(formData.ownerName, formData.ownerLink)}.`);
        }

        const audienceParts = [];
        if (formData.ageRange) audienceParts.push(`serves young people aged ${escapeMarkdown(formData.ageRange)}`);

        // Build diagnoses list from selected checkboxes + custom diagnoses
        if (formData.selectedDiagnoses && formData.selectedDiagnoses.length > 0) {
            const allDiagnoses = [...formData.selectedDiagnoses];
            if (formData.customDiagnoses) {
                const customItems = formData.customDiagnoses.split(',').map(d => d.trim()).filter(Boolean);
                allDiagnoses.push(...customItems);
            }
            audienceParts.push(`marketed for students who struggle with a variety of challenges such as ${allDiagnoses.join(', ')}`);
        } else if (formData.diagnosesList) {
            // Fallback to old comma-separated field
            const diagnoses = formData.diagnosesList.split(',')
                .map(item => item.trim())
                .filter(Boolean)
                .map(item => `"${escapeMarkdown(item)}"`)
                .join(', ');
            if (diagnoses) {
                audienceParts.push(`lists ${diagnoses} as target diagnoses or behaviors`);
            }
        }

        if (audienceParts.length > 0) {
            historySentences.push(`The program ${joinWithAnd(audienceParts)}.`);
        }

        const operationsParts = [];
        if (formData.capacity) operationsParts.push(`has a maximum enrollment of ${escapeMarkdown(formData.capacity)}`);
        if (formData.campusSize) operationsParts.push(`operates on a ${escapeMarkdown(formData.campusSize)} campus`);
        if (formData.avgStay) operationsParts.push(`reports an average length of stay of around ${escapeMarkdown(formData.avgStay)}`);
        if (formData.tuition) operationsParts.push(`reports tuition of ${escapeMarkdown(formData.tuition)}`);

        // Build NATSAP status sentence from checkbox/dropdown + year
        if (formData.natsapMember === 'yes' && formData.natsapYear) {
            operationsParts.push(`has been a NATSAP member since ${escapeMarkdown(formData.natsapYear)}`);
        } else if (formData.natsapMember === 'yes') {
            operationsParts.push(`is a NATSAP member`);
        } else if (formData.natsapMember === 'former') {
            operationsParts.push(`is a former NATSAP member`);
        } else if (formData.natsapMember === 'no') {
            operationsParts.push(`is not a NATSAP member`);
        }

        if (operationsParts.length > 0) {
            historySentences.push(`It ${joinWithAnd(operationsParts)}.`);
        }

        if (formData.mainAddress) {
            historySentences.push(`The main office is located at ${createLink(formData.mainAddress, formData.addressLink)}.`);
        }

        if (formData.accreditingBody) {
            historySentences.push(`The program is accredited by the ${createLink(formData.accreditingBody, formData.accreditingBodyLink)}.`);
        }

        // Generate sentences for additional campuses
        if (formData.campuses && formData.campuses.length > 0) {
            const campusList = formData.campuses.map(c => `${escapeMarkdown(c.name)} in ${escapeMarkdown(c.location)}`);
            historySentences.push(`The program also operates additional locations including ${joinWithAnd(campusList)}.`);
        }

        // Generate sentences for ownership changes
        if (formData.ownershipChanges && formData.ownershipChanges.length > 0) {
            formData.ownershipChanges.forEach(change => {
                const prevText = change.previousLink
                    ? `[${escapeMarkdown(change.previous)}](${change.previousLink})`
                    : escapeMarkdown(change.previous);
                const newText = change.newOwnerLink
                    ? `[${escapeMarkdown(change.newOwner)}](${change.newOwnerLink})`
                    : escapeMarkdown(change.newOwner);

                if (change.previous && change.newOwner) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, the program changed ownership from ${prevText} to ${newText}.`);
                } else if (change.newOwner) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, the program was acquired by ${newText}.`);
                } else if (change.previous) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, ${prevText} divested from the program.`);
                }
            });
        }

        // Rebrand/Spin-off info
        if (formData.rebrand) {
            const rebrandLink = formData.rebrandLink ? `[${escapeMarkdown(formData.rebrand)}](${formData.rebrandLink})` : escapeMarkdown(formData.rebrand);
            historySentences.push(`It is believed to be a rebrand or spin-off of ${rebrandLink}.`);
        }

        // Affiliations
        if (formData.affiliations && formData.affiliations.length > 0) {
            const affList = formData.affiliations.map(aff => {
                return aff.link ? `[${escapeMarkdown(aff.name)}](${aff.link})` : escapeMarkdown(aff.name);
            });
            historySentences.push(`The program is affiliated with ${joinWithAnd(affList)}.`);
        }

        historySection = historySentences.length > 0
            ? historySentences.join('\n\n')
            : getPlaceholder('History and Background Information', programName);
    }

    // --- Build Staff Section ---
    let staffSection;
    if (formData.staffMembers && formData.staffMembers.length > 0) {
        // Sort staff: current staff first, then former staff
        const sortedStaff = [...formData.staffMembers].sort((a, b) => {
            const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
            const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
            if (aIsFormer && !bIsFormer) return 1;
            if (!aIsFormer && bIsFormer) return -1;
            return 0;
        });

        staffSection = sortedStaff.map(s => {
            let roleText = escapeMarkdown(s.role);

            const roleHasFormer = /\b(former|previous|ex[\s-])/i.test(s.role || '');
            const isFormerStaff = s.isFormer || roleHasFormer;

            if (isFormerStaff) {
                roleText = roleText.replace(/\bcurrent\s+/gi, '').trim();
            }

            // If no role is defined, just output "**Name** Bio"
            if (!s.role) {
                const bio = ensureSentence(s.bio || '');
                return `**${safeStaffName}** ${bio}`.trim();
            }

            const articleRole = roleText.toLowerCase().startsWith('the ') ? roleText : `the ${roleText}`;

            const safeStaffName = escapeMarkdown((s.name || '').trim());

            let verb, descriptor;
            if (s.isFormer && !roleHasFormer) {
                verb = 'is';
                descriptor = articleRole.replace(/^the\s+/i, 'the former ');
            } else if (roleHasFormer) {
                verb = 'was';
                descriptor = articleRole;
            } else {
                verb = 'is';
                descriptor = articleRole;
            }

            // Ensure proper spacing between name and verb
            const roleSentence = ensureSentence(`**${safeStaffName}** ${verb} ${descriptor}`);

            let previousSentence = '';
            if (s.previousRoles && s.previousRoles.length) {
                const formattedRoles = s.previousRoles.map(pr => {
                    if (typeof pr === 'string') return escapeMarkdown(pr);
                    if (pr.role && pr.employer) return `${escapeMarkdown(pr.role)} at ${escapeMarkdown(pr.employer)}`;
                    return escapeMarkdown(pr.role || pr.employer || '');
                }).filter(Boolean);
                if (formattedRoles.length > 0) {
                    previousSentence = ensureSentence(`Previously worked as ${joinWithAnd(formattedRoles)}`);
                }
            }

            const bioSentence = ensureSentence(s.bio || '');
            return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
        }).join('\n\n');
        staffSection = ensureBoldNameSpacing(staffSection);
    } else {
        staffSection = getPlaceholder('Founders and Notable Staff', programName);
    }

    // --- Build Structure Section ---
    let structureSection = '';
    const structureParts = [];

    const levelSystemTypes = { level: 'level system', phase: 'phase system', point: 'point system', tier: 'tier system' };
    if (formData.levelSystemType && formData.levelCount) {
        structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[formData.levelSystemType] || formData.levelSystemType} consisting of ${escapeMarkdown(formData.levelCount)} ${formData.levelSystemType === 'phase' ? 'phases' : 'levels'}.`);
    } else if (formData.levelSystemType) {
        structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses a ${levelSystemTypes[formData.levelSystemType] || formData.levelSystemType}.`);
    }

    if (formData.levelSystemDesc && formData.levelSystemDesc.trim()) {
        structureParts.push(formData.levelSystemDesc.trim());
    }

    if (formData.programLevels && formData.programLevels.length > 0) {
        const levelDescriptions = formData.programLevels.map(level => {
            if (level.description) {
                return `- **${escapeMarkdown(level.name)}:** ${level.description}`;
            }

            const parts = [];
            if (level.duration) parts.push(`Duration: ${escapeMarkdown(level.duration)}`);
            if (level.privileges) parts.push(`Privileges: ${escapeMarkdown(level.privileges)}`);
            if (level.restrictions) parts.push(`Restrictions: ${escapeMarkdown(level.restrictions)}`);

            return `- **${escapeMarkdown(level.name)}**${parts.length > 0 ? ': ' + parts.join('. ') : ''}`;
        }).join('\n\n');

        if (levelDescriptions) {
            structureParts.push(levelDescriptions);
        }
    }

    const educationTypes = {
        accredited: 'an accredited on-site school',
        online: 'online or computer-based education',
        packet: 'packet-based or worksheet education',
        limited: 'limited or sporadic educational instruction',
        none: 'no formal educational instruction'
    };
    if (formData.educationType) {
        let eduSentence = `The program provides ${educationTypes[formData.educationType] || formData.educationType}`;
        if (formData.educationAccreditor) {
            eduSentence += `, accredited by ${escapeMarkdown(formData.educationAccreditor)}`;
        }
        eduSentence += '.';
        structureParts.push(eduSentence);
    }

    if (formData.therapies && formData.therapies.length > 0) {
        const therapyDescriptions = formData.therapies.map(t => {
            return t.frequency ? `${t.label} (${escapeMarkdown(t.frequency)})` : t.label;
        });
        structureParts.push(`The program offers ${joinWithAnd(therapyDescriptions)}.`);
    }

    if (formData.structureMisc && !isEffectivelyEmpty(formData.structureMisc)) {
        structureParts.push(formData.structureMisc.trim());
    }

    structureSection = structureParts.length > 0
        ? structureParts.join('\n\n')
        : getPlaceholder('Program Structure', programName);

    // --- Build Rules & Punishments Section ---
    let rulesSection = '';

    if (formData.punishmentsMisc && !isEffectivelyEmpty(formData.punishmentsMisc)) {
        rulesSection = formData.punishmentsMisc.trim();
    } else {
        const rulesParts = [];

        if (formData.rules && formData.rules.length > 0) {
            const rulesList = formData.rules.map(r => `- ${escapeMarkdown(r.name || r)}`).join('\n');
            rulesParts.push(`${escapeMarkdown(programName)} is a very strict program with many rules. Some of these rules include:\n\n${rulesList}`);
        }

        if (formData.punishments && formData.punishments.length > 0) {
            const punishmentDescriptions = formData.punishments.map(p => {
                return `**${escapeMarkdown(p.name)}** ${escapeMarkdown(p.description)}`;
            }).join('\n\n');
            rulesParts.push(`The program uses various punishments to enforce compliance:\n\n${punishmentDescriptions}`);
        }

        rulesSection = rulesParts.length > 0
            ? rulesParts.join('\n\n')
            : getPlaceholder('Rules and Punishments', programName);
    }

    // --- Build Abuse Section ---
    let abuseSection = '';

    if (formData.lawsuitsMisc && !isEffectivelyEmpty(formData.lawsuitsMisc)) {
        abuseSection = formData.lawsuitsMisc.trim();
    } else {
        const abuseParts = [];

        if (formData.mainComplaints) {
            abuseParts.push(`Many survivors have reported that abuse and neglect have occurred at ${escapeMarkdown(programName)}. The main complaints are of ${escapeMarkdown(formData.mainComplaints)}.`);
        }

        // Build allegations from selected checkboxes + custom allegations
        if ((formData.selectedAllegations && formData.selectedAllegations.length > 0) || formData.customAllegations) {
            const allAllegations = [...(formData.selectedAllegations || [])];

            if (formData.customAllegations) {
                const customItems = formData.customAllegations.split(',').map(a => a.trim()).filter(Boolean);
                allAllegations.push(...customItems);
            }

            if (allAllegations.length > 0) {
                abuseParts.push('Allegations of abuse and neglect that have been reported by survivors include ' +
                    allAllegations.join(', ') + '.');
            }
        }

        // Build lawsuit descriptions from structured data
        if (formData.lawsuits && formData.lawsuits.length > 0) {
            const outcomeLabels = {
                settled: 'was settled', dismissed: 'was dismissed', plaintiff: 'was decided in favor of the plaintiff',
                defendant: 'was decided in favor of the defendant', ongoing: 'is still ongoing'
            };
            const lawsuitDescriptions = formData.lawsuits.map(lawsuit => {
                const defendant = lawsuit.defendant || programName;
                let sentence = `In ${escapeMarkdown(lawsuit.year)}, ${escapeMarkdown(lawsuit.plaintiff)} filed a lawsuit against ${escapeMarkdown(defendant)}`;
                if (lawsuit.court) sentence += ` in ${escapeMarkdown(lawsuit.court)}`;
                sentence += ` alleging ${escapeMarkdown(lawsuit.claims || lawsuit.allegations || lawsuit.description)}.`;

                if (lawsuit.outcome && outcomeLabels[lawsuit.outcome]) {
                    sentence += ` The case ${outcomeLabels[lawsuit.outcome]}`;
                    if (lawsuit.amount) {
                        sentence += ` for ${escapeMarkdown(lawsuit.amount)}`;
                    }
                    sentence += '.';
                } else if (lawsuit.amount) {
                    sentence += ` The settlement was ${escapeMarkdown(lawsuit.amount)}.`;
                }

                return sentence;
            }).join('\n\n');
            abuseParts.push(lawsuitDescriptions);
        }

        abuseSection = abuseParts.length > 0
            ? abuseParts.join('\n\n')
            : getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);
    }

    // --- Build Media Section ---
    let mediaSection;
    if (formData.mediaInfo && !isEffectivelyEmpty(formData.mediaInfo)) {
        mediaSection = formData.mediaInfo.trim();
    } else if (formData.newsArticles && formData.newsArticles.length > 0) {
        const newsList = formData.newsArticles.map(a => {
            let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
            if (sourceDate) sourceDate = ` (${sourceDate})`;
            const safeUrl = sanitizeUrl(a.url);
            const linkText = safeUrl ? `[${escapeMarkdown(a.title)}](${safeUrl})` : escapeMarkdown(a.title);
            return `- ${linkText}${sourceDate}`;
        }).join('\n');
        mediaSection = newsList;
    } else {
        mediaSection = getPlaceholder('Media Coverage', programName);
    }

    // --- Build Testimonies Section ---
    let testimoniesSection;
    if (formData.testimoniesMisc && !isEffectivelyEmpty(formData.testimoniesMisc)) {
        testimoniesSection = formData.testimoniesMisc.trim();
    } else if (formData.testimonies && formData.testimonies.length > 0) {
        testimoniesSection = formData.testimonies.map(t => {
            const datePart = t.date ? `${t.date}: ` : '';
            const safeUrl = sanitizeUrl(t.url);
            const sourceLink = safeUrl ? `[${escapeMarkdown(t.source)}](${safeUrl})` : escapeMarkdown(t.source);
            return `**${datePart}(${t.type})** "${escapeMarkdown(t.quote)}" - ${sourceLink}`;
        }).join('\n\n');
    } else {
        testimoniesSection = getPlaceholder('Survivor Testimonies', programName);
    }

    // --- Build Related Programs Section ---
    let relatedProgramsSection = '';
    if (formData.relatedPrograms && formData.relatedPrograms.length > 0) {
        const tableHeader = '|** Program Name**|** Years Active**|** Location**|** HEAL Information**|** Reopened?**|';
        const tableSep = '|---|---|---|---|---|';
        const tableRows = formData.relatedPrograms.map(prog => {
            const nameLink = prog.link ? `[** ${escapeMarkdown(prog.name)}**](${sanitizeUrl(prog.link)})` : `** ${escapeMarkdown(prog.name)}**`;
            const healLink = prog.healLink ? `[HEAL](${sanitizeUrl(prog.healLink)})` : (prog.healInfo || '-');
            return `| ${nameLink} | ${escapeMarkdown(prog.yearsActive || '-')} | ${escapeMarkdown(prog.location || '-')} | ${healLink} | ${escapeMarkdown(prog.reopened || '-')} |`;
        });
        relatedProgramsSection = `##**Related Programs**\n\n${tableHeader}\n${tableSep}\n${tableRows.join('\n')}\n\n***\n\n`;
    }

    // --- Build Related Media Section ---
    let relatedMediaSection;
    if (formData.relatedMediaMisc && !isEffectivelyEmpty(formData.relatedMediaMisc)) {
        relatedMediaSection = formData.relatedMediaMisc.trim();
    } else if (formData.relatedMedia && formData.relatedMedia.length > 0) {
        relatedMediaSection = formData.relatedMedia.map(m => {
            const safeUrl = sanitizeUrl(m.url);
            const linkText = safeUrl ? `[${escapeMarkdown(m.title)}](${safeUrl})` : escapeMarkdown(m.title);
            return `- ${linkText}`;
        }).join('\n\n');
    } else {
        relatedMediaSection = getPlaceholder('Related Media', programName);
    }

    // --- Assemble Final Output ---
    const headerLine = `#**${escapeMarkdown(programName)}** (${formData.yearsActive || '[Years Active]'}) ${formData.cityState || '[City, ST]'}`;

    const output = `
${headerLine}
*${formData.programType || '[Program Type]'}*

***

##**History and Background Information**

${historySection}

***

##**Founders and Notable Staff**

${staffSection}

***

##**Program Structure**

${structureSection}

***

##**Rules and Punishments**

${rulesSection}

***

##**Abuse/Neglect Allegations and Lawsuits**

${abuseSection}

***

##**In the Media**

${mediaSection}

***

##**Survivor Testimonies**

${testimoniesSection}

***

${relatedProgramsSection}##**Related Media**

${relatedMediaSection}
    `;

    const footerPattern = /Last revised by \[shroomskillet\]\(\/user\/shroomskillet\/\)(?:\s*## Page title)?(?:\s*SaveCancel)?\s*$/gi;
    const sanitizedOutput = output.replace(footerPattern, '');

    return sanitizedOutput.trim();
}

// --- Helper Functions ---

function getPlaceholder(category, programName) {
    const name = programName || '[Program Name]';
    const lowerCategory = (category || '').toLowerCase();
    if (lowerCategory.includes('media')) {
        return `No media coverage for ${name} has been noted yet. If you have seen a news item about ${name} and would like to contribute information to help complete this page, please contact u/Signal-Strain9810.`;
    }

    return `No information is known about ${category} at ${name} yet. If you have reliable updates or references, please contact u/Signal-Strain9810.`;
}

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

function escapeMarkdown(text) {
    // Don't escape - preserve markdown formatting including links
    return String(text);
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { generateWikiMarkdown, sanitizeUrl };
} else if (typeof window !== 'undefined') {
    window.generateWikiMarkdown = generateWikiMarkdown;
    window.sanitizeUrlForWiki = sanitizeUrl;
}
