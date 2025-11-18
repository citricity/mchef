<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Helpers\OS;
use Exception;
use InvalidArgumentException;

class Git extends AbstractService {

    private File $fileService;

    final public static function instance(): Git {
        return self::setup_singleton();
    }

    private function enforceIsGitRepository(string $path) {
        try {
            $gitDir = OS::realPath($path . '/.git');
            if (!is_dir($gitDir)) {
                throw new CliRuntimeException("Directory is not a git repository: {$path}");
            }
        } catch (\RuntimeException $e) {
            // OS::realPath throws RuntimeException if path doesn't exist
            throw new CliRuntimeException("Directory is not a git repository: {$path}");
        }
    }

    /**
     * Check out a specific branch or tag in a specific folder.
     * 
     * This method will:
     * 1. Check if it's a tag and check it out if it exists
     * 2. Check if the branch exists locally and check it out if it does
     * 3. If not local, check if the branch exists remotely
     * 4. If remote branch exists, fetch and check it out
     * 5. If neither branch nor tag exists, throw an error
     *
     * @param string $repositoryPath The path to the git repository
     * @param string $branchOrTagName The branch or tag name to check out
     * @param string $remoteName The remote name (default: 'origin')
     * @throws CliRuntimeException
     */
    public function checkoutBranchOrTag(string $repositoryPath, string $branchOrTagName, string $remoteName = 'origin'): void {
        // Validate that the directory exists and is a git repository
        if (!is_dir($repositoryPath)) {
            throw new CliRuntimeException("Directory does not exist: {$repositoryPath}");
        }
        
        $this->enforceIsGitRepository($repositoryPath);

        // Check if it's a tag first (local)
        if ($this->tagExists($repositoryPath, $branchOrTagName)) {
            $this->cli->info("Tag '{$branchOrTagName}' exists locally, checking out...");
            $this->checkoutTag($repositoryPath, $branchOrTagName);
            return;
        }

        // Check if it's a remote tag
        if ($this->tagExistsRemotely($repositoryPath, $branchOrTagName, $remoteName)) {
            $this->cli->info("Tag '{$branchOrTagName}' exists remotely, fetching and checking out...");
            $this->fetchAndCheckoutRemoteTag($repositoryPath, $branchOrTagName, $remoteName);
            return;
        }

        // Check if branch exists locally
        if ($this->branchExistsLocally($repositoryPath, $branchOrTagName)) {
            $this->cli->info("Branch '{$branchOrTagName}' exists locally, checking out...");
            $this->checkoutLocalBranch($repositoryPath, $branchOrTagName);
            return;
        }

        // Check if branch exists remotely
        if ($this->branchExistsRemotely($repositoryPath, $branchOrTagName, $remoteName)) {
            $this->cli->info("Branch '{$branchOrTagName}' exists remotely, fetching and checking out...");
            $this->fetchAndCheckoutRemoteBranch($repositoryPath, $branchOrTagName, $remoteName);
            return;
        }

        // Neither branch nor tag exists locally or remotely
        throw new CliRuntimeException("Branch or tag '{$branchOrTagName}' does not exist locally or on remote '{$remoteName}'");
    }

    /**
     * Check if a tag exists locally.
     *
     * @param string $repositoryPath
     * @param string $tagName
     * @return bool
     */
    private function tagExists(string $repositoryPath, string $tagName): bool {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git tag --list " . escapeshellarg($tagName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            return false;
        }
        
        // Check if output contains the tag name
        foreach ($output as $line) {
            if (trim($line) === $tagName) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check out a tag.
     *
     * @param string $repositoryPath
     * @param string $tagName
     * @throws CliRuntimeException
     */
    private function checkoutTag(string $repositoryPath, string $tagName): void {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git checkout " . escapeshellarg($tagName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to checkout tag '{$tagName}': " . implode("\n", $output));
        }
        
