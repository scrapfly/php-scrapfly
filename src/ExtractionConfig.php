<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ExtractionConfigException;

/**
 * A request to the Extraction API, `POST /extraction`: structured data out of a
 * document you already have.
 *
 * ```php
 * $config = new ExtractionConfig(
 *     body: $html,
 *     contentType: 'text/html',
 *     url: 'https://web-scraping.dev/product/1',
 *     extractionModel: 'product',
 * );
 * ```
 */
final class ExtractionConfig
{
    public CompressionFormat|string|null $documentCompressionFormat;

    /**
     * @param string $body the document
     * @param string|null $url the URL the document came from, which resolves its relative links
     * @param array<string, mixed>|null $extractionEphemeralTemplate
     * @param CompressionFormat|string|null $documentCompressionFormat the compression `body` is already in
     * @param int|null $timeout milliseconds
     */
    public function __construct(
        public string $body,
        public string $contentType,
        public ?string $url = null,
        public ?string $charset = null,
        public ?string $extractionTemplate = null,
        public ?array $extractionEphemeralTemplate = null,
        public ?string $extractionPrompt = null,
        public ?string $extractionModel = null,
        CompressionFormat|string|null $documentCompressionFormat = null,
        public ?string $webhook = null,
        public ?int $timeout = null,
    ) {
        $this->documentCompressionFormat = is_string($documentCompressionFormat)
            ? (CompressionFormat::tryFrom($documentCompressionFormat) ?? throw new ExtractionConfigException("Invalid compression format: {$documentCompressionFormat}"))
            : $documentCompressionFormat;
    }

    /** @return array<string, string> */
    public function toApiParams(string $key): array
    {
        $params = ['key' => $key, 'content_type' => $this->contentType];
        if ($this->url !== null) {
            $params['url'] = $this->url;
        }
        if ($this->charset !== null) {
            $params['charset'] = $this->charset;
        }
        if ($this->extractionTemplate !== null && $this->extractionEphemeralTemplate !== null) {
            throw new ExtractionConfigException('Pass either extractionTemplate or extractionEphemeralTemplate, not both');
        }
        if ($this->extractionTemplate !== null) {
            $params['extraction_template'] = 'persistent:' . $this->extractionTemplate;
        }
        if ($this->extractionEphemeralTemplate !== null) {
            $params['extraction_template'] = 'ephemeral:' . Util::urlSafeBase64(Util::json($this->extractionEphemeralTemplate));
        }
        if ($this->extractionPrompt !== null) {
            $params['extraction_prompt'] = $this->extractionPrompt;
        }
        if ($this->extractionModel !== null) {
            $params['extraction_model'] = $this->extractionModel;
        }
        if ($this->webhook !== null) {
            $params['webhook_name'] = $this->webhook;
        }
        if ($this->timeout !== null) {
            $params['timeout'] = (string) $this->timeout;
        }

        return $params;
    }
}
