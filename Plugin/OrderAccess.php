<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote;

/** Enforce one fresh verdict before the native business invocation. */
class OrderAccess
{
    /**
     * @param ActionVerdict $verdict
     */
    public function __construct(private readonly ActionVerdict $verdict)
    {
    }

    /**
     * Keep native mutation and nested events within the same verification operation.
     *
     * @param QuoteManagement $subject
     * @param callable $proceed
     * @param Quote $quote
     * @param array<string,mixed> $orderData
     * @return mixed
     */
    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, array $orderData = [])
    {
        return $this->verdict->runQuote($quote, 'order', fn() => $proceed($quote, $orderData));
    }
}
