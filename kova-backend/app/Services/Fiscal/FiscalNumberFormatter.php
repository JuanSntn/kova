<?php

namespace App\Services\Fiscal;

final class FiscalNumberFormatter
{
    private const DISPLAY_LENGTH = 6;

    public function format(
        string $series,
        int $folio
    ): string {
        $series = strtoupper(
            trim($series)
        );

        return sprintf(
            '%s-%s',
            $series,
            $this->formatFolio($folio)
        );
    }

    public function formatFolio(
        int $folio
    ): string {
        return str_pad(
            (string) $folio,
            self::DISPLAY_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }
}
