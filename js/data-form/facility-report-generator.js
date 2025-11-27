// Add this to your facility-form.js or include it separately

/**
 * Determines the project category for the current project
 * Returns 'companies', 'locations', or 'referrers'
 */
function getReportProjectCategory() {
    // First check if there's a stored category on the current project
    if (typeof currentProjectName !== 'undefined' && currentProjectName && typeof window.projects !== 'undefined') {
        const project = window.projects[currentProjectName];
        if (project && project.category) {
            return project.category;
        }
    }

    // Fallback: check formData for clues
    // If referrer data is present and substantial, it's likely a referrer project
    if (formData) {
        const agency = formData.referrerAgency || formData.referrerGroup;
        const consultants = formData.referrerConsultants || [];
        const hasAgencyData = agency && (agency.name || agency.city);
        const hasConsultantData = consultants.some(c => c && (c.firstName || c.lastName || c.fullName));

        if ((hasAgencyData || hasConsultantData) && 
            (!formData.operator || !formData.operator.name) && 
            (!formData.facilities || formData.facilities.length === 0)) {
            return 'referrers';
        }
    }

    // Try to infer from project name using heuristics
    if (typeof currentProjectName === 'string') {
        const normalized = currentProjectName.toLowerCase().trim();
        
        // Referrer keywords
        if (normalized.includes('consultant') ||
            normalized.includes('district') ||
            normalized.includes('agency') ||
            normalized.includes('referrer') ||
            normalized.includes('education') ||
            normalized.includes('school')) {
            return 'referrers';
        }

        // Location/State detection - US states and countries
        const US_STATES = ['alabama','alaska','arizona','arkansas','california','colorado','connecticut',
            'delaware','florida','georgia','hawaii','idaho','illinois','indiana','iowa','kansas',
            'kentucky','louisiana','maine','maryland','massachusetts','michigan','minnesota',
            'mississippi','missouri','montana','nebraska','nevada','new hampshire','new jersey',
            'new mexico','new york','north carolina','north dakota','ohio','oklahoma','oregon',
            'pennsylvania','rhode island','south carolina','south dakota','tennessee','texas',
            'utah','vermont','virginia','washington','west virginia','wisconsin','wyoming'];
        
        const COUNTRIES = ['united states','usa','canada','mexico','jamaica','costa rica','samoa',
            'australia','united kingdom','uk','ireland'];

        if (US_STATES.includes(normalized) || COUNTRIES.includes(normalized)) {
            return 'locations';
        }
    }

    return 'companies';
}

/**
 * Gets the appropriate section title based on project category
 */
function getCategorySectionTitle(category) {
    switch (category) {
        case 'locations':
            return 'State/Location Information';
        case 'referrers':
            return 'Referrer Organization';
        case 'companies':
        default:
            return 'Operator Information';
    }
}

function generateHTMLReport() {
    const reportWindow = window.open('', '_blank', 'width=1000,height=800,scrollbars=yes,resizable=yes');

    if (!reportWindow) {
        alert('Popup blocked! Please allow popups for this site and try again.');
        return;
    }

    // Determine project category for category-aware rendering
    const projectCategory = getReportProjectCategory();

    const html = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${currentProjectName || 'Facility Report'}</title>
    <style>
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }

        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .report-header {
            border-bottom: 3px solid #33A7B5;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .report-title {
            font-size: 32px;
            font-weight: 700;
            color: #33A7B5;
            margin-bottom: 10px;
        }

        .report-meta {
            color: #666;
            font-size: 14px;
        }

        .section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 24px;
            color: #33A7B5;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .subsection-title {
            font-size: 18px;
            color: #555;
            margin-top: 25px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .facility-card {
            background: #f9fafb;
            border-left: 4px solid #33A7B5;
            padding: 20px;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }

        .facility-name {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 15px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            gap: 8px;
        }

        .info-label {
            font-weight: 600;
            color: #555;
        }

        .info-value {
            color: #333;
        }

        .list-section {
            margin-top: 15px;
        }

        .list-title {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .list-items {
            list-style: none;
            padding-left: 0;
        }

        .list-items li {
            padding: 5px 0 5px 20px;
            position: relative;
        }

        .list-items li:before {
            content: "•";
            position: absolute;
            left: 5px;
            color: #33A7B5;
            font-weight: bold;
        }

        .staff-item {
            background: white;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
        }

        .staff-role {
            font-weight: 600;
            color: #33A7B5;
        }

        .actions {
            position: sticky;
            top: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: flex;
            gap: 10px;
            justify-content: center;
            z-index: 999999;
        }

        .btn {
            padding: 10px 20px;
            background: #33A7B5;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .btn:hover {
            background: #2a8c96;
        }

        .btn-secondary {
            background: #6b7280;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .empty-note {
            color: #9ca3af;
            font-style: italic;
        }

        .referrer-card {
            border-left-color: #8b5cf6;
        }

        .referrer-name {
            color: #6d28d9;
        }

        .consultant-card {
            background: #faf5ff;
            border-left: 4px solid #8b5cf6;
        }

        .field-notes-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #e5e7eb;
        }

        .field-note-item {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 10px 15px;
            margin: 8px 0;
            font-size: 14px;
        }

        .field-note-label {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 4px;
        }

        .field-note-text {
            color: #78350f;
        }
    </style>
