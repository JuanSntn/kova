<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryExcelService;
use App\Services\Inventory\SupplyService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;

class InventoryExcelController extends Controller
{
    private function tenant(Request $r, bool $write)
    {
        abort_unless($r->user()->can($write ? 'inventory.manage' : 'inventory.view'), 403);
        $t = app(CurrentTenant::class)->get();
        app(SupplyService::class)->access($t, $write);

        return $t;
    }

    public function download(Request $r, InventoryExcelService $service)
    {
        $t = $this->tenant($r, false);
        $template = $r->boolean('template');

        return response()->download($service->export($t, $template), $template ? 'plantilla-insumos.xlsx' : 'insumos.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    public function preview(Request $r, InventoryExcelService $service)
    {
        $t = $this->tenant($r, true);
        $r->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);

        return response()->json(['data' => $service->preview($t, $r->user(), $r->file('file')->getRealPath())]);
    }

    public function commit(Request $r, string $import, InventoryExcelService $service)
    {
        return response()->json(['data' => $service->commit($this->tenant($r, true), $r->user(), $import)]);
    }
}
