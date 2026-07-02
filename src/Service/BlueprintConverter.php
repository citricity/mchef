<?php

namespace App\Service;

use App\Model\AbstractModel;
use App\Model\Recipe;
use App\Model\RecipePlugin;
use InvalidArgumentException;

class BlueprintConverter extends AbstractService {

    private Github $github;

    private array $warnings = [];

    final public static function instance(): BlueprintConverter {
        return self::setup_singleton();
    }

    /**
     * Warnings produced during the last call to convert() — e.g. plugins
     * skipped due to an unsupported host. Reset on each convert() call.
     */
    public function getWarnings(): array {
        return $this->warnings;
    }

    /**
     * Convert a mchef Recipe to a moodle-playground blueprint array.
     *
     * Only fields with a direct playground equivalent are mapped.
     * Infrastructure fields (Docker, DB, networking) are silently ignored.
     *
     * TODO: Consider mapping additional fields.
     */
    public function convert(Recipe $recipe, ?string $snapshotUrl = null): array {
        $this->warnings = [];
        $blueprint = ['$schema' => './blueprint-schema.json'];

        $blueprint['preferredVersions'] = [
            'php'    => $recipe->phpVersion,
            'moodle' => $this->convertMoodleTag($recipe->moodleTag),
        ];

        $steps = [];

        $steps[] = $this->buildInstallMoodleStep($recipe);

        if ($recipe->config->admin !== AbstractModel::UNSET) {
            $steps[] = ['step' => 'login', 'username' => $recipe->config->admin];
        }

        foreach ($this->buildPluginSteps($recipe) as $step) {
            $steps[] = $step;
        }

        if ($recipe->config->theme !== AbstractModel::UNSET) {
            $steps[] = ['step' => 'setTheme', 'name' => $recipe->config->theme];
        }

        if ($snapshotUrl !== null) {
            array_unshift($steps, ['step' => 'restoreDatabase', 'url' => $snapshotUrl]);
        }

        $blueprint['steps'] = $steps;

        if ($recipe->playgroundLandingPage !== null) {
            if (!str_starts_with($recipe->playgroundLandingPage, '/')) {
                throw new \InvalidArgumentException(
                    "playgroundLandingPage must start with '/' — got: {$recipe->playgroundLandingPage}"
                );
            }
            $blueprint['landingPage'] = $recipe->playgroundLandingPage;
        }

        return $blueprint;
    }

    /**
     * This turned out to be a real pain.
     *
     * Map a mchef moodleTag to the value expected by preferredVersions.moodle
     * in a Moodle Playground blueprint.
     *
     * Moodle Playground serves pre-built bundles — both the Moodle PHP source
     * and the SQLite install snapshot are compiled from a fixed gitRef at CI
     * build time. A blueprint can only SELECT which pre-built bundle to boot;
     * it cannot request an arbitrary commit or tag.
     * 
     * TODO: fix this somehow :-)
     *
     * The resolver in moodle-playground (src/shared/version-resolver.js)
     * accepts two input forms:
     *
     *   1. Branch name — passed through as-is if it matches a known entry:
     *        MOODLE_404_STABLE  ->  Moodle 4.4.x  (gitRef: branch head)
     *        MOODLE_405_STABLE  ->  Moodle 4.5.x LTS  (gitRef: branch head)
     *        MOODLE_500_STABLE  ->  Moodle 5.0.x  (gitRef: branch head) [DEFAULT]
     *        MOODLE_501_STABLE  ->  Moodle 5.1.x  (gitRef: branch head)
     *        MOODLE_502_STABLE  ->  Moodle 5.2.x  (gitRef: v5.2.0, exact tag)
     *        main               ->  Moodle 5.3dev  (gitRef: main branch head)
     *
     *   2. Version string — major.minor only (e.g. "5.0", "4.4"):
     *        "4.4"  ->  MOODLE_404_STABLE
     *        "4.5"  ->  MOODLE_405_STABLE
     *        "5.0"  ->  MOODLE_500_STABLE
     *        "5.1"  ->  MOODLE_501_STABLE
     *        "5.2"  ->  MOODLE_502_STABLE
     *        "dev"  ->  main
     *
     * Version tags from mchef (e.g. v4.5.2, v5.0.0) are reduced to major.minor
     * so the resolver can match them. If the resulting version is not in the
     * supported list above (e.g. v4.1.0 -> "4.1", which has no entry),
     * resolveMoodleBranch() returns null and the playground falls back to its
     * default branch (currently MOODLE_500_STABLE / Moodle 5.0.x).
     *
     * Note on version fidelity: for branch-tracked gitRefs the deployed code
     * reflects the branch HEAD at the time the playground CI last ran — not
     * necessarily a specific patch release. MOODLE_502_STABLE is the exception:
     * its gitRef is pinned to the exact tag v5.2.0, so code and snapshot are
     * both deterministic.
     *
     * The match is case-sensitive — branch names must use the canonical casing
     * shown above, matching the playground resolver's strict equality checks.
     */
    private function convertMoodleTag(string $tag): string {
        if (preg_match('/^(MOODLE_\d+_STABLE|main|dev)$/', $tag)) {
            return $tag;
        }
        $tag = ltrim($tag, 'vV');
        $parts = explode('.', $tag);
        return ($parts[0] ?? '5') . '.' . ($parts[1] ?? '0');
    }

