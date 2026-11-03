<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Scrapfly\Exception\ScheduleException;
use Scrapfly\Exception\ScrapeConfigException;
use Scrapfly\Exception\ScrapeException;
use Scrapfly\Exception\ScrapflyException;
use Scrapfly\Exception\TransportException;
use Scrapfly\Exception\UpstreamHttpServerException;
use Scrapfly\MonitoringProduct;
use Scrapfly\Msgpack;
use Scrapfly\MultipartReader;
use Scrapfly\ScheduleRecurrence;
use Scrapfly\ScheduleRequest;
use Scrapfly\ScheduleUpdate;
use Scrapfly\ScrapeConfig;
use Scrapfly\ScrapeResponse;
use Scrapfly\ScrapflyClient;

final class BatchTest extends ClientTestCase
{
    private const BOUNDARY = 'b0und4ry';

    public function testMsgpackDecodesEveryTypeTheApiSends(): void
    {
        $packed = "\x8a"
            . "\xa3int" . "\x2a"
            . "\xa3neg" . "\xd0\x80"
            . "\xa3big" . "\xcf\x80\x00\x00\x00\x00\x00\x00\x00"
            . "\xa5int64" . "\xd3\xff\xff\xff\xff\xff\xff\xff\xfe"
            . "\xa5float" . "\xcb\x40\x09\x21\xf9\xf0\x1b\x86\x6e"
            . "\xa3nil" . "\xc0"
            . "\xa4bool" . "\xc3"
            . "\xa3bin" . "\xc4\x03\x00\x01\x02"
            . "\xa4list" . "\x93\x01\xa1a\xc2"
            . "\xa3str" . "\xd9\x06h\xc3\xa9llo";

        self::assertSame([
            'int' => 42,
            'neg' => -128,
            'big' => 9223372036854775808.0,
            'int64' => -2,
            'float' => 3.14159,
            'nil' => null,
            'bool' => true,
            'bin' => "\x00\x01\x02",
            'list' => [1, 'a', false],
            'str' => 'héllo',
        ], Msgpack::decode($packed));
    }

    public function testMsgpackRefusesATruncatedValue(): void
    {
        $this->expectException(ScrapflyException::class);
        Msgpack::decode("\xa5abc");
    }

