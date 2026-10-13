<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\SatPostalTimezoneResolver;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SatPostalTimezoneResolverTest extends TestCase
{
    #[DataProvider('postalTimezones')]
    public function test_it_resolves_sat_postal_timezone_to_iana(
        array $postal,
        string $expected,
    ): void {
        $timezone = app(
            SatPostalTimezoneResolver::class,
        )->resolve($postal);

        $this->assertSame(
            $expected,
            $timezone,
        );
    }

    public static function postalTimezones(): array
    {
        return [
            'Culiacán Sinaloa' => [
                [
                    'code' => '80290',
                    'state' => 'SIN',
                    'timezone_description' =>
                    'Tiempo del Pacífico',
                    'summer_start_month' => null,
                    'summer_start_day' => null,
                    'summer_start_hour' => null,
                    'summer_difference' => '-7',
                    'winter_start_month' => null,
                    'winter_start_day' => null,
                    'winter_start_hour' => null,
                    'winter_difference' => '-7',
                ],
                'America/Mazatlan',
            ],

            'Ciudad de México' => [
                [
                    'code' => '06000',
                    'state' => 'CMX',
                    'timezone_description' =>
                    'Tiempo del Centro',
                    'summer_start_month' => null,
                    'summer_start_day' => null,
                    'summer_start_hour' => null,
                    'summer_difference' => '-6',
                    'winter_start_month' => null,
                    'winter_start_day' => null,
                    'winter_start_hour' => null,
                    'winter_difference' => '-6',
                ],
                'America/Mexico_City',
            ],

            'Cancún Quintana Roo' => [
                [
                    'code' => '77500',
                    'state' => 'ROO',
                    'timezone_description' =>
                    'Tiempo del Sureste',
                    'summer_start_month' => null,
                    'summer_start_day' => null,
                    'summer_start_hour' => null,
                    'summer_difference' => '-5',
                    'winter_start_month' => null,
                    'winter_start_day' => null,
                    'winter_start_hour' => null,
                    'winter_difference' => '-5',
                ],
                'America/Cancun',
            ],

            'Tijuana Baja California' => [
                [
                    'code' => '22000',
                    'state' => 'BCN',
                    'timezone_description' =>
                    'Tiempo del Noroeste',
                    'summer_start_month' => 'Marzo',
                    'summer_start_day' =>
                    'Segundo domingo',
                    'summer_start_hour' => '02:00',
                    'summer_difference' => '-7',
                    'winter_start_month' => 'Noviembre',
                    'winter_start_day' =>
                    'Primer domingo',
                    'winter_start_hour' => '02:00',
                    'winter_difference' => '-8',
                ],
                'America/Tijuana',
            ],

            'Hidalgo Centro' => [
                [
                    'code' => '42501',
                    'state' => 'HID',
                    'timezone_description' =>
                    'Tiempo del Centro',
                    'summer_start_month' => null,
                    'summer_start_day' => null,
                    'summer_start_hour' => null,
                    'summer_difference' => '-6',
                    'winter_start_month' => null,
                    'winter_start_day' => null,
                    'winter_start_hour' => null,
                    'winter_difference' => '-6',
                ],
                'America/Mexico_City',
            ],
        ];
    }

    public function test_it_rejects_a_postal_code_without_resolvable_timezone(): void
    {
        $this->expectException(
            ValidationException::class,
        );

        app(
            SatPostalTimezoneResolver::class,
        )->resolve([
            'code' => '00000',
            'state' => '',
            'timezone_description' => null,
            'summer_start_month' => null,
            'summer_start_day' => null,
            'summer_start_hour' => null,
            'summer_difference' => null,
            'winter_start_month' => null,
            'winter_start_day' => null,
            'winter_start_hour' => null,
            'winter_difference' => null,
        ]);
    }
}
