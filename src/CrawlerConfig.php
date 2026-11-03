<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\CrawlerConfigException;

/**
 * A crawl, `POST /crawl`. Start one from exactly one of `url` (a seed the crawler
 * follows links from), `urlList` (the pages to fetch, uploaded with the request)
 * or `remoteUrlList` (the URL of a file listing them).
 *
 * ```php
 * $config = new CrawlerConfig(
 *     url: 'https://web-scraping.dev/products',
 *     pageLimit: 20,
 *     contentFormats: [CrawlerContentFormat::Markdown],
 *     search: true,
 * );
 * ```
 *
 * Unset options are not sent, so the API applies its own defaults.
 */
final class CrawlerConfig
{
    /** Shortest refresh period, in seconds. */
    public const REFRESH_MIN_INTERVAL = 3600;

    /** Longest refresh period, in seconds. */
    public const REFRESH_MAX_INTERVAL = 7776000;

    /** The Unblocker toggle, sent as `asp`. Set through `proxyPool`, or the deprecated `unblocker` and `asp`. */
    public bool $asp;

    /**
     * @param list<string>|null $urlList
     * @param list<string>|null $excludePaths
     * @param list<string>|null $includeOnlyPaths
     * @param list<string>|null $allowedExternalDomains
     * @param list<string>|null $allowedInternalSubdomains
     * @param array<string, string>|null $headers
     * @param list<CrawlerContentFormat|string>|null $contentFormats
     * @param array<string, mixed>|null $extractionRules
     * @param bool|null $search build a semantic search index of the crawl, queried by search, prompt and extract
     * @param bool|null $refresh re-scrape the crawl's own URLs in place on a period, same uuid
     * @param int|null $refreshInterval seconds between refresh runs, REFRESH_MIN_INTERVAL to REFRESH_MAX_INTERVAL
     * @param ProxyPool|string|null $proxyPool the proxy pool of every page; a string for a pool of your own
     * @param list<string>|null $webhookEvents
     * @param bool|null $unblocker deprecated: use `proxyPool` (`ProxyPool::UnblockerUltra` for the Unblocker)
     * @param bool|null $asp deprecated: use `proxyPool` (`ProxyPool::UnblockerUltra` for the Unblocker)
     */
    public function __construct(
        public ?string $url = null,
        public ?array $urlList = null,
        public ?string $remoteUrlList = null,
        public ?int $pageLimit = null,
        public ?int $maxDepth = null,
        public ?int $maxDuration = null,
        public ?int $maxApiCredit = null,
        public ?array $excludePaths = null,
        public ?array $includeOnlyPaths = null,
        public ?bool $ignoreBasePathRestriction = null,
        public ?bool $followExternalLinks = null,
        public ?array $allowedExternalDomains = null,
        public ?bool $followInternalSubdomains = null,
        public ?array $allowedInternalSubdomains = null,
        public ?array $headers = null,
        public ?int $delay = null,
        public ?string $userAgent = null,
        public ?int $maxConcurrency = null,
        public ?int $renderingDelay = null,
        public ?bool $useSitemaps = null,
        public ?bool $respectRobotsTxt = null,
        public ?bool $ignoreNoFollow = null,
        public ?bool $cache = null,
        public ?int $cacheTtl = null,
        public ?bool $cacheClear = null,
        public ?array $contentFormats = null,
        public ?array $extractionRules = null,
        public ?bool $search = null,
        public ?bool $refresh = null,
        public ?int $refreshInterval = null,
        public ProxyPool|string|null $proxyPool = null,
        public ?string $country = null,
        public ?string $webhookName = null,
        public ?array $webhookEvents = null,
        ?bool $unblocker = null,
        ?bool $asp = null,
    ) {
        $seeds = count(array_filter([$url, $urlList, $remoteUrlList], static fn ($seed): bool => $seed !== null));
        if ($seeds !== 1) {
            throw new CrawlerConfigException('Pass exactly one of url, urlList or remoteUrlList');
        }
        if ($urlList === []) {
            throw new CrawlerConfigException('urlList is empty');
        }
        if ($excludePaths !== null && $includeOnlyPaths !== null) {
            throw new CrawlerConfigException('excludePaths and includeOnlyPaths are exclusive');
        }
        if ($refreshInterval !== null && ($refreshInterval < self::REFRESH_MIN_INTERVAL || $refreshInterval > self::REFRESH_MAX_INTERVAL)) {
            throw new CrawlerConfigException(sprintf('refreshInterval must be %d to %d seconds', self::REFRESH_MIN_INTERVAL, self::REFRESH_MAX_INTERVAL));
        }
        foreach ($contentFormats ?? [] as $format) {
            if (is_string($format) && CrawlerContentFormat::tryFrom($format) === null) {
                throw new CrawlerConfigException("Invalid content format: {$format}");
            }
        }
        // `asp` is the deprecated alias of `unblocker`: an explicit `asp` wins.
        $this->asp = $asp ?? $unblocker ?? false;
    }

    /**
     * The JSON body of `POST /crawl`. `url_list` stays in it: the client uploads it
     * as a multipart part of its own.
     *
     * @return array<string, mixed>
     */
    public function toApiBody(): array
    {
        $body = [
            'url' => $this->url,
            'url_list' => $this->urlList,
            'remote_url_list' => $this->remoteUrlList,
            'page_limit' => $this->pageLimit,
            'max_depth' => $this->maxDepth,
            'max_duration' => $this->maxDuration,
            'max_api_credit' => $this->maxApiCredit,
            'exclude_paths' => $this->excludePaths,
            'include_only_paths' => $this->includeOnlyPaths,
            'ignore_base_path_restriction' => $this->ignoreBasePathRestriction,
            'follow_external_links' => $this->followExternalLinks,
            'allowed_external_domains' => $this->allowedExternalDomains,
            'follow_internal_subdomains' => $this->followInternalSubdomains,
            'allowed_internal_subdomains' => $this->allowedInternalSubdomains,
            'headers' => $this->headers,
            'delay' => $this->delay,
            'user_agent' => $this->userAgent,
            'max_concurrency' => $this->maxConcurrency,
            'rendering_delay' => $this->renderingDelay,
            'use_sitemaps' => $this->useSitemaps,
            'respect_robots_txt' => $this->respectRobotsTxt,
            'ignore_no_follow' => $this->ignoreNoFollow,
            'cache' => $this->cache,
            'cache_ttl' => $this->cacheTtl,
            'cache_clear' => $this->cacheClear,
            'content_formats' => $this->contentFormats === null ? null : array_map(Util::value(...), $this->contentFormats),
            'extraction_rules' => $this->extractionRules,
            'search' => $this->search,
            'refresh' => $this->refresh,
            'refresh_interval' => $this->refreshInterval,
            'proxy_pool' => $this->proxyPool === null ? null : Util::value($this->proxyPool),
            'country' => $this->country,
            'webhook_name' => $this->webhookName,
            'webhook_events' => $this->webhookEvents,
            // Only true is sent, as every other SDK does: the API reads an absent
            // and a false `asp` the same way.
            'asp' => $this->asp ? true : null,
        ];

        return array_filter($body, static fn (mixed $value): bool => $value !== null);
    }
}
