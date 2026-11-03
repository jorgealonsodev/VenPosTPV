<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

final class PrinterConfig
{
    public function __construct(
        public readonly string $type,
        public readonly string $host,
        public readonly int $port,
        public readonly int $paperWidth,
        public readonly bool $enabled,
        public readonly string $queue = '',
    ) {
    }

    /**
     * True when tickets go straight to a printer as ESC/POS: a network printer
     * (host:port) or a printer installed on this machine (system queue, e.g. USB).
     */
    public function printsDirectly(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return ($this->type === 'network' && $this->host !== '')
            || ($this->type === 'system' && SystemPrintQueue::isValidQueueName($this->queue));
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return self::disabled();
        }

        $data = json_decode($json, true);
        if (!\is_array($data)) {
            return self::disabled();
        }

        return new self(
            type: isset($data['type']) && \is_string($data['type']) ? $data['type'] : 'disabled',
            host: isset($data['host']) && \is_string($data['host']) ? $data['host'] : '',
            port: isset($data['port']) && \is_int($data['port']) ? $data['port'] : 9100,
            paperWidth: isset($data['paper_width']) && \is_int($data['paper_width']) ? $data['paper_width'] : 80,
            enabled: isset($data['enabled']) && \is_bool($data['enabled']) ? $data['enabled'] : false,
            queue: isset($data['queue']) && \is_string($data['queue']) ? $data['queue'] : '',
        );
    }

    public static function disabled(): self
    {
        return new self(
            type: 'disabled',
            host: '',
            port: 9100,
            paperWidth: 80,
            enabled: false,
        );
    }
}
