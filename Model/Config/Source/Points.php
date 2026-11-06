<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Points implements OptionSourceInterface
{
    public const VALUES = ['cart', 'product', 'checkout', 'complete_order', 'all_pages'];
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'all_pages', 'label' => __('All storefront pages')],
            ['value' => 'cart', 'label' => __('Cart')],
            ['value' => 'product', 'label' => __('Product')],
            ['value' => 'checkout', 'label' => __('Checkout')],
            ['value' => 'complete_order', 'label' => __('Complete order')],
        ];
    }
}
