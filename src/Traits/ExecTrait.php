<?php

namespace App\Traits;

use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Traits\DebugModeTrait;

trait ExecTrait {
    use DebugModeTrait;

    /**
     * Allows for {{output}} token to interpolate output from cli failure into error message string.
     *
     * @param string $errorMsg
     * @param null|string|array $output
     * @return string
     */
    private function processErrorMsg(string $errorMsg, null | string | array $output): string {
        if ($output === null) {
            return $errorMsg;
        }
        $useOutput = is_array($output) ? implode("\n", $output) : $output;
        $pattern = '/\{\{(?:\s+|)output(?:\s+|)\}\}/';
        return preg_replace($pattern, $useOutput, $errorMsg);
    }

    protected function exec(string $cmd, ?string $errorMsg = null, ?bool $silent = false): string {
        if ($silent) {
            $cmd .= ' 2>&1';
        }

        $this->verboseCmdDebug($cmd);

        exec($cmd, $output, $returnVar);

        if ($returnVar != 0) {
            $this->errorCmdDebug($cmd);
            throw new ExecFailed(($errorMsg ? $this->processErrorMsg($errorMsg, $output) : "Exec failed : $cmd"), 0, $cmd);
        }

        return implode("\n", $output);
    }

    protected function execStream(string $cmd, ?string $errorMsg = null): string {
        $this->verboseCmdDebug($cmd);
        $outputBuffering = ini_get('output_buffering');
        ini_set('output_buffering', 0);
        flush();
        $output = system($cmd, $returnVar);
        if ($returnVar != 0) {
            // Restore output buffering.
            ini_set('output_buffering', $outputBuffering);
            $this->errorCmdDebug($cmd);
            throw new ExecFailed(($errorMsg ? $this->processErrorMsg($errorMsg, $output) : "Exec failed"), 0, $cmd);
        }

        // Restore output buffering.
        ini_set('output_buffering', $outputBuffering);
        return $output;
    }

    protected function execDetached(string $cmd): void {
        $this->verboseCmdDebug($cmd);
        // Execute command in background to detach from PHP process
        // This allows GUI applications to get proper focus

        // This is made OS-aware to work correctly on both Unix-like systems and Windows.
        if (OS::isWindows()) {
            // On Windows, use "start" to launch a detached process.
            // The empty title ("") is required to avoid treating the first argument as the window title.
            $detachedCmd = 'start "" ' . $cmd;
        } else {
            // On Unix-like systems.
            $detachedCmd = "($cmd) &";
        }
        $output = [];
        $returnVar = 0;
        exec($detachedCmd, $output, $returnVar);
    }

    protected function execPassthru(string $cmd, ?string $errorMsg = null): void {
        $this->verboseCmdDebug($cmd);

        // Do not alter stderr behavior if command already contains stderr redirection.
        // Covers common forms like: 2>&1, 2>file, 2>>file, 2>&2.
        $hasStderrRedirect = preg_match('/(?:^|\s|[;|&()])2\s*(?:>>?|>&)\s*(?:\S|&\d+)/', $cmd) === 1;
        $runCmd = $hasStderrRedirect ? $cmd : $cmd . ' 2>&1';

        // Keep only the tail of output to avoid unbounded memory growth.
        $maxCaptureBytes = 131072; // 128 KB
        $cliOutput = '';

        $flushChunkBytes = 1024; // Keep output responsive without 1-byte callback overhead.

        ob_start(function (string $buffer) use (&$cliOutput, $maxCaptureBytes): string {
            $cliOutput .= $buffer;
            $len = strlen($cliOutput);
            if ($len > $maxCaptureBytes) {
                $cliOutput = substr($cliOutput, $len - $maxCaptureBytes);
            }
            return $buffer; // Preserve normal passthru behavior (print live)
        }, $flushChunkBytes);

        passthru($runCmd, $returnVar);
        ob_end_flush();

        if ($returnVar !== 0) {
            $message = $errorMsg
                ? $this->processErrorMsg($errorMsg, $cliOutput)
                : "Exec failed";

            $this->errorCmdDebug($cmd);
            throw new ExecFailed($message, 0, $cmd, null, $cliOutput);
        }
    }

    private function resolveBinary(string $binary): string {
        $path = trim(shell_exec("command -v " . escapeshellarg($binary) . " 2>/dev/null"));
        return $path !== '' ? $path : $binary;
    }

    protected function execInteractive(string $cmd, array $env = []): void {
        $tmparr = explode(' ', $cmd);
        $tmparr[0] = $this->resolveBinary($tmparr[0]);
        $cmd = implode(' ', $tmparr);
        $this->verboseCmdDebug($cmd);

        $descriptorspec = [
            0 => STDIN,   // pass through input
            1 => STDOUT,  // pass through output
            2 => STDERR,  // pass through error
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, null, array_merge($_ENV, $env));

        if (!is_resource($process)) {
            $this->errorCmdDebug($cmd);
            throw new ExecFailed("Failed to start process: $cmd", 0, $cmd);
        }

        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            $this->errorCmdDebug($cmd);
            throw new ExecFailed("Exec failed: $cmd (exit $returnVar)", 0, $cmd);
        }
    }
}
