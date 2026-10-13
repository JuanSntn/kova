<?php

namespace App\Http\Resources\Pos;

use App\Services\Subscriptions\CatalogAccessService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(
            config(
                'filesystems.product_images_disk',
                'public'
            )
        );

        $defaultTax = $request->attributes->get(
            'default_tax'
        );

        $effectiveTax = $this->tax_id !== null
            ? $this->tax
            : $defaultTax;

        $access = app(CatalogAccessService::class)
            ->productCapabilities($this->resource);

        return [
            'id' => $this->public_id,

            'name' => $this->name,
            'modifier_groups' => array_map(fn ($g) => [...$g, 'options' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'price' => $o['price']], $g['options'])], app(\App\Services\Pos\ModifierService::class)->groups(app(\App\Tenancy\CurrentTenant::class)->get(), $this->resource)),

            'description' => $this->description,

            'price' => $this->getAttribute('effective_price') ?? $this->price,

            'image_path' => $this->image_path
                ? $disk->url($this->image_path)
                : null,

            'sort_order' => $this->sort_order,

            'is_active' => $this->is_active,

            'preparation_station' => $this->preparation_station,

            'billing_enabled' => $access['billing_enabled'],

            'locked_by_plan' => $access['locked_by_plan'],

            'can_edit' => $access['can_edit'],

            'can_delete' => $access['can_delete'],

            'can_use' => $access['can_use'],

            'branch_available' => $this->getAttribute('branch_available') ?? true,

            'lock_reason' => $access['lock_reason'],

            'fiscal' => [
                'product_service_code' => $this->sat_product_service_code,
                'unit_code' => $this->sat_unit_code,
                'unit_name' => $this->sat_unit_name,
                'tax_object_code' => $this->sat_tax_object_code,
            ],

            'category' => [
                'id' => $this->category->public_id,
                'name' => $this->category->name,
                'billing_enabled' => (bool) $this->category->billing_enabled,
                'locked_by_plan' => ! (bool) $this->category->billing_enabled,
            ],

            'tax' => [
                'mode' => $this->tax_id === null
                    ? 'default'
                    : 'specific',

                'id' => $this->tax_id === null
                    ? null
                    : $this->tax?->public_id,

                'effective' => $effectiveTax
                    ? [
                        'id' => $effectiveTax->public_id,
                        'name' => $effectiveTax->name,
                        'code' => $effectiveTax->code,
                        'factor_type' => $effectiveTax->factor_type,
                        'rate' => $effectiveTax->rate,
                    ]
                    : null,
            ],
        ];
    }
}