        $this->cli->success("Successfully checked out tag '{$tagName}'");
    }

    /**
     * Check if a tag exists on the remote repository.
     *
     * @param string $repositoryPath
     * @param string $tagName
     * @param string $remoteName
     * @return bool
     */
    private function tagExistsRemotely(string $repositoryPath, string $tagName, string $remoteName = 'origin'): bool {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git ls-remote --tags " . escapeshellarg($remoteName) . " " . escapeshellarg($tagName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            return false;
        }
        
        // Check if any output contains the tag (could be multiple lines for annotated tags)
        return !empty($output);
    }

    /**
     * Fetch and check out a remote tag.
     *
     * @param string $repositoryPath
     * @param string $tagName
     * @param string $remoteName
     * @throws CliRuntimeException
     */
    private function fetchAndCheckoutRemoteTag(string $repositoryPath, string $tagName, string $remoteName = 'origin'): void {
        // First, fetch the remote tags
        $fetchCmd = "cd " . escapeshellarg($repositoryPath) . " && git fetch " . escapeshellarg($remoteName) . " 'refs/tags/" . escapeshellarg($tagName) . ":refs/tags/" . escapeshellarg($tagName) . "'";
        exec($fetchCmd, $fetchOutput, $fetchReturnVar);
        
        if ($fetchReturnVar !== 0) {
            throw new CliRuntimeException("Failed to fetch tag '{$tagName}' from remote '{$remoteName}': " . implode("\n", $fetchOutput));
        }
        
        // Then, check out the tag
        $this->checkoutTag($repositoryPath, $tagName);
        
        $this->cli->success("Successfully fetched and checked out remote tag '{$tagName}' from '{$remoteName}'");
    }

    /**
     * Check if a branch exists locally.
     *
     * @param string $repositoryPath
     * @param string $branchName
     * @return bool
     */
    private function branchExistsLocally(string $repositoryPath, string $branchName): bool {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git branch --list " . escapeshellarg($branchName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            return false;
        }
        
        // Check if output contains the branch name (git branch --list returns matching branches)
        foreach ($output as $line) {
            $trimmedLine = trim($line, ' *');
            if ($trimmedLine === $branchName) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if a branch exists on the remote repository.
     *
     * @param string $repositoryPath
     * @param string $branchName
     * @param string $remoteName
     * @return bool
     */
    private function branchExistsRemotely(string $repositoryPath, string $branchName, string $remoteName = 'origin'): bool {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git ls-remote --heads " . escapeshellarg($remoteName) . " " . escapeshellarg($branchName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            return false;
        }
        
        // If output is not empty, the branch exists remotely
        return !empty($output);
    }

    /**
     * Check out a local branch.
     *
     * @param string $repositoryPath
     * @param string $branchName
     * @throws CliRuntimeException
     */
    private function checkoutLocalBranch(string $repositoryPath, string $branchName): void {
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git checkout " . escapeshellarg($branchName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to checkout local branch '{$branchName}': " . implode("\n", $output));
        }
        
        $this->cli->success("Successfully checked out local branch '{$branchName}'");
    }

    /**
     * Fetch and check out a remote branch.
     *
     * @param string $repositoryPath
     * @param string $branchName
     * @param string $remoteName
     * @throws CliRuntimeException
     */
    private function fetchAndCheckoutRemoteBranch(string $repositoryPath, string $branchName, string $remoteName = 'origin'): void {
        // First, fetch the remote branch
        $fetchCmd = "cd " . escapeshellarg($repositoryPath) . " && git fetch " . escapeshellarg($remoteName) . " " . escapeshellarg($branchName);
        exec($fetchCmd, $fetchOutput, $fetchReturnVar);
        
        if ($fetchReturnVar !== 0) {
            throw new CliRuntimeException("Failed to fetch branch '{$branchName}' from remote '{$remoteName}': " . implode("\n", $fetchOutput));
        }
        
        // Then, check out the branch (this will create a local tracking branch)
        $checkoutCmd = "cd " . escapeshellarg($repositoryPath) . " && git checkout -b " . escapeshellarg($branchName) . " " . escapeshellarg($remoteName . '/' . $branchName);
        exec($checkoutCmd, $checkoutOutput, $checkoutReturnVar);
        
        if ($checkoutReturnVar !== 0) {
            throw new CliRuntimeException("Failed to checkout remote branch '{$branchName}': " . implode("\n", $checkoutOutput));
        }
        
        $this->cli->success("Successfully fetched and checked out remote branch '{$branchName}' from '{$remoteName}'");
    }

    /**
     * Get the current branch name.
     *
     * @param string $repositoryPath
     * @return string
     * @throws CliRuntimeException
     */
    public function getCurrentBranch(string $repositoryPath): string {
        $this->enforceIsGitRepository($repositoryPath);
        
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git branch --show-current";
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0 || empty($output)) {
            throw new CliRuntimeException("Failed to get current branch name");
        }
        
        return trim($output[0]);
    }

    /**
     * Check if the repository has uncommitted changes.
     *
     * @param string $repositoryPath
     * @return bool
     * @throws CliRuntimeException
     */
    public function hasUncommittedChanges(string $repositoryPath): bool {
        $this->enforceIsGitRepository($repositoryPath);
        
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git status --porcelain";
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to check git status");
        }
        
        return !empty($output);
    }

    /**
     * Get list of available local branches.
     *
     * @param string $repositoryPath
     * @return array
     * @throws CliRuntimeException
     */
    public function getLocalBranches(string $repositoryPath): array {
        $this->enforceIsGitRepository($repositoryPath);

        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git branch --format='%(refname:short)'";
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to get local branches");
        }
        
        return array_map('trim', $output);
    }

    public function isRemoteSsh(string $repositoryPath): bool {
        return preg_match('/^(git@|ssh:\/\/)/', $repositoryPath) === 1;
    }

    /**
     * Get list of available remote branches.
     *
     * @param string $repositoryPath
     * @param string $remoteName
     * @return array
     * @throws CliRuntimeException
     */
    public function getRemoteBranches(string $repositoryPath, string $remoteName = 'origin'): array {
        $this->enforceIsGitRepository($repositoryPath);
        
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git ls-remote --heads " . escapeshellarg($remoteName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to get remote branches from '{$remoteName}'");
        }
        
        $branches = [];
        foreach ($output as $line) {
            // Remote branch output format: "hash refs/heads/branch-name"
            if (preg_match('/refs\/heads\/(.+)$/', $line, $matches)) {
                $branches[] = $matches[1];
            }
        }
        
        return $branches;
    }

    /**
     * Get list of available local tags.
     *
     * @param string $repositoryPath
     * @return array
     * @throws CliRuntimeException
     */
    public function getLocalTags(string $repositoryPath): array {
        $this->enforceIsGitRepository($repositoryPath);

        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git tag --list";
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to get local tags");
        }
        
        return array_map('trim', $output);
    }

    /**
     * Get list of available remote tags.
     *
     * @param string $repositoryPath
     * @param string $remoteName
     * @return array
     * @throws CliRuntimeException
     */
    public function getRemoteTags(string $repositoryPath, string $remoteName = 'origin'): array {
        $this->enforceIsGitRepository($repositoryPath);
        
        $cmd = "cd " . escapeshellarg($repositoryPath) . " && git ls-remote --tags " . escapeshellarg($remoteName);
        exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new CliRuntimeException("Failed to get remote tags from '{$remoteName}'");
        }
        
        $tags = [];
        foreach ($output as $line) {
            // Remote tag output format: "hash refs/tags/tag-name" or "hash refs/tags/tag-name^{}"
            if (preg_match('/refs\/tags\/([^\^]+)(\^\{\})?$/', $line, $matches)) {
                $tagName = $matches[1];
                // Avoid duplicate entries for annotated tags (which have ^{} suffix)
                if (!in_array($tagName, $tags)) {
                    $tags[] = $tagName;
                }
            }
        }
        
        return $tags;
    }

    /**
     * Check if a branch or tag exists (locally or remotely).
     *
     * @param string $repositoryPath The path to the git repository
     * @param string $branchOrTagName The branch or tag name to check
     * @param string $remoteName The remote name (default: 'origin')
     * @return bool True if the branch or tag exists, false otherwise
     * @throws CliRuntimeException
     */
    public function branchOrTagExists(string $repositoryPath, string $branchOrTagName, string $remoteName = 'origin'): bool {
        // Validate that the directory exists and is a git repository
        if (!is_dir($repositoryPath)) {
            throw new CliRuntimeException("Directory does not exist: {$repositoryPath}");
        }
        
        $this->enforceIsGitRepository($repositoryPath);

        // Check if it's a local tag
        if ($this->tagExists($repositoryPath, $branchOrTagName)) {
            return true;
        }

        // Check if it's a remote tag
        if ($this->tagExistsRemotely($repositoryPath, $branchOrTagName, $remoteName)) {
            return true;
        }

        // Check if it's a local branch
        if ($this->branchExistsLocally($repositoryPath, $branchOrTagName)) {
            return true;
        }

        // Check if it's a remote branch
        if ($this->branchExistsRemotely($repositoryPath, $branchOrTagName, $remoteName)) {
            return true;
        }

        // Neither branch nor tag exists
        return false;
    }

    /**
     * Check if a branch or tag exists on a remote repository directly (without requiring a local git repository).
     *
     * @param string $repository The repository URL to check
     * @param string $branchOrTagName The branch or tag name to check
     * @param string $remoteName The remote name (default: 'origin') - not used for direct URL checks
     * @return bool True if the branch or tag exists on remote, false otherwise
     * @throws CliRuntimeException
     */
    public function branchOrTagExistsRemotely(string $repository, string $branchOrTagName, string $remoteName = 'origin'): bool {
        // Check if it's a remote tag
        $checkTagCmd = "git ls-remote --exit-code --tags " . escapeshellarg($repository) . " " . escapeshellarg($branchOrTagName);
        exec($checkTagCmd, $tagOutput, $tagReturnVar);

        if ($tagReturnVar === 0 && !empty($tagOutput)) {
            return true;
        }

        // Check if it's a remote branch
        $checkBranchCmd = "git ls-remote --exit-code --heads " . escapeshellarg($repository) . " " . escapeshellarg($branchOrTagName);
        exec($checkBranchCmd, $branchOutput, $branchReturnVar);

        if ($branchReturnVar === 0 && !empty($branchOutput)) {
            return true;
        }

        // Neither branch nor tag exists on remote
        return false;
    }

    /**
     * Convert a GitHub repo URL to the base raw URL (removing .git and ssh syntax).
     *
     * Examples:
     *   https://github.com/moodle/moodle.git → https://raw.githubusercontent.com/moodle/moodle
     *   git@github.com:moodle/moodle.git     → https://raw.githubusercontent.com/moodle/moodle
     *
     * @param string $url Git clone URL in HTTPS or SSH format
     * @return string Raw.githubusercontent.com base
     */
    private function githubToRawBaseUrl(string $url): string {
        // Normalize SSH form: git@github.com:owner/repo(.git)
        if (preg_match('#^git@github\.com:(.+)$#', $url, $m)) {
            $url = 'https://github.com/' . $m[1];
        }

        // Extract owner + repo, drop optional .git
        if (!preg_match('#https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            throw new InvalidArgumentException("Not a valid GitHub URL: $url");
        }

        [, $owner, $repo] = $m;

        return sprintf(
            'https://raw.githubusercontent.com/%s/%s',
            $owner,
            $repo
        );
    }

    private function fetchGithubRepoSingleFileContents(string $url, string $branchOrTag, string $filePath): ?string {
        $rawBaseUrl = $this->githubToRawBaseUrl($url);
        $fullUrl = sprintf(
            '%s/%s/%s',
            $rawBaseUrl,
            rawurlencode($branchOrTag),
            ltrim($filePath, '/')
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "User-Agent: MChef\r\n", // GitHub requires a UA
                ],
                'ignore_errors' => true, // needed to read response body
                'timeout' => 10,
            ],
        ]);

        $content = @file_get_contents($fullUrl, false, $context);

        // No headers = hard failure: DNS, SSL, network down, etc.
        if (!isset($http_response_header) || empty($http_response_header)) {
            throw new CliRuntimeException("No response from GitHub when requesting: {$fullUrl}");
        }

        // Parse status line, e.g. "HTTP/1.1 200 OK"
        $statusLine = $http_response_header[0];
        preg_match('{HTTP/\S+\s(\d{3})}', $statusLine, $match);
        $status = isset($match[1]) ? (int)$match[1] : 0;

        switch ($status) {
            case 200:
                return $content; // success

            case 404:
                // File does not exist — not an error
                return null;

            case 403:
            case 429:
                throw new CliRuntimeException("GitHub rate limit or permission denied for file: {$fullUrl}");

            case 500:
            case 502:
            case 503:
            case 504:
                throw new CliRuntimeException("GitHub server error ({$status}) fetching file: {$fullUrl}");

            default:
                throw new CliRuntimeException("Unexpected HTTP {$status} fetching file: {$fullUrl}");
        }
    }


    private function fetchSingleFileFromRemote(string $repoUrl, string $branchOrTag, string $filePath): ?string {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('git_file_', true);
        mkdir($tempDir);

        try {
            // Initialize empty repo
            exec("git init --quiet " . escapeshellarg($tempDir), $_, $ret);
            if ($ret !== 0) {
                throw new CliRuntimeException("git init failed");
            }

            // Add remote
            exec("git -C " . escapeshellarg($tempDir) . " remote add origin " . escapeshellarg($repoUrl), $_, $ret);
            if ($ret !== 0) {
                throw new CliRuntimeException("git remote add failed");
            }

            // Shallow fetch with blob filter to avoid file content download
            exec(
                "git -C " . escapeshellarg($tempDir)
                . " fetch --depth=1 --filter=blob:none origin " . escapeshellarg($branchOrTag),
                $fetchOutput,
                $fetchStatus
            );

            if ($fetchStatus !== 0) {
                return null; // branch doesn't exist or network failed
            }

            // Fetch just this file blob on demand
            exec(
                "git -C " . escapeshellarg($tempDir)
                . " show FETCH_HEAD:" . escapeshellarg(ltrim($filePath, '/')),
                $fileOutput,
                $fileStatus
            );

            if ($fileStatus !== 0) {
                return null; // file not found
            }

            // Join file lines (exec strips newlines)
            return implode("\n", $fileOutput);

        } finally {
            // Always cleanup
            $this->fileService->deleteDir($tempDir);
        }
    }

    public function fetchRepoSingleFileContents(string $url, string $branchOrTag, string $filePath): ?string {
        if (strpos($url, 'github.com') !== false) {
            try {
                $contents = $this->fetchGithubRepoSingleFileContents($url, $branchOrTag, $filePath);
                return $contents;
            } catch (InvalidArgumentException $e) {
                // Not a valid GitHub URL, fall back to git fetch method
                $this->cli->info("Not a valid GitHub URL, falling back to git fetch method.");
            } catch (\Exception $e) {
                $this->cli->warning("Error fetching file via GitHub raw URL: " . $e->getMessage());
            }
        }
        return $this->fetchSingleFileFromRemote($url, $branchOrTag, $filePath);
    }

    private function extractGithubOwnerRepo(string $url): array {
        if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return [$m[1], $m[2]];
        }
        throw new InvalidArgumentException("Not a valid GitHub repo URL: {$url}");
    }

    /**
     * Check if a folder exists in a GitHub repository at a specific tag or branch
     */
    private function githubFolderExists(string $repositoryUrl, string $branchOrTag, string $folderPath): bool {
        [$owner, $repo] = $this->extractGithubOwnerRepo($repositoryUrl);

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode(trim($folderPath, '/')),
            rawurlencode($branchOrTag)
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "User-Agent: MChef\r\n", // GitHub requires a UA
                ],
                'ignore_errors' => true,
            ],
        ]);

        $json = @file_get_contents($apiUrl, false, $context);
        if ($json === false) {
            return false;
        }
                
        $jsonobj = json_decode($json, true);

        if (!empty($jsonobj['status']) && $jsonobj['status'] === "404") {
            return false;
        }
        return is_array($jsonobj);
    }

    /**
     * Check if a folder exists in a remote repository at a specific tag or branch
     */
    private function checkFolderExistsViaFetch(string $repositoryUrl, string $branchOrTag, string $folderPath) {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('git_check_', true);
        mkdir($tempDir);

        try {
            exec("git init --quiet " . escapeshellarg($tempDir));

            chdir($tempDir);

            exec("git remote add origin " . escapeshellarg($repositoryUrl));

            exec("git fetch --depth=1 --filter=blob:none origin " . escapeshellarg($branchOrTag), $output, $fetchStatus);
            if ($fetchStatus !== 0) {
                return false;
            }

            exec(
                "git ls-tree -d --name-only FETCH_HEAD " . escapeshellarg($folderPath),
                $treeOutput,
                $treeStatus
            );

            return !empty($treeOutput);

        } finally {
            $this->fileService->deleteDir($tempDir);
        }
    }

    /**
     * Check if a folder exists in a remote repository at a specific tag or branch.
     *
     * @param string $repositoryUrl The repository URL to check
     * @param string $branchOrTag The branch or tag to check
     * @param string $folderPath The folder path to check (e.g., 'public')
     * @return bool True if the folder exists, false otherwise
     * @throws CliRuntimeException
     */
    private function folderExistsInRemote(string $repositoryUrl, string $branchOrTag, string $folderPath): bool {
        if (strpos($repositoryUrl, 'github.com') !== false) {
            try {
                return $this->githubFolderExists($repositoryUrl, $branchOrTag, $folderPath);
            } catch (InvalidArgumentException $e) {
                // Not a valid GitHub URL, fall back to git fetch method
                $this->cli->info("Not a valid GitHub URL, falling back to git fetch method.");
            } catch (\Exception $e) {
                $this->cli->warning("Error checking folder via GitHub raw URL: " . $e->getMessage());
            }
        }
        
        return $this->checkFolderExistsViaFetch($repositoryUrl, $branchOrTag, $folderPath);
    }

    /**
     * Check if the 'public' folder exists in a remote Moodle repository at a specific tag.
     * This is specifically for detecting Moodle 5.1+ structure changes.
     *
     * @param string $moodleTag The Moodle version tag to check
     * @return bool True if public folder exists, false otherwise
     */
    public function moodleHasPublicFolder(string $moodleTag): bool {
        $moodleRepoUrl = 'https://github.com/moodle/moodle.git';
        
        try {
            return $this->folderExistsInRemote($moodleRepoUrl, $moodleTag, 'public');
        } catch (CliRuntimeException $e) {
            // If we can't check remotely, assume no public folder (backwards compatible)
            $this->cli->warning("Could not check for public folder in Moodle {$moodleTag}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Backward compatibility method for checkoutBranch.
     * 
     * @deprecated Use checkoutBranchOrTag instead
     * @param string $repositoryPath
     * @param string $branchName
     * @param string $remoteName
     * @throws CliRuntimeException
     */
    public function checkoutBranch(string $repositoryPath, string $branchName, string $remoteName = 'origin'): void {
        $this->checkoutBranchOrTag($repositoryPath, $branchName, $remoteName);
    }

    /**
     * Clone a git repository.
     *
     * @param string $url
     * @param string $branch
     * @param string $path
     * @param string|null $upstream
     * @throws Exception
     */
    public function cloneGithubRepository($url, $branch, $path, ?string $upstream = null) {

        if (empty($branch)) {
            $cmd = "git clone $url $path";
        } else {
            $branchOrTagExists = $this->branchOrTagExistsRemotely($url, $branch);

            // If no output, the branch doesn't exist
            if (!$branchOrTagExists) {
                throw new Exception("Branch '$branch' does not exist for repository '$url'");
            }

            $cmd = "git clone $url --branch $branch $path";
        }

        exec($cmd, $output, $returnVar);

        if ($returnVar != 0) {
            throw new Exception("Error cloning repository: " . implode("\n", $output));
        }

        // Add upstream remote if specified
        if (!empty($upstream)) {
            // Check if the upstream branch exists on the upstream repository
            $checkUpstreamBranchCmd = "git ls-remote --heads $upstream $branch";
            exec($checkUpstreamBranchCmd, $upstreamOutput, $upstreamReturnVar);

            if ($upstreamReturnVar != 0) {
                $this->cli->warning("Could not check upstream repository '$upstream': " . implode("\n", $upstreamOutput));
            } elseif (empty($upstreamOutput)) {
                $this->cli->warning("Branch '$branch' does not exist on upstream repository '$upstream'");
            } else {
                // Add upstream remote
                $addUpstreamCmd = "cd $path && git remote add upstream $upstream";
                exec($addUpstreamCmd, $upstreamAddOutput, $upstreamAddReturnVar);

                if ($upstreamAddReturnVar != 0) {
                    $this->cli->warning("Failed to add upstream remote: " . implode("\n", $upstreamAddOutput));
                } else {
                    $this->cli->info("Added upstream remote '$upstream' for repository");
                }
            }
        }
    }
}
