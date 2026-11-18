<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Integration;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\VerdictVerifier;
use Hachitechs\HideguardPhp\Verification\VerifiedVerdict;

/** Supplies preverified fixture outcomes for database and proxy tests. */
final class FixtureVerdictVerifier implements VerdictVerifier
{
    public function verify(array $payload, AccessContext $context, ProtectionPolicy $policy): VerifiedVerdict
    {
        return new VerifiedVerdict($payload['verdict'], $payload['visitorId']);
    }
}
