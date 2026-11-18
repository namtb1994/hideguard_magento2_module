<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Block\Bootstrap;
use Hachitechs\Hideguard\Block\RequestGate;
use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\DeniedResponse;
use Hachitechs\Hideguard\Model\PageRules;
use Hachitechs\Hideguard\Plugin\PageAccess;
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\Checkout\Controller\Onepage\Success;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Session\Storage;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CheckoutSuccessTest extends TestCase
{
    private const PATH = '/checkout/onepage/success/';

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('settings')->willReturn([
            'enabled' => true, 'points' => ['all_pages', 'complete_order'], 'pageRules' => [],
            'environment' => 'production', 'passDuration' => 0,
            'keys' => ['production' => ['publicKey' => 'public-test-key']],
        ]);
        return $config;
    }

    private function rules(): PageRules
    {
        return new PageRules($this->config(), $this->createStub(UrlFinderInterface::class), new PathMatcher());
    }

    private function stores(): StoreManagerInterface
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://store.test/');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return $stores;
    }

    private function request(bool $ajax = false, bool $get = true): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getPathInfo')->willReturn(self::PATH);
        $request->method('getRequestUri')->willReturn(self::PATH);
        $request->method('getFullActionName')->willReturn('checkout_onepage_success');
        $request->method('isGet')->willReturn($get);
        $request->method('isPost')->willReturn(!$get);
        $request->method('isXmlHttpRequest')->willReturn($ajax);
        return $request;
    }

    private function plugin(Access $access, Http $response, ?SuccessValidator $validator = null): PageAccess
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())->method('getUrl')->with('antidetectbrowser/gate/index', [
            '_secure' => true, '_query' => ['return' => base64_encode(self::PATH)],
        ])->willReturn('/antidetectbrowser/gate/index/?return=' . base64_encode(self::PATH));
        return new PageAccess($this->rules(), $access, $this->stores(), $response,
            new DeniedResponse($response, $url), $this->createStub(LoggerInterface::class),
            $validator ?? $this->createStub(SuccessValidator::class));
    }

    public function testCompletedCheckoutGoesStraightToNativeSuccessAndCannotReuseItsConsumedSession(): void
    {
        $values = new Storage('checkout', ['last_success_quote_id' => 6, 'last_quote_id' => 6, 'last_order_id' => 6]);
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
            ->onlyMethods(['_getQuoteIdKey', 'getLastRealOrder'])->getMock();
        (new \ReflectionProperty(Session::class, 'storage'))->setValue($session, $values);
        $session->method('_getQuoteIdKey')->willReturn('quote_id_1');
        $session->expects(self::once())->method('getLastRealOrder')
            ->willReturn($this->createStub(\Magento\Sales\Model\Order::class));
        $validator = new SuccessValidator($session);
        $onepage = $this->createStub(\Magento\Checkout\Model\Type\Onepage::class);
        $onepage->method('getCheckout')->willReturn($session);
        $controller = $this->getMockBuilder(Success::class)->disableOriginalConstructor()
            ->onlyMethods(['getOnepage'])->getMock();
        $controller->expects(self::exactly(2))->method('getOnepage')->willReturn($onepage);
        $om = $this->createStub(ObjectManagerInterface::class);
        $om->method('get')->willReturn($validator);
        $page = $this->createStub(Page::class);
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('checkout/cart')->willReturnSelf();
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        foreach (['_objectManager' => $om, 'resultPageFactory' => $pages,
            '_eventManager' => $this->createStub(ManagerInterface::class),
            'resultRedirectFactory' => $redirects] as $name => $value
        ) {
            (new \ReflectionProperty($controller, $name))->setValue($controller, $value);
        }

        $state = 'verification_required';
        $access = $this->createMock(Access::class);
        $access->expects(self::never())->method('status');
        // Consumed order grants stay pending; a valid native success session avoids another SDK check.
        $access->expects(self::exactly(2))->method('pageStatus')->with(1)
            ->willReturnCallback(function () use (&$state) { return ['status' => $state]; });
        $response = $this->createMock(Http::class);
        $response->method('setHeader')->willReturnSelf();
        $response->method('setBody')->willReturnSelf();
        $response->expects(self::once())->method('setRedirect')->with(
            '/antidetectbrowser/gate/index/?return=' . base64_encode(self::PATH), 302
        )->willReturnSelf();
        $plugin = $this->plugin($access, $response, $validator);
        $front = $this->createStub(FrontControllerInterface::class);

        self::assertTrue($validator->isValid());
        self::assertSame($page, $plugin->aroundDispatch($front, fn() => $controller->execute(), $this->request()));
        self::assertFalse($validator->isValid(), 'Native success consumes the session on its first display.');
        self::assertSame($response, $plugin->aroundDispatch($front, fn() => $controller->execute(), $this->request()));
        self::assertSame($redirect, $controller->execute(), 'Magento still rejects a repeated success request.');
    }

    public function testBlockedSuccessNeverReachesItsController(): void
    {
        $access = $this->createStub(Access::class);
        $access->method('pageStatus')->willReturn(['status' => 'blocked', 'blockedUntil' => (time() + 60) * 1000]);
        $response = $this->createMock(Http::class);
        $response->method('setHeader')->willReturnSelf();
        $response->method('setBody')->willReturnSelf();
        $response->expects(self::once())->method('setRedirect')->willReturnSelf();
        $validator = $this->createMock(SuccessValidator::class);
        $validator->expects(self::never())->method('isValid');
        self::assertSame($response, $this->plugin($access, $response, $validator)->aroundDispatch(
            $this->createStub(FrontControllerInterface::class),
            function () { self::fail('Blocked success must not consume checkout state.'); }, $this->request()
        ));
    }

    public function testMissingOrIncompleteSuccessSessionStillRequiresVerification(): void
    {
        foreach ([[], ['last_quote_id' => 6, 'last_order_id' => 6],
            ['last_success_quote_id' => 6, 'last_order_id' => 6],
            ['last_success_quote_id' => 6, 'last_quote_id' => 6]] as $data
        ) {
            $session = (new \ReflectionClass(Session::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(Session::class, 'storage'))->setValue($session, new Storage('checkout', $data));
            $access = $this->createStub(Access::class);
            $access->method('pageStatus')->willReturn(['status' => 'verification_required']);
            $response = $this->createMock(Http::class);
            $response->method('setHeader')->willReturnSelf();
            $response->method('setBody')->willReturnSelf();
            $response->expects(self::once())->method('setRedirect')->willReturnSelf();
            self::assertSame($response, $this->plugin($access, $response, new SuccessValidator($session))->aroundDispatch(
                $this->createStub(FrontControllerInterface::class),
                function () { self::fail('Incomplete checkout session must not bypass verification.'); }, $this->request()
            ));
        }
    }

    public function testSuccessSessionExceptionCannotBypassErrorsOrApplyToAjaxAndPost(): void
    {
        foreach ([['verification_failed', false, true], ['error', false, true], ['unknown', false, true],
            ['verification_required', true, true], ['verification_required', false, false]] as [$status, $ajax, $get]
        ) {
            $state = ['status' => $status];
            $request = $this->request($ajax, $get);
            $response = $this->createStub(Http::class);
            $access = $this->createMock(Access::class);
            $access->expects(self::never())->method('status');
            $access->expects(self::once())->method('pageStatus')->with(1)->willReturn($state);
            $validator = $this->createMock(SuccessValidator::class);
            $validator->expects(self::never())->method('isValid');
            $denied = $this->createMock(DeniedResponse::class);
            $denied->expects(self::once())->method('create')->with($request, $state)->willReturn($response);
            $plugin = new PageAccess($this->rules(), $access, $this->stores(), $response, $denied,
                $this->createStub(LoggerInterface::class), $validator);
            self::assertSame($response, $plugin->aroundDispatch(
                $this->createStub(FrontControllerInterface::class),
                function () { self::fail('Only pending GET navigation with a valid session may bypass the gate.'); }, $request
            ));
        }
    }

    public function testVerifiedSuccessDoesNotInstallAnotherBrowserCheckOrRequestBarrier(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects(self::never())->method('pageStatus');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->expects(self::never())->method('setElementAttribute');
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://store.test/access');
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($this->stores());
        $context->method('getRequest')->willReturn($this->request());
        $context->method('getPageConfig')->willReturn($pageConfig);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getLogger')->willReturn($this->createStub(LoggerInterface::class));
        $block = new Bootstrap($context, $this->config(), $this->rules(), $access);
        (new \ReflectionMethod(Bootstrap::class, '_prepareLayout'))->invoke($block);
        $config = $block->getBrowserConfig();
        self::assertTrue($config['enabled']);
        self::assertFalse($config['pageProtected']);
        self::assertSame('checkout_success', $config['page']);
        self::assertSame([], (new RequestGate($context, $this->config(), $this->rules()))->getRequestConfig());
        self::assertTrue($this->rules()->protects(self::PATH, 1), 'Server page protection is still required.');
    }

    public function testSuccessDetectionMatchesMagentoCaseAndParametersWithoutMatchingOtherActions(): void
    {
        $rules = $this->rules();
        foreach (['/checkout/onepage/success', '/checkout/Onepage/Success/',
            '/checkout/onepage/success/key/value?foo=bar'] as $path
        ) {
            self::assertTrue($rules->isCheckoutSuccess($path));
        }
        foreach (['/checkout/', '/checkout/onepage/successful', '/customer/section/load/'] as $path) {
            self::assertFalse($rules->isCheckoutSuccess($path));
        }
    }
}
