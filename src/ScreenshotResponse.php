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
        /** The job a webhook delivers the screenshot to; the image is then empty. */
        public readonly ?string $jobUuid = null,
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
        if (file_put_contents($path, $this->image) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }

        return $path;
    }
}
