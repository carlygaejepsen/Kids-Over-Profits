// Facility Report Generator v2.1 - December 2025 - Staff normalization active
// Add this to your facility-form.js or include it separately

function generateHTMLReport() {
    console.log('[Report Generator v2.1] Loaded successfully - staff normalization active');
    const reportWindow = window.open('', '_blank', 'width=1000,height=800,scrollbars=yes,resizable=yes');

    if (!reportWindow) {
        alert('Popup blocked! Please allow popups for this site and try again.');
        return;
    }

    const html = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${escapeHtml(currentProjectName) || 'Facility Report'}</title>
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
            <h1 class="report-title">${escapeHtml(currentProjectName) || 'Facility Data Report'}</h1>
            <div class="report-meta">
                Generated: ${new Date().toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })}
            </div>
        </div>

        ${generateOperatorSection()}
        ${generateFacilitiesSection()}
        ${generateReferrerSection()}
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

    const founders = normalizeStaffArray(op.keyStaff?.founders);
    const executives = normalizeStaffArray(op.keyStaff?.keyExecutives);

    // Build location string from city/state if location isn't already set
    let locationDisplay = safeString(op.location);
    if (!locationDisplay && (op.locationCity || op.locationState)) {
        locationDisplay = [safeString(op.locationCity), safeString(op.locationState)].filter(Boolean).join(', ');
    }

    // Build headquarters string from city/state if not already set
    let headquartersDisplay = safeString(op.headquarters);
    if (!headquartersDisplay && (op.headquartersCity || op.headquartersState)) {
        headquartersDisplay = [safeString(op.headquartersCity), safeString(op.headquartersState)].filter(Boolean).join(', ');
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
            ${renderStaffList('Founders', founders)}
            ${renderStaffList('Key Executives', executives)}
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
                ${agency.city || agency.state ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml([safeString(agency.city), safeString(agency.state)].filter(Boolean).join(', '))}</span></div>` : ''}
                ${agency.address ? `<div class="info-item"><span class="info-label">Address:</span><span class="info-value">${escapeHtml(agency.address)}</span></div>` : ''}
                ${agency.website ? `<div class="info-item"><span class="info-label">Website:</span><span class="info-value">${formatWebsiteLink(agency.website)}</span></div>` : ''}
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

            const name = safeString(consultant.fullName) || 
                        [safeString(consultant.firstName), safeString(consultant.lastName)].filter(Boolean).join(' ') ||
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
                        ${consultant.city || consultant.state ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml([safeString(consultant.city), safeString(consultant.state)].filter(Boolean).join(', '))}</span></div>` : ''}
                        ${consultant.email ? `<div class="info-item"><span class="info-label">Email:</span><span class="info-value">${formatEmailLink(consultant.email)}</span></div>` : ''}
                        ${consultant.phone ? `<div class="info-item"><span class="info-label">Phone:</span><span class="info-value">${formatPhoneLink(consultant.phone)}</span></div>` : ''}
                        ${consultant.website ? `<div class="info-item"><span class="info-label">Website:</span><span class="info-value">${formatWebsiteLink(consultant.website)}</span></div>` : ''}
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
    let locationDisplay = safeString(facility.location);
    if (!locationDisplay && (facility.city || facility.state)) {
        locationDisplay = [safeString(facility.city), safeString(facility.state)].filter(Boolean).join(', ');
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
    const admins = normalizeStaffArray(staff?.administrator);
    const notable = normalizeStaffArray(staff?.notableStaff);

    if (!staff || (!admins.length && !notable.length)) {
        return '';
    }

    return `
        <div class="subsection-title">Staff</div>
        ${renderStaffList('Administrators', admins)}
        ${renderStaffList('Notable Staff', notable)}
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
            ${details.capacity ? `<div class="info-item"><span class="info-label">Capacity:</span><span class="info-value">${escapeHtml(details.capacity)}</span></div>` : ''}
            ${details.currentCensus ? `<div class="info-item"><span class="info-label">Current Census:</span><span class="info-value">${escapeHtml(details.currentCensus)}</span></div>` : ''}
            ${details.ageRange?.min || details.ageRange?.max ? `<div class="info-item"><span class="info-label">Age Range:</span><span class="info-value">${escapeHtml(details.ageRange.min) || '?'} - ${escapeHtml(details.ageRange.max) || '?'}</span></div>` : ''}
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
            ${op.startYear ? `<div class="info-item"><span class="info-label">Opened:</span><span class="info-value">${escapeHtml(op.startYear)}</span></div>` : ''}
            ${op.endYear ? `<div class="info-item"><span class="info-label">Closed:</span><span class="info-value">${escapeHtml(op.endYear)}</span></div>` : ''}
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
                .replace(/\./g, ' ')
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
    if (!items || !Array.isArray(items) || items.length === 0) return '';

    const isWebsiteList = title.toLowerCase().includes('website') || 
                          title.toLowerCase().includes('link') ||
                          title.toLowerCase().includes('url');

    const listItems = items.map(item => {
        // Get a clean string value using safeString
        const displayValue = safeString(item);
        
        if (!displayValue) return '';

        // Check if it should be rendered as a link
        if (isWebsiteList || looksLikeUrl(displayValue)) {
            return `<li>${formatWebsiteLink(displayValue)}</li>`;
        }
        
        // Check if it's an email
        if (looksLikeEmail(displayValue)) {
            return `<li>${formatEmailLink(displayValue)}</li>`;
        }
        
        return `<li>${escapeHtml(displayValue)}</li>`;
    }).filter(item => item).join('');

    if (!listItems) return '';

    return `
        <div class="list-section">
            <div class="list-title">${escapeHtml(title)}</div>
            <ul class="list-items">${listItems}</ul>
        </div>
    `;
}

function normalizeStaffArray(rawStaff) {
    if (!rawStaff) return [];

    const entries = Array.isArray(rawStaff) ? rawStaff : (typeof rawStaff === 'object' ? Object.values(rawStaff) : [rawStaff]);
    return entries.flatMap(normalizeStaffEntry).filter(item => item && item.name);
}

function normalizeStaffEntry(entry) {
    if (entry === null || entry === undefined) return [];

    // Strings: attempt JSON parse, then "Role: Name" split, then plain name
    if (typeof entry === 'string') {
        const trimmed = entry.trim();
        if (!trimmed || /\[object\s+object\]/i.test(trimmed)) return [];

        if (/^[\[{]/.test(trimmed)) {
            try {
                return normalizeStaffEntry(JSON.parse(trimmed));
            } catch (err) {
                console.warn('[Report Generator] Failed to parse staff JSON string', trimmed, err);
            }
        }

        const roleNameMatch = trimmed.match(/^([^:]+):\s*(.+)$/);
        if (roleNameMatch) {
            return [{
                role: safeString(roleNameMatch[1]),
                name: safeString(roleNameMatch[2])
            }].filter(item => item.name);
        }

        return [{ name: safeString(trimmed), role: '' }].filter(item => item.name);
    }

    // Arrays: flatten
    if (Array.isArray(entry)) {
        return entry.flatMap(normalizeStaffEntry);
    }

    // Objects: pull name/role hints, or build a readable summary
    if (typeof entry === 'object') {
        const nameFields = [
            entry.name, entry.Name, entry.fullName, entry.label,
            entry.value, entry.text, entry.display, entry.personName
        ];
        const roleFields = [
            entry.role, entry.Role, entry.title, entry.Title,
            entry.position, entry.Position, entry.jobTitle
        ];

        let name = safeString(nameFields.find(Boolean));

        if (!name && (entry.firstName || entry.lastName)) {
            name = [safeString(entry.firstName), safeString(entry.lastName)].filter(Boolean).join(' ').trim();
        }

        const role = safeString(roleFields.find(Boolean));

        // Last resort: readable key/value summary if no explicit name
        if (!name) {
            name = buildKeyValueSummary(entry);
        }

        if (!name) return [];
        return [{ name, role }];
    }

    // Anything else: stringify safely
    return [{ name: safeString(entry), role: '' }].filter(item => item.name);
}

function buildKeyValueSummary(obj) {
    if (!obj || typeof obj !== 'object') return '';
    const parts = Object.entries(obj).map(([key, value]) => {
        const val = safeString(value);
        if (!val) return '';
        const prettyKey = key.replace(/([A-Z])/g, ' $1').trim().replace(/^./, c => c.toUpperCase());
        return `${prettyKey}: ${val}`;
    }).filter(Boolean);
    return parts.join(', ');
}

function renderStaffList(title, rawStaffArray) {
    const staffArray = normalizeStaffArray(rawStaffArray);
    if (!Array.isArray(staffArray) || staffArray.length === 0) return '';

    const staffItems = staffArray.map(staff => {
        const name = safeString(staff?.name);
        const role = safeString(staff?.role);

        // Skip if no valid name
        if (!name) return '';

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
            <div class="list-title">${escapeHtml(title)}</div>
            ${staffItems}
        </div>
    `;
}

