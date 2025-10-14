const API_ENDPOINT = 'https://kidsoverprofits.org/wp-content/themes/child/api/get-suggestions.php';
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

    try {
        const url = `${API_ENDPOINT}?category=${encodeURIComponent(category)}&q=${encodeURIComponent(query)}`;
        const startTime = performance.now();
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
                <p>✅ <strong>Success!</strong> Category: <code>${category}</code> | Response time: ${responseTime}ms | Results: ${count}</p>
                <pre>${JSON.stringify(result, null, 2)}</pre>
            `;
            statusBadge.className = 'status-badge pass';
            statusBadge.textContent = `✓ Pass (${count})`;
            testResults[category] = { success: true, count, responseTime };
        } else {
            throw new Error(result.error || 'Unknown error');
        }
    } catch (error) {
        resultsDiv.className = 'results error';
        resultsDiv.innerHTML = `
            <p>❌ <strong>Failed!</strong> Category: <code>${category}</code></p>
            <p>Error: ${error.message}</p>
        `;
        statusBadge.className = 'status-badge fail';
        statusBadge.textContent = '✗ Fail';
        testResults[category] = { success: false, error: error.message };
    }

    updateOverallStatus();
}

async function testAllCategories() {
    const categories = [
        'operator', 'facility', 'human', 'type', 'role',
        'status', 'gender', 'location', 'certification',
        'accreditation', 'membership', 'licensing', 'investor'
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
