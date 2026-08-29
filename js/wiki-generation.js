/**
 * Wiki Generation Module
 * Handles the generation of Reddit-formatted markdown from form data
 */

/**
 * Main function to generate wiki markdown from collected form data
 * @param {object} formData - Object containing all form field values and arrays
 * @returns {string} - Generated markdown text
 */
function isOrganizationFormData(formData = {}) {
    // Respect an explicit choice both ways. A facility that names a parent
    // company is still a facility, so an explicit "facility" must not be
    // overridden by the heuristics below (which would route it to the org
    // template and drop its Structure/Rules/Testimonies sections).
    const declaredType = (formData.entryType || '').toLowerCase();
    if (declaredType === 'organization') {
        return true;
    }
    if (declaredType === 'facility') {
        return false;
    }

    if (formData.isOrganizationEntry) {
        return true;
    }

    const programType = String(formData.programType || '').trim().toLowerCase();
    const programName = String(formData.programName || '').trim();
    const hasProgramsTable = Array.isArray(formData.relatedPrograms) && formData.relatedPrograms.length > 0;
    const hasOrgOnlyFields = !!String(formData.headquarters || formData.parentCompany || '').trim();
    const hasFacilitySignals = !!(
        formData.cityState ||
        formData.mainAddress ||
        formData.ageRange ||
        formData.capacity ||
        formData.avgStay ||
        formData.tuition ||
        formData.natsapMember ||
        (Array.isArray(formData.selectedDiagnoses) && formData.selectedDiagnoses.length > 0)
    );
    // "Health" alone (e.g. "Behavioral Health Hospital") is a facility type;
    // only "Healthcare"/"Health Services" reads as a company.
    const nameLooksLikeOrganization = /association|company|corporation|corp\.|group|health(?:care|\s+services)|holdings|institute|network|partners|services|collective|natsap|ieca|wwasp|uhs/i.test(programName);
    const typeLooksLikeOrganization = /organization|association|company|corporation|group|operator|network|membership/i.test(programType);

    return typeLooksLikeOrganization
        || (hasOrgOnlyFields && !hasFacilitySignals)
        || (hasProgramsTable && !hasFacilitySignals)
        || (nameLooksLikeOrganization && !hasFacilitySignals);
}

// Markdown link helper shared by the address builders.
function addressLinkMd(text, url) {
    if (!text) return '';
    const safeUrl = sanitizeUrl(url);
    return safeUrl ? `[${escapeMarkdown(text)}](${safeUrl})` : escapeMarkdown(text);
}

// When the address changed but the user didn't also update the link field, the
// old link points at the *previous* location, so it must not ride along with the
// new address. Returns the link to use for the current address ('' = no link).
function currentAddressLink(formData, changed) {
    if (changed && String(formData.addressLink || '') === String(formData.formerAddressLink || '')) {
        return '';
    }
    return formData.addressLink;
}

function addressChanged(formData) {
    const former = String(formData.formerAddress || '').trim();
    return !!former && former.toLowerCase() !== String(formData.mainAddress || '').trim().toLowerCase();
}

// Build a standalone "located at" sentence from the structured address fields.
// Used when generating fresh (non-imported) history, where there is no existing
// prose to preserve. A changed address still names the prior location.
function buildAddressSentence(formData, subjectNoun) {
    if (!String(formData.mainAddress || '').trim()) return '';
    const changed = addressChanged(formData);
    const current = addressLinkMd(formData.mainAddress, currentAddressLink(formData, changed));
    if (changed) {
        const subject = subjectNoun || 'facility';
        return `The ${subject} was previously located at ${addressLinkMd(formData.formerAddress, formData.formerAddressLink)}, and is now located at ${current}.`;
    }
    return `The main office is located at ${current}.`;
}

// Update the address inside imported History prose WITHOUT rewriting the sentence
// it lives in. Real prose phrases this many ways ("The outpatient facility is
// located at...", "The program is located at...") and may list several campus
// addresses, so only the address token after the first "located at/in/as" is
// swapped — the surrounding wording (and every other campus) is left intact. A
// changed address is noted in place with a "(previously located at ...)" aside.
// If the prose has no address sentence at all, a standalone one is appended.
function replaceAddressInProse(prose, formData, subjectNoun) {
    const text = String(prose || '');
    if (!String(formData.mainAddress || '').trim()) return text;

    const changed = addressChanged(formData);
    const newMd = addressLinkMd(formData.mainAddress, currentAddressLink(formData, changed));
    const replacement = changed
        ? `${newMd} (previously located at ${addressLinkMd(formData.formerAddress, formData.formerAddressLink)})`
        : newMd;

    // Prefer swapping a linked address token: "...located at [addr](url)". The
    // link is matched atomically and the surrounding prose is left untouched —
    // the sentence may continue after the link ("...(url) on the appropriately
    // named Shady Lane."), so no trailing clause boundary is required here.
    // (A combined link-or-plain-text alternation previously backtracked into
    // the plain-text branch on such sentences and cut the URL at its first
    // dot, leaving raw URL fragments in the output.)
    const linkRe = /(\blocated\s+(?:at|as|in)\s+)\[[^\]\n]*\]\([^)\n]*\)/i;
    if (linkRe.test(text)) {
        return text.replace(linkRe, (_m, lead) => `${lead}${replacement}`);
    }
    // Otherwise swap a plain-text address, which runs to the clause boundary.
    const plainRe = /(\blocated\s+(?:at|as|in)\s+)([^.\n]+?)(\s*(?:\.|\n|$))/i;
    if (plainRe.test(text)) {
        return text.replace(plainRe, (_m, lead, _addr, tail) => `${lead}${replacement}${tail}`);
    }

    const sentence = buildAddressSentence(formData, subjectNoun);
    return sentence ? `${text}\n\n${sentence}` : text;
}

