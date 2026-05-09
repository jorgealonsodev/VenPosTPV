<?php

declare(strict_types=1);

namespace VenPosTPV\MessageHandler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Print\PrinterService;

#[AsMessageHandler]
final class PrintTicketMessageHandler
{
    public function __construct(
        private readonly PrinterService $printerService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PrintTicketMessage $message): void
    {
        try {
            $this->printerService->printTicket($message->orderId, $message->terminalId);
            $this->logger->info(
                'Ticket printed for order {orderId} on terminal {terminalId}',
                [
                    'orderId' => $message->orderId,
                    'terminalId' => $message->terminalId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Ticket printing failed for order {orderId}: {message}',
                [
                    'orderId' => $message->orderId,
                    'terminalId' => $message->terminalId,
                    'message' => $e->getMessage(),
                ]
            );
            // Re-throw so Messenger can retry or send to failure transport
            throw $e;
        }
    }
}
