<?php

declare(strict_types=1);

namespace Scrapfly\Exception;

/** The API key is missing, invalid or revoked. */
class BadApiKeyException extends ApiHttpClientException
{
}
