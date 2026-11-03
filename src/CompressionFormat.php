<?php

declare(strict_types=1);

namespace Scrapfly;

/** Compression of a document sent to the Extraction API. */
enum CompressionFormat: string
{
    case Gzip = 'gzip';
    case Zstd = 'zstd';
    case Deflate = 'deflate';
}
