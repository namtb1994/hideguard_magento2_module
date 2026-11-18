<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class StateRepositoryTest extends TestCase
{
    /** @dataProvider revocations */
    public function testRevocationCancelsAttemptsWithoutChangingBlockDeadlines(string $kind): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturn('identity-condition');
        $connection->expects(self::once())->method('update')->with(
            'test_session',
            ['granted_until' => 0, 'quote_id' => null, 'verification_id' => null, 'verification_until' => 0],
            self::callback(fn(array $where) => $where['store_id = ?'] === 1)
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->with('hachitech_hideguard_session')->willReturn('test_session');
        $storage = new StateRepository($resource);
        match ($kind) {
            'scope' => $storage->revokeScope('1'),
            'actor' => $storage->revokeIdentity('1', null, '7'),
            'visitor' => $storage->revokeIdentity('1', 'visitor', null),
            'resource' => $storage->revokeResource('1', new ResourceRef('quote', '8')),
        };
    }

    public static function revocations(): array
    {
        return [['scope'], ['actor'], ['visitor'], ['resource']];
    }
}