</head>
<body>
    <div class="actions no-print">
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
        <button class="btn btn-secondary" onclick="closeReport()">Close</button>
    </div>

    <script>
        function closeReport() {
            // Try to close the window
            window.close();

            // If window.close() didn't work (blocked by browser), show a message
            setTimeout(function() {
                if (!window.closed) {
                    alert('Please close this window manually using your browser controls (or press Alt+F4 / Cmd+W)');
                }
            }, 100);
        }

        // Allow ESC key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeReport();
            }
        });
    </script>

    <div class="report-container">
        <div class="report-header">
            <h1 class="report-title">${currentProjectName || 'Facility Data Report'}</h1>
            <div class="report-meta">
                Generated: ${new Date().toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })}
                ${projectCategory !== 'companies' ? `<br><span style="color: #6b7280;">Category: ${projectCategory === 'locations' ? 'Location/State' : 'Referrer'}</span>` : ''}
            </div>
        </div>

        ${generateOperatorSection(projectCategory)}
        ${generateFacilitiesSection(projectCategory)}
        ${generateReferrerSection(projectCategory)}
    </div>
</body>
</html>
    `;

    reportWindow.document.write(html);
    reportWindow.document.close();

    // Bring the window to front and focus it
    setTimeout(() => {
        reportWindow.focus();
    }, 100);
}

function generateOperatorSection() {
    const op = formData.operator;
    if (!op || !op.name) return '';

    // Build location string from city/state if location isn't already set
    let locationDisplay = op.location;
    if (!locationDisplay && (op.locationCity || op.locationState)) {
        locationDisplay = [op.locationCity, op.locationState].filter(Boolean).join(', ');
    }

    // Build headquarters string from city/state if not already set
    let headquartersDisplay = op.headquarters;
    if (!headquartersDisplay && (op.headquartersCity || op.headquartersState)) {
        headquartersDisplay = [op.headquartersCity, op.headquartersState].filter(Boolean).join(', ');
    }

    return `
        <div class="section">
            <h2 class="section-title">Operator Information</h2>

            <div class="info-grid">
                ${op.name ? `<div class="info-item"><span class="info-label">Name:</span><span class="info-value">${escapeHtml(op.name)}</span></div>` : ''}
                ${op.currentName ? `<div class="info-item"><span class="info-label">Current Name:</span><span class="info-value">${escapeHtml(op.currentName)}</span></div>` : ''}
                ${locationDisplay ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml(locationDisplay)}</span></div>` : ''}
                ${headquartersDisplay ? `<div class="info-item"><span class="info-label">Headquarters:</span><span class="info-value">${escapeHtml(headquartersDisplay)}</span></div>` : ''}
                ${op.founded ? `<div class="info-item"><span class="info-label">Founded:</span><span class="info-value">${escapeHtml(op.founded)}</span></div>` : ''}
                ${op.operatingPeriod ? `<div class="info-item"><span class="info-label">Operating Period:</span><span class="info-value">${escapeHtml(op.operatingPeriod)}</span></div>` : ''}
                ${op.status ? `<div class="info-item"><span class="info-label">Status:</span><span class="info-value">${escapeHtml(op.status)}</span></div>` : ''}
                ${op.keyStaff?.ceo ? `<div class="info-item"><span class="info-label">CEO:</span><span class="info-value">${escapeHtml(op.keyStaff.ceo)}</span></div>` : ''}
            </div>

            ${renderList('Other Names', op.otherNames)}
            ${renderList('Parent Companies', op.parentCompanies)}
            ${renderList('Websites', op.websites)}
            ${renderList('Founders', op.keyStaff?.founders)}
            ${renderList('Key Executives', op.keyStaff?.keyExecutives)}
            ${renderList('Investors', op.investors)}
            ${renderList('Notes', op.notes)}
            ${renderFieldNotes(op.fieldNotes, 'Operator')}
        </div>
    `;
}

