<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** The stored content of a crawl's pages, `GET /crawl/{uuid}/contents`. */
final class CrawlerContents
{
    /**
     * @param array<string, array<string, string>> $contents url => format => content
     * @param array<string, mixed> $links next and previous page links
     */
    public function __construct(
        public readonly array $contents,
        public readonly array $links,
    ) {
    }
}
