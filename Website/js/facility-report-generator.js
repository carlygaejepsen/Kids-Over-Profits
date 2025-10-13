// Add this to your facility-form.js or include it separately

function generateHTMLReport() {
    const reportWindow = window.open('', '_blank');

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
    </style>
</head>
<body>
    <div class="actions no-print">
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>
    </div>

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
            </div>
        </div>

        ${generateOperatorSection()}
        ${generateFacilitiesSection()}
    </div>
</body>
</html>
    `;

    reportWindow.document.write(html);
    reportWindow.document.close();
}

function generateOperatorSection() {
    const op = formData.operator;
    if (!op || !op.name) return '';

    return `
        <div class="section">
            <h2 class="section-title">Operator Information</h2>

            <div class="info-grid">
                ${op.name ? `<div class="info-item"><span class="info-label">Name:</span><span class="info-value">${escapeHtml(op.name)}</span></div>` : ''}
                ${op.currentName ? `<div class="info-item"><span class="info-label">Current Name:</span><span class="info-value">${escapeHtml(op.currentName)}</span></div>` : ''}
                ${op.location ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml(op.location)}</span></div>` : ''}
                ${op.headquarters ? `<div class="info-item"><span class="info-label">Headquarters:</span><span class="info-value">${escapeHtml(op.headquarters)}</span></div>` : ''}
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

function generateFacilityBasicInfo(facility) {
    const id = facility.identification;
    if (!id) return '';

    return `
        <div class="info-grid">
            ${id.currentName ? `<div class="info-item"><span class="info-label">Current Name:</span><span class="info-value">${escapeHtml(id.currentName)}</span></div>` : ''}
            ${id.currentOperator ? `<div class="info-item"><span class="info-label">Current Operator:</span><span class="info-value">${escapeHtml(id.currentOperator)}</span></div>` : ''}
        </div>
        ${renderList('Other Names', id.otherNames)}
    `;
}

function generateFacilityLocation(facility) {
    if (!facility.location && !facility.address && (!facility.otherOperators || facility.otherOperators.length === 0)) {
        return '';
    }

    return `
        <div class="subsection-title">Location & Operations</div>
        <div class="info-grid">
            ${facility.location ? `<div class="info-item"><span class="info-label">Location:</span><span class="info-value">${escapeHtml(facility.location)}</span></div>` : ''}
            ${facility.address ? `<div class="info-item"><span class="info-label">Address:</span><span class="info-value">${escapeHtml(facility.address)}</span></div>` : ''}
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

function renderList(title, items) {
    if (!items || items.length === 0) return '';

    const listItems = items.map(item => `<li>${escapeHtml(item)}</li>`).join('');

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
            name = staff.name || '';
            role = staff.role || '';
        }

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
            <div class="list-title">${title}</div>
            ${staffItems}
        </div>
    `;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
