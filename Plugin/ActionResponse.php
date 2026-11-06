<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Model\DeniedResponse;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

class ActionResponse
{
    /**
     * @param ActionVerdict $verdict
     * @param DeniedResponse $deniedResponse
     * @param State $appState
     */
    public function __construct(
        private readonly ActionVerdict $verdict,
        private readonly DeniedResponse $deniedResponse,
        private readonly State $appState
    ) {
    }

    /**
     * Preserve denial even when native cart/payment/API controllers catch the rejection.
     *
     * @param FrontControllerInterface $subject
     * @param mixed $result
     * @param RequestInterface&\Magento\Framework\App\Request\Http $request
     * @return mixed
     */
    public function afterDispatch(FrontControllerInterface $subject, $result, RequestInterface $request)
    {
        $state = $this->verdict->denial();
        return $state === null ? $result : $this->deniedResponse->create(
            $request,
            $state,
            $this->appState->getAreaCode() === 'frontend'
        );
    }
}
