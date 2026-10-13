<?php

namespace App\Services\Fiscal;

use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SatPostalTimezoneResolver
{
    public function resolve(array $postal): string
    {
        $state = strtoupper(
            trim(
                (string) ($postal['state'] ?? ''),
            ),
        );

        $description = $this->normalize(
            $postal['timezone_description'] ?? null,
        );

        $summer = $this->offset(
            $postal['summer_difference'] ?? null,
        );

        $winter = $this->offset(
            $postal['winter_difference'] ?? null,
        );

        $seasonal = $this->hasSeasonalRule(
            $postal,
        );

        $timezone = match (true) {
            $state === 'BCN' =>
            'America/Tijuana',

            $state === 'ROO' =>
            'America/Cancun',

            $state === 'SON' =>
            'America/Hermosillo',

            $state === 'NAY'
                && $description === 'tiempo del centro' =>
            'America/Bahia_Banderas',

            in_array(
                $state,
                [
                    'BCS',
                    'SIN',
                    'NAY',
                ],
                true,
            ) =>
            'America/Mazatlan',

            $state === 'CHH' =>
            $this->resolveChihuahua(
                $summer,
                $winter,
                $seasonal,
                $postal,
            ),

            in_array(
                $state,
                [
                    'COA',
                    'NLE',
                    'TAM',
                ],
                true,
            )
                && $seasonal
                && $summer === -5
                && $winter === -6 =>
            'America/Matamoros',

            in_array(
                $state,
                [
                    'COA',
                    'NLE',
                    'TAM',
                    'DUR',
                ],
                true,
            ) =>
            'America/Monterrey',

            in_array(
                $state,
                [
                    'CAM',
                    'YUC',
                ],
                true,
            ) =>
            'America/Merida',

            $description === 'tiempo del noroeste' =>
            'America/Tijuana',

            $description === 'tiempo del sureste' =>
            'America/Cancun',

            $description === 'tiempo del pacifico' =>
            'America/Mazatlan',

            $description === 'tiempo del centro' =>
            'America/Mexico_City',

            default =>
            $this->fail($postal),
        };

        new DateTimeZone(
            $timezone,
        );

        return $timezone;
    }

    private function resolveChihuahua(
        ?int $summer,
        ?int $winter,
        bool $seasonal,
        array $postal,
    ): string {
        if (
            $seasonal
            && $summer === -6
            && $winter === -7
        ) {
            return 'America/Ciudad_Juarez';
        }

        if (
            $seasonal
            && $summer === -5
            && $winter === -6
        ) {
            return 'America/Ojinaga';
        }

        if (
            ! $seasonal
            && $summer === -6
            && $winter === -6
        ) {
            return 'America/Chihuahua';
        }

        return $this->fail(
            $postal,
        );
    }

    private function hasSeasonalRule(
        array $postal,
    ): bool {
        foreach (
            [
                'summer_start_month',
                'summer_start_day',
                'summer_start_hour',
                'winter_start_month',
                'winter_start_day',
                'winter_start_hour',
            ] as $field
        ) {
            if (
                isset($postal[$field])
                && trim(
                    (string) $postal[$field],
                ) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function offset(
        mixed $value,
    ): ?int {
        if (
            $value === null
            || trim((string) $value) === ''
            || ! is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }

    private function normalize(
        mixed $value,
    ): string {
        return preg_replace(
            '/\s+/',
            ' ',
            strtolower(
                Str::ascii(
                    trim(
                        (string) $value,
                    ),
                ),
            ),
        ) ?: '';
    }

    private function fail(
        array $postal,
    ): never {
        throw ValidationException::withMessages([
            'postal_code' => [
                sprintf(
                    'No fue posible determinar la zona horaria fiscal del código postal %s.',
                    $postal['code'] ?? '',
                ),
            ],
        ]);
    }
}
