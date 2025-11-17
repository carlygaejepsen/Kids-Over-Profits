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

    // --- Helper: Get Placeholder Text ---
    const getPlaceholder = (category, programName) => {
        const name = programName || '[Program Name]';
        return `No information is known about ${category} at ${name}. If you attended ${name} and would like to contribute information to help complete this page, please contact u/Signal-Strain8910.`;
    };

    // --- Helper: Render a Preview List ---
    const renderList = (array, outputElement, renderer) => {
        const outputDiv = document.getElementById(outputElement);
        if (!outputDiv) {
            return;
        }

        outputDiv.innerHTML = '';
        array.forEach((item, index) => {
            const el = document.createElement('div');
            el.className = 'list-preview-item';
            el.innerHTML = renderer(item);

            const removeBtn = document.createElement('button');
            removeBtn.innerText = 'Remove';
            removeBtn.onclick = () => {
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

    // --- Add Button: Staff ---
    document.getElementById('addStaffBtn').addEventListener('click', () => {
        const name = document.getElementById('staffName').value.trim();
        const role = document.getElementById('staffRole').value.trim();
        const bio = document.getElementById('staffBio').value.trim();
        if (name && role) {
            staffMembers.push({ name, role, bio });
            renderList(staffMembers, 'staffListOutput', item => `<strong>${item.name}</strong> - ${item.role}`);
            clearInputs(['staffName', 'staffRole', 'staffBio']);
        } else {
            alert('Please enter at least a name and role.');
        }
    });

    // --- Add Button: Levels ---
    document.getElementById('addLevelBtn').addEventListener('click', () => {
        const name = document.getElementById('levelName').value.trim();
        const desc = document.getElementById('levelDesc').value.trim();
        if (name && desc) {
            programLevels.push({ name, desc });
            renderList(programLevels, 'levelListOutput', item => `<strong>"${item.name}"</strong>`);
            clearInputs(['levelName', 'levelDesc']);
        } else {
            alert('Please enter a level name and description.');
        }
    });

    // --- Add Button: Articles ---
    document.getElementById('addArticleBtn').addEventListener('click', () => {
        const title = document.getElementById('articleTitle').value.trim();
        const url = document.getElementById('articleUrl').value.trim();
        const source = document.getElementById('articleSource').value.trim();
        const date = document.getElementById('articleDate').value.trim();
        if (title && url) {
            newsArticles.push({ title, url, source, date });
            renderList(newsArticles, 'articleListOutput', item => `<strong>${item.title}</strong> (${item.source || 'No Source'})`);
            clearInputs(['articleTitle', 'articleUrl', 'articleSource', 'articleDate']);
        } else {
            alert('Please enter at least a title and URL.');
        }
    });

    // --- Add Button: Testimonies ---
    document.getElementById('addTestimonyBtn').addEventListener('click', () => {
        const date = document.getElementById('testimonyDate').value.trim();
        const type = document.getElementById('testimonyType').value;
        const quote = document.getElementById('testimonyQuote').value.trim();
        const source = document.getElementById('testimonySource').value.trim();
        const url = document.getElementById('testimonyUrl').value.trim();
        if (quote && source && url) {
            testimonies.push({ date, type, quote, source, url });
            renderList(testimonies, 'testimonyListOutput', item => `<strong>(${item.type})</strong> ${item.quote.substring(0, 30)}... [${item.source}]`);
            clearInputs(['testimonyDate', 'testimonyQuote', 'testimonySource', 'testimonyUrl']);
        } else {
            alert('Please enter at least a quote, source name, and source URL.');
        }
    });

    // --- Add Button: Related Media ---
    document.getElementById('addMediaBtn').addEventListener('click', () => {
        const title = document.getElementById('mediaTitle').value.trim();
        const url = document.getElementById('mediaUrl').value.trim();
        if (title && url) {
            relatedMedia.push({ title, url });
            renderList(relatedMedia, 'mediaListOutput', item => `[${item.title}]`);
            clearInputs(['mediaTitle', 'mediaUrl']);
        } else {
            alert('Please enter a title and URL.');
        }
    });

    // --- MAIN GENERATE BUTTON ---
    document.getElementById('generateBtn').addEventListener('click', () => {
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
            if (!text) {
                return '';
            }
            if (!url) {
                return text;
            }
            return `[${text}](${url})`;
        };

        // --- Helper: Process Simple List from Textarea ---
        const processSimpleList = (text) => {
            if (!text.trim()) {
                return '';
            }
            return text.split('\n')
                .filter(line => line.trim() !== '')
                .map(line => `* ${line.trim()}`)
                .join('\n');
        };

        // --- Build History Section ---
        let historySection = '';
        const historyParts = [];
        if (vals.yearFounded) historyParts.push(`${programName} is a ${vals.programType || '[program type]'} founded in ${vals.yearFounded}.`);
        if (vals.ownerName) historyParts.push(`The program was [originally/currently] owned by ${createLink(vals.ownerName, vals.ownerLink)}.`);
        if (vals.ageRange) historyParts.push(`It is marketed as a [program's marketing claims] for teenagers aged ${vals.ageRange}.`);
        if (vals.diagnosesList) historyParts.push(`${programName} states that it enrolls teenagers with the following diagnoses/behaviors: ${vals.diagnosesList.split(',').map(item => `"${item.trim()}"`).join(', ')}.`);

        const stayTuitionNatsap = [];
        if (vals.avgStay) stayTuitionNatsap.push(`The average length of stay is reported to be around ${vals.avgStay}, but may be [Details on stay length].`);
        if (vals.tuition) stayTuitionNatsap.push(`The tuition is reported to be ${vals.tuition}.`);
        if (vals.natsapStatus) stayTuitionNatsap.push(`${programName} ${vals.natsapStatus}.`);
        if (stayTuitionNatsap.length > 0) historyParts.push(stayTuitionNatsap.join(' '));

        const addressAccrediting = [];
        if (vals.mainAddress) addressAccrediting.push(`The main office of ${programName} is located at ${createLink(vals.mainAddress, vals.addressLink)}.`);
        if (vals.accreditingBody) addressAccrediting.push(`${programName} is accredited through the ${createLink(vals.accreditingBody, vals.accreditingBodyLink)}. [Add any disclaimers about the accrediting body, if applicable].`);
        if (addressAccrediting.length > 0) historyParts.push(addressAccrediting.join('\n\n'));

        if (vals.historyMisc) historyParts.push(vals.historyMisc);
        historySection = historyParts.length > 0 ? historyParts.join('\n\n') : getPlaceholder('History and Background Information', programName);

        // --- Build Staff Section ---
        let staffSection;
        if (staffMembers.length > 0) {
            staffSection = staffMembers.map(s => `* **${s.name}** is the ${s.role}. ${s.bio}`).join('\n');
        } else {
            staffSection = getPlaceholder('Founders and Notable Staff', programName);
        }

        // --- Build Structure Section ---
        let structureSection = '';
        const levelDesc = vals.levelSystemDesc ? `Like many other behavior-modification programs, ${programName} uses ${vals.levelSystemDesc}. The levels are:\n\n` : '';
        const levelList = programLevels.map(l => `* **"${l.name}"** - ${l.desc}`).join('\n\n');
        const structureMisc = vals.structureMisc ? `\n\n${vals.structureMisc}` : '';

        if (levelDesc || levelList || structureMisc) {
            structureSection = `${levelDesc}${levelList}${structureMisc}`;
        } else {
            structureSection = getPlaceholder('Program Structure', programName);
        }

        // --- Build Rules & Punishments Section ---
        let rulesSection = '';
        const rulesList = processSimpleList(vals.rulesList);
        const rulesHeader = rulesList ? `${programName} is a very strict program with many rules. Some of these rules include:\n\n` : '';
        const punishmentsDesc = vals.punishmentsDesc ? `\n\n${vals.punishmentsDesc}` : '';

        if (rulesList || punishmentsDesc) {
            rulesSection = `${rulesHeader}${rulesList}${punishmentsDesc}`;
        } else {
            rulesSection = getPlaceholder('Rules and Punishments', programName);
        }

        // --- Build Abuse Section ---
        let abuseSection = '';
        const mainComplaints = vals.mainComplaints ? `Many survivors have reported that abuse and neglect have occurred at ${programName}. The main complaints are of ${vals.mainComplaints}.` : '';
        const otherAllegationsList = processSimpleList(vals.otherAllegationsList);
        const otherAllegationsHeader = otherAllegationsList ? `\n\nOther allegations of abuse and neglect which have been reported by survivors include but are not limited to:\n\n` : '';
        const lawsuits = vals.lawsuits ? `\n\n${vals.lawsuits}` : '';

        if (mainComplaints || otherAllegationsList || lawsuits) {
            abuseSection = `${mainComplaints}${otherAllegationsHeader}${otherAllegationsList}${lawsuits}`;
        } else {
            abuseSection = getPlaceholder('Abuse/Neglect Allegations and Lawsuits', programName);
        }

        // --- Build Media Section (Combined) ---
        const mediaList = processSimpleList(vals.mediaInfo);
        const newsList = newsArticles.map(a => {
            let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
            if (sourceDate) sourceDate = ` (${sourceDate})`;
            return `* [${a.title}](${a.url})${sourceDate}`;
        }).join('\n');
        const combinedMedia = [mediaList, newsList].filter(Boolean).join('\n\n');
        const mediaSection = combinedMedia || getPlaceholder('In the Media', programName);

        // --- Build Testimonies Section ---
        let testimoniesSection;
        if (testimonies.length > 0) {
            testimoniesSection = testimonies.map(t => {
                const datePart = t.date ? `${t.date}: ` : '';
                return `* ${datePart}(${t.type}) "${t.quote}" - [${t.source}](${t.url})`;
            }).join('\n');
        } else {
            testimoniesSection = getPlaceholder('Survivor Testimonies', programName);
        }

        // --- Build Related Media Section ---
        let relatedMediaSection;
        if (relatedMedia.length > 0) {
            relatedMediaSection = relatedMedia.map(m => `* [${m.title}](${m.url})`).join('\n');
        } else {
            relatedMediaSection = getPlaceholder('Related Media', programName);
        }

        // --- Assemble Final Output ---
        const output = `
## ${programName} (${vals.yearsActive || '[Years Active]'}) ${vals.cityState || '[City, ST]'}

*${vals.programType || '[Program Type]'}*

---

### History and Background Information

${historySection.replace('[Program Name]', programName)}

---

### Founders and Notable Staff

${staffSection}

---

### Program Structure

${structureSection.replace('[Program Name]', programName)}

---

### Rules and Punishments

${rulesSection.replace('[ProgramName]', programName)}

---

### Abuse/Neglect Allegations and Lawsuits

${abuseSection.replace('[Program Name]', programName)}

---

### In the Media

${mediaSection}

---

### Survivor Testimonies

${testimoniesSection}

---

### Related Media

${relatedMediaSection}
        `;

        document.getElementById('outputCode').value = output.trim();
    });

    // --- Copy Button ---
    document.getElementById('copyBtn').addEventListener('click', () => {
        const outputCode = document.getElementById('outputCode');
        if (!outputCode || !outputCode.value) {
            alert('Please generate the code first!');
            return;
        }
        outputCode.select();
        outputCode.setSelectionRange(0, 99999);
        document.execCommand('copy');
        alert('Wiki code copied to clipboard!');
    });

    // --- IMPORT FUNCTIONALITY ---
    const toggleImportBtn = document.getElementById('toggleImportBtn');
    const importPanel = document.getElementById('importPanel');
    const importBtn = document.getElementById('importBtn');
    const cancelImportBtn = document.getElementById('cancelImportBtn');
    const importTextarea = document.getElementById('importTextarea');

    // Toggle import panel
    toggleImportBtn.addEventListener('click', () => {
        const isHidden = importPanel.style.display === 'none';
        importPanel.style.display = isHidden ? 'block' : 'none';
        toggleImportBtn.textContent = isHidden ? '✖️ Close Import' : '📥 Import from Reddit Markdown';
    });

    // Cancel import
    cancelImportBtn.addEventListener('click', () => {
        importPanel.style.display = 'none';
        toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
        importTextarea.value = '';
    });

    // Import and parse Reddit markdown
    importBtn.addEventListener('click', () => {
        const markdown = importTextarea.value.trim();
        if (!markdown) {
            alert('Please paste some Reddit markdown first!');
            return;
        }

        try {
            parseAndPopulate(markdown);
            alert('Import successful! Form fields have been populated. Review and edit as needed.');
            importPanel.style.display = 'none';
            toggleImportBtn.textContent = '📥 Import from Reddit Markdown';
            importTextarea.value = '';
        } catch (error) {
            console.error('Import error:', error);
            alert('Error importing markdown. Please check the format and try again.\n\n' + error.message);
        }
    });

    // --- PARSER FUNCTION ---
    function parseAndPopulate(markdown) {
        // Clear existing data
        staffMembers = [];
        programLevels = [];
        newsArticles = [];
        testimonies = [];
        relatedMedia = [];

        // Helper: Extract text from markdown links
        const extractLinkText = (text) => {
            return text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
        };

        // Helper: Extract link URL
        const extractLinkUrl = (text) => {
            const match = text.match(/\[([^\]]+)\]\(([^)]+)\)/);
            return match ? match[2] : '';
        };

        // Helper: Extract section content
        const getSection = (markdown, sectionTitle) => {
            const regex = new RegExp(`###\\s+${sectionTitle}\\s*\\n([\\s\\S]*?)(?=\\n###|$)`, 'i');
            const match = markdown.match(regex);
            return match ? match[1].trim() : '';
        };

        // Parse header (Program Name, Years Active, City/State)
        const headerMatch = markdown.match(/##\s+(.+?)\s+\(([^)]+)\)\s+(.+)/);
        if (headerMatch) {
            document.getElementById('programName').value = headerMatch[1].trim();
            document.getElementById('yearsActive').value = headerMatch[2].trim();
            document.getElementById('cityState').value = headerMatch[3].trim();
        }

        // Parse program type (italicized line after header)
        const typeMatch = markdown.match(/##.*\n\s*\*([^*]+)\*/);
        if (typeMatch) {
            document.getElementById('programType').value = typeMatch[1].trim();
        }

        // Parse History section
        const historySection = getSection(markdown, 'History and Background Information');
        if (historySection) {
            // Extract year founded
            const yearFoundedMatch = historySection.match(/founded in (\d{4})/i);
            if (yearFoundedMatch) {
                document.getElementById('yearFounded').value = yearFoundedMatch[1];
            }

            // Extract owner
            const ownerMatch = historySection.match(/owned by \[([^\]]+)\]\(([^)]+)\)/i);
            if (ownerMatch) {
                document.getElementById('ownerName').value = ownerMatch[1];
                document.getElementById('ownerLink').value = ownerMatch[2];
            } else {
                const simpleOwnerMatch = historySection.match(/owned by ([^\.\n]+)/i);
                if (simpleOwnerMatch) {
                    document.getElementById('ownerName').value = simpleOwnerMatch[1].trim();
                }
            }

            // Extract age range
            const ageMatch = historySection.match(/aged (\d+-\d+)/i);
            if (ageMatch) {
                document.getElementById('ageRange').value = ageMatch[1];
            }

            // Extract diagnoses
            const diagnosesMatch = historySection.match(/diagnoses\/behaviors:\s*([^\n]+)/i);
            if (diagnosesMatch) {
                const diagnoses = diagnosesMatch[1]
                    .replace(/"/g, '')
                    .replace(/\./g, '')
                    .trim();
                document.getElementById('diagnosesList').value = diagnoses;
            }

            // Extract average stay
            const stayMatch = historySection.match(/average length of stay[^0-9]*(\d+[^,\.\n]+)/i);
            if (stayMatch) {
                document.getElementById('avgStay').value = stayMatch[1].trim();
            }

            // Extract tuition
            const tuitionMatch = historySection.match(/tuition[^$]*(\$[^\.\n]+)/i);
            if (tuitionMatch) {
                document.getElementById('tuition').value = tuitionMatch[1].trim();
            }

            // Extract NATSAP status
            const natsapMatch = historySection.match(/NATSAP[^\.\n]+/i);
            if (natsapMatch) {
                document.getElementById('natsapStatus').value = natsapMatch[0].replace(/^.*?\s/, '');
            }

            // Extract address
            const addressMatch = historySection.match(/located at \[([^\]]+)\]\(([^)]+)\)/i);
            if (addressMatch) {
                document.getElementById('mainAddress').value = addressMatch[1];
                document.getElementById('addressLink').value = addressMatch[2];
            }

            // Extract accrediting body
            const accreditMatch = historySection.match(/accredited through the \[([^\]]+)\]\(([^)]+)\)/i);
            if (accreditMatch) {
                document.getElementById('accreditingBody').value = accreditMatch[1];
                document.getElementById('accreditingBodyLink').value = accreditMatch[2];
            }

            // Extract misc history (everything that doesn't match specific patterns)
            const historyLines = historySection.split('\n\n');
            const miscLines = historyLines.filter(line => {
                return !line.match(/founded in|owned by|aged|diagnoses|length of stay|tuition|NATSAP|located at|accredited through/i);
            });
            if (miscLines.length > 0) {
                document.getElementById('historyMisc').value = miscLines.join('\n\n');
            }
        }

        // Parse Staff section
        const staffSection = getSection(markdown, 'Founders and Notable Staff');
        if (staffSection && !staffSection.includes('No information is known')) {
            const staffLines = staffSection.split('\n').filter(line => line.trim().startsWith('*'));
            staffLines.forEach(line => {
                const match = line.match(/\*\s+\*\*([^*]+)\*\*\s+is the\s+([^\.]+)\.\s*(.*)/);
                if (match) {
                    staffMembers.push({
                        name: match[1].trim(),
                        role: match[2].trim(),
                        bio: match[3].trim()
                    });
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
                document.getElementById('levelSystemDesc').value = levelDescMatch[1].trim();
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
                document.getElementById('structureMisc').value = miscStructure.trim();
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
                document.getElementById('rulesList').value = rulesList;
            }

            // Extract punishments description
            const punishmentLines = rulesSection.split('\n\n').filter(para =>
                !para.includes('Some of these rules include') && !para.trim().startsWith('*')
            );
            if (punishmentLines.length > 0) {
                document.getElementById('punishmentsDesc').value = punishmentLines.join('\n\n').trim();
            }
        }

        // Parse Abuse section
        const abuseSection = getSection(markdown, 'Abuse/Neglect Allegations and Lawsuits');
        if (abuseSection && !abuseSection.includes('No information is known')) {
            // Extract main complaints
            const complaintsMatch = abuseSection.match(/main complaints are of ([^\.]+)/i);
            if (complaintsMatch) {
                document.getElementById('mainComplaints').value = complaintsMatch[1].trim();
            }

            // Extract other allegations list
            const allegationsMatch = abuseSection.match(/Other allegations[^\n]+:\s*\n((?:\*[^\n]+\n?)+)/i);
            if (allegationsMatch) {
                const allegationsList = allegationsMatch[1]
                    .split('\n')
                    .filter(line => line.trim().startsWith('*'))
                    .map(line => line.replace(/^\*\s*/, '').trim())
                    .join('\n');
                document.getElementById('otherAllegationsList').value = allegationsList;
            }

            // Extract lawsuits
            const lawsuitLines = abuseSection.split('\n\n').filter(para =>
                !para.includes('main complaints are') &&
                !para.includes('Other allegations') &&
                !para.trim().startsWith('*') &&
                para.trim().length > 0
            );
            if (lawsuitLines.length > 0) {
                document.getElementById('lawsuits').value = lawsuitLines.join('\n\n').trim();
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
                document.getElementById('mediaInfo').value = mediaLines.join('\n');
            }

            renderList(newsArticles, 'articleListOutput', item => `<strong>${item.title}</strong> (${item.source || 'No Source'})`);
        }

        // Parse Testimonies section
        const testimoniesSection = getSection(markdown, 'Survivor Testimonies');
        if (testimoniesSection && !testimoniesSection.includes('No information is known')) {
            const testimonyMatches = testimoniesSection.matchAll(/\*\s+(?:([^:]+):\s+)?\(([^)]+)\)\s+"([^"]+)"\s*-\s*\[([^\]]+)\]\(([^)]+)\)/g);
            for (const match of testimonyMatches) {
                testimonies.push({
                    date: match[1] ? match[1].trim() : '',
                    type: match[2].trim(),
                    quote: match[3].trim(),
                    source: match[4].trim(),
                    url: match[5].trim()
                });
            }
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
});
