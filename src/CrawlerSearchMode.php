<?php

declare(strict_types=1);

namespace Scrapfly;

/** Retrieval legs of a crawl search. */
enum CrawlerSearchMode: string
{
    case Vector = 'vector';
    case Fts = 'fts';
    case Hybrid = 'hybrid';
}
