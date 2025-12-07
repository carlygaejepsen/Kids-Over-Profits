<?php
/**
 * Admin Data Form Template Content
 * 
 * This file contains the form markup. It is loaded by page-admin-data.php.
 */

get_header();
?>

    <div class="container">
        <div class="admin-header">
            <h1>🔐 Admin TTI Data Management</h1>
            <p>You are in administrator mode. Changes saved here will directly update the master database.</p>
        </div>
        
        <div class="admin-warning">
            ❗ <strong>Administrator Mode:</strong> Data saved here will directly update the master database. Proceed with caution.
        </div>

        
        <!-- Category Navigation -->
        <div class="category-navigation" id="category-navigation">
            <div class="category-tabs">
                <button type="button" class="category-tab active" data-category="companies">🏢 Companies / Operators</button>
                <button type="button" class="category-tab" data-category="locations">🌍 Locations / States</button>
                <button type="button" class="category-tab" data-category="referrers">👥 Referrers</button>
            </div>

            <!-- Category contents wrapper -->
            <div class="category-contents-wrapper">
                <!-- Companies Content -->
                <div id="companies-content" class="category-content" data-section-views="companies">
                    <div class="content-header">
                        <h3>🏢 Companies / Operators</h3>
                    </div>
                    <div class="project-management" id="project-panel-inner">
                        <h2 style="margin: 20px 0; color: #1f2937; font-size: 18px;">Projects &amp; Data Import</h2>
                        <div id="project-status" style="margin-top: 10px; font-size: 14px; color: #6b7280;"></div>
                    </div>
                    <div class="form-group">
                        <label>Saved Projects</label>
                        <div id="company-saved-projects-list" style="max-height: 150px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; background: #fafafa;">
                            <div style="color: #6b7280; font-style: italic;">No saved company projects</div>
                        </div>
                    </div>
                    <div id="upload-status" style="display: none;"></div>
                </div>

                <!-- States Content -->
                <div id="states-content" class="category-content view-hidden" data-section-views="locations">
                    <div class="content-header">
                        <h3>🌍 Locations / States</h3>
                    </div>
                    <div class="project-management location-project-management">
                        <h2 style="margin: 20px 0; color: #1f2937; font-size: 18px;">Location Projects</h2>
                        <div class="form-group">
                            <label>Saved Location Projects</label>
                            <div id="location-saved-projects-list" style="max-height: 150px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; background: #fafafa;">
                                <div style="color: #6b7280; font-style: italic;">No saved location projects</div>
                            </div>
                        </div>
                        <div class="project-actions">
                            <button type="button" id="new-project-btn-location" class="kop-btn project-action-btn">New Location Project</button>
                            <button type="button" id="export-all-btn-location" class="kop-btn project-action-btn">Export Locations</button>
                            <button type="button" id="generate-report-btn-location" class="kop-btn project-action-btn">Generate Location Report</button>
                        </div>

                    </div>

                    <!-- Private Ownership Choice (popup-controlled) -->
                    <div id="private-ownership-toggle-section" class="location-card">
                        <div style="display: flex; align-items: center; gap: 15px; font-weight: 600; color: #1f2937; flex-wrap: wrap;">
                            <span>Privately Owned Facility (not part of a chain):</span>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span id="private-ownership-status" style="font-weight: 700; color: #0f172a;">Not set</span>
                                <button type="button" id="private-ownership-edit-btn" class="kop-btn" style="padding: 6px 10px; font-size: 13px;">Edit ownership</button>
                                <input type="checkbox" id="private-ownership-toggle" style="display: none;">
                            </div>
                        </div>
                        <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                            Click "Edit ownership" to answer the popup. Your choice still hides/shows operator vs owner fields like the old toggle.
                        </p>
                    </div>
                    <div id="ownership-modal" class="kop-modal" aria-hidden="true" role="dialog" aria-labelledby="ownership-modal-title" aria-modal="true" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center; padding:20px;">
                        <div style="background:#fff; border-radius:10px; max-width:420px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.2); padding:22px; position:relative;">
                            <h3 id="ownership-modal-title" style="margin-top:0; margin-bottom:12px; font-size:18px; color:#0f172a;">Is this a privately owned facility?</h3>
                            <p style="margin:0 0 18px; color:#334155; line-height:1.5;">Choose "Yes" for independent facilities. Choose "No" for chain/corporate facilities.</p>
                            <div style="display:flex; gap:12px; justify-content:flex-end;">
                                <button type="button" data-action="ownership-no" class="kop-btn" style="background:#e2e8f0; color:#0f172a; padding:8px 14px; border-radius:6px; border:1px solid #cbd5e1;">No</button>
                                <button type="button" data-action="ownership-yes" class="kop-btn" style="background:#10b981; color:#fff; padding:8px 14px; border-radius:6px; border:1px solid #0f9f7f;">Yes</button>
                            </div>
                            <button type="button" data-action="ownership-close" aria-label="Close" style="position:absolute; top:10px; right:10px; background:transparent; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">×</button>
                        </div>
                    </div>
                </div>

                <!-- Referrers Content -->
                <div id="referrers-content" class="category-content view-hidden" data-section-views="referrers">
                    <div class="content-header">
                        <h3>👥 Referrers (Education Consultants / School Districts)</h3>
                    </div>

                    <div class="project-management" id="referrer-project-panel-inner">
                        <h2>Referrer Projects &amp; Data Import</h2>
                        <div id="referrer-project-status"></div>
                        <div class="form-group">
                            <label>Saved Referrer Projects</label>
                            <div id="referrer-saved-projects-list" class="saved-projects-list">
                                <div style="color: #6b7280; font-style: italic;">No saved referrer projects</div>
                            </div>
                        </div>
                        <div class="project-actions">
                            <button type="button" id="new-project-btn-referrer" class="kop-btn project-action-btn">New Referrer Project</button>
                            <button type="button" id="export-all-btn-referrer" class="kop-btn project-action-btn">Export Referrers</button>
                            <button type="button" id="generate-report-btn-referrer" class="kop-btn project-action-btn">Generate Referrer Report</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Referrer-Specific Sections (shown when Referrers tab is active) -->
        <div id="referrer-main-wrapper" class="view-hidden" data-section-views="referrers">

            <!-- Agency Choice (popup-controlled) -->
            <div id="referrer-agency-toggle-section" class="referrer-card">
                <div style="display: flex; align-items: center; gap: 15px; font-weight: 600; color: #1f2937; flex-wrap: wrap;">
                    <span>Independent Consultant (not part of an agency):</span>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span id="referrer-independent-status" style="font-weight: 700; color: #0f172a;">Not set</span>
                        <button type="button" id="referrer-independent-edit-btn" class="kop-btn" style="padding: 6px 10px; font-size: 13px;">Edit consultant type</button>
                        <input type="checkbox" id="referrer-independent-toggle" style="display: none;">
                    </div>
                </div>
                <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                    Click "Edit consultant type" to choose via popup. Your choice still hides/shows the agency section like the old toggle.
                </p>
            </div>
            <div id="consultant-modal" class="kop-modal" aria-hidden="true" role="dialog" aria-labelledby="consultant-modal-title" aria-modal="true" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center; padding:20px;">
                <div style="background:#fff; border-radius:10px; max-width:420px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.2); padding:22px; position:relative;">
                    <h3 id="consultant-modal-title" style="margin-top:0; margin-bottom:12px; font-size:18px; color:#0f172a;">Is this an independent consultant?</h3>
                    <p style="margin:0 0 18px; color:#334155; line-height:1.5;">Choose "Yes" for independent consultants. Choose "No" for agency/group consultants.</p>
                    <div style="display:flex; gap:12px; justify-content:flex-end;">
                        <button type="button" data-action="consultant-no" class="kop-btn" style="background:#e2e8f0; color:#0f172a; padding:8px 14px; border-radius:6px; border:1px solid #cbd5e1;">No</button>
                        <button type="button" data-action="consultant-yes" class="kop-btn" style="background:#10b981; color:#fff; padding:8px 14px; border-radius:6px; border:1px solid #0f9f7f;">Yes</button>
                    </div>
                    <button type="button" data-action="consultant-close" aria-label="Close" style="position:absolute; top:10px; right:10px; background:transparent; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">×</button>
                </div>
            </div>

            <!-- Consultants Overview (Table of Contents) -->
            <div class="facility-toc" id="consultants-toc">
                <div class="toc-header">
                    <h2 class="toc-title">Consultants Overview</h2>
                    <button class="toc-toggle" id="consultants-toc-toggle-btn">🔎</button>
                </div>
                <div class="toc-content">
                    <div class="toc-stats" id="consultants-toc-stats">Total: 1 consultant</div>
                    <div class="facility-list" id="consultants-list">
                        <!-- Consultant items will be populated here -->
                    </div>
                </div>
            </div>

            <!-- Agency Information Section -->
            <div class="section expanded" id="referrer-agency-section" data-section-views="referrers">
                <div class="section-header">
                    <h2 class="section-title">Agency/Group Information</h2>
                    <span class="section-toggle">🔎</span>
                </div>
                <div class="section-content">
                    <div class="form-group">
                        <label>Agency/Group Name</label>
                        <input type="text" id="referrer-agency-name" class="input-form" placeholder="e.g., Educational Consultants Association">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" id="referrer-agency-city" class="input-form" placeholder="e.g., Los Angeles">
                        </div>
                        <div class="form-group">
                            <label>State</label>
                            <input type="text" id="referrer-agency-state" class="input-form" placeholder="e.g., CA">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Website</label>
                        <input type="url" id="referrer-agency-website" class="input-form" placeholder="https://">
                    </div>

                    <div class="form-group">
                        <label>Key Personnel</label>
                        <div class="array-container" data-path="referrerAgency.keyPersonnel"></div>
                    </div>

                    <div class="form-group">
                        <label>Agency Notes</label>
                        <textarea id="referrer-agency-notes" class="input-form" rows="4" placeholder="Add notes about this agency..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Individual Consultants Management Section -->
            <div class="section expanded" id="referrer-consultants-section" data-section-views="referrers">
                <div class="section-header">
                    <h2 class="section-title">Individual Consultants</h2>
                    <span class="section-toggle">🔎</span>
                </div>
                <div class="section-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button class="btn" id="prev-consultant-btn" title="Previous consultant">◀ Previous</button>
                            <select id="consultant-dropdown" style="min-width: 250px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <option>1. New Consultant</option>
                            </select>
                            <button class="btn" id="next-consultant-btn" title="Next consultant">Next ▶</button>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-primary" id="add-consultant-btn">➕ Add Consultant</button>
                            <button class="btn btn-danger d-none" id="remove-consultant-btn">🗑️ Remove Consultant</button>
                        </div>
                    </div>

                    <!-- Individual Consultant Form -->
                    <div id="consultant-form-wrapper">
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" id="consultant-firstname" class="consultant-field" data-field="firstName" placeholder="e.g., John">
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" id="consultant-lastname" class="consultant-field" data-field="lastName" placeholder="e.g., Smith">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Credentials/Title</label>
                            <input type="text" id="consultant-credentials" class="consultant-field" data-field="credentials" placeholder="e.g., Licensed Educational Consultant, IECA">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" id="consultant-city" class="consultant-field" data-field="city" placeholder="e.g., Boston">
                            </div>
                            <div class="form-group">
                                <label>State</label>
                                <input type="text" id="consultant-state" class="consultant-field" data-field="state" placeholder="e.g., MA">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" id="consultant-email" class="consultant-field" data-field="email" placeholder="email@example.com">
                        </div>

                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" id="consultant-phone" class="consultant-field" data-field="phone" placeholder="(555) 123-4567">
                        </div>

                        <div class="form-group">
                            <label>Website/Profile</label>
                            <input type="url" id="consultant-website" class="consultant-field" data-field="website" placeholder="https://">
                        </div>

                        <div class="form-group">
                            <label>Professional Affiliations</label>
                            <div class="array-container" data-path="consultant.affiliations"></div>
                        </div>

                        <div class="form-group">
                            <label>Facilities Referred To</label>
                            <div class="array-container" data-path="consultant.knownReferrals"></div>
                        </div>

                        <div class="form-group">
                            <label>School Districts Worked With</label>
                            <div class="array-container" data-path="consultant.schoolDistricts"></div>
                        </div>

                        <div class="form-group">
                            <label>Notes</label>
                            <textarea id="consultant-notes" class="consultant-field" data-field="notes" rows="4" placeholder="Add notes about this consultant..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Referrer Submission Section -->
            <div class="section expanded" id="referrer-submission-section" data-section-views="referrers" style="border: 2px solid #1e40af; background: #f8fafc;">
                <div class="section-header" style="background: #1e40af; color: white; cursor: default; pointer-events: none;">
                    <h2 class="section-title" style="color: white; pointer-events: none;">💾 Save Referrer Project</h2>
                </div>
                <div class="section-content" style="display: block;">
                    <div class="form-group">
                        <label for="referrer-project-name">Project Name</label>
                        <input type="text" id="referrer-project-name" placeholder="Enter referrer project name..." style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <button type="button" class="save-master-btn" id="save-referrer-project-btn">
                            💾 Save Referrer Project
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Fixed Toolbar -->
        <div class="fixed-toolbar" id="fixed-toolbar" data-section-views="companies,locations,referrers">
            <div class="toolbar-header">
                <div class="toolbar-title">
                    <strong>📋 Admin Editor</strong>
                    <span id="toolbar-project-name" style="color: #6b7280; font-weight: normal; margin-left: 10px;"></span>
                </div>
                <button type="button" class="toolbar-toggle" id="toolbar-toggle-btn" title="Minimize toolbar">−</button>
            </div>
            <div class="toolbar-content" id="toolbar-content">
                <div class="toolbar-section">
                    <div class="toolbar-group">
                        <button type="button" class="btn-toolbar btn-success" id="new-project-btn-toolbar" title="Create a new project">📄 New Project</button>
                        <button type="button" class="btn-toolbar btn-primary" id="generate-report-btn-toolbar" title="Generate report for current project">📊 Generate Report</button>
                    </div>
                    <div class="toolbar-group">
                        <button type="button" class="btn-toolbar btn-primary" id="add-facility-btn-toolbar" title="Add a new facility">➕ Add Facility</button>
                        <button type="button" class="btn-toolbar btn-secondary" id="scroll-to-top-btn-toolbar" title="Scroll to top">⬆️ Top</button>
                    </div>
                    <div class="toolbar-group facility-nav-group">
                        <label class="toolbar-label" id="toolbar-current-item-label">Current Facility:</label>
                        <div class="facility-selector">
                            <button type="button" class="btn-toolbar btn-secondary" id="prev-facility-btn-toolbar" title="Previous facility">◀</button>
                            <select id="facility-dropdown" class="facility-dropdown"></select>
                            <button type="button" class="btn-toolbar btn-secondary" id="next-facility-btn-toolbar" title="Next facility">▶</button>
                        </div>
                    </div>
                    <div class="toolbar-group">
                        <button type="button" class="btn-toolbar btn-success" id="clone-facility-btn-toolbar" title="Clone current facility">📋 Clone</button>
                        <button type="button" class="btn-toolbar btn-danger" id="remove-facility-btn-toolbar" title="Delete current facility">🗑️ Delete</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="facility-main-wrapper" data-section-views="companies,locations">
        <!-- Facility Table of Contents (Companies only - Locations has its own in #states-content) -->
        <div class="facility-toc" id="facility-toc" data-section-views="companies">
            <div class="toc-header">
                <h2 class="toc-title">Facilities Overview</h2>
                <button class="toc-toggle" id="toc-toggle-btn">🔎</button>
            </div>
            <div class="toc-content">
                <div class="toc-stats" id="toc-stats">Total: 1 facility</div>
                <div class="facility-list" id="facility-list">
                    <!-- Facility items will be populated here -->
                </div>
                <div class="mt-15 d-flex gap-10 flex-center">
                    <button class="btn" id="add-facility-main-btn">Add New Facility</button>
                    <button class="btn" id="sort-facilities-btn">Sort Alphabetically</button>
                </div>
            </div>
        </div>
        
        <!-- Operator Information Section -->
        <div class="section expanded" id="operator-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Operator Information</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-row">
                    <div class="form-group">
                        <label for="operator-name">Operator Name</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="operator-name" class="input-form" data-autocomplete-category="operator" placeholder="Type operator name...">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="operator-current-name">Current Name</label>
                        <input type="text" id="operator-current-name" class="input-form" data-autocomplete-category="operator" placeholder="Type current operator name...">
                    </div>
                </div>

                <div class="form-group">
                    <label for="operator-other-names">Other Names</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="operator-other-names" class="input-form" data-autocomplete-category="operator" placeholder="Type other operator names (comma-separated)...">
                    </div>
                </div>

                <h3 style="margin: 20px 0 10px 0; color: #1f2937; font-size: 15px;">Primary Location</h3>
                <div class="form-row-3">
                    <div class="form-group">
                        <label for="operator-location-city">City</label>
                        <input type="text" id="operator-location-city" placeholder="e.g., Waynesboro">
                    </div>
                    <div class="form-group">
                        <label for="operator-location-state">State / Province</label>
                        <input type="text" id="operator-location-state" placeholder="e.g., TN">
                    </div>
                    <div class="form-group">
                        <label for="operator-location-country">Country</label>
                        <input type="text" id="operator-location-country" placeholder="e.g., USA (leave blank for US)">
                    </div>
                </div>

                <h3 style="margin: 20px 0 10px 0; color: #1f2937; font-size: 15px;">Headquarters</h3>
                <div class="form-row-3">
                    <div class="form-group">
                        <label for="operator-headquarters-city">City</label>
                        <input type="text" id="operator-headquarters-city" placeholder="e.g., Nashville">
                    </div>
                    <div class="form-group">
                        <label for="operator-headquarters-state">State / Province</label>
                        <input type="text" id="operator-headquarters-state" placeholder="e.g., TN">
                    </div>
                    <div class="form-group">
                        <label for="operator-headquarters-country">Country</label>
                        <input type="text" id="operator-headquarters-country" placeholder="e.g., USA (leave blank for US)">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="operator-founded">Founded</label>
                        <input type="text" id="operator-founded" placeholder="e.g., 1985">
                    </div>
                    <div class="form-group">
                        <label for="operator-period">Operating Period</label>
                        <input type="text" id="operator-period" data-autocomplete-category="operatingperiod" placeholder="e.g., 1985-Present">
                    </div>
                </div>

                <div class="form-group">
                    <label for="operator-status">Status</label>
                    <input type="text" id="operator-status" class="input-wide" data-autocomplete-category="status" placeholder="Active, Acquired, Merged, Defunct, etc.">
                </div>
                
                <div class="form-group">
                    <label>Parent Companies</label>
                    <div class="array-container" data-path="operator.parentCompanies"></div>
                </div>
                
                <div class="form-group">
                    <label>Websites</label>
                    <div class="array-container" data-path="operator.websites"></div>
                </div>
                
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Key Staff</h3>
                
                <div class="form-group">
                    <label for="operator-ceo">CEO/President</label>
                    <input type="text" id="operator-ceo" data-autocomplete-category="human" placeholder="e.g., John Smith">
                </div>
                
                <div class="form-group">
                    <label>Founders</label>
                    <div class="array-container" data-path="operator.keyStaff.founders"></div>
                </div>
                
                <div class="form-group">
                    <label>Key Executives</label>
                    <div class="array-container" data-path="operator.keyStaff.keyExecutives"></div>
                </div>

                <div class="form-group">
                    <label>Investors</label>
                    <div class="array-container" data-path="operator.investors"></div>
                </div>

                <div class="form-group">
                    <label>Operator Notes</label>
                    <textarea id="operator-notes" rows="4" placeholder="Add notes about the operator..." style="width: 100%;"></textarea>
                </div>
            </div>
        </div>

        <!-- Identification Section -->
        <div class="section" id="identification-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Identification & Names</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-row">
                    <div class="form-group">
                        <label>Name</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="facility-name" data-autocomplete-category="facility" placeholder="Type facility name...">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Current Name</label>
                        <input type="text" class="facility-field" data-field="identification.currentName" data-autocomplete-category="facility">
                    </div>
                </div>
                <!-- Current Operator (shown for corporate ownership) -->
                <div class="form-group" id="current-operator-group">
                    <label id="current-operator-label">Current Operator</label>
                    <input type="text" class="facility-field" data-field="identification.currentOperator" data-autocomplete-category="operator">
                </div>
                <!-- Current Owner(s) (shown for private ownership) -->
                <div class="form-group" id="current-owner-group" style="display: none;">
                    <label>Current Owner(s)</label>
                    <div style="color: #6b7280; font-size: 13px; margin-bottom: 6px;">Add one or more current owners.</div>
                    <div class="array-container" data-path="identification.currentOwners" data-autocomplete-category="human"></div>
                </div>
                <!-- Past Owners (shown for private ownership) -->
                <div class="form-group" id="past-owners-group" style="display: none;">
                    <label>Past Owners</label>
                    <div class="array-container" data-path="identification.pastOwners" data-autocomplete-category="human"></div>
                </div>
                <div class="form-group">
                    <label>Other Names</label>
                    <div class="array-container" data-path="identification.otherNames"></div>
                </div>
                <div class="form-group">
                    <label>Known Referrers (Education Consultants / School Districts)</label>
                    <div class="array-container" data-path="identification.knownReferrers"></div>
                </div>
            </div>
        </div>

        <!-- Location Section -->
        <div class="section" id="location-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Location & Address</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <!-- International Program Toggle -->
                <div id="international-toggle-section" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px; font-weight: 600; color: #1f2937;">
                        <span>International Program:</span>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span>No</span>
                            <div style="position: relative; display: inline-block;">
                                <input type="checkbox" id="international-program-toggle" class="facility-field" data-field="isInternational" style="display: none;">
                                <span id="international-slider-track" style="display: block; width: 48px; height: 24px; background-color: #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.3s ease; position: relative;">
                                    <span id="international-slider-knob" style="display: block; width: 20px; height: 20px; background-color: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
                                </span>
                            </div>
                            <span>Yes</span>
                        </div>
                    </div>
                    <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                        Toggle to "Yes" for programs outside the United States to show Country field instead of State.
                    </p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" class="facility-field" data-field="locationDetails.city" placeholder="e.g., Salt Lake City">
                    </div>
                    <div class="form-group" id="state-field-group">
                        <label>State</label>
                        <input type="text" class="facility-field" data-field="locationDetails.state" data-autocomplete-category="location" placeholder="e.g., UT">
                    </div>
                    <div class="form-group" id="country-field-group" style="display: none;">
                        <label>Country</label>
                        <input type="text" class="facility-field" data-field="locationDetails.country" data-autocomplete-category="country" placeholder="e.g., Mexico">
                    </div>
                </div>
                <div class="form-group">
                    <label>Full Location (Legacy)</label>
                    <input type="text" class="facility-field" data-field="location" placeholder="City, State or City, Country">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea class="facility-field" data-field="address" rows="3" placeholder="Street address, suite/unit number..."></textarea>
                </div>
                <div class="form-group">
                    <label>Additional Locations</label>
                    <div style="color: #6b7280; font-size: 13px; margin-bottom: 6px;">Add other site addresses (city + address only).</div>
                    <div class="array-container" data-path="locationDetails.additionalLocations"></div>
                </div>
            </div>
        </div>
        
        <!-- Operations Section -->
        <div class="section" id="operations-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Facility Operations</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-group">
                    <label>Other Operators</label>
                    <div class="array-container" data-path="otherOperators"></div>
                </div>
                
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Facility Operating Dates</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Facility Opened (Year)</label>
                        <input type="number" class="facility-field" data-field="operatingPeriod.startYear" placeholder="e.g., 1985">
                    </div>
                    <div class="form-group">
                        <label>Facility Closed (Year)</label>
                        <input type="number" class="facility-field" data-field="operatingPeriod.endYear" placeholder="Leave blank if still open">
                    </div>
                </div>
                <div class="form-group">
                    <label>Current Status</label>
                    <input type="text" class="facility-field input-wide" data-field="operatingPeriod.status" data-autocomplete-category="status" placeholder="Open, Closed, Transferred, etc.">
                </div>
                <div class="form-group">
                    <label>Years of Operation</label>
                    <input type="text" class="facility-field" data-field="operatingPeriod.yearsOfOperation" placeholder="e.g., 1985-2010, 2015-Present">
                </div>
                <div class="form-group">
                    <label>Operational Notes</label>
                    <textarea class="facility-field" data-field="operatingPeriod.notes" rows="4" placeholder="Add operational notes..." style="width: 100%;"></textarea>
                </div>
            </div>
        </div>
        
        <!-- Staff Section -->
        <div class="section" id="staff-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Staff & Links</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-group">
                    <label>Administrator</label>
                    <div class="array-container" data-path="staff.administrator"></div>
                </div>
                <div class="form-group">
                    <label>Notable Staff</label>
                    <div class="array-container" data-path="staff.notableStaff"></div>
                </div>
                <div class="form-group">
                    <label>Profile Links</label>
                    <div class="array-container" data-path="profileLinks"></div>
                </div>
            </div>
        </div>
        
        <!-- Facility Details Section -->
        <div class="section" id="facility-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Facility Details</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-group">
                    <label>Program Type</label>
                    <div class="autocomplete-wrapper" style="max-width: 400px;">
                        <input type="text" id="facility-type" data-autocomplete-category="type" placeholder="Type facility type...">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Capacity</label>
                        <input type="number" class="facility-field" data-field="facilityDetails.capacity">
                    </div>
                    <div class="form-group">
                        <label>Current Census</label>
                        <input type="number" class="facility-field" data-field="facilityDetails.currentCensus">
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Min Age</label>
                        <input type="number" class="facility-field" data-field="facilityDetails.ageRange.min">
                    </div>
                    <div class="form-group">
                        <label>Max Age</label>
                        <input type="number" class="facility-field" data-field="facilityDetails.ageRange.max">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <input type="text" class="facility-field" data-field="facilityDetails.gender" data-autocomplete-category="gender" placeholder="Male, Female, Co-ed...">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Accreditations & Memberships Section -->
        <div class="section" id="accreditations-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Accreditations & Memberships</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-group">
                    <label>Current Accreditations</label>
                    <div class="array-container" data-path="accreditations.current"></div>
                </div>
                <div class="form-group">
                    <label>Past Accreditations</label>
                    <div class="array-container" data-path="accreditations.past"></div>
                </div>
                <div class="form-group">
                    <label>Professional Memberships</label>
                    <div class="array-container" data-path="memberships"></div>
                </div>
                <div class="form-group">
                    <label>Certifications</label>
                    <div class="array-container" data-path="certifications"></div>
                </div>
                <div class="form-group">
                    <label>Licensing Information</label>
                    <div class="array-container" data-path="licensing"></div>
                </div>
            </div>
        </div>
        
        <!-- Resources & Documentation Section -->
        <div class="section" id="resources-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Available Resources & Documentation</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Standard Resource Types</h3>
                
                <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 13px;">News & Media</h4>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasNews" data-note-scope="facility" data-note-key="resources.hasNews" id="has-news">
                    <label for="has-news">News Articles</label>
                </div>
                <div id="has-news-details" style="display: none; margin-left: 30px; margin-bottom: 15px;">
                    <textarea class="facility-field" data-field="resources.newsDetails" placeholder="Enter details about news articles..." rows="2"></textarea>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasPressReleases" data-note-scope="facility" data-note-key="resources.hasPressReleases" id="has-press">
                    <label for="has-press">Press Releases</label>
                </div>
                <div id="has-press-details" style="display: none; margin-left: 30px; margin-bottom: 15px;">
                    <textarea class="facility-field" data-field="resources.pressReleasesDetails" placeholder="Enter details about press releases..." rows="2"></textarea>
                </div>
                
                <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 13px;">Official Documentation</h4>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasInspections" data-note-scope="facility" data-note-key="resources.hasInspections" id="has-inspections">
                    <label for="has-inspections">Inspection Reports</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasStateReports" data-note-scope="facility" data-note-key="resources.hasStateReports" id="has-state-reports">
                    <label for="has-state-reports">State Reports</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasRegulatoryFilings" data-note-scope="facility" data-note-key="resources.hasRegulatoryFilings" id="has-regulatory">
                    <label for="has-regulatory">Regulatory Filings</label>
                </div>
                
                <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 13px;">Legal & Compliance</h4>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasLawsuits" data-note-scope="facility" data-note-key="resources.hasLawsuits" id="has-lawsuits">
                    <label for="has-lawsuits">Lawsuits</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasPoliceReports" data-note-scope="facility" data-note-key="resources.hasPoliceReports" id="has-police-reports">
                    <label for="has-police-reports">Police Reports</label>
                </div>

                <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 13px;">Business & Property</h4>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasArticlesOfOrganization" data-note-scope="facility" data-note-key="resources.hasArticlesOfOrganization" id="has-articles">
                    <label for="has-articles">Articles of Organization</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasPropertyRecords" data-note-scope="facility" data-note-key="resources.hasPropertyRecords" id="has-property">
                    <label for="has-property">Property Records</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasPromotionalMaterials" data-note-scope="facility" data-note-key="resources.hasPromotionalMaterials" id="has-promotional">
                    <label for="has-promotional">Promotional Materials</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasEnrollmentDocuments" data-note-scope="facility" data-note-key="resources.hasEnrollmentDocuments" id="has-enrollment">
                    <label for="has-enrollment">Enrollment Documents</label>
                </div>

                <h4 style="margin: 15px 0 10px 0; color: #1f2937; font-size: 13px;">Other Standard Resources</h4>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasResearch" data-note-scope="facility" data-note-key="resources.hasResearch" id="has-research">
                    <label for="has-research">Academic Research</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasFinancial" data-note-scope="facility" data-note-key="resources.hasFinancial" id="has-financial">
                    <label for="has-financial">Financial Reports</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasStudent" data-note-scope="facility" data-note-key="resources.hasStudent" id="has-student">
                    <label for="has-student">Student or Resident Manual</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasStaff" data-note-scope="facility" data-note-key="resources.hasStaff" id="has-staff">
                    <label for="has-staff">Staff Manual</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasParent" data-note-scope="facility" data-note-key="resources.hasParent" id="has-parent">
                    <label for="has-parent">Parent Manual</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasWebsite" data-note-scope="facility" data-note-key="resources.hasWebsite" id="has-website">
                    <label for="has-website">Archived Website</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasNATSAP" data-note-scope="facility" data-note-key="resources.hasNATSAP" id="has-NATSAP">
                    <label for="has-NATSAP">NATSAP Profile</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasSurvivorStories" data-note-scope="facility" data-note-key="resources.hasSurvivorStories" id="has-survivor-stories">
                    <label for="has-survivor-stories">Survivor Stories</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="resources.hasOther" data-note-scope="facility" data-note-key="resources.hasOther" id="has-other">
                    <label for="has-other">Other Documentation</label>
                </div>

                <h3 style="margin: 30px 0 15px 0; color: #1f2937; font-size: 15px;">Resource Notes</h3>
                <div class="form-group">
                    <textarea class="facility-field" data-field="resources.notes" rows="4" placeholder="Add resource notes..." style="width: 100%;"></textarea>
                </div>
            </div>
        </div>

        <!-- Treatment Types Section -->
        <div class="section" id="treatment-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Treatment Types</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Standard Treatment Types</h3>

                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasABA" data-note-scope="facility" data-note-key="treatmentTypes.hasABA" id="has-aba">
                    <label for="has-aba">Applied Behavior Analysis</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasEquineTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasEquineTherapy" id="has-equine-therapy">
                    <label for="has-equine-therapy">Equine Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasWorkTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasWorkTherapy" id="has-work-therapy">
                    <label for="has-work-therapy">Work Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasWildernessTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasWildernessTherapy" id="has-wilderness-therapy">
                    <label for="has-wilderness-therapy">Wilderness Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasRealityTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasRealityTherapy" id="has-reality-therapy">
                    <label for="has-reality-therapy">Reality Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasLGATSeminars" data-note-scope="facility" data-note-key="treatmentTypes.hasLGATSeminars" id="has-lgat">
                    <label for="has-lgat">Large Group Awareness Training Seminars</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasFeedbackHotseatGroups" data-note-scope="facility" data-note-key="treatmentTypes.hasFeedbackHotseatGroups" id="has-feedback-hotseat">
                    <label for="has-feedback-hotseat">Feedback/Hotseat Groups (aka The Game)</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasPrimalScreamTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasPrimalScreamTherapy" id="has-primal-scream">
                    <label for="has-primal-scream">Primal Scream Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasRepressedMemoryTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasRepressedMemoryTherapy" id="has-repressed-memory">
                    <label for="has-repressed-memory">Repressed Memory Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasBehaviorModification" data-note-scope="facility" data-note-key="treatmentTypes.hasBehaviorModification" id="has-behavior-mod">
                    <label for="has-behavior-mod">Behavior Modification</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasKetamineTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasKetamineTherapy" id="has-ketamine">
                    <label for="has-ketamine">Ketamine Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasExposureTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasExposureTherapy" id="has-exposure">
                    <label for="has-exposure">Exposure Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasUnlicensedProvider" data-note-scope="facility" data-note-key="treatmentTypes.hasUnlicensedProvider" id="has-unlicensed">
                    <label for="has-unlicensed">Therapy with an Unlicensed Provider</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasConversionTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasConversionTherapy" id="has-conversion">
                    <label for="has-conversion">Sexual Orientation Gender Identity Change Efforts/Conversion/Reparative Therapy (SOGICE)</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasAttachmentTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasAttachmentTherapy" id="has-attachment">
                    <label for="has-attachment">Attachment Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasRebirthingTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasRebirthingTherapy" id="has-rebirthing">
                    <label for="has-rebirthing">Rebirthing Therapy</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasTappingTherapy" data-note-scope="facility" data-note-key="treatmentTypes.hasTappingTherapy" id="has-tapping">
                    <label for="has-tapping">Tapping/Thought Field Therapy (TFT)</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasPsychoanalysis" data-note-scope="facility" data-note-key="treatmentTypes.hasPsychoanalysis" id="has-psychoanalysis">
                    <label for="has-psychoanalysis">Psychoanalysis</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasEMDR" data-note-scope="facility" data-note-key="treatmentTypes.hasEMDR" id="has-emdr-treatment">
                    <label for="has-emdr-treatment">Eye Movement Desensitization and Reprocessing (EMDR)</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="treatmentTypes.hasHypnosis" data-note-scope="facility" data-note-key="treatmentTypes.hasHypnosis" id="has-hypnosis">
                    <label for="has-hypnosis">Hypnosis</label>
                </div>

                <h3 style="margin: 30px 0 15px 0; color: #1f2937; font-size: 15px;">Custom Treatment Types</h3>
                <div class="form-group">
                    <label>Add Custom Treatment Type</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="custom-treatment-input" placeholder="Enter treatment type name..." style="flex: 1;">
                        <button class="btn" id="add-custom-treatment-btn">Add</button>
                    </div>
                </div>
                <div id="custom-treatment-list" style="margin-top: 15px;">
                    <!-- Custom treatment checkboxes will be rendered here -->
                </div>
            </div>
        </div>

        <!-- Philosophy Section -->
        <div class="section" id="philosophy-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Philosophy</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Standard Philosophies</h3>

                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasPositivePeerCulture" data-note-scope="facility" data-note-key="philosophy.hasPositivePeerCulture" id="has-ppc">
                    <label for="has-ppc">Positive Peer Culture</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.has12Steps" data-note-scope="facility" data-note-key="philosophy.has12Steps" id="has-12-steps">
                    <label for="has-12-steps">12 Steps</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasFundamentalistBaptist" data-note-scope="facility" data-note-key="philosophy.hasFundamentalistBaptist" id="has-baptist">
                    <label for="has-baptist">Fundamentalist Baptist</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasPentecostal" data-note-scope="facility" data-note-key="philosophy.hasPentecostal" id="has-pentecostal">
                    <label for="has-pentecostal">Pentecostal</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasScientology" data-note-scope="facility" data-note-key="philosophy.hasScientology" id="has-scientology">
                    <label for="has-scientology">Scientology</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasTherapeuticCommunity" data-note-scope="facility" data-note-key="philosophy.hasTherapeuticCommunity" id="has-tc">
                    <label for="has-tc">Therapeutic Community</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasWildernessRoad" data-note-scope="facility" data-note-key="philosophy.hasWildernessRoad" id="has-wilderness-road">
                    <label for="has-wilderness-road">Wilderness Road</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasPsychoanalytic" data-note-scope="facility" data-note-key="philosophy.hasPsychoanalytic" id="has-psychoanalytic">
                    <label for="has-psychoanalytic">Psychoanalytic</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasLawOfAttraction" data-note-scope="facility" data-note-key="philosophy.hasLawOfAttraction" id="has-loa">
                    <label for="has-loa">Law of Attraction</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="philosophy.hasHumanPotentialMovement" data-note-scope="facility" data-note-key="philosophy.hasHumanPotentialMovement" id="has-hpm">
                    <label for="has-hpm">Human Potential Movement</label>
                </div>

                <h3 style="margin: 30px 0 15px 0; color: #1f2937; font-size: 15px;">Custom Philosophies</h3>
                <div class="form-group">
                    <label>Add Custom Philosophy</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="custom-philosophy-input" placeholder="Enter philosophy name..." style="flex: 1;">
                        <button class="btn" id="add-custom-philosophy-btn">Add</button>
                    </div>
                </div>
                <div id="custom-philosophy-list" style="margin-top: 15px;">
                    <!-- Custom philosophy checkboxes will be rendered here -->
                </div>
            </div>
        </div>

        <!-- Critical Incidents Section -->
        <div class="section" id="incidents-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">Critical Incidents</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <h3 style="margin: 20px 0 15px 0; color: #1f2937; font-size: 15px;">Standard Incident Types</h3>

                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="criticalIncidents.hasDeaths" data-note-scope="facility" data-note-key="criticalIncidents.hasDeaths" id="has-deaths">
                    <label for="has-deaths">Deaths</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="criticalIncidents.hasStaffArrests" data-note-scope="facility" data-note-key="criticalIncidents.hasStaffArrests" id="has-staff-arrests">
                    <label for="has-staff-arrests">Staff Arrests</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="criticalIncidents.hasStudentHospitalizations" data-note-scope="facility" data-note-key="criticalIncidents.hasStudentHospitalizations" id="has-hospitalizations">
                    <label for="has-hospitalizations">Student Hospitalizations</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="facility-checkbox" data-field="criticalIncidents.hasRiots" data-note-scope="facility" data-note-key="criticalIncidents.hasRiots" id="has-riots">
                    <label for="has-riots">Riots</label>
                </div>

                <h3 style="margin: 30px 0 15px 0; color: #1f2937; font-size: 15px;">Custom Critical Incidents</h3>
                <div class="form-group">
                    <label>Add Custom Critical Incident</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="custom-incident-input" placeholder="Enter incident type name..." style="flex: 1;">
                        <button class="btn" id="add-custom-incident-btn">Add</button>
                    </div>
                </div>
                <div id="custom-incidents-list" style="margin-top: 15px;">
                    <!-- Custom incident checkboxes will be rendered here -->
                </div>
            </div>
        </div>
        
        <!-- General Notes Section -->
        <div class="section" id="notes-section" data-section-views="companies,locations">
            <div class="section-header">
                <h2 class="section-title">General Notes</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content">
                <div class="form-group">
                    <label>Facility Notes</label>
                    <textarea class="facility-field" data-field="notes" rows="4" placeholder="Add general facility notes..." style="width: 100%;"></textarea>
                </div>
            </div>
        </div>
        
        <!-- Submission Section -->
        <div class="section expanded" id="submission-section" data-section-views="companies,locations,referrers" style="border: 2px solid #1e40af; background: #f8fafc;">
            <div class="section-header" style="background: #1e40af; color: white; cursor: default; pointer-events: none;">
                <h2 class="section-title" style="color: white; pointer-events: none;">💾 Save to Master Database</h2>
            </div>
            <div class="section-content" style="display: block;">
                <div class="form-group">
                    <label for="project-name">Project Name</label>
                    <input type="text" id="project-name" placeholder="Enter project name..." style="width: 100%;">
                </div>
                <div class="form-group" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="save-master-btn" id="save-project-btn">
                        💾 Save to Master Database
                    </button>
                    <button type="button" class="btn" id="save-draft-locally-btn" style="background: #6b7280; color: white;" title="Save your work locally to continue later">
                        📋 Save Draft Locally
                    </button>
                </div>
                <p id="draft-status" style="margin-top: 10px; font-size: 13px; color: #6b7280; display: none;"></p>
            </div>
        </div>
        
        <!-- Advanced User Mode Section -->
        <div class="section" id="advanced-mode-section" style="border: 2px solid #6b7280; background: #f9fafb;">
            <div class="section-header" style="background: #6b7280; color: white; cursor: pointer;">
                <h2 class="section-title" style="color: white;">⚙️ Advanced User Mode</h2>
                <span class="section-toggle">🔎</span>
            </div>
            <div class="section-content" style="display: none;">
                 <div class="form-group">
                        <label for="file-upload">Import Data Files</label>
                        <input type="file" id="file-upload" accept=".json,.csv,.txt">
                        <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                            Upload JSON or CSV files. JSON files will load directly into the form.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="json-paste">Or Paste JSON Data</label>
                        <textarea id="json-paste" rows="4" placeholder="Paste any JSON here..."></textarea>
                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button class="btn" id="import-json-btn">Import JSON</button>
                            <button class="btn" id="clear-all-btn">Clear Form</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Export Projects</label>
                        <button class="btn" id="export-all-btn">Export All Projects</button>
                        <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                            Export all saved projects as a JSON file for backup or transfer.
                        </p>
                    </div>
                <div class="json-output">
                    <div class="output-header">
                        <h3>Generated JSON</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="copy-btn" id="copy-json-btn">Copy to Clipboard</button>
                            <button class="copy-btn" id="download-json-btn">Download JSON</button>
                        </div>
                    </div>
                    <pre id="json-display">{}</pre>
                </div>

            </div>
        </div>
    </div>
    
    <!-- Clone Facility Modal -->
    <div id="clone-facility-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Clone Facility</h3>
                <button class="modal-close" id="clone-modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: #6b7280;">Choose a destination for the cloned facility:</p>
                
                <div class="clone-option">
                    <label>
                        <input type="radio" name="clone-destination" value="current" checked>
                        Clone to the current project (<strong><span id="clone-current-project-name"></span></strong>)
                    </label>
                </div>
                
                <div class="clone-option">
                    <label>
                        <input type="radio" name="clone-destination" value="existing">
                        Clone to an existing project
                    </label>
                    <div class="project-select-container" id="existing-project-container" style="display: none;">
                        <select class="project-select" id="existing-project-select">
                            <option value="">Select a project...</option>
                        </select>
                    </div>
                </div>
                
                <div class="clone-option">
                    <label>
                        <input type="radio" name="clone-destination" value="new">
                        Clone to a new project
                    </label>
                    <div class="project-select-container" id="new-project-container" style="display: none;">
                        <input type="text" class="new-project-input" id="new-project-name-input" placeholder="Enter new project name...">
                        <div style="margin-top: 12px;">
                            <label style="display: block; margin-bottom: 6px; color: #6b7280; font-size: 14px;">Category:</label>
                            <select class="project-select" id="new-project-category-select">
                                <option value="companies">Companies</option>
                                <option value="locations">Locations</option>
                                <option value="referrers">Referrers</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" id="clone-modal-cancel">Cancel</button>
                <button class="modal-btn modal-btn-primary" id="clone-modal-confirm">Clone Facility</button>
            </div>
        </div>
    </div>

    <script>
        // Ensure referrer-specific array fields render even if the main loader misses them.
        document.addEventListener('formReady', () => {
            const getActiveConsultant = () => {
                const consultants = window.formData?.referrerConsultants || [];
                const idx = window.currentConsultantIndex ?? 0;
                return consultants[idx] || consultants[0] || {};
            };

            const ensureReferrerArray = (path, value) => {
                const container = document.querySelector(`[data-path="${path}"]`);
                if (!container || typeof renderArray !== 'function') {
                    return;
                }
                const data = Array.isArray(value) ? value : [];
                renderArray(container, path, data);
            };

            const consultant = getActiveConsultant();
            ensureReferrerArray('consultant.knownReferrals', consultant.knownReferrals);
            ensureReferrerArray('consultant.schoolDistricts', consultant.schoolDistricts);
        }, { once: true });
    </script>

    <!-- Suggestion Reason Modal -->
    <div id="suggestion-reason-modal" class="organizer-modal">
        <div class="organizer-modal-content" style="max-width: 500px;">
            <div class="organizer-modal-header">
                <h2 style="margin: 0; font-size: 22px;">Reason for Submission</h2>
                <button class="organizer-modal-close" id="suggestion-modal-close">&times;</button>
            </div>
            <div class="organizer-modal-body">
                <p style="margin-top: 0; margin-bottom: 15px; color: #6b7280;">Please briefly summarize the changes you made. This helps us review your submission more quickly.</p>
                <div class="form-group">
                    <label for="suggestion-summary" style="font-weight: 600;">Summary of Changes:</label>
                    <textarea id="suggestion-summary" rows="4" placeholder="e.g., Added new facility, corrected operator name, updated staff list..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;"></textarea>
                    <p id="suggestion-error" style="color: #dc2626; font-size: 13px; display: none; margin-top: 5px;">Please provide a summary of your changes.</p>
                </div>
                <div style="padding: 20px 0 0 0; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button class="modal-btn modal-btn-secondary" id="suggestion-modal-cancel" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s;">Cancel</button>
                    <button class="modal-btn modal-btn-primary" id="suggestion-modal-confirm" style="padding: 10px 20px; border: none; background: #33A7B5; color: white; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s;">Submit Suggestion</button>
                </div>
            </div>
        </div>
    </div>

        </div>

<?php get_footer(); ?>
