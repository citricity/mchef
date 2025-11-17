<?php

namespace App\Service;

class PHPVersions extends AbstractService {

    final public static function instance(): PHPVersions {
        return self::setup_singleton();
    }

    public function listVersions(): array {
        $repoUrl = "https://api.github.com/repos/moodlehq/moodle-php-apache/branches";

        // Set up a CURL session to retrieve the branch information from GitHub
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (defined('CURLSSLOPT_NATIVE_CA')  && version_compare(curl_version()['version'], '7.71', '>=')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: PHP'
        ));

        $hardCodedVersions = [
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
            '8.4'
        ];

        try {
            $response = curl_exec($ch);
        } catch (\Exception $e) {
            return $hardCodedVersions;
        }

        // Parse the JSON response into a PHP array
        $branches = json_decode($response, true);
        if (empty($branches)) {
            return $hardCodedVersions;
        }

        // Loop through the branches and extract the PHP version from the branch name
        try {
            $phpVersions = [];
            foreach ($branches as $branch) {
                $branchName = $branch['name'];
                preg_match('/(\d+\.\d+)(?:-)/', $branchName, $matches);
                if (!empty($matches[1])) {
                    $phpVersion = $matches[1];
                    $phpVersions[] = $phpVersion;
                }
            }
        } catch (\Exception $e) {
            return $hardCodedVersions;
        }

        return array_unique($phpVersions);
    }
}
