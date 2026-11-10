<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TransferController;
use VenPosTPV\Service\Transfer\TransferPayments;
use VenPosTPV\Tests\Support\SessionRequest;

/** «Tickets → Pendientes de pago»: anyone signed in lists them; only an admin marks one received. */
final class TransferControllerTest extends TestCase
{
    public function testTheListNeedsASignedInUser(): void
    {
        $transfers = $this->createMock(TransferPayments::class);
        $transfers->method('pending')->willReturn([['id' => 4]]);
        $controller = new TransferController($transfers);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $controller->pending(SessionRequest::json([], []))->getStatusCode());
        $ok = $controller->pending(SessionRequest::json([], ['user_id' => 2, 'role' => 'cashier']));
        $this->assertSame(['data' => [['id' => 4]]], json_decode((string) $ok->getContent(), true));
    }

    public function testOnlyAnAdminMarksATransferReceived(): void
    {
        $transfers = $this->createMock(TransferPayments::class);
        $transfers->expects($this->once())->method('markReceived')->with(4, 1)->willReturn('received');
        $controller = new TransferController($transfers);

        $cashier = $controller->received(SessionRequest::json([], ['user_id' => 2, 'role' => 'cashier']), 4);
        $this->assertSame(Response::HTTP_FORBIDDEN, $cashier->getStatusCode());
        $this->assertSame(TransferController::ADMIN_ONLY_MESSAGE, json_decode((string) $cashier->getContent(), true)['error']);

        $admin = $controller->received(SessionRequest::json([], ['user_id' => 1, 'role' => 'admin']), 4);
        $this->assertSame(['data' => ['id' => 4, 'status' => 'received']], json_decode((string) $admin->getContent(), true));
    }

    public function testAlreadyReceivedIsAConflictAndUnknownIsNotFound(): void
    {
        $transfers = $this->createMock(TransferPayments::class);
        $transfers->method('markReceived')->willReturnMap([[4, 1, null, 'already_received'], [9, 1, null, 'not_found']]);
        $controller = new TransferController($transfers);
        $admin = ['user_id' => 1, 'role' => 'admin'];

        $this->assertSame(Response::HTTP_CONFLICT, $controller->received(SessionRequest::json([], $admin), 4)->getStatusCode());
        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->received(SessionRequest::json([], $admin), 9)->getStatusCode());
    }
}
