<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Plans\PlanLimitService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ProductService
{
    public function __construct(
        private readonly PlanLimitService $limits,
    ) {}

    public function list(
        Tenant $tenant
    ): Collection {
        return $tenant
            ->products()
            ->with([
                'category',
                'tax',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(
        Tenant $tenant,
        array $data
    ): Product {
        /*
         * Validar límite del plan.
         */
        $this->limits
            ->assertCanCreateProduct($tenant);

        /*
         * Resolver la categoría mediante UUID público.
         */
        $category = $tenant
            ->categories()
            ->where(
                'public_id',
                $data['category_id']
            )
            ->firstOrFail();

        /*
         * Guardar imagen si fue enviada.
         */
        $imagePath = $this->storeImage(
            tenant: $tenant,
            image: $data['image'] ?? null,
        );

        $taxId = null;

        if (
            array_key_exists(
                'tax_id',
                $data
            ) &&
            $data['tax_id'] !== null
        ) {
            $taxId = $tenant
                ->taxes()
                ->where(
                    'public_id',
                    $data['tax_id']
                )
                ->firstOrFail()
                ->id;
        }

        $product = $tenant
            ->products()
            ->create([
                'category_id' =>
                $category->id,

                'tax_id' =>
                $taxId,

                'name' =>
                trim($data['name']),

                'description' =>
                $data['description'] ?? null,

                'price' =>
                $data['price'],

                'image_path' =>
                $imagePath,

                'sort_order' =>
                $data['sort_order'] ?? 0,

                'is_active' =>
                $data['is_active'] ?? true,
            ]);

        return $product->load([
            'category',
            'tax',
        ]);
    }

    public function findForTenant(
        Tenant $tenant,
        string $publicId
    ): Product {
        return $tenant
            ->products()
            ->with([
                'category',
                'tax',
            ])
            ->where(
                'public_id',
                $publicId
            )
            ->firstOrFail();
    }

    public function update(
        Tenant $tenant,
        Product $product,
        array $data
    ): Product {
        $attributes = [
            'category_id' =>
            $product->category_id,

            'tax_id' =>
            $product->tax_id,

            'name' =>
            $product->name,

            'description' =>
            $product->description,

            'price' =>
            $product->price,

            'image_path' =>
            $product->image_path,

            'sort_order' =>
            $product->sort_order,

            'is_active' =>
            $product->is_active,
        ];

        /*
         * Cambiar categoría.
         */
        if (
            array_key_exists(
                'category_id',
                $data
            )
        ) {
            $category = $tenant
                ->categories()
                ->where(
                    'public_id',
                    $data['category_id']
                )
                ->firstOrFail();

            $attributes['category_id'] =
                $category->id;
        }

        /*
         * Nombre.
         */
        if (
            array_key_exists(
                'name',
                $data
            )
        ) {
            $attributes['name'] =
                trim($data['name']);
        }

        /*
         * Descripción.
         */
        if (
            array_key_exists(
                'description',
                $data
            )
        ) {
            $attributes['description'] =
                $data['description'];
        }

        /*
         * Precio.
         */
        if (
            array_key_exists(
                'price',
                $data
            )
        ) {
            $attributes['price'] =
                $data['price'];
        }

        /*
         * Impuesto.
         *
         * Ausente: conservar.
         * null: usar impuesto predeterminado.
         * UUID: override específico.
         */
        if (
            array_key_exists(
                'tax_id',
                $data
            )
        ) {
            $attributes['tax_id'] =
                $data['tax_id'] === null
                    ? null
                    : $tenant
                        ->taxes()
                        ->where(
                            'public_id',
                            $data['tax_id']
                        )
                        ->firstOrFail()
                        ->id;
        }

        /*
         * Nueva fotografía.
         *
         * El frontend manda "image".
         * Laravel genera "image_path".
         */
        if (
            isset($data['image']) &&
            $data['image'] instanceof UploadedFile
        ) {
            $oldImagePath =
                $product->image_path;

            $newImagePath =
                $this->storeImage(
                    tenant: $tenant,
                    image: $data['image'],
                );

            $attributes['image_path'] =
                $newImagePath;

            /*
             * Actualizamos primero.
             */
            $product->update(
                $attributes
            );

            /*
             * Si todo salió bien,
             * eliminamos la imagen anterior.
             */
            if ($oldImagePath) {
                Storage::disk(
                    $this->imageDisk()
                )->delete(
                    $oldImagePath
                );
            }

            return $product
                ->refresh()
                ->load([
                    'category',
                    'tax',
                ]);
        }

        /*
         * Orden.
         */
        if (
            array_key_exists(
                'sort_order',
                $data
            )
        ) {
            $attributes['sort_order'] =
                $data['sort_order'];
        }

        /*
         * Estado.
         */
        if (
            array_key_exists(
                'is_active',
                $data
            )
        ) {
            $attributes['is_active'] =
                $data['is_active'];
        }

        $product->update(
            $attributes
        );

        return $product
            ->refresh()
            ->load([
                'category',
                'tax',
            ]);
    }

    public function delete(
        Product $product
    ): void {
        /*
         * Como Product usa SoftDeletes,
         * NO eliminamos la fotografía física aquí.
         *
         * Si después restauramos el producto,
         * queremos conservar su imagen.
         */
        $product->delete();
    }

    private function storeImage(
        Tenant $tenant,
        mixed $image
    ): ?string {
        if (
            !$image instanceof UploadedFile
        ) {
            return null;
        }

        return $image->store(
            "products/{$tenant->public_id}",
            $this->imageDisk(),
        );
    }

    private function imageDisk(): string
    {
        return config(
            'filesystems.product_images_disk',
            'public'
        );
    }
}
