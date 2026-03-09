<?php

namespace App\Traits;

use App\Exceptions\ExecFailed;

trait ExecTrait {
    protected bool $verbose = false;

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
        if ($this->verbose && !empty($this->cli)) {
            $this->cli->info($cmd);
        }
        exec($cmd, $output, $returnVar);

        if ($returnVar != 0) {
            throw new ExecFailed(($errorMsg ? $this->processErrorMsg($errorMsg, $output) : "Exec failed : $cmd"), 0, $cmd);
        }

        return implode("\n", $output);
    }

    protected function execStream(string $cmd, ?string $errorMsg = null): string {
        if ($this->verbose && !empty($this->cli)) {
            $this->cli->info($cmd);
        }
        $outputBuffering = ini_get('output_buffering');
        ini_set('output_buffering', 0);
        flush();
        $output = system($cmd, $returnVar);
        if ($returnVar != 0) {
            // Restore output buffering.
            ini_set('output_buffering', $outputBuffering);
            throw new ExecFailed(($errorMsg ? $this->processErrorMsg($errorMsg, $output) : "Exec failed"), 0, $cmd);
        }

        // Restore output buffering.
        ini_set('output_buffering', $outputBuffering);
        return $output;
    }

    protected function execDetached(string $cmd): void {
        if ($this->verbose && !empty($this->cli)) {
            $this->cli->info($cmd);
        }
        // Execute command in background to detach from PHP process
        // This allows GUI applications to get proper focus
        exec($cmd . ' &');
    }

    protected function execPassthru(string $cmd, ?string $errorMsg = null): void {
        if ($this->verbose && !empty($this->cli)) {
            $this->cli->info($cmd);
        }
        $outputBuffering = ini_get('output_buffering');
        ini_set('output_buffering', 0);
        flush();
        $output = passthru($cmd, $returnVar);
        if ($returnVar != 0) {
            // Restore output buffering.
            ini_set('output_buffering', $outputBuffering);
            throw new ExecFailed(($errorMsg ? $this->processErrorMsg($errorMsg, $output) : "Exec failed"), 0, $cmd);
        }

        // Restore output buffering.
        ini_set('output_buffering', $outputBuffering);
    }

    private function resolveBinary(string $binary): string {
        $path = trim(shell_exec("command -v " . escapeshellarg($binary) . " 2>/dev/null"));
        return $path !== '' ? $path : $binary;
    }

    protected function execInteractive(string $cmd, array $env = []): void {
        $tmparr = explode(' ', $cmd);
        $tmparr[0] = $this->resolveBinary($tmparr[0]);
        $cmd = implode(' ', $tmparr);
        if ($this->verbose && !empty($this->cli)) {
            $this->cli->info($cmd);
        }

        $descriptorspec = [
            0 => STDIN,   // pass through input
            1 => STDOUT,  // pass through output
            2 => STDERR,  // pass through error
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, null, array_merge($_ENV, $env));

        if (!is_resource($process)) {
            throw new ExecFailed("Failed to start process: $cmd", 0, $cmd);
        }

        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new ExecFailed("Exec failed: $cmd (exit $returnVar)", 0, $cmd);
        }
    }
}
