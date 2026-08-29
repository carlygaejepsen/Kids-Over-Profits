(function() {
    const STORAGE_KEY = 'news_processor_data';
    const SAVED_VALUES_KEY = 'news_processor_saved_values';
    
    const formData = {
        title: '', author: '', publicationDate: '', publicationName: '', url: '',
        location: '', tags: '',
        facilities: [], staff: '', survivors: '', contentWarnings: [],
        summary: '', alternateTitle: '', needsAlternateTitle: false,
        articleType: '', plaintiffs: '', defendants: '', legalRep: '',
        dateFiled: '', jurisdiction: '', pressReleases: '', relatedCoverage: '',
        staffMemberName: '', arrestFacilityName: '', misconductDates: '',
        charges: '', caseStatus: '', closureFacilityName: '', closureLocation: '',
        closureDate: '', closureContext: '', corporateFacilityNames: '',
        corporateLocation: '', keyPersonnel: '', ownership: ''
    };

    const savedValues = {
        authors: [], publications: [], facilities: [],
        staff: [], jurisdictions: [], legalReps: []
    };

    const contentWarnings = [
        'Physical Restraint', 'Chemical Restraint', 'Seclusion', 'Humiliating Punishments',
        'Child Sexual Abuse', 'Child-on-Child CSA', 'Graphic Descriptions of Assaults or Injuries',
        'Peer Violence', 'Child Death', 'Suicide', 'Self-harm', 'Substance Abuse',
        'Victim Blaming', 'Spiritual Abuse', 'Racism', 'Homophobia', 'Transphobia',
        'Hate Crimes', 'Slurs', 'Autism-Specific Abuse', 'Food Restriction',
        'Unsanitary Conditions', 'Medical Neglect', 'Eating Disorders', 'Conversion Therapy',
        'Forced Labor', 'Involuntary Transport', 'Law Enforcement Abuse'
    ];

    const articleTypes = [
        { value: 'lawsuit', label: 'Lawsuit Article' },
        { value: 'event', label: 'Specific Event Article' },
        { value: 'expose', label: 'Exposé or Survivor Account' },
        { value: 'arrest', label: 'Staff Arrest Article' },
        { value: 'closure', label: 'Facility Closure Article' },
        { value: 'corporate', label: 'Corporate Change Article' },
        { value: 'general', label: 'General News Article' }
    ];

    // Generic tags to exclude - these apply to almost every TTI article
    const EXCLUDED_TAGS = [
        // Generic abuse terms (specific types belong in content warnings)
        'abuse', 'child abuse', 'teen abuse', 'youth abuse',
        'physical abuse', 'sexual abuse', 'emotional abuse', 'psychological abuse',
        'verbal abuse', 'mental abuse', 'spiritual abuse', 'medical abuse',
        'neglect', 'medical neglect', 'educational neglect',
        'restraint', 'seclusion', 'isolation',
        'assault', 'sexual assault', 'physical assault',
        'trauma', 'ptsd', 'mistreatment',
        // Generic TTI/facility terms
        'boarding school', 'boarding schools',
        'troubled teen', 'troubled teens', 'troubled teen industry', 'tti',
        'residential treatment', 'residential treatment center', 'rtc',
        'therapeutic boarding school', 'treatment center', 'treatment facility',
        'behavioral health', 'mental health', 'mental health treatment',
        'reform', 'reform school', 'boot camp',
        'facility', 'program', 'institution',
        // Generic people terms
        'adolescent', 'adolescents', 'teenager', 'teenagers', 'teen', 'teens',
        'youth', 'children', 'child', 'minor', 'minors', 'juvenile', 'juveniles',
        'survivor', 'survivors', 'victim', 'victims', 'student', 'students',
        // Generic news/legal terms
        'abuse allegations', 'allegations', 'misconduct',
        'investigation', 'report', 'news', 'article', 'lawsuit', 'lawsuit filed'
    ];

    function filterGenericTags(tags) {
        if (!Array.isArray(tags)) {
            tags = typeof tags === 'string' ? tags.split('\n').filter(t => t.trim()) : [];
        }
        return tags.filter(tag => {
            const normalized = tag.toLowerCase().trim();
            return normalized.length > 0 && !EXCLUDED_TAGS.includes(normalized);
        });
    }

    // LocalStorage functions
    function saveToLocalStorage() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(formData));
            localStorage.setItem(SAVED_VALUES_KEY, JSON.stringify(savedValues));
        } catch (e) {
            console.error('Failed to save to localStorage:', e);
        }
    }

    function loadFromLocalStorage() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            const storedValues = localStorage.getItem(SAVED_VALUES_KEY);
            
            if (stored) {
                Object.assign(formData, JSON.parse(stored));
            }

            // facilities used to be a newline-joined string; migrate any legacy
            // stored shape (string OR array of strings) into the new object array.
            formData.facilities = normalizeFacilitiesValue(formData.facilities);

            if (storedValues) {
                Object.assign(savedValues, JSON.parse(storedValues));
            }
        } catch (e) {
            console.error('Failed to load from localStorage:', e);
        }
    }

    // Coerces any prior shape of `facilities` (newline string, array of strings,
    // array of {name, facility_id}) into the canonical [{name, facility_id}] array.
    function normalizeFacilitiesValue(value) {
        if (value == null) return [];
        let items = value;
        if (typeof items === 'string') {
            items = items.split('\n').map(s => s.trim()).filter(Boolean);
        }
        if (!Array.isArray(items)) return [];
        const out = [];
        const seen = new Set();
        for (const item of items) {
            let name = '';
            let fid = null;
            if (typeof item === 'string') {
                name = item.trim();
            } else if (item && typeof item === 'object') {
                name = String(item.name || '').trim();
                if (item.facility_id != null && item.facility_id !== '') {
                    const n = parseInt(item.facility_id, 10);
                    if (Number.isFinite(n) && n > 0) fid = n;
                }
            }
            if (!name) continue;
            const key = name.toLowerCase();
            if (seen.has(key)) continue;
            seen.add(key);
            out.push({ name, facility_id: fid });
        }
        return out;
    }

    function clearLocalStorage() {
        if (confirm('Are you sure you want to clear the form? This will also clear all saved values.')) {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.removeItem(SAVED_VALUES_KEY);
            location.reload();
        }
    }

    // Templates for quick start
    const articleTemplates = {
        lawsuit: {
            name: 'Lawsuit Article',
            icon: '⚖️',
            fields: {
                articleType: 'lawsuit',
                contentWarnings: ['Physical Restraint', 'Seclusion']
            }
        },
        arrest: {
            name: 'Staff Arrest',
            icon: '👮',
            fields: {
                articleType: 'arrest',
                contentWarnings: ['Child Sexual Abuse', 'Law Enforcement Abuse']
            }
        },
        closure: {
            name: 'Facility Closure',
            icon: '🏢',
            fields: {
                articleType: 'closure',
                contentWarnings: []
            }
        },
        expose: {
            name: 'Survivor Account',
            icon: '📰',
            fields: {
                articleType: 'expose',
                contentWarnings: ['Graphic Descriptions of Assaults or Injuries', 'Physical Restraint']
            }
        },
        corporate: {
            name: 'Corporate Change',
            icon: '🏛️',
            fields: {
                articleType: 'corporate',
                contentWarnings: []
            }
        }
    };

    function restoreFormState() {
        Object.keys(formData).forEach(key => {
            const input = document.querySelector(`[name="${key}"]`);
            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = formData[key];
                } else {
                    input.value = formData[key] || '';
                }
            }
        });

        // Restore content warnings. Guard against a non-array value from an
        // older corrupted localStorage save — a TypeError here aborts init()
        // on every page load until storage is cleared.
        if (!Array.isArray(formData.contentWarnings)) {
            formData.contentWarnings = formData.contentWarnings ? [String(formData.contentWarnings)] : [];
        }
        formData.contentWarnings.forEach(warning => {
            const btn = Array.from(document.querySelectorAll('.news-warning-btn'))
                .find(b => b.textContent === warning);
            if (btn) btn.classList.add('active');
        });
        updateWarningsCount();

        // Restore article type
        if (formData.articleType) {
            const typeBtn = Array.from(document.querySelectorAll('.news-type-btn'))
                .find(b => b.dataset.value === formData.articleType);
            if (typeBtn) {
                typeBtn.classList.add('active');
                showTypeSpecificForm(formData.articleType);
            }
        }

        // Restore alt title visibility
        if (formData.needsAlternateTitle) {
            document.getElementById('alnewstleGroup').style.display = 'block';
        }
    }

    function restoreSavedValues() {
        Object.keys(savedValues).forEach(category => {
            updateSavedTags(category);
            updateDatalist(category);
        });
    }

    function setupSectionToggles() {
        document.querySelectorAll('.news-section-header').forEach(btn => {
            btn.addEventListener('click', function() {
                const section = this.dataset.section;
                const content = document.getElementById('section-' + section);
                const chevron = this.querySelector('.news-chevron');
                
                if (content.classList.contains('active')) {
                    content.classList.remove('active');
                    chevron.textContent = '▶';
                } else {
                    content.classList.add('active');
                    chevron.textContent = '▼';
                }
            });
        });
    }

    function setupFormInputs() {
        document.querySelectorAll('input, textarea').forEach(input => {
            const name = input.name;
            if (name) {
                input.addEventListener('input', function() {
                    if (this.type === 'checkbox') {
                        formData[name] = this.checked;
                    } else {
                        formData[name] = this.value;
                    }
                    saveToLocalStorage();
                });
            }
        });
    }

    function setupContentWarnings() {
        const container = document.getElementById('warnings-container');
        contentWarnings.forEach(warning => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'news-warning-btn';
            btn.textContent = warning;
            btn.addEventListener('click', function() {
                const idx = formData.contentWarnings.indexOf(warning);
                if (idx > -1) {
                    formData.contentWarnings.splice(idx, 1);
                    this.classList.remove('active');
                } else {
                    formData.contentWarnings.push(warning);
                    this.classList.add('active');
                }
                updateWarningsCount();
                saveToLocalStorage();
            });
            container.appendChild(btn);
        });
    }

    function updateWarningsCount() {
        document.getElementById('warnings-count').textContent = formData.contentWarnings.length;
    }

    function setupArticleTypes() {
        const container = document.getElementById('article-types');
        articleTypes.forEach(type => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'news-type-btn';
            btn.textContent = type.label;
            btn.dataset.value = type.value;
            btn.addEventListener('click', function() {
                document.querySelectorAll('.news-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                formData.articleType = type.value;
                showTypeSpecificForm(type.value);
                saveToLocalStorage();
            });
            container.appendChild(btn);
        });
    }

    // Escape a value for interpolation into HTML (attribute values and
    // textarea bodies). Without this, a double quote in an AI-extracted name
    // truncates the input's value and the corrupted value gets saved back.
    function escAttr(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showTypeSpecificForm(type) {
        const container = document.getElementById('type-specific-forms');
        container.innerHTML = '';
        
        if (type === 'lawsuit') {
            container.innerHTML = `
                <div class="news-type-form">
                    <h3>Lawsuit Details</h3>
                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Plaintiffs</label>
                            <input type="text" name="plaintiffs" class="news-input" data-autocomplete-category="human" value="${escAttr(formData.plaintiffs)}">
                        </div>
                        <div class="news-form-group">
                            <label>Defendants</label>
                            <input type="text" name="defendants" class="news-input" data-autocomplete-category="human" value="${escAttr(formData.defendants)}">
                        </div>
                    </div>
                    <div class="news-form-group">
                        <label>Legal Representation</label>
                        <div class="news-input-with-save">
                            <input type="text" name="legalRep" class="news-input" data-autocomplete-category="human" list="legalReps-list" value="${escAttr(formData.legalRep)}">
                            <datalist id="legalReps-list"></datalist>
                            <button class="news-save-btn" data-save="legalReps" data-field="legalRep">💾</button>
                        </div>
                        <div class="news-saved-tags" data-category="legalReps"></div>
                    </div>
                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Date Filed</label>
                            <input type="date" name="dateFiled" class="news-input" value="${escAttr(formData.dateFiled)}">
                        </div>
                        <div class="news-form-group">
                            <label>Jurisdiction</label>
                            <div class="news-input-with-save">
                                <input type="text" name="jurisdiction" class="news-input" data-autocomplete-category="location" list="jurisdictions-list" value="${escAttr(formData.jurisdiction)}">
                                <datalist id="jurisdictions-list"></datalist>
                                <button class="news-save-btn" data-save="jurisdictions" data-field="jurisdiction">💾</button>
                            </div>
                            <div class="news-saved-tags" data-category="jurisdictions"></div>
                        </div>
                    </div>
                    <div class="news-form-group">
                        <label>Press Releases (URLs)</label>
                        <textarea name="pressReleases" class="news-textarea" rows="2" placeholder="One URL per line">${escAttr(formData.pressReleases)}</textarea>
                    </div>
                </div>
            `;
        } else if (type === 'event') {
            container.innerHTML = `
                <div class="news-type-form">
                    <h3>Event Details</h3>
                    <div class="news-form-group">
                        <label>Related Coverage (URLs)</label>
                        <textarea name="relatedCoverage" class="news-textarea" rows="3" placeholder="One URL per line">${escAttr(formData.relatedCoverage)}</textarea>
                    </div>
                </div>
            `;
        } else if (type === 'arrest') {
            container.innerHTML = `
                <div class="news-type-form">
                    <h3>Arrest Details</h3>
                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Staff Member Name</label>
                            <input type="text" name="staffMemberName" class="news-input" data-autocomplete-category="human" value="${escAttr(formData.staffMemberName)}">
                        </div>
                        <div class="news-form-group">
                            <label>Facility Name</label>
                            <input type="text" name="arrestFacilityName" class="news-input" data-autocomplete-category="facility" value="${escAttr(formData.arrestFacilityName)}">
                        </div>
                    </div>
                    <div class="news-form-group">
                        <label>Date(s) of Alleged Misconduct</label>
                        <input type="text" name="misconductDates" class="news-input" value="${escAttr(formData.misconductDates)}">
                    </div>
                    <div class="news-form-group">
                        <label>Charges</label>
                        <textarea name="charges" class="news-textarea" rows="2">${escAttr(formData.charges)}</textarea>
                    </div>
                        <div class="news-form-group">
                            <label>Case Status</label>
                            <input type="text" name="caseStatus" class="news-input" data-autocomplete-category="status" placeholder="e.g., awaiting trial, convicted" value="${escAttr(formData.caseStatus)}">
                        </div>
                </div>
            `;
        } else if (type === 'closure') {
            container.innerHTML = `
                <div class="news-type-form">
                    <h3>Closure Details</h3>
                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Facility Name</label>
                            <input type="text" name="closureFacilityName" class="news-input" data-autocomplete-category="facility" value="${escAttr(formData.closureFacilityName)}">
                        </div>
                        <div class="news-form-group">
                            <label>Location (City, State)</label>
                            <input type="text" name="closureLocation" class="news-input" data-autocomplete-category="location" value="${escAttr(formData.closureLocation)}">
                        </div>
                    </div>
                    <div class="news-form-group">
                        <label>Date of Closure</label>
                        <input type="date" name="closureDate" class="news-input" value="${escAttr(formData.closureDate)}">
                    </div>
                    <div class="news-form-group">
                        <label>Context/Reason</label>
                        <textarea name="closureContext" class="news-textarea" rows="3">${escAttr(formData.closureContext)}</textarea>
                    </div>
                </div>
            `;
        } else if (type === 'corporate') {
            container.innerHTML = `
                <div class="news-type-form">
                    <h3>Corporate Change Details</h3>
                    <div class="news-form-group">
                        <label>Facility Name(s)</label>
                        <textarea name="corporateFacilityNames" class="news-textarea" rows="2" placeholder="Include old and new names for rebrand/merger">${escAttr(formData.corporateFacilityNames)}</textarea>
                    </div>
                    <div class="news-form-group">
                        <label>Location (City, State)</label>
                        <input type="text" name="corporateLocation" class="news-input" data-autocomplete-category="location" value="${escAttr(formData.corporateLocation)}">
                    </div>
                    <div class="news-form-group">
                        <label>Key Personnel</label>
                        <textarea name="keyPersonnel" class="news-textarea" rows="2" placeholder="Names of executives or staff central to the change">${escAttr(formData.keyPersonnel)}</textarea>
                    </div>
                    <div class="news-form-group">
                        <label>Ownership/Funding</label>
                        <textarea name="ownership" class="news-textarea" rows="2" placeholder="Owners, parent companies, funders, or key stakeholders">${escAttr(formData.ownership)}</textarea>
                    </div>
                </div>
            `;
        }
        
        setupFormInputs();
        // Restore saved values for dynamically added fields
        updateSavedTags('legalReps');
        updateDatalist('legalReps');
        updateSavedTags('jurisdictions');
        updateDatalist('jurisdictions');
    }

    function setupSaveButtons() {
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('news-save-btn')) {
                const category = e.target.dataset.save;
                const field = e.target.dataset.field;
                const value = formData[field];

                if (field === 'facilities') {
                    // facilities is an array of {name, facility_id}; save each name.
                    (Array.isArray(value) ? value : []).forEach(f => {
                        if (f && f.name) saveValue(category, f.name.trim());
                    });
                } else if (value && typeof value === 'string' && value.trim()) {
                    if (field === 'staff') {
                        const lines = value.split('\n').filter(l => l.trim());
                        lines.forEach(line => saveValue(category, line.trim()));
                    } else {
                        saveValue(category, value.trim());
                    }
                }
            }
            
            if (e.target.classList.contains('news-tag-remove')) {
                const category = e.target.dataset.category;
                const value = e.target.dataset.value;
                removeValue(category, value);
            }
        });
    }

    function saveValue(category, value) {
        if (!savedValues[category].includes(value)) {
            savedValues[category].push(value);
            updateSavedTags(category);
            updateDatalist(category);
            saveToLocalStorage();
        }
    }

    function removeValue(category, value) {
        const idx = savedValues[category].indexOf(value);
        if (idx > -1) {
            savedValues[category].splice(idx, 1);
            updateSavedTags(category);
            updateDatalist(category);
            saveToLocalStorage();
        }
    }

    function updateSavedTags(category) {
        const container = document.querySelector(`.news-saved-tags[data-category="${category}"]`);
        if (!container) return;
        
        container.innerHTML = '';
        savedValues[category].forEach(value => {
            // Build via DOM APIs: values with quotes broke the data-value
            // attribute (making tags unremovable) when interpolated raw.
            const tag = document.createElement('span');
            tag.className = 'news-tag';
            tag.appendChild(document.createTextNode(` ${value} `));
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'news-tag-remove';
            removeBtn.dataset.category = category;
            removeBtn.dataset.value = value;
            removeBtn.textContent = '×';
            tag.appendChild(removeBtn);
            container.appendChild(tag);
        });
    }

    function updateDatalist(category) {
        const datalist = document.getElementById(category + '-list');
        if (!datalist) return;
        
        datalist.innerHTML = '';
        savedValues[category].forEach(value => {
            const option = document.createElement('option');
            option.value = value;
            datalist.appendChild(option);
        });
    }

    function setupAlnewstle() {
        document.getElementById('needsAlnewstle').addEventListener('change', function() {
            formData.needsAlternateTitle = this.checked;
            document.getElementById('alnewstleGroup').style.display = this.checked ? 'block' : 'none';
            saveToLocalStorage();
        });
    }

    function setupClearButton() {
        document.getElementById('clear-form').addEventListener('click', clearLocalStorage);
    }

    function setupExportButtons() {
        document.getElementById('export-json').addEventListener('click', () => exportData('json'));
        document.getElementById('export-text').addEventListener('click', () => exportData('text'));
    }

    function exportData(format) {
        const output = {
            basicDetails: {
                title: formData.title,
                author: formData.author,
                publicationDate: formData.publicationDate,
                publicationName: formData.publicationName,
                url: formData.url,
                location: formData.location,
                tags: filterGenericTags((formData.tags || '').split('\n').filter(t => t.trim())),
                facilities: (Array.isArray(formData.facilities) ? formData.facilities : [])
                    .map(f => (f && f.name) ? f.name : '')
                    .filter(Boolean),
                staff: formData.staff.split('\n').filter(s => s.trim()),
                survivors: formData.survivors.split('\n').filter(s => s.trim())
            },
            contentWarnings: formData.contentWarnings,
            summary: {
                text: formData.summary,
                alternateTitle: formData.needsAlternateTitle ? formData.alternateTitle : null
            },
            articleType: formData.articleType
        };

        if (formData.articleType === 'lawsuit') {
            output.lawsuitDetails = {
                plaintiffs: formData.plaintiffs,
                defendants: formData.defendants,
                legalRepresentation: formData.legalRep,
                dateFiled: formData.dateFiled,
                jurisdiction: formData.jurisdiction,
                pressReleases: formData.pressReleases.split('\n').filter(p => p.trim())
            };
        } else if (formData.articleType === 'event') {
            output.eventDetails = {
                relatedCoverage: formData.relatedCoverage.split('\n').filter(r => r.trim())
            };
        } else if (formData.articleType === 'arrest') {
            output.arrestDetails = {
                staffMemberName: formData.staffMemberName,
                facilityName: formData.arrestFacilityName,
                misconductDates: formData.misconductDates,
                charges: formData.charges,
                caseStatus: formData.caseStatus
            };
        } else if (formData.articleType === 'closure') {
            output.closureDetails = {
                facilityName: formData.closureFacilityName,
                location: formData.closureLocation,
                closureDate: formData.closureDate,
                context: formData.closureContext
            };
        } else if (formData.articleType === 'corporate') {
            output.corporateDetails = {
                facilityNames: formData.corporateFacilityNames,
                location: formData.corporateLocation,
                keyPersonnel: formData.keyPersonnel,
                ownership: formData.ownership
            };
        }

        if (format === 'json') {
            downloadFile(JSON.stringify(output, null, 2), 'application/json', 'json');
        } else {
            downloadFile(generateTextReport(output), 'text/plain', 'txt');
        }
    }

    function generateTextReport(output) {
        let text = '='.repeat(60) + '\n';
        text += 'news NEWS ARTICLE PROCESSING REPORT\n';
        text += '='.repeat(60) + '\n\n';
        
        text += '--- BASIC DETAILS ---\n';
        text += `Title: ${formData.title}\n`;
        if (formData.needsAlternateTitle && formData.alternateTitle) {
            text += `Alternate Title: ${formData.alternateTitle}\n`;
        }
        text += `Author: ${formData.author}\n`;
        text += `Publication: ${formData.publicationName}\n`;
        text += `Date: ${formData.publicationDate}\n`;
        text += `Location: ${formData.location}\n`;
        text += `URL: ${formData.url}\n\n`;
        
        if (output.basicDetails.tags.length > 0) {
            text += 'Tags:\n';
            output.basicDetails.tags.forEach(t => text += `  - ${t}\n`);
            text += '\n';
        }

        if (output.basicDetails.facilities.length > 0) {
            text += 'news Facilities/Companies:\n';
            output.basicDetails.facilities.forEach(f => text += `  - ${f}\n`);
            text += '\n';
        }
        
        if (output.basicDetails.staff.length > 0) {
            text += 'Staff/Owners:\n';
            output.basicDetails.staff.forEach(s => text += `  - ${s}\n`);
            text += '\n';
        }
        
        if (output.basicDetails.survivors.length > 0) {
            text += 'Survivors & Victims Mentioned:\n';
            output.basicDetails.survivors.forEach(s => text += `  - ${s}\n`);
            text += '\n';
        }
        
        if (formData.contentWarnings.length > 0) {
            text += '--- CONTENT WARNINGS ---\n';
            formData.contentWarnings.forEach(w => text += `  - ${w}\n`);
            text += '\n';
        }
        
        text += '--- SUMMARY ---\n';
        text += `${formData.summary}\n\n`;
        
        if (formData.articleType) {
            text += '--- ARTICLE TYPE ---\n';
            const typeLabel = articleTypes.find(t => t.value === formData.articleType) && articleTypes.find(t => t.value === formData.articleType).label || formData.articleType;
            text += `${typeLabel}\n\n`;
            
            if (formData.articleType === 'lawsuit' && output.lawsuitDetails) {
                text += '--- LAWSUIT DETAILS ---\n';
                if (formData.plaintiffs) text += `Plaintiffs: ${formData.plaintiffs}\n`;
                if (formData.defendants) text += `Defendants: ${formData.defendants}\n`;
                if (formData.legalRep) text += `Legal Representation: ${formData.legalRep}\n`;
                if (formData.dateFiled) text += `Date Filed: ${formData.dateFiled}\n`;
                if (formData.jurisdiction) text += `Jurisdiction: ${formData.jurisdiction}\n`;
                if (output.lawsuitDetails.pressReleases.length > 0) {
                    text += 'Press Releases:\n';
                    output.lawsuitDetails.pressReleases.forEach(p => text += `  - ${p}\n`);
                }
                text += '\n';
            } else if (formData.articleType === 'event' && output.eventDetails) {
                text += '--- EVENT DETAILS ---\n';
                if (output.eventDetails.relatedCoverage.length > 0) {
                    text += 'Related Coverage:\n';
                    output.eventDetails.relatedCoverage.forEach(r => text += `  - ${r}\n`);
                }
                text += '\n';
            } else if (formData.articleType === 'arrest' && output.arrestDetails) {
                text += '--- ARREST DETAILS ---\n';
                if (formData.staffMemberName) text += `Staff Member: ${formData.staffMemberName}\n`;
                if (formData.arrestFacilityName) text += `Facility: ${formData.arrestFacilityName}\n`;
                if (formData.misconductDates) text += `Date(s) of Misconduct: ${formData.misconductDates}\n`;
                if (formData.charges) text += `Charges: ${formData.charges}\n`;
                if (formData.caseStatus) text += `Case Status: ${formData.caseStatus}\n`;
                text += '\n';
            } else if (formData.articleType === 'closure' && output.closureDetails) {
                text += '--- CLOSURE DETAILS ---\n';
                if (formData.closureFacilityName) text += `Facility: ${formData.closureFacilityName}\n`;
                if (formData.closureLocation) text += `Location: ${formData.closureLocation}\n`;
                if (formData.closureDate) text += `Closure Date: ${formData.closureDate}\n`;
                if (formData.closureContext) text += `Context: ${formData.closureContext}\n`;
                text += '\n';
            } else if (formData.articleType === 'corporate' && output.corporateDetails) {
                text += '--- CORPORATE CHANGE DETAILS ---\n';
                if (formData.corporateFacilityNames) text += `Facility Name(s): ${formData.corporateFacilityNames}\n`;
                if (formData.corporateLocation) text += `Location: ${formData.corporateLocation}\n`;
                if (formData.keyPersonnel) text += `Key Personnel: ${formData.keyPersonnel}\n`;
                if (formData.ownership) text += `Ownership/Funding: ${formData.ownership}\n`;
                text += '\n';
            }
        }
        
        text += '='.repeat(60) + '\n';
        text += `Generated: ${new Date().toLocaleString()}\n`;
        text += '='.repeat(60);
        
        return text;
    }

    function downloadFile(content, mimeType, extension) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `news-article-${Date.now()}.${extension}`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // --- TEMPLATES ---
    function setupTemplates() {
        const container = document.getElementById('template-buttons');
        if (!container) return;

        Object.keys(articleTemplates).forEach(key => {
            const template = articleTemplates[key];
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'news-template-btn';
            btn.innerHTML = `${template.icon} ${template.name}`;
            btn.addEventListener('click', () => applyTemplate(key));
            container.appendChild(btn);
        });
    }

    function applyTemplate(templateKey) {
        const template = articleTemplates[templateKey];
        if (!template) return;

        // Set article type
        formData.articleType = template.fields.articleType;

        // Set content warnings
        formData.contentWarnings = [...template.fields.contentWarnings];

        // Update UI
        document.querySelectorAll('.news-type-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.value === template.fields.articleType) {
                btn.classList.add('active');
            }
        });

        // Update content warnings UI
        document.querySelectorAll('.news-warning-btn').forEach(btn => {
            btn.classList.remove('active');
            if (template.fields.contentWarnings.includes(btn.textContent)) {
                btn.classList.add('active');
            }
        });

        updateWarningsCount();
        showTypeSpecificForm(template.fields.articleType);
        saveToLocalStorage();

        // Scroll to basic details
        document.getElementById('section-basic').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // --- DYNAMIC FIELDS (facilities, staff, survivors) ---
    function setupDynamicFields() {
        // Note: facilities is handled by setupFacilityPicker() instead, since it
        // needs a typeahead/picker UX rather than free-text inputs.
        const fields = {
            staff: { container: 'staff-container', category: 'human', placeholder: 'Staff member name' },
            survivors: { container: 'survivors-container', category: 'human', placeholder: 'Survivor or Victim name' },
            tags: { container: 'tags-container', category: 'tag', placeholder: 'Tag/Keyword' }
        };

        Object.keys(fields).forEach(fieldName => {
            const config = fields[fieldName];
            const container = document.getElementById(config.container);
            const addBtn = document.querySelector(`[data-add-field="${fieldName}"]`);

            if (!container || !addBtn) return;

            // Add click handler for add button
            addBtn.addEventListener('click', () => addDynamicField(fieldName, config));

            // Initialize with existing data
            const existingData = formData[fieldName];
            if (existingData) {
                const values = typeof existingData === 'string'
                    ? existingData.split('\n').filter(v => v.trim())
                    : (Array.isArray(existingData) ? existingData : []);

                values.forEach(value => {
                    if (value.trim()) {
                        addDynamicField(fieldName, config, value.trim());
                    }
                });
            }

            // If no fields exist, add one empty field
            if (container.children.length === 0) {
                addDynamicField(fieldName, config);
            }
        });
    }

    function addDynamicField(fieldName, config, initialValue = '') {
        const container = document.getElementById(config.container);
        if (!container) return;

        const fieldGroup = document.createElement('div');
        fieldGroup.className = 'news-dynamic-field-group';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'news-input';
        input.dataset.dynamicField = fieldName;
        input.dataset.autocompleteCategory = config.category;
        input.placeholder = config.placeholder;
        input.value = initialValue;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'news-btn news-btn-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Remove';

        removeBtn.addEventListener('click', () => {
            fieldGroup.remove();
            updateDynamicFieldData(fieldName);
            saveToLocalStorage();
        });

        input.addEventListener('input', () => {
            updateDynamicFieldData(fieldName);
            saveToLocalStorage();
        });

        // Save value to database when user finishes typing
        input.addEventListener('blur', () => {
            const value = input.value.trim();
            if (value) {
                saveValueToDatabase(fieldName, value);
            }
        });

        fieldGroup.appendChild(input);
        fieldGroup.appendChild(removeBtn);
        container.appendChild(fieldGroup);

        // Initialize autocomplete if available
        if (typeof initializeAutocompleteFields === 'function') {
            initializeAutocompleteFields();
        }

        updateDynamicFieldData(fieldName);
        return input;
    }

    function updateDynamicFieldData(fieldName) {
        const inputs = document.querySelectorAll(`[data-dynamic-field="${fieldName}"]`);
        const values = Array.from(inputs)
            .map(input => input.value.trim())
            .filter(v => v);

        formData[fieldName] = values.join('\n');
    }

    async function saveValueToDatabase(fieldName, value) {
        try {
            const endpoint = (window.KOP_NewsProcessor_Settings && window.KOP_NewsProcessor_Settings.savedValuesUrl) 
                ? window.KOP_NewsProcessor_Settings.savedValuesUrl 
                : '/wp-content/themes/child/api/saved-values.php';

            await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    form: 'news',
                    category: fieldName,
                    value: value
                })
            });
        } catch (error) {
            console.warn('Failed to save autocomplete value:', error);
        }
    }

    // --- FACILITY PICKER ---
    // Replaces the legacy free-text "facilities" dynamic-field UI with a typeahead
    // that resolves each entry to a facilities_master row when possible.
    //
    // Storage: formData.facilities is an array of { name, facility_id|null }.
    // Each row in the DOM owns one entry; the picker syncs DOM -> formData.
    function setupFacilityPicker() {
        const container = document.getElementById('facilities-container');
        const addBtn = document.getElementById('facilities-add-btn');
        if (!container || !addBtn) return;

        const searchUrl = (window.KOP_NewsProcessor_Settings && window.KOP_NewsProcessor_Settings.facilitySearchUrl)
            ? window.KOP_NewsProcessor_Settings.facilitySearchUrl
            : '/wp-content/themes/child/api/facility-search.php';

        function syncFromDom() {
            const rows = container.querySelectorAll('.facility-row');
            const items = [];
            rows.forEach(row => {
                const name = (row.dataset.name || '').trim();
                if (!name) return;
                const fid = row.dataset.facilityId ? parseInt(row.dataset.facilityId, 10) : null;
                items.push({ name, facility_id: Number.isFinite(fid) && fid > 0 ? fid : null });
            });
            formData.facilities = items;
            saveToLocalStorage();
        }

        function buildDisplayRow(entry) {
            const row = document.createElement('div');
            row.className = 'facility-row';
            row.dataset.name = entry.name;
            if (entry.facility_id) row.dataset.facilityId = String(entry.facility_id);

            const display = document.createElement('div');
            display.className = 'facility-row-display';
            display.style.display = 'flex';
            display.style.alignItems = 'center';
            display.style.gap = '8px';
            display.style.padding = '6px 10px';
            display.style.background = entry.facility_id ? '#e8f4f6' : '#fff3cd';
            display.style.border = '1px solid ' + (entry.facility_id ? '#33A7B5' : '#EF9034');
            display.style.borderRadius = '4px';
            display.style.marginBottom = '4px';

            const nameEl = document.createElement('span');
            nameEl.className = 'facility-row-name';
            nameEl.style.fontWeight = '500';
            nameEl.textContent = entry.name;

            const metaEl = document.createElement('span');
            metaEl.className = 'facility-row-meta';
            metaEl.style.fontSize = '0.85em';
            metaEl.style.color = '#666';
            if (entry.facility_id) {
                metaEl.textContent = entry.state ? `· ${entry.state}` : '· linked';
            } else {
                metaEl.textContent = '· unlinked (will be saved as text)';
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'news-btn news-btn-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove';
            removeBtn.style.marginLeft = 'auto';
            removeBtn.addEventListener('click', () => {
                row.remove();
                syncFromDom();
            });

            display.appendChild(nameEl);
            display.appendChild(metaEl);
            display.appendChild(removeBtn);
            row.appendChild(display);
            return row;
        }

        function buildEditingRow(initialName) {
            const row = document.createElement('div');
            row.className = 'facility-row facility-row-editing';
            row.style.position = 'relative';
            row.style.marginBottom = '4px';

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'news-input';
            input.placeholder = 'Search facilities (start typing a name)';
            input.value = initialName || '';
            input.autocomplete = 'off';
            input.style.width = 'calc(100% - 40px)';

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'news-btn news-btn-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Cancel';
            removeBtn.style.marginLeft = '8px';
            removeBtn.addEventListener('click', () => {
                row.remove();
                syncFromDom();
            });

            const suggestions = document.createElement('div');
            suggestions.className = 'facility-suggestions';
            suggestions.style.position = 'absolute';
            suggestions.style.zIndex = '50';
            suggestions.style.background = '#fff';
            suggestions.style.border = '1px solid #ccc';
            suggestions.style.borderTop = 'none';
            suggestions.style.maxHeight = '240px';
            suggestions.style.overflowY = 'auto';
            suggestions.style.width = 'calc(100% - 40px)';
            suggestions.style.display = 'none';
            suggestions.style.boxShadow = '0 2px 4px rgba(0,0,0,0.08)';

            const inputWrap = document.createElement('div');
            inputWrap.style.display = 'flex';
            inputWrap.style.alignItems = 'flex-start';
            inputWrap.appendChild(input);
            inputWrap.appendChild(removeBtn);
            row.appendChild(inputWrap);
            row.appendChild(suggestions);

            let lastQuery = '';
            let abortCtl = null;
            let debounceTimer = null;

            function commitUnlinked() {
                const name = input.value.trim();
                if (!name) {
                    row.remove();
                    syncFromDom();
                    return;
                }
                row.replaceWith(buildDisplayRow({ name, facility_id: null }));
                syncFromDom();
            }

            function commitLinked(match) {
                row.replaceWith(buildDisplayRow({
                    name: match.unique_name,
                    facility_id: match.id,
                    state: match.state
                }));
                syncFromDom();
            }

            async function runSearch() {
                const q = input.value.trim();
                if (q.length < 2) {
                    suggestions.innerHTML = '';
                    suggestions.style.display = 'none';
                    return;
                }
                if (q === lastQuery) return;
                lastQuery = q;
                if (abortCtl) abortCtl.abort();
                abortCtl = new AbortController();
                try {
                    const res = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}&limit=12`, {
                        signal: abortCtl.signal
                    });
                    const json = await res.json();
                    if (!json || !json.success) throw new Error('search failed');
                    renderSuggestions(json.data || [], q);
                } catch (e) {
                    if (e.name !== 'AbortError') console.warn('facility search failed', e);
                }
            }

            function renderSuggestions(items, q) {
                suggestions.innerHTML = '';
                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.style.padding = '8px 10px';
                    empty.style.color = '#666';
                    empty.style.fontStyle = 'italic';
                    empty.textContent = 'No facility matches "' + q + '". Press Enter to save as plain text.';
                    suggestions.appendChild(empty);
                    suggestions.style.display = 'block';
                    return;
                }
                items.forEach(item => {
                    const opt = document.createElement('div');
                    opt.className = 'facility-suggestion';
                    opt.style.padding = '8px 10px';
                    opt.style.cursor = 'pointer';
                    opt.style.borderBottom = '1px solid #eee';
                    opt.addEventListener('mouseenter', () => { opt.style.background = '#f2eedf'; });
                    opt.addEventListener('mouseleave', () => { opt.style.background = ''; });

                    const nameSpan = document.createElement('div');
                    nameSpan.textContent = item.unique_name;
                    nameSpan.style.fontWeight = '500';
                    opt.appendChild(nameSpan);

                    const loc = [item.city, item.state].filter(Boolean).join(', ');
                    if (loc) {
                        const locSpan = document.createElement('div');
                        locSpan.textContent = loc;
                        locSpan.style.fontSize = '0.85em';
                        locSpan.style.color = '#666';
                        opt.appendChild(locSpan);
                    }

                    opt.addEventListener('mousedown', e => {
                        // mousedown (not click) so it fires before input blur cancels.
                        e.preventDefault();
                        commitLinked(item);
                    });
                    suggestions.appendChild(opt);
                });
                suggestions.style.display = 'block';
            }

            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(runSearch, 200);
            });

            input.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commitUnlinked();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    row.remove();
                    syncFromDom();
                }
            });

            input.addEventListener('blur', () => {
                // Delay so a mousedown-handled suggestion click can win first.
                setTimeout(() => {
                    if (!document.body.contains(row)) return;
                    commitUnlinked();
                }, 150);
            });

            setTimeout(() => input.focus(), 0);
            return row;
        }

        addBtn.addEventListener('click', () => {
            container.appendChild(buildEditingRow(''));
        });

        // Render any pre-existing entries (e.g., restored from localStorage or
        // populated by AI extraction).
        function renderInitial() {
            container.innerHTML = '';
            const items = Array.isArray(formData.facilities) ? formData.facilities : [];
            items.forEach(entry => {
                if (entry && entry.name) container.appendChild(buildDisplayRow(entry));
            });
        }
        renderInitial();

        // Expose a refresh hook so AI ingestion / template loaders can trigger a re-render.
        window.refreshFacilityPicker = renderInitial;
    }

    // --- AI PROCESSING ---
    function setupAIToggle() {
        const toggle = document.getElementById('ai-enabled');
        const aiControls = document.getElementById('ai-controls');
        const processBtn = document.getElementById('process-with-ai');
        const providerSelect = document.getElementById('ai-provider');
        const providerInfo = document.getElementById('provider-info');

        if (!toggle || !aiControls || !processBtn) return;

        const providerDescriptions = {
            'groq': 'Groq offers 14,400 free requests/day. Get API key from console.groq.com',
            'huggingface': 'Hugging Face has free inference API. Get API key from huggingface.co'
        };

        toggle.addEventListener('change', function() {
            aiControls.style.display = this.checked ? 'block' : 'none';
            localStorage.setItem('news_ai_enabled', this.checked);
        });

        // Restore AI toggle state
        const aiEnabled = localStorage.getItem('news_ai_enabled') === 'true';
        toggle.checked = aiEnabled;
        aiControls.style.display = aiEnabled ? 'block' : 'none';

        // Provider selection
        if (providerSelect && providerInfo) {
            // Default to Groq (the others' keys are unreliable here). One-time
            // migration flips users still on the old 'ollama' default to Groq,
            // but respects any deliberate choice made afterward.
            if (!localStorage.getItem('news_ai_provider_default_groq')) {
                if ((localStorage.getItem('news_ai_provider') || 'ollama') === 'ollama') {
                    localStorage.setItem('news_ai_provider', 'groq');
                }
                localStorage.setItem('news_ai_provider_default_groq', '1');
            }
            const savedProvider = localStorage.getItem('news_ai_provider') || 'groq';
            providerSelect.value = savedProvider;
            providerInfo.textContent = providerDescriptions[savedProvider];

            providerSelect.addEventListener('change', function() {
                const provider = this.value;
                localStorage.setItem('news_ai_provider', provider);
                providerInfo.textContent = providerDescriptions[provider];
            });
        }

        processBtn.addEventListener('click', processWithAI);
    }

    // ---- Duplicate-URL guard --------------------------------------------------
    // Before AI processing or submitting, ask the server whether a news entry
    // already exists for this article URL. Fails open (never blocks) on error;
    // the submit endpoint still rejects true duplicates with a 409 as a backstop.
    const newsDupeCheckUrl = (() => {
        const settings = window.KOP_NewsProcessor_Settings || {};
        if (settings.duplicateCheckUrl) return settings.duplicateCheckUrl;
        const base = settings.submissionUrl || '/wp-content/themes/child/api/save-news-submission.php';
        return base.replace(/[^/]*$/, 'check-duplicate-url.php');
    })();

    async function newsFindUrlDuplicates(url, extra) {
        const clean = (url || '').trim();
        const payload = { type: 'news', url: clean };
        if (extra && (extra.title || '').trim()) {
            payload.title = extra.title.trim();
            payload.outlet = (extra.outlet || '').trim();
        }
        if (!clean && !payload.title) return [];
        try {
            const res = await fetch(newsDupeCheckUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data && data.success) return data.duplicates || [];
        } catch (e) {
            console.warn('Duplicate URL check failed:', e);
        }
        return [];
    }

    /** Returns true and shows a popup if a news entry already uses this URL
     *  (or, when extra.title is given, the same title on the same outlet). */
    async function newsBlockedByDuplicate(url, extra) {
        const dupes = await newsFindUrlDuplicates(url, extra);
        if (!dupes.length) return false;
        const d = dupes[0] || {};
        const title = d.title ? `\n\nExisting entry: "${d.title}"` : '';
        window.alert(
            'This article already appears to be in our records, so it was not added again.'
            + title
            + '\n\nThank you for helping! There is nothing more you need to do.'
        );
        return true;
    }

    async function processWithAI() {
        const aiUrlInput = document.getElementById('ai-article-url');
        const url = aiUrlInput ? aiUrlInput.value.trim() : '';
        const pastedText = document.getElementById('ai-article-text') && document.getElementById('ai-article-text').value || '';
        const customInstructions = document.getElementById('ai-custom-instructions') && document.getElementById('ai-custom-instructions').value || '';
        const statusEl = document.getElementById('ai-status');
        const processBtn = document.getElementById('process-with-ai');
        const provider = 'groq'; // provider dropdown removed — always use Groq

        if (!url && !pastedText) {
            statusEl.innerHTML = '<span class="error">Please enter a URL or paste article text</span>';
            return;
        }

        // Block generation if this article URL is already in the system.
        if (url && await newsBlockedByDuplicate(url)) {
            statusEl.innerHTML = '';
            return;
        }

        // Save custom instructions if present
        if (customInstructions) {
            localStorage.setItem('news_ai_custom_instructions', customInstructions);
        }

        statusEl.innerHTML = '<span class="loading">🤖 Processing with AI...</span>';
        processBtn.disabled = true;

        try {
            const endpoint = (window.KOP_NewsProcessor_Settings && window.KOP_NewsProcessor_Settings.apiUrl)
                ? window.KOP_NewsProcessor_Settings.apiUrl
                : '/wp-content/themes/child/api/process-news-ai.php';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    url: url,
                    articleText: pastedText,
                    provider: provider,
                    customInstructions: customInstructions
                })
            });

            const result = await response.json();

            if (result.success) {
                // Populate form with AI results
                const data = result.data;

                // Copy URL to main form field
                if (url) formData.url = url;
                if (data.title) formData.title = data.title;
                if (data.author) formData.author = data.author;
                if (data.publicationDate) formData.publicationDate = data.publicationDate;
                if (data.publicationName) formData.publicationName = data.publicationName;
                if (data.location) formData.location = data.location;
                if (data.tags) {
                    const filteredTags = filterGenericTags(data.tags);
                    formData.tags = filteredTags.join('\n');
                }
                if (data.facilities) {
                    formData.facilities = normalizeFacilitiesValue(data.facilities);
                    if (typeof window.refreshFacilityPicker === 'function') {
                        window.refreshFacilityPicker();
                    }
                }
                // The AI response is not schema-enforced — a model may return a
                // string where an array is expected. Coerce instead of calling
                // array methods blindly: a non-array contentWarnings persisted
                // to localStorage broke init() on every reload until cleared.
                const toLines = v => Array.isArray(v) ? v.join('\n') : String(v || '');
                if (data.staff) formData.staff = toLines(data.staff);
                if (data.survivors) formData.survivors = toLines(data.survivors);
                if (data.summary) formData.summary = data.summary;
                if (data.alternateTitle) {
                    formData.alternateTitle = data.alternateTitle;
                    formData.needsAlternateTitle = true;
                }
                if (data.contentWarnings) {
                    formData.contentWarnings = Array.isArray(data.contentWarnings)
                        ? data.contentWarnings
                        : [String(data.contentWarnings)];
                }
                if (data.articleType) formData.articleType = data.articleType;

                // Update type-specific fields
                if (data.typeSpecificData) {
                    Object.assign(formData, data.typeSpecificData);
                }

                saveToLocalStorage();
                location.reload(); // Reload to show all populated fields

                statusEl.innerHTML = '<span class="success">✅ Article processed successfully!</span>';
            } else {
                statusEl.innerHTML = `<span class="error">❌ ${result.error || 'Processing failed'}</span>`;
            }
        } catch (error) {
            console.error('AI processing error:', error);
            statusEl.innerHTML = `<span class="error">❌ Network error: ${error.message}</span>`;
        } finally {
            processBtn.disabled = false;
        }
    }

    // --- DATABASE SUBMISSION ---
    function setupDatabaseSubmission() {
        const submitBtn = document.getElementById('submit-to-db');
        const modal = document.getElementById('newsSubmitModal');
        const cancelBtn = document.getElementById('newsCancelSubmitBtn');
        const confirmBtn = document.getElementById('newsConfirmSubmitBtn');
        const statusEl = document.getElementById('newsSubmitStatus');

        if (!submitBtn || !modal) return;

        submitBtn.addEventListener('click', () => {
            modal.style.display = 'flex';
            if (statusEl) statusEl.innerHTML = '';
        });

        cancelBtn.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        confirmBtn.addEventListener('click', async () => {
            const title = formData.title || '';
            
            if (!title.trim()) {
                statusEl.innerHTML = '<span class="error">❌ Article title is required</span>';
                return;
            }

            // Block submission if this article URL — or the same title on the
            // same outlet — is already in the system.
            const dupExtra = { title: formData.title, outlet: formData.publicationName };
            if (await newsBlockedByDuplicate(formData.url, dupExtra)) {
                modal.style.display = 'none';
                return;
            }

            statusEl.innerHTML = '<span class="loading">⏳ Submitting...</span>';
            confirmBtn.disabled = true;

            // Build submission data
            const submissionData = {
                title: formData.title,
                alternateTitle: formData.alternateTitle,
                author: formData.author,
                publicationName: formData.publicationName,
                publicationDate: formData.publicationDate,
                url: formData.url,
                location: formData.location,
                tags: filterGenericTags((formData.tags || '').split('\n')).join('\n'),
                articleType: formData.articleType,
                facilities: formData.facilities,
                staff: formData.staff,
                survivors: formData.survivors,
                contentWarnings: formData.contentWarnings,
                summary: formData.summary,
                needsAlternateTitle: formData.needsAlternateTitle,
                // Type-specific fields
                plaintiffs: formData.plaintiffs,
                defendants: formData.defendants,
                legalRep: formData.legalRep,
                dateFiled: formData.dateFiled,
                jurisdiction: formData.jurisdiction,
                pressReleases: formData.pressReleases,
                relatedCoverage: formData.relatedCoverage,
                staffMemberName: formData.staffMemberName,
                arrestFacilityName: formData.arrestFacilityName,
                misconductDates: formData.misconductDates,
                charges: formData.charges,
                caseStatus: formData.caseStatus,
                closureFacilityName: formData.closureFacilityName,
                closureLocation: formData.closureLocation,
                closureDate: formData.closureDate,
                closureContext: formData.closureContext,
                corporateFacilityNames: formData.corporateFacilityNames,
                corporateLocation: formData.corporateLocation,
                keyPersonnel: formData.keyPersonnel,
                ownership: formData.ownership,
                // Submission metadata
                submittedBy: document.getElementById('newsSubmitterEmail') && document.getElementById('newsSubmitterEmail').value || '',
                submissionNotes: document.getElementById('newsSubmissionNotes') && document.getElementById('newsSubmissionNotes').value || ''
            };

            try {
                const endpoint = (window.KOP_NewsProcessor_Settings && window.KOP_NewsProcessor_Settings.submissionUrl)
                    ? window.KOP_NewsProcessor_Settings.submissionUrl
                    : '/wp-content/themes/child/api/save-news-submission.php';

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(submissionData)
                });

                const result = await response.json();

                if (result.success) {
                    statusEl.innerHTML = `<span class="success">✅ Submitted successfully! (ID: ${result.id})</span>`;
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 2000);
                } else {
                    // Duplicate rejections put the human-readable text in
                    // `message` (error is just the token 'duplicate').
                    statusEl.innerHTML = `<span class="error">❌ ${escAttr(result.message || result.error || 'Submission failed')}</span>`;
                }
            } catch (error) {
                console.error('Submission error:', error);
                statusEl.innerHTML = `<span class="error">❌ Network error: ${error.message}</span>`;
            } finally {
                confirmBtn.disabled = false;
            }
        });
    }

    function restoreCustomInstructions() {
        const saved = localStorage.getItem('news_ai_custom_instructions');
        const textarea = document.getElementById('ai-custom-instructions');
        if (saved && textarea) {
            textarea.value = saved;
        }
    }

    function applyUrlPrefill() {
        const params = new URLSearchParams(window.location.search);
        const stateName = params.get('prefill_state');
        if (!stateName) return;

        const locationInput = document.querySelector('[name="location"]');
        if (!locationInput) return;

        const current = (locationInput.value || '').trim();
        if (!current || !current.toLowerCase().includes(stateName.toLowerCase())) {
            locationInput.value = stateName;
            formData.location = stateName;
            locationInput.dispatchEvent(new Event('input', { bubbles: true }));
            saveToLocalStorage();
        }
    }

    // Initialize on load
    function init() {
        loadFromLocalStorage();
        setupSectionToggles();
        setupFormInputs();
        setupContentWarnings();
        setupArticleTypes();
        setupSaveButtons();
        setupExportButtons();
        setupAlnewstle();
        setupClearButton();
        setupTemplates();
        setupDynamicFields();
        setupFacilityPicker();
        setupAIToggle();
        setupDatabaseSubmission();
        restoreFormState();
        restoreSavedValues();
        restoreCustomInstructions();
        applyUrlPrefill();
        if (typeof initializeAutocompleteFields === 'function') {
            initializeAutocompleteFields();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
