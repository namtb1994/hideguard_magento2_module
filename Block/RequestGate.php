<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Block;

use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class RequestGate extends Template
{
    /**
     * @param Context $context
     * @param Config $config
     * @param PageRules $rules
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly PageRules $rules,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Only framework asset roots and our own verification endpoints bypass the queue.
     *
     * @return array
     */
    public function getRequestConfig(): array
    {
        $store = $this->_storeManager->getStore();
        $storeId = (int)$store->getId();
        if (!$this->config->settings($storeId)['enabled']
            || $this->rules->isCheckoutSuccess((string)$this->getRequest()->getPathInfo())
            || !$this->rules->protects((string)$this->getRequest()->getPathInfo(), $storeId)
        ) {
            return [];
        }

        return [
            'assetUrls' => [
                // Magento_Theme appends version<deployment>/ to the static base URL.
                // Match the asset root so unsigned and previously signed asset URLs also load.
                preg_replace('#/version[^/]+/$#', '/', $store->getBaseUrl(UrlInterface::URL_TYPE_STATIC, true)),
                $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true),
            ],
            'verificationUrls' => [
                $this->getUrl('hideguard/access/check', ['_secure' => true]),
                $this->getUrl('hideguard/access/verdict', ['_secure' => true]),
                $this->getUrl('hideguard/access/fail', ['_secure' => true]),
            ],
        ];
    }
}
