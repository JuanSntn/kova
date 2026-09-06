<?php

namespace App\Services\Business;

use App\Models\Tenant;
use App\Models\TenantBusinessProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class BusinessProfileService
{
    public function profile(
        Tenant $tenant,
    ): TenantBusinessProfile {
        $profile =
            TenantBusinessProfile::query()
            ->firstOrCreate([
                'tenant_id' =>
                $tenant->getKey(),
            ]);

        $profile->setRelation(
            'tenant',
            $tenant
        );

        return $profile;
    }

    public function update(
        Tenant $tenant,
        array $data,
        ?UploadedFile $logo = null,
    ): TenantBusinessProfile {
        $profile =
            $this->profile(
                $tenant
            );

        $oldLogo =
            $profile->logo_path;

        $newLogoPath = null;

        if ($logo !== null) {
            $extension =
                $logo->extension();

            $filename =
                Str::uuid()
                . '.'
                . $extension;

            $newLogoPath =
                $logo->storeAs(
                    'tenant-branding/'
                        . $tenant->public_id,
                    $filename,
                    'public',
                );
        }

        try {
            DB::transaction(
                function () use (
                    $tenant,
                    $profile,
                    $data,
                    $newLogoPath,
                ): void {
                    /*
                     * Nombre real del negocio.
                     *
                     * Vive en tenants.name.
                     */
                    $tenant
                        ->forceFill([
                            'name' =>
                            trim(
                                $data['name']
                            ),
                        ])
                        ->save();

                    $payload = [
                        'phone' =>
                        trim(
                            $data['phone']
                        ),

                        'public_email' =>
                        strtolower(
                            trim(
                                $data['public_email']
                            )
                        ),
                    ];

                    if (
                        $newLogoPath !==
                        null
                    ) {
                        $payload['logo_path'] =
                            $newLogoPath;
                    } elseif (
                        (bool) (
                            $data['remove_logo'] ?? false
                        )
                    ) {
                        $payload['logo_path'] =
                            null;
                    }

                    $profile
                        ->fill(
                            $payload
                        )
                        ->save();
                }
            );
        } catch (Throwable $exception) {
            if (
                $newLogoPath !== null
            ) {
                Storage::disk(
                    'public'
                )->delete(
                    $newLogoPath
                );
            }

            throw $exception;
        }

        /*
         * Eliminamos el logo anterior
         * únicamente cuando el nuevo
         * estado ya quedó guardado.
         */
        if (
            $oldLogo !== null
            && $oldLogo !==
            $profile
            ->refresh()
            ->logo_path
        ) {
            Storage::disk(
                'public'
            )->delete(
                $oldLogo
            );
        }

        $profile =
            $profile->refresh();

        $profile->setRelation(
            'tenant',
            $tenant->refresh()
        );

        return $profile;
    }

    /**
     * @return array{
     *     name: string,
     *     phone: ?string,
     *     public_email: ?string,
     *     logo_url: ?string,
     *     is_complete: bool,
     *     missing_fields: array<int, string>
     * }
     */
    public function data(
        Tenant $tenant,
    ): array {
        $profile =
            $this->profile(
                $tenant
            );

        $missing = [];

        if (
            blank(
                $tenant->name
            )
        ) {
            $missing[] =
                'name';
        }

        if (
            blank(
                $profile->phone
            )
        ) {
            $missing[] =
                'phone';
        }

        if (
            blank(
                $profile
                    ->public_email
            )
        ) {
            $missing[] =
                'public_email';
        }

        return [
            'name' =>
            (string) $tenant->name,

            'phone' =>
            $profile->phone,

            'public_email' =>
            $profile->public_email,

            'logo_url' =>
            $profile->logo_path
                ? Storage::disk(
                    'public'
                )->url(
                    $profile
                        ->logo_path
                )
                : null,

            'is_complete' =>
            $missing === [],

            'missing_fields' =>
            $missing,
        ];
    }

    public function isComplete(
        Tenant $tenant,
    ): bool {
        return $this
            ->data($tenant)['is_complete'];
    }
}
