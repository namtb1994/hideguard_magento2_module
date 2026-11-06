<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\VerdictVerifier;
use Hachitechs\HideguardPhp\Verification\PrivateVerdictChecker;
use Hachitechs\HideguardPhp\Verification\VerifiedVerdict;

/** Only for authenticated VerdictClient responses; never used by browser report controllers. */
class ApiVerdictVerifier implements VerdictVerifier
{
    /**
     * @param PrivateVerdictChecker $checker
     * @param StateRepository $storage
     */
    public function __construct(
        private readonly PrivateVerdictChecker $checker,
        private readonly StateRepository $storage
    ) {
    }

    /**
     * Check the private API response against the visitor stored by the server.
     *
     * @param array<string,mixed> $payload
     * @param AccessContext $context
     * @param ProtectionPolicy $policy
     * @return VerifiedVerdict
     */
    public function verify(array $payload, AccessContext $context, ProtectionPolicy $policy): VerifiedVerdict
    {
        $state = $this->storage->session($context->scopeId, $context->browserId);
        return $this->checker->check($payload, $state?->visitorId ?? '');
    }
}
