<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace App\Service;

use splitbrain\phpcli\Exception;
use App\Model\Recipe;
use App\StaticVars;

class RecipeService extends AbstractService {
    // Dependencies
    protected ModelJSONDeserializer $deserializerService;
    protected Configurator $configuratorService;
    protected PHPVersions $phpVersionsService;

    final public static function instance(): RecipeService {
        return self::setup_singleton();
    }

    public function parseFile(string $filePath): Recipe {
        if (!file_exists($filePath)) {
            throw new Exception('Recipe file does not exist - ' . $filePath);
        }
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new Exception('Failed to read recipe file - ' . $filePath);
        }
        return $this->parse($contents, $filePath);
    }

    public function parse(string|array|object $contents, string $recipeIdent): Recipe {
        $recipe = null;
        try {
            if (is_string($contents)) {
                /** @var Recipe $recipe */
                $recipe = $this->deserializerService->deserialize($contents, Recipe::class);
            } else if (is_array($contents)) {
                $contents = (object)$contents;
                /** @var Recipe $recipe */
                $recipe = $this->deserializerService->deserializeData($contents, Recipe::class);
            } else if (is_object($contents)) {
                /** @var Recipe $recipe */
                $recipe = $this->deserializerService->deserializeData($contents, Recipe::class);
            }
        } catch (\Exception $e) {
            throw new Exception('Failed to deserialize recipe. Recipe: ' . $recipeIdent, 0, $e);
        }

        if (!$recipe) {
            throw new Exception('Failed to deserialize recipe. Recipe: ' . $recipeIdent);
        }

        // Handle restoreStructure URL if it's a string
        $this->handleRestoreStructureUrl($recipe);

        // If adminPassword is not set in recipe, use global config value if available
        if (empty($recipe->adminPassword)) {
            $globalConfig = $this->configuratorService->getMainConfig();
            if (!empty($globalConfig->adminPassword)) {
                $recipe->adminPassword = $globalConfig->adminPassword;
            }
        }

        // Validate required properties
        $this->validateRecipe($recipe);

        $this->setDefaults($recipe);

        if (file_exists($recipeIdent)) {
            $recipe->setRecipePath($recipeIdent);
        }

        return $recipe;
    }

    /**
     * Handle restoreStructure URL - if restoreStructure is a string URL, download and parse it
     */
    private function handleRestoreStructureUrl(Recipe $recipe): void {
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

    private function validateRecipe(Recipe $recipe) {
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
        return $recipe->port === 80 ? '' : ':' . $recipe->port;
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
        // Try to set admin password from global config if not set in recipe.
        if (empty($recipe->adminPassword)) {
            $globalConfig = $this->configuratorService->getMainConfig();
            if (!empty($globalConfig->adminPassword)) {
                $recipe->adminPassword = $globalConfig->adminPassword;
            }
        }

        // Setup port and wwwRoot.
        $recipe->port = $recipe->port ?? 80;
        $portStr = $this->getPortString($recipe);
        $recipe->wwwRoot = $recipe->hostProtocol . '://' . $recipe->host . ($portStr);


        // Setup developer field defaults.
        $devFields = ['includePhpUnit', 'includeBehat', 'includeXdebug'];
        if (empty(StaticVars::$ciMode) || !empty($recipe->allowDevFeaturesInProduction)) {
            // Not in CI mode - set dev features based on developer flag.
            foreach ($devFields as $field) {
                if ($recipe->$field === null) {
                    $recipe->$field = !empty($recipe->developer);
                }
            }
        } else {
            // In CI mode - disable all dev features.
            $recipe->developer = false;
            foreach ($devFields as $field) {
                $recipe->$field = false;
            }
        }

        if ($recipe->includeBehat) {
            $recipe->behatHost = $this->getBehatHost($recipe);
            $recipe->behatWwwRoot = $recipe->hostProtocol . '://' . $recipe->behatHost . ($portStr);
        }

        // Setup database defaults.
        if (empty($recipe->dbName)) {
            $recipe->dbName = ($recipe->containerPrefix ?? 'mc') . '-moodle';
        }
    }
}
