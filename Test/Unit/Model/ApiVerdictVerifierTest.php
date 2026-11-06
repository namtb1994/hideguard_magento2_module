<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\ApiVerdictVerifier;
use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Verification\PrivateVerdictChecker;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class ApiVerdictVerifierTest extends TestCase
{
    private function verify(array $payload): \Hachitechs\HideguardPhp\Verification\VerifiedVerdict
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@1700000000'));
        $storage = $this->createMock(StateRepository::class);
        $storage->method('session')->with('1', 'server-browser')
            ->willReturn(new BrowserState('server-browser', visitorId: 'visitor-a'));
        return (new ApiVerdictVerifier(new PrivateVerdictChecker($clock), $storage))->verify($payload,
            new AccessContext('1', 'server-browser'), new ProtectionPolicy());
    }

    public function testAuthenticatedApiSchemaUsesReceiptTimeAndCurrentVerdictGroups(): void
    {
        $verdict = $this->verify(['verdict' => 'block', 'visitorId' => 'visitor-a', 'score' => 100,
            'source' => 'visit', 'reasons' => [['code' => 'browserTampering', 'detail' => null]]]);
        self::assertSame('block', $verdict->verdict);
        self::assertSame('visitor-a', $verdict->visitorId);
        self::assertTrue($verdict->verified);
        self::assertSame(1700000000, $verdict->issuedAt);
    }

    /** @dataProvider malformed */
    public function testMalformedResponseCannotGrantOrCreateABlock(array $replace): void
    {
        $this->expectException(GuardException::class);
        $this->verify(array_replace(['verdict' => 'allow', 'visitorId' => 'visitor-a', 'score' => 0, 'reasons' => []], $replace));
    }

    public static function malformed(): array
    {
        return [[['verdict' => 'unknown']], [['verdict' => ['allow']]], [['visitorId' => '']],
            [['visitorId' => "bad\nvisitor"]], [['score' => '0']], [['score' => -1]], [['reasons' => null]],
            [['reasons' => ['key' => []]]], [['visitorId' => 'other-visitor']],
            [['reasons' => [null]]], [['reasons' => [['code' => '']]]],
            [['reasons' => array_fill(0, 65, ['code' => 'reason'])]]];
    }
}
