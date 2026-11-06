<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Source;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $labels = [
            'development' => __('Development'),
            'staging' => __('Staging'),
            'production' => __('Production'),
        ];
        return array_map(
            static fn(string $value): array => ['value' => $value, 'label' => $labels[$value] ?? $value],
            ProtectionPolicy::ENVIRONMENTS
        );
    }
}
