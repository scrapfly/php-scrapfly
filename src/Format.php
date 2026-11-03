<?php

declare(strict_types=1);

namespace Scrapfly;

/** Format of the scraped content, sent as `format`. */
enum Format: string
{
    case Json = 'json';
    case Text = 'text';
    case Markdown = 'markdown';
    case CleanHtml = 'clean_html';
    case Raw = 'raw';
}
