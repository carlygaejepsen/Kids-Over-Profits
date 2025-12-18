// ============================================
// DATA FORM TEST SUITE
// ============================================
/**
 * Run comprehensive data loading diagnostics
 * Use in browser console: window.runDiagnostics()
 */
window.runDiagnostics = function() {
    console.clear();
    console.log('🔍 ========== FORM LOADING DIAGNOSTICS ==========');
    
    const issues = [];
    
    // Test 1: API Endpoints
    console.log('\n1️⃣  API ENDPOINTS:');
    console.log('  LOAD_PROJECTS:', API_ENDPOINTS.LOAD_PROJECTS);
    console.log('  SAVE_PROJECT:', API_ENDPOINTS.SAVE_PROJECT);
    console.log('  AUTOCOMPLETE:', API_ENDPOINTS.AUTOCOMPLETE);
    
    // Test 2: Projects loaded
    console.log('\n2️⃣  PROJECTS LOADED:');
    console.log('  Total projects:', Object.keys(window.projects || {}).length);
    if (Object.keys(window.projects || {}).length === 0) {
        issues.push('❌ NO PROJECTS LOADED - Check API_ENDPOINTS.LOAD_PROJECTS');
    } else {
        Object.keys(window.projects).slice(0, 5).forEach(name => {
            console.log(`    - "${name}" (category: ${window.projects[name].category || 'MISSING'})`);
        });
    }
    
    // Test 3: Form data structure
    console.log('\n3️⃣  FORM DATA STRUCTURE:');
    if (!window.formData) {
        issues.push('❌ formData is NULL - Should be initialized in initializeForm()');
    } else {
        console.log('  ✅ formData exists');
        console.log('    - operator:', !!window.formData.operator);
        console.log('    - referrerAgency:', !!window.formData.referrerAgency);
        console.log('    - referrerConsultants:', Array.isArray(window.formData.referrerConsultants) ? window.formData.referrerConsultants.length : 'NOT ARRAY');
        console.log('    - facilities:', Array.isArray(window.formData.facilities) ? window.formData.facilities.length : 'NOT ARRAY');
    }
    
    // Test 4: Consultant data
    console.log('\n4️⃣  CONSULTANT DATA (referrerConsultants):');
    if (!window.formData || !Array.isArray(window.formData.referrerConsultants)) {
        issues.push('❌ referrerConsultants is not an array');
    } else if (window.formData.referrerConsultants.length === 0) {
        console.log('  ⚠️  No consultants loaded');
    } else {
        window.formData.referrerConsultants.forEach((c, i) => {
            const keys = Object.keys(c);
            console.log(`  Consultant ${i}:`);
            console.log(`    - name: "${c.firstName} ${c.lastName}" (fullName: "${c.fullName}")`);
            console.log(`    - location: ${c.city}, ${c.state}`);
            console.log(`    - keys: ${keys.join(', ')}`);
            
            // Check for expected keys
            const expectedKeys = ['firstName', 'lastName', 'fullName', 'email', 'phone', 'city', 'state'];
            const missingKeys = expectedKeys.filter(k => !keys.includes(k));
            if (missingKeys.length > 0) {
                issues.push(`⚠️  Consultant ${i} missing keys: ${missingKeys.join(', ')}`);
            }
        });
    }
    
    // Test 5: Form field mapping
    console.log('\n5️⃣  FORM FIELD MAPPING:');
    const testFieldIds = [
        'consultant-firstname',
        'consultant-lastname',
        'consultant-city',
        'consultant-state',
        'consultant-email',
        'consultant-phone',
        'consultant-credentials'
    ];
    
    testFieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) {
            issues.push(`❌ Field not found in DOM: #${id}`);
        } else {
            console.log(`  ✅ #${id} exists (value: "${el.value}")`);
        }
    });
    
    // Test 6: Autocomplete initialization
    console.log('\n6️⃣  AUTOCOMPLETE INITIALIZATION:');
    const autocompleteFields = document.querySelectorAll('[data-autocomplete-category]');
    console.log(`  Total autocomplete fields: ${autocompleteFields.length}`);
    autocompleteFields.forEach(field => {
        const cat = field.dataset.autocompleteCategory;
        const initialized = field.dataset.autocompleteInit === 'true';
        console.log(`    - #${field.id || field.name} (category: ${cat}, initialized: ${initialized})`);
    });
    
    // Test 7: Category tabs
    console.log('\n7️⃣  CATEGORY TABS:');
    const tabs = document.querySelectorAll('.category-tab');
    console.log(`  Total tabs: ${tabs.length}`);
    tabs.forEach(tab => {
        const active = tab.classList.contains('active') ? '✅' : '  ';
        console.log(`  ${active} [${tab.dataset.category}]`);
    });
    
    // Test 8: Current state
    console.log('\n8️⃣  CURRENT STATE:');
    console.log('  currentProjectName:', window.currentProjectName || 'NONE');
    console.log('  currentConsultantIndex:', window.currentConsultantIndex || 0);
    console.log('  currentFacilityIndex:', window.currentFacilityIndex || 0);
    console.log('  formReady:', window.formReady || false);
    
    // Summary
    console.log('\n' + '='.repeat(45));
    if (issues.length === 0) {
        console.log('✅ ALL TESTS PASSED - Form should load correctly');
    } else {
        console.log(`❌ FOUND ${issues.length} ISSUE(S):`);
        issues.forEach((issue, i) => {
            console.log(`  ${i + 1}. ${issue}`);
        });
    }
    console.log('='.repeat(45));
    
    return { passed: issues.length === 0, issues };
};

