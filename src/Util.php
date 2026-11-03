<?php

declare(strict_types=1);

namespace Scrapfly;

/** @internal */
final class Util
{
    public static function urlSafeBase64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The API reads `true`/`false`; http_build_query would send `1`/`0`. */
    public static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public static function value(\BackedEnum|string $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }

    /** @param list<\BackedEnum|string> $values */
    public static function joinValues(array $values): string
    {
        return implode(',', array_map(self::value(...), $values));
    }

    /**
     * Mask the API key in a text that names a request URL: an HTTP client's error
     * message prints the URL, query and key included.
     */
    public static function maskKey(string $text): string
    {
        return preg_replace('/(?<=[?&]key=|[?&]api_key=)[^&\s\'")]+/', 'REDACTED', $text) ?? $text;
    }
}
