const AUTOCOMPLETE_TEST_CONFIG = window.KOP_AUTOCOMPLETE_TEST_CONFIG || {};

const DEFAULT_AUTOCOMPLETE_ENDPOINT = 'https://kidsoverprofits.org/wp-content/themes/child/api/data_form/get-autocomplete.php';

const endpointCandidates = [];

function addEndpointCandidate(value) {
    if (typeof value !== 'string') {
        return;
    }

    const trimmed = value.trim();

    if (!trimmed) {
        return;
    }

    endpointCandidates.push(trimmed.replace(/\/$/, ''));
}

addEndpointCandidate(AUTOCOMPLETE_TEST_CONFIG.endpoint);

if (Array.isArray(AUTOCOMPLETE_TEST_CONFIG.fallbackEndpoints)) {
    AUTOCOMPLETE_TEST_CONFIG.fallbackEndpoints.forEach(addEndpointCandidate);
}

if (Array.isArray(AUTOCOMPLETE_TEST_CONFIG.themeBases)) {
    AUTOCOMPLETE_TEST_CONFIG.themeBases.forEach((base) => {
        if (typeof base !== 'string') {
            return;
        }

        const normalizedBase = base.trim().replace(/\/$/, '');

        if (!normalizedBase) {
            return;
        }

        addEndpointCandidate(`${normalizedBase}/api/data_form/get-autocomplete.php`);
    });
}

if (!endpointCandidates.length) {
    addEndpointCandidate(DEFAULT_AUTOCOMPLETE_ENDPOINT);
}

const ENDPOINT_CANDIDATES = Array.from(new Set(endpointCandidates));
const PRIMARY_ENDPOINT = ENDPOINT_CANDIDATES[0] || DEFAULT_AUTOCOMPLETE_ENDPOINT;

const endpointLabel = document.querySelector('[data-autocomplete-endpoint]');
if (endpointLabel) {
    if (ENDPOINT_CANDIDATES.length > 1) {
        const fallbackDisplay = ENDPOINT_CANDIDATES.slice(1).join(', ');
        endpointLabel.textContent = `${PRIMARY_ENDPOINT} (fallbacks: ${fallbackDisplay})`;
    } else {
        endpointLabel.textContent = PRIMARY_ENDPOINT;
    }
}
const testResults = {};

async function testCategory(category, defaultQuery) {
    const input = document.getElementById(`${category}-query`);
    const query = input ? input.value : defaultQuery;
    const resultsDiv = document.getElementById(`results-${category}`);
    const statusBadge = document.getElementById(`status-${category}`);

    if (!resultsDiv || !statusBadge) {
        return;
    }

    resultsDiv.style.display = 'block';
    resultsDiv.className = 'results';
    resultsDiv.innerHTML = `<p>🔄 Testing <strong>${category}</strong> with query: "${query}"...</p>`;

    statusBadge.className = 'status-badge pending';
    statusBadge.textContent = 'Testing...';

    let lastError = null;

    for (let index = 0; index < ENDPOINT_CANDIDATES.length; index += 1) {
        const endpoint = ENDPOINT_CANDIDATES[index];
        const separator = endpoint.includes('?') ? '&' : '?';
        const url = `${endpoint}${separator}category=${encodeURIComponent(category)}&q=${encodeURIComponent(query)}`;
        const startTime = performance.now();

        try {
            const response = await fetch(url, { cache: 'no-store' });
            const responseTime = Math.round(performance.now() - startTime);
            const contentType = response.headers.get('content-type');

            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error(`Non-JSON response: ${text.substring(0, 200)}`);
            }

            const result = await response.json();

            if (response.ok && result.success) {
                const count = Array.isArray(result.values) ? result.values.length : 0;
                resultsDiv.className = 'results success';
                resultsDiv.innerHTML = `
                    <p>✅ <strong>Success!</strong> Category: <code>${category}</code> | Endpoint: <code>${endpoint}</code> | Response time: ${responseTime}ms | Results: ${count}</p>
                    <pre>${JSON.stringify(result, null, 2)}</pre>
                `;
                statusBadge.className = 'status-badge pass';
                statusBadge.textContent = `✓ Pass (${count})`;
                testResults[category] = { success: true, count, responseTime, endpoint };
                lastError = null;
                break;
            }

            throw new Error(result.error || 'Unknown error');
        } catch (error) {
            const responseTime = Math.round(performance.now() - startTime);
            lastError = {
                message: error && error.message ? error.message : 'Unknown error',
                endpoint,
                responseTime,
            };

            if (index < ENDPOINT_CANDIDATES.length - 1) {
                continue;
            }
        }
    }

    if (lastError) {
        const attemptedList = ENDPOINT_CANDIDATES.map((value) => `<code>${value}</code>`).join(', ');
        resultsDiv.className = 'results error';
        resultsDiv.innerHTML = `
            <p>❌ <strong>Failed!</strong> Category: <code>${category}</code></p>
            <p>Error: ${lastError.message}</p>
            <p>Tried endpoints: ${attemptedList || 'None available'}</p>
        `;
        statusBadge.className = 'status-badge fail';
        statusBadge.textContent = '✗ Fail';
        testResults[category] = {
            success: false,
            error: lastError.message,
            endpoints: ENDPOINT_CANDIDATES.slice(),
        };
    }

    updateOverallStatus();
}

async function testAllCategories() {
    const categories = [
        'operator', 'facility', 'human', 'type', 'role',
        'status', 'gender', 'location', 'certification',
        'accreditation', 'membership', 'licensing', 'investor',
        'operatingperiod'
    ];

    const statusElement = document.getElementById('overall-status');
    if (statusElement) {
        statusElement.textContent = 'Running tests...';
    }

    for (const category of categories) {
        const defaultQuery = document.getElementById(`${category}-query`)?.value || '';
        await testCategory(category, defaultQuery);
        await new Promise((resolve) => setTimeout(resolve, 300));
    }

    updateOverallStatus();
}

function updateOverallStatus() {
    const statusElement = document.getElementById('overall-status');
    if (!statusElement) {
        return;
    }

    const totalTests = Object.keys(testResults).length;
    const passedTests = Object.values(testResults).filter((result) => result.success).length;
    const failedTests = totalTests - passedTests;

    if (totalTests === 0) {
        statusElement.textContent = 'Ready to test';
        return;
    }

    const percentage = Math.round((passedTests / totalTests) * 100);
    statusElement.innerHTML = `
        <strong>${passedTests}/${totalTests} passed</strong> (${percentage}%) |
        <span class="status-pass">${passedTests} passed</span> |
        <span class="status-fail">${failedTests} failed</span>
    `;
}

window.testCategory = testCategory;
window.testAllCategories = testAllCategories;
