<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop\Exception;

use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class PsApiException extends RuntimeException
{
    private ?ResponseInterface $response = null;

    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?ResponseInterface $response = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public static function httpError(int $code, string $message, ?ResponseInterface $response = null): self
    {
        return new self(
            sprintf('PrestaShop API HTTP error %d: %s', $code, $message),
            $code,
            null,
            $response
        );
    }

    public static function networkError(string $message, Throwable $previous): self
    {
        return new self(
            sprintf('PrestaShop API network error: %s', $message),
            0,
            $previous
        );
    }

    public static function rateLimitExceeded(int $retryAfter): self
    {
        return new self(
            sprintf('PrestaShop API rate limit exceeded. Retry after %d seconds.', $retryAfter),
            429
        );
    }
}
