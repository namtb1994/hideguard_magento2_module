<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Support\SystemClock;
use Hachitechs\HideguardPhp\Http\CircuitBreakingTransport;
use Hachitechs\HideguardPhp\Contracts\CircuitBreakerStoreContract;
use Hachitechs\HideguardPhp\Contracts\HttpTransport;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Http\HttpResponse;
use Hachitechs\HideguardPhp\Verification\VerdictPolicy;
use Psr\Log\LoggerInterface;

/** Store-scoped diagnostics around the package transport, without changing its HTTP behavior. */
class DebugTransport implements HttpTransport
{
    /**
     * @param HttpTransport $transport
     * @param Config $config
     * @param LoggerInterface $logger
     * @param int $storeId
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly int $storeId,
        private readonly ?CircuitBreakerStoreContract $circuits = null,
        private readonly ?\Psr\Clock\ClockInterface $clock = null
    ) {
    }

    /**
     * Log only server-to-server verdict traffic; never log headers or raw bodies.
     *
     * @param string $url
     * @param array<string,string> $headers
     * @param string $body
     * @return HttpResponse
     */
    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $transport = $this->transport;
        $circuit = $this->config->circuitSettings($this->storeId);
        if ($circuit['enabled'] ?? false) {
            if ($this->circuits === null) {
                throw new ApiException('Circuit storage is not configured.', 503);
            }
            $transport = new CircuitBreakingTransport(
                $transport,
                $this->circuits,
                $this->clock ?? new SystemClock(),
                $circuit['policy'],
                (string)$this->storeId
            );
        }
        try {
            $enabled = $this->config->debugEnabled($this->storeId);
        } catch (\Throwable) {
            // Diagnostic configuration must not interrupt access verification.
            $enabled = false;
        }
        if (!$enabled) {
            return $transport->post($url, $headers, $body);
        }
        $start = hrtime(true);
        $context = ['request_id' => hash('sha256', $this->storeId . ':' . $start . ':' . $body),
            'store_id' => $this->storeId, 'method' => 'POST', 'url' => $url];
        $this->write('HideGuard request', $context + ['request' => $this->summary($body)]);
        try {
            $response = $transport->post($url, $headers, $body);
        } catch (\Throwable $error) {
            $this->write('HideGuard transport error', $context + [
                'duration_ms' => round((hrtime(true) - $start) / 1000000, 2),
                'error_type' => get_class($error),
                'http_status' => $error instanceof ApiException ? $error->statusCode : null,
            ]);
            throw $error;
        }
        $this->write('HideGuard response', $context + [
            'duration_ms' => round((hrtime(true) - $start) / 1000000, 2),
            'http_status' => $response->statusCode,
            'response' => $this->summary($response->body),
        ]);
        return $response;
    }

    /**
     * Bound diagnostic output and omit arbitrary payload fields, signatures and reason details.
     *
     * @param string $body
     * @return array<string,mixed>
     */
    private function summary(string $body): array
    {
        if (strlen($body) > 65536) {
            return ['body_format' => 'omitted', 'bytes' => strlen($body)];
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['body_format' => 'invalid_json'];
        }
        if (!is_array($data)) {
            return ['body_format' => 'unexpected_json'];
        }
        $summary = ['body_format' => 'json'];
        if (is_string($data['visitorId'] ?? null)) {
            $summary['visitor_hash'] = hash('sha256', $data['visitorId']);
        }
        if (isset($data['verdict'])) {
            $summary['verdict'] = VerdictPolicy::groupFor($data['verdict']) !== null ? $data['verdict'] : 'unknown';
        }
        if (is_int($data['score'] ?? null)) {
            $summary['score'] = $data['score'];
        }
        if (is_array($data['reasons'] ?? null)) {
            $summary['reason_codes'] = [];
            foreach (array_slice($data['reasons'], 0, 20) as $reason) {
                $code = is_array($reason) ? ($reason['code'] ?? null) : null;
                if (is_string($code) && preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $code)) {
                    $summary['reason_codes'][] = $code;
                }
            }
        }
        return $summary;
    }

    /**
     * Log I/O failures must not turn a valid verdict into a denial or retry a request.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    private function write(string $message, array $context): void
    {
        try {
            $this->logger->debug($message, $context);
        } catch (\Throwable) {
            // Diagnostics are best-effort and must preserve the original result/error.
            return;
        }
    }
}
