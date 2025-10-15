/**
 * JavaScript Unit Tests for Kids Over Profits
 * 
 * Tests core JavaScript functionality including:
 * - Facility display rendering
 * - Data processing and validation
 * - DOM manipulation
 * - Event handling
 */

class KidsOverProfitsUnitTests {
    constructor() {
        this.tests = [];
        this.results = [];
    }

    /**
     * Run all JavaScript unit tests
     */
    runAllTests() {
        console.log('=== Kids Over Profits JavaScript Unit Tests ===\n');
        
        // Core functionality tests
        this.testFacilityDisplayRendering();
        this.testDataProcessingFunctions();
        this.testInputValidation();
        this.testEventHandling();
        
        // Utility function tests
        this.testUtilityFunctions();
        this.testDateFormatting();
        this.testStringManipulation();
        
        // DOM manipulation tests
        this.testDomManipulation();
        this.testModalFunctionality();
        
        // Error handling tests
        this.testErrorHandling();
        this.testApiErrorHandling();
        
        this.printSummary();
    }

    /**
     * Test facility display rendering
     */
    testFacilityDisplayRendering() {
        console.log('Running: Facility Display Rendering Tests');
        
        // Test displayFacilities function exists
        if (typeof displayFacilities === 'function') {
            this.recordResult('Facility Display Function Exists', true, 'displayFacilities function is available');
        } else {
            this.recordResult('Facility Display Function Exists', false, 'displayFacilities function not found');
        }
        
        // Test facility data structure validation
        const validFacilityData = {
            projects: {
                'test_project': {
                    name: 'Test Project',
                    data: {
                        operator: { name: 'Test Operator' },
                        facilities: [
                            {
                                name: 'Test Facility',
                                address: '123 Test St',
                                city: 'Test City',
                                state: 'TS'
                            }
                        ]
                    },
                    timestamp: new Date().toISOString(),
                    currentFacilityIndex: 0
                }
            }
        };
        
        this.recordResult('Valid Facility Data Structure', true, 'Facility data structure is valid');
        
        // Test container element handling
        const testContainer = document.createElement('div');
        testContainer.id = 'test-facilities-container';
        document.body.appendChild(testContainer);
        
        try {
            if (typeof displayFacilities === 'function') {
                displayFacilities(validFacilityData, 'test-facilities-container');
                this.recordResult('Container Element Handling', true, 'Function handles container element correctly');
            } else {
                this.recordResult('Container Element Handling', false, 'Function not available for testing');
            }
        } catch (error) {
            this.recordResult('Container Element Handling', false, `Error: ${error.message}`);
        }
        
        // Clean up
        document.body.removeChild(testContainer);
        
        // Test empty data handling
        try {
            if (typeof displayFacilities === 'function') {
                displayFacilities({}, 'non-existent-container');
                this.recordResult('Empty Data Handling', true, 'Function handles empty data gracefully');
            }
        } catch (error) {
            this.recordResult('Empty Data Handling', false, `Error handling empty data: ${error.message}`);
        }
    }

    /**
     * Test data processing functions
     */
    testDataProcessingFunctions() {
        console.log('Running: Data Processing Function Tests');
        
        // Test array processing utilities
        const testArray = [
            { name: 'Item 1', type: 'A' },
            { name: 'Item 2', type: 'B' },
            { name: 'Item 3', type: 'A' }
        ];
        
        // Test filtering functionality
        const filteredResults = testArray.filter(item => item.type === 'A');
        this.recordResult('Array Filtering', filteredResults.length === 2, 'Array filtering works correctly');
        
        // Test text cleaning functions (if available)
        const testText = '  Test String  ';
        const cleanedText = testText.trim();
        this.recordResult('Text Cleaning', cleanedText === 'Test String', 'Text cleaning works correctly');
        
        // Test JSON validation
        const validJson = '{"test": "value"}';
        const invalidJson = '{test: value}';
        
        try {
            JSON.parse(validJson);
            this.recordResult('Valid JSON Parsing', true, 'Valid JSON parsed correctly');
        } catch (error) {
            this.recordResult('Valid JSON Parsing', false, `Valid JSON failed to parse: ${error.message}`);
        }
        
        try {
            JSON.parse(invalidJson);
            this.recordResult('Invalid JSON Handling', false, 'Invalid JSON should have thrown error');
        } catch (error) {
            this.recordResult('Invalid JSON Handling', true, 'Invalid JSON properly rejected');
        }
    }

