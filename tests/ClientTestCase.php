<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Scrapfly\ScrapflyClient;

abstract class ClientTestCase extends TestCase
{
    protected const KEY = 'scp-test-00000000000000000000000000000000';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    protected array $sent = [];

    /** @param list<mixed> $replies responses, or exceptions to throw */
    protected function client(array $replies, string $host = 'https://api.scrapfly.io'): ScrapflyClient
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($replies));
        $stack->push(Middleware::history($this->sent));

        return new ScrapflyClient(self::KEY, $host, new Client(['handler' => $stack]));
    }

    /** @param array<string, mixed> $body */
    protected static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    protected function query(int $index = 0): array
    {
        parse_str($this->sent[$index]['request']->getUri()->getQuery(), $query);

        return $query;
    }

    /** @return array<string, mixed> */
    protected function jsonBody(int $index = 0): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> a scrape the API answered DONE */
    protected static function scrapeEnvelope(string $content = '<html>ok</html>', array $result = []): array
    {
        return [
            'uuid' => '01J0000000000000000000000',
            'config' => ['url' => 'https://web-scraping.dev/products'],
            'context' => [],
            'result' => $result + [
                'status' => 'DONE',
                'success' => true,
                'status_code' => 200,
                'content' => $content,
                'format' => 'text',
                'url' => 'https://web-scraping.dev/products',
                'response_headers' => ['content-type' => 'text/html'],
                'request_headers' => [],
                'log_url' => 'https://scrapfly.io/dashboard/monitoring/log/01J0000000000000000000000',
            ],
        ];
    }
}
