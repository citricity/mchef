<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
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
        $rawBaseUrl = $this->githubToRawBaseUrl($url);
        $fullUrl = sprintf(
            '%s/%s/%s',
            $rawBaseUrl,
            rawurlencode($branchOrTag),
            ltrim($filePath, '/')
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "User-Agent: MChef\r\n", // GitHub requires a UA
                ],
                'ignore_errors' => true, // needed to read response body
                'timeout' => 10,
            ],
        ]);

        $content = @file_get_contents($fullUrl, false, $context);

        // No headers = hard failure: DNS, SSL, network down, etc.
        if (!isset($http_response_header) || empty($http_response_header)) {
            throw new CliRuntimeException("No response from GitHub when requesting: {$fullUrl}");
        }

        // Parse status line, e.g. "HTTP/1.1 200 OK"
        $statusLine = $http_response_header[0];
        preg_match('{HTTP/\S+\s(\d{3})}', $statusLine, $match);
        $status = isset($match[1]) ? (int)$match[1] : 0;

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
        [$owner, $repo] = $this->extractGithubOwnerRepo($repositoryUrl);

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode(trim($folderPath, '/')),
            rawurlencode($branchOrTag)
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "User-Agent: MChef\r\n", // GitHub requires a UA
                ],
                'ignore_errors' => true,
            ],
        ]);

        $json = @file_get_contents($apiUrl, false, $context);
        if ($json === false) {
            return false;
        }
                
        $jsonobj = json_decode($json, true);

        if (!empty($jsonobj['status']) && $jsonobj['status'] === "404") {
            return false;
        }
        return is_array($jsonobj);
    }
}
