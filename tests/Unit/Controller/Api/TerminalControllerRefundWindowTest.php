<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\TerminalController;
use VenPosTPV\Tests\Support\SessionRequest;

final class TerminalControllerRefundWindowTest extends TestCase
{
    public function testRefundWindowIsSavedWithinRange(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())->method('update')->with('terminal', ['refund_window_days' => 45], ['id' => 1]);
        $conn->method('fetchAssociative')->willReturn(['id' => 1, 'refund_window_days' => 45]);

        $response = (new TerminalController($conn))->update(1, SessionRequest::json(['refund_window_days' => 45], ['user_id' => 1, 'role' => 'admin']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRefundWindowOutsideRangeIsRejected(): void
    {
        foreach ([0, 3651] as $days) {
            $conn = $this->createMock(Connection::class);
            $conn->expects($this->never())->method('update');
            $conn->method('fetchAssociative')->willReturn(['id' => 1]);

            $response = (new TerminalController($conn))->update(1, SessionRequest::json(['refund_window_days' => $days], ['user_id' => 1, 'role' => 'admin']));

            $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        }
    }
}
