<?php

namespace App\Service;
use App\Helpers\OS;
use App\StaticVars;

/**
 * Service facilitating CLI but NOT the CLI (See MChefCLI for the actual CLI)
 */
class CliService extends AbstractService {

    final public static function instance(): CliService {
        return self::setup_singleton();
    }

    public function locateCommandClass(string $command): ?string {
        $commandDir = OS::path(__DIR__.'/../Command');
        $files = scandir($commandDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'AbstractCommand.php') {
                continue;
            }
            $baseName = pathinfo($file, PATHINFO_FILENAME);
            $class = "App\\Command\\".$baseName;
            $cmdName = $class::COMMAND_NAME;
            if ($cmdName === $command) {
                return $class;
            }
        }
        return null;
    }

    public function openSite(string $url): void {
        $cmd = '';
        if (StaticVars::$ciMode) {
            $this->cli->notice("CI mode detected - cannot open browser automatically. Please open the following URL in your browser: $url");
            return;
        }
        if (OS::isWindows()) {
            $cmd = "start $url";
        } elseif (OS::isMac()) {
            $cmd = "open $url";
        } elseif (OS::isLinux()) {
            $cmd = "xdg-open $url";
        } else {
            $this->cli->warning("Cannot determine OS to open the site automatically. Please open the following URL in your browser: $url");
            return;
        }

        if (!empty($cmd)) {
            exec($cmd);
        }
    }
}
