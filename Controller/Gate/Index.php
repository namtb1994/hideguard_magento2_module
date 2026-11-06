<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Controller\Gate;

use Hachitechs\Hideguard\Model\Access;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Result\Page;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    /**
     * @param PageFactory $pages
     * @param Access $access
     * @param StoreManagerInterface $stores
     * @param LoggerInterface $logger
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly PageFactory $pages,
        private readonly Access $access,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Page
    {
        $page = $this->pages->create()->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        try {
            $state = $this->access->pageStatus((int)$this->stores->getStore()->getId());
            if ($state['status'] === 'blocked') {
                $page->setHttpResponseCode(401)->setHeader(
                    'Retry-After',
                    (string)max(1, (int)ceil(($state['blockedUntil'] - microtime(true) * 1000) / 1000)),
                    true
                );
            } elseif ($state['status'] === 'verification_failed'
                || ($state['status'] !== 'allowed' && $this->request->getParam('failed') === '1')
            ) {
                $page->setHttpResponseCode(403);
            }
        } catch (\Throwable $error) {
            $this->logger->error('HideGuard block page access check failed.', ['exception' => $error]);
            $page->setHttpResponseCode(503);
        }
        return $page;
    }
}
