# Scrapfly PHP SDK

PHP client for the [Scrapfly](https://scrapfly.io) web scraping, screenshot,
extraction and crawler APIs, on Guzzle. It follows the official Python,
TypeScript, Go and Rust SDKs.

Requires PHP 8.3 or newer.

## Install

```sh
composer require scrapfly/scrapfly-sdk
```

## Quick start

```php
use Scrapfly\ScrapflyClient;
use Scrapfly\ScrapeConfig;

$client = new ScrapflyClient(key: (string) getenv('SCRAPFLY_KEY'));

$response = $client->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/products'));

echo $response->statusCode(), "\n";
echo $response->content();
```

Every option is a named argument of its config: `renderJs`, `country`,
`format`, `cache`, `session`, `jsScenario`, `extractionModel` and the others
listed in the [Web Scraping API documentation](https://scrapfly.io/docs/scrape-api/getting-started),
in camel case.

```php
use Scrapfly\Format;
use Scrapfly\ProxyPool;

$response = $client->scrape(new ScrapeConfig(
    url: 'https://web-scraping.dev/products',
    proxyPool: ProxyPool::Unblocker,
    renderJs: true,
    waitForSelector: '.product',
    format: Format::Markdown,
    tags: ['products'],
));
```

## Proxy pools

`proxyPool` picks the proxy pool of the Web Scraping API, the Crawler and the
Screenshot API. Each pool sets both the proxy network and the anti-bot bypass
(the Unblocker):

| Case | Wire value | What it runs |
|---|---|---|
| `ProxyPool::Basic` | `basic` | datacenter proxies, Unblocker off |
| `ProxyPool::Unblocker` | `unblocker` | residential proxies, Unblocker on |
| `ProxyPool::UnblockerUltra` | `unblocker_ultra` | residential proxies, Unblocker on, the only pool accepted by targets and anti-bot protections that have a price of their own |
| `ProxyPool::Auto` | `auto` | the API picks the pool before the request, from the target's profile, priced as the pool it runs on |

A request on `basic` or `unblocker` against a target or anti-bot protection
that has a price of its own fails with `ERR::PROXY::UNBLOCKER_ULTRA_REQUIRED`
(HTTP 422, not billed); retry it with `ProxyPool::UnblockerUltra`. Without
`proxyPool` the SDK sends none. `ProxyPool::PublicTorPool` routes through the
Tor network, the only pool that reaches `.onion` sites. A pool of your own is
passed by name, as a string.

The Screenshot API takes the four pools of the table only.

### Deprecated: `unblocker` and `asp`

The `unblocker` argument of `ScrapeConfig` and `CrawlerConfig`, and its older
name `asp`, are deprecated: use `proxyPool` (`ProxyPool::UnblockerUltra` for the
Unblocker). The [migration guide](https://scrapfly.io/new-pricing#legacy-spellings)
covers the requests written before the proxy pools, the pool names
`ProxyPool::PublicDatacenterPool` and `ProxyPool::PublicResidentialPool`
included.

Both names still work. When both are given, `asp` wins, so an explicit
`asp: false` turns the feature off. `isUnblockerEnabled()` reads the result,
which the request carries as `asp`.

## Errors

Every exception extends `Scrapfly\Exception\ScrapflyException`, which carries
the API's error code (`getErrorCode()`, `ERR::ASP::SHIELD_PROTECTION_FAILED` for
instance), the HTTP status, whether a retry may succeed (`isRetryable()`,
`getRetryAfter()`) and the documentation of the error.

```php
use Scrapfly\Exception\UnblockerException;
use Scrapfly\Exception\UpstreamHttpClientException;

try {
    $response = $client->scrape($config);
} catch (UpstreamHttpClientException $exception) {
    // The target answered 4xx. Pass raiseOnUpstreamError: false to get the response instead.
} catch (UnblockerException $exception) {
    // The Unblocker could not get past the target's protection.
}
```

A request that never got an answer throws `TransportException`
(`isTimeout()`). Its message names the request URL with `key=REDACTED`, never
the API key, so it is safe to log.

## Screenshot API

```php
use Scrapfly\ScreenshotConfig;
use Scrapfly\ScreenshotFormat;
use Scrapfly\ScreenshotOption;

$screenshot = $client->screenshot(new ScreenshotConfig(
    url: 'https://web-scraping.dev/product/1',
    format: ScreenshotFormat::Png,
    capture: 'fullpage',
    options: [ScreenshotOption::BlockBanners],
));
$screenshot->save('product');
```

## Extraction API

```php
use Scrapfly\ExtractionConfig;

$result = $client->extract(new ExtractionConfig(
    body: $html,
    contentType: 'text/html',
    url: 'https://web-scraping.dev/product/1',
    extractionModel: 'product',
));
print_r($result->data);
```

## Crawler API

`Crawl` drives one crawl from its start to its content:

```php
use Scrapfly\Crawler\Crawl;
use Scrapfly\CrawlerConfig;
use Scrapfly\CrawlerContentFormat;

$crawl = (new Crawl($client, new CrawlerConfig(
    url: 'https://web-scraping.dev/products',
    pageLimit: 20,
    contentFormats: [CrawlerContentFormat::Markdown],
)))->start()->wait();

foreach ($crawl->contents(CrawlerContentFormat::Markdown)->contents as $url => $formats) {
    echo $url, ': ', strlen($formats['markdown']), " chars\n";
}
```

### Search, prompt and extract

A crawl started with `search: true` builds a search index of its pages. Wait
for it with `wait(searchable: true)`, then search it, ask it a question, or
extract an object shaped by a JSON Schema:

```php
$crawl = (new Crawl($client, new CrawlerConfig(url: 'https://web-scraping.dev/products', pageLimit: 20, search: true)))
    ->start()
    ->wait(searchable: true);

$hits = $crawl->search('chocolate', limit: 5);

foreach ($crawl->prompt('Which product is the cheapest?') as $event) {
    if ($event->isToken()) {
        echo $event->data;
    }
}

$answer = $crawl->promptAnswer('Which product is the cheapest?');
echo $answer->answer;

$products = $crawl->extract('Every product with its price in USD', [
    'type' => 'object',
    'properties' => ['products' => ['type' => 'array', 'items' => [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number']],
        'required' => ['name', 'price'],
    ]]],
    'required' => ['products'],
]);
```

`$client->crawlSearch()`, `crawlPrompt()`, `crawlPromptAnswer()` and
`crawlExtract()` take a list of crawl uuids, to answer from several crawls at
once. A prompt or an extraction is sent once and never retried. The schema
constrains the model as it generates and is not checked again afterwards:
validate anything you act on.

### Refresh

A crawl can re-scrape its own URLs on a period, in place: same uuid, same
artifacts, same search index.

```php
$client->crawlRefreshSettings($uuid, enabled: true, intervalSeconds: 86400);
$client->crawlRefreshNow($uuid);
$history = $client->crawlRefreshHistory($uuid);
```

### WARC

```php
foreach ($client->crawlArtifact($uuid, 'warc')->pages() as $page) {
    echo $page['status_code'], ' ', $page['url'], PHP_EOL;
}
```

## Concurrent scrapes

```php
$results = $client->concurrentScrape([
    new ScrapeConfig(url: 'https://web-scraping.dev/product/1'),
    new ScrapeConfig(url: 'https://web-scraping.dev/product/2'),
]);
```

The results come back in the order of the configs, a failed scrape as its
exception. The concurrency defaults to your account's limit.

## Batch

One request, up to 100 scrapes, each result streamed back as soon as its
scrape finishes. Every config needs a correlation id of its own, which keys
its result:

```php
foreach ($client->scrapeBatch([
    new ScrapeConfig(url: 'https://web-scraping.dev/product/1', correlationId: 'p1'),
    new ScrapeConfig(url: 'https://web-scraping.dev/product/2', correlationId: 'p2'),
]) as $correlationId => $result) {
    echo $correlationId, ': ', $result instanceof ScrapflyException ? $result->getMessage() : $result->statusCode(), PHP_EOL;
}
```

A failed scrape is yielded as its exception, never thrown. A config with
`proxifiedResponse: true` yields the target's own response, a PSR-7
`ResponseInterface` (`getStatusCode()`, `getBody()`), or its exception when
Scrapfly failed the scrape. Pass `format: 'msgpack'` for msgpack parts. Batch
is not on the FREE plan.

`scrapeBatchAsync()` runs the same batch on Guzzle's promises: each result goes
to a callback as soon as its part arrives, and the batch shares Guzzle's event
loop with your other async requests. A page too large for the API to inline in
its part (past 5 MiB) reaches the callback once the transfer is over, its
content fetched then.

```php
$promise = $client->scrapeBatchAsync($configs, function (string $correlationId, $result): void {
    // called as each scrape finishes
});
$promise->wait();
```

## Cloud Browser API

```php
use Scrapfly\BrowserConfig;
use Scrapfly\UnblockConfig;

// The CDP WebSocket URL, for any CDP client
$wsUrl = $client->cloudBrowserUrl(new BrowserConfig(proxyPool: 'residential', country: 'us'));

// Past the anti-bot first: the browser comes back with its cookies and clearance
$session = $client->cloudBrowserUnblock(new UnblockConfig(url: 'https://web-scraping.dev/products'));
// $session['ws_url'] is the browser's CDP URL: connect your CDP client to it
$client->cloudBrowserSessionStop($session['session_id']);
```

Both URLs carry your API key, and `cloudBrowserUrl()` also the
`BrowserConfig` secrets you set (`vaultKey`, VNC and WebRTC passwords, a BYOP
proxy's credentials): keep them out of logs.

The client also manages the account's extensions (`cloudBrowserExtension*`),
credential vaults and their items (`cloudBrowserVault*`), and reads the
recording of a `debug` session (`cloudBrowserPlayback()`,
`cloudBrowserWaitForPlayback()`, `cloudBrowserVideo()`). The vault methods
send the vault key as the `X-Vault-Key` header.

## Schedules

```php
use Scrapfly\ScheduleRecurrence;
use Scrapfly\ScheduleRequest;

$schedule = $client->createScrapeSchedule(
    ['url' => 'https://web-scraping.dev/products', 'render_js' => true],
    new ScheduleRequest(webhookName: 'my-webhook', recurrence: new ScheduleRecurrence(cron: '0 */6 * * *')),
);
$client->pauseSchedule($schedule['id']);
```

`createScreenshotSchedule()` and `createCrawlerSchedule()` work the same way.
A schedule delivers each run to its webhook.

## Monitoring and classify

`monitoringMetrics()`, `monitoringTargetMetrics()`, `browserMonitoringMetrics()`
and `browserMonitoringTimeseries()` read the Monitoring API (Enterprise plans).
`classify()` tells whether a response you fetched yourself is an anti-bot
block.

## Webhooks

```php
use Scrapfly\Webhook;

$body = file_get_contents('php://input');
if (!Webhook::verifySignature($body, $_SERVER['HTTP_X_SCRAPFLY_WEBHOOK_SIGNATURE'] ?? null, $secret)) {
    http_response_code(401);
    exit;
}
```

The signature covers the body before any `Content-Encoding`: decompress a
compressed delivery before you verify it.

## Your own Guzzle client

Pass a Guzzle client to route the requests through a proxy or a middleware of
your own. The SDK sets its timeouts, headers and query on every request.

```php
$client = new ScrapflyClient(key: $key, httpClient: new \GuzzleHttp\Client(['proxy' => 'http://127.0.0.1:8080']));
```

See <https://scrapfly.io/docs/sdk/php> and <https://scrapfly.io/docs> for the
full API reference.
