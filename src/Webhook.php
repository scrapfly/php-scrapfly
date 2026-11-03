<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * Verify a webhook delivery: the `X-Scrapfly-Webhook-Signature` header is the
 * HMAC-SHA256 of the raw body, hex encoded, keyed by the webhook's signing secret.
 *
 * ```php
 * $body = file_get_contents('php://input');
 * if (!Webhook::verifySignature($body, $_SERVER['HTTP_X_SCRAPFLY_WEBHOOK_SIGNATURE'] ?? null, $secret)) {
 *     http_response_code(401);
 *     exit;
 * }
 * $payload = json_decode($body, true);
 * ```
 *
 * The signature covers the body before any `Content-Encoding`: decompress a
 * compressed delivery first. Alert webhooks sign `<timestamp>.<body>` and are not
 * verified by this method.
 */
final class Webhook
{
    /**
     * @param string|list<string> $secrets one secret, or several while one is rotated out
     */
    public static function verifySignature(string $body, ?string $signature, string|array $secrets): bool
    {
        if ($signature === null || trim($signature) === '') {
            return false;
        }
        $received = strtoupper(trim($signature));
        foreach ((array) $secrets as $secret) {
            if ($secret !== '' && hash_equals(strtoupper(hash_hmac('sha256', $body, $secret)), $received)) {
                return true;
            }
        }

        return false;
    }
}
