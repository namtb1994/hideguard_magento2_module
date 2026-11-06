<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Controller\Access;

use Hachitechs\Hideguard\Controller\Access\Check;
use Hachitechs\Hideguard\Controller\Access\Verdict;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\Hideguard\Model\Access;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CheckTest extends TestCase
{
    private const RESPONSE = '{"signature":"signed-header","siteId":"site","environment":"production",'
        . '"requestId":"request","verdict":"block","visitorId":"visitor-test","score":35,'
        . '"reasons":[{"code":"inspectorAttached"}]}';

    private function controller(
        string $class,
        Access $access,
        Json $result,
        bool $validFormKey = true,
        string $content = '',
        ?LoggerInterface $logger = null,
        ?Session $checkout = null,
        $response = self::RESPONSE
    ): Check {
        $request = $this->createMock(Http::class);
        $request->expects(self::never())->method('getActionName');
        $request->method('getContent')->willReturn($content);
        $request->method('getParam')->willReturnMap([
            ['verdict', null, 'block'], ['visitorId', null, 'visitor-test'],
            ['response', null, $response],
        ]);
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $result->method('setHeader')->willReturnSelf();
        $result->method('setHttpResponseCode')->willReturnSelf();
        $result->method('setData')->willReturnSelf();
        $formKey = $this->createMock(Validator::class);
        $formKey->method('validate')->with($request)->willReturn($validFormKey);
        $stores = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $stores->method('getStore')->willReturn($store);
        $logger ??= $this->createMock(LoggerInterface::class);
        return new $class(
            $request, $factory, $formKey, $access, $stores,
            $checkout ?? $this->createMock(Session::class),
            $logger
        );
    }

    public function testCheckReadsPermissionWithoutReportingAVerdict(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects(self::once())->method('status')->with(1)->willReturn(['status' => 'verification_required']);
        $access->expects(self::never())->method('report');
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setData')->with(['status' => 'verification_required'])->willReturnSelf();
        self::assertSame($result, $this->controller(Check::class, $access, $result)->execute());
    }

    public function testVerdictReportsPayloadWithoutDependingOnRequestActionName(): void
    {
        $blocked = ['status' => 'blocked', 'blocked' => true, 'blockedUntil' => (time() + 60) * 1000];
        $access = $this->createMock(Access::class);
        $access->expects(self::never())->method('status');
        $access->expects(self::once())->method('report')->with(1, [
            'verdict' => 'block', 'visitorId' => 'visitor-test',
            'signature' => 'signed-header', 'siteId' => 'site', 'environment' => 'production',
            'requestId' => 'request', 'score' => 35, 'reasons' => [['code' => 'inspectorAttached']],
        ])->willReturn($blocked);
        $access->expects(self::never())->method('bindQuote');
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setHttpResponseCode')->with(401)->willReturnSelf();
        $result->expects(self::once())->method('setData')->with($blocked)->willReturnSelf();
        $this->controller(Verdict::class, $access, $result)->execute();
    }

    /** @dataProvider invalidRequests */
    public function testInvalidRequestCannotReadOrMutatePermissions(bool $validFormKey, string $content, int $code): void
    {
        $access = $this->createMock(Access::class);
        $access->expects(self::never())->method('status');
        $access->expects(self::never())->method('report');
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setHttpResponseCode')->with($code)->willReturnSelf();
        $this->controller(Verdict::class, $access, $result, $validFormKey, $content)->execute();
    }

    public static function invalidRequests(): array
    {
        return [[false, '', 400], [true, str_repeat('x', 65537), 413]];
    }

    /** @dataProvider malformedResponses */
    public function testMalformedResponsesReachCoreForRevocation($response): void
    {
        $access = $this->createMock(Access::class);
        $access->expects(self::once())->method('report')->with(1, [])
            ->willThrowException(new GuardException(FailureReason::InvalidVerdict, 'Rejected'));
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setHttpResponseCode')->with(400)->willReturnSelf();
        $this->controller(Verdict::class, $access, $result, response: $response)->execute();
    }

    public static function malformedResponses(): array
    {
        return [[null], [['unexpected-array']], ['{'], ['null'], ['"string"'], [str_repeat('x', 16385)]];
    }

    public function testExpectedRateLimitReturnsRetryHeaderWithoutLoggingAnApplicationFailure(): void
    {
        $access = $this->createMock(Access::class);
        $access->method('status')->willThrowException(new GuardException(\Hachitechs\HideguardPhp\Exceptions\FailureReason::RateLimited, 'Too many requests.', 60));
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setHttpResponseCode')->with(429)->willReturnSelf();
        $result->expects(self::exactly(2))->method('setHeader')->withConsecutive(
            ['Cache-Control', 'private, no-store, max-age=0', true], ['Retry-After', '60', true]
        )->willReturnSelf();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $this->controller(Check::class, $access, $result, true, '', $logger)->execute();
    }

    public function testUnexpectedStorageFailureIsLoggedAndReturns503WithoutLeakingItsMessage(): void
    {
        $error = new \RuntimeException('Private database detail');
        $access = $this->createMock(Access::class);
        $access->method('status')->willThrowException($error);
        $result = $this->createMock(Json::class);
        $result->expects(self::once())->method('setHttpResponseCode')->with(503)->willReturnSelf();
        $result->expects(self::once())->method('setData')->with([
            'error' => 'Unable to verify access. Please retry.',
        ])->willReturnSelf();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'HideGuard access verification failed.', ['exception' => $error]
        );
        $this->controller(Check::class, $access, $result, true, '', $logger)->execute();
    }

    /** @dataProvider domainErrors */
    public function testBothEndpointsMapDomainRejectionsToHttp(FailureReason $reason, int $status): void
    {
        foreach ([Check::class => 'status', Verdict::class => 'report'] as $class => $method) {
            $access = $this->createMock(Access::class);
            $access->method($method)->willThrowException(new GuardException($reason, 'Rejected'));
            $result = $this->createMock(Json::class);
            $result->expects(self::once())->method('setHttpResponseCode')->with($status)->willReturnSelf();
            $result->expects(self::once())->method('setData')->with(['error' => 'Rejected'])->willReturnSelf();
            $this->controller($class, $access, $result)->execute();
        }
    }

    public static function domainErrors(): array
    {
        return [
            [FailureReason::InvalidConfiguration, 400], [FailureReason::InvalidVerdict, 400],
            [FailureReason::InvalidIdentity, 409], [FailureReason::Disabled, 409],
            [FailureReason::PermissionDenied, 403], [FailureReason::RateLimited, 429],
            [FailureReason::VerificationUnavailable, 501], [FailureReason::Busy, 503],
            [FailureReason::CapacityReached, 503],
        ];
    }

    public function testPageOnlyCheckDoesNotCreateOrLoadAQuote(): void
    {
        $allowed = ['status' => 'allowed'];
        $access = $this->createMock(Access::class);
        $access->method('status')->willReturn($allowed);
        $access->expects(self::never())->method('bindQuote');
        $checkout = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getQuoteId'])->getMock();
        $checkout->method('getQuoteId')->willReturn(null);
        $checkout->expects(self::never())->method('getQuote');
        $this->controller(Check::class, $access, $this->createMock(Json::class), checkout: $checkout)->execute();
    }

    public function testExistingCartIsBoundWithoutSavingANewQuote(): void
    {
        $access = $this->createMock(Access::class);
        $access->method('status')->willReturn(['status' => 'allowed']);
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(123);
        $checkout = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getQuoteId'])->getMock();
        $checkout->method('getQuoteId')->willReturn(123);
        $checkout->method('getQuote')->willReturn($quote);
        $access->expects(self::once())->method('bindQuote')->with($quote);
        $this->controller(Check::class, $access, $this->createMock(Json::class), checkout: $checkout)->execute();
    }

    public function testAnExpiredCartDoesNotTurnAnAllowedPageIntoAnError(): void
    {
        $access = $this->createMock(Access::class);
        $access->method('status')->willReturn(['status' => 'allowed']);
        $access->expects(self::never())->method('bindQuote');
        $checkout = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getQuoteId'])->getMock();
        $checkout->method('getQuoteId')->willReturn(123);
        $checkout->method('getQuote')->willReturn($this->createMock(Quote::class));
        $result = $this->createMock(Json::class);
        $result->expects(self::never())->method('setHttpResponseCode');
        $result->expects(self::once())->method('setData')->with(['status' => 'allowed'])->willReturnSelf();
        $this->controller(Check::class, $access, $result, checkout: $checkout)->execute();
    }

}
