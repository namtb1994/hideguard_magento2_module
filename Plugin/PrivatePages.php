<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\PageRules;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

class PrivatePages
{
    /**
     * @param PageRules $rules
     * @param RequestInterface $request
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        private readonly PageRules $rules,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /**
     * Prevent rendered protected pages from being stored in shared caches.
     *
     * @param ResultInterface $subject
     * @param ResultInterface $result
     * @param Http $response
     * @return ResultInterface
     */
    public function afterRenderResult(
        ResultInterface $subject,
        ResultInterface $result,
        Http $response
    ): ResultInterface {
        $this->makePrivate($response);
        return $result;
    }

    /**
     * Apply private headers to direct responses as well as result objects.
     *
     * @param Http $subject
     * @return void
     */
    public function beforeSendResponse(Http $subject): void
    {
        $this->makePrivate($subject);
    }

    /**
     * Mark protected pages and verification endpoints as private and non-cacheable.
     *
     * @param Http $response
     * @return void
     */
    private function makePrivate(Http $response): void
    {
        $path = (string)$this->request->getPathInfo();
        if ($this->rules->isGatePath($path) || $this->rules->protects($path, (int)$this->stores->getStore()->getId())) {
            $response->setNoCacheHeaders();
            $response->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        }
    }
}
