<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Observer;

use Hachitechs\Hideguard\Model\Access;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ConsumeGrant implements ObserverInterface
{
    /**
     * @param Access $access
     */
    public function __construct(private readonly Access $access)
    {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        $this->access->consumeQuote($quote);
    }
}
