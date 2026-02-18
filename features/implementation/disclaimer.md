# Disclaimer / License Agree Feature Implementation

## Status - implemented

## Overview

The disclaimer feature has been successfully implemented to ensure that users must agree to terms and conditions before using MChef for the first time. This implementation follows the requirements specified in the [disclaimer requirements](../requirements/disclaimer.md).

## Implementation Details

### 1. TermsService Class

Created `src/Service/TermsService.php` - a service class responsible for:

- Checking if user has previously agreed to terms
- Displaying the disclaimer text
- Prompting user for agreement
- Saving agreement to file
- Handling test scenarios

Key methods:

- `ensureTermsAgreement()`: Main method to check/prompt for terms agreement
- `hasAgreedToTerms()`: Checks if TERMSAGREED.txt exists
- `promptForTermsAgreement()`: Displays disclaimer and prompts user
- `createTermsAgreementForTesting()`: Helper method for tests

### 2. CLI Integration

Modified `src/MChefCLI.php` in the `main()` method to:

- Check terms agreement before any command execution
- Exit with code 1 if user declines terms
- Allow normal operation if terms are already agreed

```php
protected function main(Options $options) {
    // Check terms agreement before any operation
    $termsService = \App\Service\TermsService::instance();
    if (!$termsService->ensureTermsAgreement($options)) {
        throw new \App\Exceptions\TermsNotAgreedException();
    }
    // ... rest of main method
}
```

### 3. Test Framework Integration

Modified `src/Tests/MchefTestCase.php` to:

- Automatically create terms agreement for all tests
- Prevent terms prompts from interfering with test execution

```php
protected function setUp(): void {
    // ... existing setup code

    // Create terms agreement for tests by default
    $termsService = \App\Service\TermsService::instance();
    $termsService->createTermsAgreementForTesting();
}
```

### 4. Terms Agreement File

The terms agreement is stored in `TERMSAGREED.txt` within the user's config directory:

- Location: `~/.config/mchef/TERMSAGREED.txt` (or equivalent test directory during testing)
- Content: Timestamp and username of agreement
- Format:
  ```
  Terms agreed on: 2026-02-18 14:30:00
  User: username
  ```

### 5. Disclaimer Text

The disclaimer includes all required elements:

- "As is" warranty disclaimer
- Risk acknowledgment
- Liability limitations
- Backup responsibility notice
- Development/testing purpose statement
- Compatibility disclaimers
- Reference to LICENSE file

## Testing

### Test Coverage

1. **DisclaimerTest.php**: Tests core disclaimer functionality
   - Initial state verification (terms not agreed)
   - File creation when terms agreed
   - User prompt behavior (yes/no responses)
   - Terms agreement flow

2. **TermsIntegrationTest.php**: Tests integration with command execution
   - Verification that commands require terms agreement
   - Verification that commands work after agreement
   - Terms file content validation

### Test Features

- **Singleton Reset**: Tests properly reset TermsService singleton between runs
- **Force Terms Check**: Tests can force terms checking even in test environment
- **Mock CLI**: Tests use mocked CLI to verify user interaction flows
- **Clean State**: Each test starts with clean terms state

## Behavior

### First Run

1. User runs any MChef command
2. Disclaimer text is displayed
3. User is prompted: "Do you agree to these terms? [y/N]"
4. If "Y": Terms file is created, command continues
5. If "N": Error message shown, application exits with code 1

### Subsequent Runs

1. User runs any MChef command
2. System checks for existing TERMSAGREED.txt file
3. If file exists: Command continues normally
4. If file missing: Disclaimer flow repeats

### Test Environment

- Tests automatically create terms agreement to avoid prompts
- Special tests can force terms checking for validation
- Terms checking can be bypassed for non-disclaimer-related tests

## File Structure

```
src/
├── Service/
│   └── TermsService.php          # Main disclaimer service
├── Tests/
│   ├── MchefTestCase.php         # Modified to handle terms
│   ├── DisclaimerTest.php        # Core disclaimer tests
│   └── TermsIntegrationTest.php  # Integration tests
└── MChefCLI.php                  # Modified to check terms
```

## Configuration

The disclaimer feature uses the same configuration directory as other MChef settings:

- Production: `~/.config/mchef/`
- Testing: System temp directory + `/mchef_test_config`

No additional configuration is required - the feature works automatically on first use.
