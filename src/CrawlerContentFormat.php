<?php

declare(strict_types=1);

namespace Scrapfly;

/** Content format a crawl stores for each page. */
enum CrawlerContentFormat: string
{
    case Html = 'html';
    case CleanHtml = 'clean_html';
    case Markdown = 'markdown';
    case Text = 'text';
    case Json = 'json';
    case ExtractedData = 'extracted_data';
    case PageMetadata = 'page_metadata';
    case PageScreenshot = 'page_screenshot';
}