    public function testTheReaderReturnsEachPartAsItsBytesArriveWhateverTheChunks(): void
    {
        $body = self::multipart([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'a'], '{"n":1}'],
            [['Content-Type' => 'text/plain', 'X-Scrapfly-Correlation-Id' => 'b'], "two\r\nlines"],
        ], sized: false);

        foreach ([1, 7, strlen($body)] as $chunk) {
            $reader = new MultipartReader('multipart/mixed; boundary="' . self::BOUNDARY . '"');
            $parts = [];
            foreach (str_split($body, $chunk) as $bytes) {
                array_push($parts, ...$reader->feed($bytes));
            }
            self::assertTrue($reader->done);
            self::assertSame([[['content-type' => 'application/json', 'x-scrapfly-correlation-id' => 'a'], '{"n":1}'], [['content-type' => 'text/plain', 'x-scrapfly-correlation-id' => 'b'], "two\r\nlines"]], $parts, "chunks of {$chunk}");
        }
    }

    public function testABatchYieldsEachPartByItsCorrelationId(): void
    {
        $client = $this->client([self::batchResponse([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'ok', 'X-Scrapfly-Scrape-Status' => '200'], json_encode(self::scrapeEnvelope('<p>ok</p>'))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'blocked', 'X-Scrapfly-Scrape-Status' => '403'], json_encode(self::scrapeEnvelope('', [
                'success' => false,
                'status_code' => 403,
                'status' => 'DONE',
                'error' => ['code' => 'ERR::SCRAPE::BAD_UPSTREAM_RESPONSE', 'message' => 'The target answered 403'],
            ]))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'timeout', 'X-Scrapfly-Scrape-Status' => '504'], '{"code":"ERR::SCRAPE::OPERATION_TIMEOUT","message":"too slow","http_code":504,"retryable":true}'],
            [['Content-Type' => 'text/html', 'X-Scrapfly-Correlation-Id' => 'raw', 'X-Scrapfly-Proxified' => 'true', 'X-Scrapfly-Scrape-Status' => '200', 'X-Scrapfly-Upstream-Set-Cookie' => 'a=1', 'X-Scrapfly-Log-Uuid' => '01J'], '<!doctype html>'],
        ])]);

        $results = iterator_to_array($client->scrapeBatch([
            new ScrapeConfig(url: 'https://web-scraping.dev/a', correlationId: 'ok'),
            new ScrapeConfig(url: 'https://web-scraping.dev/b', correlationId: 'blocked'),
            new ScrapeConfig(url: 'https://web-scraping.dev/c', correlationId: 'timeout'),
            new ScrapeConfig(url: 'https://web-scraping.dev/d', correlationId: 'raw', proxifiedResponse: true),
        ], format: 'json'));

        self::assertInstanceOf(ScrapeResponse::class, $results['ok']);
        self::assertSame('<p>ok</p>', $results['ok']->content());
        // A per-part failure is yielded, never thrown, whatever raiseOnUpstreamError says.
        self::assertInstanceOf(ScrapeResponse::class, $results['blocked']);
        self::assertSame(403, $results['blocked']->statusCode());
        // Typed by the resource of its code, as a single scrape's error is.
        self::assertInstanceOf(ScrapeException::class, $results['timeout']);
        self::assertSame('ERR::SCRAPE::OPERATION_TIMEOUT', $results['timeout']->getErrorCode());
        self::assertSame(504, $results['timeout']->getHttpStatusCode());
        self::assertTrue($results['timeout']->isRetryable());
        self::assertInstanceOf(ResponseInterface::class, $results['raw']);
        self::assertSame('a=1', $results['raw']->getHeaderLine('set-cookie'));
        self::assertSame('01J', $results['raw']->getHeaderLine('x-scrapfly-log'));
        self::assertSame('<!doctype html>', (string) $results['raw']->getBody());

        $sent = $this->jsonBody();
        self::assertCount(4, $sent['configs']);
        self::assertArrayNotHasKey('key', $sent['configs'][0]);
        self::assertSame('raw', $sent['configs'][3]['correlation_id']);
        self::assertSame('true', $sent['configs'][3]['proxified_response']);
        self::assertSame('application/json', $this->sent[0]['request']->getHeaderLine('accept'));
    }

    public function testAGzipBatchIsDecodedPartByPart(): void
    {
        $body = self::multipart([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'a'], json_encode(self::scrapeEnvelope(str_repeat('a', 20000)))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'b'], json_encode(self::scrapeEnvelope('b'))],
        ]);
        $client = $this->client([new Response(200, ['content-type' => 'multipart/mixed; boundary=' . self::BOUNDARY, 'content-encoding' => 'gzip'], gzencode($body))]);

        $results = iterator_to_array($client->scrapeBatch([new ScrapeConfig(url: 'https://x.io/a', correlationId: 'a'), new ScrapeConfig(url: 'https://x.io/b', correlationId: 'b')]));

        self::assertSame(20000, strlen($results['a']->content()));
        self::assertSame('b', $results['b']->content());
        // Decoded by the SDK as it reads: Guzzle's zlib filter would hold a part back until 8 KB more arrived.
        self::assertFalse($this->sent[0]['options']['decode_content']);
    }

    public function testAGzipRefusalIsDecodedToo(): void
    {
        $client = $this->client([new Response(422, ['content-type' => 'application/json', 'content-encoding' => 'gzip'], gzencode('{"code":"ERR::SCRAPE::BATCH_CONFIG","message":"not on this plan","http_code":422}'))]);

        $this->expectExceptionMessage('not on this plan');
        $client->scrapeBatch([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')]);
    }

    public function testMsgpackPartsDecode(): void
    {
        $envelope = "\x83\xa6config\x80\xa7context\x80\xa6result\x85\xa6status\xa4DONE\xa7success\xc3\xabstatus_code\xcc\xc8\xa7content\xa2ok\xa6format\xa4text";
        $client = $this->client([self::batchResponse([[['Content-Type' => 'application/msgpack', 'X-Scrapfly-Correlation-Id' => 'mp'], $envelope]])]);

        $results = iterator_to_array($client->scrapeBatch([new ScrapeConfig(url: 'https://web-scraping.dev/a', correlationId: 'mp')], format: 'msgpack'));

        self::assertSame(200, $results['mp']->statusCode());
        self::assertSame('ok', $results['mp']->content());
        self::assertSame('application/msgpack', $this->sent[0]['request']->getHeaderLine('accept'));
    }

    public function testEveryConfigNeedsItsOwnCorrelationIdBeforeAnythingIsSent(): void
    {
        $client = $this->client([]);
        foreach ([
            [new ScrapeConfig(url: 'https://x.io', correlationId: 'a'), new ScrapeConfig(url: 'https://x.io')],
            [new ScrapeConfig(url: 'https://x.io', correlationId: 'a'), new ScrapeConfig(url: 'https://x.io', correlationId: 'a')],
            [],
        ] as $configs) {
            try {
                $client->scrapeBatch($configs);
                self::fail('the batch was accepted');
            } catch (ScrapeConfigException $e) {
                self::assertSame('ERR::SCRAPE::BATCH_CONFIG', $e->getErrorCode());
            }
        }
        self::assertSame([], $this->sent);
    }

    public function testABatchRefusedAsAWholeThrows(): void
    {
        $client = $this->client([self::json(['code' => 'ERR::SCRAPE::CONFIG_ERROR', 'message' => 'not on this plan', 'http_code' => 400], 400)]);

        $this->expectExceptionMessage('not on this plan');
        $client->scrapeBatch([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')]);
    }

    public function testAnAsyncBatchHandsEachPartOverBeforeTheTransferEnds(): void
    {
        $body = self::multipart([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'first'], json_encode(self::scrapeEnvelope('<p>1</p>'))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'second'], json_encode(self::scrapeEnvelope('<p>2</p>'))],
        ]);
        $events = [];
        // A transfer as curl runs one: headers, then the body in chunks written to the sink.
        $handler = static function (RequestInterface $request, array $options) use (&$events, $body): PromiseInterface {
            $response = new Response(200, ['content-type' => 'multipart/mixed; boundary=' . self::BOUNDARY]);
            $options['on_headers']($response);
            foreach (str_split($body, 64) as $chunk) {
                $events[] = 'chunk';
                $options['sink']->write($chunk);
            }
            $events[] = 'end';

            return Create::promiseFor($response->withBody($options['sink']));
        };
        $client = new ScrapflyClient(self::KEY, 'https://api.scrapfly.io', new Client(['handler' => HandlerStack::create($handler)]));

        $promise = $client->scrapeBatchAsync(
            [new ScrapeConfig(url: 'https://x.io/1', correlationId: 'first'), new ScrapeConfig(url: 'https://x.io/2', correlationId: 'second')],
            static function (string $correlationId, ScrapeResponse|ResponseInterface|ScrapflyException $result) use (&$events): void {
                $events[] = $correlationId . '=' . ($result instanceof ScrapeResponse ? $result->content() : '?');
            },
        );
        $promise->wait();

        self::assertContains('first=<p>1</p>', $events);
        self::assertContains('second=<p>2</p>', $events);
        self::assertLessThan(array_search('end', $events, true), array_search('first=<p>1</p>', $events, true), 'the first part waited for the end of the transfer');
        self::assertSame('chunk', $events[array_search('first=<p>1</p>', $events, true) + 1], 'chunks kept coming after the first part');
    }

    public function testAnAsyncBatchRunsBesideOtherPromises(): void
    {
        $client = $this->client([
            self::batchResponse([[['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'a'], json_encode(self::scrapeEnvelope('ok'))]]),
            self::json(['account' => ['account_id' => 'x']]),
        ]);
        $results = [];

        $batch = $client->scrapeBatchAsync([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')], static function (string $correlationId, $result) use (&$results): void {
            $results[$correlationId] = $result;
        }, format: 'json');
        $other = (new Client(['handler' => HandlerStack::create(new MockHandler([new Response(204)]))]))->requestAsync('GET', 'https://x.io');
        PromiseUtils::all([$batch, $other])->wait();

        self::assertSame('ok', $results['a']->content());
        self::assertSame('POST', $this->sent[0]['request']->getMethod());
        self::assertSame('configs', array_key_first($this->jsonBody()));
    }

    public function testAnAsyncBatchRefusedAsAWholeRejects(): void
    {
        $client = $this->client([self::json(['code' => 'ERR::SCRAPE::BATCH_CONFIG', 'message' => 'not on this plan', 'http_code' => 422], 422)]);
        $promise = $client->scrapeBatchAsync([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')], static function (): void {
            self::fail('a refused batch delivered a part');
        });

        try {
            $promise->wait();
            self::fail('the refused batch was fulfilled');
        } catch (ScrapflyException $e) {
            self::assertSame('ERR::SCRAPE::BATCH_CONFIG', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatusCode());
        }
    }

    public function testWhatTheCallbackThrowsRejectsTheBatch(): void
    {
        $client = $this->client([self::batchResponse([[['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'a'], json_encode(self::scrapeEnvelope('ok'))]])]);
        $promise = $client->scrapeBatchAsync([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')], static function (): void {
            throw new \DomainException('stop');
        });

        $this->expectException(\DomainException::class);
        $promise->wait();
    }

    public function testAnAsyncBatchRefusesBadConfigsAtTheCall(): void
    {
        $client = $this->client([]);

        $this->expectException(ScrapeConfigException::class);
        $client->scrapeBatchAsync([new ScrapeConfig(url: 'https://x.io')], static function (): void {
        });
    }

    public function testAProxifiedConfigIsRefusedByScrape(): void
    {
        $this->expectExceptionMessage('scrapeProxified()');
        $this->client([])->scrape(new ScrapeConfig(url: 'https://x.io', proxifiedResponse: true));
    }

    public function testClassifySendsTheResponseItIsGiven(): void
    {
        $client = $this->client([self::json(['blocked' => true, 'antibot' => 'cloudflare', 'cost' => 1])]);

        self::assertSame(['blocked' => true, 'antibot' => 'cloudflare', 'cost' => 1], $client->classify('https://x.io', 403, ['cf-ray' => '1'], '<html>'));
        self::assertSame(['url' => 'https://x.io', 'status_code' => 403, 'method' => 'GET', 'headers' => ['cf-ray' => '1'], 'body' => '<html>'], $this->jsonBody());
        $client = $this->client([self::json(['blocked' => false, 'cost' => 1])]);
        $client->classify('https://x.io', 200, ['set-cookie' => ['a=1', 'b=2']], "caf\xe9");
        self::assertSame(['set-cookie' => 'a=1, b=2'], $this->jsonBody()['headers'], "PSR-7's getHeaders() as is");
        self::assertSame("caf\u{FFFD}", $this->jsonBody()['body'], 'a body that is not UTF-8 is still sent');
    }

    public function testMonitoringCallsNameTheProductAndTheWindow(): void
    {
        $client = $this->client([self::json(['account_metrics' => []]), self::json(['metrics' => []]), self::json(['account_metrics' => []])]);

        $client->monitoringMetrics(MonitoringProduct::Crawler, period: 'subscription', aggregation: ['account', 'target']);
        self::assertStringEndsWith('/crawl/monitoring/metrics', $this->sent[0]['request']->getUri()->getPath());
        self::assertSame(['key' => self::KEY, 'format' => 'structured', 'period' => 'subscription', 'aggregation' => 'account,target'], $this->query(0));

        $client->monitoringTargetMetrics('web-scraping.dev', MonitoringProduct::Screenshot, start: new \DateTimeImmutable('2026-11-01 02:00:00+02:00'), end: new \DateTimeImmutable('2026-11-02 00:00:00Z'));
        self::assertSame('2026-11-01 00:00:00', $this->query(1)['start']);
        self::assertArrayNotHasKey('period', $this->query(1), 'start and end win over the period');

        $client->browserMonitoringMetrics('last7d', proxyPool: 'residential');
        self::assertStringEndsWith('/browser/monitoring/metrics', $this->sent[2]['request']->getUri()->getPath());
        self::assertSame('residential', $this->query(2)['proxy_pool']);
    }

    public function testSchedulesSendTheConfigBesideTheRequest(): void
    {
        $client = $this->client([
            self::json(['id' => 's1', 'kind' => 'api.scrape', 'status' => 'ACTIVE']),
            self::json(['id' => 's1', 'max_retries' => 3]),
            new Response(204),
            self::json(['code' => 'ERR::SCHEDULER::CONFIG_ERROR', 'message' => 'webhook_name is required', 'http_code' => 422], 422),
        ]);

        self::assertSame('s1', $client->createScrapeSchedule(['url' => 'https://x.io', 'render_js' => true], new ScheduleRequest('hook', new ScheduleRecurrence(cron: '0 */6 * * *'), maxRetries: 2))['id']);
        self::assertSame([
            'scrape_config' => ['url' => 'https://x.io', 'render_js' => true],
            'webhook_name' => 'hook',
            'allow_concurrency' => false,
            'retry_on_failure' => false,
            'recurrence' => ['cron' => '0 */6 * * *'],
            'max_retries' => 2,
        ], $this->jsonBody(0));

        $client->updateSchedule('s1', new ScheduleUpdate(maxRetries: 3, notes: 'n'));
        self::assertSame('PATCH', $this->sent[1]['request']->getMethod());
        self::assertSame(['max_retries' => 3, 'notes' => 'n'], $this->jsonBody(1));

        $client->cancelSchedule('s1');
        self::assertSame('DELETE', $this->sent[2]['request']->getMethod());

        try {
            $client->createCrawlerSchedule(['url' => 'https://x.io'], new ScheduleRequest(''));
            self::fail('a schedule without a webhook was accepted');
        } catch (ScheduleException $e) {
            self::assertSame('ERR::SCHEDULER::CONFIG_ERROR', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatusCode());
        }
    }

    public function testABatchSendsEachConfigsMethodAndBody(): void
    {
        $client = $this->client([self::batchResponse([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'post'], json_encode(self::scrapeEnvelope('ok'))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'get'], json_encode(self::scrapeEnvelope('ok'))],
        ])]);

        iterator_to_array($client->scrapeBatch([
            new ScrapeConfig(url: 'https://x.io/api', method: 'POST', body: '{"q":1}', correlationId: 'post'),
            new ScrapeConfig(url: 'https://x.io/page', correlationId: 'get'),
        ]));

        $configs = $this->jsonBody()['configs'];
        self::assertSame(['POST', '{"q":1}'], [$configs[0]['method'], $configs[0]['body']]);
        self::assertSame('GET', $configs[1]['method']);
        self::assertArrayNotHasKey('body', $configs[1]);
        // On the stream handler, the read timeout that also bounds the wait for the first part.
        self::assertSame(160.0, $this->sent[0]['options']['timeout']);
        self::assertArrayNotHasKey('curl', $this->sent[0]['options'], 'the stream handler takes no curl option');
    }

    public function testABatchCutBeforeItsLastPartThrows(): void
    {
        $body = self::multipart([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'a'], json_encode(self::scrapeEnvelope('<p>a</p>'))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'b'], json_encode(self::scrapeEnvelope('<p>b</p>'))],
        ]);
        $client = $this->client([new Response(200, ['content-type' => 'multipart/mixed; boundary=' . self::BOUNDARY], substr($body, 0, -60))]);

        $seen = [];
        try {
            foreach ($client->scrapeBatch([new ScrapeConfig(url: 'https://x.io/a', correlationId: 'a'), new ScrapeConfig(url: 'https://x.io/b', correlationId: 'b')]) as $correlationId => $result) {
                $seen[] = $correlationId;
            }
            self::fail('a cut batch ended as if it were complete');
        } catch (TransportException $e) {
            self::assertStringContainsString('ended before its last part', $e->getMessage());
        }
        self::assertSame(['a'], $seen);
    }

    public function testAProxifiedPartIsTheTargetsResponseOrTheScrapesFailure(): void
    {
        $client = $this->client([self::batchResponse([
            [['Content-Type' => 'text/html', 'X-Scrapfly-Correlation-Id' => 'page', 'X-Scrapfly-Proxified' => 'true', 'X-Scrapfly-Scrape-Status' => '200', 'X-Scrapfly-Log-Uuid' => '01J', 'X-Scrapfly-Upstream-X-Scrapfly-Log' => 'forged', 'X-Scrapfly-Upstream-Server' => 'nginx'], '<p>'],
            [['Content-Type' => 'text/html', 'X-Scrapfly-Correlation-Id' => 'odd', 'X-Scrapfly-Proxified' => 'true', 'X-Scrapfly-Scrape-Status' => '999', 'X-Scrapfly-Upstream-Server' => 'li'], ''],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'failed', 'X-Scrapfly-Proxified' => 'true', 'X-Scrapfly-Scrape-Status' => '422', 'X-Scrapfly-Log-Uuid' => '01K'], json_encode(self::scrapeEnvelope('', [
                'success' => false,
                'status_code' => 422,
                'error' => ['code' => 'ERR::ASP::SHIELD_PROTECTION_FAILED', 'message' => 'blocked', 'http_code' => 422],
            ]))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'api', 'X-Scrapfly-Proxified' => 'true', 'X-Scrapfly-Scrape-Status' => '200', 'X-Scrapfly-Upstream-Server' => 'api'], json_encode(self::scrapeEnvelope('a target that answers JSON'))],
        ])]);

        $results = iterator_to_array($client->scrapeBatch(array_map(
            static fn (string $id): ScrapeConfig => new ScrapeConfig(url: "https://x.io/{$id}", correlationId: $id, proxifiedResponse: true),
            ['page', 'odd', 'failed', 'api'],
        )));

        self::assertInstanceOf(ResponseInterface::class, $results['page']);
        self::assertSame('01J', $results['page']->getHeaderLine('x-scrapfly-log'), "the target's header of the same name lost");
        self::assertSame('nginx', $results['page']->getHeaderLine('server'));
        // PSR-7 holds 100 to 599 only: the part is an error, the rest of the batch goes on.
        self::assertInstanceOf(UpstreamHttpServerException::class, $results['odd']);
        self::assertInstanceOf(ScrapflyException::class, $results['failed']);
        self::assertSame('ERR::ASP::SHIELD_PROTECTION_FAILED', $results['failed']->getErrorCode());
        // A target's own JSON, which has the target's headers, stays the target's response.
        self::assertInstanceOf(ResponseInterface::class, $results['api']);
    }

    public function testAWebhookConfigIsAnsweredByItsQueuedScrape(): void
    {
        $client = $this->client([self::batchResponse([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'hook', 'X-Scrapfly-Scrape-Status' => '202'], json_encode([
                'correlation_id' => 'hook',
                'webhook_name' => 'my-hook',
                'job_uuid' => '01J',
                'enqueued' => true,
                'result' => self::scrapeEnvelope('', ['status' => 'QUEUED', 'success' => null]),
            ])],
        ])]);

        $results = iterator_to_array($client->scrapeBatch([new ScrapeConfig(url: 'https://x.io', correlationId: 'hook', webhook: 'my-hook')]));

        self::assertInstanceOf(ScrapeResponse::class, $results['hook']);
    }

    public function testARefusalCarriesItsRetryAfter(): void
    {
        $client = $this->client([self::json(['code' => 'ERR::SCRAPE::TOO_MANY_CONCURRENT_REQUEST', 'message' => 'busy', 'retryable' => true], 429, ['retry-after' => '5'])]);

        try {
            $client->scrapeBatch([new ScrapeConfig(url: 'https://x.io', correlationId: 'a')]);
            self::fail('a refused batch was accepted');
        } catch (ScrapflyException $e) {
            self::assertSame(5, $e->getRetryAfter());
        }
    }

    public function testAnAsyncPartWithALargeObjectComesOnceTheTransferIsOver(): void
    {
        $body = self::multipart([
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'big', 'X-Scrapfly-Large-Object-Inlined' => 'false'], json_encode(self::scrapeEnvelope('https://api.scrapfly.io/scrape/large-object/abc?format=clob', ['format' => 'clob']))],
            [['Content-Type' => 'application/json', 'X-Scrapfly-Correlation-Id' => 'small'], json_encode(self::scrapeEnvelope('<p>small</p>'))],
        ]);
        $events = [];
        $handler = static function (RequestInterface $request, array $options) use (&$events, $body): PromiseInterface {
            if (str_contains($request->getUri()->getPath(), 'large-object')) {
                $events[] = 'fetch';

                return Create::promiseFor(new Response(200, [], str_repeat('x', 6_000_000)));
            }
            $response = new Response(200, ['content-type' => 'multipart/mixed; boundary=' . self::BOUNDARY]);
            $options['on_headers']($response);
            foreach (str_split($body, 64) as $chunk) {
                $options['sink']->write($chunk);
            }
            $events[] = 'end';

            return Create::promiseFor($response->withBody($options['sink']));
        };
        $client = new ScrapflyClient(self::KEY, 'https://api.scrapfly.io', new Client(['handler' => HandlerStack::create($handler)]));

        $client->scrapeBatchAsync(
            [new ScrapeConfig(url: 'https://x.io/big', correlationId: 'big'), new ScrapeConfig(url: 'https://x.io/small', correlationId: 'small')],
            static function (string $correlationId, ScrapeResponse|ResponseInterface|ScrapflyException $result) use (&$events): void {
                $events[] = $correlationId . '=' . ($result instanceof ScrapeResponse ? strlen($result->content()) : $result::class);
            },
        )->wait();

        self::assertSame(['small=12', 'end', 'fetch', 'big=6000000'], $events);
    }

    public function testMsgpackRefusesContainersNestedPastItsLimit(): void
    {
        $this->expectExceptionMessage('nested deeper than 512');
        Msgpack::decode(str_repeat("\x91", 600) . "\xc0");
    }

    public function testAnEmptyRecurrenceIsAnObjectOnTheWire(): void
    {
        self::assertStringContainsString('"recurrence":{}', (string) json_encode((new ScheduleRequest('w', recurrence: new ScheduleRecurrence()))->toArray()));
    }

    /** @param list<array{array<string, string>, string}> $parts */
    private static function batchResponse(array $parts): Response
    {
        return new Response(200, ['content-type' => 'multipart/mixed; boundary=' . self::BOUNDARY], self::multipart($parts));
    }

    /** @param list<array{array<string, string>, string}> $parts */
    private static function multipart(array $parts, bool $sized = true): string
    {
        $body = "preamble\r\n";
        foreach ($parts as [$headers, $content]) {
            $body .= '--' . self::BOUNDARY . "\r\n";
            foreach ($headers + ($sized ? ['Content-Length' => (string) strlen($content)] : []) as $name => $value) {
                $body .= "{$name}: {$value}\r\n";
            }
            $body .= "\r\n{$content}\r\n";
        }

        return $body . '--' . self::BOUNDARY . "--\r\n";
    }
}
