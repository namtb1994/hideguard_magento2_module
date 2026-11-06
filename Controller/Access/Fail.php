<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Controller\Access;

class Fail extends Check
{
    /**
     * @inheritdoc
     */
    protected function getAccessData(int $storeId): array
    {
        $verificationId = $this->request->getParam('verificationId');
        return $this->access->failVerification($storeId, is_string($verificationId) ? $verificationId : '');
    }
}
