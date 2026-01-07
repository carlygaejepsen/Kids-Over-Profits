<?php
/**
 * Template Name: TTI Wiki Entry Generator
 *
 * Provides a custom form interface so editors can assemble wiki entries.
 */

get_header();
?>

<div class="container">
    <div class="admin-header">
        <h1>TTI Wiki Entry Generator</h1>
        <p>Create and edit Reddit wiki entries</p>
    </div>

    <div class="wiki-editor-page">
        
        <!-- Empty-entry banner (hidden by default) -->
        <div id="emptyEntryBanner" class="admin-warning" style="display:none; background:#fff4f4; border-color:#e0b4b4;">
            <strong>Reddit Wiki Entry Page has not yet been created.</strong>
            <p style="margin:6px 0 0 0;">This wiki entry appears to be empty on the Reddit wiki. You can create a new entry by filling the form below.</p>
            <div style="margin-top:8px;">
                <button type="button" id="emptyBannerCreateBtn" class="btn btn-primary">Create New Entry</button>
                <button type="button" id="emptyBannerCloseBtn" class="btn btn-secondary">Dismiss</button>
            </div>
        </div>

        <!-- Editor Mode Toggle -->
        <div class="editor-mode-toggle">
            <button type="button" id="modeFormBtn" class="mode-btn active">📝 Form Editor</button>
            <button type="button" id="modeMarkdownBtn" class="mode-btn">💻 Markdown Editor</button>
        </div>

        <!-- Wiki Entry Management (Refactored Split-Pane View) -->
        <div class="wiki-management-grid entry-browser-section" data-wiki-index-json="<?php echo get_stylesheet_directory_uri(); ?>/js/data/reddit-wiki/index.json" data-wiki-programs-base="<?php echo get_stylesheet_directory_uri(); ?>/js/data/reddit-wiki/">
            
            <!-- LEFT PANE: Index Selection -->
            <div class="index-selection-pane project-management">
                <h2>Browse Indexes</h2>
                <div class="category-tabs">
                    <button class="category-tab active" data-category="location-indexes">📍 Locations</button>
                    <button class="category-tab" data-category="organization-indexes">🏢 Organizations</button>
                </div>

                <div class="category-contents-wrapper">
                    <!-- Location Index Selector -->
                    <div id="location-indexes-content" class="category-content">
                        <div class="form-group">
                            <label>Select State/Location</label>
                            <select id="locationIndexSelect" class="input-form">
                                <option value="">Loading locations...</option>
                            </select>
                        </div>
                    </div>

                    <!-- Organization Index Selector -->
                    <div id="organization-indexes-content" class="category-content view-hidden">
                        <div class="form-group">
                            <label>Select Parent Company</label>
                            <select id="orgIndexSelect" class="input-form">
                                <option value="">Loading organizations...</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT PANE: Facilities List -->
            <div class="facilities-list-pane project-management" id="indexBrowserPanel">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; align-items:center;">
                    <h2 style="margin:0;">Facilities</h2>
                    <button type="button" id="backBtn" class="btn btn-sm" style="display: none;">← Back</button>
                </div>
                
                <div class="form-group">
                    <input type="text" id="locationSearch" class="input-form" placeholder="Filter facilities list...">
                </div>
                
                <div id="indexEntriesList" class="index-entries-list">
                    <p class="loading">Select an index on the left to see facilities...</p>
                </div>
            </div>
        </div>

        <!-- Bulk Upload Section -->
        <div class="project-management bulk-upload-section">
            <h2>Bulk Actions</h2>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" id="toggleBulkUploadBtn" class="btn btn-secondary">📤 Bulk Upload Markdown Files</button>
                <button type="button" id="toggleImportBtn" class="btn btn-secondary">📥 Import from Clipboard</button>
            </div>
            
            <!-- Bulk Upload Panel -->
            <div id="bulkUploadPanel" class="bulk-upload-panel" style="display: none;">
                <h3>Upload Multiple Markdown Files</h3>
                <p>Select multiple .md files or a folder containing wiki entry markdown files.</p>
                <div class="upload-controls" style="margin-bottom:15px;">
                    <input type="file" id="bulkFileInput" accept=".md,.txt" multiple class="input-form">
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" id="uploadFilesBtn" class="btn btn-primary">Upload Files</button>
                    <button type="button" id="cancelBulkUploadBtn" class="btn btn-danger">Close</button>
                </div>
                <div id="uploadProgress" class="upload-progress" style="display: none; margin-top:15px;">
                    <div class="progress-bar" style="height:10px; background:#eee; border-radius:5px; overflow:hidden;">
                        <div id="progressBarFill" class="progress-bar-fill" style="height:100%; background:#33A7B5; width:0%;"></div>
                    </div>
                    <p id="uploadStatus" class="upload-status" style="text-align:center; margin-top:5px; font-weight:600;"></p>
                </div>
                <div id="uploadResults" class="upload-results" style="margin-top:15px;"></div>
            </div>

            <!-- Import Panel -->
            <div id="importPanel" class="import-panel" style="display: none;">
                <h3>Paste Reddit Markdown</h3>
                <p>Paste an existing Reddit wiki entry below to import it.</p>
                <textarea id="importTextarea" class="import-textarea" rows="10" placeholder="Paste your Reddit markdown here..."></textarea>
                <div class="import-controls" style="margin-top:15px; display:flex; gap:10px;">
                    <button type="button" id="importBtn" class="btn btn-primary">Import & Fill Form</button>
                    <button type="button" id="cancelImportBtn" class="btn btn-danger">Close</button>
                </div>
            </div>
        </div>

        <form id="wikiForm">
            <fieldset>
                <legend>Basic Information</legend>
                <label for="programName">Program Name:</label>
                <input type="text" id="programName" name="programName" data-autocomplete-category="facility" placeholder="e.g., Turn-About Ranch">

                <label for="yearsActive">Years Active:</label>
                <input type="text" id="yearsActive" name="yearsActive" placeholder="e.g., 1989-present">

                <label for="cityState">City, ST:</label>
                <input type="text" id="cityState" name="cityState" data-autocomplete-category="location" placeholder="e.g., Escalante, UT">

                <label for="programType">Program Type:</label>
                <input type="text" id="programType" name="programType" data-autocomplete-category="type" placeholder="e.g., Residential Treatment Center">
            </fieldset>

            <!-- Organization-Specific Fields -->
            <fieldset class="organization-only-field">
                <legend>Organization Information</legend>
                <div class="field-row">
                    <div class="field-group">
                        <label for="headquarters">Headquarters / Main Office:</label>
                        <input type="text" id="headquarters" name="headquarters" placeholder="e.g., Franklin, TN">
                    </div>
                    <div class="field-group">
                        <label for="parentCompany">Parent Company:</label>
                        <input type="text" id="parentCompany" name="parentCompany" data-autocomplete-category="operator" placeholder="e.g., Universal Health Services">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="parentCompanyLink">Parent Company Wiki Link:</label>
                        <input type="text" id="parentCompanyLink" name="parentCompanyLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>

                <h4>Facilities Operated</h4>
                <div class="organization-facilities-list" id="organizationFacilitiesList" style="background-color: #F0F9FA; border: 2px solid #AEE0ED; border-radius: 5px; padding: 15px; min-height: 100px;">
                    <p style="color: #999; font-style: italic;">No facilities found. Facilities will appear here when this organization page is loaded.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>History and Background Information</legend>
                <div class="field-row">
                    <div class="field-group">
                        <label for="yearFounded">Year Founded:</label>
                        <input type="text" id="yearFounded" name="yearFounded" placeholder="e.g., 1989">
                    </div>
                    <div class="field-group facility-only-field">
                        <label for="ageRange">Age Range:</label>
                        <input type="text" id="ageRange" name="ageRange" placeholder="e.g., 13-17">
                    </div>
                    <div class="field-group facility-only-field">
                        <label for="capacity">Maximum Enrollment/Capacity:</label>
                        <input type="text" id="capacity" name="capacity" placeholder="e.g., 40 students">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="ownerName">Owner/Operator:</label>
                        <input type="text" id="ownerName" name="ownerName" data-autocomplete-category="operator" placeholder="e.g., Aspen Education Group">
                    </div>
                    <div class="field-group">
                        <label for="ownerLink">Owner Wiki Link:</label>
                        <input type="text" id="ownerLink" name="ownerLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="rebrand">Rebrand/Spin-off of:</label>
                        <input type="text" id="rebrand" name="rebrand" data-autocomplete-category="facility" placeholder="e.g., Cross Creek Manor">
                    </div>
                    <div class="field-group">
                        <label for="rebrandLink">Rebrand Link:</label>
                        <input type="text" id="rebrandLink" name="rebrandLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>
                <div class="field-row facility-only-field">
                    <div class="field-group">
                        <label for="avgStay">Avg. Stay:</label>
                        <input type="text" id="avgStay" name="avgStay" placeholder="e.g., 100 days">
                    </div>
                    <div class="field-group">
                        <label for="tuition">Tuition:</label>
                        <input type="text" id="tuition" name="tuition" placeholder="e.g., $40,000+">
                    </div>
                </div>
                <div class="field-row facility-only-field">
                    <div class="field-group">
                        <label for="natsapMember">NATSAP Member?</label>
                        <select id="natsapMember" name="natsapMember">
                            <option value="">Unknown</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                            <option value="former">Former Member</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="natsapYear">NATSAP Since:</label>
                        <input type="text" id="natsapYear" name="natsapYear" placeholder="e.g., 1999">
                    </div>
                </div>
                <div class="facility-only-field">
                    <label>Target Diagnoses/Behaviors (select all that apply):</label>
                    <div class="diagnoses-checklist">
                        <div class="checklist-column">
                            <h4>Clinical Diagnoses</h4>
                            <label><input type="checkbox" name="diagnoses" value="ADHD"> ADHD</label>
                            <label><input type="checkbox" name="diagnoses" value="Autism Spectrum Disorder"> Autism Spectrum Disorder</label>
                            <label><input type="checkbox" name="diagnoses" value="Bipolar Disorder"> Bipolar Disorder</label>
                            <label><input type="checkbox" name="diagnoses" value="Depression"> Depression</label>
                            <label><input type="checkbox" name="diagnoses" value="Anxiety"> Anxiety</label>
                            <label><input type="checkbox" name="diagnoses" value="OCD"> OCD</label>
                            <label><input type="checkbox" name="diagnoses" value="PTSD"> PTSD</label>
                            <label><input type="checkbox" name="diagnoses" value="Oppositional Defiant Disorder"> Oppositional Defiant Disorder (ODD)</label>
                            <label><input type="checkbox" name="diagnoses" value="Conduct Disorder"> Conduct Disorder</label>
                            <label><input type="checkbox" name="diagnoses" value="Eating Disorder"> Eating Disorder</label>
                            <label><input type="checkbox" name="diagnoses" value="Substance Abuse"> Substance Abuse</label>
                            <label><input type="checkbox" name="diagnoses" value="Borderline Personality Disorder"> Borderline Personality Disorder (BPD)</label>
                            <label><input type="checkbox" name="diagnoses" value="Psychiatric Disorders"> Psychiatric Disorders</label>
                            <label><input type="checkbox" name="diagnoses" value="Behavioral Disorders"> Behavioral Disorders</label>
                            <label><input type="checkbox" name="diagnoses" value="Emotional Disorders"> Emotional Disorders</label>
                            <label><input type="checkbox" name="diagnoses" value="Co-occurring Disorders"> Co-occurring Disorders</label>
                            <label><input type="checkbox" name="diagnoses" value="Reactive Attachment Disorder"> Reactive Attachment Disorder (RAD)</label>
                            <label><input type="checkbox" name="diagnoses" value="Personality Disorders"> Personality Disorders</label>
                        </div>
                        <div class="checklist-column">
                            <h4>Behavioral Issues</h4>
                            <label><input type="checkbox" name="diagnoses" value="defiance"> Defiance</label>
                            <label><input type="checkbox" name="diagnoses" value="aggression"> Aggression</label>
                            <label><input type="checkbox" name="diagnoses" value="self-harm"> Self-harm</label>
                            <label><input type="checkbox" name="diagnoses" value="running away"> Running away</label>
                            <label><input type="checkbox" name="diagnoses" value="academic struggles"> Academic struggles</label>
                            <label><input type="checkbox" name="diagnoses" value="social problems"> Social problems</label>
                            <label><input type="checkbox" name="diagnoses" value="family conflict"> Family conflict</label>
                            <label><input type="checkbox" name="diagnoses" value="lying"> Lying</label>
                            <label><input type="checkbox" name="diagnoses" value="stealing"> Stealing</label>
                            <label><input type="checkbox" name="diagnoses" value="emotional dysregulation"> Emotional dysregulation</label>
                            <label><input type="checkbox" name="diagnoses" value="impulsive behavior"> Impulsive behavior</label>
                            <label><input type="checkbox" name="diagnoses" value="sexually reactive behaviors"> Sexually reactive behaviors</label>
                            <label><input type="checkbox" name="diagnoses" value="attachment issues"> Attachment issues</label>
                            <label><input type="checkbox" name="diagnoses" value="adoption issues"> Adoption issues</label>
                            <label><input type="checkbox" name="diagnoses" value="trafficking"> Trafficking</label>
                        </div>
                    </div>
                    <label for="customDiagnoses">Additional Diagnoses/Behaviors (comma-separated):</label>
                    <input type="text" id="customDiagnoses" name="customDiagnoses" placeholder="Add any diagnoses not listed above">
                </div>
                <div class="field-row facility-only-field">
                    <div class="field-group flex-2">
                        <label for="mainAddress">Main Address:</label>
                        <input type="text" id="mainAddress" name="mainAddress" data-autocomplete-category="location" placeholder="e.g., 280 N 300 E, Escalante, UT 84726">
                    </div>
                    <div class="field-group">
                        <label for="addressLink">Google Maps Link:</label>
                        <input type="text" id="addressLink" name="addressLink" placeholder="https://...">
                    </div>
                </div>
                <div class="field-row facility-only-field">
                    <div class="field-group">
                        <label for="accreditingBody">Accrediting Body:</label>
                        <input type="text" id="accreditingBody" name="accreditingBody" data-autocomplete-category="accreditation" placeholder="e.g., NWAC">
                    </div>
                    <div class="field-group">
                        <label for="accreditingBodyLink">Accreditor Wiki Link:</label>
                        <input type="text" id="accreditingBodyLink" name="accreditingBodyLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>
                <label for="historyNotes">Additional History Notes (will be included as-is):</label>
                <textarea id="historyNotes" name="historyNotes" rows="4" placeholder="Any additional background information that doesn't fit the fields above..."></textarea>
                <div class="facility-only-field">
                    <hr>
                    <h4>Affiliations</h4>
                    <div class="form-adder">
                        <div class="field-row">
                            <div class="field-group">
                                <label for="affiliationName">Affiliation Name:</label>
                                <input type="text" id="affiliationName" placeholder="e.g., NATSAP">
                            </div>
                            <div class="field-group">
                                <label for="affiliationLink">Link (optional):</label>
                                <input type="text" id="affiliationLink" placeholder="https://...">
                            </div>
                        </div>
                        <button type="button" class="add-btn" id="addAffiliationBtn">Add Affiliation</button>
                    </div>
                    <div class="list-preview" id="affiliationListOutput"></div>
                    <hr>
                    <h4>Additional Locations / Campuses</h4>
                    <div class="form-adder">
                        <div class="field-row">
                            <div class="field-group">
                                <label for="campusName">Campus Name:</label>
                                <input type="text" id="campusName" placeholder="e.g., Girls Campus">
                            </div>
                            <div class="field-group">
                            <label for="campusLocation">Location:</label>
                            <input type="text" id="campusLocation" data-autocomplete-category="location" placeholder="e.g., Cedar City, UT">
                            </div>
                        </div>
                        <button type="button" class="add-btn" id="addCampusBtn">Add Campus</button>
                    </div>
                    <div class="list-preview" id="campusListOutput"></div>
                    <hr>
                </div>
                <h4>Ownership Changes</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="ownerChangeYear">Year:</label>
                            <input type="text" id="ownerChangeYear" placeholder="e.g., 2015">
                        </div>
                        <div class="field-group">
                        <label for="ownerChangePrevious">Previous Owner:</label>
                        <input type="text" id="ownerChangePrevious" data-autocomplete-category="operator" placeholder="e.g., Aspen Education">
                        </div>
                        <div class="field-group">
                            <label for="ownerChangePreviousLink">Previous Owner Link (optional):</label>
                            <input type="text" id="ownerChangePreviousLink" placeholder="https://...">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                        <label for="ownerChangeNew">New Owner:</label>
                        <input type="text" id="ownerChangeNew" data-autocomplete-category="operator" placeholder="e.g., Sequel Youth">
                        </div>
                        <div class="field-group">
                            <label for="ownerChangeNewLink">New Owner Link (optional):</label>
                            <input type="text" id="ownerChangeNewLink" placeholder="https://...">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addOwnerChangeBtn">Add Ownership Change</button>
                </div>
                <div class="list-preview" id="ownerChangeListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Founders and Notable Staff</legend>
                <div class="form-adder">
                    <label for="staffName">Name:</label>
                    <input type="text" id="staffName" data-autocomplete-category="human">
                    <label for="staffRole">Role:</label>
                    <input type="text" id="staffRole" data-autocomplete-category="role">
                    <label for="staffBio">Bio/Details (can include Markdown links):</label>
                    <textarea id="staffBio" rows="3" placeholder="e.g., Previously worked at [Another Program](link)..."></textarea>
                    
                    <div class="previous-roles-section">
                        <label>Previous Roles:</label>
                        <div id="previousRolesContainer">
                            <div class="previous-role-entry" data-index="0">
                                <div class="field-row">
                                    <div class="field-group">
                                        <input type="text" class="prev-role-title" placeholder="Role/Title (e.g., Clinical Director)">
                                    </div>
                                    <div class="field-group">
                                        <input type="text" class="prev-role-employer" placeholder="Employer (e.g., Another Campus)">
                                    </div>
                                    <button type="button" class="remove-prev-role-btn" title="Remove this role">×</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="add-btn add-prev-role-btn" id="addPreviousRoleBtn">+ Add Previous Role</button>
                    </div>
                    
                    <label class="checkbox-inline">
                        <input type="checkbox" id="staffIsFormer">
                        <span>Mark as former/previous staff member</span>
                    </label>
                    <button type="button" class="add-btn" id="addStaffBtn">Add Staff Member</button>
                </div>
                <div class="list-preview" id="staffListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Program Structure</legend>
                <div class="field-row">
                    <div class="field-group">
                        <label for="levelSystemType">Level/Phase System:</label>
                        <select id="levelSystemType" name="levelSystemType">
                            <option value="">None/Unknown</option>
                            <option value="level">Level System</option>
                            <option value="phase">Phase System</option>
                            <option value="point">Point System</option>
                            <option value="tier">Tier System</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="levelCount">Number of Levels/Phases:</label>
                        <input type="text" id="levelCount" name="levelCount" placeholder="e.g., 4">
                    </div>
                </div>
                <label for="levelSystemDesc">Level/Phase System Details:</label>
                <textarea id="levelSystemDesc" name="levelSystemDesc" rows="6" placeholder="Describe the level or phase system here. Include level names, durations, privileges, restrictions, etc."></textarea>
                <hr>
                <h4>Education</h4>
                <div class="field-row">
                    <div class="field-group">
                        <label for="educationType">Education Provided:</label>
                        <select id="educationType" name="educationType">
                            <option value="">Unknown</option>
                            <option value="accredited">Accredited On-Site School</option>
                            <option value="online">Online/Computer-Based</option>
                            <option value="packet">Packet-Based/Worksheets</option>
                            <option value="limited">Limited/Sporadic</option>
                            <option value="none">None Provided</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="educationAccreditor">School Accreditor:</label>
                        <input type="text" id="educationAccreditor" name="educationAccreditor" placeholder="e.g., Cognia, NWAC">
                    </div>
                </div>
                <hr>
                <h4>Therapy/Treatment</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="therapyType">Therapy Type:</label>
                            <select id="therapyType">
                                <option value="individual">Individual Therapy</option>
                                <option value="group">Group Therapy</option>
                                <option value="family">Family Therapy</option>
                                <option value="cbt">CBT</option>
                                <option value="dbt">DBT</option>
                                <option value="emdr">EMDR</option>
                                <option value="equine">Equine Therapy</option>
                                <option value="art">Art Therapy</option>
                                <option value="wilderness">Wilderness Therapy</option>
                                <option value="attack">Attack Therapy/Confrontation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label for="therapyFrequency">Frequency:</label>
                            <input type="text" id="therapyFrequency" placeholder="e.g., weekly, daily">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addTherapyBtn">Add Therapy Type</button>
                </div>
                <div class="list-preview" id="therapyListOutput"></div>
                <hr>
                <label for="structureMisc">Additional Structure Notes (will be included as-is):</label>
                <textarea id="structureMisc" name="structureMisc" rows="4" placeholder="Any additional program structure information that doesn't fit the fields above..."></textarea>
            </fieldset>

            <fieldset>
                <legend>Rules and Punishments</legend>
                <h4>Rules</h4>
                <div class="form-adder">
                    <label for="ruleName">Rule:</label>
                    <input type="text" id="ruleName" placeholder="e.g., No talking without permission">
                    <button type="button" class="add-btn" id="addRuleBtn">Add Rule</button>
                </div>
                <div class="list-preview" id="ruleListOutput"></div>
                <hr>
                <h4>Punishments</h4>
                <div class="form-adder">
                    <label for="punishmentName">Punishment Name:</label>
                    <input type="text" id="punishmentName" placeholder="e.g., Back to Basics, Stone Circle">
                    <label for="punishmentDescription">Complete Description:</label>
                    <textarea id="punishmentDescription" rows="4" placeholder="e.g., (also called 'Frozen') which is used as punishment for severe rule infractions including bullying, incidents of hazing, physical assault, property destruction, possession of contraband, running away, stealing, community disruption, or unacceptable sexual behavior. During this punishment, the teens must complete a repair plan with his/her advocate and clinician..."></textarea>
                    <button type="button" class="add-btn" id="addPunishmentBtn">Add Punishment</button>
                </div>
                <div class="list-preview" id="punishmentListOutput"></div>
                <hr>
                <h4>Full Rules & Punishments Section (Advanced)</h4>
                <p style="font-size: 0.9em; color: #666;">Edit the complete rules and punishments section markdown here. This will override the individual fields above when generating output.</p>
                <textarea id="punishmentsMisc" name="punishmentsMisc" rows="8" placeholder="Full rules and punishments content in markdown format..."></textarea>
            </fieldset>

            <fieldset>
                <legend>Abuse/Neglect Allegations and Lawsuits</legend>
                <label>Allegations Reported by Survivors (select all that apply):</label>
                <div class="allegations-checklist">
                    <div class="checklist-column">
                        <h4>Abuse Types</h4>
                        <label><input type="checkbox" name="allegations" value="physical abuse"> Physical abuse</label>
                        <label><input type="checkbox" name="allegations" value="emotional abuse"> Emotional abuse</label>
                        <label><input type="checkbox" name="allegations" value="psychological abuse"> Psychological abuse</label>
                        <label><input type="checkbox" name="allegations" value="sexual abuse"> Sexual abuse</label>
                        <label><input type="checkbox" name="allegations" value="verbal abuse"> Verbal abuse</label>
                    </div>
                    <div class="checklist-column">
                        <h4>Neglect & Deprivation</h4>
                        <label><input type="checkbox" name="allegations" value="medical neglect"> Medical neglect</label>
                        <label><input type="checkbox" name="allegations" value="educational neglect"> Educational neglect</label>
                        <label><input type="checkbox" name="allegations" value="food deprivation"> Food deprivation</label>
                        <label><input type="checkbox" name="allegations" value="sleep deprivation"> Sleep deprivation</label>
                        <label><input type="checkbox" name="allegations" value="withholding medical care"> Withholding medical care</label>
                    </div>
                    <div class="checklist-column">
                        <h4>Confinement & Restraints</h4>
                        <label><input type="checkbox" name="allegations" value="restraint abuse"> Restraint abuse</label>
                        <label><input type="checkbox" name="allegations" value="excessive restraints"> Excessive restraints</label>
                        <label><input type="checkbox" name="allegations" value="solitary confinement"> Solitary confinement</label>
                        <label><input type="checkbox" name="allegations" value="isolation"> Isolation</label>
                    </div>
                    <div class="checklist-column">
                        <h4>Mistreatment & Practices</h4>
                        <label><input type="checkbox" name="allegations" value="humiliation"> Humiliation</label>
                        <label><input type="checkbox" name="allegations" value="intimidation"> Intimidation</label>
                        <label><input type="checkbox" name="allegations" value="overmedication"> Overmedication</label>
                        <label><input type="checkbox" name="allegations" value="inadequate supervision"> Inadequate supervision</label>
                        <label><input type="checkbox" name="allegations" value="untrained staff"> Untrained/unqualified staff</label>
                        <label><input type="checkbox" name="allegations" value="fraudulent marketing"> Fraudulent marketing</label>
                        <label><input type="checkbox" name="allegations" value="unsanitary conditions"> Unsanitary conditions</label>
                    </div>
                </div>
                <label for="customAllegations">Additional Allegations (comma-separated):</label>
                <input type="text" id="customAllegations" name="customAllegations" placeholder="Add any allegations not listed above">
                <hr>
                <h4>Lawsuits</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitYear">Year Filed:</label>
                            <input type="text" id="lawsuitYear" placeholder="e.g., 2018">
                        </div>
                        <div class="field-group">
                        <label for="lawsuitPlaintiff">Plaintiff(s):</label>
                        <input type="text" id="lawsuitPlaintiff" data-autocomplete-category="human" placeholder="e.g., John Doe">
                        </div>
                        <div class="field-group">
                        <label for="lawsuitDefendant">Defendant(s):</label>
                        <input type="text" id="lawsuitDefendant" data-autocomplete-category="human" placeholder="e.g., program name, owner">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitClaims">Claims (comma-separated):</label>
                            <input type="text" id="lawsuitClaims" placeholder="e.g., negligence, assault, false imprisonment">
                        </div>
                        <div class="field-group">
                            <label for="lawsuitOutcome">Outcome:</label>
                            <select id="lawsuitOutcome">
                                <option value="">Unknown/Pending</option>
                                <option value="settled">Settled</option>
                                <option value="dismissed">Dismissed</option>
                                <option value="plaintiff">Plaintiff Won</option>
                                <option value="defendant">Defendant Won</option>
                                <option value="ongoing">Ongoing</option>
                            </select>
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitAmount">Settlement/Award Amount:</label>
                            <input type="text" id="lawsuitAmount" placeholder="e.g., $500,000, undisclosed">
                        </div>
                        <div class="field-group">
                        <label for="lawsuitCourt">Court/Jurisdiction:</label>
                        <input type="text" id="lawsuitCourt" data-autocomplete-category="location" placeholder="e.g., Utah District Court">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addLawsuitBtn">Add Lawsuit</button>
                </div>
                <div class="list-preview" id="lawsuitListOutput"></div>
                <hr>
                <label for="lawsuitsMisc">Additional Abuse/Lawsuit Notes (will be included as-is):</label>
                <textarea id="lawsuitsMisc" name="lawsuitsMisc" rows="4" placeholder="Any additional abuse allegations or lawsuit information that doesn't fit the fields above..."></textarea>
            </fieldset>

            <fieldset>
                <legend>In the Media &amp; News</legend>
                <label for="mediaInfo">General Media (one event per line):</label>
                <textarea id="mediaInfo" name="mediaInfo" rows="5" placeholder="e.g., 2006: Season 2 of Brat Camp..."></textarea>
                <hr>
                <div class="form-adder">
                    <h4>Add a News Article</h4>
                    <label for="articleTitle">Article Title:</label>
                    <input type="text" id="articleTitle">
                    <label for="articleUrl">Article URL:</label>
                    <input type="text" id="articleUrl">
                    <label for="articleSource">Source (optional):</label>
                    <input type="text" id="articleSource" placeholder="e.g., Deseret News">
                    <label for="articleDate">Date (optional):</label>
                    <input type="text" id="articleDate" placeholder="e.g., 8/27/1994">
                    <button type="button" class="add-btn" id="addArticleBtn">Add Article</button>
                </div>
                <div class="list-preview" id="articleListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Survivor Testimonies</legend>
                <div class="form-adder">
                    <label for="testimonyDate">Date (optional):</label>
                    <input type="text" id="testimonyDate" placeholder="e.g., May 2022">
                    <label for="testimonyType">Type:</label>
                    <select id="testimonyType">
                        <option value="SURVIVOR">SURVIVOR</option>
                        <option value="PARENT">PARENT</option>
                        <option value="STAFF">STAFF</option>
                    </select>
                    <label for="testimonyQuote">Quote or Description:</label>
                    <textarea id="testimonyQuote" rows="3" placeholder="e.g., 'This place thrives...' or 'Reddit Post by u/...'"></textarea>
                    <label for="testimonySource">Source Name:</label>
                    <input type="text" id="testimonySource" data-autocomplete-category="human" placeholder="e.g., Brooke (Google Reviews) or Reddit">
                    <label for="testimonyUrl">Source URL:</label>
                    <input type="text" id="testimonyUrl">
                    <button type="button" class="add-btn" id="addTestimonyBtn">Add Testimony</button>
                </div>
                <div class="list-preview" id="testimonyListOutput"></div>
                <hr>
                <label for="testimoniesMisc">Additional Testimony Notes (will be included as-is):</label>
                <textarea id="testimoniesMisc" name="testimoniesMisc" rows="4" placeholder="Any additional survivor testimony information that doesn't fit the fields above..."></textarea>
            </fieldset>

            <fieldset>
                <legend>Related Programs</legend>
                <p style="color: #004435; font-size: 0.9rem;">Add related or affiliated programs here to generate a table.</p>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="relProgName">Program Name:</label>
                            <input type="text" id="relProgName" data-autocomplete-category="facility">
                        </div>
                        <div class="field-group">
                            <label for="relProgLink">Link:</label>
                            <input type="text" id="relProgLink">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="relProgYears">Years Active:</label>
                            <input type="text" id="relProgYears">
                        </div>
                        <div class="field-group">
                            <label for="relProgLocation">Location:</label>
                            <input type="text" id="relProgLocation" data-autocomplete-category="location">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="relProgHeal">HEAL Link:</label>
                            <input type="text" id="relProgHeal">
                        </div>
                        <div class="field-group">
                            <label for="relProgReopened">Reopened/Status:</label>
                            <input type="text" id="relProgReopened">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addRelatedProgBtn">Add Program</button>
                </div>
                <div class="list-preview" id="relatedProgramsListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Related Media (Links)</legend>
                <div class="form-adder">
                    <label for="mediaTitle">Link Title:</label>
                    <input type="text" id="mediaTitle" placeholder="e.g., Turn-About Ranch Website Homepage">
                    <label for="mediaUrl">Link URL:</label>
                    <input type="text" id="mediaUrl">
                    <button type="button" class="add-btn" id="addMediaBtn">Add Media Link</button>
                </div>
                <div class="list-preview" id="mediaListOutput"></div>
                <hr>
                <label for="relatedMediaMisc">Additional Related Media Notes (will be included as-is):</label>
                <textarea id="relatedMediaMisc" name="relatedMediaMisc" rows="4" placeholder="Any additional related media information that doesn't fit the fields above..."></textarea>
            </fieldset>

            <!-- Auto-linking Options -->
            <div class="auto-linking-options">
                <label class="checkbox-inline">
                    <input type="checkbox" id="autoLinkPrograms" checked>
                    <span>🔗 Automatically link TTI program mentions</span>
                </label>
                <p class="auto-linking-help">When enabled, mentions of other TTI programs will automatically be linked to their Reddit wiki pages.</p>
            </div>

            <button type="button" id="generateBtn">Generate Wiki Code</button>
        </form>

        <h2>Generated Wiki Code</h2>
        <p>Copy the text below and paste it directly into the Reddit wiki editor.</p>
        <textarea id="outputCode" placeholder="Generated markdown will appear here. You can edit it before copying or submitting."></textarea>
        <div class="output-actions">
            <button type="button" id="convertPastBtn">Convert to Past Tense</button>
            <button type="button" id="copyBtn">Copy to Clipboard</button>
            <button type="button" id="submitToDbBtn" class="submit-db-btn">💾 Submit to Database</button>
        </div>
        
        <!-- Submission Modal -->
        <div id="submitModal" class="submit-modal" style="display: none;">
            <div class="submit-modal-content">
                <h3>Submit Wiki Entry to Database</h3>
                <div class="submit-form-group">
                    <label for="submitterEmail">Your Email (optional):</label>
                    <input type="email" id="submitterEmail" placeholder="your@email.com">
                </div>
                <div class="submit-form-group">
                    <label for="submissionNotes">Notes (optional):</label>
                    <textarea id="submissionNotes" rows="3" placeholder="Any additional context or notes..."></textarea>
                </div>
                <div class="submit-modal-actions">
                    <button type="button" id="cancelSubmitBtn" class="cancel-btn">Cancel</button>
                    <button type="button" id="confirmSubmitBtn" class="submit-btn">Submit</button>
                </div>
                <div id="submitStatus" class="submit-status"></div>
            </div>
        </div>
    </div>
</div>

<?php
get_footer();
?>