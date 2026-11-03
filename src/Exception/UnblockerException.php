<?php

declare(strict_types=1);

namespace Scrapfly\Exception;

/** An `ERR::ASP::*` error: the Unblocker could not get past the target's protection. */
class UnblockerException extends HttpException
{
}
