<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Plugin;

use Hachitechs\Hideguard\Model\ActionVerdict;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Shared native services cover storefront, REST and GraphQL authentication/account creation. */
class AuthAccess
{
    /**
     * @param ActionVerdict $verdict
     * @param StoreManagerInterface $stores
     */
    public function __construct(private readonly ActionVerdict $verdict, private readonly StoreManagerInterface $stores)
    {
    }

    /**
     * Check before authentication and its login side effects.
     *
     * @param AccountManagementInterface $subject
     * @param callable $proceed
     * @param string $email
     * @param string $password
     * @return mixed
     */
    public function aroundAuthenticate(AccountManagementInterface $subject, callable $proceed, $email, $password)
    {
        return $this->run('login', fn() => $proceed($email, $password));
    }

    /**
     * Check token issuance before credentials are handled by the native token service.
     *
     * @param CustomerTokenServiceInterface $subject
     * @param callable $proceed
     * @param string $username
     * @param string $password
     * @return mixed
     */
    public function aroundCreateCustomerAccessToken(
        CustomerTokenServiceInterface $subject,
        callable $proceed,
        $username,
        $password
    ) {
        return $this->run('login', fn() => $proceed($username, $password));
    }

    /**
     * Check before an account is saved or a welcome email can be sent.
     *
     * @param AccountManagementInterface $subject
     * @param callable $proceed
     * @param CustomerInterface $customer
     * @param string|null $password
     * @param string $redirectUrl
     * @return mixed
     */
    public function aroundCreateAccount(
        AccountManagementInterface $subject,
        callable $proceed,
        CustomerInterface $customer,
        $password = null,
        $redirectUrl = ''
    ) {
        return $this->run('register', fn() => $proceed($customer, $password, $redirectUrl));
    }

    /**
     * Cover native account creation paths that already have a password hash.
     *
     * @param AccountManagementInterface $subject
     * @param callable $proceed
     * @param CustomerInterface $customer
     * @param string|null $hash
     * @param string $redirectUrl
     * @return mixed
     */
    public function aroundCreateAccountWithPasswordHash(
        AccountManagementInterface $subject,
        callable $proceed,
        CustomerInterface $customer,
        $hash,
        $redirectUrl = ''
    ) {
        return $this->run('register', fn() => $proceed($customer, $hash, $redirectUrl));
    }

    /**
     * Select the current native store without accepting a store ID from the client.
     *
     * @param string $action
     * @param callable $work
     * @return mixed
     */
    private function run(string $action, callable $work): mixed
    {
        return $this->verdict->runBrowser((int)$this->stores->getStore()->getId(), $action, $work);
    }
}
