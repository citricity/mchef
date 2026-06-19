<?php

namespace App\Exceptions;

use Throwable;

class ExecFailed extends \Exception {
    protected string $cmd;
    protected ?string $debugInfo;

    public function __construct(string $message, int $code, string $cmd, ?Throwable $previous = null, ?string $debugInfo = null) {
        $this->cmd = $cmd;
        $this->debugInfo = $debugInfo;
        parent::__construct($message, $code, $previous);
    }

    public function getCmd(): string {
        return $this->cmd;
    }

    public function getDebugInfo(): string {
        return $this->debugInfo ?? '';
    }
}