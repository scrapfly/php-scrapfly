<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/**
 * One frame of the `POST /crawl/prompt` stream: `source` frames first, then
 * `token` frames, then one `done`. An `error` frame is thrown, never yielded.
 */
final class CrawlerPromptEvent
{
    /**
     * @param array<string, mixed>|string $data a text delta for `token`, an object otherwise:
     *     a source (id, crawler_uuid, url, title, score) or the done frame
     *     (sources_used, sources_dropped, truncated, api_credit)
     */
    public function __construct(
        public readonly string $event,
        public readonly array|string $data,
    ) {
    }

    public function isToken(): bool
    {
        return $this->event === 'token';
    }

    public function isDone(): bool
    {
        return $this->event === 'done';
    }
}
