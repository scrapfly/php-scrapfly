<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ScrapflyException;

/**
 * @internal A MessagePack decoder for the API's answers: a map decodes to an array,
 * str and bin both to a string, a uint64 past PHP_INT_MAX to a float. The API sends
 * no extension type, so one is an error.
 */
final class Msgpack
{
    /** Containers nested deeper end the decoding before PHP's stack does. */
    private const MAX_DEPTH = 512;

    private int $offset = 0;

    private int $depth = 0;

    private function __construct(private readonly string $data)
    {
    }

    public static function decode(string $data): mixed
    {
        $decoder = new self($data);
        $value = $decoder->value();
        if ($decoder->offset !== strlen($data)) {
            throw new ScrapflyException('msgpack: ' . (strlen($data) - $decoder->offset) . ' bytes after the value');
        }

        return $value;
    }

    private function value(): mixed
    {
        $byte = ord($this->take(1));

        return match (true) {
            $byte <= 0x7f => $byte,
            $byte >= 0xe0 => $byte - 0x100,
            ($byte & 0xf0) === 0x80 => $this->map($byte & 0x0f),
            ($byte & 0xf0) === 0x90 => $this->list($byte & 0x0f),
            ($byte & 0xe0) === 0xa0 => $this->take($byte & 0x1f),
            $byte === 0xc0 => null,
            $byte === 0xc2 => false,
            $byte === 0xc3 => true,
            $byte === 0xc4, $byte === 0xd9 => $this->take($this->uint(1)),
            $byte === 0xc5, $byte === 0xda => $this->take($this->uint(2)),
            $byte === 0xc6, $byte === 0xdb => $this->take($this->uint(4)),
            $byte === 0xca => $this->read('G', 4),
            $byte === 0xcb => $this->read('E', 8),
            $byte === 0xcc => $this->uint(1),
            $byte === 0xcd => $this->uint(2),
            $byte === 0xce => $this->uint(4),
            $byte === 0xcf => $this->uint64(),
            $byte === 0xd0 => (int) $this->read('c', 1),
            $byte === 0xd1 => $this->signed($this->uint(2), 16),
            $byte === 0xd2 => $this->signed($this->uint(4), 32),
            $byte === 0xd3 => (int) $this->read('J', 8),
            $byte === 0xdc => $this->list($this->uint(2)),
            $byte === 0xdd => $this->list($this->uint(4)),
            $byte === 0xde => $this->map($this->uint(2)),
            $byte === 0xdf => $this->map($this->uint(4)),
            default => throw new ScrapflyException(sprintf('msgpack: unsupported type 0x%02x at byte %d', $byte, $this->offset - 1)),
        };
    }

    /** @return list<mixed> */
    private function list(int $size): array
    {
        $this->enter();
        $list = [];
        for ($i = 0; $i < $size; $i++) {
            $list[] = $this->value();
        }
        $this->depth--;

        return $list;
    }

    /** @return array<array-key, mixed> */
    private function map(int $size): array
    {
        $this->enter();
        $map = [];
        for ($i = 0; $i < $size; $i++) {
            $key = $this->value();
            if (!is_string($key) && !is_int($key)) {
                throw new ScrapflyException('msgpack: a map key is neither a string nor an integer');
            }
            $map[$key] = $this->value();
        }
        $this->depth--;

        return $map;
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new ScrapflyException('msgpack: containers nested deeper than ' . self::MAX_DEPTH);
        }
    }

    private function uint(int $bytes): int
    {
        return (int) $this->read([1 => 'C', 2 => 'n', 4 => 'N'][$bytes], $bytes);
    }

    private function uint64(): int|float
    {
        // J reads the 64 bits as a signed int: past PHP_INT_MAX it comes back negative.
        $value = (int) $this->read('J', 8);

        return $value < 0 ? $value + 18446744073709551616.0 : $value;
    }

    private function signed(int $value, int $bits): int
    {
        return $value >= 1 << ($bits - 1) ? $value - (1 << $bits) : $value;
    }

    private function read(string $format, int $length): int|float
    {
        $value = unpack($format, $this->take($length));
        if ($value === false) {
            throw new ScrapflyException("msgpack: cannot read {$format}");
        }

        return $value[1];
    }

    private function take(int $length): string
    {
        if ($this->offset + $length > strlen($this->data)) {
            throw new ScrapflyException('msgpack: the data ends inside a value');
        }
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
