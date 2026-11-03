<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

/**
 * Why the agent API refuses a result or an extension of a job: 404 when the job is
 * not this till's, 409 `claim_stale` when the claim is no longer the agent's, 410
 * `job_expired` when the job expired (PRD T4, T6).
 */
final class PrintJobException extends \RuntimeException
{
    private function __construct(public readonly int $httpStatus, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'job_not_found', 'Trabajo no encontrado');
    }

    public static function claimStale(): self
    {
        return new self(409, 'claim_stale', 'El trabajo ya no está recogido por este agente');
    }

    public static function expired(): self
    {
        return new self(410, 'job_expired', 'El trabajo ha caducado');
    }
}
