<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * The schedule part of a create call: where the results go and when it fires. A
 * schedule delivers each run to the webhook; it takes a recurrence or a one-time
 * `scheduledDate`.
 */
final class ScheduleRequest
{
    /** @param string|null $scheduledDate ISO-8601 UTC date of a one-time run */
    public function __construct(
        public string $webhookName,
        public ?ScheduleRecurrence $recurrence = null,
        public ?string $scheduledDate = null,
        public bool $allowConcurrency = false,
        public bool $retryOnFailure = false,
        public ?int $maxRetries = null,
        public ?string $notes = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'webhook_name' => $this->webhookName,
            'allow_concurrency' => $this->allowConcurrency,
            'retry_on_failure' => $this->retryOnFailure,
            // An empty one is still an object on the wire.
            'recurrence' => $this->recurrence === null ? null : ($this->recurrence->toArray() ?: new \stdClass()),
            'scheduled_date' => $this->scheduledDate,
            'max_retries' => $this->maxRetries,
            'notes' => $this->notes,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
