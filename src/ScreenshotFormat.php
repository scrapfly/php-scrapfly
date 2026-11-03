<?php

declare(strict_types=1);

namespace Scrapfly;

/** Image format of the Screenshot API. */
enum ScreenshotFormat: string
{
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';
}