function generateWikiMarkdown(formData) {
    const programName = formData.programName || '[Program Name]';

    // Helper: Create markdown link
    const createLink = (text, url) => {
        if (!text) return '';
        const safeUrl = sanitizeUrl(url);
        if (!safeUrl) return escapeMarkdown(text);
        return `[${escapeMarkdown(text)}](${safeUrl})`;
    };

    // Helper: Drop duplicate list items (case-insensitive, order-preserving).
    // Imported/round-tripped data can accumulate repeats (e.g. the same profile
    // item or allegation several times); joining them raw produces "anger, anger,
    // anger". Compare on a normalized key but keep the first original spelling.
    const dedupeList = (items) => {
        const seen = new Set();
        const result = [];
        (items || []).forEach((item) => {
            const key = String(item == null ? '' : item).trim().toLowerCase();
            if (!key || seen.has(key)) return;
            seen.add(key);
            result.push(item);
        });
        return result;
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
        return text
            // Collapse padding INSIDE a single bold span: "**  Name  **" -> "**Name**".
            // The body must stay on one line ([^*\n]) so this can't latch onto a
            // closing "**" and run through the "\n\n" separating two staff entries
            // (which previously merged them into a run-on paragraph).
            .replace(/\*\*[ \t]+([^*\n]+?)[ \t]*\*\*/g, '**$1**')
            .replace(/\*\*([^*\n]+)\*\*([^\s*])/g, '**$1** $2');
    };

    const getFirstSentence = (text) => {
        const match = String(text || '').trim().match(/^(.+?[.!?])(?:\s|$)/);
        return match ? match[1].trim() : String(text || '').trim();
    };

    const normalizeGeneratedText = (value) => String(value || '')
        .toLowerCase()
        .replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1')
        .replace(/[*_`]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const stripRedundantRoleIntro = (text, roleText) => {
        const source = String(text || '').trim();
        if (!source) return '';

        const firstSentence = getFirstSentence(source);

        const normalizedSentence = normalizeGeneratedText(firstSentence);
        const normalizedRole = normalizeGeneratedText(roleText).replace(/^(?:the|a|an)\s+/, '');

        if (normalizedRole && normalizedSentence.includes(normalizedRole)) {
            return source.slice(firstSentence.length).trim();
        }

        return source;
    };

    const normalizeForComparison = normalizeGeneratedText;

    const addRoleArticle = (text) => {
        const trimmed = (text || '').trim();
        if (!trimmed) return '';
        if (/^(?:the|a|an|one of|in)\b/i.test(trimmed)) {
            return trimmed;
        }
        return `the ${trimmed}`;
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
        if (lower === 'no information is known' || lower === 'no information available') return true;
        // A short standalone "No information is known about..." stub is empty;
        // longer text that merely OPENS with the phrase ("No information is
        // known about the program used by X. It uses a level system, ...")
        // still carries real content and must be preserved.
        if (lower.startsWith('no information is known about')) return trimmed.length < 120;
        // The placeholder texts below run ~150-250 characters; anything much
        // longer than that has had real content appended to it.
        if (trimmed.length > 350) return false;
        return lower.startsWith('background information for ') ||
               lower.startsWith('detailed information about the founders or notable staff at ') ||
               lower.startsWith('detailed information about the program structure at ') ||
               lower.startsWith('detailed information about the rules, consequences, or disciplinary practices at ') ||
               lower.startsWith('documented information about abuse allegations, neglect, or lawsuits involving ') ||
               lower.startsWith('no survivor testimonies for ') ||
               lower.startsWith('no related media links for ') ||
               lower.startsWith('no media coverage for ') ||
               lower.startsWith('additional information about ') ||
               // Reddit wiki page footer accidentally stored in a notes field
               /^last revised by(\s+\[[^\]]*\]\([^)]*\))?$/i.test(trimmed);
    };

    if (isOrganizationFormData(formData)) {
        return generateOrganizationWikiMarkdown(formData, {
            programName,
            createLink,
            joinWithAnd,
            dedupeList,
            ensureSentence,
            ensureBoldNameSpacing,
            stripRedundantRoleIntro,
            normalizeForComparison,
            addRoleArticle,
            isEffectivelyEmpty
        });
    }

    // --- Build History Section ---
    let historySection = '';

    // Build the structured history from the individual fields first. Any
    // "Additional History Notes" are appended afterward (see below) rather than
    // replacing the section, so the structured fields are never dropped.
    let structuredHistory = '';
    {
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

        // Build diagnoses list from selected checkboxes + custom diagnoses.
        // Custom diagnoses must be honored even when no checkbox is ticked, so
        // combine both sources before deciding whether anything was provided.
        const allDiagnoses = [...(formData.selectedDiagnoses || [])];
        if (formData.customDiagnoses) {
            const customItems = formData.customDiagnoses.split(',').map(d => d.trim()).filter(Boolean);
            allDiagnoses.push(...customItems);
        }
        const uniqueDiagnoses = dedupeList(allDiagnoses);
        if (uniqueDiagnoses.length > 0) {
            audienceParts.push(`marketed for students who struggle with a variety of challenges such as ${uniqueDiagnoses.join(', ')}`);
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
        if (formData.avgStay) {
            // Drop the "around" lead-in when the value already carries its own
            // qualifier (e.g. "between 12 and 20 months") to avoid "around between".
            const stayHasQualifier = /^(?:between|about|approximately|roughly|around|over|under|up to)\b/i.test(formData.avgStay.trim());
            operationsParts.push(`reports an average length of stay of ${stayHasQualifier ? '' : 'around '}${escapeMarkdown(formData.avgStay)}`);
        }
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

        const addressSentence = buildAddressSentence(formData, 'facility');
        if (addressSentence) {
            historySentences.push(addressSentence);
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

        structuredHistory = historySentences.join('\n\n');
    }

    // The parser writes imported History prose to `historyMisc`; the browser editor
    // bridges it onto `historyNotes`, but headless callers (batch import,
    // regenerate-from-record) may not — so fall back to `historyMisc` here.
    const historyNotes = formData.historyNotes || formData.historyMisc;
    const historyNotesText = (historyNotes && !isEffectivelyEmpty(historyNotes))
        ? historyNotes.trim()
        : '';

    if (historyNotesText && formData.historyNotesIsImported) {
        // Imported entries already hold the full prose section, and the
        // structured fields were extracted from it — emitting both would
        // duplicate content, so keep the original notes verbatim. The one
        // exception is the address: swap that value in place so a structured
        // address edit takes effect (and a former location is noted), without
        // disturbing the wording of the sentence it lives in.
        historySection = replaceAddressInProse(historyNotesText, formData, 'facility');
    } else {
        historySection = [structuredHistory, historyNotesText].filter(Boolean).join('\n\n');
    }

    if (!historySection) {
        historySection = getPlaceholder('History and Background Information', programName);
    }

    // --- Build Staff Section ---
    // Imported staff prose is substituted verbatim (like History/Structure/etc.)
    // so bios the entry regex couldn't fully parse are never reworded or lost.
    const staffNotesText = (formData.staffMisc && !isEffectivelyEmpty(formData.staffMisc))
        ? formData.staffMisc.trim()
        : '';
    // Sort staff: current staff first, then former staff — then render each as
    // a "**Name** is/was the Role. Bio" paragraph.
    const buildStaffEntries = (staffList) => {
        const sortedStaff = [...staffList].sort((a, b) => {
            const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
            const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
            if (aIsFormer && !bIsFormer) return 1;
            if (!aIsFormer && bIsFormer) return -1;
            return 0;
        });

        return sortedStaff.map(s => {
            const safeStaffName = escapeMarkdown((s.name || '').trim());
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

            const articleRole = addRoleArticle(roleText);

            let verb, descriptor;
            if (s.isFormer && !roleHasFormer) {
                verb = 'was';
                descriptor = articleRole;
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
                    if (pr.role && pr.employer) return `as ${escapeMarkdown(pr.role)} at ${escapeMarkdown(pr.employer)}`;
                    if (pr.role) return `as ${escapeMarkdown(pr.role)}`;
                    if (pr.employer) return `at ${escapeMarkdown(pr.employer)}`;
                    return '';
                }).filter(Boolean);
                if (formattedRoles.length > 0) {
                    previousSentence = ensureSentence(`Previously worked ${joinWithAnd(formattedRoles)}`);
                }
            }

            const bioSentence = ensureSentence(stripRedundantRoleIntro(s.bio || '', roleText));
            if (previousSentence && bioSentence) {
                const normalizedPrevious = normalizeForComparison(previousSentence).replace(/^previously worked\s+/, '');
                const normalizedBio = normalizeForComparison(bioSentence);
                if (normalizedPrevious && normalizedBio.includes(normalizedPrevious)) {
                    previousSentence = '';
                }
            }

            return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
        }).join('\n\n');
    };

    let staffSection;
    if (staffNotesText && formData.staffMiscIsImported) {
        // Imported staff prose verbatim, plus entries added in the form since
        // the import (anyone whose name doesn't already appear in the text).
        const extraStaff = (formData.staffMembers || []).filter(s =>
            s && s.name && !staffNotesText.includes(String(s.name).trim()));
        staffSection = ensureBoldNameSpacing(
            [staffNotesText, extraStaff.length > 0 ? buildStaffEntries(extraStaff) : '']
                .filter(Boolean).join('\n\n')
        );
    } else if (formData.staffMembers && formData.staffMembers.length > 0) {
        staffSection = ensureBoldNameSpacing(buildStaffEntries(formData.staffMembers));
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

    // Mirror the History/Abuse/Testimonies handling: when the structure notes
    // were imported verbatim they already contain the full section, so substitute
    // them instead of appending the re-derived structured sentences (which would
    // duplicate the level system, level descriptions, etc.).
    const structureNotes = (formData.structureMisc && !isEffectivelyEmpty(formData.structureMisc))
        ? formData.structureMisc.trim()
        : '';

    if (structureNotes && formData.structureMiscIsImported) {
        structureSection = structureNotes;
    } else {
        if (structureNotes) {
            structureParts.push(structureNotes);
        }
        structureSection = structureParts.length > 0
            ? structureParts.join('\n\n')
            : getPlaceholder('Program Structure', programName);
    }

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
    let structuredAbuse = '';

    {
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

            const uniqueAllegations = dedupeList(allAllegations);
            if (uniqueAllegations.length > 0) {
                abuseParts.push('Allegations of abuse and neglect that have been reported by survivors include ' +
                    uniqueAllegations.join(', ') + '.');
            }
        }

        // Build lawsuit descriptions from structured data
        if (formData.lawsuits && formData.lawsuits.length > 0) {
            const outcomeLabels = {
                settled: 'was settled', dismissed: 'was dismissed', plaintiff: 'was decided in favor of the plaintiff',
                defendant: 'was decided in favor of the defendant', ongoing: 'is still ongoing'
            };
            const lawsuitDescriptions = formData.lawsuits
                .map(lawsuit => buildLawsuitSentence(lawsuit, programName, outcomeLabels))
                .filter(Boolean)
                .join('\n\n');
            if (lawsuitDescriptions) {
                abuseParts.push(lawsuitDescriptions);
            }
        }

        structuredAbuse = abuseParts.join('\n\n');
    }

    const lawsuitsNotesText = (formData.lawsuitsMisc && !isEffectivelyEmpty(formData.lawsuitsMisc))
        ? formData.lawsuitsMisc.trim()
        : '';

    if (lawsuitsNotesText && formData.lawsuitsMiscIsImported) {
        abuseSection = lawsuitsNotesText;
    } else {
        abuseSection = [structuredAbuse, lawsuitsNotesText].filter(Boolean).join('\n\n');
    }

    if (!abuseSection) {
        abuseSection = getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);
    }

    // --- Build Media Section ---
    let mediaSection;
    const renderableArticles = filterArticlesAlreadyInRelatedMedia(formData.newsArticles, formData);
    if (formData.mediaInfo && !isEffectivelyEmpty(formData.mediaInfo)) {
        mediaSection = formData.mediaInfo.trim();
    } else if (renderableArticles.length > 0) {
        const newsList = renderableArticles.map(a => {
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
    let structuredTestimonies = '';
    if (formData.testimonies && formData.testimonies.length > 0) {
        structuredTestimonies = formData.testimonies.map(t => {
            const headingParts = [];
            if (t.date) headingParts.push(t.date);
            if (t.type) headingParts.push(`(${t.type})`);
            const safeUrl = sanitizeUrl(t.url);
            const sourceLink = safeUrl ? `[${escapeMarkdown(t.source)}](${safeUrl})` : escapeMarkdown(t.source);
            const heading = headingParts.length > 0 ? `**${headingParts.join(': ')}** ` : '';
            const attribution = sourceLink ? ` - ${sourceLink}` : '';
            return `${heading}"${escapeMarkdown(t.quote)}"${attribution}`;
        }).join('\n\n');
    }

    const testimoniesNotesText = (formData.testimoniesMisc && !isEffectivelyEmpty(formData.testimoniesMisc))
        ? formData.testimoniesMisc.trim()
        : '';

    let testimoniesSection;
    if (testimoniesNotesText && formData.testimoniesMiscIsImported) {
        testimoniesSection = testimoniesNotesText;
    } else {
        testimoniesSection = [structuredTestimonies, testimoniesNotesText].filter(Boolean).join('\n\n');
    }

    if (!testimoniesSection) {
        testimoniesSection = getPlaceholder('Survivor Testimonies', programName);
    }

    // --- Build Related Programs Section ---
    let relatedProgramsSection = '';
    const listedRelatedPrograms = (formData.relatedPrograms || []).filter(prog => prog && prog.name);
    if (listedRelatedPrograms.length > 0) {
        const table = buildProgramsTableMd(listedRelatedPrograms, 'Years Active', true);
        relatedProgramsSection = `## **Related Programs**\n\n${table}\n\n***\n\n`;
    }

    // --- Build Related Media Section ---
    let structuredRelatedMedia = '';
    if (formData.relatedMedia && formData.relatedMedia.length > 0) {
        structuredRelatedMedia = formData.relatedMedia.map(m => {
            const safeUrl = sanitizeUrl(m.url);
            const linkText = safeUrl ? `[${escapeMarkdown(m.title)}](${safeUrl})` : escapeMarkdown(m.title);
            let annotation = [m.source, m.date].filter(Boolean).join(', ');
            if (annotation) annotation = ` (${annotation})`;
            return `- ${linkText}${annotation}`;
        }).join('\n\n');
    }

    const relatedMediaNotesText = (formData.relatedMediaMisc && !isEffectivelyEmpty(formData.relatedMediaMisc))
        ? formData.relatedMediaMisc.trim()
        : '';

    let relatedMediaSection;
    if (relatedMediaNotesText && formData.relatedMediaMiscIsImported) {
        relatedMediaSection = relatedMediaNotesText;
    } else {
        relatedMediaSection = [structuredRelatedMedia, relatedMediaNotesText].filter(Boolean).join('\n\n');
    }

    if (!relatedMediaSection) {
        relatedMediaSection = getPlaceholder('Related Media', programName);
    }

    // --- Build Additional Sections ---
    // Sections the parser couldn't map to a known field (e.g. "Closure and
    // Rebranding", "Deaths", "Controversies") are preserved verbatim in
    // unparsedContent so they aren't silently dropped on a parse->generate
    // round-trip. Bold their headers to match the rest of the page.
    const additionalSections = formatUnparsedSections(formData.unparsedContent);

    // --- Assemble Final Output ---
    const headerLine = `# **${escapeMarkdown(programName)}** (${formData.yearsActive || '[Years Active]'}) ${formData.cityState || '[City, ST]'}`;

    const output = `
${headerLine}
*${formData.programType || '[Program Type]'}*

***

## **History and Background Information**

${historySection}

***

## **Founders and Notable Staff**

${staffSection}

***

## **Program Structure**

${structureSection}

***

## **Rules and Punishments**

${rulesSection}

***

## **Abuse/Neglect Allegations and Lawsuits**

${abuseSection}

***

## **In the Media**

${mediaSection}

***

## **Survivor Testimonies**

${testimoniesSection}

***

${relatedProgramsSection}${additionalSections}## **Related Media**

${relatedMediaSection}
    `;

    // Strip any Reddit wiki footer regardless of which username appears.
    // Matches: "Last revised by [anyone](link)" or bare "Last revised by"
    // followed optionally by "## Page title", "SaveCancel", trailing whitespace.
    const footerPattern = /\n?\s*Last revised by(?:\s+\[[^\]]*\]\([^)]*\))?(?:\s*##\s*Page title)?(?:\s*SaveCancel)?\s*$/gi;
    const sanitizedOutput = normalizeContactTag(output.replace(footerPattern, ''));

    return dropDuplicateParagraphs(normalizeBoldSpacing(sanitizedOutput)).trim();
}

