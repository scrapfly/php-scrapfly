<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

/** A crawl's WARC or HAR archive. The SDK ships no parser for either. */
final class CrawlerArtifact
{
    public function __construct(
        public readonly string $type,
        public readonly string $data,
    ) {
    }

    public function save(string $path): void
    {
        if (file_put_contents($path, $this->data) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }
}
