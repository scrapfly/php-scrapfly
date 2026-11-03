<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** A crawl's state, `GET /crawl/{uuid}/status`. */
final class CrawlerStatus
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data)
    {
    }

    public function uuid(): string
    {
        return (string) ($this->data['crawler_uuid'] ?? '');
    }

    /** `PENDING`, `RUNNING`, `PAUSED`, `DONE` or `CANCELLED`. A failed crawl is `DONE`, not successful. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function isRunning(): bool
    {
        // A PAUSED crawl resumes on its own.
        return in_array($this->status(), ['PENDING', 'RUNNING', 'PAUSED'], true);
    }

    public function isComplete(): bool
    {
        return $this->status() === 'DONE' && ($this->data['is_success'] ?? false) === true;
    }

    public function isFailed(): bool
    {
        return $this->status() === 'DONE' && ($this->data['is_success'] ?? null) === false;
    }

    public function isCancelled(): bool
    {
        return $this->status() === 'CANCELLED';
    }

    public function isFinished(): bool
    {
        return ($this->data['is_finished'] ?? false) === true;
    }

    /** @return array<string, mixed> urls_visited, urls_extracted, urls_failed, api_credit_used, stop_reason, ... */
    public function state(): array
    {
        return $this->data['state'] ?? [];
    }

    /**
     * The search index of a crawl started with `search: true`: `status` is
     * `BUILDING`, `READY`, `PARTIAL`, `FAILED` or `DISABLED`; null without one.
     *
     * @return array<string, mixed>|null
     */
    public function search(): ?array
    {
        return $this->data['search'] ?? null;
    }

    /** Whether the search index answers queries. */
    public function isSearchable(): bool
    {
        return in_array($this->search()['status'] ?? null, ['READY', 'PARTIAL'], true);
    }

    /** @return array<string, mixed>|null the refresh schedule and its history */
    public function refresh(): ?array
    {
        return $this->data['refresh'] ?? null;
    }
}
