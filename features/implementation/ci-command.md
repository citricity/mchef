# CI Command Implementation

## Status - Completed

## Overview

The CI command has been successfully implemented as specified in the requirements. This command builds production-ready Docker images from MChef recipes and optionally publishes them to Docker registries.

## Implementation Details

### 1. Recipe Model Enhancement

- **File**: `src/Model/Recipe.php`
- **Added**: `publishTagPrefix` property for customizing Docker image tag prefixes
- **Purpose**: Allows recipes to specify custom prefixes for published images (e.g., "my-app" → "my-app:v1.5.0")

### 2. CI Command

- **File**: `src/Command/CI.php`
- **Command**: `mchef ci <recipe-file> --publish=<tag>`
- **Features**:
  - Validates recipe file existence
  - Requires publish tag parameter
  - Overrides development settings for production builds
  - Graceful error handling with `CliRuntimeException`

### 3. Production Build Overrides

The CI command automatically overrides the following recipe fields for production builds:

- `cloneRepoPlugins: false`
- `mountPlugins: false`
- `developer: false`
- `includePhpUnit: false`
- `includeBehat: false`
- `includeXdebug: false`

### 4. Docker Service Enhancements

- **File**: `src/Service/Docker.php`
- **Added Methods**:
  - `loginToRegistry()` - Authenticate with Docker registries
  - `tagImage()` - Tag images with custom names
  - `pushImage()` - Push images to registries
  - `buildImageWithCompose()` - Build images using docker-compose

### 5. Main Service Enhancements

- **File**: `src/Service/Main.php`
- **Added Methods**:
  - `buildDockerCiImage()` - Build production images with custom names
  - `prepareDockerDataForCI()` - Prepare Docker configuration for CI builds

### 6. Environment Variable Support

The CI command supports the following environment variables for registry publishing:

- `MCHEF_REGISTRY_URL` - Registry URL (e.g., "https://ghcr.io")
- `MCHEF_REGISTRY_USERNAME` - Registry username
- `MCHEF_REGISTRY_PASSWORD` - Password-based authentication
- `MCHEF_REGISTRY_TOKEN` - Token-based authentication (e.g., GitHub)

### 7. Error Handling & User Experience

- **Validation**: Comprehensive input validation with helpful error messages
- **Graceful Degradation**: Builds locally if registry credentials are missing
- **Clear Messaging**: Informative output about build steps and configuration

## Usage Examples

### Basic Build (No Publishing)

```bash
# Missing registry credentials - builds locally only
mchef ci recipe.json --publish=v1.5.0
```

### Build and Publish to Docker Hub

```bash
export MCHEF_REGISTRY_URL="https://docker.io"
export MCHEF_REGISTRY_USERNAME="myusername"
export MCHEF_REGISTRY_PASSWORD="mypassword"

mchef ci recipe.json --publish=v1.5.0
```

### Build and Publish to GitHub Container Registry

```bash
export MCHEF_REGISTRY_URL="https://ghcr.io"
export MCHEF_REGISTRY_USERNAME="github-username"
export MCHEF_REGISTRY_TOKEN="ghp_xxxxxxxxxxxx"

mchef ci recipe.json --publish=v1.5.0
```

## Image Naming Strategy

1. **With publishTagPrefix**: Uses `recipe.publishTagPrefix:tag`
   - Recipe: `{"publishTagPrefix": "my-moodle-app"}`
   - Result: `my-moodle-app:v1.5.0`

2. **With recipe name**: Uses sanitized `recipe.name:tag`
   - Recipe: `{"name": "My Moodle App!"}`
   - Result: `my-moodle-app:v1.5.0`

3. **Fallback**: Uses `mchef-app:tag`

## Security Features

- **Input Validation**: Recipe file and tag validation
- **Shell Escaping**: All Docker commands use `escapeshellarg()`
- **Safe Credential Handling**: Environment variables for registry credentials

## Testing

### Test Coverage

- **File**: `src/Tests/CICommandTest.php`
- **Tests**: 11 tests covering core functionality
- **Status**: 8 passing, 3 skipped (environment variable mocking would require additional setup)

### Test Categories

1. **Error Handling**: Missing arguments, invalid files, missing tags
2. **Core Logic**: Image naming, recipe overrides, sanitization
3. **Build Process**: Recipe loading, Docker image building
4. **Registry Integration**: Environment variable parsing (skipped - requires mocking infrastructure)

## Future Enhancements

### Potential Improvements

1. **Environment Variable Injection**: Refactor to use dependency injection for easier testing
2. **Advanced Registry Support**: Support for additional registry types
3. **Build Caching**: Docker layer caching for faster CI builds
4. **Multi-platform Builds**: Support for building ARM64/AMD64 images
5. **Build Artifacts**: Save build logs and metadata

### CI/CD Integration Examples

#### GitHub Actions

```yaml
- name: Build and Publish Moodle Image
  env:
    MCHEF_REGISTRY_URL: ghcr.io
    MCHEF_REGISTRY_USERNAME: ${{ github.actor }}
    MCHEF_REGISTRY_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  run: mchef ci recipe.json --publish=${{ github.ref_name }}
```

#### GitLab CI

```yaml
build:
  variables:
    MCHEF_REGISTRY_URL: $CI_REGISTRY
    MCHEF_REGISTRY_USERNAME: $CI_REGISTRY_USER
    MCHEF_REGISTRY_PASSWORD: $CI_REGISTRY_PASSWORD
  script:
    - mchef ci recipe.json --publish=$CI_COMMIT_TAG
```

## Architecture Benefits

1. **Consistency**: Uses existing MChef architecture and patterns
2. **Extensibility**: Easy to add new registry types or build options
3. **Maintainability**: Clear separation of concerns between services
4. **Testability**: Comprehensive test coverage for core functionality
5. **Security**: Proper input validation and credential handling

The CI command successfully addresses the need for automated, production-ready Docker image builds while maintaining consistency with the existing MChef codebase architecture.
