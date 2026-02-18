<?php

namespace App\Exceptions;

/**
 * Exception thrown when user declines to agree to terms and conditions
 */
class TermsNotAgreedException extends \Exception {
    public function __construct(string $message = "User declined to agree to terms and conditions", int $code = 1, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}