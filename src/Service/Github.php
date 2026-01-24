<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Helpers\Http;
use App\Helpers\OS;
use Exception;
use InvalidArgumentException;

class Github extends AbstractService {

    final public static function instance(): Github {
        return self::setup_singleton();
    }

    /**
     * Convert a GitHub repo URL to the base raw URL (removing .git and ssh syntax).
     *
     * Examples:
     *   https://github.com/moodle/moodle.git → https://raw.githubusercontent.com/moodle/moodle
     *   git@github.com:moodle/moodle.git     → https://raw.githubusercontent.com/moodle/moodle
     *
     * @param string $url Git clone URL in HTTPS or SSH format
     * @return string Raw.githubusercontent.com base
     */
    public function githubToRawBaseUrl(string $url): string {
        // Normalize SSH form: git@github.com:owner/repo(.git)
        if (preg_match('#^git@github\.com:(.+)$#', $url, $m)) {
            $url = 'https://github.com/' . $m[1];
        }

        // Extract owner + repo, drop optional .git
        if (!preg_match('#https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            throw new InvalidArgumentException("Not a valid GitHub URL: $url");
        }

        [, $owner, $repo] = $m;

        return sprintf(
            'https://raw.githubusercontent.com/%s/%s',
            $owner,
            $repo
        );
    }

    public function fetchGithubRepoSingleFileContents(string $url, string $branchOrTag, string $filePath): ?string {
        $token = getenv('GITHUB_TOKEN') ?: null;
        if ($token) {
            // Fetch via GitHub API with authentication
            try {
                return $this->fetchViaApi($url, $branchOrTag, $filePath, $token);
            } catch (CliRuntimeException $e) {
                // If API fails, fall back to raw URL method
                $this->cli->info("GitHub API failed, falling back to raw URL: " . $e->getMessage());
                return $this->fetchGithubRepoSingleFileContentsFallback($url, $branchOrTag, $filePath, $token);
            }
        } else {
            return $this->fetchGithubRepoSingleFileContentsFallback($url, $branchOrTag, $filePath);
        }
    }

    private function fetchViaApi(string $url, string $branchOrTag, string $filePath, string $token): ?string {
        [$owner, $repo] = $this->extractGithubOwnerRepo($url);

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode(ltrim($filePath, '/')),
            rawurlencode($branchOrTag)
        );

        $response = Http::get($apiUrl, ['Authorization' => 'Bearer ' . $token]);

        switch ($response->statusCode) {
            case 200:
                $data = json_decode($response->body, true);
                if (!$data || !isset($data['content'])) {
                    throw new CliRuntimeException("Invalid API response format");
                }
                
                // GitHub API returns base64-encoded content with newlines; strip them before decoding
                return base64_decode(str_replace(["\n", "\r"], '', $data['content']));

            case 404:
                return null; // File doesn't exist

            case 403:
                $error = json_decode($response->body, true);
                if (isset($error['message']) && str_contains($error['message'], 'rate limit')) {
                    throw new CliRuntimeException("GitHub API rate limit exceeded");
                }
                throw new CliRuntimeException("GitHub API access forbidden - check token permissions");

            case 401:
                throw new CliRuntimeException("GitHub API authentication failed - invalid token");

            default:
                throw new CliRuntimeException("GitHub API error {$response->statusCode}: {$response->body}");
        }
    }

    private function fetchGithubRepoSingleFileContentsFallback(string $url, string $branchOrTag, string $filePath, ?string $token = null): ?string {
        $rawBaseUrl = $this->githubToRawBaseUrl($url);
        $fullUrl = sprintf(
            '%s/%s/%s',
            $rawBaseUrl,
            rawurlencode($branchOrTag),
            ltrim($filePath, '/')
        );

        $response = Http::get($fullUrl, ($token ? ['Authorization' => 'Bearer ' . $token] : []));

        // No headers = hard failure: DNS, SSL, network down, etc.
        if (empty($response->headers)) {
            throw new CliRuntimeException("No response from GitHub when requesting: {$fullUrl}");
        }
        $content = $response->body;
        $status = $response->statusCode;
        switch ($status) {
            case 200:
                return $content; // success

            case 404:
                // File does not exist — not an error
                return null;

            case 403:
            case 429:
                throw new CliRuntimeException("GitHub rate limit or permission denied for file: {$fullUrl}");

            case 500:
            case 502:
            case 503:
            case 504:
                throw new CliRuntimeException("GitHub server error ({$status}) fetching file: {$fullUrl}");

            default:
                throw new CliRuntimeException("Unexpected HTTP {$status} fetching file: {$fullUrl}");
        }
    }

    public function extractGithubOwnerRepo(string $url): array {
        if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }
        throw new InvalidArgumentException("Not a valid GitHub repo URL: {$url}");
    }

    /**
     * Check if a folder exists in a GitHub repository at a specific tag or branch
     */
    public function githubFolderExists(string $repositoryUrl, string $branchOrTag, string $folderPath): bool {
        // TODO - use github API
        $token = getenv('GITHUB_TOKEN') ?: null;
        if (!$token) {
            return $this->githubFolderExistsFallback($repositoryUrl, $branchOrTag, $folderPath);
        }
        // Fetch via GitHub API with authentication
        try {
            [$owner, $repo] = $this->extractGithubOwnerRepo($repositoryUrl);

            $apiUrl = sprintf(
                'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode(ltrim($folderPath, '/')),
                rawurlencode($branchOrTag)
            );

            $response = Http::get($apiUrl, ['Authorization' => 'Bearer ' . $token]);

            switch ($response->statusCode) {
                case 200:
                    $data = json_decode($response->body, true);
                    return is_array($data) && !empty($data); // Folder exists

                case 404:
                    return false; // Folder doesn't exist

                default:
                    throw new CliRuntimeException("GitHub API error {$response->statusCode}: {$response->body}");
            }
        } catch (Exception $e) {
            $this->cli->info("GitHub API failed, falling back to raw URL: " . $e->getMessage());
            return $this->githubFolderExistsFallback($repositoryUrl, $branchOrTag, $folderPath);
        }
    }

    private function githubFolderExistsFallback(string $repositoryUrl, string $branchOrTag, string $folderPath): bool {
            [$owner, $repo] = $this->extractGithubOwnerRepo($repositoryUrl);

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode(trim($folderPath, '/')),
            rawurlencode($branchOrTag)
        );

        $response = Http::get($apiUrl);
        
        $json = $response->body;
        if (empty($json)) {
            return false;
        }
                
        $jsonobj = json_decode($json, true);

        if (!empty($jsonobj['status']) && $jsonobj['status'] === "404") {
            return false;
        }
        return is_array($jsonobj);
    }        
}
