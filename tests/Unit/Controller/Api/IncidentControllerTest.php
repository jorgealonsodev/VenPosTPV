<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\IncidentController;
use VenPosTPV\Service\Incident\DiscardNotReady;
use VenPosTPV\Service\Incident\OutboxIncidents;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * PRD I1-I3: anyone logged in sees the counts; only an administrator lists and
 * retries the incidents, and the retries only take a JSON body.
 */
final class IncidentControllerTest extends TestCase
{
    private const ADMIN = ['user_id' => 1, 'role' => 'admin', 'shop_id' => 1];
    private const CASHIER = ['user_id' => 5, 'role' => 'cashier', 'shop_id' => 1];

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $body
     */
    private function json(array $session, array $body = []): Request
    {
        $request = SessionRequest::json($body, $session);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    public function testAnyoneLoggedInGetsThePendingAndIncidentCounts(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('counts')->willReturn(['pending' => 4, 'incidents' => 2]);
        $controller = new IncidentController($incidents);

        $response = $controller->outboxStatus(SessionRequest::create([], self::CASHIER));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['data' => ['pending' => 4, 'incidents' => 2]], $this->body($response));
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->outboxStatus(SessionRequest::create())->getStatusCode());
    }

    public function testOnlyAnAdministratorListsOrRetriesIncidents(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->never())->method('list');
        $incidents->expects($this->never())->method('retry');
        $incidents->expects($this->never())->method('retryOrder');
        $controller = new IncidentController($incidents);

        foreach ([
            $controller->list(SessionRequest::create([], self::CASHIER)),
            $controller->retry(7, $this->json(self::CASHIER)),
            $controller->retryOrder(3, $this->json(self::CASHIER)),
        ] as $response) {
            $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        }
        foreach ([
            $controller->list(SessionRequest::create()),
            $controller->retry(7, $this->json([])),
            $controller->retryOrder(3, $this->json([])),
        ] as $response) {
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        }
    }

    public function testAnAdministratorListsTheIncidents(): void
    {
        $row = ['id' => 7, 'type' => 'sale', 'order_id' => 3, 'reference' => 'A26000003', 'last_error' => 'HTTP 422', 'attempts' => 1];
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('list')->willReturn([$row]);

        $response = (new IncidentController($incidents))->list(SessionRequest::create([], self::ADMIN));

        $this->assertSame(['data' => [$row]], $this->body($response));
    }

    public function testRetriesOnlyTakeAJsonBody(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->never())->method('retry');
        $incidents->expects($this->never())->method('retryOrder');
        $controller = new IncidentController($incidents);
        $form = SessionRequest::create([], self::ADMIN, 'a=1');
        $form->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $controller->retry(7, $form)->getStatusCode());
        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $controller->retryOrder(3, $form)->getStatusCode());
    }

    public function testRetryAnswersWhetherTheIncidentWentBackToTheQueue(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('retry')->willReturnMap([[7, true], [8, false]]);
        $controller = new IncidentController($incidents);

        $ok = $controller->retry(7, $this->json(self::ADMIN));
        $gone = $controller->retry(8, $this->json(self::ADMIN));

        $this->assertSame(['data' => ['retried' => [7]]], $this->body($ok));
        $this->assertSame(Response::HTTP_CONFLICT, $gone->getStatusCode());
        $this->assertSame('not_incident', $this->body($gone)['code']);
    }

    public function testRetryingASaleAnswersTheRowsInOrder(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('retryOrder')->willReturnMap([[3, [11, 14]], [4, []]]);
        $controller = new IncidentController($incidents);

        $this->assertSame(['data' => ['retried' => [11, 14]]], $this->body($controller->retryOrder(3, $this->json(self::ADMIN))));
        $this->assertSame(Response::HTTP_CONFLICT, $controller->retryOrder(4, $this->json(self::ADMIN))->getStatusCode());
    }

    public function testOnlyAnAdministratorDiscardsOrSeesTheDiscardedIncidents(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->never())->method('discard');
        $incidents->expects($this->never())->method('discarded');
        $controller = new IncidentController($incidents);

        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->discard(7, $this->json(self::CASHIER))->getStatusCode());
        $this->assertSame(Response::HTTP_FORBIDDEN, $controller->discardedList(SessionRequest::create([], self::CASHIER))->getStatusCode());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->discard(7, $this->json([]))->getStatusCode());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->discardedList(SessionRequest::create())->getStatusCode());
        $form = SessionRequest::create([], self::ADMIN, 'reason=x');
        $form->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $controller->discard(7, $form)->getStatusCode());
    }

    public function testDiscardStoresTheAdministratorAndTheTrimmedReason(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->once())->method('discard')->with(7, 1, 'Producto borrado')->willReturn([7, 8, 9]);
        $response = (new IncidentController($incidents))->discard(7, $this->json(self::ADMIN, ['reason' => '  Producto borrado ']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['data' => ['discarded' => [7, 8, 9]]], $this->body($response));
    }

    public function testDiscardWithoutAReasonStoresNone(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->exactly(2))->method('discard')->with(7, 1, null)->willReturn([7]);
        $controller = new IncidentController($incidents);

        $this->assertSame(Response::HTTP_OK, $controller->discard(7, $this->json(self::ADMIN))->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $controller->discard(7, $this->json(self::ADMIN, ['reason' => '   ']))->getStatusCode());
    }

    public function testDiscardRejectsAReasonThatIsNotText(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->expects($this->never())->method('discard');
        $response = (new IncidentController($incidents))->discard(7, $this->json(self::ADMIN, ['reason' => ['x']]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('invalid_reason', $this->body($response)['code']);
    }

    public function testDiscardAnswers409WhenTheRowIsNoLongerAnIncident(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('discard')->willReturn(null);
        $response = (new IncidentController($incidents))->discard(8, $this->json(self::ADMIN));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('not_incident', $this->body($response)['code']);
    }

    public function testDiscardAnswers503UntilTheMigrationIsApplied(): void
    {
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('discard')->willThrowException(new DiscardNotReady());
        $response = (new IncidentController($incidents))->discard(8, $this->json(self::ADMIN));

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('discard_not_ready', $this->body($response)['code']);
    }

    public function testAnAdministratorListsTheRecentlyDiscarded(): void
    {
        $row = ['id' => 7, 'type' => 'sale', 'discarded_by' => 'Ana', 'discarded_at' => '2026-11-03 12:40:00', 'discard_reason' => null];
        $incidents = $this->createMock(OutboxIncidents::class);
        $incidents->method('discarded')->willReturn([$row]);

        $response = (new IncidentController($incidents))->discardedList(SessionRequest::create([], self::ADMIN));

        $this->assertSame(['data' => [$row]], $this->body($response));
    }
}
