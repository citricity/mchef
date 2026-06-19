<?php

namespace App\Exceptions;

use Throwable;

class ExecFailed extends \Exception {
    protected string $cmd;

    public function __construct(string $message, int $code, string $cmd, ?Throwable $previous = null) {
        $this->cmd = $cmd;
        parent::__construct($message, $code, $previous);
    }

    public function getCmd(): string {
        return $this->cmd;
    }
}