<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Observer;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Hachitechs\Hideguard\Observer\CheckAction;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CheckActionTest extends TestCase
{
    /** @dataProvider controllerActions */
    public function testRegisteredActionsCannotExecuteWhenBackendVerdictDenies(string $action): void
    {
        $this->execute($action, true, 'blocked', true);
    }

    public static function controllerActions(): array
    {
        return [['checkout_index_index'], ['checkout_onepage_index'], ['onestepcheckout_index_index'],
            ['checkout_onepage_saveOrder'],
            ['sociallogin_popup_create'], ['sociallogin_social_login'], ['sociallogin_social_callback'],
            ['sociallogin_social_email'], ['contact_index_post']];
    }

    public function testDisabledOrUnrelatedActionsNeverCallHideGuard(): void
    {
        $this->execute('customer_account_loginPost', false, 'allowed', false);
        $this->execute('catalog_product_view', true, 'allowed', false);
    }

    public function testBackendAllowLetsTheNativeControllerProceed(): void
    {
        $this->execute('contact_index_post', true, 'allowed', true);
    }

    /** @dataProvider quoteEvents */
    public function testPaymentAndOrderEventsCheckBeforeTheNativeMutation(string $name): void
    {
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $payment = $this->createMock(\Magento\Quote\Model\Quote\Payment::class);
        $payment->method('getQuote')->willReturn($quote);
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->expects(self::once())->method('assertQuote')->with($quote, 'order');
        (new CheckAction($verdict, $this->createMock(Config::class),
            $this->createMock(StoreManagerInterface::class), $this->createMock(ActionFlag::class), $this->createMock(PageRules::class)))->execute(new Observer([
                'event' => new Event(['name' => $name, 'quote' => $quote, 'payment' => $payment]),
            ]));
    }

    public static function quoteEvents(): array
    {
        return [['sales_model_service_quote_submit_before']];
    }

    public function testCustomProtectedPostRequiresAFreshVerdict(): void
    {
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->expects(self::once())->method('checkBrowser')->with(1, 'form:custom_form_save')
            ->willReturn(['status' => 'blocked']);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $rules = $this->createMock(PageRules::class);
        $rules->method('protects')->with('/custom/form/save', 1)->willReturn(true);
        $flag = $this->createMock(ActionFlag::class);
        $flag->expects(self::once())->method('set')->with('', ActionInterface::FLAG_NO_DISPATCH, '1');
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn('custom_form_save');
        $request->method('getPathInfo')->willReturn('/custom/form/save');
        $request->method('isPost')->willReturn(true);
        (new CheckAction($verdict, $this->createMock(Config::class), $stores, $flag, $rules))->execute(new Observer([
            'event' => new Event(['name' => 'controller_action_predispatch', 'request' => $request]),
        ]));
    }

    public function testServiceCoveredPostsSkipBothNamedAndGenericChecks(): void
    {
        foreach (['checkout_cart_add', 'customer_account_loginPost', 'customer_ajax_login',
            'customer_account_createPost'] as $action
        ) {
            $this->execute($action, true, 'blocked', false);
        }
    }

    public function testUnknownControllerOnNativeRouteRetainsPredispatchProtection(): void
    {
        $this->execute('checkout_cart_add', true, 'blocked', true, false);
    }

    private function execute(string $action, bool $enabled, string $status, bool $checked, bool $native = true): void
    {
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->expects($checked ? self::once() : self::never())->method('checkBrowser')->with(1, CheckAction::EVENTS['controller_action_predispatch_' . strtolower($action)] ?? '')
            ->willReturn(['status' => $status]);
        $config = $this->createMock(Config::class);
        $config->method('settings')->willReturn(['enabled' => $enabled]);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $flag = $this->createMock(ActionFlag::class);
        $flag->expects($checked && $status !== 'allowed' ? self::once() : self::never())
            ->method('set')->with('', ActionInterface::FLAG_NO_DISPATCH, '1');
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn($action);
        $request->method('isPost')->willReturn(true);
        $controllerClass = CheckAction::SERVICE_EVENTS['controller_action_predispatch_' . strtolower($action)] ?? null;
        $controller = $native && $controllerClass !== null
            ? (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor() : null;
        (new CheckAction($verdict, $config, $stores, $flag, $this->createMock(PageRules::class)))->execute(new Observer([
            'event' => new Event(['name' => 'controller_action_predispatch', 'request' => $request,
                'controller_action' => $controller]),
        ]));
    }
}
