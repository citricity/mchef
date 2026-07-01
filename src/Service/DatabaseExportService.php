<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Model\Recipe;
use App\Traits\ExecTrait;

final class DatabaseExportService extends AbstractService {

    use ExecTrait;

    final public static function instance(bool $reset = false): DatabaseExportService {
        return self::setup_singleton($reset);
    }

    /**
     * Export the live MySQL Moodle database from the running Docker container to SQLite.
     *
     * Copies patch files from $patchDir into the container at /tmp/playground-export/,
     * runs migrate-to-sqlite.php inside the container, then copies the result back to
     * $patchDir/export.sq3 on the host.
     *
     * Returns the host-side path to the produced export.sq3 file.
     */
    public function exportToSqlite(Recipe $recipe, string $patchDir): string {
        if (empty($recipe->containerPrefix)) {
            throw new CliRuntimeException(
                "Cannot export database: recipe has no containerPrefix. " .
                "Ensure the recipe file sets 'containerPrefix'."
            );
        }

        $container  = escapeshellarg($recipe->containerPrefix . '-moodle');
        $remotePath = '/tmp/playground-export';
        $outPath    = $patchDir . '/export.sq3';

        $this->exec(
            "docker exec $container mkdir -p " . escapeshellarg($remotePath),
            "Failed to create export directory in container"
        );

        $this->exec(
            "docker cp " . escapeshellarg($patchDir . '/.') . " $container:" . escapeshellarg($remotePath),
            "Failed to copy patch files into container"
        );

        $this->execStream(
            "docker exec $container php " .
            escapeshellarg($remotePath . '/migrate-to-sqlite.php') . ' ' .
            escapeshellarg('/var/www/html') . ' ' .
            escapeshellarg($remotePath . '/export.sq3')
        );

        $this->exec(
            "docker cp $container:" . escapeshellarg($remotePath . '/export.sq3') . ' ' . escapeshellarg($outPath),
            "Failed to copy export.sq3 from container"
        );

        if (!is_file($outPath)) {
            throw new CliRuntimeException("Migration did not produce export.sq3 at: $outPath");
        }

        return $outPath;
    }
}
