<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog\Exception;

/**
 * A refused edit of «Modo edición»: the message is shown at the till as is, the
 * status is the HTTP status the API answers with.
 */
final class CatalogEditException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(): self
    {
        return new self('Artículo no encontrado en el catálogo', 404, 'item_not_found');
    }

    public static function invalid(string $message, string $code): self
    {
        return new self($message, 422, $code);
    }

    public static function overridesUnavailable(): self
    {
        return new self('El TPV aún no admite datos locales del catálogo: falta aplicar la actualización de la base de datos', 503, 'catalog_override_unavailable');
    }
}
