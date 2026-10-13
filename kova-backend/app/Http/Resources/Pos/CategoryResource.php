<?php

namespace App\Http\Resources\Pos;

use App\Services\Subscriptions\CatalogAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = app(CatalogAccessService::class)
            ->categoryCapabilities($this->resource);

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'billing_enabled' => $access['billing_enabled'],
            'locked_by_plan' => $access['locked_by_plan'],
            'can_edit' => $access['can_edit'],
            'can_delete' => $access['can_delete'],
            'can_use' => $access['can_use'],
            'lock_reason' => $access['lock_reason'],
        ];
    }
}
