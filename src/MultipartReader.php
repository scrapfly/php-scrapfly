<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ScrapflyException;
use Scrapfly\Exception\TransportException;

/**
 * @internal A push reader of a multipart/mixed body: feed() takes the bytes as they
 * arrive and returns the parts they completed, each as [headers, body] with the
 * header names in lower case. A part is sized by its Content-Length when it has one,
 * by the next boundary otherwise.
 */
final class MultipartReader
{
    private const CRLF = "\r\n";

    public bool $done = false;

    private string $buffer = '';

    private string $state = 'preamble';

    /** @var array<string, string>|null */
    private ?array $headers = null;

    private int $length = 0;

    private readonly string $delimiter;

    public function __construct(string $contentType)
    {
        if (preg_match('#^\s*multipart/mixed\s*;(?:.*;)?\s*boundary="?([^";]+)"?#i', $contentType, $match) !== 1) {
            throw new ScrapflyException("A batch answers multipart/mixed with a boundary, not \"{$contentType}\"");
        }
        $this->delimiter = '--' . $match[1];
    }

    /** @return list<array{array<string, string>, string}> */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $parts = [];
        while (!$this->done && $this->step($parts)) {
        }

        return $parts;
    }

    /** The body ended: the API always closes it with the final delimiter, its absence is a cut. */
    public function close(): void
    {
        if (!$this->done) {
            throw new TransportException('The batch answer ended before its last part');
        }
    }

    /** @param list<array{array<string, string>, string}> $parts */
    private function step(array &$parts): bool
    {
        switch ($this->state) {
            case 'preamble':
                $at = strpos($this->buffer, $this->delimiter);
                if ($at === false) {
                    return false;
                }
                $this->take($at, strlen($this->delimiter));
                $this->state = 'boundary';

                return true;
            case 'boundary':
                if (strlen($this->buffer) < 2) {
                    return false;
                }
                // `--` after a delimiter closes the body.
                if ($this->take(2) !== self::CRLF) {
                    $this->done = true;

                    return false;
                }
                $this->state = 'headers';

                return true;
            case 'headers':
                $at = strpos($this->buffer, self::CRLF . self::CRLF);
                if ($at === false) {
                    return false;
                }
                $this->headers = [];
                foreach (explode(self::CRLF, $this->take($at, 4)) as $line) {
                    $colon = strpos($line, ':');
                    if ($colon !== false) {
                        $this->headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                    }
                }
                $length = $this->headers['content-length'] ?? '';
                if (ctype_digit($length)) {
                    $this->length = (int) $length;
                    $this->state = 'sized';
                } else {
                    $this->state = 'delimited';
                }

                return true;
            case 'sized':
                if (strlen($this->buffer) < $this->length) {
                    return false;
                }
                $parts[] = [(array) $this->headers, $this->take($this->length)];
                $this->headers = null;
                $this->state = 'next';

                return true;
            case 'next':
                $at = strpos($this->buffer, self::CRLF . $this->delimiter);
                if ($at === false) {
                    return false;
                }
                $this->take($at, 2 + strlen($this->delimiter));
                $this->state = 'boundary';

                return true;
            default:
                $at = strpos($this->buffer, self::CRLF . $this->delimiter);
                if ($at === false) {
                    return false;
                }
                $parts[] = [(array) $this->headers, $this->take($at, 2 + strlen($this->delimiter))];
                $this->headers = null;
                $this->state = 'boundary';

                return true;
        }
    }

    private function take(int $length, int $skip = 0): string
    {
        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length + $skip);

        return $out;
    }
}
