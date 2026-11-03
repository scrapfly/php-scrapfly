<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ConfigException;

/**
 * A Cloud Browser session, sent as the query of the CDP WebSocket URL that
 * ScrapflyClient::cloudBrowserUrl() builds.
 *
 * ```php
 * $url = $client->cloudBrowserUrl(new BrowserConfig(proxyPool: 'residential', country: 'us', os: 'linux'));
 * ```
 *
 * `proxyPool` takes the Cloud Browser's own pools: `datacenter`, `residential` or `tor`.
 * The Web Scraping API pools (ProxyPool) do not apply here. Unset options are not sent.
 */
final class BrowserConfig
{
    public const MAX_TIMEOUT = 1800;

    /**
     * @param string|null $proxyPool `datacenter`, `residential` or `tor`
     * @param string|null $os `linux`, `windows`, `macos`, `android`, `iphone` or `ipad`
     * @param string|null $session a name to resume the session by; the browser then outlives the disconnect
     * @param list<string> $languages Accept-Language and navigator.languages, in order
     * @param int|null $timeout session lifetime in seconds, MAX_TIMEOUT at most
     * @param list<string> $extensions ids of uploaded extensions to load
     * @param string|null $targetUrl the site the session scrapes: its proxy is picked for it when the session opens
     * @param string|null $byopProxy a proxy of your own, `scheme://user:pass@host:port`
     * @param string|null $vault a credential vault id, opened with `vaultKey`
     * @param list<string> $hitlAllowedNetworks CIDRs allowed to attach over VNC, WebRTC and downloads without a password
     */
    public function __construct(
        public ?string $proxyPool = null,
        public ?string $os = null,
        public ?string $session = null,
        public ?string $country = null,
        public ?string $lang = null,
        public array $languages = [],
        public ?bool $autoClose = null,
        public ?int $timeout = null,
        public ?bool $debug = null,
        public array $extensions = [],
        public ?bool $blockImages = null,
        public ?bool $blockStyles = null,
        public ?bool $blockFonts = null,
        public ?bool $blockMedia = null,
        public ?bool $screenshot = null,
        public ?string $resolution = null,
        public ?string $targetUrl = null,
        public ?bool $cache = null,
        public ?bool $blacklist = null,
        public ?string $browserBrand = null,
        #[\SensitiveParameter]
        public ?string $byopProxy = null,
        public ?bool $enableMcp = null,
        public ?bool $solveCaptcha = null,
        public ?string $vault = null,
        #[\SensitiveParameter]
        public ?string $vaultKey = null,
        public ?bool $enableVnc = null,
        #[\SensitiveParameter]
        public ?string $vncPassword = null,
        public ?bool $enableRtc = null,
        public ?string $rtcUsername = null,
        #[\SensitiveParameter]
        public ?string $rtcPassword = null,
        public array $hitlAllowedNetworks = [],
    ) {
        if ($timeout !== null && $timeout > self::MAX_TIMEOUT) {
            throw new ConfigException('timeout cannot exceed ' . self::MAX_TIMEOUT . ' seconds');
        }
    }

    /** @return array<string, string> the query of the WebSocket URL, without the API key */
    public function toQuery(): array
    {
        $flags = [
            'auto_close' => $this->autoClose,
            'debug' => $this->debug,
            'block_images' => $this->blockImages,
            'block_styles' => $this->blockStyles,
            'block_fonts' => $this->blockFonts,
            'block_media' => $this->blockMedia,
            'screenshot' => $this->screenshot,
            'cache' => $this->cache,
            'blacklist' => $this->blacklist,
            'enable_mcp' => $this->enableMcp,
            'solve_captcha' => $this->solveCaptcha,
            'enable_vnc' => $this->enableVnc,
            'enable_rtc' => $this->enableRtc,
        ];
        $query = [
            'proxy_pool' => $this->proxyPool,
            'os' => $this->os,
            'session' => $this->session,
            'country' => $this->country,
            'lang' => $this->lang,
            'languages' => self::joined($this->languages),
            'timeout' => $this->timeout === null ? null : (string) $this->timeout,
            'extensions' => self::joined($this->extensions),
            'resolution' => $this->resolution,
            'target_url' => $this->targetUrl,
            'browser_brand' => $this->browserBrand,
            'byop_proxy' => $this->byopProxy,
            'vault' => $this->vault,
            'vault_key' => $this->vaultKey,
            'vnc_password' => $this->vncPassword,
            'rtc_username' => $this->rtcUsername,
            'rtc_password' => $this->rtcPassword,
            'hitl_allowed_networks' => self::joined($this->hitlAllowedNetworks),
        ] + array_map(static fn (?bool $flag): ?string => $flag === null ? null : Util::bool($flag), $flags);

        return array_filter($query, static fn (?string $value): bool => $value !== null);
    }

    /** @return array<string, mixed> what var_dump() and print_r() show: the secrets masked */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);
        foreach (['byopProxy', 'vaultKey', 'vncPassword', 'rtcPassword'] as $secret) {
            if ($values[$secret] !== null) {
                $values[$secret] = '***';
            }
        }

        return $values;
    }

    /** @param list<string> $values */
    private static function joined(array $values): ?string
    {
        $values = array_values(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''));

        return $values === [] ? null : implode(',', $values);
    }
}
