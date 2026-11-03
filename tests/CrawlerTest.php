<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use GuzzleHttp\Psr7\Response;
use Scrapfly\Crawler\Crawl;
use Scrapfly\CrawlerConfig;
use Scrapfly\CrawlerContentFormat;
use Scrapfly\CrawlerSearchMode;
use Scrapfly\Exception\CrawlerConfigException;
use Scrapfly\Exception\CrawlerException;
use Scrapfly\ProxyPool;

final class CrawlerTest extends ClientTestCase
{
    private const SEARCH = ['completeness' => 'exact', 'crawls_requested' => 1, 'crawls_searched' => 1, 'crawls_pruned_exact' => 0, 'skipped' => []];

    private const SOURCE = ['id' => 1, 'crawler_uuid' => 'c1', 'url' => 'https://web-scraping.dev/product/1', 'title' => 'Box', 'score' => 0.9];

    public function testStartSendsTheConfigAsJson(): void
    {
        $client = $this->client([self::json(['crawler_uuid' => 'c1', 'status' => 'PENDING'])]);

        $start = $client->startCrawl(new CrawlerConfig(
            url: 'https://web-scraping.dev/products',
            pageLimit: 5,
            contentFormats: [CrawlerContentFormat::Markdown],
            search: true,
            proxyPool: ProxyPool::Unblocker,
            unblocker: true,
        ));

        self::assertSame(['crawler_uuid' => 'c1', 'status' => 'PENDING'], $start);
        self::assertSame('/crawl', $this->sent[0]['request']->getUri()->getPath());
        self::assertSame([
            'url' => 'https://web-scraping.dev/products',
            'page_limit' => 5,
            'content_formats' => ['markdown'],
            'search' => true,
            'proxy_pool' => 'unblocker',
            'asp' => true,
        ], $this->jsonBody());
    }