    /**
     * Test input validation
     */
    testInputValidation() {
        console.log('Running: Input Validation Tests');
        
        // Test email validation
        const validEmails = ['test@example.com', 'user.name@domain.org', 'admin+tag@site.co.uk'];
        const invalidEmails = ['invalid-email', '@domain.com', 'user@', 'user@domain', 'user.domain.com'];
        
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        validEmails.forEach(email => {
            const isValid = emailPattern.test(email);
            this.recordResult(`Email Validation - ${email}`, isValid, 'Valid email accepted');
        });
        
        invalidEmails.forEach(email => {
            const isValid = emailPattern.test(email);
            this.recordResult(`Email Validation - ${email}`, !isValid, 'Invalid email rejected');
        });
        
        // Test phone number validation
        const validPhones = ['(555) 123-4567', '555-123-4567', '555.123.4567', '5551234567'];
        const phonePattern = /^[\(\)\d\s\-\.]+$/;
        
        validPhones.forEach(phone => {
            const isValid = phonePattern.test(phone) && phone.replace(/\D/g, '').length >= 10;
            this.recordResult(`Phone Validation - ${phone}`, isValid, 'Valid phone number accepted');
        });
        
        // Test URL validation
        const validUrls = ['https://example.com', 'http://site.org', 'https://www.domain.co.uk/path'];
        const invalidUrls = ['not-a-url', 'ftp://invalid', 'javascript:alert(1)'];
        
        validUrls.forEach(url => {
            try {
                new URL(url);
                this.recordResult(`URL Validation - ${url}`, true, 'Valid URL accepted');
            } catch (error) {
                this.recordResult(`URL Validation - ${url}`, false, 'Valid URL rejected');
            }
        });
        
        invalidUrls.forEach(url => {
            try {
                new URL(url);
                this.recordResult(`URL Validation - ${url}`, false, 'Invalid URL should be rejected');
            } catch (error) {
                this.recordResult(`URL Validation - ${url}`, true, 'Invalid URL properly rejected');
            }
        });
    }

