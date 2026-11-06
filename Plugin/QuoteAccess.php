<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Magento\Quote\Model\Quote;

/** Enforce one fresh verdict before the native business invocation. */
class QuoteAccess
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
     * @param Quote $subject
     * @param callable $proceed
     * @param mixed $arguments
     * @return mixed
     */
    public function aroundAddProduct(Quote $subject, callable $proceed, ...$arguments)
    {
        return $this->verdict->runQuote($subject, 'cart', fn() => $proceed(...$arguments));
    }
}
