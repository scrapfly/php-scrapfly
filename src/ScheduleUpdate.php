<?php

declare(strict_types=1);

namespace Scrapfly;

/** A schedule change: only what is set is sent, the rest stays as it is. */
final class ScheduleUpdate
{
    /**
     * @param array<string, mixed>|null $scrapeConfig the whole new config of an api.scrape schedule, in API option names
     * @param array<string, mixed>|null $screenshotConfig
     * @param array<string, mixed>|null $crawlerConfig
     */
    public function __construct(
        public ?ScheduleRecurrence $recurrence = null,
        public ?string $scheduledDate = null,
        public ?bool $allowConcurrency = null,
        public ?bool $retryOnFailure = null,
        public ?int $maxRetries = null,
        public ?string $notes = null,
        public ?array $scrapeConfig = null,
        public ?array $screenshotConfig = null,
        public ?array $crawlerConfig = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            // An empty one is still an object on the wire.
            'recurrence' => $this->recurrence === null ? null : ($this->recurrence->toArray() ?: new \stdClass()),
            'scheduled_date' => $this->scheduledDate,
            'allow_concurrency' => $this->allowConcurrency,
            'retry_on_failure' => $this->retryOnFailure,
            'max_retries' => $this->maxRetries,
            'notes' => $this->notes,
            'scrape_config' => $this->scrapeConfig,
            'screenshot_config' => $this->screenshotConfig,
            'crawler_config' => $this->crawlerConfig,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
