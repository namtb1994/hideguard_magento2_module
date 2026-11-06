<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Source;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Framework\Data\OptionSourceInterface;

class Duration implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $labels = [900 => __('15 minutes'), 3600 => __('1 hour'), 21600 => __('6 hours'), 86400 => __('24 hours')];
        return array_map(
            static fn(int $value): array => ['value' => $value, 'label' => $labels[$value] ?? __('%1 seconds', $value)],
            ProtectionPolicy::BLOCK_DURATIONS
        );
    }
}
