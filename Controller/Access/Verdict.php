<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Controller\Access;

class Verdict extends Check
{
    /**
     * @inheritdoc
     */
    protected function getAccessData(int $storeId): array
    {
        $response = $this->request->getParam('response');
        try {
            $payload = is_string($response) && strlen($response) <= 16384
                ? json_decode($response, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $payload = null;
        }
        // Report malformed payloads too, so core revokes any earlier permission.
        $verificationId = $this->request->getParam('verificationId');
        return $this->access->report(
            $storeId,
            is_array($payload) ? $payload : [],
            is_string($verificationId) ? $verificationId : null
        );
    }
}
