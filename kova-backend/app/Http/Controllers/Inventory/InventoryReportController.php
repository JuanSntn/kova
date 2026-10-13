<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryIntelligencePdfRenderer;
use App\Services\Inventory\InventoryIntelligenceReportService;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\SupplyService;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class InventoryReportController extends Controller
{
    private function context(Request $request): array
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $tenant = app(CurrentTenant::class)->get();
        app(SupplyService::class)->access($tenant);
        $branch = app(InventoryLedgerService::class)->branch($tenant, $request->user(), $request->header('X-Branch-ID'));
        $data = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $timezone = $branch->timezone ?: $tenant->timezone ?: config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d', $data['date_from'], $timezone)->startOfDay()->utc();
        $endsAt = CarbonImmutable::createFromFormat('Y-m-d', $data['date_to'], $timezone)->addDay()->startOfDay()->utc();
        abort_if($startsAt->diffInDays($endsAt) > 366, 422, 'El período máximo es de 366 días.');

        return [$tenant, $branch, $startsAt, $endsAt];
    }

    public function show(Request $request, InventoryIntelligenceReportService $reports)
    {
        [$tenant, $branch, $startsAt, $endsAt] = $this->context($request);

        return response()->json(['data' => $reports->build($tenant, $branch, $startsAt, $endsAt)]);
    }

    public function download(Request $request, InventoryIntelligenceReportService $reports, InventoryIntelligencePdfRenderer $pdf)
    {
        [$tenant, $branch, $startsAt, $endsAt] = $this->context($request);
        $document = $pdf->render($reports->build($tenant, $branch, $startsAt, $endsAt));

        return response($document, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="reporte-inventario-'.$branch->public_id.'-'.$startsAt->toDateString().'-'.$endsAt->subDay()->toDateString().'.pdf"',
        ]);
    }
}
