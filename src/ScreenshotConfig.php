<?php

declare(strict_types=1);

namespace Scrapfly;

use Scrapfly\Exception\ScreenshotConfigException;

/**
 * A request to the Screenshot API, `GET /screenshot`.
 *
 * ```php
 * $config = new ScreenshotConfig(
 *     url: 'https://web-scraping.dev/product/1',
 *     format: ScreenshotFormat::Png,
 *     capture: 'fullpage',
 *     options: [ScreenshotOption::BlockBanners],
 * );
 * ```
 */
final class ScreenshotConfig
{
    public ScreenshotFormat|string|null $format;

    public VisionDeficiency|string|null $visionDeficiency;

    /**
     * @param string|null $capture `viewport` (default), `fullpage`, or a CSS selector
     * @param string|null $resolution `WIDTHxHEIGHT`, `1920x1080` by default
     * @param int|null $timeout milliseconds
     * @param list<ScreenshotOption|string> $options
     * @param ProxyPool|string|null $proxyPool the proxy pool; a string for a pool of your own
     */
    public function __construct(
        public string $url,
        ScreenshotFormat|string|null $format = null,
        public ?string $capture = null,
        public ?string $resolution = null,
        public ?string $country = null,
        public ?int $timeout = null,
        public ?int $renderingWait = null,
        public ?string $waitForSelector = null,
        public array $options = [],
        public ?bool $autoScroll = null,
        public ?string $js = null,
        public bool $cache = false,
        public ?int $cacheTtl = null,
        public bool $cacheClear = false,
        VisionDeficiency|string|null $visionDeficiency = null,
        public ?string $webhook = null,
        public ProxyPool|string|null $proxyPool = null,
    ) {
        $this->format = is_string($format) ? (ScreenshotFormat::tryFrom($format) ?? throw new ScreenshotConfigException("Invalid format: {$format}")) : $format;
        $this->visionDeficiency = is_string($visionDeficiency)
            ? (VisionDeficiency::tryFrom($visionDeficiency) ?? throw new ScreenshotConfigException("Invalid vision deficiency: {$visionDeficiency}"))
            : $visionDeficiency;
        foreach ($options as $option) {
            if (is_string($option) && ScreenshotOption::tryFrom($option) === null) {
                throw new ScreenshotConfigException("Invalid option: {$option}");
            }
        }
    }

    /** @return array<string, string> */
    public function toApiParams(string $key): array
    {
        $params = ['key' => $key, 'url' => $this->url];
        $optional = [
            'format' => $this->format === null ? null : Util::value($this->format),
            'capture' => $this->capture,
            'resolution' => $this->resolution,
            'country' => $this->country,
            'timeout' => $this->timeout,
            'rendering_wait' => $this->renderingWait,
            'wait_for_selector' => $this->waitForSelector,
            'options' => $this->options === [] ? null : Util::joinValues($this->options),
            'auto_scroll' => $this->autoScroll === null ? null : Util::bool($this->autoScroll),
            'js' => $this->js === null ? null : Util::urlSafeBase64($this->js),
        ];
        if ($this->cache) {
            $optional['cache'] = 'true';
            $optional['cache_ttl'] = $this->cacheTtl;
            $optional['cache_clear'] = $this->cacheClear ? 'true' : null;
        }
        $optional['vision_deficiency'] = $this->visionDeficiency === null ? null : Util::value($this->visionDeficiency);
        $optional['webhook_name'] = $this->webhook;
        $optional['proxy_pool'] = $this->proxyPool === null ? null : Util::value($this->proxyPool);

        foreach ($optional as $name => $value) {
            if ($value !== null) {
                $params[$name] = (string) $value;
            }
        }

        return $params;
    }
}
