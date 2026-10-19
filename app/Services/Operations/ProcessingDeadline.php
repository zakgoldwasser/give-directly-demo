<?php

namespace App\Services\Operations;

class ProcessingDeadline
{
    private ?int $deadlineNanoseconds = null;

    private ?string $traceId = null;

    public function activate(string $traceId): void
    {
        $seconds = max(0, (int) config('reconciliation.max_processing_seconds', 20));
        $this->deadlineNanoseconds = hrtime(true) + ($seconds * 1_000_000_000);
        $this->traceId = $traceId;
    }

    public function checkpoint(): void
    {
        if ($this->deadlineNanoseconds === null || hrtime(true) < $this->deadlineNanoseconds) {
            return;
        }

        throw new ProcessingDeadlineExceeded($this->traceId ?? 'unknown');
    }

    public function clear(): void
    {
        $this->deadlineNanoseconds = null;
        $this->traceId = null;
    }
}
