<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Model\Recipe;
use App\StaticVars;
use App\Traits\ExecTrait;

final class PlaygroundSnapshotService extends AbstractService {

    use ExecTrait;

    private Configurator $configuratorService;
    private PlaygroundUrlsService $playgroundUrlsService;
    private DatabaseExportService $databaseExportService;

    final public static function instance(bool $reset = false): PlaygroundSnapshotService {
        return self::setup_singleton($reset);
    }

    /**
     * Resolve the absolute path to the local moodle-playground checkout.
     * Returns null if neither the config value nor the default resolves to a real directory.
     */
    public function resolvePlaygroundPath(): ?string {
        $configured = $this->configuratorService->getMainConfig()->playgroundLocalPath;
        if ($configured !== null) {
            return is_dir($configured) ? $configured : null;
        }
        // __DIR__ is src/Service/ — three levels up reaches the playground/ parent
        $fallback = realpath(__DIR__ . '/../../../moodle-playground');
        return ($fallback !== false && is_dir($fallback)) ? $fallback : null;
    }

    /**
     * Map a moodleTag to the channel name used as the manifest filename.
     * e.g. "MOODLE_500_STABLE" -> "MOODLE_500_STABLE"
     *      "v5.0.8"            -> "MOODLE_500_STABLE"
     *      "main"              -> "main"
     * Returns null when the tag format is unrecognised.
     */
    public function deriveChannel(string $moodleTag): ?string {
        if (preg_match('/^(MOODLE_\d+_STABLE|main|dev)$/', $moodleTag)) {
            return $moodleTag;
        }
        if (preg_match('/^v?(\d+)\.(\d+)/', $moodleTag, $m)) {
            $num = (int)$m[1] * 100 + (int)$m[2];
            return 'MOODLE_' . $num . '_STABLE';
        }
        return null;
    }

    /**
     * Run build-moodle-bundle.sh for the given moodleTag.
     * Streams output live. Called when the version-guard prompt is accepted.
     */
    public function buildBundle(string $moodleTag): void {
        $playgroundPath = $this->resolvePlaygroundPath();
        if ($playgroundPath === null) {
            throw new CliRuntimeException(
                "moodle-playground not found. Configure it with: mchef config --playground-path=<path>"
            );
        }

        $channel = $this->deriveChannel($moodleTag);
        if ($channel === null) {
            throw new CliRuntimeException("Cannot derive a build channel from moodleTag: $moodleTag");
        }

        $gitRef = preg_match('/^v\d+\.\d+/', $moodleTag) ? $moodleTag : '';

        $script = escapeshellarg($playgroundPath . '/scripts/build-moodle-bundle.sh');
        $env    = 'BRANCH=' . escapeshellarg($channel);
        if ($gitRef !== '') {
            $env .= ' GIT_REF=' . escapeshellarg($gitRef);
        }
        $this->execStream("$env $script");
    }

    /**
     * Build and publish a snapshot for the given recipe. Returns the public sq3 URL.
     *
     * Mode is determined automatically:
     *   - Instance mode (StaticVars::$instance is set): live export from running container
     *   - Recipe-file mode: fresh snapshot via moodle-playground build scripts
     *
     * When $stageOnly is true the snapshot files are staged in the mchef-urls repo but
     * not committed — the caller is expected to call PlaygroundUrlsService::publish()
     * immediately after, which will commit the snapshot and blueprint in a single push.
     */
    public function buildAndPublish(Recipe $recipe, string $slug, bool $stageOnly = false): string {
        if (StaticVars::$instance !== null) {
            return $this->liveSnapshot($recipe, $slug, $stageOnly);
        }
        return $this->freshSnapshot($recipe, $slug, $stageOnly);
    }

    /**
     * Build a clean install snapshot at the recipe's Moodle version using
     * moodle-playground's scripts, then upload to mchef-urls.
     */
    private function freshSnapshot(Recipe $recipe, string $slug, bool $stageOnly = false): string {
        $playgroundPath = $this->requirePlaygroundPath();
        $channel        = $this->requireChannel($recipe->moodleTag);

        $gitRef = preg_match('/^v\d+\.\d+/', $recipe->moodleTag) ? $recipe->moodleTag : '';

        $outDir = sys_get_temp_dir() . '/mchef-snapshot-' . uniqid('', true);
        mkdir($outDir, 0755, true);

        try {
            $fetchScript = escapeshellarg($playgroundPath . '/scripts/fetch-moodle-source.sh');
            $env = 'BRANCH=' . escapeshellarg($channel);
            if ($gitRef !== '') {
                $env .= ' GIT_REF=' . escapeshellarg($gitRef);
            }
            $sourceDir = trim($this->exec("$env $fetchScript " . escapeshellarg($channel)));

            $patchScript = escapeshellarg($playgroundPath . '/scripts/patch-moodle-source.sh');
            $this->exec("$patchScript " . escapeshellarg($sourceDir) . ' ' . escapeshellarg($channel));

            $snapshotScript = escapeshellarg($playgroundPath . '/scripts/generate-install-snapshot.sh');
            $this->execStream("$snapshotScript " . escapeshellarg($sourceDir) . ' ' . escapeshellarg($outDir));

            $sq3Path        = $outDir . '/install.sq3';
            $localcachePath = is_file($outDir . '/localcache.zip') ? $outDir . '/localcache.zip' : null;

            if (!is_file($sq3Path)) {
                throw new CliRuntimeException("generate-install-snapshot.sh did not produce install.sq3");
            }

            return $stageOnly
                ? $this->playgroundUrlsService->stageSnapshot($slug, $sq3Path, $localcachePath)
                : $this->playgroundUrlsService->publishSnapshot($slug, $sq3Path, $localcachePath);
        } finally {
            $this->exec('rm -rf ' . escapeshellarg($outDir), null, true);
        }
    }

