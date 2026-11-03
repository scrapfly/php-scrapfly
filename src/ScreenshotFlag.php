<?php

declare(strict_types=1);

namespace Scrapfly;

/** Flags of the screenshots taken during a scrape, sent as `screenshot_flags`. */
enum ScreenshotFlag: string
{
    case LoadImages = 'load_images';
    case DarkMode = 'dark_mode';
    case BlockBanners = 'block_banners';
    case PrintMediaFormat = 'print_media_format';
    case HighQuality = 'high_quality';
}
