<?php

declare(strict_types=1);

namespace Scrapfly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Scrapfly\BrowserConfig;
use Scrapfly\Exception\CloudBrowserException;
use Scrapfly\Exception\ConfigException;
use Scrapfly\ScrapflyClient;
use Scrapfly\UnblockConfig;

final class CloudBrowserTest extends ClientTestCase
{
    public function testTheUrlCarriesTheKeyAndEveryOptionSet(): void
    {
        $url = $this->browserClient([])->cloudBrowserUrl(new BrowserConfig(
            proxyPool: 'residential',
            os: 'linux',
            country: 'us',
            languages: ['en-US', ' fr ', ''],
            autoClose: false,
            timeout: 600,
            extensions: ['ext1', 'ext2'],
            blockImages: true,
            targetUrl: 'https://web-scraping.dev/products?page=2',
            vault: 'v1',
            vaultKey: 'k+/=',
            hitlAllowedNetworks: ['10.0.0.0/8'],
        ));

        self::assertStringStartsWith('wss://browser.example.test?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'api_key' => self::KEY,
            'proxy_pool' => 'residential',
            'os' => 'linux',
            'country' => 'us',
            'languages' => 'en-US,fr',
            'timeout' => '600',
            'extensions' => 'ext1,ext2',
            'target_url' => 'https://web-scraping.dev/products?page=2',
            'vault' => 'v1',
            'vault_key' => 'k+/=',
            'hitl_allowed_networks' => '10.0.0.0/8',
            'auto_close' => 'false',
            'block_images' => 'true',
        ], $query);
    }

    public function testTheDefaultUrlCarriesTheKeyAlone(): void
    {
        $client = new ScrapflyClient(self::KEY);

        self::assertSame('wss://browser.scrapfly.io?api_key=' . self::KEY, $client->cloudBrowserUrl());
    }

    public function testVncPasswordIsSaltedWithTheKey(): void
    {
        $client = $this->browserClient([]);

        self::assertSame(substr(hash('sha256', self::KEY), 0, 8), $client->cloudBrowserProjectSalt());
        self::assertSame($client->cloudBrowserProjectSalt() . '-s3cret', $client->cloudBrowserVncPassword(new BrowserConfig(enableVnc: true, vncPassword: 's3cret')));

        $this->expectException(ConfigException::class);
        $client->cloudBrowserVncPassword(new BrowserConfig(vncPassword: 's3cret'));
    }

    public function testATimeoutPastTheCeilingIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        new BrowserConfig(timeout: BrowserConfig::MAX_TIMEOUT + 1);
    }

    public function testSecretsAreMaskedInDumps(): void
    {
        $dump = print_r(new BrowserConfig(vaultKey: 'vk-secret', vncPassword: 'vnc-secret', byopProxy: 'socks5h://u:p@h:1'), true);

        self::assertStringNotContainsString('secret', $dump);
        self::assertStringNotContainsString('u:p@', $dump);
    }

    public function testUnblockPostsToTheBrowserHostOnce(): void
    {
        $client = $this->browserClient([
            self::json(['ws_url' => 'wss://browser.example.test/cdp/1', 'session_id' => 's1', 'run_id' => 'r1']),
            new \GuzzleHttp\Exception\ConnectException('reset', new \GuzzleHttp\Psr7\Request('POST', '/')),
        ]);

        $result = $client->cloudBrowserUnblock(new UnblockConfig(url: 'https://web-scraping.dev/products', country: 'us', session: 'shop', enableMcp: true));

        self::assertSame('s1', $result['session_id']);
        $request = $this->sent[0]['request'];
        self::assertSame('https://browser.example.test/unblock', (string) $request->getUri()->withQuery(''));
        self::assertSame(self::KEY, $this->query()['key']);
        self::assertSame(['url' => 'https://web-scraping.dev/products', 'country' => 'us', 'session' => 'shop', 'enable_mcp' => true], $this->jsonBody());

        // The Unblocker navigation is billed: a failed connection is not retried.
        try {
            $client->cloudBrowserUnblock(new UnblockConfig(url: 'https://web-scraping.dev/products'));
            self::fail('the failed unblock did not throw');
        } catch (\Scrapfly\Exception\TransportException) {
        }
        self::assertCount(2, $this->sent);
        self::assertSame(160.0, $this->sent[0]['options']['timeout'], 'the default navigation fits the read timeout');
    }

    public function testALongUnblockNavigationIsWaitedFor(): void
    {
        $client = $this->browserClient([self::json(['ws_url' => 'wss://browser.example.test/cdp/1', 'session_id' => 's1', 'run_id' => 'r1'])]);

        $client->cloudBrowserUnblock(new UnblockConfig(url: 'https://web-scraping.dev/products', timeout: 240));

        self::assertSame(300, $this->sent[0]['options']['timeout']);
    }

    public function testAVaultIsCreatedOnce(): void
    {
        // Its key is in this answer only: a second vault would leave the first one's lost.
        $client = $this->browserClient([new \GuzzleHttp\Exception\ConnectException('empty reply', new \GuzzleHttp\Psr7\Request('POST', '/'))]);

        try {
            $client->cloudBrowserVaultCreate('shop');
            self::fail('the failed create did not throw');
        } catch (\Scrapfly\Exception\TransportException) {
        }
        self::assertCount(1, $this->sent);
    }

    public function testBrowserErrorsAreTyped(): void
    {
        $client = $this->browserClient([self::json(['code' => 'ERR::BROWSER::SESSION_NOT_FOUND', 'message' => 'no such session', 'http_code' => 404], 404)]);

        try {
            $client->cloudBrowserSessionStop('nope');
            self::fail('the stop of an unknown session did not throw');
        } catch (CloudBrowserException $e) {
            self::assertSame('ERR::BROWSER::SESSION_NOT_FOUND', $e->getErrorCode());
            self::assertSame(404, $e->getHttpStatusCode());
        }
    }

    public function testVaultCallsSendTheKeyAsAHeaderOnly(): void
    {
        $client = $this->browserClient(array_fill(0, 5, self::json(['ok' => true])));

        $client->cloudBrowserVaultItemCreate('v1', 'vault-key', 'password', 'Shop', 'https://web-scraping.dev', ['password' => 'p'], username: 'user');
        self::assertSame('vault-key', $this->sent[0]['request']->getHeaderLine('x-vault-key'));
        self::assertArrayNotHasKey('vault_key', $this->query(0));
        self::assertSame(['type' => 'password', 'label' => 'Shop', 'origin' => 'https://web-scraping.dev', 'secret' => ['password' => 'p'], 'username' => 'user'], $this->jsonBody(0));

        $client->cloudBrowserVaultItemUpdate('v1', 'i1', label: 'Shop 2');
        self::assertSame('PATCH', $this->sent[1]['request']->getMethod());
        self::assertFalse($this->sent[1]['request']->hasHeader('x-vault-key'));
        self::assertSame(['label' => 'Shop 2'], $this->jsonBody(1));

        $client->cloudBrowserVaultServiceLink('v1', 'vault-key', 'ops_token', ['vault_id' => 'up1', 'token_item_id' => 'set-by-server']);
        self::assertSame(['linked_service' => '1password', 'token' => 'ops_token', 'linked_service_data' => ['vault_id' => 'up1']], $this->jsonBody(2));

        $client->cloudBrowserVaultServiceTest('v1', 'vault-key');
        self::assertSame('', (string) $this->sent[3]['request']->getBody(), 'a probe of the sealed token sends no body');

        $client->cloudBrowserVaultServiceUnlink('v1', keepItems: false);
        self::assertSame('false', $this->query(4)['keep_items']);
        self::assertSame('DELETE', $this->sent[4]['request']->getMethod());
    }

    public function testANewSecretNeedsTheKeyAndItsType(): void
    {
        $this->expectException(ConfigException::class);
        $this->browserClient([])->cloudBrowserVaultItemUpdate('v1', 'i1', secret: ['password' => 'p'], type: 'password');
    }

    public function testWaitForPlaybackPollsWhileUploading(): void
    {
        $client = $this->browserClient([
            self::json(['status' => 'uploading', 'retry_after_ms' => 1]),
            self::json(['status' => 'ready', 'video_url' => 'https://x/video']),
        ]);

        self::assertSame('ready', $client->cloudBrowserWaitForPlayback('r1')['status']);
        self::assertCount(2, $this->sent);
    }

    public function testExtensionUploadIsAMultipartFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ext') . '.zip';
        file_put_contents($path, 'PK-zip-bytes');
        $client = $this->browserClient([self::json(['extension' => ['id' => 'e1'], 'is_update' => false])]);

        try {
            self::assertSame('e1', $client->cloudBrowserExtensionUpload($path)['extension']['id']);
        } finally {
            unlink($path);
        }
        $body = (string) $this->sent[0]['request']->getBody();
        self::assertStringContainsString('name="file"; filename="' . basename($path) . '"', $body);
        self::assertStringContainsString('PK-zip-bytes', $body);
    }

    /** @param list<mixed> $replies */
    private function browserClient(array $replies): ScrapflyClient
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($replies));
        $stack->push(Middleware::history($this->sent));

        return new ScrapflyClient(self::KEY, 'https://api.example.test', new Client(['handler' => $stack]), cloudBrowserHost: 'wss://browser.example.test/');
    }
}
