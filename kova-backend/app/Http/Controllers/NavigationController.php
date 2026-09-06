<?php

namespace App\Http\Controllers;

use App\Services\Navigation\NavigationService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function index(
        Request $request,
        CurrentTenant $currentTenant,
        NavigationService $navigation,
    ): JsonResponse {
        return response()->json([
            'sections' => $navigation->sectionsFor(
                $request->user(),
                $currentTenant->get(),
            ),
        ]);
    }
}
