<?php

namespace App\Service;

use splitbrain\phpcli\Exception;
use stdClass;
use App\Model\Recipe;

class RecipeService extends AbstractService {

    // Dependencies
    protected ModelJSONDeserializer $deserializerService;
    protected Configurator $configuratorService;
    protected PHPVersions $phpVersionsService;

    final public static function instance(): RecipeService {
        return self::setup_singleton();
    }

    public function parse(string $filePath): Recipe {
        if (!file_exists($filePath)) {
            throw new Exception('Recipe file does not exist - '.$filePath);
        }
        $contents = file_get_contents($filePath);

        try {
            /** @var Recipe $recipe */
            $recipe = $this->deserializerService->deserialize($contents, Recipe::class);
        } catch (\Exception $e) {
            throw new Exception('Failed to decode recipe JSON. Recipe: '.$filePath, 0, $e);
        }

        // Handle restoreStructure URL if it's a string
        $this->handleRestoreStructureUrl($recipe, $filePath);

        // If adminPassword is not set in recipe, use global config value if available
        if (empty($recipe->adminPassword)) {
            $globalConfig = $this->configuratorService->getMainConfig();
            if (!empty($globalConfig->adminPassword)) {
                $recipe->adminPassword = $globalConfig->adminPassword;
            }
        }

        // Validate required properties
        $this->validateRecipe($recipe, $filePath);

        $this->setDefaults($recipe);
        $recipe->setRecipePath($filePath);

        return $recipe;
    }  

    /**
     * Handle restoreStructure URL - if restoreStructure is a string URL, download and parse it
     */
    private function handleRestoreStructureUrl(Recipe $recipe, string $filePath): void {
        // Check if restoreStructure is a string (URL)
        if (is_string($recipe->restoreStructure)) {
            $restoreStructureUrl = $recipe->restoreStructure;
            
            if ($this->isUrl($restoreStructureUrl)) {
                $this->cli->notice('Downloading restore structure from URL: ' . $restoreStructureUrl);
                $downloadedContent = $this->downloadFile($restoreStructureUrl);
                $downloadedData = json_decode($downloadedContent, true);
                
                if ($downloadedData === null) {
                    throw new Exception('Failed to parse restore structure JSON from URL: ' . $restoreStructureUrl);
                }

                // Deserialize the downloaded structure as RestoreStructure
                $restoreStructure = $this->deserializerService->deserializeData(
                    $downloadedData,
                    \App\Model\RestoreStructure::class
                );
                
                $recipe->restoreStructure = $restoreStructure;
            }
        }
    }

    /**
     * Download a file from URL
     */
    private function downloadFile(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development - consider making configurable

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($content === false || $httpCode !== 200) {
            throw new Exception("Failed to download file from {$url}: HTTP {$httpCode} - {$error}");
        }

        return $content;
    }

    /**
     * Check if a string is a valid URL
     */
    private function isUrl(string $str): bool {
        return filter_var($str, FILTER_VALIDATE_URL) !== false;
    }

    private function validateRecipe(Recipe $recipe, string $filePath) {
        // Validate required properties - these are already validated by the constructor
        // but we can add additional business logic validation here

        $validPHPVersions = $this->phpVersionsService->listVersions();
        if (!in_array($recipe->phpVersion, $validPHPVersions)) {
            $supported = implode(', ', $validPHPVersions);
            throw new Exception("Unsupported php version $recipe->phpVersion - supported versions are $supported");
        }
    }

    private function getPortString(Recipe $recipe): ?string {
        $recipe->port = $recipe->port ?? 80;
        return $recipe->port === 80 ? '' : ':'.$recipe->port;
    }

    public function getBehatHost(Recipe $recipe): ?string {
        if (!$recipe->includeBehat) {
            return null;
        }
        if (empty($recipe->behatHost)) {
            if (!empty($recipe->host)) {
                return $recipe->host . '.behat';
            } else {
                throw new Exception('You must specify either a host or behatHost!');
            }
        }
        return null;
    }

    private function setDefaults(Recipe $recipe) {

        // Setup port and wwwRoot.
        $recipe->port = $recipe->port ?? 80;
        $portStr = $this->getPortString($recipe);
        $recipe->wwwRoot = $recipe->hostProtocol.'://'.$recipe->host.($portStr);

        // Setup behat defaults.
        if ($recipe->includeBehat) {
            $recipe->behatHost = $this->getBehatHost($recipe);
            $recipe->behatWwwRoot = $recipe->hostProtocol.'://'.$recipe->behatHost.($portStr);
        }

        // Setup database defaults.
        if (empty($recipe->dbName)) {
            $recipe->dbName = ($recipe->containerPrefix ?? 'mc').'-moodle';
        }
    }
}
