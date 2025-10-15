# Kids Over Profits Testing Guide

This document explains the comprehensive testing framework implemented for the Kids Over Profits project.

## Overview

The testing framework includes:
- **PHP Unit Tests** - Backend API and functionality testing
- **JavaScript Unit Tests** - Frontend functionality testing
- **Python Unit Tests** - Data scraping and processing testing
- **Integration Tests** - End-to-end workflow testing
- **Existing Test Tools** - Visual regression, autocomplete, and report testing

## Test Structure

```
Kids-Over-Profits/
├── run-tests.php                    # Main test runner
├── TESTING.md                       # This documentation
├── Website/
│   ├── tests/
│   │   ├── ApiTest.php             # PHP API unit tests
│   │   └── IntegrationTest.php     # Integration tests
│   ├── js/
│   │   ├── unit-tests.js           # JavaScript unit tests
│   │   ├── test-autocomplete.js    # Autocomplete API testing
│   │   ├── report-test.js          # Report loading diagnostics
│   │   └── visual-test.js          # Visual regression testing
│   └── inc/
│       └── test-config.php         # Test configuration utilities
└── Scripts/
    └── test_scrapers.py            # Python scraper unit tests
```

## Running Tests

### All Tests
```bash
php run-tests.php
```

### Specific Test Suites
```bash
# PHP tests only
php run-tests.php php

# JavaScript tests only
php run-tests.php js

# Python tests only
php run-tests.php python

# Integration tests only
php run-tests.php integration
```

### Individual Test Files
```bash
# Run PHP API tests directly
php Website/tests/ApiTest.php

# Run Python scraper tests directly
python Scripts/test_scrapers.py

# Run integration tests directly
php Website/tests/IntegrationTest.php
```

## Test Categories

### 1. PHP Unit Tests (`Website/tests/ApiTest.php`)

Tests PHP backend functionality:

#### Database Tests
- ✅ Database connection establishment
- ✅ Database table structure validation
- ✅ Connection error handling

#### API Endpoint Tests
- ✅ `get-master-data.php` - Data retrieval
- ✅ `get-autocomplete.php` - Autocomplete functionality
- ✅ `save-suggestion.php` - Data submission
- ✅ `process-edit.php` - Data editing
- ✅ `save-master.php` - Master data saving

#### Security Tests
- ✅ Input sanitization
- ✅ SQL injection prevention
- ✅ XSS prevention
- ✅ Parameter validation

#### Error Handling Tests
- ✅ Invalid JSON handling
- ✅ Missing parameter handling
- ✅ Database error handling

### 2. JavaScript Unit Tests (`Website/js/unit-tests.js`)

Tests frontend JavaScript functionality:

#### Core Functionality
- ✅ Facility display rendering
- ✅ Data processing functions
- ✅ Event handling
- ✅ DOM manipulation

#### Input Validation
- ✅ Email validation
- ✅ Phone number validation
- ✅ URL validation
- ✅ Form validation

#### Utility Functions
- ✅ String manipulation
- ✅ Date formatting
- ✅ Array processing
- ✅ Object handling

#### UI Components
- ✅ Modal functionality
- ✅ Form interactions
- ✅ Dynamic content loading
- ✅ Error display

#### Running JavaScript Tests
1. **Browser Method**: Open any page with `?runtests=js` in URL
2. **Test Runner**: PHP test runner creates `test-runner.html`
3. **Manual**: Include `unit-tests.js` and call `new KidsOverProfitsUnitTests().runAllTests()`

### 3. Python Unit Tests (`Scripts/test_scrapers.py`)

Tests Python scraping and data processing:

#### Scraper Utilities
- ✅ Smart title case conversion
- ✅ Data structure validation
- ✅ JSON processing

#### Web Scraping
- ✅ HTTP request handling
- ✅ HTML parsing
- ✅ Data extraction patterns
- ✅ Error handling

#### Data Processing
- ✅ Rate limiting
- ✅ Data validation
- ✅ File operations
- ✅ Performance testing

#### Running Python Tests
```bash
# From project root
python Scripts/test_scrapers.py

# Or via test runner
php run-tests.php python
```

### 4. Integration Tests (`Website/tests/IntegrationTest.php`)

Tests end-to-end workflows:

#### Data Workflows
- ✅ Complete data submission workflow
- ✅ Data retrieval workflow
- ✅ Facility display workflow
- ✅ Autocomplete workflow

#### Error Scenarios
- ✅ API error handling
- ✅ Database error handling
- ✅ Validation error handling

#### Performance Tests
- ✅ Large dataset handling
- ✅ Concurrent request handling
- ✅ Memory usage optimization

### 5. Existing Test Tools

The project already includes several specialized testing tools:

#### Autocomplete Testing (`Website/js/test-autocomplete.js`)
- Tests all autocomplete categories
- Validates API responses
- Checks endpoint availability
- Tests fallback mechanisms

**Usage**: Visit `/test-autocomplete` page or include the script with appropriate configuration.

#### Report Testing (`Website/js/report-test.js`)
- Validates JSON data loading
- Tests script enqueuing
- Checks data structure
- Monitors loading performance

**Usage**: Automatically appears on report pages when test configuration is enabled.

#### Visual Testing (`Website/js/visual-test.js`)
- Grid overlay for alignment checking
- Element measurement tools
- Display issue detection
- Interactive debugging

**Usage**: Add `?debug=visual` to any page URL.

#### CSS Testing (`Website/inc/test-config.php`)
- Validates CSS rule application
- Tests responsive design
- Checks cross-browser compatibility
- Monitors style loading

## Test Data

### Sample Test Data Structure

