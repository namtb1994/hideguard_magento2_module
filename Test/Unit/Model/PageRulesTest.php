<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;

class PageRulesTest extends TestCase
{
    private function rules(array $points, array $paths = [], ?string $target = null, bool $enabled = true): PageRules
    {
        $config = $this->createMock(Config::class);
        $config->method('settings')->willReturn(['enabled' => $enabled, 'points' => $points, 'pageRules' => $paths]);
        $rewrites = $this->createMock(UrlFinderInterface::class);
        $rewrite = $target === null ? null : (new UrlRewrite([], new \Magento\Framework\Serialize\Serializer\Json()))->setTargetPath($target);
        $rewrites->method('findOneByData')->willReturn($rewrite);
        return new PageRules($config, $rewrites, new \Hachitechs\HideguardPhp\Rules\PathMatcher());
    }

    public function testAllPagesIncludesHomepageCmsAndCustomRoutes(): void
    {
        foreach (['/', '/about-us', '/custom/module/action', '/customer/account/login', '/checkout/cart/add'] as $path) {
            self::assertTrue($this->rules(['all_pages'])->protects($path, 1));
        }
    }

    public function testOnlyExactVerificationRoutesAreExempt(): void
    {
        $rules = $this->rules(['all_pages']);
        foreach (['/hideguard/gate/index', '/hideguard/access/check', '/hideguard/access/verdict'] as $path) {
            self::assertFalse($rules->protects($path, 1));
        }
        self::assertTrue($rules->protects('/hideguard/arbitrary/page', 1));
    }

    public function testProductSeoRewritesAreProtected(): void
    {
        self::assertTrue($this->rules(['product'], [], 'catalog/product/view/id/123')->protects('/diamond-ring.html', 1));
        self::assertFalse($this->rules(['product'], [], 'cms/page/view/page_id/4')->protects('/about-us', 1));
    }

    public function testGlobRulesIgnoreQueryAndCanProtectAnyRoute(): void
    {
        $rules = $this->rules([], ['customer/*', '/contact', 'category/*']);
        self::assertTrue($rules->protects('/customer/account/login?return=x', 1));
        self::assertTrue($rules->protects('/contact', 1));
        self::assertTrue($rules->protects('/category/rings', 1));
        self::assertFalse($rules->protects('/about-us', 1));
    }

    public function testCompleteOrderAloneDoesNotGateCheckoutOrCartPages(): void
    {
        $rules = $this->rules(['complete_order']);
        self::assertFalse($rules->protects('/checkout', 1));
        self::assertFalse($rules->protects('/checkout/cart', 1));
    }

    public function testActionOnlyOrCustomPathRulesDoNotQuerySeoRewrites(): void
    {
        foreach ([[], ['complete_order']] as $points) {
            $config = $this->createMock(Config::class);
            $config->method('settings')->willReturn([
                'enabled' => true, 'points' => $points, 'pageRules' => ['Campaign/*'],
            ]);
            $rewrites = $this->createMock(UrlFinderInterface::class);
            $rewrites->expects(self::never())->method('findOneByData');
            $rules = new PageRules($config, $rewrites, new \Hachitechs\HideguardPhp\Rules\PathMatcher());
            self::assertFalse($rules->protects('/about-us', 1));
            self::assertTrue($rules->protects('/Campaign/Offer', 1));
        }
    }

    public function testCartAndCheckoutRulesMatchTheirMagentoPaths(): void
    {
        self::assertTrue($this->rules(['cart'])->protects('/checkout/cart', 1));
        self::assertTrue($this->rules(['cart'])->protects('/checkout/cart/add/product/123', 1));
        self::assertTrue($this->rules(['checkout'])->protects('/checkout/index/index', 1));
        self::assertTrue($this->rules(['checkout'])->protects('/onestepcheckout', 1));
        self::assertFalse($this->rules(['checkout'])->protects('/checkout/cart', 1));
    }

    public function testDisabledProtectionDoesNotGatePages(): void
    {
        self::assertFalse($this->rules(['all_pages'], ['*'], null, false)->protects('/about-us', 1));
    }

    public function testMagentoControllerAndActionCaseCannotBypassProductProtection(): void
    {
        self::assertTrue($this->rules(['product'])->protects('/catalog/Product/View/id/123', 1));
        self::assertTrue($this->rules(['cart'])->protects('/checkout/Cart/Index', 1));
        self::assertTrue($this->rules(['checkout'])->protects('/checkout/Index/Index', 1));
    }

    public function testVerificationRouteExemptionsFollowMagentoActionCase(): void
    {
        $rules = $this->rules(['all_pages']);
        self::assertFalse($rules->protects('/hideguard/Access/Verdict', 1));
        self::assertFalse($rules->protects('/hideguard/Gate/Index', 1));
        self::assertFalse($rules->protects('/hideguard/Gate', 1));
        self::assertTrue($rules->protects('/hideguard/Gate/Unknown', 1));
        self::assertTrue($rules->protects('/hideguard/Access/Unknown', 1));
    }

    public function testRouteCaseNormalizationPreservesCustomPathAndRewriteLookupCase(): void
    {
        $custom = $this->rules([], ['Campaign/*']);
        self::assertTrue($custom->protects('/Campaign/Offer', 1));
        self::assertFalse($custom->protects('/campaign/Offer', 1));
        $config = $this->createMock(Config::class);
        $config->method('settings')->willReturn(['enabled' => true, 'points' => ['product'], 'pageRules' => []]);
        $rewrites = $this->createMock(UrlFinderInterface::class);
        $rewrites->expects(self::once())->method('findOneByData')->with([
            'request_path' => 'Diamond-Ring.html', 'store_id' => 1,
        ])->willReturn((new UrlRewrite([], new \Magento\Framework\Serialize\Serializer\Json()))->setTargetPath('catalog/Product/View/id/123'));
        self::assertTrue((new PageRules($config, $rewrites, new \Hachitechs\HideguardPhp\Rules\PathMatcher()))->protects('/Diamond-Ring.html', 1));
    }
    public function testSnapshotTargetAndLiveRulesDecodeTargetsOnlyOnce(): void
    {
        self::assertTrue($this->rules(['product'], [], 'catalog%2Fproduct%2Fview/id/1')->protects('/alias', 1));
        self::assertFalse($this->rules(['product'], [], 'catalog%252Fproduct%252Fview/id/1')->protects('/alias', 1));
        self::assertFalse($this->rules(['product'])->protectsTarget('catalog%252Fproduct%252Fview/id/1', ['product']));
    }
}
