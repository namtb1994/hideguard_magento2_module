<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Hachitechs\Hideguard\Model\DeniedResponse;
use Hachitechs\Hideguard\Plugin\ActionResponse;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class ActionResponseTest extends TestCase
{
    /** @dataProvider publicAreas */
    public function testCaughtControllerOrApiExceptionStillReturnsCommonDenial(string $area): void
    {
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->method('denial')->willReturn(['status' => 'blocked']);
        $response = $this->createMock(Http::class);
        $request = $this->createMock(RequestInterface::class);
        $denied = $this->createMock(DeniedResponse::class);
        $denied->expects(self::once())->method('create')->with($request, ['status' => 'blocked'], $area === 'frontend')
            ->willReturn($response);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn($area);
        $plugin = new ActionResponse($verdict, $denied, $state);
        self::assertSame($response, $plugin->afterDispatch($this->createMock(FrontControllerInterface::class),
            'native controller caught exception and returned something else', $request));
    }

    public static function publicAreas(): array
    {
        return [['frontend'], ['webapi_rest'], ['graphql']];
    }

    public function testUnrelatedResponsesArePreserved(): void
    {
        $verdict = $this->createMock(ActionVerdict::class);
        $verdict->method('denial')->willReturn(null);
        $denied = $this->createMock(DeniedResponse::class);
        $denied->expects(self::never())->method('create');
        $state = $this->createMock(State::class);
        $state->expects(self::never())->method('getAreaCode');
        self::assertSame('unchanged', (new ActionResponse($verdict, $denied, $state))->afterDispatch(
            $this->createMock(FrontControllerInterface::class), 'unchanged', $this->createMock(RequestInterface::class)));
    }
}
