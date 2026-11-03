<?php

// php examples/scrape.php, with SCRAPFLY_KEY set.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Scrapfly\Format;
use Scrapfly\ProxyPool;
use Scrapfly\ScrapeConfig;
use Scrapfly\ScrapflyClient;

$client = new ScrapflyClient(key: (string) getenv('SCRAPFLY_KEY'));

$response = $client->scrape(new ScrapeConfig(
    url: 'https://web-scraping.dev/products',
    proxyPool: ProxyPool::Basic,
    format: Format::Markdown,
));

printf("%d, %d credits\n\n%s\n", $response->statusCode(), $response->cost(), $response->content());
