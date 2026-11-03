<?php

declare(strict_types=1);

namespace Scrapfly;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
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
use Scrapfly\Exception\CrawlerConfigException;
use Scrapfly\Exception\CrawlerException;
use Scrapfly\Exception\ExtractionApiException;
use Scrapfly\Exception\HttpException;
use Scrapfly\Exception\ProxyException;
use Scrapfly\Exception\ScheduleException;
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
    public const VERSION = '0.1.0';

    public const DEFAULT_HOST = 'https://api.scrapfly.io';

    /** Past the Scrape API's 155 s read timeout, so its own answer arrives first. */
    private const READ_TIMEOUT = 160.0;

    /** Prompt and extract: retrieval plus up to 150 s of generation. */
    private const GENERATION_TIMEOUT = 180.0;

    private const CONNECT_TIMEOUT = 30.0;

    /** Attempts of a request whose connection failed. A timeout is never retried. */
    private const CONNECT_TRIES = 3;

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
    ];

    private readonly string $host;

    private readonly ClientInterface $http;

    /**
     * @param ClientInterface|null $httpClient a Guzzle client of your own, for a proxy or middleware;
     *     the SDK sets its timeouts, headers and query on every request it sends
     * @param bool|null $verify TLS verification; null keeps the Guzzle client's own setting, on by default
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
        string $host = self::DEFAULT_HOST,
        ?ClientInterface $httpClient = null,
        private readonly ?bool $verify = null,
    ) {
        if ($key === '') {
            throw new BadApiKeyException('The API key must be a non-empty string');
        }
        $this->host = rtrim($host, '/');
        $this->http = $httpClient ?? new Client();
    }

    /** @return array<string, mixed> what var_dump() and print_r() show: the key masked */
    public function __debugInfo(): array
    {
        return ['key' => substr($this->key, 0, 9) . '...', 'host' => $this->host, 'verify' => $this->verify ?? 'client default'];
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
                ['name' => 'config', 'contents' => Util::json($body), 'filename' => 'config.json', 'headers' => ['Content-Type' => 'application/json']],
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
    ): ResponseInterface
    {
        $options = $this->requestOptions($query, $options);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->http->request($method, $this->host . $path, $options);
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
        $format = $data['result']['format'] ?? null;
        if ($format === 'clob' || $format === 'blob') {
            $data['result'] = $this->fetchLargeObject($data['result'], $format);
        } elseif ($format === 'binary' && ($data['result']['content'] ?? '') !== '') {
            // An inline binary document comes base64 encoded; a large one is fetched as its bytes.
            $bytes = base64_decode((string) $data['result']['content'], true);
            if ($bytes === false && ($data['result']['success'] ?? false) === true) {
                throw new ScrapflyException('The binary content is not valid base64');
            }
            $data['result']['content'] = $bytes === false ? $data['result']['content'] : $bytes;
        }
        $scrape = new ScrapeResponse($data, $response->getHeaders());
        $result = $data['result'];
        // QUEUED: a scrape with a webhook, delivered to it later.
        if (($result['status'] ?? null) === 'QUEUED' || (($result['status'] ?? null) === 'DONE' && ($result['success'] ?? false) === true)) {
            return $scrape;
        }

        // A rejected scrape (concurrency, quota) is `status: DONE` with a null
        // `success`: the code is in `error` whatever `success` says.
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        $code = (string) ($error['code'] ?? $result['status'] ?? '');
        $statusCode = (int) ($result['status_code'] ?? $error['http_code'] ?? $response->getStatusCode());
        $message = (string) ($error['message'] ?? $code);
        $retryable = ($error['retryable'] ?? false) === true;
        $retryAfter = $retryable ? (int) ($response->getHeaderLine('retry-after') ?: 5) : null;
        $links = is_array($error['links'] ?? null) ? $error['links'] : [];
        $documentation = $error['doc_url'] ?? $links['Related Error Doc'] ?? 'https://scrapfly.io/docs/scrape-api/errors';

        if ($code === self::BAD_UPSTREAM_RESPONSE && !$config->raiseOnUpstreamError) {
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
     */
    private function apiException(array $data, int $status, string $fallback): ScrapflyException
    {
        $code = isset($data['code']) ? (string) $data['code'] : null;
        $error = $data['error'] ?? null;
        if ($code === null && is_string($error) && str_starts_with($error, 'ERR::')) {
            $code = $error;
        }
        $message = $data['message'] ?? (is_string($error) && !str_starts_with($error, 'ERR::') ? $error : null) ?? $code ?? "The API answered {$status}";
        $status = (int) ($data['http_code'] ?? $status);
        $documentation = is_array($data['links'] ?? null) ? (string) (array_values($data['links'])[0] ?? '') : null;

        return $this->exceptionFor($code ?? '', (string) $message, $status, ($data['retryable'] ?? false) === true, null, $documentation ?: null, $data, $fallback);
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
