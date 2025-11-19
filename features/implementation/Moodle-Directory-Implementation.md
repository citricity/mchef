# Moodle Directory Structure Implementation

This document outlines the comprehensive moodle directory functionality implemented in MChef to provide better project organization and git-friendly structure.

## Overview

The moodle directory feature creates a centralized location for all Moodle source code and plugins within your project, separating them from project configuration files and providing a cleaner, more manageable structure.

## Key Components

### 1. Moodle Service (`src/Service/Moodle.php`)

The core service that manages moodle directory operations:

```php
class Moodle extends AbstractService {
    // Creates moodle directory if it doesn't exist
    public function provideMoodleDirectory(Recipe $recipe, string $recipePath): string
    
    // Gets path without creating directory  
    public function getMoodleDirectoryPath(Recipe $recipe, string $recipePath): string
}
```

**Key Features:**
- ✅ **Directory Creation**: Automatically creates moodle directory with proper permissions (0755)
- ✅ **Configurable Names**: Supports custom directory names via `$recipe->moodleDirectory`
- ✅ **Default Behavior**: Uses 'moodle' as default directory name
- ✅ **Path Resolution**: Handles absolute path construction safely

### 2. Recipe Model Enhancement (`src/Model/Recipe.php`)

Added `moodleDirectory` field to Recipe model:

```php
/**
 * @var string - directory name for moodle source and plugins (default: 'moodle')
 */
public ?string $moodleDirectory = 'moodle',
```

**Configuration Options:**
- ✅ **Default**: `'moodle'` - Standard directory name
- ✅ **Custom**: Any valid directory name (e.g., `'custom-moodle'`, `'src'`)
- ✅ **JSON Support**: Configurable via recipe JSON files

### 3. Plugin Service Integration (`src/Service/Plugins.php`)

Enhanced plugin management to use moodle directory structure:

```php
// New method for plugin target path calculation
private function getPluginTargetPath(Recipe $recipe, string $recipePath, string $pluginName): string {
    $moodleDir = $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
    $pluginPath = $this->getMoodlePluginPath($pluginName, $recipe);
    return $moodleDir . $pluginPath;
}
```

**Plugin Structure Changes:**
- ✅ **Moodle Directory Integration**: Plugins now clone to `moodle/mod/plugin`, `moodle/blocks/plugin`, etc.
- ✅ **Volume Mounting**: Proper volume paths for Docker integration
- ✅ **Path Resolution**: Automatic path calculation within moodle directory
- ✅ **Git-Friendly**: Plugins are contained within moodle subdirectory structure

### 4. CopySrc Command Update (`src/Command/CopySrc.php`)

Modified to copy Moodle source to the configured moodle directory:

```php
// Uses Moodle service for target directory
$moodleTargetPath = $this->moodleService->provideMoodleDirectory($this->recipe, $instance->recipePath);

// Updated user prompts
"Copying the moodle src into your moodle directory will wipe everything except your plugin files. Continue?"
```

**Functionality:**
- ✅ **Target Directory**: Copies to configured moodle directory instead of project root
- ✅ **User Communication**: Clear prompts showing moodle directory name
- ✅ **Plugin Protection**: Preserves plugin files during copy operations
- ✅ **Safety Checks**: Validates successful copy by checking for `lib/weblib.php`

### 5. Project Service Safety (`src/Service/Project.php`)

**CRITICAL FIX**: Removed dangerous `./.*` pattern and added proper moodle directory protection:

```php
// DANGEROUS PATTERN REMOVED:
// $paths[] = './.*'; // This matched EVERYTHING!

// SAFE PATTERNS ADDED:
$paths[] = './.git';                // Protect Git repository
$paths[] = './.vscode';             // Protect VS Code settings
$paths[] = './.idea';               // Protect PhpStorm settings
$paths[] = './.env';                // Protect environment files
$paths[] = './.gitignore';          // Protect gitignore
$paths[] = './.DS_Store';           // Protect macOS files
```

**Safety Improvements:**
- ✅ **Fixed Catastrophic Bug**: Removed `./.*` pattern that could delete everything
- ✅ **Specific Protection**: Added explicit protection for common development files
- ✅ **Plugin Integration**: Proper handling of plugin paths within moodle directory

## Project Structure

### Before (Flat Structure)
```
project/
├── recipe.json
├── .mchef/
├── mod/              # Plugin directly in project root
│   └── customplugin/
├── blocks/           # Plugin directly in project root
│   └── customblock/
├── lib/              # Moodle core mixed with project
├── admin/            # Moodle core mixed with project
└── config.php        # Moodle core mixed with project
```

