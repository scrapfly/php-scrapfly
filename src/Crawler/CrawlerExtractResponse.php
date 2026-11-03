<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/**
 * The answer of `POST /crawl/extract`: `data` shaped by the request's JSON Schema.
 * The schema constrains the model as it generates and is not checked again
 * afterwards, so validate anything you act on.
 */
final class CrawlerExtractResponse
{
    /**
     * @param list<array<string, mixed>> $sources the retrieved chunks `data` was built from
     * @param array<string, mixed> $search completeness, crawls_requested, crawls_searched, crawls_pruned_exact, skipped
     */
    public function __construct(
        public readonly string $prompt,
        public readonly mixed $data,
        public readonly array $sources,
        public readonly int $sourcesDropped,
        public readonly bool $truncated,
        public readonly array $search,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['prompt'] ?? ''),
            $data['data'] ?? null,
            $data['sources'] ?? [],
            (int) ($data['sources_dropped'] ?? 0),
            (bool) ($data['truncated'] ?? false),
            $data['search'] ?? [],
        );
    }

    public function isExact(): bool
    {
        return ($this->search['completeness'] ?? null) === 'exact';
    }
}
