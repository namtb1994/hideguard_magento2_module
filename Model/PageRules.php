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
            '#^antidetectbrowser/(?:access/(?:check|verdict|fail)(?:/|$)|gate(?:/index(?:/|$)|$))#i',
            $this->paths->normalize($path)
        );
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
        $path = $this->paths->normalize($path);
        $rewrite = $this->rewrites->findOneByData(['request_path' => $path, 'store_id' => $storeId]);
        // Magento resolves controller/action classes without regard to case.
        // Keep the original case for custom path rules and URL rewrite lookup.
        $target = strtolower($rewrite ? $this->paths->normalize($rewrite->getTargetPath()) : $path);
        return (in_array('product', $settings['points'], true) && str_starts_with($target, 'catalog/product/'))
            || (in_array('cart', $settings['points'], true)
                && ($target === 'checkout/cart' || str_starts_with($target, 'checkout/cart/')))
            || (in_array('checkout', $settings['points'], true)
                && (in_array($target, ['checkout', 'checkout/index', 'onestepcheckout'], true)
                    || str_starts_with($target, 'checkout/index/')
                    || $target === 'checkout/onepage' || str_starts_with($target, 'checkout/onepage/index')
                    || str_starts_with($target, 'onestepcheckout/')));
    }
}
