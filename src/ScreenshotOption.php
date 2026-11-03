<?php

declare(strict_types=1);

namespace Scrapfly;

/** Options of the Screenshot API, sent as `options`. */
enum ScreenshotOption: string
{
    case LoadImages = 'load_images';
    case DarkMode = 'dark_mode';
    case BlockBanners = 'block_banners';
    case PrintMediaFormat = 'print_media_format';
}
