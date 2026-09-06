<?php

namespace App\Http\Resources\Pos;

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

        return [
            'id' => $this->public_id,

            'name' => $this->name,

            'description' => $this->description,

            'price' => $this->price,

            'image_path' => $this->image_path
                ? $disk->url($this->image_path)
                : null,

            'sort_order' => $this->sort_order,

            'is_active' => $this->is_active,

            'category' => [
                'id' => $this->category->public_id,
                'name' => $this->category->name,
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
