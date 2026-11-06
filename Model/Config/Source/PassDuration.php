<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Source;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Framework\Data\OptionSourceInterface;

class PassDuration implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $labels = [
            0 => __('Always'), 60 => __('1 minute'), 300 => __('5 minutes'),
            900 => __('15 minutes'), 3600 => __('1 hour'),
        ];
        return array_map(
            static fn(int $value): array => ['value' => $value, 'label' => $labels[$value] ?? __('%1 seconds', $value)],
            ProtectionPolicy::PASS_DURATIONS
        );
    }
}