```json
{
  "projects": {
    "test_project": {
      "name": "Test Project",
      "data": {
        "operator": {
          "name": "Test Operator",
          "website": "https://example.com",
          "headquarters": {
            "address": "123 Test St",
            "city": "Test City",
            "state": "TS"
          }
        },
        "facilities": [
          {
            "name": "Test Facility",
            "address": "456 Facility Rd",
            "programType": "Residential",
            "capacity": 50
          }
        ]
      },
      "timestamp": "2024-01-15T10:30:00Z",
      "currentFacilityIndex": 0
    }
  }
}
```

## Test Environment Setup

### Prerequisites
- **PHP 7.4+** - For backend tests
- **Python 3.x** - For scraper tests
- **Modern Browser** - For JavaScript tests
- **MySQL/MariaDB** - For database tests (optional)

### Environment Variables
```bash
# Test database configuration
export DB_HOST="localhost"
export DB_NAME="kidsover_test"
export DB_USER="test_user"
export DB_PASS="test_pass"
```

### Test Database Setup (Optional)
```sql
-- Create test database
CREATE DATABASE kidsover_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Create test user
CREATE USER 'test_user'@'localhost' IDENTIFIED BY 'test_pass';
GRANT ALL PRIVILEGES ON kidsover_test.* TO 'test_user'@'localhost';

-- Create test tables
CREATE TABLE facilities_master (
    unique_name VARCHAR(255) PRIMARY KEY,
    json_data LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Continuous Integration

### Automated Testing
The test runner can be integrated into CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
name: Run Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
      - name: Setup Python
        uses: actions/setup-python@v2
        with:
          python-version: '3.x'
      - name: Install Python dependencies
        run: pip install requests beautifulsoup4
      - name: Run all tests
        run: php run-tests.php
```

### Pre-commit Hooks
```bash
#!/bin/sh
# .git/hooks/pre-commit
echo "Running tests before commit..."
php run-tests.php
if [ $? -ne 0 ]; then
    echo "Tests failed! Commit aborted."
    exit 1
fi
```

## Test Coverage

### Current Coverage Areas

#### ✅ Covered
- API endpoint functionality
- Database operations
- Data validation
- Error handling
- Frontend JavaScript utilities
- Scraper functionality
- Integration workflows

#### 🚧 Partial Coverage
- WordPress integration functions
- Anonymous portal functionality
- Report generation
- File upload handling

#### ❌ Not Covered Yet
- WordPress admin interface
- Theme template functions
- Shortcode functionality
- Email notifications

## Writing New Tests

### PHP Test Example
```php
public function testNewFunctionality()
{
    $test_name = "New Functionality Test";
    
    // Setup
    $test_data = ['key' => 'value'];
    
    // Execute
    $result = your_function($test_data);
    
    // Assert
    if ($result === expected_value) {
        $this->recordResult($test_name, true, "Function works correctly");
    } else {
        $this->recordResult($test_name, false, "Function failed");
    }
}
```

### JavaScript Test Example
```javascript
testNewJavaScriptFunction() {
    console.log('Running: New JavaScript Function Test');
    
    // Setup
    const testInput = 'test data';
    
    // Execute
    const result = yourFunction(testInput);
    
    // Assert
    const expected = 'expected result';
    this.recordResult('New Function Test', result === expected, 'Function works correctly');
}
```

### Python Test Example
```python
def test_new_python_function(self):
    """Test new Python functionality"""
    # Setup
    test_data = {'key': 'value'}
    
    # Execute
    result = your_function(test_data)
    
    # Assert
    self.assertEqual(result, expected_value)
```

## Troubleshooting

### Common Issues

#### PHP Tests Fail
- Check PHP version (7.4+ required)
- Verify file permissions
- Check for syntax errors
- Ensure all dependencies are available

#### JavaScript Tests Don't Run
- Check browser console for errors
- Verify script loading
- Check for JavaScript syntax errors
- Ensure DOM elements exist

#### Python Tests Fail
- Check Python version (3.x required)
- Install required packages: `pip install requests beautifulsoup4`
- Verify file paths
- Check import statements

#### Database Connection Issues
- Verify database credentials
- Check database server is running
- Ensure test database exists
- Check user permissions

### Debug Mode
Enable verbose output in test runner:
```php
$this->config['verbose'] = true;
```

### Test Isolation
Each test should be independent:
- Clean up after tests
- Use unique test data
- Reset global state
- Mock external dependencies

## Best Practices

### Test Writing
1. **One assertion per test** - Keep tests focused
2. **Descriptive names** - Clear test and function names
3. **Independent tests** - No dependencies between tests
4. **Clean up** - Remove test data and files
5. **Mock external services** - Don't rely on external APIs

### Test Maintenance
1. **Update regularly** - Keep tests current with code changes
2. **Remove obsolete tests** - Clean up unused tests
3. **Refactor test code** - Keep test code clean
4. **Document test purpose** - Clear comments and documentation

### Performance
1. **Fast tests** - Keep individual tests under 1 second
2. **Parallel execution** - Run independent tests in parallel
3. **Skip slow tests** - Mark integration tests appropriately
4. **Optimize test data** - Use minimal required data

## Contributing Tests

When contributing new functionality:

1. **Write tests first** - Test-driven development
2. **Test edge cases** - Handle error conditions
3. **Test performance** - Ensure efficiency
4. **Update documentation** - Keep this guide current
5. **Run all tests** - Ensure no regressions

## Support

For testing-related questions:
- Check existing test implementations
- Review this documentation
- Contact: dani@kidsoverprofits.org

---

**For the kids. Always for the kids.**