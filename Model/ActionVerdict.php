<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\GuardContract;
use Hachitechs\HideguardPhp\VerdictClientFactory;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/** Refresh action verdicts through authenticated HideGuard requests and the shared Guard protocol. */
class ActionVerdict implements ResetAfterRequestInterface
{
    /** @var array<string,array<string,array<string,mixed>>> Results live only within one business invocation. */
    private array $operations = [];

    /** @var array<string,mixed>|null */
    private ?array $denial = null;

    /**
     * @param Config $config
     * @param GuardContract $guard
     * @param StateRepository $storage
     * @param BrowserIdentity $identity
     * @param VerdictClientFactory $clients
     * @param DebugTransportFactory $transports
     * @param State $appState
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly GuardContract $guard,
        private readonly StateRepository $storage,
        private readonly BrowserIdentity $identity,
        private readonly VerdictClientFactory $clients,
        private readonly DebugTransportFactory $transports,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check a storefront action with a server-owned browser identity.
     *
     * @param int $storeId
     * @param string $action
     * @return array<string,mixed>
     */
    public function checkBrowser(int $storeId, string $action = 'browser'): array
    {
        try {
            return $this->check(new AccessContext(
                (string)$storeId,
                $this->identity->hash(),
                $this->identity->actorId()
            ), $this->browserOperation($storeId, $action));
        } catch (\Throwable $error) {
            $this->logger->warning('HideGuard action identity is unavailable.', ['exception' => $error]);
            return $this->denial = ['status' => 'error'];
        }
    }

