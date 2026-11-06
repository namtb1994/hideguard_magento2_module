<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Observer;

use Hachitechs\Hideguard\Model\Access;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Model\StoreManagerInterface;

class SettingsChanged implements ObserverInterface
{
    /**
     * @param Access $access
     * @param StoreManagerInterface $stores
     * @param TypeListInterface $cacheTypes
     */
    public function __construct(
        private readonly Access $access,
        private readonly StoreManagerInterface $stores,
        private readonly TypeListInterface $cacheTypes
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $storeIds = [];
        foreach ($this->stores->getStores() as $store) {
            if ($event->getStore() && (string)$store->getCode() !== (string)$event->getStore()
                && (string)$store->getId() !== (string)$event->getStore()) {
                continue;
            }
            if (!$event->getStore() && $event->getWebsite()
                && (string)$store->getWebsite()->getCode() !== (string)$event->getWebsite()
                && (string)$store->getWebsiteId() !== (string)$event->getWebsite()) {
                continue;
            }
            $storeIds[] = (int)$store->getId();
        }
        $this->access->revokeStores($storeIds);
        // Cached HTML must carry the current frontend policy and SDK key.
        $this->cacheTypes->cleanType('full_page');
    }
}
