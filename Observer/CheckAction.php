<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Observer;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

class CheckAction implements ObserverInterface
{
    /** Native mutations are enforced at their public service, before its side effects. */
    public const SERVICE_EVENTS = [
        'controller_action_predispatch_checkout_cart_add' => \Magento\Checkout\Controller\Cart\Add::class,
        'controller_action_predispatch_customer_account_loginpost'
            => \Magento\Customer\Controller\Account\LoginPost::class,
        'controller_action_predispatch_customer_ajax_login' => \Magento\Customer\Controller\Ajax\Login::class,
        'controller_action_predispatch_customer_account_createpost'
            => \Magento\Customer\Controller\Account\CreatePost::class,
    ];

    /** Predispatch events run after native request/CSRF validation and before business actions. */
    public const EVENTS = [
        'controller_action_predispatch_checkout_index_index' => 'checkout',
        'controller_action_predispatch_checkout_onepage_index' => 'checkout',
        'controller_action_predispatch_onestepcheckout_index_index' => 'checkout',
        'controller_action_predispatch_checkout_onepage_saveorder' => 'order',
        'controller_action_predispatch_checkout_cart_add' => 'cart',
        'controller_action_predispatch_customer_account_loginpost' => 'login',
        'controller_action_predispatch_customer_ajax_login' => 'login',
        'controller_action_predispatch_customer_account_createpost' => 'register',
        'controller_action_predispatch_sociallogin_popup_create' => 'register',
        'controller_action_predispatch_sociallogin_social_login' => 'login',
        'controller_action_predispatch_sociallogin_social_callback' => 'login',
        'controller_action_predispatch_sociallogin_social_email' => 'register',
        'controller_action_predispatch_contact_index_post' => 'form',
        'sales_model_service_quote_submit_before' => 'order',
    ];

    /**
     * @param ActionVerdict $verdict
     * @param Config $config
     * @param StoreManagerInterface $stores
     * @param ActionFlag $actionFlag
     * @param PageRules $rules
     */
    public function __construct(
        private readonly ActionVerdict $verdict,
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly ActionFlag $actionFlag,
        private readonly PageRules $rules
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent()->getName();
        $request = null;
        if ($event === 'controller_action_predispatch') {
            /** @var \Magento\Framework\App\Request\Http $request */
            $request = $observer->getEvent()->getData('request');
            $event .= '_' . strtolower((string)$request->getFullActionName());
        }
        // Exclude covered routes before the generic POST fallback as well as the named mapping.
        if (isset(self::SERVICE_EVENTS[$event])) {
            $controller = $observer->getEvent()->getData('controller_action');
            $class = $controller === null ? null : get_class($controller);
            if ($controller instanceof \Magento\Framework\Interception\InterceptorInterface) {
                $class = get_parent_class($controller);
            }
            // Unknown controller preferences retain predispatch protection even on a native action name.
            if ($class === self::SERVICE_EVENTS[$event]) {
                return;
            }
        }
        if ($request !== null && !isset(self::EVENTS[$event]) && $request->isPost()) {
            $storeId = (int)$this->stores->getStore()->getId();
            $action = 'form:' . $request->getFullActionName();
            if (
                $this->rules->protects((string)$request->getPathInfo(), $storeId)
                && $this->verdict->checkBrowser($storeId, $action)['status'] !== 'allowed'
            ) {
                $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, '1');
            }
            return;
        }
        if (!isset(self::EVENTS[$event])) {
            return;
        }
        if ($event === 'sales_model_service_quote_submit_before') {
            $this->verdict->assertQuote($observer->getEvent()->getData('quote'));
            return;
        }
        $storeId = (int)$this->stores->getStore()->getId();
        if (!$this->config->settings($storeId)['enabled']) {
            return;
        }
        if ($this->verdict->checkBrowser($storeId, self::EVENTS[$event])['status'] !== 'allowed') {
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, '1');
        }
    }
}
