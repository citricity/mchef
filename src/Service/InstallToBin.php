<?php

namespace App\Service;

use App\Helpers\OS;
use App\Traits\ExecTrait;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\CLI;

final class InstallToBin extends AbstractService {
    use ExecTrait;

    public static function instance(): InstallToBin {
        return self::setup_singleton();
    }

    protected function get_php_executable_path() {
        if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Find PHP in system PATH
        $pathSeparator = OS::isWindows() ? ';' : ':';
        $path = getenv('PATH');
        $dirs = explode($pathSeparator, $path);

        foreach ($dirs as $dir) {
            $executablePath = OS::isWindows() ? "$dir\\php.exe" : "$dir/php";
            if (is_executable($executablePath)) {
                return $executablePath;
            }
        }

        throw new Exception("PHP executable not found.");
    }

    public function install() {
        // Determine the correct bin directory
        if (OS::isWindows()) {
            // Get Windows user's local AppData path
            $userBinDir = getenv('APPDATA') . '\\mchef';

            // Ensure the bin directory exists
            if (!is_dir($userBinDir)) {
                mkdir($userBinDir, 0777, true);
            }

            $binDir = $userBinDir;

            // Ensure bin directory is in path so that we can execute mchef from command line.
            $this->exec('powershell -Command "[System.Environment]::SetEnvironmentVariable(\"PATH\", $env:PATH + \";'.$binDir.'\", [System.EnvironmentVariableTarget]::User)"');
        } else {
            // Unix-based systems: Check if the user's home bin folder is in the $PATH
            // Get the list of directories in the $PATH environment variable
            $path = getenv('PATH');
            $dirs = explode(':', $path);

            $userBinDir = getenv('HOME') . '/bin';
            if (in_array($userBinDir, $dirs)) {
                $binDir = $userBinDir;
                if (!file_exists($binDir)) {
                    throw new Exception('Your ~/bin directory does not exist. Please create it first before installing mchef there.');
                }
            } else if (in_array('/usr/local/bin', $dirs)) {
                $binDir = '/usr/local/bin';
            } else {
                throw new Exception('Could not find a suitable bin directory for installation.');
            }
        }

        $contents = file_get_contents(OS::path(__DIR__.'/../../mchef.php'));
        $phpPath = $this->get_php_executable_path();
        $contents = '#!'.$phpPath."\n".$contents;
        $installFilePath = OS::path(__DIR__.'/../../bin/mchef.php');
        file_put_contents($installFilePath, $contents);

        if (!OS::isWindows()) {
            // Make executable on *nix - note, no need to do this on windows.
            chmod($installFilePath, 0750);
        }
        $binFilePath = !OS::isWindows() ? "$binDir/mchef" : OS::path("$binDir/mchef.cmd");

        if (file_exists($binFilePath) || is_link($binFilePath)) {
            try {
                unlink($binFilePath);
            } catch (\Exception $e) {
                throw new Exception('Unable to install to bin dir as it already exists there - ' . $binFilePath);
            }
        }

        if (!OS::isWindows()) {
            $this->exec('sudo ln -s ' . OS::escShellArg($installFilePath) . ' ' . OS::escShellArg($binFilePath));
        } else {
            $cmdFile = OS::path("$binDir/mchef.cmd");
            $cmdContents = "@echo off\r\nphp \"$installFilePath\" %*";
            file_put_contents($cmdFile, $cmdContents);
        }

        $this->cli->success("Success! mchef has successfully been installed to $binDir");
        $this->cli->success("Open a brand new terminal and you should be able to call mchef directly (i.e. just type mchef --help and hit return)");
    }
}
