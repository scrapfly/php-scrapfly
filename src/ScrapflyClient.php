<?php

declare(strict_types=1);

namespace Scrapfly;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Scrapfly\Crawler\CrawlerArtifact;
use Scrapfly\Crawler\CrawlerContents;
use Scrapfly\Crawler\CrawlerExtractResponse;
use Scrapfly\Crawler\CrawlerPromptAnswer;
use Scrapfly\Crawler\CrawlerPromptEvent;
use Scrapfly\Crawler\CrawlerSearchResponse;
use Scrapfly\Crawler\CrawlerStatus;
use Scrapfly\Crawler\CrawlerUrls;
use Scrapfly\Exception\ApiHttpClientException;
use Scrapfly\Exception\ApiHttpServerException;
use Scrapfly\Exception\BadApiKeyException;
use Scrapfly\Exception\CloudBrowserException;
use Scrapfly\Exception\ConfigException;
use Scrapfly\Exception\CrawlerConfigException;
use Scrapfly\Exception\CrawlerException;
use Scrapfly\Exception\ExtractionApiException;
use Scrapfly\Exception\HttpException;
use Scrapfly\Exception\ProxyException;
use Scrapfly\Exception\ScheduleException;
use Scrapfly\Exception\ScrapeConfigException;
use Scrapfly\Exception\ScrapeException;
use Scrapfly\Exception\ScrapflyException;
use Scrapfly\Exception\ScreenshotApiException;
use Scrapfly\Exception\SessionException;
use Scrapfly\Exception\ThrottleException;
use Scrapfly\Exception\TooManyRequestsException;
use Scrapfly\Exception\TransportException;
use Scrapfly\Exception\UnblockerException;
use Scrapfly\Exception\UpstreamHttpClientException;
use Scrapfly\Exception\UpstreamHttpException;
use Scrapfly\Exception\UpstreamHttpServerException;
use Scrapfly\Exception\WebhookException;

/**
 * Client of every Scrapfly API: scrape, screenshot, extraction and crawler.
 *
 * ```php
 * $client = new ScrapflyClient(key: (string) getenv('SCRAPFLY_KEY'));
 * $response = $client->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/products'));
 * echo $response->content();
 * ```
 *
 * One instance is safe to reuse for every call.
 */
final class ScrapflyClient
{
    public const VERSION = '0.0.1';

    public const DEFAULT_HOST = 'https://api.scrapfly.io';

    public const DEFAULT_CLOUD_BROWSER_HOST = 'wss://browser.scrapfly.io';

    /** Past the Scrape API's 155 s read timeout, so its own answer arrives first. */
    private const READ_TIMEOUT = 160.0;

    /** Prompt and extract: retrieval plus up to 150 s of generation. */
    private const GENERATION_TIMEOUT = 180.0;

    private const CONNECT_TIMEOUT = 30.0;

    /** The most configs one batch takes. */
    public const BATCH_MAX_CONFIGS = 100;

    /** Pauses of a batch read finding nothing yet, doubled from the first to the last. */
    private const BATCH_POLL_MIN_MICROSECONDS = 1_000;

    private const BATCH_POLL_MAX_MICROSECONDS = 20_000;

    /** Attempts of a request whose connection failed. A timeout is never retried. */
    private const CONNECT_TRIES = 3;

    /** Seconds an unblock may take past its navigation timeout: the browser is allocated first. */
    private const UNBLOCK_TIMEOUT_MARGIN = 60;

    /** The target answered an error status: the scrape ran. */
    private const BAD_UPSTREAM_RESPONSE = 'ERR::SCRAPE::BAD_UPSTREAM_RESPONSE';

    /** cURL's code for a timeout, connect or read. */
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** @var array<string, class-string<HttpException>> */
    private const RESOURCE_EXCEPTIONS = [
        'SCRAPE' => ScrapeException::class,
        'WEBHOOK' => WebhookException::class,
        'PROXY' => ProxyException::class,
        'SCHEDULE' => ScheduleException::class,
        'ASP' => UnblockerException::class,
        'SESSION' => SessionException::class,
        'THROTTLE' => ThrottleException::class,
        'CRAWLER' => CrawlerException::class,
        'SCHEDULER' => ScheduleException::class,
        'BROWSER' => CloudBrowserException::class,
    ];

    private readonly string $host;

    /** The CDP WebSocket host, wss:// */
    private readonly string $cloudBrowserHost;

    /** The same host over https://, for the Cloud Browser REST calls. */
    private readonly string $cloudBrowserApiHost;

    private readonly ClientInterface $http;

    /**
     * @param ClientInterface|null $httpClient a Guzzle client of your own, for a proxy or middleware;
     *     the SDK sets its timeouts, headers and query on every request it sends
     * @param bool|null $verify TLS verification; null keeps the Guzzle client's own setting, on by default
     * @param string $cloudBrowserHost the Cloud Browser's wss:// host; its REST calls go to the same host over https://
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
        string $host = self::DEFAULT_HOST,
        ?ClientInterface $httpClient = null,
        private readonly ?bool $verify = null,
        string $cloudBrowserHost = self::DEFAULT_CLOUD_BROWSER_HOST,
    ) {
        if ($key === '') {
            throw new BadApiKeyException('The API key must be a non-empty string');
        }
        $this->host = rtrim($host, '/');
        $this->cloudBrowserHost = rtrim($cloudBrowserHost, '/');
        $this->cloudBrowserApiHost = (string) preg_replace('#^ws(s?)://#', 'http$1://', $this->cloudBrowserHost);
        $this->http = $httpClient ?? new Client();
    }

    /** @return array<string, mixed> what var_dump() and print_r() show: the key masked */
    public function __debugInfo(): array
    {
        return ['key' => substr($this->key, 0, 9) . '...', 'host' => $this->host, 'cloudBrowserHost' => $this->cloudBrowserHost, 'verify' => $this->verify ?? 'client default'];
    }

    // -------------------------------------------------------------------------
    // Account
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> the account, its subscription and usage */
    public function account(): array
    {
        $response = $this->send('GET', '/account', ['key' => $this->key]);

        return $this->decodeApiResponse($response);
    }

    /** Whether the API key is accepted. */
    public function verifyApiKey(): bool
    {
        $response = $this->send('GET', '/account', ['key' => $this->key]);
        if ($response->getStatusCode() === 401) {
            return false;
        }
        $this->decodeApiResponse($response);

        return true;
    }

    // -------------------------------------------------------------------------
    // Web Scraping API
    // -------------------------------------------------------------------------

    /**
     * Scrape a page.
     *
     * Throws an UpstreamHttpClientException or UpstreamHttpServerException when the
     * target answered an error status, unless `raiseOnUpstreamError` is off.
     */
    public function scrape(ScrapeConfig $config): ScrapeResponse
    {
        if ($config->proxifiedResponse) {
            throw new ConfigException('A proxified scrape answers the target\'s response: call scrapeProxified()');
        }
        $response = $this->send($config->method, '/scrape', $config->toApiParams($this->key), $this->scrapeOptions($config));

        return $this->scrapeResponse($config, $response);
    }

    /**
     * Scrape a page and return the target's own response: its status, headers and
     * body, with the Scrapfly details in `X-Scrapfly-*` headers. A failed scrape is
     * thrown from its `X-Scrapfly-Reject-*` headers, a target's error status as an
     * UpstreamHttpClientException or UpstreamHttpServerException.
     */
    public function scrapeProxified(ScrapeConfig $config): ResponseInterface
    {
        $params = $config->toApiParams($this->key);
        $params['proxified_response'] = 'true';
        $response = $this->send($config->method, '/scrape', $params, $this->scrapeOptions($config));

        $rejection = $this->rejection($response);
        if ($rejection !== null && !($rejection instanceof UpstreamHttpException && !$config->raiseOnUpstreamError)) {
            throw $rejection;
        }
        // A scrape refused (concurrency, quota) or failed after the target answered
        // (an Unblocker block) comes back as the API's own scrape envelope, not as
        // the target's response.
        if (str_starts_with($response->getHeaderLine('content-type'), 'application/json')) {
            $data = json_decode((string) $response->getBody(), true);
            $response->getBody()->rewind();
            if (is_array($data) && isset($data['result'], $data['config'], $data['context']) && is_array($data['result']['error'] ?? null)) {
                $this->scrapeResponse($config, $response);
            }
        }

        return $response;
    }

