<?php

declare(strict_types=1);

namespace Scrapfly;

/** Options of the markdown, text and clean_html formats, appended to `format`. */
enum FormatOption: string
{
    case NoLinks = 'no_links';
    case NoImages = 'no_images';
    case OnlyContent = 'only_content';
}