/**
 * Test specific consultant loading
 */
window.testConsultantLoad = function(projectName, consultantIndex = 0) {
    console.log(`\n🧪 Testing consultant load for "${projectName}" [index: ${consultantIndex}]`);
    
    if (!window.projects[projectName]) {
        console.error(`❌ Project not found: ${projectName}`);
        return false;
    }
    
    const project = window.projects[projectName];
    console.log('  Project category:', project.category);
    console.log('  Has referrerConsultants:', Array.isArray(project.data?.referrerConsultants));
    
    if (!Array.isArray(project.data?.referrerConsultants)) {
        console.error('❌ Project has no referrerConsultants array');
        return false;
    }
    
    const consultant = project.data.referrerConsultants[consultantIndex];
    if (!consultant) {
        console.error(`❌ Consultant not found at index ${consultantIndex}`);
        return false;
    }
    
    console.log('✅ Consultant found:');
    console.log('  Keys:', Object.keys(consultant));
    console.log('  firstName:', consultant.firstName);
    console.log('  lastName:', consultant.lastName);
    console.log('  fullName:', consultant.fullName);
    console.log('  email:', consultant.email);
    console.log('  city:', consultant.city);
    console.log('  state:', consultant.state);
    
    // Now load it
    window.currentProjectName = projectName;
    window.currentConsultantIndex = consultantIndex;
    window.formData = window.projects[projectName].data;
    
    if (typeof loadConsultantData === 'function') {
        loadConsultantData();
        console.log('✅ loadConsultantData() called');
    }
    
    if (typeof updateConsultantsUI === 'function') {
        updateConsultantsUI();
        console.log('✅ updateConsultantsUI() called');
    }
    
    return true;
};

/**
 * List all projects with their types
 */
window.listAllProjects = function() {
    console.log('\n📋 ALL PROJECTS:');
    const categories = {};
    
    Object.keys(window.projects || {}).forEach(name => {
        const cat = window.projects[name].category || 'UNKNOWN';
        if (!categories[cat]) categories[cat] = [];
        categories[cat].push(name);
    });
    
    Object.keys(categories).forEach(cat => {
        console.log(`\n${cat.toUpperCase()} (${categories[cat].length}):`);
        categories[cat].forEach(name => {
            console.log(`  - ${name}`);
        });
    });
};
