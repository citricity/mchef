<?php

namespace App\Helpers;

class OS {
    public static function isWindows(): bool {
        return stripos(PHP_OS, 'WIN') === 0;
    }

    public static function isMac(): bool {
        return stripos(PHP_OS, 'DAR') === 0;
    }

    public static function isLinux(): bool {
        return stripos(PHP_OS, 'LINUX') === 0;
    }

    public static function escShellArg(string $arg): string {
        if (!self::isWindows()) {
            return escapeshellarg($arg);
        }
        return "'".str_replace("'", "''", $arg)."'";
    }

    public static function path(string $path): string {
        // Expand tilde only if it's the first character
        if (str_starts_with($path, '~')) {
            $home = getenv('HOME') ?: getenv('USERPROFILE');
            if ($home) {
                $path = $home . substr($path, 1); // skip the tilde
            }
        }

        // Normalize directory separators
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return $path;
    }

    public static function realPath(string $path): string {
        $resolved = realpath(self::path($path));
        if ($resolved === false) {
            throw new \RuntimeException("Path does not exist: $path");
        }
        return $resolved;
    }
}
