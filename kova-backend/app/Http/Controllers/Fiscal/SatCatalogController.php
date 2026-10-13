<?php

namespace App\Http\Controllers\Fiscal;

use App\Http\Controllers\Controller;
use App\Services\Fiscal\SatCatalogService;
use App\Services\Fiscal\SatPostalTimezoneResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SatCatalogController extends Controller
{
    public function regimes(
        Request $request,
        SatCatalogService $catalogs,
    ): JsonResponse {
        $data = $request->validate([
            'rfc' => [
                'required',
                'string',
                'max:13',
            ],
        ]);

        return $this->response(
            $catalogs,
            $catalogs->regimes(
                $data['rfc'],
            ),
        );
    }

    public function postal(
        string $code,
        SatCatalogService $catalogs,
        SatPostalTimezoneResolver $timezones,
    ): JsonResponse {
        abort_unless(
            preg_match(
                '/^\d{5}$/',
                $code,
            ),
            404,
        );

        $postal = $catalogs->resolvePostalCode(
            $code,
        );

        abort_unless(
            $postal,
            404,
        );

        return $this->response(
            $catalogs,
            [
                'code' =>
                $postal['code'],

                'description' =>
                $postal['description'],

                'state' =>
                $postal['state'],

                'municipality' =>
                $postal['municipality'],

                'locality' =>
                $postal['locality'],

                'timezone' =>
                $timezones->resolve(
                    $postal,
                ),
            ],
        );
    }

    public function products(
        Request $request,
        SatCatalogService $catalogs,
    ): JsonResponse {
        $data = $request->validate([
            'q' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:25',
            ],
        ]);

        return $this->response(
            $catalogs,
            $catalogs->search(
                'sat_product_services',
                $data['q'],
                $data['limit'] ?? 20,
            ),
        );
    }

    public function units(
        Request $request,
        SatCatalogService $catalogs,
    ): JsonResponse {
        $data = $request->validate([
            'q' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:25',
            ],
        ]);

        return $this->response(
            $catalogs,
            $catalogs->search(
                'sat_units',
                $data['q'],
                $data['limit'] ?? 20,
            ),
        );
    }

    public function objects(
        SatCatalogService $catalogs,
    ): JsonResponse {
        return $this->response(
            $catalogs,
            $catalogs->list(
                'sat_tax_objects',
            ),
        );
    }

    public function uses(
        Request $request,
        SatCatalogService $catalogs,
    ): JsonResponse {
        $data = $request->validate([
            'rfc' => [
                'required',
                'string',
                'max:13',
            ],

            'tax_regime_code' => [
                'required',
                'string',
                'size:3',
            ],
        ]);

        return $this->response(
            $catalogs,
            $catalogs->cfdiUses(
                $data['rfc'],
                $data['tax_regime_code'],
            ),
        );
    }

    private function response(
        SatCatalogService $catalogs,
        mixed $data,
    ): JsonResponse {
        return response()->json([
            'data' =>
            $data,

            'meta' => [
                'catalog_version' =>
                $catalogs->versionName(),
            ],
        ]);
    }
}
