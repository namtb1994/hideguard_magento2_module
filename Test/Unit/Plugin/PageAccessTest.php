<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\PageRules;
use Hachitechs\Hideguard\Plugin\PageAccess;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\App\Response\Http;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PageAccessTest extends TestCase
{
    private function plugin(Http $response, string $status = 'verification_required', bool $failure = false, bool $protected = true): PageAccess
    {
        $rules = $this->createMock(PageRules::class);
        $rules->method('protects')->willReturn($protected);
        $access = $this->createMock(Access::class);
        if ($failure) {
            foreach (['status', 'pageStatus'] as $method) {
                $access->method($method)->willThrowException(new \RuntimeException('Database offline'));
            }
        } else {
            foreach (['status', 'pageStatus'] as $method) {
                $access->method($method)->willReturn(['status' => $status, 'blocked' => $status === 'blocked', 'blockedUntil' => (time() + 60) * 1000]);
            }
        }
        $stores = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores->method('getStore')->willReturn($store);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('hideguard/gate/index', [
            '_secure' => true, '_query' => ['return' => base64_encode('/private-page?x=1')] + (in_array($status, ['error', 'verification_failed'], true) ? ['failed' => '1'] : []),
        ])->willReturn('https://store.test/hideguard/gate/index');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($failure ? self::once() : self::never())->method('error');
        return new PageAccess($rules, $access, $stores, $response,
            new \Hachitechs\Hideguard\Model\DeniedResponse($response, $url), $logger,
            $this->createStub(\Magento\Checkout\Model\Session\SuccessValidator::class));
    }

    private function request(string $method = 'GET', bool $ajax = false): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getPathInfo')->willReturn('/private-page');
        $request->method('getRequestUri')->willReturn('/private-page?x=1');
        $request->method('isGet')->willReturn($method === 'GET');
        $request->method('isXmlHttpRequest')->willReturn($ajax);
        return $request;
    }

    /** @dataProvider deniedStates */
    public function testDeniedGetNeverCallsRouterOrCache(string $status, bool $failure): void
    {
        $response = $this->createMock(Http::class);
        $response->method('setHeader')->willReturnSelf();
        $response->expects(self::once())->method('setRedirect')->with('https://store.test/hideguard/gate/index', 302)->willReturnSelf();
        $response->expects(self::once())->method('setBody')->with('')->willReturnSelf();
        $called = false;
        $result = $this->plugin($response, $status, $failure)->aroundDispatch(
            $this->createMock(FrontControllerInterface::class),
            function () use (&$called) { $called = true; return 'cached protected HTML'; }, $this->request()
        );
        self::assertSame($response, $result);
        self::assertFalse($called);
    }

    public static function deniedStates(): array
    {
        return [['error', true], ['unknown', false], ['verification_failed', false]];
    }

    public function testPendingNavigationRendersPageWithoutGrantingActions(): void
    {
        $response = $this->createMock(Http::class);
        $response->expects(self::once())->method('setHeader')->with('Cache-Control', 'private, no-store, max-age=0', true)->willReturnSelf();
        self::assertSame('page beneath transparent lock', $this->plugin($response)->aroundDispatch(
            $this->createMock(FrontControllerInterface::class), fn() => 'page beneath transparent lock', $this->request()
        ));
    }

    public function testBlockedNavigationRedirectsToGateWithoutDispatchingOriginalAction(): void
    {
        $response = $this->createMock(Http::class);
        $response->method('setHeader')->willReturnSelf();
        $response->expects(self::once())->method('setRedirect')
            ->with('https://store.test/hideguard/gate/index', 302)->willReturnSelf();
        $response->expects(self::once())->method('setBody')->with('')->willReturnSelf();
        self::assertSame($response, $this->plugin($response, 'blocked')->aroundDispatch(
            $this->createMock(FrontControllerInterface::class),
            function () { self::fail('Blocked navigation must never reach the original action or cache.'); }, $this->request()
        ));
    }

    /** @dataProvider deniedActions */
    public function testDeniedActionIsRejectedWithoutDispatch(string $status, string $method, bool $ajax, int $code): void
    {
        $response = $this->createMock(Http::class);
        $response->method('setHeader')->willReturnSelf();
        $response->expects(self::once())->method('setHttpResponseCode')->with($code)->willReturnSelf();
        $response->method('setBody')->willReturnSelf();
        $this->plugin($response, $status)->aroundDispatch($this->createMock(FrontControllerInterface::class),
            function () { self::fail('Denied request must not reach its action.'); }, $this->request($method, $ajax));
    }

    public static function deniedActions(): array
    {
        return [
            ['verification_required', 'POST', false, 403], ['verification_required', 'GET', true, 403],
            ['verification_required', 'HEAD', false, 403], ['verification_failed', 'POST', false, 403], ['blocked', 'POST', false, 401],
            ['blocked', 'GET', true, 401], ['blocked', 'HEAD', false, 401],
        ];
    }

    public function testAllowedAccessReachesRouter(): void
    {
        $response = $this->createMock(Http::class);
        self::assertSame('page HTML', $this->plugin($response, 'allowed')->aroundDispatch(
            $this->createMock(FrontControllerInterface::class), fn() => 'page HTML', $this->request()
        ));
    }

    public function testUnprotectedPageDoesNotNeedAnAccessGrant(): void
    {
        $response = $this->createMock(Http::class);
        self::assertSame('public page', $this->plugin($response, 'blocked', false, false)->aroundDispatch(
            $this->createMock(FrontControllerInterface::class), fn() => 'public page', $this->request()
        ));
    }

    public function testAuthorizedActionErrorsAreNotConvertedToAccessDenials(): void
    {
        $this->expectExceptionMessage('Action failed');
        $this->plugin($this->createMock(Http::class), 'allowed')->aroundDispatch(
            $this->createMock(FrontControllerInterface::class),
            function () { throw new \RuntimeException('Action failed'); }, $this->request()
        );
    }
}