    /**
     * Scrape several pages at once, at most `$concurrency` in flight; the
     * account's concurrency limit when null.
     *
     * @param list<ScrapeConfig> $configs
     *
     * @return list<ScrapeResponse|ScrapflyException> in the order of `$configs`: a
     *     failed scrape is its exception, returned rather than thrown
     */
    public function concurrentScrape(array $configs, ?int $concurrency = null): array
    {
        $concurrency ??= (int) ($this->account()['subscription']['usage']['scrape']['concurrent_limit'] ?? 1);
        $results = [];
        $options = [];
        foreach ($configs as $index => $config) {
            try {
                if ($config->proxifiedResponse) {
                    throw new ConfigException('A proxified scrape answers the target\'s response: call scrapeProxified()');
                }
                $options[$index] = $this->requestOptions($config->toApiParams($this->key), $this->scrapeOptions($config));
            } catch (ScrapflyException $exception) {
                $results[$index] = $exception;
            }
        }
        // Connection failures are not retried here: each one is that scrape's result.
        $requests = function () use ($configs, $options): \Generator {
            foreach ($options as $index => $requestOptions) {
                yield $index => fn () => $this->http->sendAsync(new Request($configs[$index]->method, $this->host . '/scrape'), $requestOptions);
            }
        };
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function (ResponseInterface $response, int $index) use ($configs, &$results): void {
                try {
                    $results[$index] = $this->scrapeResponse($configs[$index], $response);
                } catch (ScrapflyException $exception) {
                    $results[$index] = $exception;
                }
            },
            'rejected' => function (mixed $reason, int $index) use (&$results): void {
                $results[$index] = $reason instanceof GuzzleException
                    ? $this->transportException($reason)
                    : new ScrapflyException(Util::maskKey($reason instanceof \Throwable ? $reason->getMessage() : 'request failed'));
            },
        ]);
        $pool->promise()->wait();
        ksort($results);

        return array_values($results);
    }

    /**
     * Scrape up to 100 pages in one request. Results come as each scrape finishes,
     * keyed by the correlation id of its config, so each config needs one of its own.
     * A failed scrape is yielded as its exception, not thrown; a proxified config
     * (`proxifiedResponse: true`) yields the target's own response, a PSR-7 one.
     *
     * ```php
     * foreach ($client->scrapeBatch($configs) as $correlationId => $result) {
     *     echo $correlationId, ': ', match (true) {
     *         $result instanceof ScrapflyException => $result->getMessage(),
     *         $result instanceof ResponseInterface => $result->getStatusCode(),
     *         default => $result->statusCode(),
     *     }, PHP_EOL;
     * }
     * ```
     *
     * @param list<ScrapeConfig> $configs
     * @param string|null $format `json` or `msgpack`: how each part is encoded, json when null
     *
     * @return \Generator<string, ScrapeResponse|ResponseInterface|ScrapflyException>
     */
    public function scrapeBatch(array $configs, ?string $format = null): \Generator
    {
        [$body, $byCorrelation] = $this->batchBody($configs, $format);
        // Sent once: the batch is billed as soon as it is accepted.
        // Decoded here, not by Guzzle: its zlib stream filter reads on until 8 KB of output,
        // so a part would wait for the next one.
        // On the stream handler `timeout` is the socket's read timeout, which also bounds the
        // wait for the status line: the API sends it with the first part, after the fastest scrape.
        $response = $this->send('POST', '/scrape/batch', ['key' => $this->key], self::batchOptions($body, $format) + [
            RequestOptions::STREAM => true,
            RequestOptions::DECODE_CONTENT => false,
            RequestOptions::TIMEOUT => self::READ_TIMEOUT,
        ], tries: 1);
        if ($response->getStatusCode() !== 200) {
            $inflate = self::inflater($response->getHeaderLine('content-encoding'));
            $raw = (string) $response->getBody();
            $data = self::decodeBody($response->getHeaderLine('content-type'), $inflate === null ? $raw : (string) inflate_add($inflate, $raw, ZLIB_FINISH));
            throw $this->apiException(is_array($data) ? $data : ['message' => (string) $data], $response->getStatusCode(), ApiHttpClientException::class, $response->getHeaderLine('retry-after'));
        }

        return $this->batchResults($response, new MultipartReader($response->getHeaderLine('content-type')), $byCorrelation);
    }

    /**
     * scrapeBatch() without blocking, on Guzzle's promises: each result goes to
     * `$onResult` as soon as its part arrives, while this promise or any other of
     * the client is waited on, so a batch runs beside a Pool or your own
     * requestAsync() calls. The promise is fulfilled once every part was handed
     * over, and rejected with the batch's own refusal, a transport error, or what
     * `$onResult` threw, which stops the transfer. A failed scrape is a part, never
     * a rejection. Configs the API cannot take (no correlationId, two sharing one)
     * throw at the call, before anything is sent. A part whose content is too large
     * for the API to inline is handed over once the transfer is over, its content
     * fetched then.
     *
     * ```php
     * $promise = $client->scrapeBatchAsync($configs, function (string $correlationId, $result): void {
     *     echo $correlationId, ': ', $result instanceof ScrapflyException ? $result->getMessage() : 'done', PHP_EOL;
     * });
     * $promise->wait();
     * ```
     *
     * @param list<ScrapeConfig> $configs each with a correlationId of its own
     * @param callable(string, ScrapeResponse|ResponseInterface|ScrapflyException): void $onResult
     * @param string|null $format `json` or `msgpack`: how each part is encoded, json when null
     */
    public function scrapeBatchAsync(array $configs, callable $onResult, ?string $format = null): PromiseInterface
    {
        [$body, $byCorrelation] = $this->batchBody($configs, $format);
        $fallback = reset($byCorrelation);
        // Set by the callbacks below as the transfer goes.
        /** @var MultipartReader|null $reader */
        $reader = null;
        $refusal = '';
        /** @var \Throwable|null $failure */
        $failure = null;
        /** @var list<array{array<string, string>, string}> $late */
        $late = [];
        $deliver = function (array $parts, bool $fetchLargeObjects = false) use ($onResult, $byCorrelation, $fallback, &$late): void {
            foreach ($parts as [$headers, $content]) {
                // Its large object cannot be fetched from inside the transfer's callbacks.
                if (!$fetchLargeObjects && ($headers['x-scrapfly-large-object-inlined'] ?? '') === 'false') {
                    $late[] = [$headers, $content];

                    continue;
                }
                $correlationId = $headers['x-scrapfly-correlation-id'] ?? '';
                $onResult($correlationId, $this->batchPart($headers, $content, $byCorrelation[$correlationId] ?? $fallback, $fetchLargeObjects));
            }
        };
        // The body is read as it is written: a part goes out the moment its last byte lands.
        $sink = FnStream::decorate(Utils::streamFor(''), [
            'write' => static function (string $bytes) use (&$reader, &$refusal, &$failure, $deliver): int {
                if ($reader === null) {
                    $refusal .= $bytes;

                    return strlen($bytes);
                }
                try {
                    $deliver($reader->feed($bytes));
                } catch (\Throwable $throwable) {
                    $failure = $throwable;

                    // A short write aborts the transfer.
                    return 0;
                }

                return strlen($bytes);
            },
        ]);
        $options = $this->requestOptions(['key' => $this->key], self::batchOptions($body, $format) + [
            RequestOptions::SINK => $sink,
            RequestOptions::TIMEOUT => 0,
        ] + (\defined('CURLOPT_LOW_SPEED_TIME') ? ['curl' => [\CURLOPT_LOW_SPEED_LIMIT => 1, \CURLOPT_LOW_SPEED_TIME => (int) self::READ_TIMEOUT]] : []) + [
            RequestOptions::ON_HEADERS => static function (ResponseInterface $response) use (&$reader, &$failure): void {
                if ($response->getStatusCode() !== 200) {
                    return;
                }
                try {
                    $reader = new MultipartReader($response->getHeaderLine('content-type'));
                } catch (ScrapflyException $exception) {
                    $failure = $exception;
                }
            },
        ]);

        // Sent once: the batch is billed as soon as it is accepted.
        return $this->http->requestAsync('POST', $this->host . '/scrape/batch', $options)->then(
            function (ResponseInterface $response) use (&$reader, &$refusal, &$failure, &$late, $deliver): void {
                if ($failure !== null) {
                    throw $failure;
                }
                if ($reader === null) {
                    $data = self::decodeBody($response->getHeaderLine('content-type'), $refusal);
                    throw $this->apiException(is_array($data) ? $data : ['message' => (string) $data], $response->getStatusCode(), ApiHttpClientException::class, $response->getHeaderLine('retry-after'));
                }
                $reader->close();
                $deliver($late, fetchLargeObjects: true);
            },
            function (mixed $reason) use (&$failure): never {
                if ($failure !== null) {
                    throw $failure;
                }
                throw $reason instanceof GuzzleException ? $this->transportException($reason) : $reason;
            },
        );
    }

    /**
     * Tell whether a response you fetched yourself is an anti-bot block, and by which
     * anti-bot, without scraping again.
     *
     * @param array<string, string|list<string>> $headers the response headers, PSR-7's getHeaders() as is
     * @param string|null $body bytes that are not UTF-8 are sent as U+FFFD
     *
     * @return array{blocked: bool, antibot: ?string, cost: int}
     */
    public function classify(string $url, int $statusCode, array $headers = [], ?string $body = null, string $method = 'GET'): array
    {
        if ($url === '' || $statusCode < 100 || $statusCode > 599) {
            throw new ConfigException('classify takes a URL and an HTTP status of 100 to 599');
        }
        $payload = ['url' => $url, 'status_code' => $statusCode, 'method' => $method]
            + ($headers === [] ? [] : ['headers' => array_map(static fn (string|array $value): string => is_array($value) ? implode(', ', $value) : $value, $headers)])
            + ($body === null ? [] : ['body' => $body]);
        $data = $this->decodeApiResponse($this->send('POST', '/classify', ['key' => $this->key], [
            RequestOptions::BODY => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            RequestOptions::HEADERS => ['content-type' => 'application/json'],
        ]));

        return ['blocked' => ($data['blocked'] ?? false) === true, 'antibot' => isset($data['antibot']) ? (string) $data['antibot'] : null, 'cost' => (int) ($data['cost'] ?? 0)];
    }

    // -------------------------------------------------------------------------
    // Monitoring API
    // -------------------------------------------------------------------------

    /**
     * A product's metrics over a period.
     *
     * @param string|null $period `subscription`, `last7d`, `last24h`, `last1h`, ...
     * @param list<string> $aggregation `account`, `project` and/or `target`
     *
     * @return array<string, mixed> account_metrics, projects_metrics, targets_metrics, as aggregated
     */
    public function monitoringMetrics(MonitoringProduct $product = MonitoringProduct::WebScraping, ?string $period = null, array $aggregation = [], bool $includeWebhook = false, string $format = 'structured'): array
    {
        $params = array_filter([
            'format' => $format,
            'period' => $period,
            'aggregation' => $aggregation === [] ? null : implode(',', $aggregation),
            'include_webhook' => $includeWebhook ? 'true' : null,
        ], static fn (?string $value): bool => $value !== null);

        return $this->decodeApiResponse($this->send('GET', '/' . $product->value . '/monitoring/metrics', ['key' => $this->key] + $params));
    }

    /**
     * A product's metrics of one target domain, over a period or between two dates.
     *
     * @return array<string, mixed>
     */
    public function monitoringTargetMetrics(
        string $domain,
        MonitoringProduct $product = MonitoringProduct::WebScraping,
        ?string $period = 'last24h',
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
        bool $groupSubdomain = false,
        bool $includeWebhook = false,
    ): array {
        $params = ['domain' => $domain, 'group_subdomain' => Util::bool($groupSubdomain)] + self::monitoringWindow($period, $start, $end);
        if ($includeWebhook) {
            $params['include_webhook'] = 'true';
        }

        return $this->decodeApiResponse($this->send('GET', '/' . $product->value . '/monitoring/metrics/target', ['key' => $this->key] + $params));
    }

    /**
     * Cloud Browser session metrics: runs, durations, bandwidth, by proxy pool.
     *
     * @return array<string, mixed> account_metrics, proxy_pool_breakdown
     */
    public function browserMonitoringMetrics(?string $period = null, ?string $proxyPool = null, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): array
    {
        $params = self::monitoringWindow($period, $start, $end) + ($proxyPool === null ? [] : ['proxy_pool' => $proxyPool]);

        return $this->decodeApiResponse($this->send('GET', '/browser/monitoring/metrics', ['key' => $this->key] + $params));
    }

    /** @return array<string, mixed> the same figures per time bucket */
    public function browserMonitoringTimeseries(?string $period = null, ?string $proxyPool = null, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): array
    {
        $params = self::monitoringWindow($period, $start, $end) + ($proxyPool === null ? [] : ['proxy_pool' => $proxyPool]);

        return $this->decodeApiResponse($this->send('GET', '/browser/monitoring/metrics/timeseries', ['key' => $this->key] + $params));
    }

    // -------------------------------------------------------------------------
    // Schedule API
    // -------------------------------------------------------------------------

    /**
     * Run a scrape on a recurrence, delivered to a webhook.
     *
     * @param array<string, mixed> $scrapeConfig the scrape in API option names: url, render_js, asp, country, headers, ...
     *
     * @return array<string, mixed> the schedule: id, kind, status, recurrence, next_scheduled_date, metadata, ...
     */
    public function createScrapeSchedule(array $scrapeConfig, ScheduleRequest $request): array
    {
        return $this->scheduleJson('POST', '/scrape/schedules', body: ['scrape_config' => $scrapeConfig] + $request->toArray());
    }

    /**
     * @param array<string, mixed> $screenshotConfig the screenshot in API option names
     *
     * @return array<string, mixed>
     */
    public function createScreenshotSchedule(array $screenshotConfig, ScheduleRequest $request): array
    {
        return $this->scheduleJson('POST', '/screenshot/schedules', body: ['screenshot_config' => $screenshotConfig] + $request->toArray());
    }

    /**
     * A scheduled crawl takes `url` or `remote_url_list`: an inline url_list is refused.
     *
     * @param array<string, mixed> $crawlerConfig the crawl in API option names
     *
     * @return array<string, mixed>
     */
    public function createCrawlerSchedule(array $crawlerConfig, ScheduleRequest $request): array
    {
        return $this->scheduleJson('POST', '/crawl/schedules', body: ['crawler_config' => $crawlerConfig] + $request->toArray());
    }

    /** @return array<string, mixed> */
    public function getSchedule(string $scheduleId): array
    {
        return $this->scheduleJson('GET', '/schedules/' . rawurlencode($scheduleId));
    }

    /**
     * @param string|null $kind `api.scrape`, `api.screenshot` or `api.crawler`
     * @param string|null $status `ACTIVE`, `PAUSED` or `CANCELLED`
     *
     * @return list<array<string, mixed>>
     */
    public function listSchedules(?string $kind = null, ?string $status = null): array
    {
        return $this->scheduleJson('GET', '/schedules', array_filter(['kind' => $kind, 'status' => $status], static fn (?string $value): bool => $value !== null));
    }

    /** @return list<array<string, mixed>> */
    public function listScrapeSchedules(?string $status = null): array
    {
        return $this->scheduleJson('GET', '/scrape/schedules', $status === null ? [] : ['status' => $status]);
    }

    /** @return list<array<string, mixed>> */
    public function listScreenshotSchedules(?string $status = null): array
    {
        return $this->scheduleJson('GET', '/screenshot/schedules', $status === null ? [] : ['status' => $status]);
    }

    /** @return list<array<string, mixed>> */
    public function listCrawlerSchedules(?string $status = null): array
    {
        return $this->scheduleJson('GET', '/crawl/schedules', $status === null ? [] : ['status' => $status]);
    }

    /** @return array<string, mixed> */
    public function updateSchedule(string $scheduleId, ScheduleUpdate $update): array
    {
        return $this->scheduleJson('PATCH', '/schedules/' . rawurlencode($scheduleId), body: (object) $update->toArray());
    }

    public function cancelSchedule(string $scheduleId): void
    {
        $this->scheduleJson('DELETE', '/schedules/' . rawurlencode($scheduleId));
    }

    /** @return array<string, mixed> */
    public function pauseSchedule(string $scheduleId): array
    {
        return $this->scheduleJson('POST', '/schedules/' . rawurlencode($scheduleId) . '/pause');
    }

    /** @return array<string, mixed> */
    public function resumeSchedule(string $scheduleId): array
    {
        return $this->scheduleJson('POST', '/schedules/' . rawurlencode($scheduleId) . '/resume');
    }

    /**
     * Fire a schedule now, outside its recurrence. Billed like a fire, so sent once.
     *
     * @return array<string, mixed>
     */
    public function executeSchedule(string $scheduleId): array
    {
        return $this->scheduleJson('POST', '/schedules/' . rawurlencode($scheduleId) . '/execute', tries: 1);
    }

    // -------------------------------------------------------------------------
    // Screenshot API
    // -------------------------------------------------------------------------

    public function screenshot(ScreenshotConfig $config): ScreenshotResponse
    {
        $response = $this->send('GET', '/screenshot', $config->toApiParams($this->key));
        $body = (string) $response->getBody();
        $contentType = $response->getHeaderLine('content-type');
        $data = str_starts_with($contentType, 'application/json') ? json_decode($body, true) : null;
        if ($response->getStatusCode() === 201 && is_array($data) && ($data['webhook_enqueued'] ?? false) === true) {
            return new ScreenshotResponse('', $contentType, null, null, (string) ($data['job_uuid'] ?? ''));
        }

        if ($response->getStatusCode() >= 300 || str_starts_with($contentType, 'application/json')) {
            $data = json_decode($body, true);
            throw $this->apiException(
                is_array($data) ? $data : ['message' => $body],
                $response->getStatusCode(),
                ScreenshotApiException::class,
            );
        }
        $upstreamStatus = $response->getHeaderLine('x-scrapfly-upstream-http-code');

        return new ScreenshotResponse(
            $body,
            $contentType,
            $upstreamStatus === '' ? null : (int) $upstreamStatus,
            $response->getHeaderLine('x-scrapfly-upstream-url') ?: null,
        );
    }

    // -------------------------------------------------------------------------
    // Extraction API
    // -------------------------------------------------------------------------

    public function extract(ExtractionConfig $config): ExtractionResponse
    {
        $headers = ['content-type' => $config->contentType];
        if ($config->documentCompressionFormat !== null) {
            $headers['content-encoding'] = Util::value($config->documentCompressionFormat);
        }
        $response = $this->send('POST', '/extraction', $config->toApiParams($this->key), [
            RequestOptions::HEADERS => $headers,
            RequestOptions::BODY => $config->body,
        ]);
        $data = $this->decodeJson($response);
        if ($response->getStatusCode() >= 300 || isset($data['error_id'])) {
            throw $this->apiException($data, $response->getStatusCode(), ExtractionApiException::class);
        }

        return new ExtractionResponse($data['data'] ?? null, $data['content_type'] ?? null, isset($data['job_uuid']) ? (string) $data['job_uuid'] : null);
    }

    // -------------------------------------------------------------------------
    // Cloud Browser API
    // -------------------------------------------------------------------------

    /**
     * The CDP WebSocket URL of a Cloud Browser session, for Playwright, Puppeteer or
     * any CDP client. A refused session answers a JSON error frame, then a close
     * frame of code 1008, 1011 or 1013 whose reason starts with the ERR::BROWSER code.
     */
    public function cloudBrowserUrl(?BrowserConfig $config = null): string
    {
        $query = ['api_key' => $this->key] + ($config ?? new BrowserConfig())->toQuery();

        return $this->cloudBrowserHost . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The first 8 hex characters of the API key's SHA-256: the X-Browser-Project-Salt
     * header of a VNC session, and the prefix of its VNC password.
     */
    public function cloudBrowserProjectSalt(): string
    {
        return substr(hash('sha256', $this->key), 0, 8);
    }

    /**
     * The password a native VNC client types on the TCP endpoint (port 5901):
     * `<project salt>-<vncPassword>`. The WebSocket endpoint /run/<run_id>/vnc
     * takes the raw vncPassword.
     */
    public function cloudBrowserVncPassword(BrowserConfig $config): string
    {
        if ($config->enableVnc !== true || $config->vncPassword === null || $config->vncPassword === '') {
            throw new ConfigException('enableVnc and vncPassword must both be set');
        }

        return $this->cloudBrowserProjectSalt() . '-' . $config->vncPassword;
    }

    /**
     * Open a URL past its anti-bot and get the browser, ready for CDP.
     *
     * @return array<string, mixed> ws_url, session_id, run_id, and mcp_endpoint when enableMcp is set
     */
    public function cloudBrowserUnblock(UnblockConfig $config): array
    {
        // Sent once: a retry would run the Unblocker navigation again. Its navigation may take 300 s.
        return $this->browserJson('POST', '/unblock', [
            RequestOptions::JSON => $config->toApiBody(),
            RequestOptions::TIMEOUT => max(self::READ_TIMEOUT, ($config->timeout ?? 0) + self::UNBLOCK_TIMEOUT_MARGIN),
        ], tries: 1);
    }

    public function cloudBrowserSessionStop(string $sessionId): void
    {
        $this->browserJson('POST', '/session/' . rawurlencode($sessionId) . '/stop');
    }

    /** @return array<string, mixed> sessions, total: the account's running sessions */
    public function cloudBrowserSessions(): array
    {
        return $this->browserJson('GET', '/sessions');
    }

    /** @return array<string, mixed> available, status (`uploading`, `ready`, `unavailable`), metadata, video_url, retry_after_ms */
    public function cloudBrowserPlayback(string $runId): array
    {
        return $this->browserJson('GET', '/run/' . rawurlencode($runId) . '/playback');
    }

    /**
     * Poll the recording of a debug session until it is no longer `uploading`, or
     * $timeout seconds ran out. The server's retry_after_ms paces the polls.
     *
     * @return array<string, mixed> the last playback answer
     */
    public function cloudBrowserWaitForPlayback(string $runId, float $timeout = 180.0, float $pollInterval = 3.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $playback = $this->cloudBrowserPlayback($runId);
            $remaining = $deadline - microtime(true);
            if (($playback['status'] ?? null) !== 'uploading' || $remaining <= 0) {
                return $playback;
            }
            $wait = isset($playback['retry_after_ms']) ? $playback['retry_after_ms'] / 1000 : $pollInterval;
            usleep((int) (min($wait, $remaining) * 1_000_000));
        }
    }

    /** The video of a debug session, as served. */
    public function cloudBrowserVideo(string $runId): string
    {
        $response = $this->send('GET', '/run/' . rawurlencode($runId) . '/video', ['key' => $this->key], [RequestOptions::HEADERS => ['accept' => '*/*']], host: $this->cloudBrowserApiHost);
        if ($response->getStatusCode() >= 300) {
            throw $this->browserException($response);
        }

        return (string) $response->getBody();
    }

    /** @return array<string, mixed> extensions, quota (used, limit) */
    public function cloudBrowserExtensionList(): array
    {
        return $this->browserJson('GET', '/extension');
    }

    /** @return array<string, mixed> */
    public function cloudBrowserExtensionGet(string $extensionId): array
    {
        return $this->browserJson('GET', '/extension/' . rawurlencode($extensionId));
    }

    /**
     * Upload a .zip or .crx extension. One with the id of a stored extension replaces it.
     *
     * @return array<string, mixed> extension, is_update
     */
    public function cloudBrowserExtensionUpload(string $filePath): array
    {
        try {
            // The stream owns the handle and closes it when it is released.
            $file = Utils::streamFor(Utils::tryFopen($filePath, 'r'));
        } catch (\RuntimeException $exception) {
            throw new ConfigException("Cannot read {$filePath}: {$exception->getMessage()}");
        }

        return $this->browserJson('POST', '/extension', [RequestOptions::MULTIPART => [['name' => 'file', 'contents' => $file, 'filename' => basename($filePath)]]]);
    }

    /** @return array<string, mixed> extension, is_update */
    public function cloudBrowserExtensionUploadFromUrl(string $extensionUrl): array
    {
        return $this->browserJson('POST', '/extension', [RequestOptions::JSON => ['extension_url' => $extensionUrl]]);
    }

    /** @return array<string, mixed> */
    public function cloudBrowserExtensionDelete(string $extensionId): array
    {
        return $this->browserJson('DELETE', '/extension/' . rawurlencode($extensionId));
    }

    /**
     * Create a credential vault. The answer carries the vault key once: the server
     * keeps no copy, so save it at once.
     *
     * @return array<string, mixed> vault, key, message
     */
    public function cloudBrowserVaultCreate(string $name, ?string $description = null): array
    {
        // Sent once: the key comes back from this answer only, a second vault's would replace it.
        return $this->browserJson('POST', '/vault', [RequestOptions::JSON => array_filter(['name' => $name, 'description' => $description], static fn (?string $value): bool => $value !== null)], tries: 1);
    }

    /** @return array<string, mixed> vaults */
    public function cloudBrowserVaultList(): array
    {
        return $this->browserJson('GET', '/vault');
    }

    /** @return array<string, mixed> vault */
    public function cloudBrowserVaultGet(string $vaultId): array
    {
        return $this->browserJson('GET', '/vault/' . rawurlencode($vaultId));
    }

    /** @return array<string, mixed> */
    public function cloudBrowserVaultUpdate(string $vaultId, ?string $name = null, ?string $description = null): array
    {
        $body = array_filter(['name' => $name, 'description' => $description], static fn (?string $value): bool => $value !== null);

        return $this->browserJson('PATCH', '/vault/' . rawurlencode($vaultId), [RequestOptions::JSON => (object) $body]);
    }

    /** @return array<string, mixed> */
    public function cloudBrowserVaultDelete(string $vaultId): array
    {
        return $this->browserJson('DELETE', '/vault/' . rawurlencode($vaultId));
    }

    /**
     * Re-key a vault: every item is encrypted again under a new key, returned once.
     *
     * @return array<string, mixed> key, message
     */
    public function cloudBrowserVaultRotate(string $vaultId, #[\SensitiveParameter] string $currentVaultKey): array
    {
        return $this->browserJson('POST', '/vault/' . rawurlencode($vaultId) . '/rotate', self::vaultKeyHeader($currentVaultKey));
    }

    /** @return array<string, mixed> items, their secrets left out */
    public function cloudBrowserVaultItemList(string $vaultId): array
    {
        return $this->browserJson('GET', '/vault/' . rawurlencode($vaultId) . '/item');
    }

    /**
     * @param string $type `password`, `totp`, ...: the shape of $secret
     * @param array<string, mixed> $secret encrypted under the vault key before it is stored
     *
     * @return array<string, mixed> item, message
     */
    public function cloudBrowserVaultItemCreate(
        string $vaultId,
        #[\SensitiveParameter]
        string $vaultKey,
        string $type,
        string $label,
        string $origin,
        #[\SensitiveParameter]
        array $secret,
        ?string $username = null,
    ): array {
        $body = ['type' => $type, 'label' => $label, 'origin' => $origin, 'secret' => $secret] + ($username === null ? [] : ['username' => $username]);

        return $this->browserJson('POST', '/vault/' . rawurlencode($vaultId) . '/item', [RequestOptions::JSON => $body] + self::vaultKeyHeader($vaultKey));
    }

    /**
     * Only what is passed changes. A new secret needs the vault key, which encrypts
     * it, and its type.
     *
     * @param array<string, mixed>|null $secret
     *
     * @return array<string, mixed>
     */
    public function cloudBrowserVaultItemUpdate(
        string $vaultId,
        string $itemId,
        #[\SensitiveParameter]
        ?string $vaultKey = null,
        ?string $label = null,
        ?string $origin = null,
        ?string $username = null,
        #[\SensitiveParameter]
        ?array $secret = null,
        ?string $type = null,
    ): array {
        if ($secret !== null && ($vaultKey === null || $vaultKey === '' || $type === null)) {
            throw new ConfigException('A new secret needs vaultKey and type');
        }
        $body = array_filter(['label' => $label, 'origin' => $origin, 'username' => $username, 'secret' => $secret, 'type' => $secret === null ? null : $type], static fn (mixed $value): bool => $value !== null);

        return $this->browserJson('PATCH', '/vault/' . rawurlencode($vaultId) . '/item/' . rawurlencode($itemId), [RequestOptions::JSON => (object) $body] + ($vaultKey === null ? [] : self::vaultKeyHeader($vaultKey)));
    }

    /** @return array<string, mixed> */
    public function cloudBrowserVaultItemDelete(string $vaultId, string $itemId): array
    {
        return $this->browserJson('DELETE', '/vault/' . rawurlencode($vaultId) . '/item/' . rawurlencode($itemId));
    }

    /**
     * Mirror a vault from an external secret manager. The token is sealed under the
     * vault key, which the server checks first.
     *
     * @param array<string, mixed> $linkedServiceData the provider's settings, such as the upstream vault id
     *
     * @return array<string, mixed> vault, message
     */
    public function cloudBrowserVaultServiceLink(
        string $vaultId,
        #[\SensitiveParameter]
        string $vaultKey,
        #[\SensitiveParameter]
        string $token,
        array $linkedServiceData,
        string $linkedService = '1password',
    ): array {
        $body = ['linked_service' => $linkedService, 'token' => $token, 'linked_service_data' => (object) self::withoutTokenItem($linkedServiceData)];

        return $this->browserJson('POST', '/vault/' . rawurlencode($vaultId) . '/service', [RequestOptions::JSON => $body] + self::vaultKeyHeader($vaultKey));
    }

    /**
     * Change a link's settings or token. A new token needs the vault key, which
     * seals it; switching provider takes an unlink then a link.
     *
     * @param array<string, mixed>|null $linkedServiceData
     *
     * @return array<string, mixed> vault, message
     */
    public function cloudBrowserVaultServiceUpdate(
        string $vaultId,
        ?array $linkedServiceData = null,
        #[\SensitiveParameter]
        ?string $token = null,
        #[\SensitiveParameter]
        ?string $vaultKey = null,
    ): array {
        if ($token !== null && $token !== '' && ($vaultKey === null || $vaultKey === '')) {
            throw new ConfigException('A new token needs vaultKey');
        }
        $body = ['token' => $token ?? ''] + ($linkedServiceData === null ? [] : ['linked_service_data' => (object) self::withoutTokenItem($linkedServiceData)]);

        return $this->browserJson('PATCH', '/vault/' . rawurlencode($vaultId) . '/service', [RequestOptions::JSON => $body] + ($vaultKey === null ? [] : self::vaultKeyHeader($vaultKey)));
    }

    /**
     * @param bool $keepItems keep the mirrored items as items of the vault
     *
     * @return array<string, mixed> message
     */
    public function cloudBrowserVaultServiceUnlink(string $vaultId, bool $keepItems = true): array
    {
        return $this->browserJson('DELETE', '/vault/' . rawurlencode($vaultId) . '/service', query: ['keep_items' => Util::bool($keepItems)]);
    }

    /**
     * Sync now, past the TTL and the back-off of a failed run.
     *
     * @return array<string, mixed> imported, updated, deleted, ...
     */
    public function cloudBrowserVaultServiceSync(string $vaultId, #[\SensitiveParameter] string $vaultKey): array
    {
        return $this->browserJson('POST', '/vault/' . rawurlencode($vaultId) . '/service/sync', self::vaultKeyHeader($vaultKey));
    }

    /**
     * Check the provider credentials and list the upstream vaults they see; reads no
     * secret. Without a token, the one sealed in the vault is tested.
     *
     * @return array<string, mixed> vaults_visible, item_count, ...
     */
    public function cloudBrowserVaultServiceTest(
        string $vaultId,
        #[\SensitiveParameter]
        string $vaultKey,
        #[\SensitiveParameter]
        ?string $token = null,
        ?string $linkedService = null,
    ): array {
        $body = array_filter(['linked_service' => $linkedService, 'token' => $token], static fn (?string $value): bool => $value !== null);
        // No body at all rather than {}: the server reads a probe without one as "test the sealed token".
        $options = $body === [] ? [] : [RequestOptions::JSON => $body];

        return $this->browserJson('POST', '/vault/' . rawurlencode($vaultId) . '/service/test', $options + self::vaultKeyHeader($vaultKey));
    }

    // -------------------------------------------------------------------------
    // Crawler API
    // -------------------------------------------------------------------------

    /**
     * Start a crawl. Poll it with crawlStatus(), or drive it with the Crawl helper.
     *
     * @return array{crawler_uuid: string, status: string}
     */
    public function startCrawl(CrawlerConfig $config): array
    {
        $body = $config->toApiBody();
        if (isset($body['url_list'])) {
            // The URLs go up as a file part of their own, streamed to the crawler.
            $urls = implode("\n", $body['url_list']);
            unset($body['url_list']);
            $options = [RequestOptions::MULTIPART => [
                // Cast: a config of only a urlList is empty here, and an empty PHP array encodes as a JSON list.
                ['name' => 'config', 'contents' => Util::json((object) $body), 'filename' => 'config.json', 'headers' => ['Content-Type' => 'application/json']],
                ['name' => 'urls', 'contents' => $urls, 'filename' => 'urls.txt', 'headers' => ['Content-Type' => 'text/plain']],
            ]];
        } else {
            $options = [RequestOptions::JSON => $body];
        }
        $response = $this->send('POST', '/crawl', ['key' => $this->key], $options);
        $data = $this->crawlerJson($response);
        $uuid = (string) ($data['crawler_uuid'] ?? $data['uuid'] ?? '');
        if ($uuid === '') {
            throw new CrawlerException('The API started no crawl: its answer carries no crawler_uuid', httpStatusCode: $response->getStatusCode(), apiResponse: $data);
        }

        return ['crawler_uuid' => $uuid, 'status' => (string) ($data['status'] ?? '')];
    }

    public function crawlStatus(string $uuid): CrawlerStatus
    {
        $response = $this->send('GET', '/crawl/' . rawurlencode($uuid) . '/status', ['key' => $this->key]);

        return new CrawlerStatus($this->crawlerJson($response));
    }

    /**
     * @param string $status `visited`, `pending`, `failed` or `skipped`
     */
    public function crawlUrls(string $uuid, string $status = 'visited', int $page = 1, int $perPage = 100): CrawlerUrls
    {
        $response = $this->send('GET', '/crawl/' . rawurlencode($uuid) . '/urls', [
            'key' => $this->key,
            'status' => $status,
            'page' => (string) $page,
            'per_page' => (string) $perPage,
        ], [RequestOptions::HEADERS => ['accept' => 'text/plain, application/json']]);
        if (str_contains($response->getHeaderLine('content-type'), 'application/json') || $response->getStatusCode() >= 300) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        return CrawlerUrls::fromText((string) $response->getBody(), $status, $page, $perPage);
    }

    /** The stored content of a crawl's pages, in one format. */
    public function crawlContents(string $uuid, CrawlerContentFormat|string $format, ?int $limit = null, ?int $offset = null): CrawlerContents
    {
        $params = ['key' => $this->key, 'formats' => Util::value($format)];
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }
        if ($offset !== null) {
            $params['offset'] = (string) $offset;
        }
        $data = $this->crawlerJson($this->send('GET', '/crawl/' . rawurlencode($uuid) . '/contents', $params));

        return new CrawlerContents($data['contents'] ?? [], $data['links'] ?? []);
    }

    /** One page of a crawl, as stored. */
    public function crawlContent(string $uuid, string $url, CrawlerContentFormat|string $format = CrawlerContentFormat::Markdown): string
    {
        $response = $this->send('GET', '/crawl/' . rawurlencode($uuid) . '/contents', [
            'key' => $this->key,
            'formats' => Util::value($format),
            'url' => $url,
            'plain' => 'true',
        ], [RequestOptions::HEADERS => ['accept' => '*/*']]);
        if ($response->getStatusCode() >= 300) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        return (string) $response->getBody();
    }

    /** @param string $type `warc` or `har` */
    public function crawlArtifact(string $uuid, string $type = 'warc'): CrawlerArtifact
    {
        $response = $this->send('GET', '/crawl/' . rawurlencode($uuid) . '/artifact', ['key' => $this->key, 'type' => $type], [
            RequestOptions::HEADERS => ['accept' => '*/*'],
        ]);
        if ($response->getStatusCode() >= 300) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        return new CrawlerArtifact($type, (string) $response->getBody());
    }

    /** Stop a crawl. Cancelling a finished crawl is a no-op. */
    public function crawlCancel(string $uuid): bool
    {
        $response = $this->send('POST', '/crawl/' . rawurlencode($uuid) . '/cancel', ['key' => $this->key]);
        if ($response->getStatusCode() >= 300) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        return true;
    }

    /**
     * Re-scrape a crawl's own URLs now, in place: same uuid, same artifacts, same
     * search index. Billed like the crawl, so it is sent once.
     *
     * @return array<string, mixed> the refresh block: enabled, interval_seconds, status, generation, next_run_at, history, ...
     */
    public function crawlRefreshNow(string $uuid): array
    {
        return self::refreshBlock($this->crawlerJson($this->send('POST', '/crawl/' . rawurlencode($uuid) . '/refresh', ['key' => $this->key], tries: 1)));
    }

    /**
     * Change a crawl's refresh schedule. Only what is passed changes, so turning it
     * off keeps the interval for when it is turned back on.
     *
     * @param int|null $intervalSeconds CrawlerConfig::REFRESH_MIN_INTERVAL to REFRESH_MAX_INTERVAL
     *
     * @return array<string, mixed> the refresh block
     */
    public function crawlRefreshSettings(string $uuid, ?bool $enabled = null, ?int $intervalSeconds = null): array
    {
        if ($enabled === null && $intervalSeconds === null) {
            throw new CrawlerConfigException('Pass enabled, intervalSeconds or both');
        }
        if ($intervalSeconds !== null && ($intervalSeconds < CrawlerConfig::REFRESH_MIN_INTERVAL || $intervalSeconds > CrawlerConfig::REFRESH_MAX_INTERVAL)) {
            throw new CrawlerConfigException(sprintf('intervalSeconds must be %d to %d', CrawlerConfig::REFRESH_MIN_INTERVAL, CrawlerConfig::REFRESH_MAX_INTERVAL));
        }
        // The request names what POST /crawl names; the answer is the refresh block.
        $body = array_filter(['refresh' => $enabled, 'refresh_interval' => $intervalSeconds], static fn (bool|int|null $value): bool => $value !== null);

        return self::refreshBlock($this->crawlerJson($this->send('PATCH', '/crawl/' . rawurlencode($uuid) . '/refresh', ['key' => $this->key], [RequestOptions::JSON => $body])));
    }

    /** @return list<array<string, mixed>> the crawl's refresh runs, newest last; the API keeps the last 50 */
    public function crawlRefreshHistory(string $uuid, ?int $limit = null): array
    {
        $params = ['key' => $this->key];
        if ($limit !== null) {
            $params['limit'] = (string) $limit;
        }

        return self::refreshBlock($this->crawlerJson($this->send('GET', '/crawl/' . rawurlencode($uuid) . '/refresh/history', $params)))['history'] ?? [];
    }

    /**
     * The refresh calls answer the block under `refresh`, next to the crawl's uuid.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function refreshBlock(array $data): array
    {
        return is_array($data['refresh'] ?? null) ? $data['refresh'] : $data;
    }

    /**
     * Search the indexes of one or more crawls started with `search: true`, as one
     * merged ranking. A crawl whose index is not ready is listed in `skipped()`.
     *
     * @param list<string> $crawlIds
     * @param array<string, mixed>|null $filters url_prefix, host, source_format, content_type, http_status, crawler_uuid
     * @param string|null $cursor the cursor of the previous page
     */
    public function crawlSearch(
        array $crawlIds,
        string $query,
        ?int $limit = null,
        CrawlerSearchMode|string|null $mode = null,
        ?array $filters = null,
        ?string $cursor = null,
    ): CrawlerSearchResponse {
        $this->validateCrawlIds($crawlIds);
        if ($query === '') {
            throw new CrawlerConfigException('The query must be a non-empty string');
        }
        $body = ['query' => $query, 'crawl_ids' => $crawlIds] + $this->retrieval($limit, $mode, $filters);
        if ($cursor !== null) {
            $body['cursor'] = $cursor;
        }
        $response = $this->send('POST', '/crawl/search', ['key' => $this->key], [RequestOptions::JSON => $body]);

        return new CrawlerSearchResponse($this->crawlerJson($response));
    }

    /**
     * Answer a question from the content of one or more crawls, streamed: `source`
     * events, then `token` events, then one `done`. Generation can fail after tokens
     * were yielded: a CrawlerException is thrown at that point.
     *
     * Sent once, never retried: the call runs a fan-out and a generation, both billed.
     *
     * @param list<string> $crawlIds
     * @param array<string, mixed>|null $filters
     *
     * @return \Generator<int, CrawlerPromptEvent>
     */
    public function crawlPrompt(
        array $crawlIds,
        string $prompt,
        ?int $limit = null,
        CrawlerSearchMode|string|null $mode = null,
        ?array $filters = null,
        ?string $model = null,
    ): \Generator {
        $body = $this->promptBody($crawlIds, $prompt, true, $limit, $mode, $filters, $model);
        $response = $this->send('POST', '/crawl/prompt', ['key' => $this->key], [
            RequestOptions::JSON => $body,
            RequestOptions::HEADERS => ['accept' => 'text/event-stream'],
            RequestOptions::STREAM => true,
            RequestOptions::TIMEOUT => self::GENERATION_TIMEOUT,
        ], tries: 1);
        if ($response->getStatusCode() !== 200) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        yield from $this->promptEvents($response);
    }

    /**
     * Answer a question from the content of one or more crawls, the whole answer at once.
     *
     * @param list<string> $crawlIds
     * @param array<string, mixed>|null $filters
     */
    public function crawlPromptAnswer(
        array $crawlIds,
        string $prompt,
        ?int $limit = null,
        CrawlerSearchMode|string|null $mode = null,
        ?array $filters = null,
        ?string $model = null,
    ): CrawlerPromptAnswer {
        $body = $this->promptBody($crawlIds, $prompt, false, $limit, $mode, $filters, $model);

        return CrawlerPromptAnswer::fromArray($this->generate('/crawl/prompt', $body));
    }

    /**
     * Extract an object shaped by a JSON Schema from the content of one or more
     * crawls. The schema is handed to the model as its response schema: types,
     * enums and `required` are what constrain it.
     *
     * @param list<string> $crawlIds
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $filters
     */
    public function crawlExtract(
        array $crawlIds,
        string $prompt,
        array $schema,
        ?int $limit = null,
        CrawlerSearchMode|string|null $mode = null,
        ?array $filters = null,
    ): CrawlerExtractResponse {
        $this->validateCrawlIds($crawlIds);
        if ($prompt === '') {
            throw new CrawlerConfigException('The prompt must be a non-empty string');
        }
        if ($schema === [] || array_is_list($schema)) {
            throw new CrawlerConfigException('The schema must be a non-empty JSON Schema object');
        }
        $body = ['prompt' => $prompt, 'crawl_ids' => $crawlIds, 'schema' => $schema];
        $search = $this->retrieval($limit, $mode, $filters);
        if ($search !== []) {
            $body['search'] = $search;
        }

        return CrawlerExtractResponse::fromArray($this->generate('/crawl/extract', $body));
    }

    // -------------------------------------------------------------------------
    // Requests
    // -------------------------------------------------------------------------

    /**
     * @param array<array-key, string> $query
     * @param array<string, mixed> $options Guzzle request options
     */
    private function send(
        string $method,
        string $path,
        #[\SensitiveParameter]
        array $query,
        #[\SensitiveParameter]
        array $options = [],
        int $tries = self::CONNECT_TRIES,
        ?string $host = null,
    ): ResponseInterface
    {
        $options = $this->requestOptions($query, $options);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->http->request($method, ($host ?? $this->host) . $path, $options);
            } catch (ConnectException $exception) {
                if ($attempt >= $tries || $this->isTimeout($exception)) {
                    throw $this->transportException($exception);
                }
                usleep(100_000 * 2 ** ($attempt - 1));
            } catch (GuzzleException $exception) {
                throw $this->transportException($exception);
            }
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestOptions(#[\SensitiveParameter] array $query, #[\SensitiveParameter] array $options): array
    {
        $headers = array_change_key_case($options[RequestOptions::HEADERS] ?? [], CASE_LOWER) + [
            'user-agent' => 'PHP Scrapfly SDK/' . self::VERSION,
            'accept' => 'application/json',
            'accept-encoding' => 'gzip, deflate',
        ];

        return array_replace([
            RequestOptions::QUERY => http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            RequestOptions::HTTP_ERRORS => false,
            // The API never redirects. A target's 3xx a proxified scrape passes on
            // must not be followed from here, outside Scrapfly's proxies.
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
            RequestOptions::TIMEOUT => self::READ_TIMEOUT,
            RequestOptions::DECODE_CONTENT => true,
        ], $this->verify === null ? [] : [RequestOptions::VERIFY => $this->verify], $options, [RequestOptions::HEADERS => $headers]);
    }

    /** @return array<string, mixed> */
    private function scrapeOptions(ScrapeConfig $config): array
    {
        if ($config->body === null) {
            return [];
        }

        return [
            RequestOptions::BODY => $config->body,
            RequestOptions::HEADERS => ['content-type' => $config->headers['content-type'] ?? 'application/json'],
        ];
    }

    private function isTimeout(#[\SensitiveParameter] GuzzleException $exception): bool
    {
        $context = $exception instanceof RequestException || $exception instanceof ConnectException ? $exception->getHandlerContext() : [];

        return ($context['errno'] ?? null) === self::CURLE_OPERATION_TIMEDOUT
            || str_contains(strtolower($exception->getMessage()), 'timed out');
    }

    /** The HTTP client's exception is not chained: its message prints the key. */
    private function transportException(#[\SensitiveParameter] GuzzleException $exception): TransportException
    {
        return new TransportException(Util::maskKey($exception->getMessage()), $this->isTimeout($exception));
    }

    // -------------------------------------------------------------------------
    // Responses
    // -------------------------------------------------------------------------

    private function scrapeResponse(ScrapeConfig $config, ResponseInterface $response): ScrapeResponse
    {
        if ($config->method === 'HEAD') {
            // A HEAD answer carries no body: the result is built from its headers,
            // a failure from its X-Scrapfly-Reject-* headers.
            $rejection = $this->rejection($response);
            if ($rejection !== null && !($rejection instanceof UpstreamHttpException && !$config->raiseOnUpstreamError)) {
                throw $rejection;
            }
            $status = $response->getStatusCode();
            $targetHeaders = array_filter(
                $response->getHeaders(),
                static fn (string $name): bool => !str_starts_with(strtolower($name), 'x-scrapfly-'),
                ARRAY_FILTER_USE_KEY,
            );

            return new ScrapeResponse([
                'config' => $config->toApiParams('REDACTED'),
                'context' => [],
                'result' => [
                    'status' => 'DONE',
                    'success' => $status >= 200 && $status < 300,
                    'status_code' => $status,
                    'content' => '',
                    'format' => 'text',
                    'response_headers' => array_map(static fn (array $values): string => implode(', ', $values), $targetHeaders),
                    'request_headers' => [],
                ],
            ], $response->getHeaders());
        }

        $data = $this->decodeJson($response);
        if (!isset($data['result']) || !is_array($data['result'])) {
            throw $this->apiException($data, $response->getStatusCode(), ApiHttpClientException::class);
        }

        return $this->scrapeEnvelope($config, $data, $response->getHeaders(), $response->getStatusCode(), $response->getHeaderLine('retry-after'), $config->raiseOnUpstreamError);
    }

    /**
     * The result of a scrape envelope, from a /scrape answer or a batch part.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string>> $headers
     */
    private function scrapeEnvelope(ScrapeConfig $config, array $data, array $headers, int $httpStatus, string $retryAfterHeader, bool $raiseOnUpstreamError, bool $fetchLargeObjects = true): ScrapeResponse
    {
        $format = $data['result']['format'] ?? null;
        if (($format === 'clob' || $format === 'blob') && $fetchLargeObjects) {
            $data['result'] = $this->fetchLargeObject($data['result'], $format);
        } elseif ($format === 'binary' && ($data['result']['content'] ?? '') !== '') {
            // An inline binary document comes base64 encoded; a large one is fetched as its bytes.
            $bytes = base64_decode((string) $data['result']['content'], true);
            if ($bytes === false && ($data['result']['success'] ?? false) === true) {
                throw new ScrapflyException('The binary content is not valid base64');
            }
            $data['result']['content'] = $bytes === false ? $data['result']['content'] : $bytes;
        }
        $scrape = new ScrapeResponse($data, $headers);
        $result = $data['result'];
        // QUEUED: a scrape with a webhook, delivered to it later.
        if (($result['status'] ?? null) === 'QUEUED' || (($result['status'] ?? null) === 'DONE' && ($result['success'] ?? false) === true)) {
            return $scrape;
        }

        // A rejected scrape (concurrency, quota) is `status: DONE` with a null
        // `success`: the code is in `error` whatever `success` says.
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        $code = (string) ($error['code'] ?? $result['status'] ?? '');
        $statusCode = (int) ($result['status_code'] ?? $error['http_code'] ?? $httpStatus);
        $message = (string) ($error['message'] ?? $code);
        $retryable = ($error['retryable'] ?? false) === true;
        $retryAfter = $retryable ? (int) ($retryAfterHeader ?: 5) : null;
        $links = is_array($error['links'] ?? null) ? $error['links'] : [];
        $documentation = $error['doc_url'] ?? $links['Related Error Doc'] ?? 'https://scrapfly.io/docs/scrape-api/errors';

        if ($code === self::BAD_UPSTREAM_RESPONSE && !$raiseOnUpstreamError) {
            return $scrape;
        }

        // A failed scrape carries the target's status: its 401 or 429 says nothing of the account.
        $targetStatus = ($result['success'] ?? null) === false;

        throw $this->exceptionFor($code, $message, $statusCode, $retryable, $retryAfter, $documentation, $data, targetStatus: $targetStatus);
    }

    /**
     * The failure a proxified or HEAD answer reports in its X-Scrapfly-Reject-*
     * headers. A failed scrape names its `ERR::` code there and its status in
     * Reject-Http-Code; a request refused before any scrape puts its status in
     * Reject-Code and has no code.
     */
    private function rejection(ResponseInterface $response): ?ScrapflyException
    {
        $reject = $response->getHeaderLine('x-scrapfly-reject-code');
        if ($reject === '') {
            return null;
        }
        $retryable = $response->getHeaderLine('x-scrapfly-reject-retryable') === 'true';
        $retryAfter = $response->getHeaderLine('retry-after');
        if (ctype_digit($reject)) {
            $code = '';
            $status = (int) $reject;
        } elseif ($reject === self::BAD_UPSTREAM_RESPONSE) {
            // The target's own status is the response's.
            $code = $reject;
            $status = $response->getStatusCode();
        } else {
            $code = $reject;
            $httpCode = $response->getHeaderLine('x-scrapfly-reject-http-code');
            $status = $httpCode === '' ? $response->getStatusCode() : (int) $httpCode;
        }

        return $this->exceptionFor(
            $code,
            $response->getHeaderLine('x-scrapfly-reject-description') ?: $reject,
            $status,
            $retryable,
            $retryable && $retryAfter !== '' ? (int) $retryAfter : null,
            $response->getHeaderLine('x-scrapfly-reject-doc') ?: null,
            null,
        );
    }

    /**
     * A content past the inline limit is served apart: fetch it in its place.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function fetchLargeObject(array $result, string $format): array
    {
        $url = (string) ($result['content'] ?? '');
        $parts = parse_url($url);
        // The key only goes to the API host.
        if (!is_array($parts) || !isset($parts['path']) || ($parts['host'] ?? null) !== parse_url($this->host, PHP_URL_HOST)) {
            throw new ScrapflyException('The large object URL is not on the API host: ' . Util::maskKey($url));
        }
        parse_str($parts['query'] ?? '', $query);
        $query = array_map(strval(...), array_filter($query, is_string(...)));
        $response = $this->send('GET', $parts['path'], ['key' => $this->key] + $query, [RequestOptions::HEADERS => ['accept' => '*/*']]);
        if ($response->getStatusCode() !== 200) {
            throw $this->apiException($this->decodeJson($response), $response->getStatusCode(), ApiHttpClientException::class);
        }
        $result['content'] = (string) $response->getBody();
        $result['format'] = $format === 'clob' ? 'text' : 'binary';

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function generate(string $path, array $body): array
    {
        // Sent once: a retry would run the fan-out and the generation again.
        $response = $this->send('POST', $path, ['key' => $this->key], [
            RequestOptions::JSON => $body,
            RequestOptions::TIMEOUT => self::GENERATION_TIMEOUT,
        ], tries: 1);
        if ($response->getStatusCode() !== 200) {
            throw $this->crawlerException($response, $this->decodeJson($response));
        }

        return $this->decodeJson($response, strict: true);
    }

    /**
     * @param list<string> $crawlIds
     * @param array<string, mixed>|null $filters
     *
     * @return array<string, mixed>
     */
    private function promptBody(array $crawlIds, string $prompt, bool $stream, ?int $limit, CrawlerSearchMode|string|null $mode, ?array $filters, ?string $model): array
    {
        $this->validateCrawlIds($crawlIds);
        if ($prompt === '') {
            throw new CrawlerConfigException('The prompt must be a non-empty string');
        }
        $generation = ['stream' => $stream];
        if ($model !== null) {
            $generation['model'] = $model;
        }
        $body = ['prompt' => $prompt, 'crawl_ids' => $crawlIds, 'generation' => $generation];
        $search = $this->retrieval($limit, $mode, $filters);
        if ($search !== []) {
            $body['search'] = $search;
        }

        return $body;
    }

    /**
     * @param array<string, mixed>|null $filters
     *
     * @return array<string, mixed>
     */
    private function retrieval(?int $limit, CrawlerSearchMode|string|null $mode, ?array $filters): array
    {
        $search = [];
        if ($limit !== null) {
            $search['limit'] = $limit;
        }
        if ($mode !== null) {
            $search['mode'] = is_string($mode)
                ? (CrawlerSearchMode::tryFrom($mode) ?? throw new CrawlerConfigException("Invalid search mode: {$mode}"))->value
                : $mode->value;
        }
        if ($filters !== null) {
            if (array_is_list($filters) && $filters !== []) {
                throw new CrawlerConfigException('The search filters must be an associative array');
            }
            $search['filters'] = (object) $filters;
        }

        return $search;
    }

    /** @param list<string> $crawlIds */
    private function validateCrawlIds(array $crawlIds): void
    {
        if ($crawlIds === []) {
            throw new CrawlerConfigException('crawl_ids must contain at least one crawler UUID');
        }
        if (in_array('', $crawlIds, true)) {
            throw new CrawlerConfigException('crawl_ids contains an empty crawler UUID');
        }
        if (count(array_unique($crawlIds)) !== count($crawlIds)) {
            throw new CrawlerConfigException('crawl_ids contains a duplicate crawler UUID');
        }
    }

    /** @return \Generator<int, CrawlerPromptEvent> */
    private function promptEvents(ResponseInterface $response): \Generator
    {
        $body = $response->getBody();
        $buffer = '';
        $done = false;
        // The connection is released however the stream ends: done, an error frame,
        // or a caller that stops iterating.
        try {
            while (!$done && !$body->eof()) {
                $buffer .= $body->read(8192);
                $buffer = str_replace("\r\n", "\n", $buffer);
                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 2);
                    $event = $this->promptEvent($frame);
                    if ($event === null) {
                        continue;
                    }
                    yield $event;
                    if ($event->isDone()) {
                        $done = true;
                        break;
                    }
                }
            }
        } finally {
            $body->close();
        }
        if (!$done) {
            throw new CrawlerException('The prompt stream ended without its done frame', 'ERR::CRAWLER::PROMPT_GENERATION_FAILED');
        }
    }

    private function promptEvent(string $frame): ?CrawlerPromptEvent
    {
        $event = null;
        $data = [];
        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }
        // Comment frames (`:keepalive`) carry nothing.
        if ($event === null || $data === []) {
            return null;
        }
        try {
            $payload = json_decode(implode("\n", $data), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CrawlerException("The prompt stream sent a {$event} frame that is not JSON: {$exception->getMessage()}", 'ERR::CRAWLER::PROMPT_GENERATION_FAILED');
        }
        if ($event === 'error') {
            $payload = is_array($payload) ? $payload : ['message' => (string) $payload];

            throw new CrawlerException((string) ($payload['message'] ?? 'prompt generation failed'), (string) ($payload['code'] ?? 'ERR::CRAWLER::PROMPT_GENERATION_FAILED'));
        }

        return new CrawlerPromptEvent($event, is_array($payload) ? $payload : (string) $payload);
    }

    /** @return array<string, mixed> */
    private function decodeJson(ResponseInterface $response, bool $strict = false): array
    {
        $body = (string) $response->getBody();
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            if ($strict) {
                throw new ScrapflyException('The API answered a body that is not JSON: ' . $exception->getMessage(), httpStatusCode: $response->getStatusCode());
            }

            return ['message' => substr($body, 0, 500)];
        }

        return is_array($data) ? $data : ['message' => $body];
    }

    /** @return array<string, mixed> */
    private function decodeApiResponse(ResponseInterface $response): array
    {
        $data = $this->decodeJson($response);
        if ($response->getStatusCode() >= 300 || isset($data['error_id'])) {
            throw $this->apiException($data, $response->getStatusCode(), ApiHttpClientException::class);
        }

        return $data;
    }

    /**
     * The `configs` of a batch, and its configs by correlation id. Refuses a batch
     * before anything is sent.
     *
     * @param list<ScrapeConfig> $configs
     *
     * @return array{list<array<string, string>>, non-empty-array<string, ScrapeConfig>}
     */
    private function batchBody(array $configs, ?string $format): array
    {
        if ($configs === [] || count($configs) > self::BATCH_MAX_CONFIGS) {
            throw new ScrapeConfigException('A batch takes 1 to ' . self::BATCH_MAX_CONFIGS . ' configs, not ' . count($configs), 'ERR::SCRAPE::BATCH_CONFIG', 400);
        }
        if ($format !== null && !in_array($format, ['json', 'msgpack'], true)) {
            throw new ScrapeConfigException("A batch answers json or msgpack, not {$format}");
        }
        $byCorrelation = [];
        $body = [];
        foreach (array_values($configs) as $index => $config) {
            $correlationId = (string) $config->correlationId;
            if ($correlationId === '') {
                throw new ScrapeConfigException("configs[{$index}] has no correlationId: the parts of a batch are matched by it", 'ERR::SCRAPE::BATCH_CONFIG', 422);
            }
            if (isset($byCorrelation[$correlationId])) {
                throw new ScrapeConfigException("correlationId {$correlationId} is used by two configs", 'ERR::SCRAPE::BATCH_CONFIG', 422);
            }
            $byCorrelation[$correlationId] = $config;
            $params = $config->toApiParams($this->key);
            unset($params['key']);
            // What a single scrape sends as the request itself.
            $params['method'] = $config->method;
            if ($config->body !== null) {
                $params['body'] = $config->body;
            }
            $body[] = $params;
        }

        return [$body, $byCorrelation];
    }

    /**
     * A batch takes as long as its slowest scrape: no total timeout, a read timeout
     * between two chunks instead (the stream handler's read_timeout, curl's low-speed
     * limit, set by each caller).
     *
     * @param list<array<string, string>> $body
     *
     * @return array<string, mixed>
     */
    private static function batchOptions(array $body, ?string $format): array
    {
        return [
            RequestOptions::JSON => ['configs' => $body],
            RequestOptions::HEADERS => ['accept' => $format === 'msgpack' ? 'application/msgpack' : 'application/json'],
            RequestOptions::READ_TIMEOUT => self::READ_TIMEOUT,
        ];
    }

    /**
     * @param non-empty-array<string, ScrapeConfig> $byCorrelation
     *
     * @return \Generator<string, ScrapeResponse|ResponseInterface|ScrapflyException>
     */
    private function batchResults(ResponseInterface $response, MultipartReader $reader, array $byCorrelation): \Generator
    {
        $stream = $response->getBody()->detach();
        if ($stream === null) {
            throw new ScrapflyException('The batch answer has no readable body');
        }
        // PHP's http wrapper reads a chunked body through a dechunk filter, and a blocking
        // read of a filtered stream waits for the whole length it asked for, so a part
        // would wait for the next one. A non-blocking read takes what has arrived; a
        // filtered stream cannot be selected on, hence the short pauses while it is empty.
        stream_set_blocking($stream, false);
        $inflate = self::inflater($response->getHeaderLine('content-encoding'));
        // A part names a correlation id the batch sent; an unknown one reads with the first config.
        $fallback = reset($byCorrelation);
        $lastRead = microtime(true);
        $pause = self::BATCH_POLL_MIN_MICROSECONDS;
        try {
            while (!$reader->done && !feof($stream)) {
                $bytes = fread($stream, 65536);
                if ($bytes === false) {
                    throw new TransportException('The batch connection failed while its parts were read');
                }
                if ($bytes === '') {
                    if (microtime(true) - $lastRead > self::READ_TIMEOUT) {
                        throw new TransportException(sprintf('The batch sent nothing for %d seconds', self::READ_TIMEOUT), true);
                    }
                    usleep($pause);
                    $pause = min($pause * 2, self::BATCH_POLL_MAX_MICROSECONDS);

                    continue;
                }
                $lastRead = microtime(true);
                $pause = self::BATCH_POLL_MIN_MICROSECONDS;
                if ($inflate !== null) {
                    $bytes = inflate_add($inflate, $bytes, ZLIB_SYNC_FLUSH);
                    if ($bytes === false) {
                        throw new ScrapflyException('The batch body is not valid ' . $response->getHeaderLine('content-encoding'));
                    }
                }
                foreach ($reader->feed($bytes) as [$headers, $content]) {
                    $correlationId = $headers['x-scrapfly-correlation-id'] ?? '';
                    yield $correlationId => $this->batchPart($headers, $content, $byCorrelation[$correlationId] ?? $fallback);
                }
            }
            $reader->close();
        } finally {
            fclose($stream);
        }
    }

    /** An incremental decoder of a gzip or deflate body, null for an identity one. */
    private static function inflater(string $contentEncoding): ?\InflateContext
    {
        $encoding = strtolower(trim($contentEncoding));
        if ($encoding !== 'gzip' && $encoding !== 'deflate') {
            return null;
        }
        $context = inflate_init($encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new ScrapflyException("Cannot decode a {$encoding} body");
        }

        return $context;
    }

    /**
     * @param array<string, string> $headers
     * @param bool $fetchLargeObjects false while the transfer that delivers the part runs: a fetch there would re-enter
     *     Guzzle's handler. The API inlines a large object up to 5 MiB; a part with a larger one says
     *     X-Scrapfly-Large-Object-Inlined: false.
     */
    private function batchPart(array $headers, string $content, ScrapeConfig $config, bool $fetchLargeObjects = true): ScrapeResponse|ResponseInterface|ScrapflyException
    {
        $status = (int) ($headers['x-scrapfly-scrape-status'] ?? 200);
        try {
            if (($headers['x-scrapfly-proxified'] ?? '') === 'true') {
                // A scrape Scrapfly failed itself is its JSON envelope, with none of the target's headers.
                $data = self::hasUpstreamHeaders($headers) ? null : json_decode($content, true);
                if (!is_array($data) || !is_array($data['result'] ?? null)) {
                    return self::proxifiedPart($headers, $content, $status);
                }
            } else {
                $data = self::decodeBody($headers['content-type'] ?? 'application/json', $content);
            }
            if (!is_array($data)) {
                throw new ScrapflyException('A batch part is not an object', 'ERR::API::INTERNAL_ERROR', 500);
            }
            // An error the API answered itself, a gateway timeout for one, carries no scrape envelope.
            if ($status >= 400 && !isset($data['result']) && !isset($data['config'])) {
                return $this->apiException($data, $status, $status >= 500 ? ApiHttpServerException::class : ApiHttpClientException::class, $headers['retry-after'] ?? '');
            }
            // A config with a webhook is answered by its enqueue ack, the scrape's answer under `result`.
            if (($data['enqueued'] ?? null) === true && is_array($data['result'] ?? null)) {
                $data = $data['result'];
            }
            if (!is_array($data['result'] ?? null)) {
                throw new ScrapflyException('A batch part carries no result', 'ERR::API::INTERNAL_ERROR', 500);
            }

            return $this->scrapeEnvelope($config, $data, array_map(static fn (string $value): array => [$value], $headers), $status, $headers['retry-after'] ?? '', false, $fetchLargeObjects);
        } catch (ScrapflyException $exception) {
            return $exception;
        }
    }

    /** @param array<string, string> $headers */
    private static function hasUpstreamHeaders(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (str_starts_with($name, 'x-scrapfly-upstream-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The target's response a proxified part carries: its status, its Content-Type,
     * its headers under X-Scrapfly-Upstream-*, and the scrape's X-Scrapfly-* ones,
     * which win over a target header of the same name.
     *
     * @param array<string, string> $headers
     */
    private static function proxifiedPart(array $headers, string $content, int $status): ResponseInterface|ScrapflyException
    {
        $target = [];
        $scrape = [];
        foreach ($headers as $name => $value) {
            if ($name === 'content-type') {
                $scrape['content-type'] = $value;
            } elseif (str_starts_with($name, 'x-scrapfly-upstream-')) {
                $target[substr($name, strlen('x-scrapfly-upstream-'))] = $value;
            } elseif (str_starts_with($name, 'x-scrapfly-')) {
                $scrape[$name] = $value;
            }
        }
        if (!isset($scrape['x-scrapfly-log']) && isset($scrape['x-scrapfly-log-uuid'])) {
            $scrape['x-scrapfly-log'] = $scrape['x-scrapfly-log-uuid'];
        }
        try {
            return new Response($status, $scrape + $target, $content);
        } catch (\InvalidArgumentException $exception) {
            // A status out of 100-599 (LinkedIn's 999), a header value with a control character.
            return new UpstreamHttpServerException('The target answered a response PSR-7 cannot hold: ' . $exception->getMessage(), self::BAD_UPSTREAM_RESPONSE, 502);
        }
    }

    /** A JSON or msgpack body, by its Content-Type. */
    private static function decodeBody(string $contentType, string $body): mixed
    {
        if (str_contains($contentType, 'msgpack')) {
            return Msgpack::decode($body);
        }
        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['message' => substr($body, 0, 500)];
        }
    }

    /**
     * @return array<string, string> a period, or a start and an end, which win over it
     */
    private static function monitoringWindow(?string $period, ?\DateTimeInterface $start, ?\DateTimeInterface $end): array
    {
        if (($start === null) !== ($end === null)) {
            throw new ConfigException('Pass both start and end, or neither');
        }
        if ($start !== null && $end !== null) {
            $utc = new \DateTimeZone('UTC');

            return [
                'start' => \DateTimeImmutable::createFromInterface($start)->setTimezone($utc)->format('Y-m-d H:i:s'),
                'end' => \DateTimeImmutable::createFromInterface($end)->setTimezone($utc)->format('Y-m-d H:i:s'),
            ];
        }

        return $period === null ? [] : ['period' => $period];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|object|null $body
     */
    private function scheduleJson(string $method, string $path, array $query = [], array|object|null $body = null, int $tries = self::CONNECT_TRIES): mixed
    {
        $response = $this->send($method, $path, ['key' => $this->key] + $query, $body === null ? [] : [RequestOptions::JSON => $body], $tries);
        if ($response->getStatusCode() >= 400) {
            throw $this->apiException($this->decodeJson($response), $response->getStatusCode(), ScheduleException::class);
        }
        if ($response->getStatusCode() === 204 || (string) $response->getBody() === '') {
            return null;
        }

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function browserJson(string $method, string $path, #[\SensitiveParameter] array $options = [], array $query = [], int $tries = self::CONNECT_TRIES): array
    {
        $response = $this->send($method, $path, ['key' => $this->key] + $query, $options, $tries, $this->cloudBrowserApiHost);
        if ($response->getStatusCode() >= 300) {
            throw $this->browserException($response);
        }

        return $this->decodeJson($response);
    }

    private function browserException(ResponseInterface $response): ScrapflyException
    {
        return $this->apiException($this->decodeJson($response), $response->getStatusCode(), CloudBrowserException::class);
    }

    /** @return array<string, mixed> */
    private static function vaultKeyHeader(#[\SensitiveParameter] string $vaultKey): array
    {
        return [RequestOptions::HEADERS => ['x-vault-key' => $vaultKey]];
    }

    /**
     * token_item_id names the item the server sealed the token in: it is the server's to set.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withoutTokenItem(array $data): array
    {
        unset($data['token_item_id']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function crawlerJson(ResponseInterface $response): array
    {
        $data = $this->decodeJson($response);
        if ($response->getStatusCode() >= 300 || isset($data['error_id'])) {
            throw $this->crawlerException($response, $data);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function crawlerException(ResponseInterface $response, array $data): ScrapflyException
    {
        return $this->apiException($data, $response->getStatusCode(), ApiHttpClientException::class);
    }

    /**
     * An API error envelope `{error_id, http_code, code, message}`, or the engine's
     * own `{error, code}`, as the exception of its code.
     *
     * @param array<string, mixed> $data
     * @param class-string<HttpException> $fallback
     * @param string $retryAfterHeader the answer's Retry-After, in seconds
     */
    private function apiException(array $data, int $status, string $fallback, string $retryAfterHeader = ''): ScrapflyException
    {
        $code = isset($data['code']) ? (string) $data['code'] : null;
        $error = $data['error'] ?? null;
        if ($code === null && is_string($error) && str_starts_with($error, 'ERR::')) {
            $code = $error;
        }
        $message = $data['message'] ?? (is_string($error) && !str_starts_with($error, 'ERR::') ? $error : null) ?? $code ?? "The API answered {$status}";
        $status = (int) ($data['http_code'] ?? $status);
        $documentation = is_array($data['links'] ?? null) ? (string) (array_values($data['links'])[0] ?? '') : null;

        $retryAfter = ctype_digit(trim($retryAfterHeader)) ? (int) $retryAfterHeader : null;

        return $this->exceptionFor($code ?? '', (string) $message, $status, ($data['retryable'] ?? false) === true, $retryAfter, $documentation ?: null, $data, $fallback);
    }

    /**
     * @param array<string, mixed>|null $data
     * @param class-string<HttpException> $fallback
     */
    private function exceptionFor(
        string $code,
        string $message,
        int $status,
        bool $retryable,
        ?int $retryAfter,
        ?string $documentation,
        ?array $data,
        string $fallback = HttpException::class,
        bool $targetStatus = false,
    ): ScrapflyException {
        $code = $code === '' ? null : $code;
        $class = match (true) {
            $code === self::BAD_UPSTREAM_RESPONSE => $status >= 500 ? UpstreamHttpServerException::class : UpstreamHttpClientException::class,
            !$targetStatus && $status === 401 => BadApiKeyException::class,
            !$targetStatus && $status === 429 && !str_contains((string) $code, '::THROTTLE::') => TooManyRequestsException::class,
            default => self::RESOURCE_EXCEPTIONS[explode('::', (string) $code)[1] ?? ''] ?? ($status >= 500 ? ApiHttpServerException::class : $fallback),
        };

        return new $class($message, $code, $status, $retryable, $retryAfter, $documentation, $data);
    }
}
