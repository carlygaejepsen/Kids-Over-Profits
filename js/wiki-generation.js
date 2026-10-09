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
    // "**  Name  **" -> "**Name**", "**Name**was" -> "**Name** was": the same
    // line-by-line pass as the final output (normalizeBoldSpacing), so a bio with
    // a second bold phrase keeps both spans.
    const ensureBoldNameSpacing = (text) => (text ? normalizeBoldSpacing(text) : text);

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
    // A closed program's staff are all written in the past ("was a therapist").
    const pastProgram = isClosedYears(formData.yearsActive);
    const buildStaffEntries = (staffList) => {
        const sortedStaff = mergeStaffByName(staffList).sort((a, b) => {
            const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
            const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
            if (aIsFormer && !bIsFormer) return 1;
            if (!aIsFormer && bIsFormer) return -1;
            return 0;
        });

        return sortedStaff.map(s => {
            const safeStaffName = escapeMarkdown((s.name || '').trim());
            const roleText = escapeMarkdown(s.role);
            const past = pastProgram || s.isFormer || /\b(former|previous|ex[\s-])/i.test(s.role || '');

            // If no role is defined, just output "**Name** Bio"
            if (!s.role) {
                const bio = ensureSentence(s.bio || '');
                return `**${safeStaffName}** ${bio}`.trim();
            }

            // "**Name** was a therapist" / "is the Executive Director" / "worked in admissions"; the same
            // person's other roles at the program follow ("Roe was also the program director").
            const roleSentence = [ensureSentence(`**${safeStaffName}** ${staffRoleClause(roleText, past)}`),
                staffExtraRolesSentence(safeStaffName, (s.extraRoles || []).map(escapeMarkdown), past)].filter(Boolean).join(' ');

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
${alternateNamesLine(formData)}*${formData.programType || '[Program Type]'}*

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

    return dropDuplicateParagraphs(normalizePunctSpacing(normalizeBoldSpacing(sanitizedOutput))).trim();
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
    const pastOrganization = isClosedYears(formData.yearsActive);
    const buildStaffEntries = (staffList) => {
        const sortedStaff = mergeStaffByName(staffList).sort((a, b) => {
            const aIsFormer = a.isFormer || /\b(former|previous|ex[\s-])/i.test(a.role || '');
            const bIsFormer = b.isFormer || /\b(former|previous|ex[\s-])/i.test(b.role || '');
            if (aIsFormer && !bIsFormer) return 1;
            if (!aIsFormer && bIsFormer) return -1;
            return 0;
        });

        return sortedStaff.map((s) => {
            const safeStaffName = escapeMarkdown((s.name || '').trim());
            const roleText = escapeMarkdown(s.role);
            const past = pastOrganization || s.isFormer || /\b(former|previous|ex[\s-])/i.test(s.role || '');

            if (!s.role) {
                const bio = ensureSentence(s.bio || '');
                return `**${safeStaffName}** ${bio}`.trim();
            }

            // The same role sentence as a program's staff (staffRoleClause()).
            const roleSentence = [ensureSentence(`**${safeStaffName}** ${staffRoleClause(roleText, past)}`),
                staffExtraRolesSentence(safeStaffName, (s.extraRoles || []).map(escapeMarkdown), past)].filter(Boolean).join(' ');

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
        ? `# **${escapeMarkdown(programName)}** (${formData.yearsActive})`
        : `# **${escapeMarkdown(programName)}**`;
    const orgLocation = String(formData.headquarters || formData.cityState || '').trim();
    if (orgLocation) {
        headerLine += ` ${escapeMarkdown(orgLocation)}`;
    }

    const output = `
${headerLine}
${alternateNamesLine(formData)}*${escapeMarkdown(organizationType)}*

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

    return dropDuplicateParagraphs(normalizePunctSpacing(normalizeBoldSpacing(sanitizedOutput))).trim();
}

// --- Helper Functions ---

// Readers with something to add write to the subreddit's modmail, never to one
// person's account. api/lib-wiki-contact.php holds the same link and rewrites
// the saved entries; keep the two in step.
const CONTACT_URL = 'https://www.reddit.com/message/compose?to=/r/troubledteens';
const CONTACT_LINK = `[r/troubledteens modmail](${CONTACT_URL})`;

// Normalize every mention of a personal contact handle to the modmail link:
// the handles pages used to name (Miss_Nobody89, Signal-Strain9810 and its
// Signal-Strain8910 typo), whether they appear as plain text (u/Name), a bare
// path (/u/Name, /user/Name), or an existing markdown link. Run this AFTER the
// footer is stripped, so the footer regex (which matches the old handle) still
// fires first.
function normalizeContactTag(md) {
    const names = 'Miss_Nobody89|Signal-Strain9810|Signal-Strain8910';
    // ONE pass, alternation ordered most-specific first, so a link is replaced
    // whole and never nested inside another:
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

// The bold-italic line above the type line naming the program's previous and alternate
// names ("***Previous & alternate names: Old Name, Other Name***", then a blank line so Reddit
// does not run it into the type line); '' when there are none. The parser reads it back into
// alternateNames; the wiki updates write the same line (inc/wiki-update-drafts.php).
function alternateNamesLine(formData) {
    const names = String((formData && formData.alternateNames) || '')
        .split(/\s*[;,\n]\s*/).map(n => n.replace(/\*/g, '').trim()).filter(Boolean);
    if (!names.length) return '';
    return `***Previous & alternate names: ${[...new Set(names)].map(escapeMarkdown).join(', ')}***\n\n`;
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
//
// The "**" markers on a line are paired in order, first with second, third with
// fourth. Matching spans with regexes instead paired the END of one span with
// the START of the next on a line holding two ("the **confirmedly abusive**
// [Program]" became "**confirmedly abusive ** [Program]"), which Reddit does not
// render as bold. A line with an odd number of markers, or an empty span, keeps
// its bold as it is. scripts/wiki-drafts.py bold_spacing() and
// kop_wiki_drafts_bold_spacing() (inc/wiki-update-drafts.php) do the same.
function normalizeBoldSpacing(md) {
    return String(md || '').split('\n').map((line) => {
        const parts = line.split('**');
        if (parts.length > 1 && parts.length % 2 === 1 && parts.every((p, k) => k % 2 === 0 || p.trim())) {
            for (let k = 1; k < parts.length; k += 2) {
                // padding inside the span: "** text **" -> "**text**"
                parts[k] = parts[k].replace(/^[ \t]+|[ \t]+$/g, '');
                // missing space BEFORE it: "at**Name**" -> "at **Name**"
                if (parts[k - 1] && !/[\s*|[("']$/.test(parts[k - 1])) parts[k - 1] += ' ';
                // missing space AFTER it: "**Name**text" -> "**Name** text"
                if (parts[k + 1] && !/^[\s*|).,;:!?'"\]]/.test(parts[k + 1])) parts[k + 1] = ' ' + parts[k + 1];
            }
            line = parts.join('**');
        }
        // inline italic jammed between letters: "by*many*survivors"
        return line.replace(/([A-Za-z])\*([A-Za-z][^*\n|]{0,60}?)\*([A-Za-z])/g, '$1 *$2* $3');
    }).join('\n');
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

// No space before . , ) ] that ends a word, nor before ; : ! ? after a link
// ("[Name](url) , which" -> "[Name](url), which"; a survivor's "HERE !" stays as
// written), and an empty date after a source goes ("(FOX 13 News, )" ->
// "(FOX 13 News)"). scripts/wiki-drafts.py punct_spacing() and
// kop_wiki_drafts_reddit_format() (inc/wiki-update-drafts.php) do the same.
function normalizePunctSpacing(md) {
    return String(md || '').split('\n').map((line) => line
        .replace(/(?<=\S) +(?=[.,)\]](?:\s|$|[.,;:!?)\]("'*]))/g, '')
        .replace(/(?<=\)) +(?=[;:!?](?:\s|$))/g, '')
        .replace(/,\)/g, ')')).join('\n');
}

// ---- Staff role sentences -------------------------------------------------
// The rules the wiki update drafts settled on (scripts/wiki-drafts.py role_phrase(),
// role_article(), also_roles(); owner, 2026-10-08): a title one person holds at a
// time takes "the" ("the Executive Director"), any other job "a"/"an" ("a
// therapist"); a department is "worked in admissions", never "was the Admissions";
// abbreviations are written out; "Former"/"current" never stay in the role (the
// verb says it); a year in the role only as a span ("from 2008 to 2010"), a lone
// year that only dates a source goes; staff of a closed program are "was".
const ONE_HOLDER_ROLE = /^(?!(assistant|associate|deputy|vice|co-?|former )\b)[^,]*\b(director|ceo|coo|cfo|cmo|cto|president|founder|owner|headmaster|headmistress|head of|principal|superintendent|administrator|chair(man|woman|person)?|chief|dean)\b/i;
const ROLE_ABBREVIATIONS = [
    [/\bexec\b\.?/gi, 'executive'], [/\basst\b\.?/gi, 'assistant'], [/\bdir\b\.?/gi, 'director'],
    [/\bbiz\.?\s*dev\b\.?/gi, 'business development'], [/\bmktg\b\.?/gi, 'marketing'], [/\bops\b/gi, 'operations'],
    [/\bVP\b/g, 'vice president'], [/\bspecial ed\b\.?/gi, 'special education']
];
const ROLE_DEPARTMENT = /^(?:admissions|marketing|facilities|maintenance|logistics|food services?|business development|enrollment(?: development)?|outreach|development|human resources|finance|accounting|intake|referral relations)(?:\s*(?:,|and|&|\/)\s*(?:admissions|marketing|facilities|maintenance|logistics|food services?|business development|enrollment(?: development)?|outreach|development|human resources|finance|accounting|intake|referral relations))*$/i;

function roleArticle(role) {
    if (ONE_HOLDER_ROLE.test(role)) return 'the';
    if (/^[A-Z]{2}/.test(role)) return /^[AEFHILMNORSX]/.test(role) ? 'an' : 'a';   // read by its letters: "an RN", "a CNA"
    return /^(?:[aeiou]|hono|hour)/i.test(role) && !/^(?:uni|use|eu|one)/i.test(role) ? 'an' : 'a';
}

// "Program / Clinical Director (2007-2010)" -> {phrase: "the program and clinical director", span: " from 2007 to 2010"}.
function staffRolePhrase(role) {
    let r = String(role || '').trim();
    let span = '';
    const range = r.match(/\s*(?:\(|\bin\s+|\bfrom\s+)?((?:19|20)\d\d)\s*[-–]\s*((?:19|20)\d\d|present)\)?/i);
    if (range) {
        span = /present/i.test(range[2]) ? ` from ${range[1]}` : ` from ${range[1]} to ${range[2]}`;
        r = r.replace(range[0], ' ');
    }
    r = r.replace(/\s*\((?:19|20)\d\d\)/g, ' ').replace(/\s+in\s+(?:19|20)\d\d\s*$/i, ' ');   // a lone year dates the source
    r = r.replace(/\b(?:former|previous|current|ex-)\s*/gi, ' ');
    ROLE_ABBREVIATIONS.forEach(([re, word]) => { r = r.replace(re, word); });
    r = r.replace(/\s*\/\s*/g, ' and ').replace(/\s+&\s+/g, ' and ').replace(/\s+/g, ' ').replace(/^[,\s]+|[,\s]+$/g, '');
    if (!r) return { phrase: '', span, worked: false };
    if (/^(?:the|a|an|one of|in)\b/i.test(r)) return { phrase: r, span, worked: false };
    if (ROLE_DEPARTMENT.test(r)) return { phrase: r.toLowerCase(), span, worked: true };
    // A role written in sentence case ("Adventure therapy coordinator") reads as words mid-sentence; a one-word job
    // many people hold too ("Teacher" -> "a teacher"); titles ("the Headmaster", "Family Teacher") stay as written.
    if (/^staff$/i.test(r)) r = 'staff member';
    else if (/^[A-Z][a-z]+(?: (?:[a-z][\w&/-]*|&))+$/.test(r)) r = r[0].toLowerCase() + r.slice(1);
    else if (/^[A-Z][a-z]+$/.test(r) && roleArticle(r) !== 'the') r = r.toLowerCase();
    return { phrase: `${roleArticle(r)} ${r}`, span, worked: false };
}

// "**Name** was a therapist from 2008 to 2010" (no full stop: the caller adds it).
function staffRoleClause(role, past) {
    const p = staffRolePhrase(role);
    if (!p.phrase) return '';
    if (p.worked) return `${past ? 'worked' : 'works'} in ${p.phrase}${p.span}`;
    return `${past ? 'was' : 'is'} ${p.phrase}${p.span}`;
}

// The same person's other roles at the program: "Roe was also the program director and a therapist. Roe also worked in admissions."
function staffExtraRolesSentence(name, roles, past) {
    const surname = String(name || '').trim().split(/\s+/).pop();
    const was = [], worked = [];
    (roles || []).forEach((r) => {
        const p = staffRolePhrase(r);
        if (p.phrase) (p.worked ? worked : was).push(p.phrase + p.span);
    });
    const join = (xs) => (xs.length < 3 ? xs.join(' and ') : `${xs.slice(0, -1).join(', ')} and ${xs[xs.length - 1]}`);
    return [was.length ? `${surname} ${past ? 'was' : 'is'} also ${join(was)}.` : '',
        worked.length ? `${surname} also ${past ? 'worked' : 'works'} in ${join(worked)}.` : ''].filter(Boolean).join(' ');
}

// A program whose years end in a year ("1994-2010", "2001-2005/2010") is closed: its staff are written in the past.
function isClosedYears(years) {
    const y = String(years || '').trim();
    return /(?:19|20)\d\d\s*$/.test(y) && !/present|current|ongoing|now\b/i.test(y);
}

// ---- One person, many names ------------------------------------------------
// Known other names (inc/name-variants.php: the people table's aliases and merges, the network map, the names checked
// by hand in js/data/people/name-variants-reviewed.json) and nicknames (js/data/people/nicknames.json) arrive as
// window.KOP_NAME_VARIANTS = {groups: [[name, ...]], distinct: [[a, b]], nicknames: {same: [[...]], maybe: [[...]]}}.
// samePerson(): one key, one known group, or a first name that can only be the other's ("Charlie"/"Charles") with
// the same last name; never a "distinct" pair. possibleSamePerson(): what to ask about instead of merging.

// Case, punctuation and a title in front ignored: the key inc/name-variants.php kop_name_variants_key() writes.
function staffNameKey(name) {
    return String(name || '').toLowerCase().replace(/\b(?:dr|rabbi|rev|mr|mrs|ms|miss)\.?\s+/g, '')
        .replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ').trim();
}

let NAME_VARIANT_INDEX = null;

// For the tests, or a page that loads the data later.
function setNameVariants(data) {
    NAME_VARIANT_INDEX = null;
    if (typeof window !== 'undefined') window.KOP_NAME_VARIANTS = data;
    else setNameVariants.data = data;
}

function nameVariantIndex() {
    if (NAME_VARIANT_INDEX) return NAME_VARIANT_INDEX;
    let data = (typeof window !== 'undefined' && window.KOP_NAME_VARIANTS) || setNameVariants.data || null;
    if (!data && typeof require === 'function') {
        try {   // node (tests, batch import): the files themselves; the people table's groups need the site
            const nick = require('./data/people/nicknames.json');
            const reviewed = require('./data/people/name-variants-reviewed.json');
            data = { groups: (reviewed.same || []).map(g => g.names), distinct: (reviewed.distinct || []).map(d => d.names), nicknames: nick };
        } catch (e) { data = null; }
    }
    data = data || {};
    const idx = { same: new Map(), maybe: new Map(), group: new Map(), distinct: new Set() };
    ((data.nicknames || {}).same || []).forEach((g) => g.forEach((n) => { if (!idx.same.has(n)) idx.same.set(n, g[0]); }));
    ((data.nicknames || {}).maybe || []).forEach((g, i) => g.forEach((n) => {
        if (!idx.maybe.has(n)) idx.maybe.set(n, new Set());
        idx.maybe.get(n).add(i);
    }));
    NAME_VARIANT_INDEX = idx;   // personKey() below reads idx.same
    (data.groups || []).forEach((g, i) => g.forEach((n) => {
        [staffNameKey(n), personKey(n)].forEach((k) => { if (k && !idx.group.has(k)) idx.group.set(k, i); });
    }));
    (data.distinct || []).forEach(([a, b]) => {
        [[staffNameKey(a), staffNameKey(b)], [personKey(a), personKey(b)]].forEach(([x, y]) => {
            if (x && y) idx.distinct.add([x, y].sort().join('|'));
        });
    });
    return idx;
}

// A name's words: titles, credentials, "Jr.", initials and quoted nicknames gone; a hyphen splits ("Quinney-Packard").
function personTokens(name) {
    const plain = String(name || '').replace(/["“”][^"“”]*["“”]|\([^)]*\)/g, ' ').replace(/,.*$/, '').replace(/-/g, ' ');
    const drop = /^(?:jr|sr|ii|iii|iv|phd|md|psyd|lcsw|lpc|lmft|rn|ma|ms|msw|edd|med|lmhc|lcpc|cmhc|ncc)$/;
    return staffNameKey(plain).split(' ').filter(t => t.length > 1 && !drop.test(t));
}

// "first last" with the first name folded to the one name it can be ("Charlie Smith" -> "charles smith").
function personKey(name) {
    const t = personTokens(name);
    if (t.length < 2) return '';
    const idx = NAME_VARIANT_INDEX || nameVariantIndex();
    return `${idx.same.get(t[0]) || t[0]} ${t[t.length - 1]}`;
}

function isDistinctPair(a, b) {
    const idx = nameVariantIndex();
    return [[staffNameKey(a), staffNameKey(b)], [personKey(a), personKey(b)]]
        .some(([x, y]) => x && y && idx.distinct.has([x, y].sort().join('|')));
}

function samePerson(a, b) {
    const ka = staffNameKey(a), kb = staffNameKey(b);
    if (!ka || !kb || isDistinctPair(a, b)) return false;
    if (ka === kb) return true;
    const idx = nameVariantIndex();
    const pa = personKey(a), pb = personKey(b);
    const ga = idx.group.has(ka) ? idx.group.get(ka) : idx.group.get(pa);
    const gb = idx.group.has(kb) ? idx.group.get(kb) : idx.group.get(pb);
    if (ga !== undefined && ga === gb) return true;
    return Boolean(pa) && pa === pb;
}

function editDistance(a, b) {
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 1; j <= b.length; j++) d[0][j] = j;
    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
        }
    }
    return d[a.length][b.length];
}

// Why two names might be one person ('' when nothing suggests it): Merge People's tests (inc/people-merge.php) plus
// the nickname table's "maybe" names.
function possibleSamePerson(a, b) {
    if (samePerson(a, b) || isDistinctPair(a, b)) return '';
    const ta = personTokens(a), tb = personTokens(b);
    if (ta.length < 2 || tb.length < 2) return '';
    const idx = nameVariantIndex();
    const fa = ta[0], fb = tb[0], la = ta[ta.length - 1], lb = tb[tb.length - 1];
    const fold = (f) => idx.same.get(f) || f;
    const firstSame = fold(fa) === fold(fb);
    const firstMaybe = [...(idx.maybe.get(fa) || [])].some(i => (idx.maybe.get(fb) || new Set()).has(i));
    const firstShort = fa[0] === fb[0] && Math.min(fa.length, fb.length) >= 3 && (fa.startsWith(fb) || fb.startsWith(fa));
    const lastSpelling = la !== lb && editDistance(la, lb) <= (Math.min(la.length, lb.length) >= 8 ? 2 : 1);
    if (la === lb && (firstMaybe || firstShort) && !firstSame) return `"${a.trim()}" may be a nickname or short form of "${b.trim()}"`;
    if ((firstSame || firstMaybe) && lastSpelling) return `the last names are spelled one letter apart`;
    if ((firstSame || firstMaybe) && la !== lb && (ta.slice(1).includes(lb) || tb.slice(1).includes(la))) {
        return 'one may be a maiden or married name';
    }
    if (fa === lb && la === fb) return 'first and last names swapped';
    if (la === lb && !firstSame && fa !== fb && editDistance(fa, fb) === 1 && Math.min(fa.length, fb.length) >= 4) return 'the first names are spelled one letter apart';
    return '';
}

// Names that open a staff paragraph in imported text ("**Jane Roe** was ...").
function staffNamesInText(md) {
    const out = [];
    String(md || '').split('\n').forEach((line) => {
        const m = line.match(/^\s*(?:[-*]\s+)?\*\*\s*([^*\n]{3,80}?)\s*\*\*/);
        if (m && !/[:\d]/.test(m[1]) && /\s/.test(m[1].trim())) out.push(m[1].trim());
    });
    return out;
}

// Every pair among the names that is one person ("same": merged on the page) or might be ("maybe": a question).
function findStaffNameMatches(names) {
    const list = [...new Set((names || []).map(n => String(n || '').trim()).filter(Boolean))];
    const out = [];
    for (let i = 0; i < list.length; i++) {
        for (let j = i + 1; j < list.length; j++) {
            if (staffNameKey(list[i]) === staffNameKey(list[j])) continue;
            if (samePerson(list[i], list[j])) out.push({ a: list[i], b: list[j], kind: 'same', reason: 'known to be the same person' });
            else {
                const why = possibleSamePerson(list[i], list[j]);
                if (why) out.push({ a: list[i], b: list[j], kind: 'maybe', reason: why });
            }
        }
    }
    return out;
}

// One paragraph per person: staff entries naming the same person (samePerson()) become one, the first keeping its
// name and role and the others' roles listed after it.
function mergeStaffByName(list) {
    const out = [];
    (list || []).forEach((s) => {
        if (!s) return;
        const first = s.name ? out.find(o => o.name && samePerson(o.name, s.name)) : null;
        if (!first) {
            out.push({ ...s, extraRoles: [] });
            return;
        }
        if (s.role && staffNameKey(s.role) !== staffNameKey(first.role)) first.extraRoles.push(s.role);
        if (s.bio && !String(first.bio || '').includes(String(s.bio).trim())) first.bio = [first.bio, s.bio].filter(Boolean).join(' ');
        if (s.previousRoles && s.previousRoles.length) first.previousRoles = [...(first.previousRoles || []), ...s.previousRoles];
    });
    return out;
}

function getPlaceholder(category, programName) {
    const name = programName || '[Program Name]';
    const lowerCategory = (category || '').toLowerCase();
    const placeholderByCategory = [
        {
            match: ['history', 'background'],
            text: `Background information for ${name} has not been added yet. If you have reliable historical details or sources to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['founders', 'staff'],
            text: `Information about the founders or notable staff at ${name} has not been added yet. If you have reliable names, roles, or source material to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['structure'],
            text: `Information about the program structure at ${name} has not been added yet. If you have reliable descriptions or source material to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['rules', 'punishments'],
            text: `Information about the rules, consequences, or disciplinary practices at ${name} has not been added yet. If you have reliable source material to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['abuse', 'neglect', 'lawsuits'],
            text: `Information about abuse allegations, neglect, or lawsuits involving ${name} has not been added yet. If you have reliable reports or source material to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['survivor testimonies', 'survivor testimony', 'testimonies', 'testimonials'],
            text: `No survivor testimonies for ${name} have been added here yet. If you have a firsthand account or reliable source material to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['related media'],
            text: `No related media links for ${name} have been added yet. If you have reliable external resources to share, please contact ${CONTACT_LINK}.`
        },
        {
            match: ['related programs', 'affiliated programs'],
            text: `Programs associated with ${name} have not been added yet. If you have reliable information about operated, affiliated, or successor programs to share, please contact ${CONTACT_LINK}.`
        }
    ];

    const categoryPlaceholder = placeholderByCategory.find((entry) =>
        entry.match.some((term) => lowerCategory.includes(term))
    );

    if (categoryPlaceholder) {
        return categoryPlaceholder.text;
    }

    if (lowerCategory.includes('media')) {
        return `No media coverage for ${name} has been added yet. If you have seen a news item about ${name} and would like to share it, please contact ${CONTACT_LINK}.`;
    }

    return `Additional information about ${name} has not been added yet. If you have reliable updates or references to share, please contact ${CONTACT_LINK}.`;
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
    module.exports = { generateWikiMarkdown, sanitizeUrl, normalizeContactTag, normalizePunctSpacing, staffRoleClause, mergeStaffByName, isClosedYears,
        setNameVariants, samePerson, possibleSamePerson, findStaffNameMatches, staffNamesInText };
} else if (typeof window !== 'undefined') {
    window.generateWikiMarkdown = generateWikiMarkdown;
    window.sanitizeUrlForWiki = sanitizeUrl;
    window.normalizeContactTag = normalizeContactTag;
    // The editor's staff check (js/wiki-editor.js renderStaffNameCheck()).
    window.kopStaffNames = { findStaffNameMatches, staffNamesInText, samePerson };
}
