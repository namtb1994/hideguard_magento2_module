<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Controller\Adminhtml\Blocks;

use Hachitechs\Hideguard\Model\Access;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Reset extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hachitechs_Hideguard::reset';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Validator $formKey
     * @param Access $access
     * @param StoreManagerInterface $stores
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Validator $formKey,
        private readonly Access $access,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create()->setHeader('Cache-Control', 'no-store', true);
        if (!$this->formKey->validate($this->getRequest())) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid form key.']);
        }
        try {
            $storeParam = $this->getRequest()->getParam('store');
            $websiteParam = $this->getRequest()->getParam('website');
            $storeId = $storeParam ? (int)$this->stores->getStore($storeParam)->getId() : null;
            $websiteId = $websiteParam ? (int)$this->stores->getWebsite($websiteParam)->getId() : null;
            $cleared = 0;
            foreach ($this->stores->getStores() as $store) {
                if (($storeId !== null && $storeId !== (int)$store->getId())
                    || ($storeId === null && $websiteId !== null && $websiteId !== (int)$store->getWebsiteId())) {
                    continue;
                }
                $cleared += $this->access->resetBlocks((int)$store->getId());
            }
            return $result->setData(['cleared' => $cleared]);
        } catch (\Throwable $error) {
            $this->logger->error('HideGuard block reset failed.', ['exception' => $error]);
            return $result->setHttpResponseCode(503)->setData([
                'error' => 'Unable to reset all selected blocks. Retry reset to finish.',
            ]);
        }
    }
}
