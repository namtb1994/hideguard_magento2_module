<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Magento\PageCache\Model\VclGeneratorInterface;
use Magento\Framework\UrlInterface;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Laminas\Uri\Http as Uri;

class VarnishAccess
{
    /**
     * @param Config $config
     * @param StoreManagerInterface $stores
     * @param PathMatcher $paths
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly PathMatcher $paths,
        private readonly ?\Hachitechs\Hideguard\Model\Cache\ManagedVcl $managed = null
    ) {
    }

    /**
     * Export rules for the current store scopes. Regenerate/apply VCL and purge after scope changes.
     *
     * @param VclGeneratorInterface $subject
     * @param string $result
     * @return string
     */
    public function afterGenerateVcl(VclGeneratorInterface $subject, string $result): string
    {
        if ($this->managed?->enabled()) {
            return $this->managed->generate($result);
        }
        $conditions = [];
        /** @var Store $store */
        foreach ($this->stores->getStores() as $store) {
            $settings = $this->config->settings((int)$store->getId());
            if (!$settings['enabled']) {
                continue;
            }
            $patterns = ['hideguard/*'];
            if (array_intersect(['all_pages', 'product', 'cart', 'checkout'], $settings['points'])) {
                // Native routes can have mutable SEO aliases; conservatively cover the store.
                $patterns[] = '*';
            } else {
                $patterns = [...$patterns, ...$settings['pageRules']];
            }
            $baseUrls = [
                $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, false),
                $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
            ];
            foreach (array_unique($baseUrls) as $baseUrl) {
                $base = new Uri($baseUrl);
                $prefix = trim((string)$base->getPath(), '/');
                $prefix = $prefix === '' ? '' : $prefix . '/';
                $host = '(?i)^' . preg_quote((string)$base->getHost(), '~') . '(?::[0-9]+)?$';
                // Encoded paths are normalized by Magento; keep them out of shared cache conservatively.
                $conditions[] = '(req.http.host ~ ' . $this->regexLiteral($host)
                    . ' && req.url ~ "(%|//|/index[.]php/|[?&]___store=)")';
                if ($prefix !== '') {
                    $root = '(?i)^/' . preg_quote(rtrim($prefix, '/'), '~') . '(?:[?]|$)';
                    $conditions[] = '(req.http.host ~ ' . $this->regexLiteral($host)
                        . ' && req.url ~ ' . $this->regexLiteral($root) . ')';
                }
                foreach (array_unique($patterns) as $pattern) {
                    $path = '(?i)^/' . preg_quote($prefix, '~')
                        . str_replace('\\*', '[^?]*', preg_quote($this->paths->normalize($pattern), '~'))
                        . '/*(?:\\?|$)';
                    $conditions[] = '(req.http.host ~ ' . $this->regexLiteral($host)
                        . ' && req.url ~ ' . $this->regexLiteral($path) . ')';
                }
            }
        }
        if ($conditions === []) {
            return $result;
        }
        $rule = "\n    # Hachitechs_Hideguard: exported protection scopes.\n"
            . '    if (req.method != "PURGE" && req.url !~ "^/(static|media)(/|$)" && ('
            . implode(' || ', $conditions) . ")) {\n        return (pass);\n    }\n";
        $result = preg_replace_callback(
            '/(sub\s+vcl_recv\s*\{)/',
            fn(array $match) => $match[1] . $rule,
            $result,
            1,
            $count
        );
        if ($result === null || $count !== 1) {
            throw new \RuntimeException('Unable to add HideGuard access checks to this VCL template.');
        }
        return $result;
    }

    /**
     * Preserve PCRE backslashes: VCL strings do not use JSON/C escaping.
     *
     * @param string $pattern
     * @return string
     */
    private function regexLiteral(string $pattern): string
    {
        return '"' . preg_replace_callback(
            '/["\x00-\x1f\x7f]/',
            fn(array $match) => sprintf('\\x%02x', ord($match[0])),
            $pattern
        ) . '"';
    }
}
