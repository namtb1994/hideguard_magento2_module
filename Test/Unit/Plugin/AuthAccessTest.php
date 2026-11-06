<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Plugin\AuthAccess;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AuthAccessTest extends TestCase
{
    /** @dataProvider services */
    public function testNativeAuthCannotRunWhenHideguardDenies(string $method, string $action, bool $denied): void
    {
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->expects(self::once())->method('runBrowser')->with(2, $action, self::isType('callable'))
            ->willReturnCallback(function ($store, $name, $work) use ($denied) {
                if ($denied) { throw new LocalizedException(__('Access verification is required.')); }
                return $work();
            });
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(2);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $plugin = new AuthAccess($verdict, $stores);
        $called = false;
        $proceed = function (...$args) use (&$called) { $called = true; return $args; };
        $arguments = match ($method) {
            'aroundAuthenticate' => [$this->createMock(AccountManagementInterface::class), $proceed, 'email', 'password'],
            'aroundCreateCustomerAccessToken' => [$this->createMock(CustomerTokenServiceInterface::class), $proceed, 'email', 'password'],
            default => [$this->createMock(AccountManagementInterface::class), $proceed, $this->createMock(CustomerInterface::class), 'password-or-hash', 'return'],
        };
        try {
            self::assertSame(array_slice($arguments, 2), $plugin->$method(...$arguments));
            self::assertFalse($denied);
        } catch (LocalizedException) {
            self::assertTrue($denied);
        }
        self::assertSame(!$denied, $called);
    }

    public static function services(): array
    {
        $cases = [];
        foreach (['aroundAuthenticate' => 'login', 'aroundCreateCustomerAccessToken' => 'login',
            'aroundCreateAccount' => 'register', 'aroundCreateAccountWithPasswordHash' => 'register'] as $method => $action) {
            $cases[] = [$method, $action, false];
            $cases[] = [$method, $action, true];
        }
        return $cases;
    }
}
