<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

final class ProvisioningReport
{
    /** @param list<StepStatus> $steps in run order */
    public function __construct(public readonly array $steps)
    {
    }

    public function isComplete(): bool
    {
        return $this->pending() === [];
    }

    /** @return list<StepStatus> the steps that are not done */
    public function pending(): array
    {
        return array_values(array_filter($this->steps, static fn (StepStatus $s): bool => !$s->isDone()));
    }

    /** @return list<StepStatus> the steps run() was called on in this run */
    public function attempted(): array
    {
        return array_values(array_filter($this->steps, static fn (StepStatus $s): bool => $s->attempted));
    }

    /** @return array{complete: bool, steps: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'complete' => $this->isComplete(),
            'steps' => array_map(static fn (StepStatus $s): array => $s->toArray(), $this->steps),
        ];
    }
}
