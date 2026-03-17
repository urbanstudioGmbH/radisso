<?php

declare(strict_types=1);

namespace Radisso\OIDCClient;

class RadissoOIDCException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
