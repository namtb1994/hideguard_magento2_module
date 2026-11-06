<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/** Allow SDK loading and requests only to the active store's configured HideGuard origin. */
class CspCollector implements PolicyCollectorInterface
{
    /**
     * @param Config $config
     * @param State $appState
     */
    public function __construct(private readonly Config $config, private readonly State $appState)
    {
    }

    /**
     * @inheritdoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
            return $defaultPolicies;
        }
        $policy = $this->config->policy();
        if ($policy->enabled) {
            foreach (['script-src', 'connect-src'] as $directive) {
                $defaultPolicies[] = new FetchPolicy($directive, false, [$policy->endpointUrl]);
            }
        }
        return $defaultPolicies;
    }
}
