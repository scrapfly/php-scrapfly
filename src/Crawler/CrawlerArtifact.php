<?php

declare(strict_types=1);

namespace Scrapfly\Crawler;

use Scrapfly\Exception\ScrapflyException;

/** A crawl's WARC or HAR archive. pages() reads the HTTP responses of a WARC. */
final class CrawlerArtifact
{
    private const INFLATE_CHUNK = 65536;

    public function __construct(
        public readonly string $type,
        public readonly string $data,
    ) {
    }

    public function save(string $path): void
    {
        if (file_put_contents($path, $this->data) === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    /**
     * The HTTP responses the WARC holds, in archive order, header names in lower case,
     * the values of a repeated header joined by `, `.
     *
     * @return list<array{url: string, status_code: int, headers: array<string, string>, content: string}>
     */
    public function pages(): array
    {
        if ($this->type !== 'warc') {
            throw new \LogicException("pages() reads a WARC archive, not a {$this->type} one");
        }
        $warc = self::inflate($this->data);
        $length = strlen($warc);
        $pages = [];
        $offset = 0;
        while ($offset < $length) {
            while (substr_compare($warc, "\r\n", $offset, 2) === 0) {
                $offset += 2;
            }
            $headerEnd = strpos($warc, "\r\n\r\n", $offset);
            if ($headerEnd === false || substr_compare($warc, 'WARC/', $offset, 5) !== 0) {
                break;
            }
            $headers = self::headers(substr($warc, $offset, $headerEnd - $offset));
            $size = $headers['content-length'] ?? '';
            if (!ctype_digit($size)) {
                throw new ScrapflyException("A WARC record at byte {$offset} has no valid Content-Length");
            }
            $size = (int) $size;
            $block = substr($warc, $headerEnd + 4, $size);
            $offset = $headerEnd + 4 + $size;
            if (($headers['warc-type'] ?? '') !== 'response' || !str_starts_with($block, 'HTTP/')) {
                continue;
            }
            $split = strpos($block, "\r\n\r\n");
            $head = $split === false ? $block : substr($block, 0, $split);
            $statusLine = strtok($head, "\r\n");
            $pages[] = [
                'url' => $headers['warc-target-uri'] ?? '',
                'status_code' => (int) (explode(' ', (string) $statusLine, 3)[1] ?? 0),
                'headers' => self::headers(substr($head, strlen((string) $statusLine))),
                'content' => $split === false ? '' : substr($block, $split + 4),
            ];
        }

        return $pages;
    }

    /**
     * A WARC writer compresses each record as a gzip member of its own; inflate
     * stops at the end of a member, so each one is inflated in turn.
     */
    private static function inflate(string $data): string
    {
        if (!str_starts_with($data, "\x1f\x8b")) {
            return $data;
        }
        $length = strlen($data);
        $out = '';
        $offset = 0;
        while ($offset < $length && substr_compare($data, "\x1f\x8b", $offset, 2) === 0) {
            $context = inflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                throw new ScrapflyException('Cannot inflate the WARC archive');
            }
            $start = $offset;
            do {
                $chunk = substr($data, $offset, self::INFLATE_CHUNK);
                $offset += strlen($chunk);
                $inflated = inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
                if ($inflated === false) {
                    throw new ScrapflyException('The WARC archive is not valid gzip');
                }
                $out .= $inflated;
            } while (inflate_get_status($context) !== ZLIB_STREAM_END && $offset < $length);
            // The member may end inside the last chunk: the next one starts right after it.
            $offset = $start + inflate_get_read_len($context);
        }
        if ($offset < $length) {
            throw new ScrapflyException("The WARC archive has bytes that are not gzip after byte {$offset}");
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function headers(string $block): array
    {
        $headers = [];
        foreach (explode("\r\n", $block) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));
                $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
            }
        }

        return $headers;
    }
}
