<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use PHPUnit\Framework\TestCase;
use Scrapfly\Webhook;

final class WebhookTest extends TestCase
{
    public function testTheSignatureIsTheHmacOfTheBody(): void
    {
        $body = '{"event":"crawler_finished"}';
        $signature = strtoupper(hash_hmac('sha256', $body, 'secret'));

        self::assertTrue(Webhook::verifySignature($body, $signature, 'secret'));
        self::assertTrue(Webhook::verifySignature($body, strtolower($signature), 'secret'));
        self::assertTrue(Webhook::verifySignature($body, " {$signature} ", ['old', 'secret']));
        self::assertFalse(Webhook::verifySignature($body . ' ', $signature, 'secret'));
        self::assertFalse(Webhook::verifySignature($body, $signature, 'other'));
        self::assertFalse(Webhook::verifySignature($body, null, 'secret'));
        self::assertFalse(Webhook::verifySignature($body, $signature, ''));
    }
}