    /**
     * Export the live MySQL database from the running mchef container to SQLite,
     * run post-processing via generate-install-snapshot.sh, then upload to mchef-urls.
     */
    private function liveSnapshot(Recipe $recipe, string $slug, bool $stageOnly = false): string {
        $playgroundPath = $this->requirePlaygroundPath();
        $channel        = $this->requireChannel($recipe->moodleTag);
        $gitRef         = preg_match('/^v\d+\.\d+/', $recipe->moodleTag) ? $recipe->moodleTag : '';

        $patchDir = sys_get_temp_dir() . '/mchef-export-' . uniqid('', true);
        mkdir($patchDir, 0755, true);
        $outDir = sys_get_temp_dir() . '/mchef-snapshot-' . uniqid('', true);
        mkdir($outDir, 0755, true);

        try {
            $patchesShared = $playgroundPath . '/patches/shared';
            $filesToCopy   = [
                $patchesShared . '/lib/dml/sqlite3_pdo_moodle_database.php' => $patchDir . '/sqlite3_pdo_moodle_database.php',
                $patchesShared . '/lib/ddl/sqlite_sql_generator.php'        => $patchDir . '/sqlite_sql_generator.php',
                $patchesShared . '/lib/classes/encryption.php'              => $patchDir . '/encryption.php',
                __DIR__ . '/../Scripts/migrate-to-sqlite.php'               => $patchDir . '/migrate-to-sqlite.php',
            ];
            foreach ($filesToCopy as $src => $dst) {
                if (copy($src, $dst) === false) {
                    throw new CliRuntimeException("Failed to copy patch file: $src");
                }
            }

            $exportSq3 = $this->databaseExportService->exportToSqlite($recipe, $patchDir);

            $fetchScript = escapeshellarg($playgroundPath . '/scripts/fetch-moodle-source.sh');
            $env = 'BRANCH=' . escapeshellarg($channel);
            if ($gitRef !== '') {
                $env .= ' GIT_REF=' . escapeshellarg($gitRef);
            }
            $sourceDir = trim($this->exec("$env $fetchScript " . escapeshellarg($channel)));

            $patchScript = escapeshellarg($playgroundPath . '/scripts/patch-moodle-source.sh');
            $this->exec("$patchScript " . escapeshellarg($sourceDir) . ' ' . escapeshellarg($channel));

            $snapshotScript = escapeshellarg($playgroundPath . '/scripts/generate-install-snapshot.sh');
            $env = 'PREBUILT_DB_PATH=' . escapeshellarg($exportSq3);
            $this->execStream(
                "$env $snapshotScript " . escapeshellarg($sourceDir) . ' ' . escapeshellarg($outDir)
            );

            $sq3Path        = $outDir . '/install.sq3';
            $localcachePath = is_file($outDir . '/localcache.zip') ? $outDir . '/localcache.zip' : null;

            if (!is_file($sq3Path)) {
                throw new CliRuntimeException("Post-processing did not produce install.sq3");
            }

            return $stageOnly
                ? $this->playgroundUrlsService->stageSnapshot($slug, $sq3Path, $localcachePath)
                : $this->playgroundUrlsService->publishSnapshot($slug, $sq3Path, $localcachePath);
        } finally {
            $this->exec('rm -rf ' . escapeshellarg($patchDir), null, true);
            $this->exec('rm -rf ' . escapeshellarg($outDir), null, true);
        }
    }

    private function requirePlaygroundPath(): string {
        $path = $this->resolvePlaygroundPath();
        if ($path === null) {
            throw new CliRuntimeException(
                "moodle-playground not found. Configure it with: mchef config --playground-path=<path>"
            );
        }
        return $path;
    }

    private function requireChannel(string $moodleTag): string {
        $channel = $this->deriveChannel($moodleTag);
        if ($channel === null) {
            throw new CliRuntimeException(
                "Cannot derive a Moodle channel from moodleTag: $moodleTag"
            );
        }
        return $channel;
    }
}
