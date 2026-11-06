<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class SignatureContractTest extends TestCase
{
    /** @dataProvider vectors */
    public function testSameContractCasesAsTheNodeVerifier(array $item, string $publicKey): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@' . $item['now']));
        $policy = new ProtectionPolicy(enabled: true, environment: $item['environment'] ?? 'production',
            publicKey: 'fixture', signaturePublicKey: $item['publicKey'] ?? $publicKey, passDuration: 300,
            blockDuration: 900);
        if (!$item['accepted']) {
            $this->expectException(GuardException::class);
        }
        $result = (new Ed25519Verifier($clock))->verify($item['payload'], new AccessContext('1', 'fixture-browser'), $policy);
        self::assertTrue($result->verified);
        self::assertSame($item['payload']['visitorId'], $result->visitorId);
    }

    public static function vectors(): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/Contract/collect-vectors.json'), true, 512,
            JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture['cases'] as $item) {
            $cases[$item['name']] = [$item, $fixture['publicKey']];
        }
        return $cases;
    }
}
