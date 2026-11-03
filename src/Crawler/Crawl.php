<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

use Scrapfly\CrawlerConfig;
use Scrapfly\CrawlerContentFormat;
use Scrapfly\CrawlerSearchMode;
use Scrapfly\Exception\CrawlerException;
use Scrapfly\ScrapflyClient;

/**
 * One crawl, driven from start to its content.
 *
 * ```php
 * $crawl = (new Crawl($client, new CrawlerConfig(url: 'https://web-scraping.dev/products', pageLimit: 10)))
 *     ->start()
 *     ->wait();
 * foreach ($crawl->contents(CrawlerContentFormat::Markdown)->contents as $url => $formats) {
 *     echo $url, "\n";
 * }
 * ```
 */
final class Crawl
{
    private ?string $uuid = null;

    public function __construct(
        private readonly ScrapflyClient $client,
        private readonly ?CrawlerConfig $config = null,
    ) {
    }

    /** An existing crawl, by uuid. */
    public static function fromUuid(ScrapflyClient $client, string $uuid): self
    {
        $crawl = new self($client);
        $crawl->uuid = $uuid;

        return $crawl;
    }

    public function start(): self
    {
        if ($this->uuid !== null || $this->config === null) {
            throw new CrawlerException('The crawl is already started, or was opened by uuid', 'ERR::CRAWLER::ALREADY_STARTED');
        }
        $this->uuid = $this->client->startCrawl($this->config)['crawler_uuid'];

        return $this;
    }

    public function uuid(): string
    {
        return $this->uuid ?? throw new CrawlerException('The crawl is not started: call start() first', 'NOT_STARTED');
    }

    public function status(): CrawlerStatus
    {
        return $this->client->crawlStatus($this->uuid());
    }

    /**
     * Poll until the crawl ends. With `$searchable`, also wait for its search
     * index to answer queries, which it does after the crawl is DONE. A failed
     * crawl throws, and so does a cancelled one unless `$allowCancelled`.
     *
     * @param float $maxWait seconds; 0 waits without a limit
     */
    public function wait(float $pollInterval = 5.0, float $maxWait = 0.0, bool $searchable = false, bool $allowCancelled = false): self
    {
        $deadline = $maxWait > 0 ? microtime(true) + $maxWait : null;
        while (true) {
            $status = $this->status();
            $ended = $status->isFinished() || $status->status() === 'DONE' || $status->isCancelled();
            if ($ended && $status->isFailed()) {
                throw new CrawlerException("Crawl {$this->uuid()} failed: " . ($status->state()['stop_reason'] ?? 'unknown'), 'ERR::CRAWLER::FAILED');
            }
            if ($status->isCancelled() && !$allowCancelled) {
                throw new CrawlerException("Crawl {$this->uuid()} was cancelled", 'ERR::CRAWLER::CANCELLED');
            }
            $indexSettled = !$searchable || $status->isSearchable() || in_array($status->search()['status'] ?? 'DISABLED', ['FAILED', 'DISABLED'], true);
            if ($ended && $indexSettled) {
                return $this;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new CrawlerException("Crawl {$this->uuid()} is still {$status->status()} after {$maxWait}s", 'ERR::CRAWLER::TIMEOUT');
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    public function urls(string $status = 'visited', int $page = 1, int $perPage = 100): CrawlerUrls
    {
        return $this->client->crawlUrls($this->uuid(), $status, $page, $perPage);
    }

    public function contents(CrawlerContentFormat|string $format, ?int $limit = null, ?int $offset = null): CrawlerContents
    {
        return $this->client->crawlContents($this->uuid(), $format, $limit, $offset);
    }

    public function content(string $url, CrawlerContentFormat|string $format = CrawlerContentFormat::Markdown): string
    {
        return $this->client->crawlContent($this->uuid(), $url, $format);
    }

    public function artifact(string $type = 'warc'): CrawlerArtifact
    {
        return $this->client->crawlArtifact($this->uuid(), $type);
    }

    public function cancel(): bool
    {
        return $this->client->crawlCancel($this->uuid());
    }

    /** @param array<string, mixed>|null $filters */
    public function search(string $query, ?int $limit = null, CrawlerSearchMode|string|null $mode = null, ?array $filters = null, ?string $cursor = null): CrawlerSearchResponse
    {
        return $this->client->crawlSearch([$this->uuid()], $query, $limit, $mode, $filters, $cursor);
    }

    /**
     * @param array<string, mixed>|null $filters
     *
     * @return \Generator<int, CrawlerPromptEvent>
     */
    public function prompt(string $prompt, ?int $limit = null, CrawlerSearchMode|string|null $mode = null, ?array $filters = null, ?string $model = null): \Generator
    {
        return $this->client->crawlPrompt([$this->uuid()], $prompt, $limit, $mode, $filters, $model);
    }

    /** @param array<string, mixed>|null $filters */
    public function promptAnswer(string $prompt, ?int $limit = null, CrawlerSearchMode|string|null $mode = null, ?array $filters = null, ?string $model = null): CrawlerPromptAnswer
    {
        return $this->client->crawlPromptAnswer([$this->uuid()], $prompt, $limit, $mode, $filters, $model);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $filters
     */
    public function extract(string $prompt, array $schema, ?int $limit = null, CrawlerSearchMode|string|null $mode = null, ?array $filters = null): CrawlerExtractResponse
    {
        return $this->client->crawlExtract([$this->uuid()], $prompt, $schema, $limit, $mode, $filters);
    }
}