function generateFacilitiesSection() {
    if (!formData.facilities || formData.facilities.length === 0) {
        return '<div class="section"><h2 class="section-title">Facilities</h2><p class="empty-note">No facilities data available.</p></div>';
    }

    const facilitiesHTML = formData.facilities.map((facility, index) => {
        const name = facility.identification?.name || facility.identification?.currentName || `Facility ${index + 1}`;

        return `
            <div class="facility-card">
                <h3 class="facility-name">${escapeHtml(name)}</h3>

                ${generateFacilityBasicInfo(facility)}
                ${generateFacilityLocation(facility)}
                ${generateFacilityStaff(facility)}
                ${generateFacilityDetails(facility)}
                ${generateFacilityOperations(facility)}
                ${generateFacilityAccreditations(facility)}
                ${generateFacilityResources(facility)}
                ${generateFacilityTreatmentTypes(facility)}
                ${generateFacilityPhilosophy(facility)}
                ${generateFacilityCriticalIncidents(facility)}
                ${generateFacilityNotes(facility)}
                ${generateFacilityFieldNotes(facility)}
            </div>
        `;
    }).join('');

    return `
        <div class="section">
            <h2 class="section-title">Facilities (${formData.facilities.length})</h2>
            ${facilitiesHTML}
        </div>
    `;
}

function generateReferrerSection() {
    const agency = formData.referrerAgency || formData.referrerGroup;
    const consultants = formData.referrerConsultants || [];
    const isIndependent = formData.isIndependentConsultant;

    // Check if there's any referrer data to display
    const hasAgencyData = agency && (agency.name || agency.city || agency.affiliations?.length);
    const hasConsultantData = consultants.some(c => c && (c.firstName || c.lastName || c.fullName));

    if (!hasAgencyData && !hasConsultantData) {
        return '';
    }

    let html = '<div class="section"><h2 class="section-title">👥 Referrer Information</h2>';

    // Agency/Group information
    if (hasAgencyData && !isIndependent) {
        html += `
            <div class="subsection-title">Agency/Organization</div>
            <div class="info-grid">
                ${agency.name ? `<div class="info-item"><span class="info-label">Name:</span><span class="info-value">${escapeHtml(agency.name)}</span></div>` : ''}
                ${agency.city || agency.state ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml([agency.city, agency.state].filter(Boolean).join(', '))}</span></div>` : ''}
                ${agency.address ? `<div class="info-item"><span class="info-label">Address:</span><span class="info-value">${escapeHtml(agency.address)}</span></div>` : ''}
                ${agency.website ? `<div class="info-item"><span class="info-label">Website:</span><span class="info-value">${escapeHtml(agency.website)}</span></div>` : ''}
                ${agency.founded ? `<div class="info-item"><span class="info-label">Founded:</span><span class="info-value">${escapeHtml(agency.founded)}</span></div>` : ''}
            </div>
            ${renderList('Affiliations', agency.affiliations)}
            ${renderList('Key Personnel', agency.keyPersonnel)}
            ${agency.notes ? `<div class="list-section"><div class="list-title">Notes</div><p>${escapeHtml(agency.notes)}</p></div>` : ''}
            ${renderFieldNotes(agency.fieldNotes, 'Agency')}
        `;
    }

    // Individual consultants
    if (hasConsultantData) {
        const consultantTitle = isIndependent ? 'Independent Consultant' : 'Consultants';
        html += `<div class="subsection-title">${consultantTitle}</div>`;

        consultants.forEach((consultant, index) => {
            if (!consultant) return;

            const name = consultant.fullName || 
                        [consultant.firstName, consultant.lastName].filter(Boolean).join(' ') ||
                        `Consultant ${index + 1}`;

            if (!name || name === `Consultant ${index + 1}` && !consultant.role && !consultant.email) {
                return; // Skip empty consultants
            }

            html += `
                <div class="facility-card" style="border-left-color: #8b5cf6;">
                    <h3 class="facility-name" style="color: #6d28d9;">${escapeHtml(name)}</h3>
                    <div class="info-grid">
                        ${consultant.role ? `<div class="info-item"><span class="info-label">Role:</span><span class="info-value">${escapeHtml(consultant.role)}</span></div>` : ''}
                        ${consultant.status ? `<div class="info-item"><span class="info-label">Status:</span><span class="info-value">${escapeHtml(consultant.status)}</span></div>` : ''}
                        ${consultant.city || consultant.state ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml([consultant.city, consultant.state].filter(Boolean).join(', '))}</span></div>` : ''}
                        ${consultant.email ? `<div class="info-item"><span class="info-label">Email:</span><span class="info-value">${escapeHtml(consultant.email)}</span></div>` : ''}
                        ${consultant.phone ? `<div class="info-item"><span class="info-label">Phone:</span><span class="info-value">${escapeHtml(consultant.phone)}</span></div>` : ''}
                        ${consultant.website ? `<div class="info-item"><span class="info-label">Website:</span><span class="info-value">${escapeHtml(consultant.website)}</span></div>` : ''}
                        ${consultant.education ? `<div class="info-item"><span class="info-label">Education:</span><span class="info-value">${escapeHtml(consultant.education)}</span></div>` : ''}
                        ${consultant.credentials ? `<div class="info-item"><span class="info-label">Credentials:</span><span class="info-value">${escapeHtml(consultant.credentials)}</span></div>` : ''}
                    </div>
                    ${renderList('Affiliations', consultant.affiliations)}
                    ${renderList('Facilities Referred', consultant.facilitiesReferred)}
                    ${renderList('Known Referrals', consultant.knownReferrals)}
                    ${renderList('Past TTI Jobs', consultant.pastTTIJobs)}
                    ${renderList('School Districts', consultant.schoolDistricts)}
                    ${consultant.lawsuits ? `<div class="list-section"><div class="list-title">Lawsuits</div><p>${escapeHtml(consultant.lawsuits)}</p></div>` : ''}
                    ${consultant.notes ? `<div class="list-section"><div class="list-title">Notes</div><p>${escapeHtml(consultant.notes)}</p></div>` : ''}
                    ${renderFieldNotes(consultant.fieldNotes, 'Consultant')}
                </div>
            `;
        });
    }

    html += '</div>';
    return html;
}

