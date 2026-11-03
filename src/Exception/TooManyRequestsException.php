<?php

declare(strict_types=1);

namespace Scrapfly\Exception;

/** Too many requests: back off, or lower the concurrency. */
class TooManyRequestsException extends HttpException
{
}