    public function testAUrlListIsUploadedAsAFilePart(): void
    {
        $client = $this->client([self::json(['crawler_uuid' => 'c1', 'status' => 'PENDING'])]);

        $client->startCrawl(new CrawlerConfig(urlList: ['https://x.io/1', 'https://x.io/2'], pageLimit: 2));

        $request = $this->sent[0]['request'];
        $body = (string) $request->getBody();
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('content-type'));
        self::assertStringContainsString("https://x.io/1\nhttps://x.io/2", $body);
        self::assertStringContainsString('{"page_limit":2}', $body);
    }

    public function testInvalidCrawlerConfigsAreRefused(): void
    {
        $cases = [
            fn () => new CrawlerConfig(),
            fn () => new CrawlerConfig(url: 'https://x.io', remoteUrlList: 'https://x.io/list.txt'),
            fn () => new CrawlerConfig(url: 'https://x.io', excludePaths: ['/a'], includeOnlyPaths: ['/b']),
            fn () => new CrawlerConfig(url: 'https://x.io', refreshInterval: 60),
            fn () => new CrawlerConfig(url: 'https://x.io', contentFormats: ['pdf']),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                self::fail("case {$index} was accepted");
            } catch (CrawlerConfigException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStatusUrlsContentsAndArtifact(): void
    {
        $client = $this->client([
            self::json(['crawler_uuid' => 'c1', 'status' => 'DONE', 'is_success' => true, 'is_finished' => true, 'state' => ['urls_visited' => 5], 'search' => ['status' => 'READY']]),
            new Response(200, ['content-type' => 'text/plain'], "https://x.io/a,timeout\nhttps://x.io/b\n"),
            self::json(['contents' => ['https://x.io/a' => ['markdown' => '# A']], 'links' => ['next' => null]]),
            new Response(200, ['content-type' => 'application/gzip'], 'WARC'),
        ]);

        $status = $client->crawlStatus('c1');
        self::assertTrue($status->isComplete());
        self::assertTrue($status->isSearchable());
        self::assertSame(5, $status->state()['urls_visited']);

        $urls = $client->crawlUrls('c1', 'failed');
        self::assertSame('timeout', $urls->urls[0]->reason);
        self::assertNull($urls->urls[1]->reason);
        self::assertSame('failed', $this->query(1)['status']);

        self::assertSame('# A', $client->crawlContents('c1', CrawlerContentFormat::Markdown)->contents['https://x.io/a']['markdown']);
        self::assertSame('markdown', $this->query(2)['formats']);

        self::assertSame('WARC', $client->crawlArtifact('c1')->data);
    }

    public function testCrawlErrorsAreTyped(): void
    {
        $client = $this->client([self::json(['error_id' => 'e', 'http_code' => 404, 'code' => 'ERR::CRAWLER::NOT_FOUND', 'message' => 'no such crawl'], 404)]);

        try {
            $client->crawlStatus('nope');
            self::fail('no exception');
        } catch (CrawlerException $exception) {
            self::assertSame('ERR::CRAWLER::NOT_FOUND', $exception->getErrorCode());
            self::assertSame(404, $exception->getHttpStatusCode());
        }
    }

    public function testSearchSendsTheRetrievalOptions(): void
    {
        $client = $this->client([self::json(['results' => [['rank' => 1, 'url' => 'https://x.io/a']], 'completeness' => 'exact', 'skipped' => [], 'cursor' => 'n2'])]);

        $search = $client->crawlSearch(['c1', 'c2'], 'chocolate', limit: 5, mode: CrawlerSearchMode::Hybrid, filters: ['url_prefix' => 'https://x.io/'], cursor: 'n1');

        self::assertTrue($search->isExact());
        self::assertSame('n2', $search->cursor());
        self::assertSame(['query' => 'chocolate', 'crawl_ids' => ['c1', 'c2'], 'limit' => 5, 'mode' => 'hybrid', 'filters' => ['url_prefix' => 'https://x.io/'], 'cursor' => 'n1'], $this->jsonBody());
    }

    public function testPromptStreamsItsEvents(): void
    {
        $stream = "event: source\ndata: " . json_encode(self::SOURCE) . "\n\n"
            . ":keepalive\n\n"
            . "event: token\ndata: \"Box of \"\n\n"
            . "event: token\ndata: \"Chocolate\"\n\n"
            . "event: done\ndata: {\"sources_used\":[1],\"sources_dropped\":0,\"truncated\":false,\"api_credit\":3}\n\n";
        $client = $this->client([new Response(200, ['content-type' => 'text/event-stream'], $stream)]);

        $events = iterator_to_array($client->crawlPrompt(['c1'], 'Which product is cheapest?', limit: 5), false);

        self::assertSame(['source', 'token', 'token', 'done'], array_map(static fn ($event) => $event->event, $events));
        self::assertSame('Box of Chocolate', $events[1]->data . $events[2]->data);
        self::assertSame(3, $events[3]->data['api_credit']);
        self::assertSame(['stream' => true], $this->jsonBody()['generation']);
        self::assertSame(['limit' => 5], $this->jsonBody()['search']);
        self::assertSame('text/event-stream', $this->sent[0]['request']->getHeaderLine('accept'));
        self::assertSame(180.0, $this->sent[0]['options']['timeout']);
    }

    public function testAnErrorFrameThrowsAfterTheEventsBeforeIt(): void
    {
        $stream = "event: token\ndata: \"partial\"\n\nevent: error\ndata: {\"code\":\"ERR::CRAWLER::PROMPT_GENERATION_FAILED\",\"message\":\"model failed\"}\n\n";
        $events = [];

        try {
            foreach ($this->client([new Response(200, [], $stream)])->crawlPrompt(['c1'], 'q') as $event) {
                $events[] = $event;
            }
            self::fail('no exception');
        } catch (CrawlerException $exception) {
            self::assertSame('model failed', $exception->getMessage());
            self::assertSame('ERR::CRAWLER::PROMPT_GENERATION_FAILED', $exception->getErrorCode());
        }
        self::assertCount(1, $events);

        $this->expectException(CrawlerException::class);
        iterator_to_array($this->client([new Response(200, [], "event: token\ndata: \"cut\"\n\n")])->crawlPrompt(['c1'], 'q'));
    }

    public function testPromptAnswerAsksForOneObjectOnce(): void
    {
        $client = $this->client([self::json([
            'prompt' => 'q', 'answer' => 'Box of Chocolate Candy [1].', 'sources' => [self::SOURCE], 'sources_used' => [1],
            'sources_dropped' => 0, 'truncated' => false, 'search' => self::SEARCH, 'api_credit' => 3,
        ])]);

        $answer = $client->crawlPromptAnswer(['c1'], 'Which product is cheapest?', model: 'gemini-flash');

        self::assertSame('Box of Chocolate Candy [1].', $answer->answer);
        self::assertSame([1], $answer->sourcesUsed);
        self::assertSame(3, $answer->apiCredit);
        self::assertSame(['stream' => false, 'model' => 'gemini-flash'], $this->jsonBody()['generation']);
        self::assertArrayNotHasKey('search', $this->jsonBody());
        self::assertSame(180.0, $this->sent[0]['options']['timeout']);
    }

    public function testExtractSendsTheSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['products' => ['type' => 'array']], 'required' => ['products']];
        $client = $this->client([self::json([
            'prompt' => 'Every product', 'data' => ['products' => [['name' => 'Box', 'price' => 24.99]]], 'sources' => [self::SOURCE],
            'sources_used' => [], 'sources_dropped' => 0, 'truncated' => false, 'search' => self::SEARCH,
        ])]);

        $result = $client->crawlExtract(['c1'], 'Every product', $schema, filters: ['url_prefix' => 'https://web-scraping.dev/product/']);

        self::assertSame(24.99, $result->data['products'][0]['price']);
        self::assertTrue($result->isExact());
        self::assertSame('/crawl/extract', $this->sent[0]['request']->getUri()->getPath());
        self::assertSame(['prompt' => 'Every product', 'crawl_ids' => ['c1'], 'schema' => $schema, 'search' => ['filters' => ['url_prefix' => 'https://web-scraping.dev/product/']]], $this->jsonBody());
    }

    public function testAnEngineFailureKeepsItsMessageAndIsSentOnce(): void
    {
        $client = $this->client([
            self::json(['error' => 'the model returned no text', 'code' => 'ERR::CRAWLER::PROMPT_GENERATION_FAILED'], 500),
            self::json(['data' => []]),
        ]);

        try {
            $client->crawlExtract(['c1'], 'p', ['type' => 'object']);
            self::fail('no exception');
        } catch (CrawlerException $exception) {
            self::assertSame('the model returned no text', $exception->getMessage());
            self::assertSame(500, $exception->getHttpStatusCode());
        }
        self::assertCount(1, $this->sent);
    }

    public function testBadInputIsRefusedBeforeAnyRequest(): void
    {
        $client = $this->client([]);
        $cases = [
            fn () => $client->crawlSearch([], 'q'),
            fn () => $client->crawlSearch(['c1', 'c1'], 'q'),
            fn () => $client->crawlSearch(['c1'], ''),
            fn () => $client->crawlSearch(['c1'], 'q', mode: 'semantic'),
            fn () => $client->crawlSearch(['c1'], 'q', filters: ['url_prefix']),
            fn () => $client->crawlPromptAnswer(['c1'], ''),
            fn () => $client->crawlExtract(['c1'], 'p', []),
            fn () => $client->crawlExtract(['c1'], 'p', ['type']),
            fn () => iterator_to_array($client->crawlPrompt([''], 'q')),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                self::fail("case {$index} was accepted");
            } catch (CrawlerConfigException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $this->sent);
    }

    public function testCrawlDrivesOneCrawl(): void
    {
        $client = $this->client([
            self::json(['crawler_uuid' => 'c1', 'status' => 'PENDING']),
            self::json(['crawler_uuid' => 'c1', 'status' => 'RUNNING', 'is_finished' => false]),
            self::json(['crawler_uuid' => 'c1', 'status' => 'DONE', 'is_success' => true, 'is_finished' => true, 'search' => ['status' => 'BUILDING']]),
            self::json(['crawler_uuid' => 'c1', 'status' => 'DONE', 'is_success' => true, 'is_finished' => true, 'search' => ['status' => 'READY']]),
            self::json(['prompt' => 'p', 'data' => ['a' => 1], 'sources' => [], 'sources_dropped' => 0, 'truncated' => false, 'search' => self::SEARCH]),
        ]);

        $crawl = (new Crawl($client, new CrawlerConfig(url: 'https://x.io', search: true)))->start()->wait(pollInterval: 0.0, searchable: true);

        self::assertSame('c1', $crawl->uuid());
        self::assertSame(['a' => 1], $crawl->extract('p', ['type' => 'object'])->data);
        self::assertSame(['c1'], $this->jsonBody(4)['crawl_ids']);

        $this->expectException(CrawlerException::class);
        (new Crawl($client, new CrawlerConfig(url: 'https://x.io')))->status();
    }

    public function testWaitThrowsOnAFailedCrawl(): void
    {
        $client = $this->client([self::json(['crawler_uuid' => 'c1', 'status' => 'DONE', 'is_success' => false, 'is_finished' => true, 'state' => ['stop_reason' => 'seed_url_failed']])]);

        $this->expectExceptionMessage('seed_url_failed');
        Crawl::fromUuid($client, 'c1')->wait(pollInterval: 0.0);
    }
}
