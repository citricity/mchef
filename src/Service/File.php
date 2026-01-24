<?php

namespace App\Service;

use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Traits\ExecTrait;
use Phar;
use splitbrain\phpcli\Exception;

class File extends AbstractService {
    use ExecTrait;

    final public static function instance(): File {
        return self::setup_singleton();
    }

    /**
     * Copy files from point a to b.
     * @param $src
     * @param $target
     * @return string - Output from exec
     * @throws ExecFailed
     */
    public function copyFiles($src, $target): string {
        $this->folderRestrictionCheck($src, 'copy');
        $this->folderRestrictionCheck($target, 'copy');

        if (!OS::isWindows()) {
            $cmd = sprintf(
                "cp -r %s/{.,}* %s",
                OS::escShellArg($src),
                OS::escShellArg($target)
            );
        } else {
            $cmd = sprintf(
                'powershell -Command "Copy-Item -Path %s -Destination %s -Recurse -Force -ErrorAction Stop"',
                OS::escShellArg("$src\\*"),
                OS::escShellArg($target)
            );
        }

        return $this->exec($cmd, "Failed to copy files from $src to $target: {{output}}");
    }

    public function folderRestrictionCheck(string $path, string $action) {
        if (strpos(__FILE__, 'phar://') === 0) {
            // Skip checks when running from PHAR
            return;
        }
        if (!is_dir($path)) {
            throw new Exception('Invalid path: ' . $path);
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new Exception('Could not resolve real path for: ' . $path);
        }

        if (!OS::isWindows()) {
            if ($realPath === DIRECTORY_SEPARATOR) {
                throw new Exception('You cannot ' . $action . ' files from root!');
            }
        } else {
            // Windows sensitive folders
            $restrictedWindowsPaths = [
                'C:\\Windows',         // Windows system folder
                'C:\\Windows\\System32',
                'C:\\Windows\\SysWOW64',
                'C:\\Program Files',   // Default installation folder
                'C:\\Program Files (x86)',
                'C:\\Users\\Administrator',  // Admin home
                'C:\\Users\\Public',  // Public shared files
                'C:\\',               // Root of C drive
            ];

            foreach ($restrictedWindowsPaths as $restrictedPath) {
                if (stripos($realPath, $restrictedPath) === 0) {
                    throw new Exception('You cannot ' . $action . ' files from sensitive Windows system directories!');
                }
            }
        }

        // Unix-sensitive folders
        $restrictedUnixPaths = [
            OS::path('/etc'),
            OS::path('/bin'),
            OS::path('/usr/bin'),
            OS::path('/var/lib'),
            OS::path('/boot'),
            OS::path('/sbin'),
        ];

        if (in_array($realPath, $restrictedUnixPaths, true)) {
            throw new Exception('You cannot ' . $action . ' files from sensitive Unix system directories!');
        }
    }

    public function cmdFindAllFilesExcluding(string $mainPath, array $files, array $paths): string {
        if (!OS::isWindows()) {
            $files = array_map(function($file) use ($mainPath) {
                $cleanFile = preg_replace('/^\.\//', '', $file);
                return ' -not -path ' . OS::escShellArg($mainPath . DIRECTORY_SEPARATOR . $cleanFile);
            }, $files);
            $paths = array_map(function($path) use ($mainPath) {
                $cleanPath = preg_replace('/^\.\//', '', $path);
                return ' -not -path ' . OS::escShellArg($mainPath . DIRECTORY_SEPARATOR . $cleanPath) . ' -not -path ' . OS::escShellArg($mainPath . DIRECTORY_SEPARATOR . $cleanPath . DIRECTORY_SEPARATOR . '*');
            }, $paths);
            return "find " . OS::escShellArg($mainPath) . implode(' ', $files) . implode(' ', $paths);
        }

        // PowerShell Alternative for Windows
        $notFiles = implode(' -and ', array_map(function($file) use ($mainPath) {
            $cleanFile = preg_replace('/^\.\//', '', $file);
            return "-not (Get-Item " . OS::escShellArg($mainPath . '\\' . $cleanFile) . ")";
        }, $files));
        $notPaths = implode(' -and ', array_map(function($path) use ($mainPath) {
            $cleanPath = preg_replace('/^\.\//', '', $path);
            return "-not (Get-Item " . OS::escShellArg($mainPath . '\\' . $cleanPath) . ") -and -not (Get-Item " . OS::escShellArg($mainPath . '\\' . $cleanPath . '\\*') . ")";
        }, $paths));

        return sprintf(
            'powershell -Command "Get-ChildItem -Path %s -Recurse | Where-Object { %s %s }"',
            OS::escShellArg($mainPath),
            $notFiles,
            $notPaths
        );
    }

