document.addEventListener('DOMContentLoaded', () => {

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
        outputDiv.innerHTML = "";
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
        ids.forEach(id => document.getElementById(id).value = '');
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
            if (input.id && !input.closest('.form-adder')) { // Only get inputs NOT in an "adder" box
                vals[input.id] = input.value.trim();
            }
        });

        const programName = vals.programName || '[Program Name]';

        // --- Helper: Create Link ---
        const createLink = (text, url) => {
            if (!text) return "";
            if (!url) return text;
            return `[${text}](${url})`;
        };
        
        // --- Helper: Process Simple List from Textarea ---
        const processSimpleList = (text) => {
            if (!text.trim()) return "";
            return text.split('\n')
                       .filter(line => line.trim() !== '')
                       .map(line => `* ${line.trim()}`)
                       .join('\n');
        };

        // --- Build History Section ---
        let historySection = "";
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
        historySection = historyParts.length > 0 ? historyParts.join('\n\n') : getPlaceholder("History and Background Information", programName);

        // --- Build Staff Section ---
        let staffSection;
        if (staffMembers.length > 0) {
            staffSection = staffMembers.map(s => `* **${s.name}** is the ${s.role}. ${s.bio}`).join('\n');
        } else {
            staffSection = getPlaceholder("Founders and Notable Staff", programName);
        }

        // --- Build Structure Section ---
        let structureSection = "";
        const levelDesc = vals.levelSystemDesc ? `Like many other behavior-modification programs, ${programName} uses ${vals.levelSystemDesc}. The levels are:\n\n` : "";
        const levelList = programLevels.map(l => `* **"${l.name}"** - ${l.desc}`).join('\n\n');
        const structureMisc = vals.structureMisc ? `\n\n${vals.structureMisc}` : "";

        if (levelDesc || levelList || structureMisc) {
            structureSection = `${levelDesc}${levelList}${structureMisc}`;
        } else {
            structureSection = getPlaceholder("Program Structure", programName);
        }

        // --- Build Rules & Punishments Section ---
        let rulesSection = "";
        const rulesList = processSimpleList(vals.rulesList);
        const rulesHeader = rulesList ? `${programName} is a very strict program with many rules. Some of these rules include:\n\n` : "";
        const punishmentsDesc = vals.punishmentsDesc ? `\n\n${vals.punishmentsDesc}` : "";

        if (rulesList || punishmentsDesc) {
            rulesSection = `${rulesHeader}${rulesList}${punishmentsDesc}`;
        } else {
            rulesSection = getPlaceholder("Rules and Punishments", programName);
        }

        // --- Build Abuse Section ---
        let abuseSection = "";
        const mainComplaints = vals.mainComplaints ? `Many survivors have reported that abuse and neglect have occurred at ${programName}. The main complaints are of ${vals.mainComplaints}.` : "";
        const otherAllegationsList = processSimpleList(vals.otherAllegationsList);
        const otherAllegationsHeader = otherAllegationsList ? `\n\nOther allegations of abuse and neglect which have been reported by survivors include but are not limited to:\n\n` : "";
        const lawsuits = vals.lawsuits ? `\n\n${vals.lawsuits}` : "";

        if (mainComplaints || otherAllegationsList || lawsuits) {
            abuseSection = `${mainComplaints}${otherAllegationsHeader}${otherAllegationsList}${lawsuits}`;
        } else {
            abuseSection = getPlaceholder("Abuse/Neglect Allegations and Lawsuits", programName);
        }

        // --- Build Media Section (Combined) ---
        const mediaList = processSimpleList(vals.mediaInfo);
        const newsList = newsArticles.map(a => {
            let sourceDate = [a.source, a.date].filter(Boolean).join(', ');
            if (sourceDate) sourceDate = ` (${sourceDate})`;
            return `* [${a.title}](${a.url})${sourceDate}`;
        }).join('\n');
        const combinedMedia = [mediaList, newsList].filter(Boolean).join('\n\n');
        const mediaSection = combinedMedia || getPlaceholder("In the Media", programName);

        // --- Build Testimonies Section ---
        let testimoniesSection;
        if (testimonies.length > 0) {
            testimoniesSection = testimonies.map(t => {
                const datePart = t.date ? `${t.date}: ` : '';
                return `* ${datePart}(${t.type}) "${t.quote}" - [${t.source}](${t.url})`;
            }).join('\n');
        } else {
            testimoniesSection = getPlaceholder("Survivor Testimonies", programName);
        }

        // --- Build Related Media Section ---
        let relatedMediaSection;
        if (relatedMedia.length > 0) {
            relatedMediaSection = relatedMedia.map(m => `* [${m.title}](${m.url})`).join('\n');
        } else {
            relatedMediaSection = getPlaceholder("Related Media", programName);
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
        if (!outputCode.value) {
            alert('Please generate the code first!');
            return;
        }
        outputCode.select();
        outputCode.setSelectionRange(0, 99999);
        document.execCommand('copy');
        alert('Wiki code copied to clipboard!');
    });
});