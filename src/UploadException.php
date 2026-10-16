<?php
/**
 * Send - Erreur d'upload destinée au client, porteuse du code HTTP
 */

declare(strict_types=1);

class UploadException extends Exception
{
    public function __construct(string $message, private readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