function generateFacilityBasicInfo(facility) {
    const id = facility.identification;
    if (!id) return '';

    // Combine otherNames and pastNames
    const allNames = [...(id.otherNames || []), ...(id.pastNames || [])];
    const uniqueNames = [...new Set(allNames)];

    return `
        <div class="info-grid">
            ${id.currentName ? `<div class="info-item"><span class="info-label">Current Name:</span><span class="info-value">${escapeHtml(id.currentName)}</span></div>` : ''}
            ${id.currentOperator ? `<div class="info-item"><span class="info-label">Current Operator:</span><span class="info-value">${escapeHtml(id.currentOperator)}</span></div>` : ''}
        </div>
        ${renderList('Other Names', uniqueNames)}
        ${renderList('Known Referrers', id.knownReferrers)}
    `;
}

function generateFacilityLocation(facility) {
    // Check for location data in various formats
    const hasLocation = facility.location || facility.address || facility.city || facility.state || 
                        facility.country || (facility.otherOperators && facility.otherOperators.length > 0);
    
    if (!hasLocation) {
        return '';
    }

    // Build location string from city/state if location isn't already set
    let locationDisplay = facility.location;
    if (!locationDisplay && (facility.city || facility.state)) {
        locationDisplay = [facility.city, facility.state].filter(Boolean).join(', ');
    }

    return `
        <div class="subsection-title">Location & Operations</div>
        <div class="info-grid">
            ${locationDisplay ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml(locationDisplay)}</span></div>` : ''}
            ${facility.address ? `<div class="info-item"><span class="info-label">Address:</span><span class="info-value">${escapeHtml(facility.address)}</span></div>` : ''}
            ${facility.country ? `<div class="info-item"><span class="info-label">Country:</span><span class="info-value">${escapeHtml(facility.country)}</span></div>` : ''}
        </div>
        ${renderList('Other Operators', facility.otherOperators)}
    `;
}

function generateFacilityStaff(facility) {
    const staff = facility.staff;
    if (!staff || (!staff.administrator?.length && !staff.notableStaff?.length)) {
        return '';
    }

    return `
        <div class="subsection-title">Staff</div>
        ${renderStaffList('Administrators', staff.administrator)}
        ${renderStaffList('Notable Staff', staff.notableStaff)}
        ${renderList('Profile Links', facility.profileLinks)}
    `;
}

function generateFacilityDetails(facility) {
    const details = facility.facilityDetails;
    if (!details) return '';

    return `
        <div class="subsection-title">Facility Details</div>
        <div class="info-grid">
            ${details.type ? `<div class="info-item"><span class="info-label">Type:</span><span class="info-value">${escapeHtml(details.type)}</span></div>` : ''}
            ${details.capacity ? `<div class="info-item"><span class="info-label">Capacity:</span><span class="info-value">${details.capacity}</span></div>` : ''}
            ${details.currentCensus ? `<div class="info-item"><span class="info-label">Current Census:</span><span class="info-value">${details.currentCensus}</span></div>` : ''}
            ${details.ageRange?.min || details.ageRange?.max ? `<div class="info-item"><span class="info-label">Age Range:</span><span class="info-value">${details.ageRange.min || '?'} - ${details.ageRange.max || '?'}</span></div>` : ''}
            ${details.gender ? `<div class="info-item"><span class="info-label">Gender:</span><span class="info-value">${escapeHtml(details.gender)}</span></div>` : ''}
        </div>
    `;
}

function generateFacilityOperations(facility) {
    const op = facility.operatingPeriod;
    if (!op) return '';

    return `
        <div class="subsection-title">Operating Information</div>
        <div class="info-grid">
            ${op.startYear ? `<div class="info-item"><span class="info-label">Opened:</span><span class="info-value">${op.startYear}</span></div>` : ''}
            ${op.endYear ? `<div class="info-item"><span class="info-label">Closed:</span><span class="info-value">${op.endYear}</span></div>` : ''}
            ${op.status ? `<div class="info-item"><span class="info-label">Status:</span><span class="info-value">${escapeHtml(op.status)}</span></div>` : ''}
            ${op.yearsOfOperation ? `<div class="info-item"><span class="info-label">Years of Operation:</span><span class="info-value">${escapeHtml(op.yearsOfOperation)}</span></div>` : ''}
        </div>
        ${renderList('Operational Notes', op.notes)}
    `;
}

function generateFacilityAccreditations(facility) {
    const acc = facility.accreditations;
    if (!acc || (!acc.current?.length && !acc.past?.length && !facility.memberships?.length && !facility.certifications?.length && !facility.licensing?.length)) {
        return '';
    }

    return `
        <div class="subsection-title">Accreditations & Licensing</div>
        ${renderList('Current Accreditations', acc.current)}
        ${renderList('Past Accreditations', acc.past)}
        ${renderList('Memberships', facility.memberships)}
        ${renderList('Certifications', facility.certifications)}
        ${renderList('Licensing', facility.licensing)}
    `;
}

function generateFacilityResources(facility) {
    if (!facility.resources) return '';

    const resources = [];
    const resourceMap = {
        'hasNews': 'News',
        'hasPressReleases': 'Press Releases',
        'hasInspections': 'Inspections',
        'hasStateReports': 'State Reports',
        'hasRegulatoryFilings': 'Regulatory Filings',
        'hasLawsuits': 'Lawsuits',
        'hasSettlements': 'Settlements',
        'hasViolations': 'Violations',
        'hasResearch': 'Research',
        'hasFinancial': 'Financial',
        'hasNATSAP': 'NATSAP Profile',
        'hasWebsite': 'Website Screenshots',
        'hasPoliceReports': 'Police Reports',
        'hasArticlesOfOrganization': 'Articles of Organization',
        'hasPropertyRecords': 'Property Records',
        'hasPromotionalMaterials': 'Promotional Materials',
        'hasEnrollmentDocuments': 'Enrollment Documents',
        'hasStudent': 'Student Records',
        'hasStaff': 'Staff Records',
        'hasParent': 'Parent Records',
        'hasSurvivorStories': 'Survivor Stories',
        'hasOther': 'Other'
    };

    Object.keys(resourceMap).forEach(key => {
        if (facility.resources[key] === true) {
            resources.push(resourceMap[key]);
        }
    });

    if (facility.resources.customResources && facility.resources.customResources.length > 0) {
        resources.push(...facility.resources.customResources.filter(r => typeof r === 'string' && r.trim()));
    }

    if (resources.length === 0 && (!facility.resources.notes || facility.resources.notes.length === 0)) {
        return '';
    }

    return `
        <div class="subsection-title">Resources Available</div>
        ${resources.length > 0 ? `<p>${resources.map(r => escapeHtml(r)).join(', ')}</p>` : ''}
        ${renderList('Resource Notes', facility.resources.notes)}
    `;
}

function generateFacilityTreatmentTypes(facility) {
    if (!facility.treatmentTypes) return '';

    const treatments = [];
    const treatmentMap = {
        'hasABA': 'Applied Behavior Analysis (ABA)',
        'hasEquineTherapy': 'Equine Therapy',
        'hasWorkTherapy': 'Work Therapy',
        'hasWildernessTherapy': 'Wilderness Therapy',
        'hasRealityTherapy': 'Reality Therapy',
        'hasLGATSeminars': 'Large Group Awareness Training Seminars',
        'hasFeedbackHotseatGroups': 'Feedback/Hotseat Groups (The Game)',
        'hasPrimalScreamTherapy': 'Primal Scream Therapy',
        'hasRepressedMemoryTherapy': 'Repressed Memory Therapy',
        'hasBehaviorModification': 'Behavior Modification',
        'hasKetamineTherapy': 'Ketamine Therapy',
        'hasExposureTherapy': 'Exposure Therapy',
        'hasUnlicensedProvider': 'Therapy with Unlicensed Provider',
        'hasConversionTherapy': 'Conversion/Reparative Therapy (SOGICE)',
        'hasAttachmentTherapy': 'Attachment Therapy',
        'hasRebirthingTherapy': 'Rebirthing Therapy',
        'hasTappingTherapy': 'Tapping/Thought Field Therapy (TFT)',
        'hasPsychoanalysis': 'Psychoanalysis',
        'hasEMDR': 'Eye Movement Desensitization and Reprocessing (EMDR)',
        'hasHypnosis': 'Hypnosis'
    };

    Object.keys(treatmentMap).forEach(key => {
        if (facility.treatmentTypes[key] === true) {
            treatments.push(treatmentMap[key]);
        }
    });

    // Check for custom treatment types
    if (facility.treatmentTypes.custom && Array.isArray(facility.treatmentTypes.custom)) {
        treatments.push(...facility.treatmentTypes.custom.filter(t => typeof t === 'string' && t.trim()));
    }

    // Also check for any custom fields that aren't in the standard map
    Object.keys(facility.treatmentTypes).forEach(key => {
        if (!treatmentMap[key] && key !== 'custom' && facility.treatmentTypes[key] === true) {
            // Convert camelCase to readable format
            const readable = key.replace(/^has/, '').replace(/([A-Z])/g, ' $1').trim();
            if (readable) treatments.push(readable);
        }
    });

    if (treatments.length === 0) return '';

    return `
        <div class="subsection-title">Treatment Types</div>
        <p>${treatments.map(t => escapeHtml(t)).join(', ')}</p>
    `;
}

function generateFacilityPhilosophy(facility) {
    if (!facility.philosophy) return '';

    const philosophies = [];
    const philosophyMap = {
        'hasPositivePeerCulture': 'Positive Peer Culture',
        'has12Steps': '12 Steps',
        'hasFundamentalistBaptist': 'Fundamentalist Baptist',
        'hasPentecostal': 'Pentecostal',
        'hasScientology': 'Scientology',
        'hasTherapeuticCommunity': 'Therapeutic Community',
        'hasWildernessRoad': 'Wilderness Road',
        'hasPsychoanalytic': 'Psychoanalytic',
        'hasLawOfAttraction': 'Law of Attraction',
        'hasHumanPotentialMovement': 'Human Potential Movement'
    };

    Object.keys(philosophyMap).forEach(key => {
        if (facility.philosophy[key] === true) {
            philosophies.push(philosophyMap[key]);
        }
    });

    // Check for custom philosophies
    if (facility.philosophy.custom && Array.isArray(facility.philosophy.custom)) {
        philosophies.push(...facility.philosophy.custom.filter(p => typeof p === 'string' && p.trim()));
    }

    // Also check for any custom fields that aren't in the standard map
    Object.keys(facility.philosophy).forEach(key => {
        if (!philosophyMap[key] && key !== 'custom' && facility.philosophy[key] === true) {
            // Convert camelCase to readable format
            const readable = key.replace(/^has/, '').replace(/([A-Z])/g, ' $1').trim();
            if (readable) philosophies.push(readable);
        }
    });

    if (philosophies.length === 0) return '';

    return `
        <div class="subsection-title">Philosophy</div>
        <p>${philosophies.map(p => escapeHtml(p)).join(', ')}</p>
    `;
}

function generateFacilityCriticalIncidents(facility) {
    if (!facility.criticalIncidents) return '';

    const incidents = [];
    const incidentMap = {
        'hasDeaths': 'Deaths',
        'hasStaffArrests': 'Staff Arrests',
        'hasStudentHospitalizations': 'Student Hospitalizations',
        'hasRiots': 'Riots'
    };

    Object.keys(incidentMap).forEach(key => {
        if (facility.criticalIncidents[key] === true) {
            incidents.push(incidentMap[key]);
        }
    });

    // Check for custom incidents
    if (facility.criticalIncidents.custom && Array.isArray(facility.criticalIncidents.custom)) {
        incidents.push(...facility.criticalIncidents.custom.filter(i => typeof i === 'string' && i.trim()));
    }

    // Also check for any custom fields that aren't in the standard map
    Object.keys(facility.criticalIncidents).forEach(key => {
        if (!incidentMap[key] && key !== 'custom' && facility.criticalIncidents[key] === true) {
            // Convert camelCase to readable format
            const readable = key.replace(/^has/, '').replace(/([A-Z])/g, ' $1').trim();
            if (readable) incidents.push(readable);
        }
    });

    if (incidents.length === 0) return '';

    return `
        <div class="subsection-title">Critical Incidents</div>
        <p style="color: #dc2626;">${incidents.map(i => escapeHtml(i)).join(', ')}</p>
    `;
}

function generateFacilityNotes(facility) {
    if (!facility.notes || facility.notes.length === 0) return '';

    return `
        <div class="subsection-title">Facility Notes</div>
        ${renderList('Notes', facility.notes)}
    `;
}

function generateFacilityFieldNotes(facility) {
    return renderFieldNotes(facility.fieldNotes, 'Field');
}

/**
 * Renders field notes from the fieldNotes object
 * @param {object} fieldNotes - The fieldNotes object containing notes keyed by field identifier
 * @param {string} sectionLabel - Label for the section (e.g., 'Operator', 'Field')
 * @returns {string} - HTML string for the field notes section
 */
function renderFieldNotes(fieldNotes, sectionLabel = 'Field') {
    if (!fieldNotes || typeof fieldNotes !== 'object') return '';

    const noteEntries = [];

    // Map of common field keys to human-readable labels
    const fieldLabelMap = {
        'operator.name': 'Operator Name',
        'operator.currentName': 'Current Operator Name',
        'operator.location': 'Operator Location',
        'operator.headquarters': 'Headquarters',
        'operator.founded': 'Founded',
        'operator.status': 'Status',
        'operator.operatingPeriod': 'Operating Period',
        'identification.name': 'Facility Name',
        'identification.currentName': 'Current Name',
        'identification.currentOperator': 'Current Operator',
        'location': 'Location',
        'address': 'Address',
        'facilityDetails.type': 'Facility Type',
        'facilityDetails.capacity': 'Capacity',
        'facilityDetails.gender': 'Gender',
        'operatingPeriod.startYear': 'Start Year',
        'operatingPeriod.endYear': 'End Year',
        'operatingPeriod.status': 'Operating Status',
        'treatmentTypes': 'Treatment Types',
        'philosophy': 'Philosophy',
        'criticalIncidents': 'Critical Incidents',
        'resources': 'Resources'
    };

    Object.keys(fieldNotes).forEach(key => {
        const notes = fieldNotes[key];
        if (!notes) return;

        // Get a human-readable label for this field
        let fieldLabel = fieldLabelMap[key];
        if (!fieldLabel) {
            // Convert camelCase/dot notation to readable format
            fieldLabel = key
                .replace(/\./g, ' → ')
                .replace(/([A-Z])/g, ' $1')
                .replace(/^./, str => str.toUpperCase())
                .replace(/has\s*/gi, '')
                .trim();
        }

        // Handle different note formats
        if (Array.isArray(notes)) {
            notes.forEach(note => {
                if (!note) return;
                
                let noteText = '';
                if (typeof note === 'string' && note.trim()) {
                    noteText = note.trim();
                } else if (typeof note === 'object') {
                    // Notes module stores notes as {id, text, timestamp}
                    noteText = note.text || note.value || note.content || '';
                    if (typeof noteText === 'string') noteText = noteText.trim();
                }
                
                if (noteText) {
                    noteEntries.push({ label: fieldLabel, text: noteText });
                }
            });
        } else if (typeof notes === 'string' && notes.trim()) {
            noteEntries.push({ label: fieldLabel, text: notes.trim() });
        } else if (typeof notes === 'object' && notes.text) {
            noteEntries.push({ label: fieldLabel, text: notes.text });
        }
    });

    if (noteEntries.length === 0) return '';

    const notesHTML = noteEntries.map(entry => `
        <div class="field-note-item">
            <div class="field-note-label">${escapeHtml(entry.label)}</div>
            <div class="field-note-text">${escapeHtml(entry.text)}</div>
        </div>
    `).join('');

    return `
        <div class="field-notes-section">
            <div class="subsection-title">📝 ${sectionLabel} Notes</div>
            ${notesHTML}
        </div>
    `;
}

function renderList(title, items) {
    if (!items || items.length === 0) return '';

    const listItems = items.map(item => {
        // Handle different item types
        if (item === null || item === undefined) return '';
        
        if (typeof item === 'string') {
            return item.trim() ? `<li>${escapeHtml(item)}</li>` : '';
        }
        
        if (typeof item === 'object') {
            // Try to extract a meaningful display value from common field names
            const displayValue = item.name || item.value || item.text || item.title || 
                                 item.url || item.link || item.label ||
                                 (item.firstName && item.lastName ? `${item.firstName} ${item.lastName}` : null) ||
                                 item.firstName || item.lastName;
            
            if (displayValue && typeof displayValue === 'string' && displayValue.trim()) {
                return `<li>${escapeHtml(displayValue)}</li>`;
            }
            
            // For objects without recognized fields, skip them to avoid [object Object]
            return '';
        }
        
        // For numbers or other primitives, convert to string
        const strVal = String(item);
        return strVal && strVal !== '[object Object]' ? `<li>${escapeHtml(strVal)}</li>` : '';
    }).filter(item => item).join('');

    if (!listItems) return '';

    return `
        <div class="list-section">
            <div class="list-title">${title}</div>
            <ul class="list-items">${listItems}</ul>
        </div>
    `;
}

function renderStaffList(title, staffArray) {
    if (!staffArray || staffArray.length === 0) return '';

    const staffItems = staffArray.map(staff => {
        let name, role;

        if (typeof staff === 'string') {
            name = staff;
            role = '';
        } else if (staff && typeof staff === 'object') {
            // Try multiple possible field names for the name
            name = staff.name || staff.Name || staff.fullName || staff.firstName ||
                   (staff.firstName && staff.lastName ? `${staff.firstName} ${staff.lastName}` : '') ||
                   JSON.stringify(staff); // Fallback to show the object data
            role = staff.role || staff.Role || staff.title || staff.Title || staff.position || staff.Position || '';
        } else {
            // Fallback for unexpected types
            name = String(staff);
            role = '';
        }

        if (!name || name === '{}' || name === '[object Object]') return '';

        return `
            <div class="staff-item">
                ${role ? `<div class="staff-role">${escapeHtml(role)}</div>` : ''}
                <div>${escapeHtml(name)}</div>
            </div>
        `;
    }).filter(item => item).join('');

    if (!staffItems) return '';

    return `
        <div class="list-section">
            <div class="list-title">${title}</div>
            ${staffItems}
        </div>
    `;
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    
    // Handle objects - try to extract a string value
    if (typeof text === 'object') {
        const displayValue = text.name || text.value || text.text || text.title || 
                             text.url || text.link || text.label ||
                             (text.firstName && text.lastName ? `${text.firstName} ${text.lastName}` : null) ||
                             text.firstName || text.lastName;
        if (displayValue && typeof displayValue === 'string') {
            text = displayValue;
        } else {
            return ''; // Don't output [object Object]
        }
    }
    
    // Convert to string if not already
    if (typeof text !== 'string') {
        text = String(text);
    }
    
    if (!text || text === '[object Object]') return '';
    
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