    private function buildInstallMoodleStep(Recipe $recipe): array {
        $options = [];

        if ($recipe->config->admin !== 'admin' && $recipe->config->admin !== AbstractModel::UNSET) {
            $options['adminUser'] = $recipe->config->admin;
        }
        if ($recipe->adminPassword !== null && $recipe->adminPassword !== '') {
            $options['adminPass'] = $recipe->adminPassword;
        }
        if ($recipe->config->lang !== AbstractModel::UNSET) {
            $options['locale'] = $recipe->config->lang;
        }
        if ($recipe->config->timezone !== AbstractModel::UNSET) {
            $options['timezone'] = $recipe->config->timezone;
        }

        $step = ['step' => 'installMoodle'];
        if (!empty($options)) {
            $step['options'] = $options;
        }
        return $step;
    }

    /**
     * Convert recipe plugins to installMoodlePlugin blueprint steps.
     *
     * GitHub HTTPS and SSH URLs are supported via githubToDownloadZipUrl.
     * Non-GitHub URLs are recorded as warnings (see getWarnings()) and skipped.
     * TODO: Add private/self-hosted repo support via base64 bundling.
     */
    private function buildPluginSteps(Recipe $recipe): array {
        if (empty($recipe->plugins)) {
            return [];
        }

        $steps = [];
        foreach ($recipe->plugins as $plugin) {
            $zipUrl = $this->convertPluginToZipUrl($plugin);
            if ($zipUrl === null) {
                $url = is_string($plugin) ? $plugin : $plugin->repo;
                $this->warnings[] = "Plugin skipped (non-GitHub URL): $url";
                continue;
            }
            if (strpos($plugin->repo, 'theme_')) {
                // TODO - actually inspect the repo to see if it's a theme or not.
                $steps[] = ['step' => 'installTheme', 'url' => $zipUrl];
            } else {
                $steps[] = ['step' => 'installMoodlePlugin', 'url' => $zipUrl];
            }
        }
        return $steps;
    }

    /**
     * Convert a plugin entry (string URL or RecipePlugin) to a GitHub archive ZIP URL.
     *
     * String entries support a ~branch suffix (e.g. "https://github.com/org/repo~dev")
     * matching the syntax used by Plugins::extractRepoInfoFromPlugin().
     *
     * Returns null for non-GitHub URLs — caller records a warning and skips.
     *
     * TODO: Add private/self-hosted repo support via base64 bundling.
     */
    private function convertPluginToZipUrl(RecipePlugin|string $plugin): ?string {
        if (is_string($plugin)) {
            if (str_contains($plugin, '~')) {
                [$repo, $branch] = explode('~', $plugin, 2);
            } else {
                $repo = $plugin;
                $branch = 'main';
            }
        } else {
            $repo = $plugin->repo;
            $branch = !empty($plugin->branch) ? $plugin->branch : 'main';
        }

        try {
            return $this->github->githubToDownloadZipUrl($repo, $branch);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
