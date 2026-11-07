<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Console;

use Hachitechs\Hideguard\Console\Command\ReconcileSessions;
use Hachitechs\Hideguard\Model\{StateRepository, StoreLock};
use Magento\Store\Model\{Store, StoreManagerInterface};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileSessionsTest extends TestCase
{
    public function testAllStoresSkipsAdminScopeAndRepairsUnderFence(): void
    {
        $stores = $this->createMock(StoreManagerInterface::class);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $stores->expects(self::once())->method('getStores')->with(false)->willReturn([$store]);
        $storage = $this->createMock(StateRepository::class);
        $storage->expects(self::once())->method('reconcileSessionCount')->with('1')->willReturn(42);
        $atomic = $this->createMock(StoreLock::class);
        $atomic->expects(self::once())->method('run')->with('1', self::isType('callable'))
            ->willReturnCallback(fn($id, $work) => $work());
        $tester = new CommandTester(new ReconcileSessions($storage, $atomic, $stores));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Store 1: 42 sessions', $tester->getDisplay());
    }

    public function testAdminScopeIsRejectedBeforeAccessingStorage(): void
    {
        $storage = $this->createMock(StateRepository::class);
        $storage->expects(self::never())->method('reconcileSessionCount');
        $tester = new CommandTester(new ReconcileSessions(
            $storage,
            $this->createStub(StoreLock::class),
            $this->createStub(StoreManagerInterface::class)
        ));
        self::assertSame(1, $tester->execute(['--store' => '0']));
    }
}
