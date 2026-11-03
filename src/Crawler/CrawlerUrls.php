<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** A page of a crawl's URLs, `GET /crawl/{uuid}/urls`. */
final class CrawlerUrls
{
    /** @param list<CrawlerUrl> $urls */
    public function __construct(
        public readonly array $urls,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /** The body is one URL per line, `url,reason` for failed and skipped URLs. */
    public static function fromText(string $body, string $status, int $page, int $perPage): self
    {
        $urls = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($status, ['visited', 'pending'], true) || !str_contains($line, ',')) {
                $urls[] = new CrawlerUrl($line, $status);
            } else {
                // A URL may hold a comma, a reason never does.
                $comma = (int) strrpos($line, ',');
                $urls[] = new CrawlerUrl(substr($line, 0, $comma), $status, substr($line, $comma + 1));
            }
        }

        return new self($urls, $page, $perPage);
    }
}
