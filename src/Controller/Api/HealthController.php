<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use VenPosTPV\Service\Db\ConnectionManagerInterface;

#[AsController]
class HealthController
{
    public function __construct(private readonly ConnectionManagerInterface $connectionManager)
    {
    }

    public function index(): JsonResponse
    {
        $dbStatus = $this->checkDatabase();
        $healthy = $dbStatus === 'ok';

        $data = [
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => [
                'database' => $dbStatus,
            ],
        ];

        return new JsonResponse($data, $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function checkDatabase(): string
    {
        try {
            $conn = $this->connectionManager->getTpvConnection();
            $conn->executeQuery('SELECT 1');

            return 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
