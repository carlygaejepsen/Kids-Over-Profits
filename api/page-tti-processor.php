<?php
/*
 * Template Name: TTI Article Processor
 * Description: A standalone page template for the TTI processing tool.
 */

get_header(); // Loads your site's navigation, CSS, and enqueued scripts
?>

<div id="tti-main-container" class="w-full max-w-4xl mx-auto my-10 px-4">
    <div id="tti-tool-root">Loading Processor...</div>
</div>

<script type="text/babel">
(function() {
    const { useState, useEffect } = React;
    const { 
        AlertCircle, ChevronDown, ChevronRight, Check, FileText, Tag, 
        AlertTriangle, FileEdit, Briefcase, Save, Trash2 
    } = lucideReact;

    // --- COMPONENT START ---
    function TTIArticleProcessor() {
        const [formData, setFormData] = useState({
            title: '', author: '', publicationDate: '', publicationName: '', url: '',
            facilities: '', staff: '', survivors: '', contentWarnings: [],
            summary: '', alternateTitle: '', needsAlternateTitle: false,
            articleType: '', plaintiffs: '', defendants: '', legalRep: '',
            dateFiled: '', jurisdiction: '', pressReleases: '', relatedCoverage: '',
            staffMemberName: '', arrestFacilityName: '', misconductDates: '',
            charges: '', caseStatus: '', closureFacilityName: '', closureLocation: '',
            closureDate: '', closureContext: '', corporateFacilityNames: '',
            corporateLocation: '', keyPersonnel: '', ownership: ''
        });

        const [savedValues, setSavedValues] = useState({
            authors: [], publications: [], facilities: [], staff: [], jurisdictions: [], legalReps: []
        });

        const [expandedSections, setExpandedSections] = useState({
            basic: true, warnings: false, summary: false, type: false
        });

        const contentWarningsList = [
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

        const toggleSection = (section) => {
            setExpandedSections(prev => ({ ...prev, [section]: !prev[section] }));
        };

        const handleInputChange = (field, value) => {
            setFormData(prev => ({ ...prev, [field]: value }));
        };

        const saveValue = (category, value) => {
            if (!value || value.trim() === '') return;
            setSavedValues(prev => {
                const newValues = { ...prev };
                if (!newValues[category].includes(value.trim())) {
                    newValues[category] = [...newValues[category], value.trim()];
                }
                return newValues;
            });
        };

        const removeValue = (category, value) => {
            setSavedValues(prev => ({
                ...prev,
                [category]: prev[category].filter(v => v !== value)
            }));
        };

        const toggleWarning = (warning) => {
            setFormData(prev => ({
                ...prev,
                contentWarnings: prev.contentWarnings.includes(warning)
                    ? prev.contentWarnings.filter(w => w !== warning)
                    : [...prev.contentWarnings, warning]
            }));
        };

        const exportData = (format = 'json') => {
            const output = {
                basicDetails: {
                    title: formData.title,
                    author: formData.author,
                    publicationDate: formData.publicationDate,
                    publicationName: formData.publicationName,
                    url: formData.url,
                    facilities: formData.facilities.split('\n').filter(f => f.trim()),
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
                output.eventDetails = { relatedCoverage: formData.relatedCoverage.split('\n').filter(r => r.trim()) };
            } else if (formData.articleType === 'arrest') {
                output.arrestDetails = {
                    staffMemberName: formData.staffMemberName, facilityName: formData.arrestFacilityName,
                    misconductDates: formData.misconductDates, charges: formData.charges, caseStatus: formData.caseStatus
                };
            } else if (formData.articleType === 'closure') {
                output.closureDetails = {
                    facilityName: formData.closureFacilityName, location: formData.closureLocation,
                    closureDate: formData.closureDate, context: formData.closureContext
                };
            } else if (formData.articleType === 'corporate') {
                output.corporateDetails = {
                    facilityNames: formData.corporateFacilityNames, location: formData.corporateLocation,
                    keyPersonnel: formData.keyPersonnel, ownership: formData.ownership
                };
            }

            let content, mimeType, extension;
            if (format === 'text') {
                let text = 'TTI NEWS ARTICLE PROCESSING REPORT\n============================================================\n\n';
                text += `--- BASIC DETAILS ---\nTitle: ${formData.title}\nAuthor: ${formData.author}\nPublication: ${formData.publicationName}\nDate: ${formData.publicationDate}\nURL: ${formData.url}\n\n`;
                
                if (output.basicDetails.facilities.length) text += `Facilities:\n${output.basicDetails.facilities.map(f => '  - ' + f).join('\n')}\n\n`;
                if (formData.contentWarnings.length) text += `--- CONTENT WARNINGS ---\n${formData.contentWarnings.map(w => '  - ' + w).join('\n')}\n\n`;
                text += `--- SUMMARY ---\n${formData.summary}\n\n`;
                
                content = text; mimeType = 'text/plain'; extension = 'txt';
            } else {
                content = JSON.stringify(output, null, 2); mimeType = 'application/json'; extension = 'json';
            }

            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = `tti-article-${Date.now()}.${extension}`; a.click();
        };

        const SectionHeader = ({ icon: Icon, title, section, count }) => (
            <button onClick={() => toggleSection(section)} className="w-full flex items-center justify-between p-4 bg-gray-50 hover:bg-gray-100 border-b border-gray-200 transition-colors">
                <div className="flex items-center gap-3">
                    <Icon className="w-5 h-5" style={{ color: '#33a7b5' }} />
                    <h2 className="text-lg font-semibold text-gray-800">{title}</h2>
                    {count !== undefined && <span className="text-sm text-gray-500 bg-white px-2 py-1 rounded-full">{count}</span>}
                </div>
                {expandedSections[section] ? <ChevronDown className="w-5 h-5 text-gray-600" /> : <ChevronRight className="w-5 h-5 text-gray-600" />}
            </button>
        );

        const SaveableInput = ({ label, field, category, placeholder, type = "text" }) => (
            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
                <div className="flex gap-2">
                    <div className="flex-1 relative">
                        <input type={type} list={`${field}-list`} value={formData[field]} onChange={(e) => handleInputChange(field, e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-md" style={{ outlineColor: '#33a7b5' }} placeholder={placeholder} />
                        <datalist id={`${field}-list`}>{savedValues[category]?.map((value, idx) => <option key={idx} value={value} />)}</datalist>
                    </div>
                    <button onClick={() => saveValue(category, formData[field])} className="px-3 py-2 rounded-md" style={{ backgroundColor: '#ecf385', color: '#000000' }}><Save className="w-4 h-4" /></button>
                </div>
            </div>
        );

        return (
            <div className="bg-white rounded-lg shadow-lg overflow-hidden" style={{ fontFamily: 'sans-serif', maxWidth: '100%' }}>
                <div className="p-6 text-white" style={{ background: 'linear-gradient(to right, #33a7b5, #000080)' }}>
                    <h1 className="text-2xl font-bold mb-2">TTI News Processor</h1>
                    <p style={{ color: '#aee0ed' }}>Trauma-sensitive article processing</p>
                </div>

                <div className="border-b border-gray-200">
                    <SectionHeader icon={FileText} title="1. Basic Details" section="basic" />
                    {expandedSections.basic && (
                        <div className="p-6 space-y-4 bg-white">
                            <SaveableInput label="Article Title" field="title" category="titles" placeholder="Title" />
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <SaveableInput label="Author" field="author" category="authors" placeholder="Name" />
                                <SaveableInput label="Publication" field="publicationName" category="publications" placeholder="Publication" />
                            </div>
                                <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Facilities (One per line)</label>
                                <textarea value={formData.facilities} onChange={(e) => handleInputChange('facilities', e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-md" rows={3} />
                            </div>
                        </div>
                    )}
                </div>

                <div className="border-b border-gray-200">
                    <SectionHeader icon={AlertTriangle} title="2. Content Warnings" section="warnings" count={formData.contentWarnings.length} />
                    {expandedSections.warnings && (
                        <div className="p-6 bg-white">
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                                {contentWarningsList.map(w => (
                                    <button key={w} onClick={() => toggleWarning(w)} className={`px-3 py-2 text-sm rounded-md border text-left ${formData.contentWarnings.includes(w) ? 'bg-red-400 text-white border-red-400' : 'bg-gray-50 text-gray-700'}`}>
                                        {w}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="border-b border-gray-200">
                    <SectionHeader icon={FileEdit} title="3. Summary" section="summary" />
                    {expandedSections.summary && (
                        <div className="p-6 space-y-4 bg-white">
                                <div className="p-4 rounded border-l-4 bg-blue-50 border-blue-400">
                                <p className="text-sm text-blue-900">Use factual, neutral, concise language.</p>
                            </div>
                            <textarea value={formData.summary} onChange={(e) => handleInputChange('summary', e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-md" rows={4} placeholder="Summary..." />
                        </div>
                    )}
                </div>

                <div className="border-b border-gray-200">
                        <SectionHeader icon={Tag} title="4. Article Type" section="type" />
                        {expandedSections.type && (
                        <div className="p-6 space-y-6 bg-white">
                            <div className="grid grid-cols-2 gap-2">
                                {articleTypes.map(type => (
                                    <button key={type.value} onClick={() => handleInputChange('articleType', type.value)} className={`px-4 py-3 text-sm rounded-md border ${formData.articleType === type.value ? 'bg-blue-900 text-white' : 'bg-white text-gray-700'}`}>
                                        {type.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        )}
                </div>

                <div className="p-6 bg-gray-50">
                    <div className="grid grid-cols-2 gap-3">
                        <button onClick={() => exportData('json')} className="bg-blue-600 text-white py-3 px-6 rounded-md hover:bg-blue-700">Export JSON</button>
                        <button onClick={() => exportData('text')} className="bg-orange-500 text-white py-3 px-6 rounded-md hover:bg-orange-600">Export Text</button>
                    </div>
                </div>
            </div>
        );
    }
    // --- COMPONENT END ---

    const root = ReactDOM.createRoot(document.getElementById('tti-tool-root'));
    root.render(<TTIArticleProcessor />);
})();
</script>

<?php get_footer(); ?>