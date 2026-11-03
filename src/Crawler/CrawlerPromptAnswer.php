<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** The whole answer of `POST /crawl/prompt` with `generation.stream: false`. */
final class CrawlerPromptAnswer
{
    /**
     * @param list<array<string, mixed>> $sources the retrieved chunks the answer cites by id
     * @param list<int> $sourcesUsed the ids the answer cites
     * @param int $sourcesDropped retrieved chunks that did not fit the context
     * @param bool $truncated whether the model hit its output cap; the answer is still delivered
     * @param array<string, mixed> $search completeness, crawls_requested, crawls_searched, crawls_pruned_exact, skipped
     * @param int|null $apiCredit the charge, zero included
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $sourcesUsed,
        public readonly int $sourcesDropped,
        public readonly bool $truncated,
        public readonly array $search,
        public readonly ?int $apiCredit,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['prompt'] ?? ''),
            (string) ($data['answer'] ?? ''),
            $data['sources'] ?? [],
            $data['sources_used'] ?? [],
            (int) ($data['sources_dropped'] ?? 0),
            (bool) ($data['truncated'] ?? false),
            $data['search'] ?? [],
            isset($data['api_credit']) ? (int) $data['api_credit'] : null,
        );
    }
}
