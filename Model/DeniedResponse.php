<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Laminas\Uri\Http as Uri;
use Laminas\Uri\Exception\InvalidArgumentException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http;
use Magento\Framework\UrlInterface;

/** The same gate and HTTP protocol for page protection and action denials. */
class DeniedResponse
{
    /**
     * @param Http $response
     * @param UrlInterface $url
     */
    public function __construct(private readonly Http $response, private readonly UrlInterface $url)
    {
    }

    /**
     * Render the shared denial protocol for a page or an action.
     *
     * @param RequestInterface&HttpRequest $request
     * @param array<string,mixed> $state
     * @param bool $redirectPost
     * @return Http
     */
    public function create(RequestInterface $request, array $state, bool $redirectPost = false): Http
    {
        $failed = in_array($state['status'], ['error', 'verification_failed'], true);
        $gateUrl = $this->url->getUrl('antidetectbrowser/gate/index', [
            '_secure' => true,
            '_query' => ['return' => base64_encode($this->returnPath($request))]
                + ($failed ? ['failed' => '1'] : []),
        ]);
        $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->response->setHeader('X-Hachitech-Antidetect', 'denied', true);
        if ($state['status'] === 'blocked') {
            $this->response->setHeader('Retry-After', (string)max(1, (int)ceil(
                ($state['blockedUntil'] - microtime(true) * 1000) / 1000
            )), true);
        }
        if (!$request->isXmlHttpRequest() && ($request->isGet() || ($redirectPost && $request->isPost()))) {
            $this->response->setRedirect($gateUrl, $request->isGet() ? 302 : 303);
            $this->response->setBody('');
            return $this->response;
        }
        $code = match ($state['status']) {
            'blocked' => 401,
            'error' => 503,
            default => 403,
        };
        $this->response->clearHeader('Location');
        $this->response->clearHeader('Content-Length');
        $this->response->setHttpResponseCode($code);
        $this->response->setHeader('Content-Type', 'application/json', true);
        $this->response->setBody(json_encode($state + [
                'error' => $state['status'] === 'blocked'
                    ? 'Access is temporarily blocked.' : 'Access verification is required.',
                'verificationUrl' => $gateUrl,
            ], JSON_THROW_ON_ERROR));
        return $this->response;
    }

    /**
     * Return to the referring storefront page after a denied form POST, never replay its action URL.
     *
     * @param RequestInterface&HttpRequest $request
     * @return string
     */
    private function returnPath(RequestInterface $request): string
    {
        if ($request->isGet() || !$request->isPost()) {
            return (string)$request->getRequestUri();
        }
        $source = (string)$request->getServer('HTTP_REFERER');
        if ($source !== '') {
            try {
                $referer = new Uri($source);
                $base = new Uri($this->url->getBaseUrl());
                if ($referer->getHost() !== $base->getHost()) {
                    return '/';
                }
                $path = (string)$referer->getPath();
                if (str_starts_with($path, '/') && !str_starts_with($path, '//')
                    && !preg_match('/[\\\\\x00-\x20]/', $path)
                ) {
                    return $path . ($referer->getQuery() === null ? '' : '?' . $referer->getQuery());
                }
            } catch (InvalidArgumentException) {
                return '/';
            }
        }
        return '/';
    }
}
