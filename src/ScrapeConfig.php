<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ScrapeConfigException;

/**
 * A request to the Web Scraping API, `GET|POST|... /scrape`.
 *
 * ```php
 * $config = new ScrapeConfig(
 *     url: 'https://web-scraping.dev/products',
 *     proxyPool: ProxyPool::Unblocker,
 *     renderJs: true,
 *     format: Format::Markdown,
 * );
 * ```
 *
 * Options that only apply with another one set (`waitForSelector` without
 * `renderJs`, `cacheTtl` without `cache`) are not sent.
 */
final class ScrapeConfig
{
    /** @var array<string, string> lower-cased header names */
    public array $headers;

    /** @var array<string, string> */
    public array $cookies;

    /** The Unblocker toggle, sent as `asp`. Set through `proxyPool`, or the deprecated `unblocker` and `asp`. */
    public bool $asp;

    public ProxyPool|string|null $proxyPool;

    public Format|string|null $format;

    /** @var list<FormatOption|string> */
    public array $formatOptions;

    /**
     * @param array<string, mixed>|null $data form or JSON fields, encoded into `body` from the `content-type` header
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param ProxyPool|string|null $proxyPool the proxy pool, which sets the Unblocker too; a string for a pool of your own
     * @param list<string> $tags
     * @param list<FormatOption|string> $formatOptions
     * @param array<string, mixed>|null $extractionEphemeralTemplate
     * @param array<string, string>|null $screenshots name => CSS selector, or `fullpage`
     * @param list<ScreenshotFlag|string> $screenshotFlags
     * @param list<array<string, mixed>>|null $jsScenario
     * @param list<string> $lang
     * @param int|null $timeout milliseconds
     * @param bool $proxifiedResponse answer with the target's own response; a batch part, or scrapeProxified() for one scrape
     * @param bool|null $unblocker deprecated: use `proxyPool` (`ProxyPool::UnblockerUltra` for the Unblocker)
     * @param bool|null $asp deprecated: use `proxyPool` (`ProxyPool::UnblockerUltra` for the Unblocker)
     */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public ?string $body = null,
        public ?array $data = null,
        array $headers = [],
        array $cookies = [],
        public ?string $country = null,
        ProxyPool|string|null $proxyPool = null,
        public bool $renderJs = false,
        public bool $retry = true,
        public bool $cache = false,
        public bool $cacheClear = false,
        public ?int $cacheTtl = null,
        public ?int $costBudget = null,
        public bool $ssl = false,
        public bool $dns = false,
        public bool $debug = false,
        public bool $raiseOnUpstreamError = true,
        public ?string $session = null,
        public bool $sessionStickyProxy = true,
        public array $tags = [],
        Format|string|null $format = null,
        array $formatOptions = [],
        public ?string $extractionTemplate = null,
        public ?array $extractionEphemeralTemplate = null,
        public ?string $extractionPrompt = null,
        public ?string $extractionModel = null,
        public ?string $correlationId = null,
        public ?string $js = null,
        public ?int $renderingWait = null,
        public ?string $renderingStage = null,
        public ?string $waitForSelector = null,
        public ?array $screenshots = null,
        public array $screenshotFlags = [],
        public ?string $webhook = null,
        public ?int $timeout = null,
        public ?array $jsScenario = null,
        public array $lang = [],
        public ?string $os = null,
        public ?bool $autoScroll = null,
        public ?string $browserBrand = null,
        public ?string $geolocation = null,
        public bool $proxifiedResponse = false,
        ?bool $unblocker = null,
        ?bool $asp = null,
    ) {
        $this->method = strtoupper($method);
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->cookies = $cookies;
        $this->proxyPool = $proxyPool;
        $this->format = is_string($format) ? (Format::tryFrom($format) ?? throw new ScrapeConfigException("Invalid format: {$format}")) : $format;
        foreach ($formatOptions as $option) {
            if (is_string($option) && FormatOption::tryFrom($option) === null) {
                throw new ScrapeConfigException("Invalid format option: {$option}");
            }
        }
        $this->formatOptions = $formatOptions;
        foreach ($screenshotFlags as $flag) {
            if (is_string($flag) && ScreenshotFlag::tryFrom($flag) === null) {
                throw new ScrapeConfigException("Invalid screenshot flag: {$flag}");
            }
        }
        // `asp` is the deprecated alias of `unblocker`: an explicit `asp` wins,
        // and an explicit false on either name turns the feature off.
        $this->asp = $asp ?? $unblocker ?? false;

        if ($this->body !== null && $this->data !== null) {
            throw new ScrapeConfigException('Cannot set both body and data');
        }
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $this->encodeBody();
        } elseif ($this->data !== null || $this->body !== null) {
            throw new ScrapeConfigException("A {$this->method} request carries no body: use POST, PUT or PATCH");
        }
        foreach ($cookies as $name => $value) {
            // A separator would split one cookie into several.
            if (preg_match('/[;,\s]/', $name . $value) === 1) {
                throw new ScrapeConfigException("Cookie {$name}: a name or value cannot hold ';', ',' or whitespace");
            }
        }
    }

    /** Whether the Unblocker is asked for through `unblocker` or `asp`. A proxy pool sets it on its own. */
    public function isUnblockerEnabled(): bool
    {
        return $this->asp;
    }

    private function encodeBody(): void
    {
        $contentType = $this->headers['content-type'] ?? null;
        if ($this->data !== null) {
            if ($contentType === null || str_contains($contentType, 'application/x-www-form-urlencoded')) {
                $this->headers['content-type'] ??= 'application/x-www-form-urlencoded';
                $this->body = http_build_query($this->data);
            } elseif (str_contains($contentType, 'application/json')) {
                $this->body = json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } else {
                throw new ScrapeConfigException("Content-Type \"{$contentType}\" not supported: pass the encoded body as `body`");
            }
        } elseif ($this->body !== null && $contentType === null) {
            $this->headers['content-type'] = 'text/plain';
        }
    }

    /**
     * The query string parameters of `/scrape`.
     *
     * @return array<string, string>
     */
    public function toApiParams(#[\SensitiveParameter] string $key): array
    {
        $params = ['key' => $key, 'url' => $this->url];
        if ($this->country !== null) {
            $params['country'] = $this->country;
        }
        foreach ($this->headers as $name => $value) {
            $params["headers[{$name}]"] = $value;
        }
        if ($this->cookies !== []) {
            $cookies = implode('; ', array_map(
                static fn (string $name, string $value): string => "{$name}={$value}",
                array_keys($this->cookies),
                $this->cookies,
            ));
            $existing = $params['headers[cookie]'] ?? null;
            $params['headers[cookie]'] = $existing === null ? $cookies : rtrim($existing, '; ') . '; ' . $cookies;
        }
        if ($this->webhook !== null) {
            $params['webhook_name'] = $this->webhook;
        }
        if ($this->timeout !== null) {
            $params['timeout'] = (string) $this->timeout;
        }
        if ($this->costBudget !== null) {
            $params['cost_budget'] = (string) $this->costBudget;
        }
        if ($this->renderJs) {
            $params['render_js'] = 'true';
            if ($this->waitForSelector !== null) {
                $params['wait_for_selector'] = $this->waitForSelector;
            }
            if ($this->js !== null) {
                $params['js'] = Util::urlSafeBase64($this->js);
            }
            if ($this->jsScenario !== null) {
                $params['js_scenario'] = Util::urlSafeBase64(Util::json($this->jsScenario));
            }
            if ($this->renderingWait !== null) {
                $params['rendering_wait'] = (string) $this->renderingWait;
            }
            if ($this->renderingStage !== null && $this->renderingStage !== 'complete') {
                $params['rendering_stage'] = $this->renderingStage;
            }
            if ($this->geolocation !== null) {
                $params['geolocation'] = $this->geolocation;
            }
            if ($this->screenshots !== null) {
                foreach ($this->screenshots as $name => $selector) {
                    $params["screenshots[{$name}]"] = $selector;
                }
                if ($this->screenshotFlags !== []) {
                    $params['screenshot_flags'] = Util::joinValues($this->screenshotFlags);
                }
            }
            if ($this->autoScroll !== null) {
                $params['auto_scroll'] = Util::bool($this->autoScroll);
            }
        }
        if ($this->proxifiedResponse) {
            $params['proxified_response'] = 'true';
        }
        // The wire key stays `asp` for both deprecated names.
        if ($this->asp) {
            $params['asp'] = 'true';
        }
        if (!$this->retry) {
            $params['retry'] = 'false';
        }
        if ($this->cache) {
            $params['cache'] = 'true';
            if ($this->cacheClear) {
                $params['cache_clear'] = 'true';
            }
            if ($this->cacheTtl !== null) {
                $params['cache_ttl'] = (string) $this->cacheTtl;
            }
        }
        if ($this->dns) {
            $params['dns'] = 'true';
        }
        if ($this->ssl) {
            $params['ssl'] = 'true';
        }
        if ($this->tags !== []) {
            $params['tags'] = implode(',', array_unique($this->tags));
        }
        if ($this->format !== null) {
            $params['format'] = Util::value($this->format);
            if ($this->formatOptions !== []) {
                $params['format'] .= ':' . Util::joinValues($this->formatOptions);
            }
        }
        if ($this->extractionTemplate !== null && $this->extractionEphemeralTemplate !== null) {
            throw new ScrapeConfigException('Pass either extractionTemplate or extractionEphemeralTemplate, not both');
        }
        if ($this->extractionTemplate !== null) {
            $params['extraction_template'] = 'persistent:' . $this->extractionTemplate;
        }
        if ($this->extractionEphemeralTemplate !== null) {
            $params['extraction_template'] = 'ephemeral:' . Util::urlSafeBase64(Util::json($this->extractionEphemeralTemplate));
        }
        if ($this->extractionPrompt !== null) {
            $params['extraction_prompt'] = $this->extractionPrompt;
        }
        if ($this->extractionModel !== null) {
            $params['extraction_model'] = $this->extractionModel;
        }
        if ($this->correlationId !== null) {
            $params['correlation_id'] = $this->correlationId;
        }
        if ($this->session !== null) {
            $params['session'] = $this->session;
            $params['session_sticky_proxy'] = Util::bool($this->sessionStickyProxy);
        }
        if ($this->debug) {
            $params['debug'] = 'true';
        }
        if ($this->proxyPool !== null) {
            $params['proxy_pool'] = Util::value($this->proxyPool);
        }
        if ($this->lang !== []) {
            $params['lang'] = implode(',', $this->lang);
        }
        if ($this->os !== null) {
            $params['os'] = $this->os;
        }
        if ($this->browserBrand !== null) {
            $params['browser_brand'] = $this->browserBrand;
        }

        return $params;
    }
}
