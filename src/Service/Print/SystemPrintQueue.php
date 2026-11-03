<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * Sends raw ESC/POS bytes to a printer installed on this machine (CUPS), such as
 * a USB receipt printer: `lp -d <queue> -o raw`. Only usable when the TPV server
 * runs on the computer the printer is connected to.
 */
class SystemPrintQueue
{
    private const QUEUE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$/';

    public function __construct(
        private readonly string $lpBinary = 'lp',
        private readonly string $lpstatBinary = 'lpstat',
    ) {
    }

    public static function isValidQueueName(string $queue): bool
    {
        return (bool) preg_match(self::QUEUE_PATTERN, $queue);
    }

    public function send(string $queue, string $bytes): void
    {
        if (!self::isValidQueueName($queue)) {
            throw new \InvalidArgumentException(sprintf('Invalid printer queue name "%s"', $queue));
        }

        // Array command: no shell, so the queue name can never inject arguments.
        [$exitCode, , $stderr] = $this->run([$this->lpBinary, '-d', $queue, '-o', 'raw'], $bytes);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('System printer "%s" rejected the job: %s', $queue, trim($stderr) ?: 'exit code ' . $exitCode));
        }
    }

    /**
     * @return string[] names of the printers installed on this machine
     */
    public function list(): array
    {
        try {
            [$exitCode, $stdout] = $this->run([$this->lpstatBinary, '-e'], '');
        } catch (\Throwable) {
            return [];
        }
        if ($exitCode !== 0) {
            return [];
        }

        $queues = array_values(array_filter(
            array_map('trim', explode("\n", $stdout)),
            static fn (string $name): bool => self::isValidQueueName($name)
        ));
        sort($queues);

        return $queues;
    }

    /**
     * @param string[] $command
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command, string $stdin): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException(sprintf('Could not start %s', $command[0]));
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