    /**
     * Check before public quote mutations; API callers must already own a bound storefront grant.
     *
     * @param Quote $quote
     * @param string $action
     * @return void
     * @throws LocalizedException
     */
    public function assertQuote(Quote $quote, string $action = 'order'): void
    {
        $storeId = (int)$quote->getStoreId();
        if (!$this->config->settings($storeId)['enabled']) {
            return;
        }
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            return;
        }
        if (!in_array($area, ['frontend', 'webapi_rest', 'webapi_soap', 'graphql'], true)) {
            return;
        }
        try {
            $resource = $quote->getId() ? new ResourceRef('quote', (string)$quote->getId(), $quote->getCustomerId()
                ? (string)$quote->getCustomerId() : null) : null;
            if ($area === 'frontend') {
                $context = new AccessContext((string)$storeId, $this->identity->hash(), $this->identity->actorId());
            } else {
                $state = $resource === null ? null : $this->storage->resource((string)$storeId, $resource);
                if ($resource === null || $state === null || $state->actorId !== $resource->actorId) {
                    $this->denial = ['status' => 'verification_required'];
                    throw new LocalizedException(__('Access verification is required.'));
                }
                $context = new AccessContext((string)$storeId, $state->browserId, $resource->actorId);
            }
            $result = $this->check($context, $this->quoteOperation($quote, $action));
            if ($result['status'] !== 'allowed') {
                throw new LocalizedException(__('Access verification is required.'));
            }
            if ($resource !== null) {
                $policy = $this->config->policy($storeId);
                $this->guard->bindGrant($context, $policy, $resource);
            }
        } catch (\Throwable $error) {
            if ($this->denial === null) {
                $this->denial = ['status' => 'error'];
                $this->logger->warning('HideGuard quote verification failed.', ['exception' => $error]);
            }
            throw new LocalizedException(
                __('Access verification is required.'),
                $error instanceof \Exception ? $error : null
            );
        }
    }

    /**
     * Guard one auth invocation, sharing its verdict only with nested calls for that same action.
     *
     * @param int $storeId
     * @param string $action
     * @param callable $work
     * @return mixed
     */
    public function runBrowser(int $storeId, string $action, callable $work): mixed
    {
        if (!$this->isPublicRequest() || !$this->config->settings($storeId)['enabled']) {
            return $work();
        }
        return $this->during($this->browserOperation($storeId, $action), function () use ($storeId, $action, $work) {
            if ($this->checkBrowser($storeId, $action)['status'] !== 'allowed') {
                throw new LocalizedException(__('Access verification is required.'));
            }
            return $work();
        });
    }

    /**
     * Guard one cart/order invocation; nested quote events may reuse only its current permission.
     *
     * @param Quote $quote
     * @param string $action
     * @param callable $work
     * @return mixed
     */
    public function runQuote(Quote $quote, string $action, callable $work): mixed
    {
        return $this->during($this->quoteOperation($quote, $action), function () use ($quote, $action, $work) {
            $this->assertQuote($quote, $action);
            return $work();
        });
    }

    /**
     * Limit duplicate suppression to the lifetime of a single native service invocation.
     *
     * @param string $operation
     * @param callable $work
     * @return mixed
     */
    private function during(string $operation, callable $work): mixed
    {
        if (array_key_exists($operation, $this->operations)) {
            return $work();
        }
        $this->operations[$operation] = [];
        try {
            return $work();
        } finally {
            unset($this->operations[$operation]);
        }
    }

    /**
     * Identify one browser action within its store scope.
     *
     * @param int $storeId
     * @param string $action
     * @return string
     */
    private function browserOperation(int $storeId, string $action): string
    {
        return $storeId . ':browser:' . $action;
    }

    /**
     * Identify a quote mutation without trusting an ID from the HTTP request.
     *
     * @param Quote $quote
     * @param string $action
     * @return string
     */
    private function quoteOperation(Quote $quote, string $action): string
    {
        return $quote->getStoreId() . ':quote:' . ($quote->getId() ?: 'new-' . spl_object_id($quote)) . ':' . $action;
    }

    /**
     * Exclude admin and background operations from storefront protection.
     *
     * @return bool
     */
    private function isPublicRequest(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
            return in_array($area, ['frontend', 'webapi_rest', 'webapi_soap', 'graphql'], true);
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * Preserve denials when a controller or service catches a LocalizedException.
     *
     * @return array<string,mixed>|null
     */
    public function denial(): ?array
    {
        return $this->denial;
    }

    /**
     * @inheritdoc
     */
    public function _resetState(): void
    {
        $this->operations = [];
        $this->denial = null;
    }

    /**
     * Fetch outside the storage lock and never accept a visitor ID from request parameters.
     *
     * @param AccessContext $context
     * @param string $operation
     * @return array<string,mixed>
     */
    private function check(AccessContext $context, string $operation): array
    {
        $key = $context->browserId . ':' . $context->actorId;
        $policy = null;
        $attempt = null;
        try {
            $policy = $this->config->policy((int)$context->scopeId);
            if (!$policy->enabled) {
                return ['status' => 'disabled'];
            }
            $key .= ':' . $policy->fingerprint();
            if (isset($this->operations[$operation][$key])) {
                $cached = $this->operations[$operation][$key];
                $current = $this->guard->inspect($context, $policy)->toBrowserArray();
                if ($cached['status'] === 'allowed' && $current['status'] === 'allowed'
                    && ($cached['verificationId'] ?? null) === ($current['verificationId'] ?? null)
                ) {
                    return $cached;
                }
                return $this->denial = $current['status'] === 'allowed' ? ['status' => 'error'] : $current;
            }
            $attempt = $this->guard->preflight($context, $policy);
            $result = $attempt->toBrowserArray();
            if (in_array($result['status'], ['allowed', 'verification_required'], true)) {
                $state = $this->storage->session($context->scopeId, $context->browserId);
                if ($state !== null && $state->visitorId !== null && $attempt->verificationId !== null) {
                    $client = $this->clients->create([
                        'baseUrl' => $policy->endpointUrl,
                        'apiKey' => $this->config->apiKey((int)$context->scopeId),
                        'transport' => $this->transports->create(['storeId' => (int)$context->scopeId]),
                    ]);
                    $payload = $client->getVerdict($state->visitorId);
                    // A response for another visitor must never replace this browser's identity.
                    if (($payload['visitorId'] ?? null) !== $state->visitorId) {
                        $payload = [];
                    }
                    $decision = $this->guard->report($context, $policy, $payload, $attempt->verificationId);
                    $result = $decision->toBrowserArray();
                    if ($result['status'] === 'allowed' && $decision->verificationId !== $attempt->verificationId) {
                        $result = ['status' => 'verification_required'];
                    }
                } else {
                    $result = ['status' => 'verification_required'];
                }
            }
        } catch (\Throwable $error) {
            // Do not keep an old pass usable after an unavailable or invalid fresh verdict.
            $result = ['status' => 'error'];
            if ($policy instanceof ProtectionPolicy && $attempt !== null && $attempt->verificationId !== null) {
                try {
                    $current = $this->guard->report($context, $policy, [], $attempt->verificationId)->toBrowserArray();
                    if ($current['status'] === 'blocked') {
                        $result = $current;
                    }
                } catch (\Throwable) {
                    // The existing Guard has revoked the grant for this invalid report.
                    $result = ['status' => 'error'];
                }
            }
            $this->logger->warning('HideGuard action verification failed.', ['exception' => $error]);
        }
        if ($result['status'] !== 'allowed') {
            $this->denial = $result;
        }
        if (array_key_exists($operation, $this->operations)) {
            $this->operations[$operation][$key] = $result;
        }
        return $result;
    }
}
