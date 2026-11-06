<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Controller\Access;

use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\Hideguard\Model\Access;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Check implements HttpPostActionInterface
{
    /**
     * @param RequestInterface&\Magento\Framework\App\Request\Http $request
     * @param JsonFactory $jsonFactory
     * @param Validator $formKey
     * @param Access $access
     * @param StoreManagerInterface $stores
     * @param Session $checkout
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Validator $formKey,
        protected readonly Access $access,
        private readonly StoreManagerInterface $stores,
        private readonly Session $checkout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create()->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        try {
            if (!$this->formKey->validate($this->request)) {
                return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid form key. Reload the page.']);
            }
            if (strlen((string)$this->request->getContent()) > 65536) {
                return $result->setHttpResponseCode(413)->setData(['error' => 'Request body is too large.']);
            }
            $storeId = (int)$this->stores->getStore()->getId();
            $data = $this->getAccessData($storeId);
            if ($data['status'] === 'allowed' && $this->checkout->getQuoteId()) {
                $quote = $this->checkout->getQuote();
                if ($quote->getId()) {
                    $this->access->bindQuote($quote);
                }
            }
            if ($data['status'] === 'blocked') {
                $result->setHttpResponseCode(401)->setHeader(
                    'Retry-After',
                    (string)max(1, (int)ceil(($data['blockedUntil'] - microtime(true) * 1000) / 1000)),
                    true
                );
            } elseif ($data['status'] === 'verification_failed') {
                $result->setHttpResponseCode(403);
            }
            return $result->setData($data);
        } catch (GuardException $error) {
            if ($error->retryAfterSeconds > 0) {
                $result->setHeader('Retry-After', (string)$error->retryAfterSeconds, true);
            }
            $status = match ($error->reason) {
                FailureReason::InvalidConfiguration, FailureReason::InvalidVerdict => 400,
                FailureReason::InvalidIdentity, FailureReason::Disabled => 409,
                FailureReason::PermissionDenied => 403,
                FailureReason::RateLimited => 429,
                FailureReason::VerificationUnavailable => 501,
                FailureReason::Busy, FailureReason::CapacityReached => 503,
            };
            return $result->setHttpResponseCode($status)
                ->setData(['error' => $error->getMessage()]);
        } catch (\Throwable $error) {
            $this->logger->error('HideGuard access verification failed.', ['exception' => $error]);
            return $result->setHttpResponseCode(503)->setData(['error' => 'Unable to verify access. Please retry.']);
        }
    }

    /**
     * Read the current browser permission without accepting a new verdict.
     *
     * @param int $storeId
     * @return array<string,mixed>
     */
    protected function getAccessData(int $storeId): array
    {
        return $this->access->status($storeId, $this->request->getParam('retry') === '1');
    }
}
