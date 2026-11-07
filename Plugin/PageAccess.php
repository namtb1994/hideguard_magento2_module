<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\Access;
use Hachitechs\Hideguard\Model\PageRules;
use Hachitechs\Hideguard\Model\DeniedResponse;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class PageAccess
{
    /**
     * @param PageRules $rules
     * @param Access $access
     * @param StoreManagerInterface $stores
     * @param Http $response
     * @param DeniedResponse $deniedResponse
     * @param LoggerInterface $logger
     * @param SuccessValidator $successValidator
     */
    public function __construct(
        private readonly PageRules $rules,
        private readonly Access $access,
        private readonly StoreManagerInterface $stores,
        private readonly Http $response,
        private readonly DeniedResponse $deniedResponse,
        private readonly LoggerInterface $logger,
        private readonly SuccessValidator $successValidator
    ) {
    }

    /**
     * Enforce access before routing or full-page cache can deliver protected content.
     *
     * @param FrontControllerInterface $subject
     * @param callable $proceed
     * @param RequestInterface&\Magento\Framework\App\Request\Http $request
     * @return mixed
     */
    public function aroundDispatch(FrontControllerInterface $subject, callable $proceed, RequestInterface $request)
    {
        $storeId = (int)$this->stores->getStore()->getId();
        if (!$this->rules->protects((string)$request->getPathInfo(), $storeId)) {
            return $proceed($request);
        }
        $successPage = $this->rules->isCheckoutSuccess((string)$request->getPathInfo());
        $state = ['status' => 'error'];
        try {
            // Persist the deadline before sending HTML, even if the browser never runs JavaScript.
            // Success must accept the gate's grant without restarting Always verification.
            $state = !$successPage && $request->isGet() && !$request->isXmlHttpRequest()
                ? $this->access->status($storeId) : $this->access->pageStatus($storeId);
        } catch (\Throwable $error) {
            // Storage/session failures must not deliver the protected page or a cached response.
            $this->logger->error('HideGuard page access check failed.', ['exception' => $error]);
        }
        if ($state['status'] === 'allowed'
            || ($successPage && $request->isGet() && !$request->isXmlHttpRequest()
                && $state['status'] === 'verification_required' && $this->successValidator->isValid())
        ) {
            // A successful checkout already passed order validation. Native success consumes this session once.
            // A real block or verification error must still reach the denial response.
            return $proceed($request);
        }
        if (!$successPage && $state['status'] === 'verification_required'
            && $request->isGet() && !$request->isXmlHttpRequest()
        ) {
            // Render the page beneath the interaction lock while verification is pending.
            $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
            return $proceed($request);
        }
        return $this->deniedResponse->create($request, $state);
    }
}
