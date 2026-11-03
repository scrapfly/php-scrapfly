<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** One URL of a crawl, and why it failed or was skipped. */
final class CrawlerUrl
{
    public function __construct(
        public readonly string $url,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {
    }
}
