<?php

namespace App\Services\Fiscal;

final class RfcClassifier
{
    public const GENERIC_NATIONAL = 'XAXX010101000';

    public const GENERIC_FOREIGN = 'XEXX010101000';

    public function classify(string $value): string
    {
        $rfc = strtoupper(trim($value));
        if ($rfc === self::GENERIC_NATIONAL) {
            return 'generic_national';
        }
        if ($rfc === self::GENERIC_FOREIGN) {
            return 'generic_foreign';
        }
        if (! preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $rfc)) {
            return 'invalid';
        }

        return strlen($rfc) === 12 ? 'legal' : (strlen($rfc) === 13 ? 'physical' : 'invalid');
    }
}
