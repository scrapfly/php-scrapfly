<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/**
 * The merged ranking of `POST /crawl/search`.
 *
 * `completeness` is `exact` when the ranking is proven complete for the requested
 * crawls, most of them unopened included, and `partial` when the deadline cut the
 * fan-out short. A crawl that contributed nothing is in `skipped()` with a reason,
 * never an error.
 */
final class CrawlerSearchResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data)
    {
    }

    /** @return list<array<string, mixed>> rank, score, crawler_uuid, url, title, text, source_format, contents_url, ... */
    public function results(): array
    {
        return $this->data['results'] ?? [];
    }

    public function completeness(): ?string
    {
        return $this->data['completeness'] ?? null;
    }

    public function isExact(): bool
    {
        return $this->completeness() === 'exact';
    }

    /** @return list<array<string, mixed>> crawler_uuid, reason, status */
    public function skipped(): array
    {
        return $this->data['skipped'] ?? [];
    }

    /** The token of the next page, null on the last one. */
    public function cursor(): ?string
    {
        return $this->data['cursor'] ?? null;
    }
}
