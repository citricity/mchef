<?php

namespace App\Service;

use App\Exceptions\CliRuntimeException;
use App\Helpers\OS;

class Git extends AbstractService {

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
}
