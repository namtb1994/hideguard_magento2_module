<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Source;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    /**
     * @inheritdoc
     * @return list<array{value:string,label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $labels = [
            'development' => __('Testing'),
            'production' => __('Production'),
        ];
        return array_map(
            static fn(string $value): array => ['value' => $value, 'label' => $labels[$value]],
            ProtectionPolicy::ENVIRONMENTS
        );
    }
}
