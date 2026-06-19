<?php

namespace App\Service;

class PHPVersions extends AbstractService {

    final public static function instance(): PHPVersions {
        return self::setup_singleton();
    }

    public function listVersions(): array {
        $hardCodedVersions = $this->getHardCodedVersions();

        $response = $this->fetchBranchesResponse();
        if (!is_string($response) || $response === '') {
            return $hardCodedVersions;
        }

        // Parse the JSON response into a PHP array
        $branches = json_decode($response, true);
        if (empty($branches) || !is_array($branches)) {
            return $hardCodedVersions;
        }

        // Handle api rate limit issue.
        if (!empty($branches['message']) && strpos($branches['message'], 'API rate limit exceeded') !== false) {
            return $hardCodedVersions;
        }

        // Loop through the branches and extract the PHP version from the branch name
        // GitHub errors are often JSON objects with "message" and not a list of branch objects.
        // Ensure the payload is a list before iterating to avoid TypeError on malformed responses.
        if (!array_is_list($branches)) {
            return $hardCodedVersions;
        }

        try {
            $phpVersions = [];
            foreach ($branches as $branch) {
                if (!is_array($branch) || empty($branch['name']) || !is_string($branch['name'])) {
                    continue;
                }

                $branchName = $branch['name'];
                preg_match('/(\d+\.\d+)(?:-)/', $branchName, $matches);
                if (!empty($matches[1])) {
                    $phpVersions[] = $matches[1];
                }
            }
        } catch (\Throwable $e) {
            return $hardCodedVersions;
        }

        return array_unique($phpVersions);
    }

    /**
     * Fetch branches JSON from GitHub API.
     */
    protected function fetchBranchesResponse(): ?string {
        $repoUrl = "https://api.github.com/repos/moodlehq/moodle-php-apache/branches";

        // Set up a CURL session to retrieve branch information from GitHub.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (defined('CURLSSLOPT_NATIVE_CA')  && version_compare(curl_version()['version'], '7.71', '>=')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: PHP'
        ));

        try {
            $response = curl_exec($ch);
        } catch (\Throwable $e) {
            return null;
        } finally {
            unset($ch); // Close curl handle.
        }

        if (!is_string($response) || $response === '') {
            return null;
        }

        return $response;
    }

    protected function getHardCodedVersions(): array {
        return [
            '5.6',
            '7.0',
            '7.1',
            '7.2',
            '7.3',
            '7.4',
            '8.0',
            '8.1',
            '8.2',
            '8.3',
            '8.4',
            '8.5'
        ];
    }
}
