<?php

namespace App\Contracts\Fiscal;

use App\Data\Fiscal\PacResult;
use PhpCfdi\Credentials\Credential;

interface PacProvider
{
    public function healthCheck(): PacResult;

    public function registerIssuer(string $rfc): PacResult;

    public function issuerStatus(string $rfc): PacResult;

    public function stamp(string $signedXml): PacResult;

    public function recoverStamp(string $signedXml): PacResult;

    public function documentStatus(string $issuerRfc, string $receiverRfc, string $uuid, string $total): PacResult;

    public function cancel(Credential $credential, string $rfc, string $uuid, string $reason, ?string $replacementUuid = null): PacResult;

    public function downloadXml(string $rfc, string $uuid): PacResult;
}
