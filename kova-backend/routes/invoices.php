<?php

use App\Http\Controllers\Fiscal\PublicInvoicingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Resolución de código manual
|--------------------------------------------------------------------------
|
| Ejemplo:
|
| K7PT-4M9X-2QW8
|
| Debe declararse antes de /{token}.
|
*/

Route::post(
    'public/factura/resolve-code',
    [
        PublicInvoicingController::class,
        'resolveCode',
    ]
)->middleware(
    'throttle:public-invoice-resolve'
);

/*
|--------------------------------------------------------------------------
| Autofacturación mediante token seguro
|--------------------------------------------------------------------------
*/

Route::prefix(
    'public/factura/{token}'
)
    ->group(
        function (): void {
            Route::get(
                '/',
                [
                    PublicInvoicingController::class,
                    'show',
                ]
            )->middleware(
                'throttle:public-invoice-read'
            );

            Route::post(
                '/',
                [
                    PublicInvoicingController::class,
                    'store',
                ]
            )->middleware(
                'throttle:public-invoice-write'
            );

            Route::get(
                '/status',
                [
                    PublicInvoicingController::class,
                    'status',
                ]
            )->middleware(
                'throttle:public-invoice-read'
            );

            Route::get(
                '/catalogs',
                [
                    PublicInvoicingController::class,
                    'catalogs',
                ]
            )->middleware(
                'throttle:public-invoice-read'
            );

            Route::get(
                '/download/{format}',
                [
                    PublicInvoicingController::class,
                    'download',
                ]
            )
                ->middleware(
                    [
                        'signed',
                        'throttle:public-invoice-read',
                    ]
                )
                ->name(
                    'public.fiscal.factura.download'
                );
        }
    );
