<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Block;

use Hachitechs\Hideguard\Block\Bootstrap;
use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BootstrapTest extends TestCase
{
    private function block(
        Access $access,
        PageConfig $pageConfig,
        bool $protected = true,
        string $action = 'catalog_Product_View',
        string $returnPath = '/',
        ?LoggerInterface $logger = null,
        array $points = ['product'],
        bool $enabled = true,
        string $endpointUrl = 'https://hideguard.io'
    ): Bootstrap {
        $config = $this->createMock(Config::class);
        $config->method('settings')->willReturn([
            'enabled' => $enabled, 'points' => $points, 'environment' => 'production',
            'keys' => ['production' => ['publicKey' => 'public-test-key', 'endpointUrl' => $endpointUrl]],
        ]);
        $rules = $this->createMock(PageRules::class);
        $rules->method('protects')->willReturn($protected);
        $stores = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://store.test/');
        $stores->method('getStore')->willReturn($store);
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn('/catalog/Product/View/id/1');
        $request->method('getFullActionName')->willReturn($action);
        $request->method('getParam')->willReturnMap([['return', '', base64_encode($returnPath)]]);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://store.test/access');
        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($stores);
        $context->method('getRequest')->willReturn($request);
        $context->method('getPageConfig')->willReturn($pageConfig);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getLogger')->willReturn($logger ?? $this->createMock(LoggerInterface::class));
        return new Bootstrap($context, $config, $rules, $access);
    }

    private function accessPending(): Access
    {
        $access = $this->createMock(Access::class);
        $access->method('pageStatus')->willReturn(['status' => 'verification_required']);
        return $access;
    }

    public function testBrowserEndpointIsFixedToTheLiveHost(): void
    {
        $block = $this->block($this->accessPending(), $this->createMock(PageConfig::class),
            endpointUrl: 'https://store.hideguard.example:8443');
        self::assertSame('https://hideguard.io', $block->getBrowserConfig()['endpointUrl']);
        self::assertArrayNotHasKey('signaturePublicKey', $block->getBrowserConfig());
    }

    public function testLayoutLocksImmediatelyAndReadsAccessOnlyWhenRenderingConfig(): void
    {
        $reads = 0;
        $access = $this->createMock(Access::class);
        $access->expects(self::once())->method('pageStatus')->with(1)->willReturnCallback(function () use (&$reads) {
            $reads++;
            return ['status' => 'verification_required'];
        });
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getElementAttribute')->willReturn(null);
        $pageConfig->expects(self::exactly(2))->method('setElementAttribute')->withConsecutive(
            [PageConfig::ELEMENT_TYPE_BODY, 'inert', ''],
            [PageConfig::ELEMENT_TYPE_BODY, 'data-hachitech-hideguard-inert', '']
        );
        $block = $this->block($access, $pageConfig);
        (new \ReflectionMethod(Bootstrap::class, '_prepareLayout'))->invoke($block);
        self::assertSame(0, $reads);
        $config = $block->getBrowserConfig();
        self::assertSame(1, $reads);
        self::assertSame('', $config['page']);
        self::assertTrue($config['pageProtected']);
        self::assertSame('pending', $config['initialState']);
    }

    public function testUnprotectedPageDoesNotReadPermissionOrSetAnInteractionLock(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects(self::never())->method('pageStatus');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->expects(self::never())->method('setElementAttribute');
        $block = $this->block($access, $pageConfig, false, 'cms_index_index');
        (new \ReflectionMethod(Bootstrap::class, '_prepareLayout'))->invoke($block);
        self::assertFalse($block->getBrowserConfig()['pageProtected']);
    }

    /** @dataProvider initializerModes */
    public function testEnabledModuleListensForActionDenialsWithoutProtectingUnselectedPages(
        array $points,
        bool $protected,
        string $action,
        bool $enabled,
        bool $expected
    ): void {
        $access = $this->createMock(Access::class);
        if (!$enabled || (!$protected && $action !== 'hideguard_gate_index')) {
            $access->expects(self::never())->method('pageStatus');
        } else {
            $access->method('pageStatus')->willReturn(['status' => 'verification_required']);
        }
        $block = $this->block(
            $access, $this->createMock(PageConfig::class), $protected, $action, '/', null, $points, $enabled
        );
        self::assertSame($expected, $block->getBrowserConfig()['enabled']);
    }

    public static function initializerModes(): array
    {
        return [
            [['product'], false, 'catalog_product_view', true, true],
            [[], false, 'cms_index_index', true, true],
            [[], true, 'custom_page_index', true, true],
            [['cart'], false, 'cms_index_index', true, true],
            [['checkout'], false, 'cms_index_index', true, true],
            [['complete_order'], false, 'cms_index_index', true, true],
            [[], false, 'hideguard_gate_index', true, true],
            [['product'], true, 'catalog_product_view', false, false],
        ];
    }

    public function testAnotherComponentsBodyLockIsPreserved(): void
    {
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getElementAttribute')->willReturn('');
        $pageConfig->expects(self::never())->method('setElementAttribute');
        $block = $this->block($this->createMock(Access::class), $pageConfig);
        (new \ReflectionMethod(Bootstrap::class, '_prepareLayout'))->invoke($block);
    }

    public function testInitialStateFailureIsLoggedAndDoesNotBecomeAnAllow(): void
    {
        $error = new \RuntimeException('Storage unavailable');
        $access = $this->createMock(Access::class);
        $access->method('pageStatus')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'HideGuard initial access state failed.', ['exception' => $error]
        );
        $block = $this->block($access, $this->createMock(PageConfig::class), true, 'catalog_Product_View', '/', $logger);
        self::assertSame('error', $block->getBrowserConfig()['initialState']);
    }

    /** @dataProvider returnPaths */
    public function testReturnPathRejectsExternalTargetsAndGateLoops(string $path, string $expected): void
    {
        $block = $this->block(
            $this->createMock(Access::class), $this->createMock(PageConfig::class),
            false, 'cms_index_index', $path
        );
        self::assertSame($expected, $block->getBrowserConfig()['returnUrl']);
    }

    public static function returnPaths(): array
    {
        return [
            ['/products?sort=name', '/products?sort=name'],
            ['//external.test/', 'https://store.test/'],
            ['/\\external.test/', 'https://store.test/'],
            ['/hideguard/Gate/Index', 'https://store.test/'],
            ["/bad\npath", 'https://store.test/'],
        ];
    }
}
