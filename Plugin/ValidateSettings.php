<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\Hideguard\Model\Config;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;
use Magento\Config\Model\Config as SystemConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class ValidateSettings
{
    /**
     * @param Config $config
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /**
     * Validate posted fields against their effective inherited configuration.
     *
     * @param SystemConfig $subject
     * @return void
     */
    public function beforeSave(SystemConfig $subject): void
    {
        if ($subject->getSection() !== Config::SECTION) {
            return;
        }
        $storeScope = $subject->getStore() ?: ($subject->getScope() === 'stores' ? $subject->getScopeId() : null);
        $websiteScope = $subject->getWebsite() ?: ($subject->getScope() === 'websites' ? $subject->getScopeId() : null);
        if ($storeScope) {
            $store = $this->stores->getStore($storeScope);
            $settings = $this->config->read('stores', $store->getId());
            $parent = $this->config->read('websites', $store->getWebsiteId());
        } elseif ($websiteScope) {
            $settings = $this->config->read('websites', $websiteScope);
            $parent = $this->config->read('default', null);
        } else {
            $settings = $this->config->read('default', null);
            $parent = $settings;
        }
        $groups = (array)$subject->getData('groups');
        $fields = [
            'enabled' => 'enabled',
            'protected_paths' => 'pageRules', 'block_duration' => 'blockDuration', 'pass_duration' => 'passDuration',
        ];
        foreach ($fields as $field => $name) {
            $posted = $groups['general']['fields'][$field] ?? null;
            if ($posted === null) {
                continue;
            }
            if (!empty($posted['inherit'])) {
                $settings[$name] = $parent[$name];
                continue;
            }
            $value = $posted['value'] ?? '';
            $settings[$name] = match ($field) {
                'enabled' => (bool)$value,
                'block_duration' => (int)$value,
                'pass_duration' => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => -1]]),
                'protected_paths' => $this->config->normalizePaths((string)$value),
                default => (string)$value,
            };
        }
        $credentialFields = [
            'public_key' => 'publicKey',
            'signature_public_key' => 'signaturePublicKey',
        ];
        foreach ($credentialFields as $field => $name) {
            $posted = $groups['general']['fields'][$field] ?? null;
            if ($posted !== null) {
                $settings['keys'][$name] = !empty($posted['inherit'])
                    ? $parent['keys'][$name] : trim((string)($posted['value'] ?? ''));
            }
        }
        try {
            $policy = $this->config->toPolicy($settings);
            if ($policy->enabled) {
                Ed25519Verifier::validatePublicKey($policy->signaturePublicKey);
            }
        } catch (GuardException $error) {
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