function generateOrganizationWikiMarkdown(formData, helpers) {
    const {
        programName,
        createLink,
        joinWithAnd,
        dedupeList,
        ensureSentence,
        ensureBoldNameSpacing,
        stripRedundantRoleIntro,
        normalizeForComparison,
        addRoleArticle,
        isEffectivelyEmpty
    } = helpers;

    const headquartersText = formData.headquarters || formData.cityState || '';
    const organizationType = formData.programType || 'Parent Organization';

    let historySection = '';
    let structuredHistory = '';
    {
        const historySentences = [];
        const descriptorParts = [];

        descriptorParts.push(formData.programType ? `a ${escapeMarkdown(formData.programType)}` : 'a parent organization');

        if (formData.yearFounded) {
            descriptorParts.push(`founded in ${escapeMarkdown(formData.yearFounded)}`);
        }

        if (headquartersText) {
            descriptorParts.push(`headquartered in ${createLink(headquartersText, formData.addressLink)}`);
        }

        historySentences.push(`${escapeMarkdown(programName)} is ${joinWithAnd(descriptorParts)}.`);

        if (formData.ownerName) {
            historySentences.push(`The organization is owned or operated by ${createLink(formData.ownerName, formData.ownerLink)}.`);
        }

        if (formData.parentCompany) {
            historySentences.push(`Its parent company is ${createLink(formData.parentCompany, formData.parentCompanyLink)}.`);
        }

        if (!headquartersText) {
            const addressSentence = buildAddressSentence(formData, 'organization');
            if (addressSentence) {
                historySentences.push(addressSentence);
            }
        }

        if (formData.relatedPrograms && formData.relatedPrograms.length > 0) {
            historySentences.push(`Programs associated with ${escapeMarkdown(programName)} are listed below.`);
        }

        if (formData.ownershipChanges && formData.ownershipChanges.length > 0) {
            formData.ownershipChanges.forEach(change => {
                const prevText = change.previousLink
                    ? `[${escapeMarkdown(change.previous)}](${change.previousLink})`
                    : escapeMarkdown(change.previous);
                const newText = change.newOwnerLink
                    ? `[${escapeMarkdown(change.newOwner)}](${change.newOwnerLink})`
                    : escapeMarkdown(change.newOwner);

                if (change.previous && change.newOwner) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, the organization changed ownership from ${prevText} to ${newText}.`);
                } else if (change.newOwner) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, the organization was acquired by ${newText}.`);
                } else if (change.previous) {
                    historySentences.push(`In ${escapeMarkdown(change.year)}, ${prevText} divested from the organization.`);
                }
            });
        }

        if (formData.rebrand) {
            const rebrandLink = formData.rebrandLink
                ? `[${escapeMarkdown(formData.rebrand)}](${formData.rebrandLink})`
                : escapeMarkdown(formData.rebrand);
            historySentences.push(`It is believed to be a rebrand or spin-off of ${rebrandLink}.`);
        }

        if (formData.affiliations && formData.affiliations.length > 0) {
            const affList = formData.affiliations.map((aff) =>
                aff.link ? `[${escapeMarkdown(aff.name)}](${aff.link})` : escapeMarkdown(aff.name)
            );
            historySentences.push(`The organization is affiliated with ${joinWithAnd(affList)}.`);
        }

        structuredHistory = historySentences.filter(Boolean).join('\n\n');
    }

    // The parser writes imported History prose to `historyMisc`; the browser editor
    // bridges it onto `historyNotes`, but headless callers (batch import,
    // regenerate-from-record) may not — so fall back to `historyMisc` here.
    const historyNotes = formData.historyNotes || formData.historyMisc;
    const historyNotesText = (historyNotes && !isEffectivelyEmpty(historyNotes))
        ? historyNotes.trim()
        : '';

    if (historyNotesText && formData.historyNotesIsImported) {
        // Keep imported prose verbatim, but swap the address value in place so a
        // structured edit takes effect. When the org is headquartered somewhere
        // the address lives in the intro sentence, so leave it alone.
        historySection = headquartersText
            ? historyNotesText
            : replaceAddressInProse(historyNotesText, formData, 'organization');
    } else {
        historySection = [structuredHistory, historyNotesText].filter(Boolean).join('\n\n');
    }

    if (!historySection) {
        historySection = getPlaceholder('History and Background Information', programName);
    }

    const staffNotesText = (formData.staffMisc && !isEffectivelyEmpty(formData.staffMisc))
        ? formData.staffMisc.trim()
        : '';
    const buildStaffEntries = (staffList) => {
        const sortedStaff = [...staffList].sort((a, b) => {
            const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
            const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
            if (aIsFormer && !bIsFormer) return 1;
            if (!aIsFormer && bIsFormer) return -1;
            return 0;
        });

        return sortedStaff.map((s) => {
            const safeStaffName = escapeMarkdown((s.name || '').trim());
            let roleText = escapeMarkdown(s.role);

            const roleHasFormer = /\b(former|previous|ex[\s-])/i.test(s.role || '');
            const isFormerStaff = s.isFormer || roleHasFormer;

            if (isFormerStaff) {
                roleText = roleText.replace(/\bcurrent\s+/gi, '').trim();
            }

            if (!s.role) {
                const bio = ensureSentence(s.bio || '');
                return `**${safeStaffName}** ${bio}`.trim();
            }

            const articleRole = addRoleArticle(roleText);
            const verb = isFormerStaff ? 'was' : 'is';
            const descriptor = articleRole;
            const roleSentence = ensureSentence(`**${safeStaffName}** ${verb} ${descriptor}`);

            let previousSentence = '';
            if (s.previousRoles && s.previousRoles.length) {
                const formattedRoles = s.previousRoles.map((pr) => {
                    if (typeof pr === 'string') return escapeMarkdown(pr);
                    if (pr.role && pr.employer) return `as ${escapeMarkdown(pr.role)} at ${escapeMarkdown(pr.employer)}`;
                    if (pr.role) return `as ${escapeMarkdown(pr.role)}`;
                    if (pr.employer) return `at ${escapeMarkdown(pr.employer)}`;
                    return '';
                }).filter(Boolean);

                if (formattedRoles.length > 0) {
                    previousSentence = ensureSentence(`Previously worked ${joinWithAnd(formattedRoles)}`);
                }
            }

            const bioSentence = ensureSentence(stripRedundantRoleIntro(s.bio || '', roleText));
            if (previousSentence && bioSentence) {
                const normalizedPrevious = normalizeForComparison(previousSentence).replace(/^previously worked\s+/, '');
                const normalizedBio = normalizeForComparison(bioSentence);
                if (normalizedPrevious && normalizedBio.includes(normalizedPrevious)) {
                    previousSentence = '';
                }
            }

            return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
        }).join('\n\n');
    };

    let staffSection;
    if (staffNotesText && formData.staffMiscIsImported) {
        // Imported staff prose verbatim, plus entries added in the form since
        // the import (anyone whose name doesn't already appear in the text).
        const extraStaff = (formData.staffMembers || []).filter(s =>
            s && s.name && !staffNotesText.includes(String(s.name).trim()));
        staffSection = ensureBoldNameSpacing(
            [staffNotesText, extraStaff.length > 0 ? buildStaffEntries(extraStaff) : '']
                .filter(Boolean).join('\n\n')
        );
    } else if (formData.staffMembers && formData.staffMembers.length > 0) {
        staffSection = ensureBoldNameSpacing(buildStaffEntries(formData.staffMembers));
    } else {
        staffSection = getPlaceholder('Founders and Notable Staff', programName);
    }

    // Operator pages list their programs in two tables — "Open <Company> Programs"
    // and "Closed <Company> Programs" — with different columns (open shows Year
    // Opened; closed shows Years Active + Reopened?). Split by each program's
    // status (set by the parser from the source heading), falling back to
    // inferring from the year span ("…-present"/open-ended => open).
    const isOpenProgram = (prog) => {
        if (prog.status) return String(prog.status).toLowerCase() !== 'closed';
        const ya = String(prog.yearsActive || '').toLowerCase();
        if (!ya) return true;                                   // unknown -> treat as current
        if (/present|current|ongoing|now\b/.test(ya)) return true;
        return !/\d{4}\s*[-–—]\s*\d{4}/.test(ya);               // an explicit end year => closed
    };

    // Index-style pages already have "Programs" in their own name ("Active
    // Programs in Utah", "The Program Watchlist") — don't produce headings like
    // "Open Active Programs in Utah Programs".
    const tableNoun = /\bprograms?\b/i.test(programName) ? 'Programs' : `${programName} Programs`;

    let programsSection = '';
    const listedPrograms = (formData.relatedPrograms || []).filter((prog) => prog && prog.name);
    if (listedPrograms.length > 0) {
        const openPrograms = listedPrograms.filter(isOpenProgram);
        const closedPrograms = listedPrograms.filter((prog) => !isOpenProgram(prog));

        if (openPrograms.length > 0) {
            const table = buildProgramsTableMd(openPrograms, 'Year Opened', false);
            programsSection += `## **Open ${tableNoun}**\n\n${table}\n\n***\n\n`;
        }
        if (closedPrograms.length > 0) {
            const table = buildProgramsTableMd(closedPrograms, 'Years Active', true);
            programsSection += `## **Closed ${tableNoun}**\n\n${table}\n\n***\n\n`;
        }
    }
    if (!programsSection) {
        programsSection = `## **Open ${tableNoun}**\n\n${getPlaceholder('Related Programs', programName)}\n\n***\n\n`;
    }

    let abuseSection = '';
    let structuredAbuse = '';
    {
        const abuseParts = [];

        if (formData.mainComplaints) {
            abuseParts.push(`Reported complaints involving ${escapeMarkdown(programName)} include ${escapeMarkdown(formData.mainComplaints)}.`);
        }

        if ((formData.selectedAllegations && formData.selectedAllegations.length > 0) || formData.customAllegations) {
            const allAllegations = [...(formData.selectedAllegations || [])];
            if (formData.customAllegations) {
                const customItems = formData.customAllegations.split(',').map((a) => a.trim()).filter(Boolean);
                allAllegations.push(...customItems);
            }

            const uniqueAllegations = dedupeList(allAllegations);
            if (uniqueAllegations.length > 0) {
                abuseParts.push(`Reported allegations involving this organization or its programs include ${uniqueAllegations.join(', ')}.`);
            }
        }

        if (formData.lawsuits && formData.lawsuits.length > 0) {
            const outcomeLabels = {
                settled: 'was settled',
                dismissed: 'was dismissed',
                plaintiff: 'was decided in favor of the plaintiff',
                defendant: 'was decided in favor of the defendant',
                ongoing: 'is still ongoing'
            };

            const lawsuitDescriptions = formData.lawsuits
                .map((lawsuit) => buildLawsuitSentence(lawsuit, programName, outcomeLabels))
                .filter(Boolean)
                .join('\n\n');

            if (lawsuitDescriptions) {
                abuseParts.push(lawsuitDescriptions);
            }
        }

        structuredAbuse = abuseParts.join('\n\n');
    }

    const lawsuitsNotesText = (formData.lawsuitsMisc && !isEffectivelyEmpty(formData.lawsuitsMisc))
        ? formData.lawsuitsMisc.trim()
        : '';

    if (lawsuitsNotesText && formData.lawsuitsMiscIsImported) {
        abuseSection = lawsuitsNotesText;
    } else {
        abuseSection = [structuredAbuse, lawsuitsNotesText].filter(Boolean).join('\n\n');
    }

    if (!abuseSection) {
        abuseSection = getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);
    }

    let mediaSection;
    const renderableArticles = filterArticlesAlreadyInRelatedMedia(formData.newsArticles, formData);
    if (formData.mediaInfo && !isEffectivelyEmpty(formData.mediaInfo)) {
        mediaSection = formData.mediaInfo.trim();
    } else if (renderableArticles.length > 0) {
        const newsList = renderableArticles.map((a) => {
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

    let structuredRelatedMedia = '';
    if (formData.relatedMedia && formData.relatedMedia.length > 0) {
        structuredRelatedMedia = formData.relatedMedia.map((m) => {
            const safeUrl = sanitizeUrl(m.url);
            const linkText = safeUrl ? `[${escapeMarkdown(m.title)}](${safeUrl})` : escapeMarkdown(m.title);
            let annotation = [m.source, m.date].filter(Boolean).join(', ');
            if (annotation) annotation = ` (${annotation})`;
            return `- ${linkText}${annotation}`;
        }).join('\n\n');
    }

    const relatedMediaNotesText = (formData.relatedMediaMisc && !isEffectivelyEmpty(formData.relatedMediaMisc))
        ? formData.relatedMediaMisc.trim()
        : '';

    let relatedMediaSection;
    if (relatedMediaNotesText && formData.relatedMediaMiscIsImported) {
        relatedMediaSection = relatedMediaNotesText;
    } else {
        relatedMediaSection = [structuredRelatedMedia, relatedMediaNotesText].filter(Boolean).join('\n\n');
    }

    if (!relatedMediaSection) {
        relatedMediaSection = getPlaceholder('Related Media', programName);
    }

    // Preserve sections the parser couldn't map to a known field (see facility path).
    const additionalSections = formatUnparsedSections(formData.unparsedContent);

    // Safety net: an entry classified as an organization can still carry
    // facility-style sections (imported pages whose type detection is fuzzy).
    // The organization template has no fixed slots for them, so emit them as
    // extra sections rather than silently dropping the content.
    const orgStructureNotes = (formData.structureMisc && !isEffectivelyEmpty(formData.structureMisc)) ? formData.structureMisc.trim() : '';
    const orgRulesNotes = (formData.punishmentsMisc && !isEffectivelyEmpty(formData.punishmentsMisc)) ? formData.punishmentsMisc.trim() : '';
    const orgTestimoniesNotes = (formData.testimoniesMisc && !isEffectivelyEmpty(formData.testimoniesMisc)) ? formData.testimoniesMisc.trim() : '';
    let orgStructureSections = '';
    if (orgStructureNotes) orgStructureSections += `## **Program Structure**\n\n${orgStructureNotes}\n\n***\n\n`;
    if (orgRulesNotes) orgStructureSections += `## **Rules and Punishments**\n\n${orgRulesNotes}\n\n***\n\n`;
    let orgTestimoniesSection = '';
    if (orgTestimoniesNotes) orgTestimoniesSection = `## **Survivor Testimonies**\n\n${orgTestimoniesNotes}\n\n***\n\n`;

    let headerLine = formData.yearsActive
        ? `# **${escapeMarkdown(programName)}**(${formData.yearsActive})`
        : `# **${escapeMarkdown(programName)}**`;
    const orgLocation = String(formData.headquarters || formData.cityState || '').trim();
    if (orgLocation) {
        headerLine += ` ${escapeMarkdown(orgLocation)}`;
    }

    const output = `
${headerLine}
*${escapeMarkdown(organizationType)}*

***

## **History and Background Information**

${historySection}

***

## **Founders and Notable Staff**

${staffSection}

***

${programsSection}${orgStructureSections}## **Abuse/Neglect Allegations and Lawsuits**

${abuseSection}

***

## **In the Media**

${mediaSection}

***

${orgTestimoniesSection}${additionalSections}## **Related Media**

${relatedMediaSection}
    `;

    // Strip any Reddit wiki footer regardless of which username appears.
    const footerPattern = /\n?\s*Last revised by(?:\s+\[[^\]]*\]\([^)]*\))?(?:\s*##\s*Page title)?(?:\s*SaveCancel)?\s*$/gi;
    const sanitizedOutput = normalizeContactTag(output.replace(footerPattern, ''));

    return dropDuplicateParagraphs(normalizeBoldSpacing(sanitizedOutput)).trim();
}

// --- Helper Functions ---

// The page's point-of-contact handle. Every mention of it (and of the previous
// handle it replaced) must render as a markdown link to the Reddit profile.
const CONTACT_USERNAME = 'Signal-Strain9810';
const CONTACT_LINK = `[u/${CONTACT_USERNAME}](/u/${CONTACT_USERNAME})`;

// Normalize all contact-handle mentions to a single markdown link form. Handles
// both the current handle and the prior "Miss_Nobody89" handle, whether they
// appear as plain text (u/Name), a bare path (/u/Name, /user/Name), or an
// existing markdown link — so entries saved during the Miss_Nobody89 era are
// rewritten to the current contact on regeneration. Run this AFTER the footer
// is stripped, so the footer regex (which matches the old handle) still fires
// first.
function normalizeContactTag(md) {
    const names = 'Miss_Nobody89|Signal-Strain9810';
    // ONE pass, alternation ordered most-specific first, so the replacement
    // (which itself contains the handle) is never re-scanned and we never nest:
    //   1. an existing markdown link referencing a handle (label or url)
    //   2. a bare /u/, /user/, or u/ path mention
    //   3. any leftover bare mention of the handle
    // Character classes exclude newlines: markdown links never span lines, and
    // letting them match "\n" allowed a stray unclosed "[" in the source to
    // swallow everything up to a far-away link and replace it with the contact
    // handle.
    const re = new RegExp(
        `\\[[^\\]\\n]*(?:${names})[^\\]\\n]*\\]\\([^)\\n]*\\)` +
        `|\\[[^\\]\\n]*\\]\\([^)\\n]*(?:${names})[^)\\n]*\\)` +
        `|\\/?u(?:ser)?\\/(?:${names})\\/?` +
        `|(?:${names})`,
        'g'
    );
    return String(md || '').replace(re, CONTACT_LINK);
}

// Render a single lawsuit/incident record. Records extracted from imported prose
// frequently lack a plaintiff and structured year (the parser stores the whole
// narrative in `description`); in that case emit the narrative as its own
// sentence instead of "In undefined, undefined filed a lawsuit against ...".
function buildLawsuitSentence(lawsuit, programName, outcomeLabels) {
    if (!lawsuit) return '';
    const defendant = lawsuit.defendant || programName;
    const claims = lawsuit.claims || lawsuit.allegations || lawsuit.description || '';
    const hasParties = !!(lawsuit.year && lawsuit.plaintiff);

    let sentence;
    if (hasParties) {
        sentence = `In ${escapeMarkdown(lawsuit.year)}, ${escapeMarkdown(lawsuit.plaintiff)} filed a lawsuit against ${escapeMarkdown(defendant)}`;
        if (lawsuit.court) sentence += ` in ${escapeMarkdown(lawsuit.court)}`;
        if (claims) sentence += ` alleging ${escapeMarkdown(claims)}`;
        sentence += '.';
    } else if (claims) {
        sentence = String(escapeMarkdown(claims)).trim();
        if (sentence && !/[.!?]"?$/.test(sentence)) sentence += '.';
    } else {
        return '';
    }

    if (lawsuit.outcome && outcomeLabels[lawsuit.outcome]) {
        sentence += ` The case ${outcomeLabels[lawsuit.outcome]}`;
        if (lawsuit.amount) sentence += ` for ${escapeMarkdown(lawsuit.amount)}`;
        sentence += '.';
    } else if (lawsuit.amount) {
        sentence += ` The settlement was ${escapeMarkdown(lawsuit.amount)}.`;
    }

    return sentence;
}

// Build one markdown table of programs. Columns are included only when at
// least one program carries data for them, so watchlist-style tables (Program
// Type / Reported Abuse? / Reported Deaths? / Warning Level) round-trip without
// dropping those cells, while ordinary tables stay compact.
function buildProgramsTableMd(programs, yearsLabel, includeReopened) {
    const nameCell = (prog) => prog.link
        ? `[**${escapeMarkdown(prog.name)}**](${sanitizeUrl(prog.link)})`
        : `**${escapeMarkdown(prog.name)}**`;
    const healCell = (prog) => prog.healLink
        ? `[HEAL](${sanitizeUrl(prog.healLink)})`
        : (escapeMarkdown(prog.healInfo || '-') || '-');
    const textCell = (key) => (prog) => escapeMarkdown(prog[key] || '-');

    const columns = [
        { label: '**Program Name**', cell: nameCell, present: true },
        { label: `**${yearsLabel}**`, cell: textCell('yearsActive'), present: programs.some(p => p.yearsActive && p.yearsActive !== '-') },
        { label: '**Location(s)**', cell: textCell('location'), present: programs.some(p => p.location && p.location !== '-') },
        { label: '**Program Type**', cell: textCell('type'), present: programs.some(p => p.type && p.type !== '-') },
        { label: '**Reported Abuse?**', cell: textCell('reportedAbuse'), present: programs.some(p => p.reportedAbuse) },
        { label: '**Reported Deaths?**', cell: textCell('reportedDeaths'), present: programs.some(p => p.reportedDeaths) },
        { label: '**Warning Level**', cell: textCell('warningLevel'), present: programs.some(p => p.warningLevel) },
        { label: '**HEAL Information**', cell: healCell, present: programs.some(p => p.healLink || (p.healInfo && p.healInfo !== '-')) },
        { label: '**Reopened?**', cell: textCell('reopened'), present: includeReopened && programs.some(p => p.reopened && p.reopened !== '-') }
    ].filter(c => c.present);

    const header = `|${columns.map(c => c.label).join('|')}|`;
    const sep = `|${columns.map(() => '---').join('|')}|`;
    const rows = programs.map(prog => `| ${columns.map(c => c.cell(prog)).join(' | ')} |`);
    return `${header}\n${sep}\n${rows.join('\n')}`;
}

// Format sections the parser captured into `unparsedContent` (titles it couldn't
// map to a known field) for inclusion in generated output. Each is preserved
// verbatim; headers are bolded ("## Title" -> "## **Title**") to match the page
// style, and a trailing separator is added so it slots cleanly between sections.
function formatUnparsedSections(raw) {
    const text = String(raw || '').trim();
    if (!text) return '';
    const bolded = text
        // Keep each heading's original level so subsections ("### Photos")
        // aren't flattened into top-level sections.
        .replace(/^(#{1,6})\s*\*{0,2}\s*(.+?)\s*\*{0,2}\s*$/gm, '$1 **$2**')
        // Ensure a blank line between a header and the body that follows it.
        .replace(/^(#{1,6} \*\*.+\*\*)\n(?=\S)/gm, '$1\n\n');
    return `${bolded}\n\n***\n\n`;
}

// News articles promoted out of an imported Related Media section still live in
// that section's verbatim text (which the generator re-emits). Rendering them
// again under "In the Media" would duplicate them, so filter out any article
// whose URL already appears in the preserved Related Media text.
function filterArticlesAlreadyInRelatedMedia(articles, formData) {
    const misc = (formData.relatedMediaMiscIsImported && formData.relatedMediaMisc)
        ? String(formData.relatedMediaMisc)
        : '';
    if (!misc) return articles || [];
    return (articles || []).filter((a) => {
        const url = String(a.url || '');
        if (!url) return true;
        const raw = url.replace(/%20/g, ' ').replace(/%28/g, '(').replace(/%29/g, ')');
        return !misc.includes(url) && !misc.includes(raw);
    });
}

// Fix malformed bold spacing that HTML->markdown conversions leave behind:
// "** Name**" (padding inside the span, which breaks Reddit's renderer) and
// "at**Name**"/"**Name**text" (missing space at the span boundary). Also
// repairs the inline-italic variant "by*many*survivors". Applied as a final
// pass over generated output.
function normalizeBoldSpacing(md) {
    // Span content excludes "|" so adjacent bold table cells ("**A**|**B**")
    // are never mistaken for one bold span containing a pipe.
    return String(md || '')
        // padding inside a bold span: "** text **" -> "**text**"
        .replace(/\*\*[ \t]+([^*\n|]+?)[ \t]*\*\*/g, '**$1**')
        .replace(/\*\*([^*\n|]+?)[ \t]+\*\*/g, '**$1**')
        // missing space BEFORE a bold span: "at**Name**" -> "at **Name**"
        .replace(/([^\s*\n|[("'])\*\*([^*\n|]+?)\*\*/g, '$1 **$2**')
        // missing space AFTER a bold span: "**Name**text" -> "**Name** text"
        .replace(/\*\*([^*\n|]+?)\*\*([^\s*\n|).,;:!?'"\]])/g, '**$1** $2')
        // inline italic jammed between letters: "by*many*survivors"
        .replace(/([A-Za-z])\*([A-Za-z][^*\n|]{0,60}?)\*([A-Za-z])/g, '$1 *$2* $3');
}

// Final safety net: drop any duplicate paragraph block, keeping the first.
// Source pages occasionally repeat the same paragraph across two sections (e.g.
// the same memoir blurb under both History and Abuse), which the verbatim section
// passthrough would otherwise emit twice. Compared on a whitespace-collapsed key
// so an incidental line-wrap difference doesn't defeat the dedupe; URLs and
// wording still count, so same-label links to different sources are kept.
// Separators, headers, and short lines are never deduped, so distinct
// per-section placeholders (which differ in wording) are unaffected.
function dropDuplicateParagraphs(md) {
    const seen = new Set();
    const kept = [];
    for (const block of String(md || '').split(/\n{2,}/)) {
        const trimmed = block.trim();
        const isSeparator = trimmed === '***' || trimmed === '---';
        const isHeader = /^#{1,6}\s/.test(trimmed);
        if (!isSeparator && !isHeader && trimmed.length >= 40) {
            const key = trimmed.replace(/\s+/g, ' ');
            if (seen.has(key)) continue;
            seen.add(key);
        }
        kept.push(block);
    }
    return kept.join('\n\n');
}

function getPlaceholder(category, programName) {
    const name = programName || '[Program Name]';
    const lowerCategory = (category || '').toLowerCase();
    const placeholderByCategory = [
        {
            match: ['history', 'background'],
            text: `Background information for ${name} has not been added yet. If you have reliable historical details or sources to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['founders', 'staff'],
            text: `Information about the founders or notable staff at ${name} has not been added yet. If you have reliable names, roles, or source material to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['structure'],
            text: `Information about the program structure at ${name} has not been added yet. If you have reliable descriptions or source material to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['rules', 'punishments'],
            text: `Information about the rules, consequences, or disciplinary practices at ${name} has not been added yet. If you have reliable source material to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['abuse', 'neglect', 'lawsuits'],
            text: `Information about abuse allegations, neglect, or lawsuits involving ${name} has not been added yet. If you have reliable reports or source material to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['survivor testimonies', 'survivor testimony', 'testimonies', 'testimonials'],
            text: `No survivor testimonies for ${name} have been added here yet. If you have a firsthand account or reliable source material to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['related media'],
            text: `No related media links for ${name} have been added yet. If you have reliable external resources to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        },
        {
            match: ['related programs', 'affiliated programs'],
            text: `Programs associated with ${name} have not been added yet. If you have reliable information about operated, affiliated, or successor programs to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`
        }
    ];

    const categoryPlaceholder = placeholderByCategory.find((entry) =>
        entry.match.some((term) => lowerCategory.includes(term))
    );

    if (categoryPlaceholder) {
        return categoryPlaceholder.text;
    }

    if (lowerCategory.includes('media')) {
        return `No media coverage for ${name} has been added yet. If you have seen a news item about ${name} and would like to share it, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`;
    }

    return `Additional information about ${name} has not been added yet. If you have reliable updates or references to share, please contact [u/Signal-Strain9810](/u/Signal-Strain9810).`;
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
    // Only http(s) and site-relative URLs may pass — blocks javascript:,
    // data:, and other script-bearing schemes (including "javascript://…").
    if (!/^https?:\/\//i.test(url) && !url.startsWith('/')) return '';
    return url;
}

function escapeMarkdown(text) {
    // Don't escape - preserve markdown formatting including links
    return String(text);
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { generateWikiMarkdown, sanitizeUrl, normalizeContactTag };
} else if (typeof window !== 'undefined') {
    window.generateWikiMarkdown = generateWikiMarkdown;
    window.sanitizeUrlForWiki = sanitizeUrl;
    window.normalizeContactTag = normalizeContactTag;
}