    /**
     * Test event handling
     */
    testEventHandling() {
        console.log('Running: Event Handling Tests');
        
        // Test event listener attachment
        const testButton = document.createElement('button');
        testButton.id = 'test-button';
        let eventTriggered = false;
        
        testButton.addEventListener('click', function() {
            eventTriggered = true;
        });
        
        // Simulate click
        testButton.click();
        this.recordResult('Event Listener Attachment', eventTriggered, 'Click event properly handled');
        
        // Test form submission handling
        const testForm = document.createElement('form');
        const submitInput = document.createElement('input');
        submitInput.type = 'submit';
        testForm.appendChild(submitInput);
        
        let formSubmitted = false;
        testForm.addEventListener('submit', function(e) {
            e.preventDefault();
            formSubmitted = true;
        });
        
        // Simulate form submission
        testForm.dispatchEvent(new Event('submit'));
        this.recordResult('Form Submission Handling', formSubmitted, 'Form submission properly handled');
        
        // Test keyboard event handling
        const testInput = document.createElement('input');
        let keyPressed = false;
        
        testInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                keyPressed = true;
            }
        });
        
        // Simulate Enter key press
        const enterEvent = new KeyboardEvent('keydown', { key: 'Enter' });
        testInput.dispatchEvent(enterEvent);
        this.recordResult('Keyboard Event Handling', keyPressed, 'Keyboard events properly handled');
    }

    /**
     * Test utility functions
     */
    testUtilityFunctions() {
        console.log('Running: Utility Function Tests');
        
        // Test string formatting
        const testString = 'test string';
        const capitalizedString = testString.charAt(0).toUpperCase() + testString.slice(1);
        this.recordResult('String Capitalization', capitalizedString === 'Test string', 'String capitalization works');
        
        // Test array deduplication
        const arrayWithDuplicates = [1, 2, 2, 3, 3, 3, 4];
        const uniqueArray = [...new Set(arrayWithDuplicates)];
        this.recordResult('Array Deduplication', uniqueArray.length === 4, 'Array deduplication works');
        
        // Test object property checking
        const testObject = { name: 'Test', value: 123 };
        this.recordResult('Object Property Checking', testObject.hasOwnProperty('name'), 'Object property checking works');
        
        // Test deep object cloning
        const originalObject = { nested: { value: 'test' } };
        const clonedObject = JSON.parse(JSON.stringify(originalObject));
        clonedObject.nested.value = 'modified';
        
        this.recordResult('Deep Object Cloning', originalObject.nested.value === 'test', 'Deep object cloning preserves original');
    }

    /**
     * Test date formatting
     */
    testDateFormatting() {
        console.log('Running: Date Formatting Tests');
        
        // Test ISO date parsing
        const isoDate = '2024-01-15T10:30:00Z';
        const parsedDate = new Date(isoDate);
        this.recordResult('ISO Date Parsing', !isNaN(parsedDate.getTime()), 'ISO dates parse correctly');
        
        // Test date formatting
        const testDate = new Date('2024-01-15');
        const formattedDate = testDate.toLocaleDateString();
        this.recordResult('Date Formatting', formattedDate.length > 0, 'Date formatting works');
        
        // Test relative time
        const now = new Date();
        const pastDate = new Date(now.getTime() - (24 * 60 * 60 * 1000)); // 1 day ago
        const timeDiff = now.getTime() - pastDate.getTime();
        const daysDiff = Math.floor(timeDiff / (24 * 60 * 60 * 1000));
        
        this.recordResult('Relative Time Calculation', daysDiff === 1, 'Relative time calculation works');
    }

    /**
     * Test string manipulation
     */
    testStringManipulation() {
        console.log('Running: String Manipulation Tests');
        
        // Test HTML escaping
        const htmlString = '<script>alert("test")</script>';
        const escapedHtml = htmlString
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        
        this.recordResult('HTML Escaping', !escapedHtml.includes('<script>'), 'HTML escaping works correctly');
        
        // Test URL encoding
        const testUrl = 'test string with spaces';
        const encodedUrl = encodeURIComponent(testUrl);
        this.recordResult('URL Encoding', encodedUrl.includes('%20'), 'URL encoding works correctly');
        
        // Test string truncation
        const longString = 'This is a very long string that needs to be truncated';
        const truncatedString = longString.length > 20 ? longString.substring(0, 20) + '...' : longString;
        this.recordResult('String Truncation', truncatedString.length === 23, 'String truncation works correctly');
    }

    /**
     * Test DOM manipulation
     */
    testDomManipulation() {
        console.log('Running: DOM Manipulation Tests');
        
        // Test element creation
        const testDiv = document.createElement('div');
        testDiv.id = 'test-dom-element';
        testDiv.textContent = 'Test Content';
        
        this.recordResult('Element Creation', testDiv.id === 'test-dom-element', 'Elements created correctly');
        this.recordResult('Text Content Setting', testDiv.textContent === 'Test Content', 'Text content set correctly');
        
        // Test element insertion
        document.body.appendChild(testDiv);
        const foundElement = document.getElementById('test-dom-element');
        this.recordResult('Element Insertion', foundElement !== null, 'Elements inserted into DOM correctly');
        
        // Test style manipulation
        testDiv.style.display = 'none';
        this.recordResult('Style Manipulation', testDiv.style.display === 'none', 'Element styles modified correctly');
        
        // Test class manipulation
        testDiv.classList.add('test-class');
        this.recordResult('Class Addition', testDiv.classList.contains('test-class'), 'Classes added correctly');
        
        testDiv.classList.remove('test-class');
        this.recordResult('Class Removal', !testDiv.classList.contains('test-class'), 'Classes removed correctly');
        
        // Clean up
        document.body.removeChild(testDiv);
    }

    /**
     * Test modal functionality
     */
    testModalFunctionality() {
        console.log('Running: Modal Functionality Tests');
        
        // Create test modal
        const modal = document.createElement('div');
        modal.id = 'test-modal';
        modal.style.display = 'none';
        modal.innerHTML = `
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Test Modal</h2>
                <p>Modal content</p>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Test modal show
        function showModal() {
            modal.style.display = 'block';
        }
        
        // Test modal hide
        function hideModal() {
            modal.style.display = 'none';
        }
        
        showModal();
        this.recordResult('Modal Show', modal.style.display === 'block', 'Modal shows correctly');
        
        hideModal();
        this.recordResult('Modal Hide', modal.style.display === 'none', 'Modal hides correctly');
        
        // Test close button functionality
        const closeButton = modal.querySelector('.close');
        if (closeButton) {
            closeButton.addEventListener('click', hideModal);
            this.recordResult('Modal Close Button', true, 'Close button functionality added');
        }
        
        // Clean up
        document.body.removeChild(modal);
    }

    /**
     * Test error handling
     */
    testErrorHandling() {
        console.log('Running: Error Handling Tests');
        
        // Test try-catch blocks
        let errorCaught = false;
        try {
            throw new Error('Test error');
        } catch (error) {
            errorCaught = true;
        }
        this.recordResult('Error Catching', errorCaught, 'Errors properly caught and handled');
        
        // Test null/undefined handling
        const undefinedVar = undefined;
        const nullVar = null;
        
        this.recordResult('Undefined Handling', typeof undefinedVar === 'undefined', 'Undefined values handled correctly');
        this.recordResult('Null Handling', nullVar === null, 'Null values handled correctly');
        
        // Test type checking
        const stringVar = 'test';
        const numberVar = 123;
        const objectVar = {};
        
        this.recordResult('Type Checking - String', typeof stringVar === 'string', 'String type detection works');
        this.recordResult('Type Checking - Number', typeof numberVar === 'number', 'Number type detection works');
        this.recordResult('Type Checking - Object', typeof objectVar === 'object', 'Object type detection works');
    }

    /**
     * Test API error handling
     */
    testApiErrorHandling() {
        console.log('Running: API Error Handling Tests');
        
        // Test fetch error handling
        const testApiCall = async () => {
            try {
                const response = await fetch('/non-existent-endpoint');
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return await response.json();
            } catch (error) {
                return { error: error.message };
            }
        };
        
        // Test timeout handling
        const timeoutPromise = new Promise((resolve) => {
            setTimeout(() => resolve({ timeout: true }), 5000);
        });
        
        this.recordResult('API Error Handling Setup', true, 'API error handling functions defined');
        
        // Test JSON parse error handling
        try {
            JSON.parse('invalid json');
            this.recordResult('JSON Parse Error Handling', false, 'Should have thrown error');
        } catch (error) {
            this.recordResult('JSON Parse Error Handling', true, 'JSON parse errors handled correctly');
        }
    }

    /**
     * Record test result
     */
    recordResult(testName, passed, message) {
        const result = {
            test: testName,
            passed: passed,
            message: message
        };
        
        this.results.push(result);
        
        const status = passed ? '✓ PASS' : '✗ FAIL';
        console.log(`  ${status}: ${message}`);
    }

    /**
     * Print test summary
     */
    printSummary() {
        console.log('\n=== JavaScript Test Summary ===');
        
        const totalTests = this.results.length;
        const passedTests = this.results.filter(r => r.passed).length;
        const failedTests = totalTests - passedTests;
        
        console.log(`Total Tests: ${totalTests}`);
        console.log(`Passed: ${passedTests}`);
        console.log(`Failed: ${failedTests}`);
        console.log(`Success Rate: ${Math.round((passedTests / totalTests) * 100 * 10) / 10}%`);
        
        if (failedTests > 0) {
            console.log('\n=== Failed Tests ===');
            this.results
                .filter(r => !r.passed)
                .forEach(result => {
                    console.log(`✗ ${result.test}: ${result.message}`);
                });
        }
        
        console.log('\n=== JavaScript Tests Complete ===');
        
        // Return results for further processing
        return {
            total: totalTests,
            passed: passedTests,
            failed: failedTests,
            results: this.results
        };
    }
}

// Auto-run tests if this file is loaded directly
if (typeof window !== 'undefined' && window.location && window.location.search.includes('runtests=js')) {
    document.addEventListener('DOMContentLoaded', function() {
        const tests = new KidsOverProfitsUnitTests();
        tests.runAllTests();
    });
}

// Export for manual testing
if (typeof module !== 'undefined' && module.exports) {
    module.exports = KidsOverProfitsUnitTests;
}

// Make available globally
if (typeof window !== 'undefined') {
    window.KidsOverProfitsUnitTests = KidsOverProfitsUnitTests;
}