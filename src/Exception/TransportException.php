<?php

declare(strict_types=1);

namespace Scrapfly\Exception;

/**
 * The request never got an answer: connection refused, TLS failure, timeout.
 *
 * The message names the request URL with `key=REDACTED` in place of the API key,
 * and the HTTP client's own exception is not chained, as its message prints the key.
 */
class TransportException extends ScrapflyException
{
    public function __construct(string $message, private readonly bool $timeout = false)
    {
        parent::__construct($message);
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
