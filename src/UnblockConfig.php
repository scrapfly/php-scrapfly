<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * An unblock, `POST /unblock` of the Cloud Browser API: the Unblocker opens `url`
 * on a residential proxy and hands back a browser past the anti-bot, ready for CDP.
 */
final class UnblockConfig
{
    /**
     * @param string|null $session reuses the named Unblocker session when it exists, and keeps the browser open on disconnect
     * @param int|null $timeout navigation timeout in seconds, 300 at most
     * @param int|null $browserTimeout session lifetime in seconds, 1800 at most
     * @param bool|null $debug records the session: cloudBrowserPlayback() and cloudBrowserVideo() read it by run_id
     */
    public function __construct(
        public string $url,
        public ?string $country = null,
        public ?string $os = null,
        public ?string $browserBrand = null,
        public ?string $session = null,
        public ?int $timeout = null,
        public ?int $browserTimeout = null,
        public ?bool $enableMcp = null,
        public ?bool $debug = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toApiBody(): array
    {
        return array_filter([
            'url' => $this->url,
            'country' => $this->country,
            'os' => $this->os,
            'browser_brand' => $this->browserBrand,
            'session' => $this->session,
            'timeout' => $this->timeout,
            'browser_timeout' => $this->browserTimeout,
            'enable_mcp' => $this->enableMcp,
            'debug' => $this->debug,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
