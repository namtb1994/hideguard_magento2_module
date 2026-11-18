<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Block;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Page\Config as PageConfig;

class Bootstrap extends Template
{
    /**
     * @param Context $context
     * @param Config $config
     * @param PageRules $rules
     * @param Access $access
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly PageRules $rules,
        private readonly Access $access,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _prepareLayout()
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        if ($this->config->settings($storeId)['enabled']
            && ($this->rules->protects((string)$this->getRequest()->getPathInfo(), $storeId)
                && !$this->rules->isCheckoutSuccess((string)$this->getRequest()->getPathInfo())
                || $this->isGatePage())
            && $this->pageConfig->getElementAttribute(PageConfig::ELEMENT_TYPE_BODY, 'inert') === null
        ) {
            $this->pageConfig->setElementAttribute(PageConfig::ELEMENT_TYPE_BODY, 'inert', '');
            $this->pageConfig->setElementAttribute(PageConfig::ELEMENT_TYPE_BODY, 'data-hachitech-hideguard-inert', '');
        }
        return parent::_prepareLayout();
    }

    /**
     * Expose the active public SDK key and page state to the AMD initializer.
     *
     * @return array
     */
    public function getBrowserConfig(): array
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        $settings = $this->config->settings($storeId);
        $successPage = $this->rules->isCheckoutSuccess((string)$this->getRequest()->getPathInfo());
        $page = $this->isGatePage() ? 'gate' : ($successPage ? 'checkout_success' : '');
        // PageAccess verifies success before its controller clears the checkout session.
        $pageProtected = !$successPage && $this->rules->protects((string)$this->getRequest()->getPathInfo(), $storeId);
        // Unprotected pages still listen for action denials; initialization does not start the SDK there.
        $enabled = $settings['enabled'];
        $initialState = 'pending';
        $state = [];
        if ($enabled && ($pageProtected || $page === 'gate')) {
            try {
                $state = $this->access->pageStatus($storeId);
                $initialState = match ($state['status']) {
                    'blocked' => 'blocked',
                    'verification_failed' => 'error',
                    default => $page === 'gate' && $this->getRequest()->getParam('failed') === '1'
                        && $state['status'] !== 'allowed' ? 'error' : 'pending',
                };
            } catch (\Throwable $error) {
                $this->_logger->error('HideGuard initial access state failed.', ['exception' => $error]);
                $initialState = 'error';
            }
        }
        $returnUrl = $page === 'gate' ? $this->returnPath() : (string)$this->getRequest()->getRequestUri();
        $gateQuery = ['return' => base64_encode($returnUrl)];
        return [
            'enabled' => $enabled, 'points' => $settings['points'], 'page' => $page,
            'pageProtected' => $pageProtected, 'initialState' => $initialState,
            'verificationId' => $state['verificationId'] ?? null,
            'endpointUrl' => ProtectionPolicy::DEFAULT_ENDPOINT_URL,
            'publicKey' => $settings['keys']['publicKey'],
            'accessUrl' => $this->getUrl('hideguard/access/check', ['_secure' => true]),
            'verdictUrl' => $this->getUrl('hideguard/access/verdict', ['_secure' => true]),
            'failUrl' => $this->getUrl('hideguard/access/fail', ['_secure' => true]),
            'gateUrl' => $this->getUrl('hideguard/gate/index', ['_secure' => true, '_query' => $gateQuery]),
            'deniedUrl' => $this->getUrl('hideguard/gate/index', [
                '_secure' => true, '_query' => $gateQuery + ['failed' => '1'],
            ]),
            'returnUrl' => $this->returnPath(),
        ];
    }

    /**
     * Recognize the standalone gate; PageRules owns all storefront page selection.
     *
     * @return bool
     */
    private function isGatePage(): bool
    {
        return strtolower((string)$this->getRequest()->getFullActionName()) === 'hideguard_gate_index';
    }

    /**
     * Accept only safe internal paths and prevent a return to the verification gate.
     *
     * @return string
     */
    private function returnPath(): string
    {
        $path = base64_decode((string)$this->getRequest()->getParam('return', ''), true);
        // Only root-relative paths; reject external, backslash and control-character redirects.
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\\\\\x00-\x20]/', $path)
            || stripos($path, 'hideguard/gate') !== false
        ) {
            return $this->_storeManager->getStore()->getBaseUrl();
        }
        return $path;
    }
}
