<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Scrapfly\Exception\ApiHttpClientException;
use Scrapfly\Exception\BadApiKeyException;
use Scrapfly\Exception\ExtractionApiException;
use Scrapfly\Exception\ScrapeException;
use Scrapfly\Exception\ScreenshotApiException;
use Scrapfly\Exception\TooManyRequestsException;
use Scrapfly\Exception\TransportException;
use Scrapfly\Exception\UnblockerException;
use Scrapfly\Exception\UpstreamHttpClientException;
use Scrapfly\Exception\UpstreamHttpServerException;
use Scrapfly\ExtractionConfig;
use Scrapfly\ProxyPool;
use Scrapfly\ScrapeConfig;
use Scrapfly\ScrapeResponse;
use Scrapfly\ScrapflyClient;
use Scrapfly\ScreenshotConfig;
use Scrapfly\ScreenshotFormat;

final class ScrapflyClientTest extends ClientTestCase
{
    public function testScrapeSendsTheConfigAndReadsTheResult(): void
    {
        $client = $this->client([self::json(self::scrapeEnvelope('<h1>Box</h1>'), headers: ['x-scrapfly-api-cost' => '6'])]);

        $response = $client->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/products', proxyPool: ProxyPool::Unblocker, renderJs: true));

        $request = $this->sent[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/scrape', $request->getUri()->getPath());
        self::assertSame(['key' => self::KEY, 'url' => 'https://web-scraping.dev/products', 'render_js' => 'true', 'proxy_pool' => 'unblocker'], $this->query());
        self::assertStringStartsWith('PHP Scrapfly SDK/', $request->getHeaderLine('user-agent'));
        self::assertSame('<h1>Box</h1>', $response->content());
        self::assertSame(200, $response->statusCode());
        self::assertSame(6, $response->cost());
        self::assertSame(160.0, $this->sent[0]['options']['timeout']);
    }

    public function testPostSendsTheEncodedBody(): void
    {
        $client = $this->client([self::json(self::scrapeEnvelope())]);

        $client->scrape(new ScrapeConfig(url: 'https://httpbin.dev/post', method: 'POST', data: ['q' => 'chocolate']));

        $request = $this->sent[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('q=chocolate', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('content-type'));
        self::assertSame('application/x-www-form-urlencoded', $this->query()['headers']['content-type']);
    }

    public function testAnUpstreamErrorStatusThrowsUnlessAsked(): void
    {
        $failed = self::scrapeEnvelope('not found', [
            'success' => false,
            'status_code' => 404,
            'error' => ['code' => 'ERR::SCRAPE::BAD_UPSTREAM_RESPONSE', 'message' => 'The target answered 404', 'retryable' => false],
        ]);

        try {
            $this->client([self::json($failed, 422)])->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/404'));
            self::fail('no exception');
        } catch (UpstreamHttpClientException $exception) {
            self::assertSame('ERR::SCRAPE::BAD_UPSTREAM_RESPONSE', $exception->getErrorCode());
            self::assertSame(404, $exception->getHttpStatusCode());
            self::assertSame('SCRAPE', $exception->getResource());
        }

        $response = $this->client([self::json($failed, 422)])->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/404', raiseOnUpstreamError: false));
        self::assertSame(404, $response->statusCode());
        self::assertSame('not found', $response->content());

        $failed['result']['status_code'] = 503;
        $this->expectException(UpstreamHttpServerException::class);
        $this->client([self::json($failed, 422)])->scrape(new ScrapeConfig(url: 'https://web-scraping.dev/503'));
    }

    public function testAScrapeErrorIsTheExceptionOfItsResource(): void
    {
        $blocked = self::scrapeEnvelope('', [
            'success' => false,
            'status_code' => 403,
            'error' => ['code' => 'ERR::ASP::SHIELD_PROTECTION_FAILED', 'message' => 'blocked', 'retryable' => true, 'doc_url' => 'https://scrapfly.io/docs/scrape-api/error/ERR::ASP::SHIELD_PROTECTION_FAILED'],
        ]);

        try {
            $this->client([self::json($blocked, 422, ['x-retry' => '30'])])->scrape(new ScrapeConfig(url: 'https://x.io'));
            self::fail('no exception');
        } catch (UnblockerException $exception) {
            self::assertTrue($exception->isRetryable());
            self::assertSame(30, $exception->getRetryAfter());
            self::assertStringContainsString('SHIELD_PROTECTION_FAILED', (string) $exception->getDocumentationUrl());
        }

        $this->expectException(ScrapeException::class);
        $blocked['result']['error']['code'] = 'ERR::SCRAPE::DNS_NAME_NOT_RESOLVED';
        $this->client([self::json($blocked, 422)])->scrape(new ScrapeConfig(url: 'https://x.io'));
    }

    public function testAnApiErrorEnvelopeIsTyped(): void
    {
        try {
            $this->client([self::json(['error_id' => 'e1', 'http_code' => 401, 'code' => 'ERR::AUTH::INVALID_KEY', 'message' => 'Invalid API key'], 401)])
                ->scrape(new ScrapeConfig(url: 'https://x.io'));
            self::fail('no exception');
        } catch (BadApiKeyException $exception) {
            self::assertSame('Invalid API key', $exception->getMessage());
        }

        $this->expectException(TooManyRequestsException::class);
        $this->client([self::json(['error_id' => 'e2', 'http_code' => 429, 'code' => 'ERR::SCRAPE::QUOTA_LIMIT_REACHED', 'message' => 'quota'], 429)])
            ->scrape(new ScrapeConfig(url: 'https://x.io'));
    }

    public function testAConcurrencyRejectionCarriesItsCode(): void
    {
        // As the API answers it: `status: DONE`, a null `success`, the code in `error`.
        $rejected = [
            'config' => [], 'context' => [],
            'result' => ['status' => 'DONE', 'success' => null, 'status_code' => null, 'error' => [
                'code' => 'ERR::SCRAPE::TOO_MANY_CONCURRENT_REQUEST', 'http_code' => 429, 'retryable' => true,
                'message' => 'Account concurrency limit reached',
                'links' => ['Related Error Doc' => 'https://scrapfly.io/docs/scrape-api/error/ERR::SCRAPE::TOO_MANY_CONCURRENT_REQUEST'],
            ]],
        ];

        try {
            $this->client([self::json($rejected, 429)])->scrape(new ScrapeConfig(url: 'https://x.io'));
            self::fail('no exception');
        } catch (TooManyRequestsException $exception) {
            self::assertSame('ERR::SCRAPE::TOO_MANY_CONCURRENT_REQUEST', $exception->getErrorCode());
            self::assertSame(429, $exception->getHttpStatusCode());
            self::assertTrue($exception->isRetryable());
            self::assertStringEndsWith('TOO_MANY_CONCURRENT_REQUEST', (string) $exception->getDocumentationUrl());
        }
    }

    public function testABinaryDocumentIsDecoded(): void
    {
        $bytes = "RIFF\x00\x01\xffWEBP";
        $response = $this->client([self::json(self::scrapeEnvelope(base64_encode($bytes), ['format' => 'binary']))])
            ->scrape(new ScrapeConfig(url: 'https://x.io/image.webp'));

        self::assertSame($bytes, $response->content());
        self::assertSame('binary', $response->format());
    }

    public function testALargeObjectIsFetchedInPlace(): void
    {
        $envelope = self::scrapeEnvelope('https://api.scrapfly.io/scrape/large-object/01J0?format=clob', ['format' => 'clob']);
        $client = $this->client([self::json($envelope), new Response(200, ['content-type' => 'text/plain'], str_repeat('a', 10))]);

        $response = $client->scrape(new ScrapeConfig(url: 'https://x.io'));

        self::assertSame('aaaaaaaaaa', $response->content());
        self::assertSame('text', $response->format());
        self::assertSame('/scrape/large-object/01J0', $this->sent[1]['request']->getUri()->getPath());
        self::assertSame(['key' => self::KEY, 'format' => 'clob'], $this->query(1));
    }

    public function testALargeObjectOffTheApiHostIsRefused(): void
    {
        $envelope = self::scrapeEnvelope('https://elsewhere.example/blob/01J0', ['format' => 'blob']);

        try {
            $this->client([self::json($envelope)])->scrape(new ScrapeConfig(url: 'https://x.io'));
            self::fail('no exception');
        } catch (\Scrapfly\Exception\ScrapflyException $exception) {
            self::assertStringContainsString('not on the API host', $exception->getMessage());
        }
        self::assertCount(1, $this->sent, 'the key went to another host');
    }

    public function testHeadIsBuiltFromTheHeaders(): void
    {
        $response = $this->client([new Response(200, ['content-type' => 'text/html'])])
            ->scrape(new ScrapeConfig(url: 'https://x.io', method: 'HEAD'));

        self::assertSame('HEAD', $this->sent[0]['request']->getMethod());
        self::assertTrue($response->isSuccess());
        self::assertSame('text/html', $response->responseHeaders()['content-type']);
        self::assertStringNotContainsString(self::KEY, json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    public function testProxifiedReturnsTheTargetResponseAndThrowsARejection(): void
    {
        $response = $this->client([new Response(503, ['content-type' => 'text/html'], 'down')])
            ->scrapeProxified(new ScrapeConfig(url: 'https://x.io'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('true', $this->query()['proxified_response']);

        $this->expectException(UnblockerException::class);
        $this->client([new Response(422, ['x-scrapfly-reject-code' => 'ERR::ASP::SHIELD_PROTECTION_FAILED', 'x-scrapfly-reject-description' => 'blocked'])])
            ->scrapeProxified(new ScrapeConfig(url: 'https://x.io'));
    }

    public function testAConnectionFailureIsRetriedAndNeverPrintsTheKey(): void
    {
        $request = new Request('GET', 'https://api.scrapfly.io/account?key=' . self::KEY);
        $refused = new ConnectException('cURL error 7: Failed to connect for https://api.scrapfly.io/account?key=' . self::KEY, $request, null, ['errno' => 7]);
        $client = $this->client([$refused, $refused, $refused]);

        try {
            $client->account();
            self::fail('no exception');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            self::assertStringContainsString('key=REDACTED', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertFalse($exception->isTimeout());
        }
        self::assertCount(3, $this->sent);
    }

    public function testATimeoutIsNotRetried(): void
    {
        $request = new Request('GET', 'https://api.scrapfly.io/scrape');
        $timeout = new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]);
        $client = $this->client([$timeout, self::json(self::scrapeEnvelope())]);

        try {
            $client->scrape(new ScrapeConfig(url: 'https://x.io'));
            self::fail('no exception');
        } catch (TransportException $exception) {
            self::assertTrue($exception->isTimeout());
        }
        self::assertCount(1, $this->sent);
    }

    public function testARealConnectionErrorDoesNotPrintTheKey(): void
    {
        $client = new ScrapflyClient('scp-live-0123456789abcdef', 'http://127.0.0.1:1');

        try {
            $client->account();
            self::fail('no exception');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('0123456789abcdef', $exception->getMessage() . $exception->getTraceAsString());
        }
    }

    public function testConcurrentScrapeKeepsTheOrderAndReturnsFailures(): void
    {
        $blocked = self::scrapeEnvelope('', ['success' => false, 'error' => ['code' => 'ERR::ASP::SHIELD_PROTECTION_FAILED', 'message' => 'blocked']]);
        $client = $this->client([self::json(self::scrapeEnvelope('one')), self::json($blocked, 422), self::json(self::scrapeEnvelope('three'))]);

        $results = $client->concurrentScrape([
            new ScrapeConfig(url: 'https://x.io/1'),
            new ScrapeConfig(url: 'https://x.io/2'),
            new ScrapeConfig(url: 'https://x.io/3'),
        ], concurrency: 1);

        self::assertInstanceOf(ScrapeResponse::class, $results[0]);
        self::assertSame('one', $results[0]->content());
        self::assertInstanceOf(UnblockerException::class, $results[1]);
        self::assertInstanceOf(ScrapeResponse::class, $results[2]);
        self::assertSame('three', $results[2]->content());
    }

    public function testScreenshotReturnsTheImage(): void
    {
        $client = $this->client([new Response(200, ['content-type' => 'image/png', 'x-scrapfly-upstream-http-code' => '200', 'x-scrapfly-upstream-url' => 'https://x.io/'], "\x89PNG")]);

        $screenshot = $client->screenshot(new ScreenshotConfig(url: 'https://x.io', format: ScreenshotFormat::Png, capture: 'fullpage', proxyPool: ProxyPool::Basic));

        self::assertSame("\x89PNG", $screenshot->image);
        self::assertSame('png', $screenshot->extension());
        self::assertSame(200, $screenshot->upstreamStatusCode);
        self::assertSame(['key' => self::KEY, 'url' => 'https://x.io', 'format' => 'png', 'capture' => 'fullpage', 'proxy_pool' => 'basic'], $this->query());

        $this->expectException(ScreenshotApiException::class);
        $this->client([self::json(['error_id' => 'e', 'http_code' => 422, 'code' => 'ERR::SCREENSHOT::INVALID', 'message' => 'bad'], 422)])
            ->screenshot(new ScreenshotConfig(url: 'https://x.io'));
    }

    public function testExtractPostsTheDocument(): void
    {
        $client = $this->client([self::json(['data' => ['name' => 'Box'], 'content_type' => 'application/json'])]);

        $result = $client->extract(new ExtractionConfig(body: '<h1>Box</h1>', contentType: 'text/html', url: 'https://x.io/p/1', extractionPrompt: 'product name'));

        $request = $this->sent[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('<h1>Box</h1>', (string) $request->getBody());
        self::assertSame('text/html', $request->getHeaderLine('content-type'));
        self::assertFalse($request->hasHeader('content-encoding'));
        self::assertSame(['name' => 'Box'], $result->data);

        $this->expectException(ExtractionApiException::class);
        $this->client([self::json(['error_id' => 'e', 'http_code' => 422, 'code' => 'ERR::EXTRACTION::INVALID', 'message' => 'bad'], 422)])
            ->extract(new ExtractionConfig(body: 'x', contentType: 'text/html'));
    }

    public function testVerifyApiKey(): void
    {
        self::assertTrue($this->client([self::json(['account' => []])])->verifyApiKey());
        self::assertFalse($this->client([self::json(['error_id' => 'e', 'http_code' => 401], 401)])->verifyApiKey());

        $this->expectException(ApiHttpClientException::class);
        $this->client([self::json(['error_id' => 'e', 'http_code' => 403, 'message' => 'suspended'], 403)])->account();
    }
}
