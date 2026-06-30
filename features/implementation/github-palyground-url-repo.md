# Feature Implementation: Github playground url repo

## Status - in progress

## Overview

Implemented the playground URL repository flow so long playground URLs can be transformed into short, shareable GitHub-hosted redirect URLs, then rendered as QR codes.

## Implementation Details

### 1. Playground command flow

- **File**: `src/Command/Playground.php`
- Added global configuration checks before publishing:
  - `githubToken` is required
  - `githubUrlsRepo` is required
- Added warnings and early return if either field is missing.
- Added publish flow using `QrCodeService::publishRedirectUrl()`.
- Added command output showing:
  - Published GitHub resource URL
  - Generated short URL
  - Terminal QR code for the short URL
- Added a temporary long URL builder method used by playground while recipe-to-blueprint conversion remains TODO.

### 2. QR service templating and orchestration

- **File**: `src/Service/QrCodeService.php`
- Kept existing terminal QR generation behavior.
- Added `renderRedirectHtml(string $url): string`:
  - Uses Twig template rendering through `Main` service.
  - Renders `@github/urlFile.twig` with the target URL.
- Added `publishRedirectUrl(string $url, string $repo, string $token, ?string $id = null): array`:
  - Generates a UUID-like uppercase id when not provided.
  - Builds path `<ID>.html`.
  - Renders HTML redirect template.
  - Publishes HTML via `Github` service.
  - Returns id, resource URL, and short URL.

### 3. GitHub service publishing support

- **File**: `src/Service/Github.php`
- Added `publishHtmlToRepository(...)`:
  - Uses GitHub Contents API (`PUT /repos/{repo}/contents/{path}`)
  - Sends payload with:
    - `message`: `Add URL redirect <ID>`
    - `content`: base64 encoded HTML
    - `branch`: `main` by default
  - Returns a URL to created resource (prefers API URLs, falls back to blob URL).
  - Throws `CliRuntimeException` for non-success responses or invalid API responses.
- Added `buildGithubPagesUrl(string $repo, string $path): string`:
  - Supports both project pages (`owner.github.io/repo/path`) and user/org pages (`owner.github.io/path`).
- Added internal `putRepoContents(...)` helper for the raw cURL API call.

### 4. Template usage

- **File**: `templates/github/urlFile.twig`
- Existing template was reused.
- This template now drives generated redirect files published to GitHub.

## Testing

### Added tests

- **File**: `src/Tests/GithubServiceTest.php`
  - Verifies publish success URL handling.
  - Verifies URL fallback behavior.
  - Verifies error handling for failed publish.
  - Verifies GitHub Pages URL generation for both repo styles.

- **File**: `src/Tests/QrCodeServiceTest.php`
  - Verifies redirect HTML is rendered from Twig template.
  - Verifies publish orchestration calls GitHub service with expected values.
  - Verifies returned payload shape and values.

### Test execution

Executed targeted tests:

```bash
./vendor/bin/phpunit --filter "GithubServiceTest|QrCodeServiceTest"
```

Result:

- 6 tests passed
- 13 assertions

## Configuration

The feature requires the following global config fields:

- `githubToken`: GitHub token with repo contents write permissions.
- `githubUrlsRepo`: Target repository in `owner/repo` format.

If either field is not set, `playground` command emits warnings and exits without publishing.

## Usage

1. Ensure `githubToken` and `githubUrlsRepo` are configured in global config.
2. Run playground command for an instance.
3. Command publishes a redirect HTML file to the configured GitHub repo.
4. Command prints the short URL and a terminal QR code for that URL.

## Future Considerations

- Replace temporary long URL generation with real recipe-to-playground blueprint URL mapping once blueprint serialization is implemented.
- Add config command flags for setting `githubToken` and `githubUrlsRepo` directly from CLI.
- Optionally support configurable branch names instead of fixed `main` default.
- Add integration tests with HTTP mocking around GitHub publish flow.

## References

- Requirement: `features/requirements/github-palyground-url-repo.md`
- Related implementation files:
  - `src/Command/Playground.php`
  - `src/Service/QrCodeService.php`
  - `src/Service/Github.php`
  - `src/Tests/GithubServiceTest.php`
  - `src/Tests/QrCodeServiceTest.php`
