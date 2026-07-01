<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Exceptions\ExecFailed;
use App\Model\GlobalConfig;
use App\Traits\ExecTrait;
use InvalidArgumentException;

class PlaygroundUrlsService extends AbstractService {

    use ExecTrait;

    private Configurator $configuratorService;
    private Github $github;
    private Main $mainService;

    /** Cached repo path so ensureRepoReady() is not called twice in the same invocation. */
    private ?string $cachedRepoPath = null;

    /** Cached default branch of the currently-cloned repo (resolved once per invocation). */
    private ?string $cachedBranch = null;

    final public static function instance(bool $reset = false): PlaygroundUrlsService {
        return self::setup_singleton($reset);
    }

    /**
     * Publish a blueprint to the user's configured mchef-urls repo.
     *
     * Writes blueprints/<name>.json (fetched programmatically via raw.githubusercontent.com,
     * never navigated to directly) and links/<sha1-of-target-url>.txt (the tiny redirect
     * target, also fetched via raw.githubusercontent.com by the shell page's client-side
     * script). Commits and pushes — picking up anything already staged by stageSnapshot()
     * earlier in the same command run — and returns a short URL that routes through the
     * repo's single unchanging index.html rather than a freshly-written page, so it works
     * within seconds of the push instead of waiting on a GitHub Pages rebuild.
     */
    public function publish(string $blueprintJson, string $name): string {
        $config = $this->getValidatedConfig();

        $repoPath = $this->ensureRepoReady($config->playgroundUrlsRepo);

        foreach (['blueprints', 'links'] as $dir) {
            if (!is_dir($repoPath . '/' . $dir)) {
                mkdir($repoPath . '/' . $dir, 0755, true);
            }
        }

        $blueprintUrl = $this->buildRawUrl($config->playgroundUrlsRepo, 'blueprints/' . $name . '.json');
        $targetUrl    = 'https://moodle-playground.com/?blueprint-url=' . $blueprintUrl;
        $linkHash     = sha1($targetUrl);

        $blueprintPath = $repoPath . '/blueprints/' . $name . '.json';
        $linkPath      = $repoPath . '/links/' . $linkHash . '.txt';

        if (file_put_contents($blueprintPath, $blueprintJson . PHP_EOL) === false) {
            throw new CliRuntimeException("Failed to write blueprint file: $blueprintPath");
        }
        if (file_put_contents($linkPath, $targetUrl) === false) {
            throw new CliRuntimeException("Failed to write redirect link: $linkPath");
        }

        $stagedFiles = array_merge(
            $this->writeShellPageIfMissing($repoPath),
            ['blueprints/' . $name . '.json', 'links/' . $linkHash . '.txt']
        );
        $this->execGit($repoPath, array_merge(['add'], $stagedFiles));
        $this->commitAndPushIfStaged($repoPath, "Add blueprint: $name");

        return rtrim($config->playgroundUrlsBase, '/') . '/index.html?linkHash=' . $linkHash;
    }

    /**
     * Stage a snapshot (.sq3 + optional localcache.zip) in the mchef-urls repo without
     * committing. Use this when a blueprint publish will follow immediately so both are
     * batched into a single git push by publish().
     *
     * Returns the raw.githubusercontent.com URL the .sq3 will be reachable at once
     * committed — fetched programmatically by moodle-playground's restoreDatabase step,
     * never navigated to directly, so it's available as soon as the push completes
     * rather than waiting on a GitHub Pages rebuild.
     */
    public function stageSnapshot(string $slug, string $sq3Path, ?string $localcachePath = null): string {
        $config   = $this->getValidatedConfig();
        $repoPath = $this->ensureRepoReady($config->playgroundUrlsRepo);

        $dataDir = $repoPath . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $destSq3 = $dataDir . '/' . $slug . '.sq3';
        if (copy($sq3Path, $destSq3) === false) {
            throw new CliRuntimeException("Failed to copy snapshot to: $destSq3");
        }

        $stagedFiles = array_merge(
            $this->writeShellPageIfMissing($repoPath),
            ['data/' . $slug . '.sq3']
        );

        if ($localcachePath !== null) {
            $destCache = $dataDir . '/' . $slug . '-localcache.zip';
            if (copy($localcachePath, $destCache) === false) {
                throw new CliRuntimeException("Failed to copy localcache to: $destCache");
            }
            $stagedFiles[] = 'data/' . $slug . '-localcache.zip';
        }

        $this->execGit($repoPath, array_merge(['add'], $stagedFiles));

        return $this->buildRawUrl($config->playgroundUrlsRepo, 'data/' . $slug . '.sq3');
    }

