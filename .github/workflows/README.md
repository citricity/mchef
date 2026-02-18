# GitHub Actions Workflows for MChef

This directory contains automated testing workflows for MChef, ensuring code quality and functionality across different environments.

## Workflow

### `test-mchef.yml` - Comprehensive Testing Pipeline

**Purpose**: Complete testing pipeline with both unit tests and integration testing, optimized for efficiency with sequential job execution.

**Triggers**:

- Pull requests to `main`, `master`, or `develop` branches
- Manual trigger via `workflow_dispatch`
- Ignores documentation changes (`README.md`, `LICENSE`, `docs/**`)

**Jobs**:

#### 1. Unit Tests (10 minutes)

- PHPUnit test suite execution
- Basic MChef CLI functionality validation
- Composer validation and dependency installation
- Quick feedback for development iterations

#### 2. Integration Tests (25 minutes, runs after unit tests)

- Complete MChef recipe initialization with Docker containers
- Container lifecycle management (up, halt, cleanup)
- Core MChef commands (`list`, `use`, `up`, `halt`, `database`, `config`)
- Docker container creation and management
- PostgreSQL database setup and connectivity
- Moodle plugin installation (optimized set for CI efficiency)

**Total Runtime**: ~30-35 minutes (sequential execution with unit tests first)

**Environment**:

- Ubuntu latest
- PHP 8.2 with required extensions
- Docker with BuildKit enabled (Docker desktop or OrbStack compatible)
- Composer dependency management with caching

## Optimization Features

### Performance Optimizations:

- **Sequential Job Execution**: Unit tests run first (fast feedback), followed by integration tests only if unit tests pass
- **Minimal Plugin Set**: Uses single lightweight plugin for faster testing
- **Proper Timeout Handling**: 10 minutes for unit tests, 25 minutes for integration
- **Docker BuildKit**: Enabled for faster image builds
- **Composer Caching**: Dependencies cached across runs
- **Efficient Container Management**: Quick initialization with optimized recipe

### Reliability Features:

- **Comprehensive Cleanup**: Automatic container and image cleanup in all scenarios
- **Detailed Logging**: Extensive output for debugging failures
- **Graceful Timeout Handling**: Proper process management and cleanup
- **Error Diagnostics**: Container logs captured on failure

## Configuration Details

### Environment Variables

```yaml
DOCKER_BUILDKIT: 1 # Enable Docker BuildKit for faster builds
COMPOSE_DOCKER_CLI_BUILD: 1 # Use Docker CLI for compose operations
```

### Test Recipe Configuration

The workflow uses an optimized test recipe designed for CI efficiency:

```json
{
  "name": "ci-test",
  "moodleTag": "v4.1.0",
  "phpVersion": "8.0",
  "plugins": [
    {
      "repo": "https://github.com/marcusgreen/moodle-qtype_gapfill.git",
      "branch": "main"
    }
  ],
  "containerPrefix": "ci-test",
  "host": "ci-test.localhost",
  "port": 8080,
  "updateHostHosts": false,
  "dbType": "pgsql",
  "developer": true,
  "cloneRepoPlugins": false
}
```

### Resource Management

- **Unit Test Timeout**: 10 minutes (quick feedback)
- **Integration Test Timeout**: 25 minutes (full container lifecycle)
- **Cleanup**: Automatic container and image cleanup in all scenarios
- **Caching**: Composer dependencies cached across runs
- **Sequential Execution**: Fails fast if unit tests fail

## Debugging Failed Workflows

### Common Issues and Solutions

1. **Unit Test Failures (Job 1)**
   - Review PHPUnit output in workflow logs
   - Check for dependency issues
   - Validate PHP version compatibility
   - Verify composer.json/composer.lock consistency

2. **Integration Test Failures (Job 2)**
   - Container startup timeout: Check Docker daemon status and resource availability
   - MChef command failures: Verify recipe configuration and container connectivity
   - Database issues: Review PostgreSQL container logs
   - Network problems: Check container network setup

3. **Workflow Configuration Issues**
   - Verify trigger conditions (branch names, file paths)
   - Check timeout settings for adequate time
   - Validate environment variables and secrets

### Accessing Logs

- Both unit test and integration test logs are available in GitHub Actions
- Container logs are automatically captured on failure in integration tests
- Use `workflow_dispatch` for manual testing with full debug output
- Each job provides detailed step-by-step output

## Maintenance

### Updating Dependencies

- **PHP Version Updates**: Update PHP version in both jobs consistently
- **Docker Images**: Test image updates in development environment first
- **Composer Dependencies**: Automatically validated, cached for performance
- **MChef Commands**: New commands should be added to integration test validation

