<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\UrlRewrite\Model\UrlFinderInterface;

class PageRules
{
    /**
     * @param Config $config
     * @param UrlFinderInterface $rewrites
     * @param PathMatcher $paths
     */
    public function __construct(
        private readonly Config $config,
        private readonly UrlFinderInterface $rewrites,
        private readonly PathMatcher $paths
    ) {
    }

    /**
     * Keep the verification endpoints reachable independently of page protection.
     *
     * @param string $path
     * @return bool
     */
    public function isGatePath(string $path): bool
    {
        return (bool)preg_match(
            '#^hideguard/(?:access/(?:check|verdict|fail)(?:/|$)|gate(?:/index(?:/|$)|$))#i',
            $this->paths->normalize($path)
        );
    }

    /** The success controller consumes checkout session state and must only run after verification. */
    public function isCheckoutSuccess(string $path): bool
    {
        return (bool)preg_match('#^checkout/onepage/success(?:/|$)#i', $this->paths->normalize($path));
    }

    /**
     * Match custom paths or Magento access points, resolving SEO URL rewrites.
     *
     * @param string $path
     * @param int $storeId
     * @return bool
     */
    public function protects(string $path, int $storeId): bool
    {
        $settings = $this->config->settings($storeId);
        if (!$settings['enabled'] || $this->isGatePath($path)) {
            return false;
        }
        if (in_array('all_pages', $settings['points'], true)) {
            return true;
        }
        if ($this->paths->matches($path, $settings['pageRules'])) {
            return true;
        }
        if (array_intersect(['product', 'cart', 'checkout'], $settings['points']) === []) {
            return false;
        }
        $normalized = $this->paths->normalize($path);
        $rewrite = $this->rewrites->findOneByData(['request_path' => $normalized, 'store_id' => $storeId]);
        // Magento resolves controller/action classes without regard to case.
        // Keep the original case for custom path rules and URL rewrite lookup.
        return $this->protectsTarget($rewrite ? $rewrite->getTargetPath() : $path, $settings['points']);
    }

    /** Same canonical route selection for live requests and the VCL snapshot. */
    public function protectsTarget(string $target, array $points): bool
    {
        $target = strtolower($this->paths->normalize($target));
        return (in_array('product', $points, true) && str_starts_with($target, 'catalog/product/'))
            || (in_array('cart', $points, true)
                && ($target === 'checkout/cart' || str_starts_with($target, 'checkout/cart/')))
            || (in_array('checkout', $points, true)
                && (in_array($target, ['checkout', 'checkout/index', 'onestepcheckout'], true)
                    || str_starts_with($target, 'checkout/index/')
                    || $target === 'checkout/onepage' || str_starts_with($target, 'checkout/onepage/index')
                    || str_starts_with($target, 'onestepcheckout/')));
    }

    public function nativePatterns(array $points): array
    {
        if (in_array('all_pages', $points, true)) {
            return ['*'];
        }
        $patterns = [];
        if (in_array('product', $points, true)) {
            $patterns[] = 'catalog/product/*';
        }
        if (in_array('cart', $points, true)) {
            $patterns = [...$patterns, 'checkout/cart', 'checkout/cart/*'];
        }
        if (in_array('checkout', $points, true)) {
            $patterns = [...$patterns, 'checkout', 'checkout/index', 'checkout/index/*', 'checkout/onepage',
                'checkout/onepage/index*', 'onestepcheckout', 'onestepcheckout/*'];
        }
        return $patterns;
    }
}
