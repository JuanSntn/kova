<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BusinessProfileResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        $tenant =
            $this->tenant;

        $missing = [];

        if (
            blank(
                $tenant?->name
            )
        ) {
            $missing[] =
                'name';
        }

        if (
            blank(
                $this->phone
            )
        ) {
            $missing[] =
                'phone';
        }

        if (
            blank(
                $this->public_email
            )
        ) {
            $missing[] =
                'public_email';
        }

        return [
            'name' =>
            $tenant?->name,

            'phone' =>
            $this->phone,

            'public_email' =>
            $this->public_email,

            'logo_url' =>
            $this->logo_path
                ? Storage::disk(
                    'public'
                )->url(
                    $this->logo_path
                )
                : null,

            'is_complete' =>
            $missing === [],

            'missing_fields' =>
            $missing,
        ];
    }
}