### Performance Monitoring

- Monitor total workflow runtime (target: under 35 minutes)
- Track job-level performance (unit tests: <10min, integration: <25min)
- Optimize Docker image builds and caching strategies
- Consider splitting integration tests if runtime exceeds limits

### Adding New Tests

- **Unit Tests**: Add to PHPUnit test suite (automatically included in Job 1)
- **Integration Tests**: Extend MChef command testing in Job 2
- **New Commands**: Add validation steps to integration job
- **Performance Tests**: Consider adding separate job if needed

## Local Testing

You can run the same tests locally using the provided `Tests/Integration/Bash/test-mchef.sh` script:

```bash
# Full integration test (equivalent to both jobs)
./src/Tests/Integration/Bash/test-mchef.sh

# Unit tests only (equivalent to Job 1)
./src/Tests/Integration/Bash/test-mchef.sh --unit-only

# Integration tests only (equivalent to Job 2)
./src/Tests/Integration/Bash/test-mchef.sh --integration-only

# Custom configuration
./src/Tests/Integration/Bash/test-mchef.sh --recipe custom-recipe.json --timeout 600
```

The local script provides the same functionality as the GitHub workflow with additional debugging options and flexible execution modes.

```json
{
  "name": "ci-test",
  "moodleTag": "v4.1.0",
  "phpVersion": "8.0",
  "plugins": [
    {
      "repo": "https://github.com/marcusgreen/moodle-qtype_gapfill.git",
      "branch": "main"
    }
  ],
  "containerPrefix": "ci-test",
  "host": "ci-test.localhost",
  "port": 8080,
  "updateHostHosts": false,
  "dbType": "pgsql",
  "developer": true,
  "cloneRepoPlugins": false
}
```

**Key optimizations:**

- Single plugin instead of multiple
- `cloneRepoPlugins: false` for faster setup
- `updateHostHosts: false` (not needed in CI)
- Non-standard port to avoid conflicts

## Commands Tested

### Core MChef Commands

- `mchef.php --help` - CLI help and basic functionality
- `mchef.php <recipe.json>` - Recipe initialization
- `mchef.php list` - List registered instances
- `mchef.php use <instance>` - Select active instance
- `mchef.php up <instance>` - Start containers
- `mchef.php halt <instance>` - Stop containers
- `mchef.php config --help` - Configuration options
- `mchef.php database --info` - Database connection info

### Container Verification

- Docker container creation
- Container status monitoring
- Container log inspection (on failure)
- Container networking verification
- Proper cleanup after tests

## Failure Handling

**Timeout Protection:**

- 30-minute timeout for full tests
- 10-minute timeout for fast tests
- Process termination for hung operations

**Error Diagnostics:**

- Container log collection on failure
- Docker system state inspection
- Clear error messages for debugging

**Cleanup Guarantee:**

- Containers removed even on failure
- Images cleaned up to save space
- Docker system pruning

## Usage for Development

### For Major Changes

Run the full `test-mchef.yml` workflow by:

1. Creating a PR to main/master/develop
2. Or manually triggering via GitHub Actions UI

### For Quick Iteration

The `fast-test.yml` workflow runs automatically on:

- Changes to `src/**` (source code)
- Changes to `mchef.php` (main CLI)
- Changes to `composer.json/lock` (dependencies)
- Changes to `example-mrecipe.json` (test recipe)
- Changes to workflow files

### Local Testing

To test locally before pushing:

```bash
# Run unit tests
php vendor/bin/phpunit src/Tests/ --testdox

# Test basic CLI
php mchef.php --help

# Test with minimal recipe
mkdir test-dir && cd test-dir
echo '{"name":"test","moodleTag":"v4.1.0","phpVersion":"8.0","plugins":[],"containerPrefix":"test","host":"test.localhost","dbType":"pgsql","developer":false}' > test.json
php ../mchef.php test.json
```

## CI Environment Considerations

**Docker Resources:**

- GitHub Actions runners have limited resources
- Timeouts prevent hung builds
- Cleanup ensures no resource leaks

**Network Configuration:**

- Uses localhost domains to avoid DNS issues
- Non-standard ports to avoid conflicts
- No host file modifications in CI

**Performance Optimizations:**

- Composer caching for faster dependency installation
- Minimal plugin sets for faster builds
- Parallel job execution where possible
- Early termination on critical failures

**Security:**

- No sensitive data in recipes
- Proper container isolation
- Clean environment for each run
