<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Plugin\VarnishAccess;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\PageCache\Model\VclGeneratorInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class VarnishAccessTest extends TestCase
{
    private function plugin(bool $enabled = true, array $points = ['all_pages'], array $paths = [],
        string $baseUrl = 'https://shop.example/en/', ?string $secureBaseUrl = null): VarnishAccess
    {
        $config = $this->createMock(Config::class);
        $config->method('settings')->willReturn(['enabled' => $enabled, 'points' => $points, 'pageRules' => $paths]);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturnCallback(
            fn($type = null, $secure = null) => $secure !== false ? ($secureBaseUrl ?? $baseUrl) : $baseUrl
        );
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$store]);
        return new VarnishAccess($config, $stores, new PathMatcher());
    }

    public function testDisabledScopesPreserveNativeCacheRules(): void
    {
        $template = 'vcl 4.0; sub vcl_recv { }';
        self::assertSame($template, $this->plugin(false)->afterGenerateVcl($this->createMock(VclGeneratorInterface::class), $template));
    }

    public function testSelectedPathsAndHostAreScopedAndPurgeIsPreserved(): void
    {
        $result = $this->plugin(true, ['complete_order'], ['private/*', 'price$1'])->afterGenerateVcl(
            $this->createMock(VclGeneratorInterface::class), 'vcl 4.0; sub vcl_recv { return (hash); }');
        self::assertStringContainsString('req.method != "PURGE"', $result);
        self::assertStringContainsString('shop', $result);
        self::assertStringContainsString('^/en/private/', $result);
        self::assertStringContainsString('$1', $result);
        self::assertStringNotContainsString('^/en/[^?]*', $result);
        self::assertLessThan(strpos($result, 'return (hash)'), strpos($result, 'return (pass)'));
    }

    /** @dataProvider nativePoints */
    public function testNativeSeoAliasesKeepConservativeStoreCoverage(string $point): void
    {
        $result = $this->plugin(true, [$point])->afterGenerateVcl($this->createMock(VclGeneratorInterface::class), 'sub vcl_recv {}');
        self::assertStringContainsString('^/en/[^?]*', $result);
    }

    public static function nativePoints(): array
    {
        return [['all_pages'], ['product'], ['cart'], ['checkout']];
    }

    /** @dataProvider scopedRequests */
    public function testExportedRegexMatchesTheActualRequest(string $host, string $url, bool $expected): void
    {
        $vcl = $this->plugin(true, ['complete_order'], ['private/*', 'price$1', 'quote"test', 'café'])
            ->afterGenerateVcl($this->createMock(VclGeneratorInterface::class), 'sub vcl_recv {}');
        self::assertSame($expected, $this->bypassesCache($vcl, $host, $url));
    }

    public static function scopedRequests(): array
    {
        return [
            ['shop.example', '/en/private/report', true],
            ['SHOP.EXAMPLE:443', '/en/private/report?x=1', true],
            ['shop.example', '/en/price$1?x=1', true],
            ['shop.example', '/en/priceX1', false],
            ['shop.example', '/en/quote"test', true],
            ['shop.example', '/en/café', true],
            ['shop.example', '/en/caf%C3%A9', true],
            ['shop.example', '/en/antidetectbrowser/access/check', true],
            ['shop.example', '/en/index.php/private/report', true],
            ['shop.example', '/en//private/report', true],
            ['shop.example', '/en/public', false],
            ['shop.example', '/en', true],
            ['shop.example', '/en?x=1', true],
            ['shop.example', '/elsewhere?___store=protected', true],
            ['shop.example', '/en/private-other', false],
            ['shop.example', '/elsewhere/private/report', false],
            ['shopXexample', '/en/private/report', false],
            ['other.example', '/en/private/report', false],
        ];
    }

    public function testSecureAndUnsecureStoreHostsAreBothProtected(): void
    {
        $vcl = $this->plugin(true, ['complete_order'], ['private/*'],
            'http://shop.example/en/', 'https://secure.example/en/')
            ->afterGenerateVcl($this->createMock(VclGeneratorInterface::class), 'sub vcl_recv {}');
        self::assertTrue($this->bypassesCache($vcl, 'shop.example', '/en/private/report'));
        self::assertTrue($this->bypassesCache($vcl, 'secure.example', '/en/private/report'));
        self::assertFalse($this->bypassesCache($vcl, 'unrelated.example', '/en/private/report'));
    }

    private function bypassesCache(string $vcl, string $host, string $url): bool
    {
        // VCL passes the literal backslashes to PCRE; JSON decoding here would hide an export bug.
        preg_match_all('/\(req[.]http[.]host ~ "([^"]*)" && req[.]url ~ "([^"]*)"\)/', $vcl, $conditions,
            PREG_SET_ORDER);
        self::assertNotEmpty($conditions);
        foreach ($conditions as $condition) {
            if (preg_match('~' . $condition[1] . '~', $host) && preg_match('~' . $condition[2] . '~', $url)) {
                return true;
            }
        }
        return false;
    }

    public function testUnknownTemplateCannotSilentlyGenerateUnsafeVcl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->plugin()->afterGenerateVcl($this->createMock(VclGeneratorInterface::class), 'unsupported template');
    }
}
