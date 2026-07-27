<?php

namespace App\Exception;

class DrawSessionProblem extends \RuntimeException
{
    private $statusCode;
    private $error;

    public function __construct(int $statusCode, string $error, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->error = $error;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getError(): string
    {
        return $this->error;
    }
}
