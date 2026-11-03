<?php

// php examples/crawl_extract.php, with SCRAPFLY_KEY set.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Scrapfly\Crawler\Crawl;
use Scrapfly\CrawlerConfig;
use Scrapfly\ScrapflyClient;

$client = new ScrapflyClient(key: (string) getenv('SCRAPFLY_KEY'));

$crawl = (new Crawl($client, new CrawlerConfig(url: 'https://web-scraping.dev/products', pageLimit: 10, search: true)))
    ->start()
    ->wait(searchable: true);

$result = $crawl->extract('Every product with its name and its price in USD', [
    'type' => 'object',
    'properties' => ['products' => ['type' => 'array', 'items' => [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number']],
        'required' => ['name', 'price'],
    ]]],
    'required' => ['products'],
]);

foreach ($result->data['products'] as $product) {
    printf("%-50s %8.2f\n", $product['name'], $product['price']);
}
