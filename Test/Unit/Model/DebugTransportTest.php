<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Test\Unit\Model;

use Hachitechs\HideguardPhp\Contracts\HttpTransport;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Http\HttpResponse;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\Hideguard\Model\DebugTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DebugTransportTest extends TestCase
{
    private const URL = 'https://fixture.hideguard.example/api/v1/verdict';
    private const BODY = '{"visitorId":"private-visitor"}';
    private const HEADERS = ['x-api-key' => 'private-api-key'];

    public function testCopyTruncateKeepsAnOpenLoggerWriting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hideguard-log-');
        $archive = $file . '.1';
        $handler = new \Monolog\Handler\StreamHandler($file, \Monolog\Logger::DEBUG);
        $logger = new \Monolog\Logger('fixture', [$handler]);
        $transport = $this->createMock(HttpTransport::class);
        $response = new HttpResponse(200, '{"verdict":"allow","visitorId":"private-visitor"}');
        $transport->expects(self::exactly(2))->method('post')->willReturn($response);
        try {
            $config = $this->createMock(Config::class);
            $config->expects(self::exactly(2))->method('debugEnabled')->with(7)->willReturn(true);
            $subject = new DebugTransport($transport, $config, $logger, 7);
            self::assertSame($response, $subject->post(self::URL, self::HEADERS, self::BODY));
            copy($file, $archive);
            $stream = fopen($file, 'c');
            ftruncate($stream, 0);
            fclose($stream);
            self::assertSame($response, $subject->post(self::URL, self::HEADERS, self::BODY));
            foreach ([$file, $archive] as $path) {
                $records = file_get_contents($path);
                self::assertSame(2, substr_count(trim($records), "\n") + 1);
                self::assertStringContainsString('HideGuard response', $records);
                self::assertStringNotContainsString('private-visitor', $records);
                self::assertStringNotContainsString('private-api-key', $records);
            }
        } finally {
            $handler->close();
            unlink($file);
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    public function testDisabledLoggingForwardsTheExactRequestAndResponseWithoutWriting(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $response = new HttpResponse(200, '{"verdict":"allow"}');
        $transport->expects(self::once())->method('post')->with(self::URL, self::HEADERS, self::BODY)
            ->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');
        self::assertSame($response, $this->subject($transport, $logger, false)->post(self::URL, self::HEADERS, self::BODY));
    }

    public function testEnabledLoggingCorrelatesTheRequestWithAnAllowlistedResponseSummary(): void
    {
        $response = new HttpResponse(401, json_encode([
            'visitorId' => 'response-visitor', 'verdict' => 'block', 'score' => 100,
            'reasons' => [['code' => 'browserTampering', 'detail' => 'private-detail'], ['code' => "bad\ncode"]],
            'signature' => 'private-signature', 'apiKey' => 'private-api-key', 'email' => 'private-email',
        ], JSON_THROW_ON_ERROR));
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects(self::once())->method('post')->with(self::URL, self::HEADERS, self::BODY)
            ->willReturn($response);
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug')
            ->willReturnCallback(function ($message, $context) use (&$records): void { $records[] = [$message, $context]; });

        self::assertSame($response, $this->subject($transport, $logger)->post(self::URL, self::HEADERS, self::BODY));
        self::assertSame('HideGuard request', $records[0][0]);
        self::assertSame('HideGuard response', $records[1][0]);
        self::assertSame($records[0][1]['request_id'], $records[1][1]['request_id']);
        self::assertSame(7, $records[0][1]['store_id']);
        self::assertSame(self::URL, $records[0][1]['url']);
        self::assertSame('POST', $records[0][1]['method']);
        self::assertSame(hash('sha256', 'private-visitor'), $records[0][1]['request']['visitor_hash']);
        self::assertSame(401, $records[1][1]['http_status']);
        self::assertGreaterThanOrEqual(0, $records[1][1]['duration_ms']);
        self::assertSame(['body_format' => 'json', 'visitor_hash' => hash('sha256', 'response-visitor'),
            'verdict' => 'block', 'score' => 100, 'reason_codes' => ['browserTampering']], $records[1][1]['response']);
        foreach (['private-visitor', 'response-visitor', 'private-api-key', 'private-detail',
            'private-signature', 'private-email', 'bad\\ncode'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($records));
        }
    }

    public function testTransportFailureIsLoggedWithoutExposingTheMessageAndRethrownUnchanged(): void
    {
        $error = new ApiException('private-error-with-api-key', 502);
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects(self::once())->method('post')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug')->willReturnCallback(function ($message, $context): void {
            self::assertStringNotContainsString('private-error', json_encode($context));
            if ($message === 'HideGuard transport error') {
                self::assertSame(ApiException::class, $context['error_type']);
                self::assertSame(502, $context['http_status']);
            }
        });
        try {
            $this->subject($transport, $logger)->post(self::URL, self::HEADERS, self::BODY);
            self::fail('Expected the original transport exception.');
        } catch (ApiException $caught) {
            self::assertSame($error, $caught);
        }
    }

    /** @dataProvider failingTransport */
    public function testLogWriteFailureCannotChangeTheHttpResultOrRetry(bool $fail): void
    {
        $response = new HttpResponse(200, '{"verdict":"allow"}');
        $error = new ApiException('connection failed');
        $transport = $this->createMock(HttpTransport::class);
        $post = $transport->expects(self::once())->method('post');
        if ($fail) {
            $post->willThrowException($error);
        } else {
            $post->willReturn($response);
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug')->willThrowException(new \RuntimeException('disk full'));
        try {
            self::assertSame($response, $this->subject($transport, $logger)->post(self::URL, self::HEADERS, self::BODY));
            self::assertFalse($fail);
        } catch (ApiException $caught) {
            self::assertTrue($fail);
            self::assertSame($error, $caught);
        }
    }

    public static function failingTransport(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider responseBodies */
    public function testMalformedOrLargeResponsesProduceBoundedDiagnostics(string $body, array $expected): void
    {
        $response = new HttpResponse(503, $body);
        $transport = $this->createMock(HttpTransport::class);
        $transport->method('post')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('debug')->willReturnCallback(
            function ($message, $context) use ($expected): void {
                if ($message === 'HideGuard response') {
                    self::assertSame(503, $context['http_status']);
                    self::assertSame($expected, $context['response']);
                }
            }
        );
        self::assertSame($response, $this->subject($transport, $logger)->post(self::URL, self::HEADERS, self::BODY));
    }

    public static function responseBodies(): array
    {
        return [
            ['<html>private-error</html>', ['body_format' => 'invalid_json']],
            ['"private-string"', ['body_format' => 'unexpected_json']],
            [str_repeat('x', 65537), ['body_format' => 'omitted', 'bytes' => 65537]],
            ['{"verdict":{"secret":"private"}}', ['body_format' => 'json', 'verdict' => 'unknown']],
        ];
    }

    public function testDiagnosticConfigFailureStillSendsTheRequestWithoutLogging(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('debugEnabled')->with(7)
            ->willThrowException(new \RuntimeException('config unavailable'));
        $transport = $this->createMock(HttpTransport::class);
        $response = new HttpResponse(200, '{}');
        $transport->expects(self::once())->method('post')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');
        self::assertSame($response, (new DebugTransport($transport, $config, $logger, 7))
            ->post(self::URL, self::HEADERS, self::BODY));
    }

    private function subject(HttpTransport $transport, LoggerInterface $logger, bool $enabled = true): DebugTransport
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('debugEnabled')->with(7)->willReturn($enabled);
        return new DebugTransport($transport, $config, $logger, 7);
    }
}
