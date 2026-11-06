<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Plugin\QuoteAccess;
use Hachitechs\Hideguard\Plugin\OrderAccess;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\TestCase;

class QuoteAccessTest extends TestCase
{
    /** @dataProvider operations */
    public function testNativeSideEffectsCannotRunBeforeTheVerdict(string $action, bool $denied): void
    {
        $quote = $this->createMock(Quote::class);
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->expects(self::once())->method('runQuote')->with($quote, $action, self::isType('callable'))
            ->willReturnCallback(function ($q, $a, $work) use ($denied) {
                if ($denied) {
                    throw new LocalizedException(__('Blocked'));
                }
                return $work();
            });
        $called = false;
        $proceed = function (...$args) use (&$called) { $called = true; return $args; };
        try {
            $result = $action === 'cart'
                ? (new QuoteAccess($verdict))->aroundAddProduct($quote, $proceed, 'product', 'request')
                : (new OrderAccess($verdict))->aroundSubmit($this->createMock(QuoteManagement::class), $proceed, $quote, ['key' => 'value']);
            self::assertFalse($denied);
            self::assertSame($action === 'cart' ? ['product', 'request'] : [$quote, ['key' => 'value']], $result);
        } catch (LocalizedException $error) {
            self::assertTrue($denied);
        }
        self::assertSame(!$denied, $called);
    }

    public static function operations(): array
    {
        return [['cart', false], ['cart', true], ['order', false], ['order', true]];
    }
}
