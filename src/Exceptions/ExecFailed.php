<?php

namespace App\Exceptions;

use Throwable;

class ExecFailed extends \Exception {
    protected string $cmd;
    protected $debugInfo;

    public function __construct(string $message, int $code, string $cmd, ?Throwable $previous = null, $debugInfo = null) {
        $this->cmd = $cmd;
        $this->debugInfo = $debugInfo;
        parent::__construct($message, $code, $previous);
    }

    public function getCmd(): string {
        return $this->cmd;
    }

    public function getDebugInfo() {
        return $this->debugInfo;
    }
}