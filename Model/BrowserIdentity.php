<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model;

use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Magento\Customer\Model\Session;

class BrowserIdentity
{
    /**
     * @param Session $session
     */
    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Identify this browser across session regeneration without exposing its secret.
     *
     * @return string
     * @throws GuardException
     */
    public function hash(): string
    {
        $id = (string)$this->session->getSessionId();
        if ($id === '') {
            throw new GuardException(FailureReason::InvalidIdentity, 'A browser session is required for verification.');
        }
        // Keep browser continuity when Magento regenerates the PHP session ID at login.
        $browserId = (string)$this->session->getData('hachitech_hideguard_browser_id');
        if (!preg_match('/^[a-f0-9]{64}$/D', $browserId)) {
            $browserId = bin2hex(random_bytes(32));
            $this->session->setData('hachitech_hideguard_browser_id', $browserId);
        }
        return hash('sha256', $browserId);
    }

    /**
     * Map guests to null and authenticated customers to strings.
     *
     * @return ?string
     */
    public function actorId(): ?string
    {
        $customerId = (int)$this->session->getCustomerId();
        return $customerId ? (string)$customerId : null;
    }
}
