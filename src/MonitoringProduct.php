<?php

declare(strict_types=1);

namespace Scrapfly;

/** The products whose metrics the Monitoring API serves: the value is their path prefix. */
enum MonitoringProduct: string
{
    case WebScraping = 'scrape';
    case Screenshot = 'screenshot';
    case Extraction = 'extraction';
    case Crawler = 'crawl';
}
