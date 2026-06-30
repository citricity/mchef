<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Exceptions\ExecFailed;
use App\Model\GlobalConfig;
use App\Traits\ExecTrait;

class PlaygroundUrlsService extends AbstractService {

    use ExecTrait;

    private Configurator $configuratorService;

    final public static function instance(bool $reset = false): PlaygroundUrlsService {
        return self::setup_singleton($reset);
    }

    /**
     * Publish a blueprint to the user's configured mchef-urls repo.
     *
     * Writes blueprints/<name>.json and links/<name>.html, commits, pushes,
     * and returns the short URL.
     */
    public function publish(string $blueprintJson, string $name): string {
        $config = $this->getValidatedConfig();

        $repoPath = $this->ensureRepoReady($config->playgroundUrlsRepo);

        foreach (['blueprints', 'links'] as $dir) {
            if (!is_dir($repoPath . '/' . $dir)) {
                mkdir($repoPath . '/' . $dir, 0755, true);
            }
        }

        $blueprintUrl  = rtrim($config->playgroundUrlsBase, '/') . '/blueprints/' . $name . '.json';
        $targetUrl     = 'https://moodle-playground.com/?blueprint-url=' . $blueprintUrl;
        $blueprintPath = $repoPath . '/blueprints/' . $name . '.json';
        $htmlPath      = $repoPath . '/links/' . $name . '.html';

        if (file_put_contents($blueprintPath, $blueprintJson . PHP_EOL) === false) {
            throw new CliRuntimeException("Failed to write blueprint file: $blueprintPath");
        }
        if (file_put_contents($htmlPath, $this->buildRedirectHtml($targetUrl)) === false) {
            throw new CliRuntimeException("Failed to write redirect HTML: $htmlPath");
        }

        $this->gitCommitAndPush($repoPath, $name);

        return $this->buildShortUrl($config->playgroundUrlsBase, $name);
    }

    private function getValidatedConfig(): GlobalConfig {
        $config = $this->configuratorService->getMainConfig();

        if (empty($config->playgroundUrlsRepo)) {
            throw new CliRuntimeException(
                "Playground URLs repo not configured.\nRun: mchef config --playground-urls-repo=<git-clone-url>"
            );
        }
        if (empty($config->playgroundUrlsBase)) {
            throw new CliRuntimeException(
                "Playground URLs base URL not configured.\nRun: mchef config --playground-urls-base=<github-pages-url>"
            );
        }

        return $config;
    }

    private function ensureRepoReady(string $repoUrl): string {
        $cacheDir = $this->configuratorService->configDir() . '/playground-urls-cache';

        if (is_dir($cacheDir . '/.git')) {
            try {
                $this->execGit($cacheDir, ['pull', '--ff-only']);
            } catch (ExecFailed $e) {
                // Cache has diverged from remote (interrupted run, force-push, etc.). Re-clone to recover.
                $this->exec('rm -rf ' . escapeshellarg($cacheDir), 'Failed to remove stale cache directory', true);
                $this->execGit(null, ['clone', $repoUrl, $cacheDir]);
            }
        } else {
            $this->execGit(null, ['clone', $repoUrl, $cacheDir]);
        }

        return $cacheDir;
    }

    private function gitCommitAndPush(string $repoPath, string $name): void {
        $this->execGit($repoPath, ['add', 'blueprints/' . $name . '.json', 'links/' . $name . '.html']);

        if (trim($this->execGit($repoPath, ['diff', '--cached', '--name-only'])) === '') {
            return; // Already up to date — nothing to commit
        }

        $this->execGit($repoPath, ['commit', '-m', "Add blueprint: $name"]);
        $this->execGit($repoPath, ['push']);
    }

    /**
     * Run a git command, optionally in a working directory.
     * Marked protected so tests can stub it out.
     */
    protected function execGit(?string $cwd, array $args): string {
        $parts = ['git'];
        if ($cwd !== null) {
            $parts[] = '-C';
            $parts[] = escapeshellarg($cwd);
        }
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        $cmd = implode(' ', $parts);

        return $this->exec($cmd, 'Git command failed: git ' . implode(' ', $args), true);
    }

    private function buildRedirectHtml(string $targetUrl): string {
        $htmlUrl = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
        $jsUrl   = json_encode($targetUrl);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Opening Moodle Playground…</title>
            <meta http-equiv="refresh" content="0; url={$htmlUrl}">
            <script>location.replace({$jsUrl});</script>
        </head>
        <body>
            <p><a href="{$htmlUrl}">Open Moodle Playground</a></p>
        </body>
        </html>
        HTML;
    }

    private function buildShortUrl(string $base, string $name): string {
        return rtrim($base, '/') . '/links/' . $name;
    }
}
