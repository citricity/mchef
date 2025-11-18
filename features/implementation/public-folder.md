# Public Folder Implementation

## Overview

Implemented support for Moodle 5.1+ public folder structure in the MChef project. This feature automatically detects if a Moodle version uses the public folder structure and adjusts plugin installation paths accordingly.

## Components Modified

### 1. Git Service (`src/Service/Git.php`)

Added two new methods:

- `folderExistsInRemote(string $repositoryUrl, string $branchOrTag, string $folderPath): bool`
  - Generic method to check if a folder exists in a remote repository at a specific branch/tag
  - Uses `git archive` command to avoid cloning entire repositories
  
- `moodleHasPublicFolder(string $moodleTag): bool`
  - Specific method to check if Moodle version has public folder structure
  - Targets the official Moodle repository: `https://github.com/moodle/moodle.git`
  - Returns false gracefully if remote check fails (backwards compatible)

### 2. Plugins Service (`src/Service/Plugins.php`)

Modified plugin path resolution logic:

- `shouldUsePublicFolder(Recipe $recipe): bool`
  - Helper method that caches public folder detection results per Moodle tag
  - Provides user feedback when public folder structure is detected
  
- Updated `getMoodlePluginPath($pluginName, ?Recipe $recipe = null): string`
  - Added optional Recipe parameter for public folder detection
  - Prefixes paths with `/public` when Moodle version uses public folder structure
  - Maintains backward compatibility when no recipe provided

- Updated all callers of `getMoodlePluginPath` to pass the recipe parameter

### 3. Testing

- **Unit Tests**: Updated `PluginsServiceTest.php` to handle new method signature
- **Integration Tests**: Created `PublicFolderIntegrationTest.php` with tests for:
  - Public folder detection against real Moodle repository
  - Plugin path generation for different Moodle versions
  - Placeholder for full workflow testing (marked incomplete until Moodle 5.1 release)

## Implementation Details

### Path Resolution Logic

**Before (all Moodle versions):**
```
filter_imageopt → /filter/imageopt
local_customcode → /local/customcode
theme_boost → /theme/boost
```

**After (Moodle 5.1+ with public folder):**
```
filter_imageopt → /public/filter/imageopt
local_customcode → /public/local/customcode
theme_boost → /public/theme/boost
```

### Caching Strategy

Public folder detection results are cached in a static array within the `shouldUsePublicFolder` method to avoid repeated remote Git calls during recipe processing.

### Error Handling

- Remote Git failures return `false` (no public folder) to maintain backwards compatibility
- Warning messages are displayed when remote checks fail
- All existing functionality continues to work if public folder detection is unavailable

## Testing

### Unit Tests
```bash
php vendor/bin/phpunit src/Tests/PluginsServiceTest.php
```

### Integration Tests  
```bash
php vendor/bin/phpunit src/Tests/Integration/PublicFolderIntegrationTest.php
```

## Usage Example

The feature works automatically when processing recipes:

```json
{
  "moodleTag": "v5.1.0",
  "phpVersion": "8.0",
  "plugins": [
    {
      "repo": "https://github.com/gthomas2/moodle-filter_imageopt",
      "branch": "master"
    }
  ]
}
```

For Moodle 5.1+, the plugin will be installed to:
- **Container path**: `/public/filter/imageopt/`
- **Host mount**: `./moodle/public/filter/imageopt/`

For Moodle 5.0 and earlier, the plugin installs to:
- **Container path**: `/filter/imageopt/`
- **Host mount**: `./moodle/filter/imageopt/`

## Backwards Compatibility

- All existing recipes continue to work unchanged
- Plugin installation for Moodle 4.x and 5.0 remains identical
- No breaking changes to any APIs
- New functionality only activates for Moodle versions that actually have public folders

## Future Considerations

- When Moodle 5.1 is officially released, update integration tests to verify against real public folder structure
- Consider adding configuration option to override public folder detection if needed
- Monitor Moodle development for any changes to the public folder implementation