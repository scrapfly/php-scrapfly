<?php

declare(strict_types=1);

namespace Scrapfly\Exception;

/**
 * Base class of every exception the SDK throws.
 *
 * `getErrorCode()` is the API's `ERR::RESOURCE::NAME` code, distinct from the
 * integer `getCode()` PHP reserves for itself.
 */
class ScrapflyException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $apiResponse the decoded body of the response that failed
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly ?int $httpStatusCode = null,
        private readonly bool $retryable = false,
        private readonly ?int $retryAfter = null,
        private readonly ?string $documentationUrl = null,
        private readonly ?array $apiResponse = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The API's `ERR::RESOURCE::NAME` code. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** The resource of the error code, `SCRAPE` for `ERR::SCRAPE::BAD_UPSTREAM_RESPONSE`. */
    public function getResource(): ?string
    {
        return $this->errorCode === null ? null : (explode('::', $this->errorCode)[1] ?? null);
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /** Whether the API says the same request may succeed later. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** Seconds to wait before a retry, when the API names a delay. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getDocumentationUrl(): ?string
    {
        return $this->documentationUrl;
    }

    /** @return array<string, mixed>|null */
    public function getApiResponse(): ?array
    {
        return $this->apiResponse;
    }
}
