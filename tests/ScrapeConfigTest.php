<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scrapfly\Exception\ScrapeConfigException;
use Scrapfly\Format;
use Scrapfly\FormatOption;
use Scrapfly\ProxyPool;
use Scrapfly\ScrapeConfig;
use Scrapfly\ScreenshotFlag;

final class ScrapeConfigTest extends TestCase
{
    public function testBooleansAreSentAsTrueAndFalse(): void
    {
        $params = (new ScrapeConfig(url: 'https://web-scraping.dev/products', renderJs: true, retry: false, session: 's1', sessionStickyProxy: false))
            ->toApiParams('k');

        self::assertSame('true', $params['render_js']);
        self::assertSame('false', $params['retry']);
        self::assertSame('false', $params['session_sticky_proxy']);
    }

    public function testProxyPoolIsSentAsItsName(): void
    {
        self::assertSame('unblocker_ultra', (new ScrapeConfig(url: 'https://x.io', proxyPool: ProxyPool::UnblockerUltra))->toApiParams('k')['proxy_pool']);
        self::assertSame('my_pool', (new ScrapeConfig(url: 'https://x.io', proxyPool: 'my_pool'))->toApiParams('k')['proxy_pool']);
        self::assertArrayNotHasKey('asp', (new ScrapeConfig(url: 'https://x.io', proxyPool: ProxyPool::Unblocker))->toApiParams('k'));
    }

    /** @return iterable<string, array{?bool, ?bool, bool}> */
    public static function unblockerNames(): iterable
    {
        yield 'neither' => [null, null, false];
        yield 'unblocker on' => [true, null, true];
        yield 'unblocker off' => [false, null, false];
        yield 'asp on' => [null, true, true];
        yield 'asp wins over unblocker' => [true, false, false];
        yield 'asp on, unblocker off' => [false, true, true];
    }

    #[DataProvider('unblockerNames')]
    public function testTheDeprecatedUnblockerNamesAreSentAsAsp(?bool $unblocker, ?bool $asp, bool $sent): void
    {
        $config = new ScrapeConfig(url: 'https://x.io', unblocker: $unblocker, asp: $asp);

        self::assertSame($sent, $config->isUnblockerEnabled());
        self::assertSame($sent ? 'true' : null, $config->toApiParams('k')['asp'] ?? null);
        self::assertArrayNotHasKey('unblocker', $config->toApiParams('k'));
    }

    public function testOptionsThatNeedAnotherOneAreDropped(): void
    {
        $params = (new ScrapeConfig(url: 'https://x.io', waitForSelector: '#x', js: 'return 1', cacheTtl: 60, screenshots: ['all' => 'fullpage']))
            ->toApiParams('k');

        foreach (['wait_for_selector', 'js', 'cache_ttl', 'screenshots[all]', 'render_js', 'cache'] as $name) {
            self::assertArrayNotHasKey($name, $params);
        }
    }

    public function testBrowserOptionsAreEncoded(): void
    {
        $params = (new ScrapeConfig(
            url: 'https://x.io',
            renderJs: true,
            js: 'return document.title',
            jsScenario: [['click' => ['selector' => '#load-more']]],
            screenshots: ['main' => 'fullpage'],
            screenshotFlags: [ScreenshotFlag::BlockBanners, 'dark_mode'],
            renderingStage: 'domcontentloaded',
            autoScroll: true,
        ))->toApiParams('k');

        self::assertSame('return document.title', base64_decode(strtr($params['js'], '-_', '+/'), true));
        self::assertSame([['click' => ['selector' => '#load-more']]], json_decode(base64_decode(strtr($params['js_scenario'], '-_', '+/')), true));
        self::assertSame('fullpage', $params['screenshots[main]']);
        self::assertSame('block_banners,dark_mode', $params['screenshot_flags']);
        self::assertSame('domcontentloaded', $params['rendering_stage']);
        self::assertSame('true', $params['auto_scroll']);
    }

    public function testHeadersAndCookiesShareTheCookieHeader(): void
    {
        $params = (new ScrapeConfig(url: 'https://x.io', headers: ['Cookie' => 'a=1;', 'X-Test' => 'v'], cookies: ['b' => '2', 'c' => '3']))
            ->toApiParams('k');

        self::assertSame('a=1; b=2; c=3', $params['headers[cookie]']);
        self::assertSame('v', $params['headers[x-test]']);
    }

    public function testFormatTagsAndExtraction(): void
    {
        $params = (new ScrapeConfig(
            url: 'https://x.io',
            tags: ['a', 'b', 'a'],
            format: Format::Markdown,
            formatOptions: [FormatOption::NoLinks, 'only_content'],
            extractionEphemeralTemplate: ['source' => 'html'],
            extractionModel: 'product',
        ))->toApiParams('k');

        self::assertSame('a,b', $params['tags']);
        self::assertSame('markdown:no_links,only_content', $params['format']);
        self::assertStringStartsWith('ephemeral:', $params['extraction_template']);
        self::assertSame('product', $params['extraction_model']);
        self::assertSame('persistent:t1', (new ScrapeConfig(url: 'https://x.io', extractionTemplate: 't1'))->toApiParams('k')['extraction_template']);
    }

    public function testDataIsEncodedFromTheContentType(): void
    {
        $form = new ScrapeConfig(url: 'https://x.io', method: 'post', data: ['q' => 'é a']);
        self::assertSame('POST', $form->method);
        self::assertSame('q=%C3%A9+a', $form->body);
        self::assertSame('application/x-www-form-urlencoded', $form->headers['content-type']);

        $json = new ScrapeConfig(url: 'https://x.io', method: 'POST', data: ['q' => 'a/b'], headers: ['Content-Type' => 'application/json']);
        self::assertSame('{"q":"a/b"}', $json->body);

        $text = new ScrapeConfig(url: 'https://x.io', method: 'PUT', body: 'raw');
        self::assertSame('text/plain', $text->headers['content-type']);
    }

    public function testInvalidConfigsAreRefused(): void
    {
        $cases = [
            fn () => new ScrapeConfig(url: 'https://x.io', method: 'POST', body: 'a', data: ['a' => 1]),
            fn () => new ScrapeConfig(url: 'https://x.io', format: 'pdf'),
            fn () => new ScrapeConfig(url: 'https://x.io', formatOptions: ['no_tables']),
            fn () => new ScrapeConfig(url: 'https://x.io', screenshotFlags: ['sepia']),
            fn () => new ScrapeConfig(url: 'https://x.io', method: 'POST', data: ['a' => 1], headers: ['content-type' => 'text/xml']),
            fn () => (new ScrapeConfig(url: 'https://x.io', extractionTemplate: 't', extractionEphemeralTemplate: ['a' => 1]))->toApiParams('k'),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                self::fail("case {$index} was accepted");
            } catch (ScrapeConfigException) {
                self::assertTrue(true);
            }
        }
    }

    public function testProxyPoolLegacyNames(): void
    {
        self::assertTrue(ProxyPool::PublicResidentialPool->isLegacy());
        self::assertTrue(ProxyPool::PublicDatacenterPool->isLegacy());
        self::assertFalse(ProxyPool::PublicTorPool->isLegacy());
        self::assertFalse(ProxyPool::Auto->isLegacy());
    }
}
