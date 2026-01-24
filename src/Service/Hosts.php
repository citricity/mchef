<?php

namespace App\Service;

use App\Helpers\OS;
use App\Model\Recipe;
use App\Traits\ExecTrait;

class Hosts extends AbstractService {

    use ExecTrait;

    public const MCHEF_SECTION_START = '# Hosts added by mchef';
    public const MCHEF_SECTION_END = '# End hosts added by mchef';

    final public static function instance(): Hosts {
        return self::setup_singleton();
    }

    /**
     * Get the path to the hosts file based on the operating system.
     *
     * @return string Path to the hosts file
     */
    public function getHostsFilePath(): string {
        if (!OS::isWindows()) {
            return '/etc/hosts';
        } else {
            return 'C:\\Windows\\System32\\drivers\\etc\\hosts';
        }
    }

    /**
     * Extract all hosts from existing mchef sections in the hosts file.
     *
     * @param array $hostsLines Array of lines from the hosts file
     * @return array Array of hostnames (without IP addresses)
     */
    private function extractMchefHosts(array $hostsLines): array {
        $mchefHosts = [];
        $inMchefSection = false;

        foreach ($hostsLines as $line) {
            $trimmed = trim($line);
            
            if (strpos($trimmed, self::MCHEF_SECTION_START) === 0) {
                $inMchefSection = true;
                continue;
            }
            
            if (strpos($trimmed, self::MCHEF_SECTION_END) === 0) {
                $inMchefSection = false;
                continue;
            }
            
            if ($inMchefSection && preg_match('/^127\.0\.0\.1\s+(\S+)/', $trimmed, $matches)) {
                $host = trim($matches[1]);
                if (!empty($host)) {
                    $mchefHosts[] = $host;
                }
            }
        }

        return array_unique($mchefHosts);
    }

    /**
     * Remove all mchef sections from the hosts file lines.
     * Sections are identified by MCHEF_SECTION_START and MCHEF_SECTION_END markers.
     *
     * @param array $hostsLines Array of lines from the hosts file
     * @return array Array of lines with mchef sections removed
     */
    private function removeMchefSections(array $hostsLines): array {
        $result = [];
        $inMchefSection = false;

        foreach ($hostsLines as $line) {
            $trimmed = trim($line);
            
            if (strpos($trimmed, self::MCHEF_SECTION_START) === 0) {
                $inMchefSection = true;
                continue;
            }
            
            if (strpos($trimmed, self::MCHEF_SECTION_END) === 0) {
                $inMchefSection = false;
                continue;
            }
            
            if (!$inMchefSection) {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Check if a host already exists in the hosts file.
     *
     * @param array $hostsLines Array of lines from the hosts file
     * @param string $hostname The hostname to check
     * @return bool True if the host exists
     */
    private function hostExistsInFile(array $hostsLines, string $hostname): bool {
        foreach ($hostsLines as $line) {
            if (preg_match('/^127\.0\.0\.1\s+' . preg_quote($hostname, '/') . '(?:\s|$)/m', $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get hosts from a recipe that should be added to the hosts file.
     *
     * @param Recipe $recipe The recipe to extract hosts from
     * @return array Array of hostnames
     */
    private function getHostsFromRecipe(Recipe $recipe): array {
        $hosts = [];
        
        if (!empty($recipe->host)) {
            $hosts[] = $recipe->host;
        }
        
        if (!empty($recipe->behatHost)) {
            $hosts[] = $recipe->behatHost;
        }
        
        return array_filter($hosts);
    }

    /**
     * Update the hosts file with hosts from the recipe, consolidating all mchef hosts into a single section.
     *
     * @param Recipe $recipe The recipe containing hosts to add
     * @return void
     * @throws \Exception If the update fails
     */
    public function updateHosts(Recipe $recipe): void {
        if (!$recipe->updateHostHosts) {
            return;
        }

        $hostsFilePath = $this->getHostsFilePath();

        // Read the current hosts file
        try {
            $hostsLines = file($hostsFilePath);
            if ($hostsLines === false) {
                throw new \Exception("Failed to read hosts file: $hostsFilePath");
            }
        } catch (\Exception $e) {
            $this->cli->error('Failed to read hosts file: ' . $e->getMessage());
            throw $e;
        }

        // Extract existing mchef hosts
        $existingMchefHosts = $this->extractMchefHosts($hostsLines);
        
        // Get hosts from the current recipe
        $recipeHosts = $this->getHostsFromRecipe($recipe);
        
        // Remove mchef sections from the file
        $hostsLines = $this->removeMchefSections($hostsLines);
        
        // Remove any trailing newlines at the end of the file
        while (!empty($hostsLines) && trim(end($hostsLines)) === '') {
            array_pop($hostsLines);
        }
        
        // Combine existing mchef hosts with new recipe hosts
        $allMchefHosts = array_unique(array_merge($existingMchefHosts, $recipeHosts));
        
        // Sort hosts alphabetically for consistency
        sort($allMchefHosts);

        // Build the consolidated mchef section
        $mchefSection = [];
        $mchefSection[] = "\n" . self::MCHEF_SECTION_START . "\n";
        foreach ($allMchefHosts as $host) {
            $mchefSection[] = "127.0.0.1       $host\n";
        }
        $mchefSection[] = self::MCHEF_SECTION_END . "\n";

        // Add the consolidated section to the end of the file
        $hostsLines = array_merge($hostsLines, $mchefSection);

        // Combine all lines into a single string
        $hostsContent = implode('', $hostsLines);

        // Write to a temporary file first
        $tmpHostsFile = tempnam(sys_get_temp_dir(), "etc_hosts");
        file_put_contents($tmpHostsFile, $hostsContent);

        // Copy the temporary file to the actual hosts file (requires sudo/administrator)
        if (!OS::isWindows()) {
            $this->cli->notice("Updating $hostsFilePath - may need root password.");
            $cmd = "sudo cp -f $tmpHostsFile $hostsFilePath";
        } else {
            $this->cli->notice("Updating $hostsFilePath - may need to be running as administrator.");
            $cmd = "copy /Y \"$tmpHostsFile\" \"$hostsFilePath\"";
        }
        
        exec($cmd, $output, $returnVar);

        if ($returnVar != 0) {
            throw new \Exception("Error updating $hostsFilePath file");
        }

        // Verify the update was successful
        $updatedContent = file_get_contents($hostsFilePath);
        foreach ($recipeHosts as $hostToCheck) {
            if (stripos($updatedContent, $hostToCheck) === false) {
                throw new \Exception("Failed to update $hostsFilePath - host $hostToCheck not found");
            }
        }

        $this->cli->success("Successfully updated $hostsFilePath");
    }
}