    /**
     * Delete all files excluding specific files
     * @param string $path - target path of which to delete files from
     * @param array $files
     * @param array $relativePaths - paths relative to $path to exclude
     * @return string
     * @throws ExecFailed
     */
    public function deleteAllFilesExcluding(string $path, array $files, array $relativePaths): string {
        $this->folderRestrictionCheck($path, 'delete');

        if (!OS::isWindows()) {
            $cmd = $this->cmdFindAllFilesExcluding($path, $files, $relativePaths);
            $cmd = "$cmd -delete";
        } else {
            // PowerShell equivalent for Windows
            $notFiles = implode(' -and ', array_map(function($file) use ($path) {
                $cleanFile = preg_replace('/^\.\//', '', $file);
                return "-not (Get-Item " . OS::escShellArg($path . '\\' . $cleanFile) . ")";
            }, $files));
            $notPaths = implode(' -and ', array_map(function($relativePath) use ($path) {
                $cleanPath = preg_replace('/^\.\//', '', $relativePath);
                return "-not (Get-Item " . OS::escShellArg($path . '\\' . $cleanPath) . ") -and -not (Get-Item " . OS::escShellArg($path . '\\' . $cleanPath . '\\*') . ")";
            }, $relativePaths));        
            
            $cmd = sprintf(
                'powershell -Command "Get-ChildItem -Path %s -Recurse | Where-Object { %s %s } | Remove-Item -Force -Recurse"',
                OS::escShellArg($path),
                $notFiles,
                $notPaths
            );
        }

        return $this->exec($cmd);
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $dir
     */
    public function deleteDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $scanResult = scandir($dir);
        if ($scanResult === false) {
            throw new Exception('Failed to read directory: ' . $dir . ' - check permissions');
        }
        
        $files = array_diff($scanResult, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                if (!unlink($path)) {
                    throw new Exception('Failed to delete file: ' . $path . ' - check permissions');
                }
            }
        }
        
        if (!rmdir($dir)) {
            throw new Exception('Failed to remove directory: ' . $dir . ' - check permissions or if directory is empty');
        }
    }

    public function getMchefBasePath(): string {
        // If running from PHAR
        if (strpos(__FILE__, 'phar://') === 0) {
            $pharFile = Phar::running(false);
            return "phar://{$pharFile}";  // Return PHAR internal path
        }
        // If running from source
        return dirname(dirname(__DIR__));
    }

    public function copyFilesFromDirToDir(string $sourceDir, string $targetDir, int $depth = 0): void {
        if ($depth === 0) {
            $this->folderRestrictionCheck($sourceDir, 'copy');
            if (!is_dir($sourceDir)) {
                throw new Exception('Source directory does not exist: ' . $sourceDir);
            }
            if (!is_dir($targetDir)) {
                throw new Exception('Target directory does not exist: ' . $targetDir);
            }
        }
        
        $this->folderRestrictionCheck($targetDir, 'copy');

        $scanResult = scandir($sourceDir);
        if ($scanResult === false) {
            throw new Exception('Failed to read source directory: ' . $sourceDir . ' - check permissions or if directory exists');
        }

        $files = array_diff($scanResult, ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            $destPath = $targetDir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                if (!is_dir($destPath)) {
                    if (!mkdir($destPath, 0755, true)) {
                        throw new Exception('Failed to create directory: ' . $destPath . ' - check permissions');
                    }
                }
                $this->copyFilesFromDirToDir($srcPath, $destPath, $depth + 1);
            } else {
                if (!copy($srcPath, $destPath)) {
                    throw new Exception('Failed to copy file from ' . $srcPath . ' to ' . $destPath . ' - check permissions and disk space');
                }
            }
        }
    }

    public function tempDir() {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid(sha1(microtime()), true);
        mkdir($tempDir);
        return $tempDir;
    }

    private function getRootDirectoryWindows($currentDir) {
        if (preg_match('/^[A-Z]:\\\\/', $currentDir, $matches)) {
            return $matches[0];
        }
        return 'C:\\'; // Fallback if something goes wrong
    }

    function findFileInOrAboveDir($filename, ?string $dir = null): ?string {
        $currentDir = $dir ?? getcwd();
        $rootDir = OS::isWindows() ? $this->getRootDirectoryWindows($currentDir) : '/';

        while ($currentDir !== $rootDir && $currentDir !== false) {
            $filePath = OS::path("$currentDir/$filename");

            if (file_exists($filePath)) {
                return $filePath;
            }

            $parentDir = realpath(OS::path($currentDir .'/..'));
            if ($parentDir === $currentDir) {
                break; // Prevent infinite loop at root
            }

            $currentDir = $parentDir;
        }

        return null;
    }

}
