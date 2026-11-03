<?php

declare(strict_types=1);

namespace Scrapfly;

/** The data the Extraction API read out of a document. */
final class ExtractionResponse
{
    public function __construct(
        public readonly mixed $data,
        public readonly ?string $contentType,
    ) {
    }
}