### After (Moodle Directory Structure)
```
project/
├── recipe.json       # Project configuration
├── .mchef/           # MChef metadata
├── .gitignore        # Git configuration
└── moodle/           # All Moodle-related code
    ├── mod/          # Plugins in proper structure
    │   └── customplugin/
    ├── blocks/       # Plugins in proper structure
    │   └── customblock/
    ├── lib/          # Moodle core code
    ├── admin/        # Moodle core code
    └── config.php    # Moodle core code
```

## Benefits

### 1. **Git-Friendly Structure**
- ✅ **Clean Separation**: Project files vs Moodle code
- ✅ **Selective Ignoring**: Easy to ignore Moodle core while keeping plugins
- ✅ **Repository Organization**: Clear boundaries between different code types

Example `.gitignore`:
```gitignore
# Ignore Moodle core but keep custom plugins
moodle/*
!moodle/mod/customplugin/
!moodle/blocks/customblock/
!moodle/local/customlocal/
```

### 2. **Improved Development Workflow**
- ✅ **Container Isolation**: Moodle code separated from project configuration
- ✅ **Plugin Management**: Plugins properly organized in Moodle structure
- ✅ **Source Control**: Better version control with clear boundaries

### 3. **Safety and Reliability**
- ✅ **Fixed Dangerous Patterns**: Removed catastrophic `./.*` wildcard
- ✅ **Protected Directories**: Explicit protection for important files/folders
- ✅ **Error Prevention**: Reduced risk of accidental deletions

## Usage Examples

### Basic Usage (Default 'moodle' directory)
```json
{
    "moodleTag": "4.1.0",
    "phpVersion": "8.0",
    "plugins": [
        {"repo": "https://github.com/user/mod_customplugin"}
    ]
}
```

Result: Creates `./moodle/` directory with plugin at `./moodle/mod/customplugin/`

### Custom Directory Name
```json
{
    "moodleTag": "4.1.0", 
    "phpVersion": "8.0",
    "moodleDirectory": "src",
    "plugins": [
        {"repo": "https://github.com/user/mod_customplugin"}
    ]
}
```

Result: Creates `./src/` directory with plugin at `./src/mod/customplugin/`

### Commands Integration
```bash
# Copy Moodle source to configured directory
mchef copysrc

# Example output:
# Selected instance is test
# Project directory is /path/to/project
# Moodle directory: moodle/
# 
# Copying the moodle src into your moodle directory will wipe everything except your plugin files. Continue? [y/N]
```

## Implementation Status

### ✅ **Completed Features**
1. **Moodle Service**: Complete directory management functionality
2. **Recipe Integration**: moodleDirectory field with default values  
3. **Plugin Integration**: Plugins clone to moodle subdirectories
4. **CopySrc Integration**: Copies to configured moodle directory
5. **Safety Fixes**: Removed dangerous patterns from Project service
6. **Path Resolution**: Proper absolute path handling across services

### ✅ **Testing Coverage**
1. **Unit Tests**: Moodle service functionality
2. **Integration Tests**: Complete workflow testing
3. **Safety Tests**: Verification of dangerous pattern removal
4. **Edge Cases**: Custom directory names, permissions, error handling

### ✅ **Backward Compatibility**
- **Default Behavior**: Maintains 'moodle' directory if not specified
- **Existing Recipes**: Work without modification
- **Plugin Structure**: Maintains proper Moodle plugin paths
- **Command Interface**: Same commands with enhanced functionality

## Migration Guide

### For Existing Projects
1. **Add moodleDirectory to recipe** (optional - defaults to 'moodle')
2. **Run `mchef up`** to recreate containers with new structure
3. **Run `mchef copysrc`** to copy Moodle source to new directory
4. **Update .gitignore** to use new structure

### For New Projects
- **No changes required** - new structure is default
- **Configure custom directory** via `moodleDirectory` field if desired

## Related Files

### Core Implementation
- `src/Service/Moodle.php` - Main service
- `src/Model/Recipe.php` - Configuration model
- `src/Service/Plugins.php` - Plugin integration
- `src/Command/CopySrc.php` - Source copying
- `src/Service/Project.php` - Safety fixes

### Tests
- `src/Tests/MoodleTest.php` - Unit tests
- `src/Tests/Integration/MoodleDirectoryIntegrationTest.php` - Integration tests

This implementation provides a robust, safe, and git-friendly structure for Moodle development projects while maintaining full backward compatibility and improving developer experience.
