<?php

declare(strict_types=1);

namespace Scrapfly;

/** The image the Screenshot API took, and where it took it. */
final class ScreenshotResponse
{
    public function __construct(
        public readonly string $image,
        public readonly string $contentType,
        public readonly ?int $upstreamStatusCode,
        public readonly ?string $upstreamUrl,
    ) {
    }

    /** `png`, `jpeg`, `webp` or `gif`, from the content type. */
    public function extension(): string
    {
        return explode(';', explode('/', $this->contentType)[1] ?? '')[0];
    }

    /** Write the image to `$path`, adding the extension when `$path` has none. */
    public function save(string $path): string
    {
        if (pathinfo($path, PATHINFO_EXTENSION) === '') {
            $path .= '.' . $this->extension();
        }
        file_put_contents($path, $this->image);

        return $path;
    }
}