/**
 * Safely extracts a displayable string from any value
 * @param {*} value - The value to convert to a safe string
 * @param {number} depth - Current recursion depth (for internal use)
 * @returns {string} - A clean string or empty string
 */
function safeString(value, depth = 0) {
    // Prevent infinite recursion
    if (depth > 3) return '';

    if (value === null || value === undefined) return '';

    // Already a string
    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (!trimmed || /\[object\s+object\]/i.test(trimmed)) return '';
        if (/^[\[{]/.test(trimmed)) {
            try {
                return safeString(JSON.parse(trimmed), depth + 1);
            } catch (err) {
                // Fall through if JSON parsing fails
            }
        }
        return trimmed;
    }

    // Numbers and booleans - convert directly
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    // Arrays - try to join string values
    if (Array.isArray(value)) {
        const stringValues = value.map(v => safeString(v, depth + 1)).filter(Boolean);
        return stringValues.join(', ');
    }
    
    // Objects - try to extract a meaningful value
    if (typeof value === 'object') {
        // Try common field names in order of preference
        const possibleFields = ['name', 'Name', 'fullName', 'value', 'text', 'title',
                                'url', 'link', 'label', 'display', 'content'];

        for (const field of possibleFields) {
            if (value[field]) {
                // If it's a string, use it directly
                if (typeof value[field] === 'string' && value[field].trim()) {
                    return value[field].trim();
                }
                // If it's a nested object, try to recursively extract
                if (typeof value[field] === 'object' && value[field] !== null) {
                    const nested = safeString(value[field]);
                    if (nested) return nested;
                }
            }
        }

        // Try firstName + lastName combo, optionally with role
        if (value.firstName || value.lastName) {
            const firstName = typeof value.firstName === 'string' ? value.firstName :
                              (typeof value.firstName === 'object' ? safeString(value.firstName, depth + 1) : '');
            const lastName = typeof value.lastName === 'string' ? value.lastName :
                             (typeof value.lastName === 'object' ? safeString(value.lastName, depth + 1) : '');
            const name = [firstName, lastName].filter(Boolean).join(' ').trim();

            // If there's also a role/title field, format as "role: name"
            if (name) {
                // Get role - try string fields first
                const roleField = value.role || value.Role || value.title || value.Title ||
                                value.position || value.Position || value.jobTitle;
                const role = typeof roleField === 'string' ? roleField.trim() :
                           (roleField && typeof roleField === 'object' ? safeString(roleField, depth + 1) : '');
                if (role) {
                    return `${role}: ${name}`;
                }
                return name;
            }
        }

        // Try role + name combination for staff objects
        const roleField = value.role || value.Role || value.title || value.Title ||
                        value.position || value.Position || value.jobTitle;
        const role = typeof roleField === 'string' ? roleField.trim() :
                   (roleField && typeof roleField === 'object' ? safeString(roleField, depth + 1) : '');

        const nameField = value.name || value.Name || value.fullName;
        const personName = typeof nameField === 'string' ? nameField.trim() :
                         (nameField && typeof nameField === 'object' ? safeString(nameField, depth + 1) : '');

        if (role && personName) {
            return `${role}: ${personName}`;
        }

        // If object has a small number of properties, try to format them nicely
        const entries = Object.entries(value).filter(([k, v]) => {
            // Skip internal/meta properties
            return !k.startsWith('_') && !k.startsWith('$') &&
                   v !== null && v !== undefined && v !== '';
        });

        if (entries.length > 0 && entries.length <= 5) {
            // Format as "key: value, key: value"
            const formatted = entries.map(([k, v]) => {
                const val = safeString(v, depth + 1);
                if (!val) return '';
                // Make key more readable
                const readableKey = k.replace(/([A-Z])/g, ' $1').trim()
                                    .replace(/^./, str => str.toUpperCase());
                return `${readableKey}: ${val}`;
            }).filter(Boolean).join(', ');

            if (formatted) return formatted;
        }

        // Last resort: don't show raw object notation
        return '';
    }
    
    // Fallback - try String() but check result
    const str = String(value);
    return (str && str !== '[object Object]') ? str : '';
}

function escapeHtml(text) {
    // Use safeString to get a clean string value first
    const cleanText = safeString(text);
    if (!cleanText) return '';

    const div = document.createElement('div');
    div.textContent = cleanText;
    return div.innerHTML;
}

/**
 * Checks if a string looks like a URL
 * @param {string} str - The string to check
 * @returns {boolean} - True if it looks like a URL
 */
function looksLikeUrl(str) {
    if (typeof str !== 'string') return false;
    const trimmed = str.trim().toLowerCase();
    return trimmed.startsWith('http://') || 
           trimmed.startsWith('https://') || 
           trimmed.startsWith('www.') ||
           /^[a-z0-9][a-z0-9-]*\.[a-z]{2,}/i.test(trimmed);
}

/**
 * Checks if a string looks like an email address
 * @param {string} str - The string to check
 * @returns {boolean} - True if it looks like an email
 */
function looksLikeEmail(str) {
    if (typeof str !== 'string') return false;
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(str.trim());
}

/**
 * Format a URL as a clickable link with clean display text
 * @param {*} url - The URL value (can be string or object with url/displayText properties)
 * @returns {string} - HTML link or empty string
 */
function formatWebsiteLink(url) {
    // Handle object with url and displayText properties
    let urlStr = '';
    let customDisplayText = '';
    
    if (url && typeof url === 'object') {
        urlStr = safeString(url.url || url.link || url.href || url);
        customDisplayText = safeString(url.displayText || url.text || url.label || url.title || '');
    } else {
        urlStr = safeString(url);
    }
    
    if (!urlStr) return '';
    
    // Ensure URL has protocol for href
    const href = urlStr.startsWith('http') ? urlStr : `https://${urlStr}`;
    
    // Use custom display text if provided, otherwise create clean display text
    let displayText = customDisplayText;
    if (!displayText) {
        try {
            // Try to parse the URL to get a cleaner display
            const urlObj = new URL(href);
            // Get hostname and remove www. prefix
            displayText = urlObj.hostname.replace(/^www\./, '');
            // If there's a meaningful path, add it (but truncate if too long)
            const path = urlObj.pathname;
            if (path && path !== '/' && path.length > 1) {
                const cleanPath = path.length > 30 ? path.substring(0, 30) + '...' : path;
                displayText += cleanPath;
            }
        } catch (e) {
            // If URL parsing fails, use original but clean up common prefixes
            displayText = urlStr
                .replace(/^https?:\/\//, '')
                .replace(/^www\./, '');
            // Truncate if too long
            if (displayText.length > 50) {
                displayText = displayText.substring(0, 50) + '...';
            }
        }
    }
    
    return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" style="color: #33A7B5; text-decoration: underline;">${escapeHtml(displayText)}</a>`;
}

/**
 * Format an email as a clickable mailto link
 * @param {*} email - The email value (can be string or object)
 * @returns {string} - HTML mailto link or empty string
 */
function formatEmailLink(email) {
    let emailStr = safeString(email);
    if (!emailStr) return '';
    
    return `<a href="mailto:${escapeHtml(emailStr)}" style="color: #33A7B5; text-decoration: underline;">${escapeHtml(emailStr)}</a>`;
}

/**
 * Format a phone number as a clickable tel link
 * @param {*} phone - The phone value (can be string or object)
 * @returns {string} - HTML tel link or escaped string
 */
function formatPhoneLink(phone) {
    let phoneStr = safeString(phone);
    if (!phoneStr) return '';
    
    // Clean the phone number for the tel: href (remove spaces, parens, dashes for some formats)
    const cleanPhone = phoneStr.replace(/[\s\(\)\-\.]/g, '');
    return `<a href="tel:${escapeHtml(cleanPhone)}" style="color: #33A7B5; text-decoration: underline;">${escapeHtml(phoneStr)}</a>`;
}
