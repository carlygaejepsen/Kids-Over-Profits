document.addEventListener('DOMContentLoaded', () => {
    const wikiForm = document.getElementById('wikiForm');

    if (!wikiForm) {
        console.warn('Wiki Editor: #wikiForm not found, skipping initialization.');
        return;
    }

    // --- Data Storage ---
    let staffMembers = [];
    let programLevels = [];
    let newsArticles = [];
    let testimonies = [];
    let relatedMedia = [];

    // --- Initialize empty list output containers ---
    const initializeEmptyLists = () => {
        const emptyHtml = '<p style="color:#999;">No items added yet</p>';
        const listIds = ['staffListOutput', 'levelListOutput', 'articleListOutput', 'testimonyListOutput', 'mediaListOutput'];
        listIds.forEach(id => {
            const element = document.getElementById(id);
            if (element && !element.innerHTML.trim()) {
                element.innerHTML = emptyHtml;
            }
        });
    };
    initializeEmptyLists();

    // --- Helper: Get Placeholder Text ---
    const getPlaceholder = (category, programName) => {
        const name = programName || '[Program Name]';
        return `No information is known about ${category} at ${name}. If you attended ${name} and would like to contribute information to help complete this page, please contact u/Signal-Strain8910.`;
    };

    // --- Helper: Render a Preview List ---
    const renderList = (array, outputElement, renderer) => {
        const outputDiv = document.getElementById(outputElement);
        if (!outputDiv) {
            console.warn(`Wiki Editor: #${outputElement} not found.`);
            return;
        }

        outputDiv.innerHTML = '';
        array.forEach((item, index) => {
            const el = document.createElement('div');
            el.className = 'list-preview-item';
            el.innerHTML = renderer(item);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.innerHTML = '✕ Remove';
            removeBtn.setAttribute('aria-label', `Remove item ${index + 1}`);
            removeBtn.onclick = (e) => {
                e.preventDefault();
                array.splice(index, 1);
                renderList(array, outputElement, renderer);
            };
            el.appendChild(removeBtn);
            outputDiv.appendChild(el);
        });
    };

    // --- Helper: Clear Input Fields ---
    const clearInputs = (ids) => {
        ids.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.value = '';
            }
        });
    };

    // --- Helper: Validate Required Fields ---
    const validateRequired = (fieldIds, errorMsg) => {
        const values = fieldIds.map(id => document.getElementById(id)?.value.trim() || '');
        if (values.some(v => !v)) {
            alert(errorMsg);
            return false;
        }
        return true;
    };

    // --- Add Button: Staff ---
    const addStaffBtn = document.getElementById('addStaffBtn');
    if (addStaffBtn) {
        addStaffBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('staffName').value.trim();
            const role = document.getElementById('staffRole').value.trim();
            const previousRolesRaw = document.getElementById('staffPreviousRoles').value.trim();
            const bio = document.getElementById('staffBio').value.trim();
            if (name && role) {
                const previousRoles = previousRolesRaw
                    ? previousRolesRaw.split('\n').map(r => r.trim()).filter(Boolean)
                    : [];
                staffMembers.push({ name, role, bio, previousRoles });
                renderList(staffMembers, 'staffListOutput', item => {
                    const previous = item.previousRoles && item.previousRoles.length
                        ? `<div class="staff-prev">Prev: ${escapeHtml(item.previousRoles.join('; '))}</div>`
                        : '';
                    return `<strong>${escapeHtml(item.name)}</strong> — ${escapeHtml(item.role)}${previous}`;
                });
                clearInputs(['staffName', 'staffRole', 'staffBio', 'staffPreviousRoles']);
            } else {
                alert('Please enter at least a name and role.');
            }
        });
    }

    // --- Add Button: Levels ---
    const addLevelBtn = document.getElementById('addLevelBtn');
    if (addLevelBtn) {
        addLevelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = document.getElementById('levelName').value.trim();
            const desc = document.getElementById('levelDesc').value.trim();
            if (name && desc) {
                programLevels.push({ name, desc });
                renderList(programLevels, 'levelListOutput', item => `<strong>"${escapeHtml(item.name)}"</strong>`);
                clearInputs(['levelName', 'levelDesc']);
            } else {
                alert('Please enter a level name and description.');
            }
        });
    }

    // --- Add Button: Articles ---
    const addArticleBtn = document.getElementById('addArticleBtn');
    if (addArticleBtn) {
        addArticleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const title = document.getElementById('articleTitle').value.trim();
            const url = document.getElementById('articleUrl').value.trim();
            const source = document.getElementById('articleSource').value.trim();
            const date = document.getElementById('articleDate').value.trim();
            if (title && url) {
                newsArticles.push({ title, url, source, date });
                renderList(newsArticles, 'articleListOutput', item => `<strong>${escapeHtml(item.title)}</strong> (${escapeHtml(item.source || 'No Source')})`);
                clearInputs(['articleTitle', 'articleUrl', 'articleSource', 'articleDate']);
            } else {
                alert('Please enter at least a title and URL.');
            }
        });
    }

    // --- Add Button: Testimonies ---
    const addTestimonyBtn = document.getElementById('addTestimonyBtn');
    if (addTestimonyBtn) {
        addTestimonyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const date = document.getElementById('testimonyDate').value.trim();
            const type = document.getElementById('testimonyType').value;
            const quote = document.getElementById('testimonyQuote').value.trim();
            const source = document.getElementById('testimonySource').value.trim();
            const url = document.getElementById('testimonyUrl').value.trim();
            if (quote && source && url) {
                testimonies.push({ date, type, quote, source, url });
                renderList(testimonies, 'testimonyListOutput', item => `<strong>(${escapeHtml(item.type)})</strong> ${escapeHtml(item.quote.substring(0, 30))}... [${escapeHtml(item.source)}]`);
                clearInputs(['testimonyDate', 'testimonyQuote', 'testimonySource', 'testimonyUrl']);
            } else {
                alert('Please enter at least a quote, source name, and source URL.');
            }
        });
    }

    // --- Add Button: Related Media ---
    const addMediaBtn = document.getElementById('addMediaBtn');
    if (addMediaBtn) {
        addMediaBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const title = document.getElementById('mediaTitle').value.trim();
            const url = document.getElementById('mediaUrl').value.trim();
            if (title && url) {
                relatedMedia.push({ title, url });
                renderList(relatedMedia, 'mediaListOutput', item => `[${escapeHtml(item.title)}]`);
                clearInputs(['mediaTitle', 'mediaUrl']);
            } else {
                alert('Please enter a title and URL.');
            }
        });
    }

    // --- MAIN GENERATE BUTTON ---
    const generateBtn = document.getElementById('generateBtn');
    if (generateBtn) {
        generateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // --- Get All Single-Field Values ---
            const vals = {};
            const inputs = document.querySelectorAll('#wikiForm input[type="text"], #wikiForm textarea, #wikiForm select');
            inputs.forEach(input => {
                if (input.id && !input.closest('.form-adder')) {
                    vals[input.id] = input.value.trim();
                }
            });

            const programName = vals.programName || '[Program Name]';

            // --- Helper: Create Link ---
            const createLink = (text, url) => {
                if (!text) return '';
                if (!url) return escapeMarkdown(text);
                return `[${escapeMarkdown(text)}](${url})`;
            };

            // --- Helper: Process Simple List from Textarea ---
            const processSimpleList = (text) => {
                if (!text.trim()) return '';
                return text.split('\n')
                    .filter(line => line.trim() !== '')
                    .map(line => `* ${escapeMarkdown(line.trim())}`)
                    .join('\n');
            };

            // --- Helper: Join With "and" for natural sentences ---
            const joinWithAnd = (items) => {
                if (items.length === 1) return items[0];
                if (items.length === 2) return `${items[0]} and ${items[1]}`;
                return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
            };

            // --- Helper: Ensure trailing period for fragments ---
            const ensureSentence = (text) => {
                const trimmed = (text || '').trim();
                if (!trimmed) return '';
                return /[.!?]"?$/.test(trimmed) ? trimmed : `${trimmed}.`;
            };

            const roleVerb = (roleText) => {
                const value = (roleText || '').toLowerCase();
                return /\b(former|previous|ex[\s-])/.test(value) ? 'was' : 'is';
            };

            // --- Build History Section ---
            let historySection = '';
            const historySentences = [];

            const descriptorParts = [];
            if (vals.programType) descriptorParts.push(`a ${escapeMarkdown(vals.programType)}`);
            if (vals.yearFounded) descriptorParts.push(`founded in ${escapeMarkdown(vals.yearFounded)}`);
            if (vals.cityState) descriptorParts.push(`based in ${escapeMarkdown(vals.cityState)}`);
            if (descriptorParts.length > 0) {
                historySentences.push(`${escapeMarkdown(programName)} is ${joinWithAnd(descriptorParts)}.`);
            }

            if (vals.ownerName) {
                historySentences.push(`${escapeMarkdown(programName)} is owned by ${createLink(vals.ownerName, vals.ownerLink)}.`);
            }

            const audienceParts = [];
            if (vals.ageRange) audienceParts.push(`serves young people aged ${escapeMarkdown(vals.ageRange)}`);
            if (vals.diagnosesList) {
                const diagnoses = vals.diagnosesList.split(',')
                    .map(item => item.trim())
                    .filter(Boolean)
                    .map(item => `"${escapeMarkdown(item)}"`).join(', ');
                if (diagnoses) {
                    audienceParts.push(`lists ${diagnoses} as target diagnoses or behaviors`);
                }
            }
            if (audienceParts.length > 0) {
                historySentences.push(`The program ${joinWithAnd(audienceParts)}.`);
            }

            const operationsParts = [];
            if (vals.avgStay) operationsParts.push(`reports an average length of stay of around ${escapeMarkdown(vals.avgStay)}`);
            if (vals.tuition) operationsParts.push(`reports tuition of ${escapeMarkdown(vals.tuition)}`);
            if (vals.natsapStatus) operationsParts.push(escapeMarkdown(vals.natsapStatus));
            if (operationsParts.length > 0) {
                historySentences.push(`It ${joinWithAnd(operationsParts)}.`);
            }

            if (vals.mainAddress) {
                historySentences.push(`The main office is located at ${createLink(vals.mainAddress, vals.addressLink)}.`);
            }

            if (vals.accreditingBody) {
                historySentences.push(`The program is accredited by the ${createLink(vals.accreditingBody, vals.accreditingBodyLink)}.`);
            }

            if (vals.historyMisc) historySentences.push(vals.historyMisc);
            historySection = historySentences.length > 0 ? historySentences.join('\n\n') : getPlaceholder('History and Background Information', programName);

            // --- Build Staff Section ---
            let staffSection;
            if (staffMembers.length > 0) {
                staffSection = staffMembers.map(s => {
                    const roleText = escapeMarkdown(s.role);
                    const articleRole = roleText.toLowerCase().startsWith('the ') ? roleText : `the ${roleText}`;
                    const roleSentence = ensureSentence(`**${escapeMarkdown(s.name)}** ${roleVerb(roleText)} ${articleRole}`);
                    const previousSentence = (s.previousRoles && s.previousRoles.length)
                        ? ensureSentence(`Previously worked at ${joinWithAnd(s.previousRoles.map(pr => escapeMarkdown(pr)))}`)
                        : '';
                    const bioSentence = ensureSentence(escapeMarkdown(s.bio || ''));
                    return [roleSentence, previousSentence, bioSentence].filter(Boolean).join(' ');
                }).join('\n\n');
            } else {
                staffSection = getPlaceholder('Founders and Notable Staff', programName);
            }

            // --- Build Structure Section ---
            let structureSection = '';
            const structureParts = [];
            if (vals.levelSystemDesc) {
                structureParts.push(`Like other behavior-modification programs, ${escapeMarkdown(programName)} uses ${escapeMarkdown(vals.levelSystemDesc)}. The levels are:`);
            }
            const levelList = programLevels.length > 0
                ? programLevels.map(l => `- ${escapeMarkdown(l.name)}${l.desc ? ` — ${escapeMarkdown(l.desc)}` : ''}`).join('\n')
                : '';
            if (levelList) structureParts.push(levelList);
            if (vals.structureMisc) structureParts.push(vals.structureMisc);
            structureSection = structureParts.length > 0 ? structureParts.join('\n\n') : getPlaceholder('Program Structure', programName);

            // --- Build Rules & Punishments Section ---
            let rulesSection = '';
            const rulesParts = [];
            const rulesList = processSimpleList(vals.rulesList);
            if (rulesList) {
                rulesParts.push(`${escapeMarkdown(programName)} is a very strict program with many rules. Some of these rules include:\n\n${rulesList.replace(/^\*/gm, '-')}`);
            }
            if (vals.punishmentsDesc) {
                rulesParts.push(vals.punishmentsDesc);
            }
            rulesSection = rulesParts.length > 0 ? rulesParts.join('\n\n') : getPlaceholder('Rules and Punishments', programName);

            // --- Build Abuse Section ---
            let abuseSection = '';
            const abuseParts = [];
            if (vals.mainComplaints) {
                abuseParts.push(`Many survivors have reported that abuse and neglect have occurred at ${escapeMarkdown(programName)}. The main complaints are of ${vals.mainComplaints}.`);
            }
            const otherAllegationsList = processSimpleList(vals.otherAllegationsList);
            if (otherAllegationsList) {
                abuseParts.push(`Other allegations of abuse and neglect which have been reported by survivors include but are not limited to:\n\n${otherAllegationsList.replace(/^\*/gm, '-')}`);
            }
            if (vals.lawsuits) {
                abuseParts.push(vals.lawsuits);
            }
            abuseSection = abuseParts.length > 0 ? abuseParts.join('\n\n') : getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);

            // --- Build Media Section (Combined) ---
            const mediaList = processSimpleList(vals.mediaInfo).replace(/^\*/gm, '-');
            const newsList = newsArticles.map(a => {
                let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
                if (sourceDate) sourceDate = ` (${sourceDate})`;
                return `- [${escapeMarkdown(a.title)}](${a.url})${sourceDate}`;
            }).join('\n');
            const combinedMedia = [mediaList, newsList].filter(Boolean).join('\n\n');
            const mediaSection = combinedMedia || getPlaceholder('In the Media', programName);

            // --- Build Testimonies Section ---
            let testimoniesSection;
            if (testimonies.length > 0) {
                testimoniesSection = testimonies.map(t => {
                    const datePart = t.date ? `${t.date}: ` : '';
                    return `**${datePart}(${t.type})** "${escapeMarkdown(t.quote)}" - [${escapeMarkdown(t.source)}](${t.url})`;
                }).join('\n\n');
            } else {
                testimoniesSection = getPlaceholder('Survivor Testimonies', programName);
            }

            // --- Build Related Media Section ---
            let relatedMediaSection;
            if (relatedMedia.length > 0) {
                relatedMediaSection = relatedMedia.map(m => `- [${escapeMarkdown(m.title)}](${m.url})`).join('\n\n');
            } else {
                relatedMediaSection = getPlaceholder('Related Media', programName);
            }

            const headerLine = `#**${escapeMarkdown(programName)}** (${vals.yearsActive || '[Years Active]'}) ${vals.cityState || '[City, ST]'}`;
            const sectionBreak = '\n***\n\n';

            // --- Assemble Final Output ---
            const output = `
${headerLine}
*${vals.programType || '[Program Type]'}*

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

##**Related Media**

${relatedMediaSection}
            `;

            const outputCode = document.getElementById('outputCode');
            if (outputCode) {
                outputCode.value = output.trim();
                outputCode.focus();
                outputCode.select();
            }
        });
    }

    // --- Copy Button ---
    const copyBtn = document.getElementById('copyBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const outputCode = document.getElementById('outputCode');
            if (!outputCode || !outputCode.value) {
                alert('Please generate the code first!');
                return;
            }
            outputCode.select();
            outputCode.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(outputCode.value).then(() => {
                const originalText = copyBtn.textContent;
                copyBtn.textContent = '✓ Copied!';
                copyBtn.style.backgroundColor = '#B6E3D4';
                setTimeout(() => {
                    copyBtn.textContent = originalText;
                    copyBtn.style.backgroundColor = '';
                }, 2000);
            }).catch((err) => {
                console.warn('Clipboard API failed, using fallback:', err);
                // Fallback: use old selection method
                try {
                    outputCode.focus();
                    outputCode.select();
                    if (document.execCommand('copy')) {
                        const originalText = copyBtn.textContent;
                        copyBtn.textContent = '✓ Copied!';
                        copyBtn.style.backgroundColor = '#B6E3D4';
                        setTimeout(() => {
                            copyBtn.textContent = originalText;
                            copyBtn.style.backgroundColor = '';
                        }, 2000);
                    } else {
                        alert('Copy failed. Please select and copy manually.');
                    }
                } catch (fallbackErr) {
                    console.error('Fallback copy failed:', fallbackErr);
                    alert('Copy failed. Please select and copy manually.');
                }
            });
        });
    }

    // --- IMPORT FUNCTIONALITY ---
    const toggleImportBtn = document.getElementById('toggleImportBtn');
    const importPanel = document.getElementById('importPanel');
    const importBtn = document.getElementById('importBtn');
    const cancelImportBtn = document.getElementById('cancelImportBtn');
    const importTextarea = document.getElementById('importTextarea');

    // Toggle import panel
    if (toggleImportBtn && importPanel) {
        toggleImportBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const computedStyle = window.getComputedStyle(importPanel);
            const isHidden = computedStyle.display === 'none' || importPanel.style.display === 'none';
            importPanel.style.display = isHidden ? 'block' : 'none';
            toggleImportBtn.textContent = isHidden ? '✖️ Close Import' : '📥 Import from Reddit Markdown';
        });
    }

    // Cancel import
    if (cancelImportBtn && importPanel) {
        cancelImportBtn.addEventListener('click', (e) => {
            e.preventDefault();
            importPanel.style.display = 'none';
            if (toggleImportBtn) toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
            if (importTextarea) importTextarea.value = '';
        });
    }

    // Import and parse Reddit markdown
    if (importBtn) {
        importBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const markdown = importTextarea ? importTextarea.value.trim() : '';
            if (!markdown) {
                alert('Please paste some Reddit markdown first!');
                return;
            }

            console.log('=== IMPORT DEBUG ===');
            console.log('Markdown length:', markdown.length);
            console.log('First 200 characters:', markdown.substring(0, 200));

            try {
                parseAndPopulate(markdown);
                alert('Import successful! Form fields have been populated. Review and edit as needed.');
                if (importPanel) importPanel.style.display = 'none';
                if (toggleImportBtn) toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
                if (importTextarea) importTextarea.value = '';
            } catch (error) {
                console.error('Import error:', error);
                alert('Error importing markdown. Please check the format and try again.\n\n' + error.message);
            }
        });
    }

    // --- PARSER FUNCTION ---
    function parseAndPopulate(markdown) {
        console.log('parseAndPopulate called');
        staffMembers = [];
        programLevels = [];
        newsArticles = [];
        testimonies = [];
        relatedMedia = [];

        // Helper to safely set element values
        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) {
                el.value = value;
                console.log(`Set ${id} = "${value}"`);
            } else {
                console.warn(`Element not found: ${id}`);
            }
        };

        const escapeRegex = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        const getSection = (markdown, sectionTitle) => {
            const regex = new RegExp(`^#{1,6}\\s*\\**${escapeRegex(sectionTitle)}\\**\\s*\\n([\\s\\S]*?)(?=^#{1,6}\\s*\\**[^\\n]+\\**\\s*$|\\z)`, 'im');
            const match = markdown.match(regex);
            const result = match ? match[1].trim() : '';
            console.log(`getSection("${sectionTitle}"):`, result ? `Found (${result.length} chars)` : 'Not found');
            return result;
        };

        const getSectionAny = (markdown, titles) => {
            for (const title of titles) {
                const section = getSection(markdown, title);
                if (section) return section;
            }
            return '';
        };

        // Parse header
        const headerMatch = markdown.match(/^#{1,3}\s*\**(.+?)\**\s*\(([^)]+)\)\s+(.+)$/m);
        console.log('Header match:', headerMatch);
        if (headerMatch) {
            setValue('programName', headerMatch[1].trim());
            setValue('yearsActive', headerMatch[2].trim());
            setValue('cityState', headerMatch[3].trim());
        } else {
            console.warn('No header match found. Looking for pattern: ## ProgramName (Years) City, ST');
        }

        // Parse program type
        const typeMatch = markdown.match(/^\s*\*([^*]+)\*\s*$/m);
        if (typeMatch) {
            setValue('programType', typeMatch[1].trim());
        }

        // Parse History section
        const historySection = getSection(markdown, 'History and Background Information');
        if (historySection) {
            const yearFoundedMatch = historySection.match(/founded in (\d{4})/i);
            if (yearFoundedMatch) {
                const yearEl = document.getElementById('yearFounded');
                if (yearEl) yearEl.value = yearFoundedMatch[1];
            }

            // Extract owner
            const ownerMatch = historySection.match(/owned by \[([^\]]+)\]\(([^)]+)\)/i);
            if (ownerMatch) {
                const ownerNameEl = document.getElementById('ownerName');
                const ownerLinkEl = document.getElementById('ownerLink');
                if (ownerNameEl) ownerNameEl.value = ownerMatch[1];
                if (ownerLinkEl) ownerLinkEl.value = ownerMatch[2];
            } else {
                const simpleOwnerMatch = historySection.match(/owned by ([^\.\n]+)/i);
                if (simpleOwnerMatch) {
                    setValue('ownerName', simpleOwnerMatch[1].trim());
                }
            }

            // Extract age range
            const ageMatch = historySection.match(/aged (\d+-\d+)/i);
            if (ageMatch) {
                setValue('ageRange', ageMatch[1]);
            }

            // Extract diagnoses
            const diagnosesMatch = historySection.match(/diagnoses\/behaviors:\s*([^\n]+)/i);
            if (diagnosesMatch) {
                const diagnoses = diagnosesMatch[1]
                    .replace(/"/g, '')
                    .replace(/\./g, '')
                    .trim();
                setValue('diagnosesList', diagnoses);
            }

            // Extract average stay
            const stayMatch = historySection.match(/average length of stay[^0-9]*(\d+[^,\.\n]+)/i);
            if (stayMatch) {
                setValue('avgStay', stayMatch[1].trim());
            }

            // Extract tuition
            const tuitionMatch = historySection.match(/tuition[^$]*(\$[^\.\n]+)/i);
            if (tuitionMatch) {
                setValue('tuition', tuitionMatch[1].trim());
            }

            // Extract NATSAP status
            const natsapMatch = historySection.match(/NATSAP[^\.\n]+/i);
            if (natsapMatch) {
                setValue('natsapStatus', natsapMatch[0].replace(/^.*?\s/, ''));
            }

            // Extract address (allow variations like "located at/as/in")
            const addressMatch = historySection.match(/located\s+(?:at|as|in)\s+\[([^\]]+)\]\(([^)]+)\)/i);
            if (addressMatch) {
                setValue('mainAddress', addressMatch[1]);
                setValue('addressLink', addressMatch[2]);
            } else {
                const addressTextMatch = historySection.match(/located\s+(?:at|as|in)\s+([^.\n]+)/i);
                if (addressTextMatch) {
                    setValue('mainAddress', addressTextMatch[1].trim());
                }
            }

            // Extract accrediting body
            const accreditMatch = historySection.match(/accredited through the \[([^\]]+)\]\(([^)]+)\)/i);
            if (accreditMatch) {
                setValue('accreditingBody', accreditMatch[1]);
                setValue('accreditingBodyLink', accreditMatch[2]);
            }

            // Extract misc history (everything that doesn't match specific patterns)
            const historyLines = historySection.split('\n\n');
            const miscLines = historyLines.filter(line => {
                return !line.match(/founded in|owned by|aged|diagnoses|length of stay|tuition|NATSAP|located at|accredited through/i);
            });
            if (miscLines.length > 0) {
                setValue('historyMisc', miscLines.join('\n\n'));
            }
        }

        // Parse Staff section
        const staffSection = getSectionAny(markdown, [
            'Founders and Notable Staff',
            'Founders & Notable Staff',
            'Notable Staff',
            'Founders'
        ]);
        if (staffSection && !staffSection.includes('No information is known')) {
            const addStaff = (name, role, bio) => {
                if (!name || !role) return;
                staffMembers.push({
                    name: name.trim(),
                    role: role.trim(),
                    bio: (bio || '').trim()
                });
            };

            const staffLines = staffSection.split('\n').filter(line =>
                line.trim().startsWith('*') || line.trim().startsWith('-')
            );
            staffLines.forEach(line => {
                const match = line.match(/^[*-]\s+\*\*([^*]+)\*\*\s+(?:is|was)\s+(?:the\s+)?([^\.]+)\.\s*(.*)/i);
                if (match) {
                    addStaff(match[1], match[2], match[3]);
                }
            });

            // Paragraph or line blocks starting with the name in bold
            const staffParagraphs = staffSection.split(/\n\n+/).filter(p => p.trim().startsWith('**'));
            staffParagraphs.forEach(block => {
                const normalized = block.replace(/^[*-]\s*/, '').trim();
                let match = normalized.match(/^\*\*([^*]+)\*\*\s+(?:is|was)\s+(?:the\s+)?([^\.]+)\.\s*(.*)$/is);
                if (match) {
                    addStaff(match[1], match[2], match[3]);
                } else {
                    // Fallback: capture role until first sentence end
                    match = normalized.match(/^\*\*([^*]+)\*\*\s+([^\.]+)\.\s*(.*)$/is);
                    if (match) {
                        addStaff(match[1], match[2], match[3]);
                    }
                }
            });

            renderList(staffMembers, 'staffListOutput', item => `<strong>${item.name}</strong> - ${item.role}`);
        }

        // Parse Structure section
        const structureSection = getSection(markdown, 'Program Structure');
        if (structureSection && !structureSection.includes('No information is known')) {
            // Extract level system description
            const levelDescMatch = structureSection.match(/uses ([^\.]+level[^\.]+)/i);
            if (levelDescMatch) {
                setValue('levelSystemDesc', levelDescMatch[1].trim());
            }

            // Extract individual levels
            const levelMatches = structureSection.matchAll(/\*\s+\*\*"([^"]+)"\*\*\s*-\s*([^\n*]+)/g);
            for (const match of levelMatches) {
                programLevels.push({
                    name: match[1].trim(),
                    desc: match[2].trim()
                });
            }
            renderList(programLevels, 'levelListOutput', item => `<strong>"${item.name}"</strong>`);

            // Extract misc structure info
            const structureLines = structureSection.split('\n\n');
            const miscStructure = structureLines.filter(line =>
                !line.includes('uses a level') && !line.includes('The levels are:') && !line.trim().startsWith('*')
            ).join('\n\n');
            if (miscStructure) {
                setValue('structureMisc', miscStructure.trim());
            }
        }

        // Parse Rules section
        const rulesSection = getSection(markdown, 'Rules and Punishments');
        if (rulesSection && !rulesSection.includes('No information is known')) {
            // Extract rules list
            const rulesMatch = rulesSection.match(/Some of these rules include:\s*\n((?:\*[^\n]+\n?)+)/i);
            if (rulesMatch) {
                const rulesList = rulesMatch[1]
                    .split('\n')
                    .filter(line => line.trim().startsWith('*'))
                    .map(line => line.replace(/^\*\s*/, '').trim())
                    .join('\n');
                setValue('rulesList', rulesList);
            }

            // Extract punishments description
            const punishmentLines = rulesSection.split('\n\n').filter(para =>
                !para.includes('Some of these rules include') && !para.trim().startsWith('*')
            );
            if (punishmentLines.length > 0) {
                setValue('punishmentsDesc', punishmentLines.join('\n\n').trim());
            }
        }

        // Parse Abuse section
        const abuseSection = getSection(markdown, 'Abuse/Neglect Allegations and Lawsuits');
        if (abuseSection && !abuseSection.includes('No information is known')) {
            // Extract main complaints
            const complaintsMatch = abuseSection.match(/main complaints are of ([^\.]+)/i);
            if (complaintsMatch) {
                setValue('mainComplaints', complaintsMatch[1].trim());
            }

            // Extract other allegations list
            const allegationsMatch = abuseSection.match(/Other allegations[^\n]+:\s*\n((?:\*[^\n]+\n?)+)/i);
            if (allegationsMatch) {
                const allegationsList = allegationsMatch[1]
                    .split('\n')
                    .filter(line => line.trim().startsWith('*'))
                    .map(line => line.replace(/^\*\s*/, '').trim())
                    .join('\n');
                setValue('otherAllegationsList', allegationsList);
            }

            // Extract lawsuits
            const lawsuitLines = abuseSection.split('\n\n').filter(para =>
                !para.includes('main complaints are') &&
                !para.includes('Other allegations') &&
                !para.trim().startsWith('*') &&
                para.trim().length > 0
            );
            if (lawsuitLines.length > 0) {
                setValue('lawsuits', lawsuitLines.join('\n\n').trim());
            }
        }

        // Parse Media section
        const mediaSection = getSection(markdown, 'In the Media');
        if (mediaSection && !mediaSection.includes('No information is known')) {
            const lines = mediaSection.split('\n');
            const mediaLines = [];

            lines.forEach(line => {
                if (line.trim().startsWith('* [')) {
                    // This is a news article with a link
                    const match = line.match(/\*\s+\[([^\]]+)\]\(([^)]+)\)\s*(?:\(([^)]+)\))?/);
                    if (match) {
                        const title = match[1];
                        const url = match[2];
                        const sourceDate = match[3] || '';

                        // Try to split source and date
                        let source = '';
                        let date = '';
                        if (sourceDate) {
                            const parts = sourceDate.split(',').map(p => p.trim());
                            if (parts.length === 2) {
                                source = parts[0];
                                date = parts[1];
                            } else {
                                source = sourceDate;
                            }
                        }

                        newsArticles.push({ title, url, source, date });
                    }
                } else if (line.trim().startsWith('*')) {
                    // This is general media info
                    mediaLines.push(line.replace(/^\*\s*/, '').trim());
                }
            });

            if (mediaLines.length > 0) {
                setValue('mediaInfo', mediaLines.join('\n'));
            }

            renderList(newsArticles, 'articleListOutput', item => `<strong>${item.title}</strong> (${item.source || 'No Source'})`);
        }

        // Parse Testimonies section
        const testimoniesSection = getSectionAny(markdown, [
            'Survivor Testimonies',
            'Survivor/Parent Testimonials',
            'Survivor/Parent Testimonies',
            'Survivor Testimonials',
            'Survivor and Parent Testimonials'
        ]);
        if (testimoniesSection && !testimoniesSection.includes('No information is known')) {
            const blocks = testimoniesSection.split(/\n{2,}/).filter(l => l.trim().length > 0);
            blocks.forEach(block => {
                const cleaned = block
                    .replace(/^\s*[*-]\s*/, '') // drop list markers
                    .replace(/^\s*\*\*([^*]+)\*\*\s*/, '$1 ') // drop leading bold on date/type
                    .replace(/\n+/g, ' ') // flatten multiline entries
                    .trim();
                // Match formats like:
                // 10/29/2020: (SURVIVOR) "quote" - [Source](url)
                // (PARENT) "quote" - [Source](url)
                const match = cleaned.match(/^(?:(.+?):\s+)?\(([^)]+)\)\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/i);
                if (match) {
                    testimonies.push({
                        date: match[1] ? match[1].trim() : '',
                        type: match[2].trim(),
                        quote: match[3].trim(),
                        source: match[4].trim(),
                        url: match[5].trim()
                    });
                }
            });
            renderList(testimonies, 'testimonyListOutput', item => `<strong>(${item.type})</strong> ${item.quote.substring(0, 30)}... [${item.source}]`);
        }

        // Parse Related Media section
        const relatedMediaSection = getSection(markdown, 'Related Media');
        if (relatedMediaSection && !relatedMediaSection.includes('No information is known')) {
            const mediaMatches = relatedMediaSection.matchAll(/\*\s+\[([^\]]+)\]\(([^)]+)\)/g);
            for (const match of mediaMatches) {
                relatedMedia.push({
                    title: match[1].trim(),
                    url: match[2].trim()
                });
            }
            renderList(relatedMedia, 'mediaListOutput', item => `[${item.title}]`);
        }
    }

    // --- UTILITY FUNCTIONS ---
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function escapeMarkdown(text) {
        return String(text)
            .replace(/\\/g, '\\\\')
            .replace(/\*/g, '\\*')
            .replace(/_/g, '\\_')
            .replace(/\[/g, '\\[')
            .replace(/\]/g, '\\]')
            .replace(/`/g, '\\`');
    }
});