    /**
     * Upload a snapshot (.sq3 + optional localcache.zip) to the mchef-urls repo and push.
     *
     * Returns the public URL of the uploaded .sq3 file so it can be referenced
     * in a restoreDatabase blueprint step.
     */
    public function publishSnapshot(string $slug, string $sq3Path, ?string $localcachePath = null): string {
        $config   = $this->getValidatedConfig();
        $repoPath = $this->ensureRepoReady($config->playgroundUrlsRepo);

        $url = $this->stageSnapshot($slug, $sq3Path, $localcachePath);

        $this->commitAndPushIfStaged($repoPath, "Add snapshot: $slug");

        return $url;
    }

    /**
     * Discard anything staged-but-not-committed in the cloned repo. Best-effort recovery
     * for callers that stage a snapshot (stageSnapshot()) and then fail before the
     * follow-up publish() commits it — leaves the local cache clean instead of dirty for
     * the next run. A no-op if no repo has been cloned yet in this invocation.
     */
    public function discardStaged(): void {
        if ($this->cachedRepoPath === null) {
            return;
        }
        $this->execGit($this->cachedRepoPath, ['reset']);
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
        if ($this->cachedRepoPath !== null) {
            return $this->cachedRepoPath;
        }

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

        $this->cachedRepoPath = $cacheDir;
        $this->cachedBranch   = $this->resolveDefaultBranch($cacheDir);
        return $cacheDir;
    }

    /**
     * Resolve the repo's actual default branch (e.g. "main", "master") rather than
     * assuming "main" — forks predating GitHub's rename, or repos configured otherwise,
     * would silently 404 every published link if we guessed wrong. Falls back to "main"
     * if detection fails for any reason (fresh empty repo, detached HEAD, etc).
     */
    private function resolveDefaultBranch(string $repoPath): string {
        try {
            $ref = trim($this->execGit($repoPath, ['symbolic-ref', 'refs/remotes/origin/HEAD']));
        } catch (ExecFailed $e) {
            return 'main';
        }
        $branch = preg_replace('#^refs/remotes/origin/#', '', $ref);
        return $branch !== '' ? $branch : 'main';
    }

    /**
     * Write the shared redirect shell page if this repo doesn't already have one.
     * Returns the list of relative paths that need staging (empty if already present) —
     * callers merge this into their own staged-files list so the bootstrap commit rides
     * along with the first real publish rather than needing a separate push.
     */
    private function writeShellPageIfMissing(string $repoPath): array {
        $path = $repoPath . '/index.html';
        if (is_file($path)) {
            return [];
        }
        $html = $this->mainService->twig->render('github/redirectShell.html.twig', [
            'branch' => $this->cachedBranch ?? 'main',
        ]);
        if (file_put_contents($path, $html) === false) {
            throw new CliRuntimeException("Failed to write redirect shell page: $path");
        }
        return ['index.html'];
    }

    /**
     * Build a raw.githubusercontent.com URL for a path inside the configured repo, on
     * its actual default branch. Used for anything fetched programmatically (blueprint
     * JSON, snapshots) rather than navigated to directly — bypasses GitHub Pages entirely.
     */
    private function buildRawUrl(string $repoUrl, string $path): string {
        try {
            $base = $this->github->githubToRawBaseUrl($repoUrl);
        } catch (InvalidArgumentException $e) {
            throw new CliRuntimeException(
                "playgroundUrlsRepo is not a valid GitHub repository URL: {$repoUrl}\n" .
                "Run: mchef config --playground-urls-repo=<git-clone-url>"
            );
        }
        $branch = $this->cachedBranch ?? 'main';
        return $base . '/' . $branch . '/' . ltrim($path, '/');
    }

    /**
     * Commit and push whatever is currently staged, if anything is. Shared by publish()
     * and publishSnapshot() — both stage files earlier via git add, then reach this same
     * "is there anything to commit, and if so push it" sequence.
     */
    private function commitAndPushIfStaged(string $repoPath, string $message): void {
        if (trim($this->execGit($repoPath, ['diff', '--cached', '--name-only'])) === '') {
            return;
        }
        $this->execGit($repoPath, ['commit', '-m', $message]);
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
}
