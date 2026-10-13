<?php

namespace App\Services\Navigation;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;

final class NavigationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function sectionsFor(
        User $user,
        Tenant $tenant,
    ): array {
        /*
         * El tenant no necesita consultarse directamente aquí todavía.
         *
         * ResolveTenant ya:
         * - validó membership
         * - validó tenant activo
         * - configuró Spatie Team con setPermissionsTeamId()
         *
         * Por eso $user->can() ya trabaja dentro
         * del tenant actualmente seleccionado.
         *
         * Conservamos $tenant porque posteriormente
         * NavigationService también podrá evaluar
         * entitlements/capacidades del plan.
         */

        return collect(config('navigation', []))
            ->sortBy('order')
            ->map(function (array $section) use ($user): array {
                return [
                    'key' => $section['key'],
                    'label' => $section['label'] ?? null,
                    'order' => $section['order'] ?? 0,

                    'items' => $this->visibleItems(
                        items: collect(
                            $section['items'] ?? []
                        ),
                        user: $user,
                    ),
                ];
            })

            /*
             * No mandar secciones vacías.
             */
            ->filter(
                fn (array $section): bool => $section['items'] !== []
            )

            ->values()
            ->all();
    }

    /**
     * Resuelve los elementos visibles de navegación.
     *
     * Soporta navegación anidada:
     *
     * Menú
     * ├── Productos
     * └── Categorías
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function visibleItems(
        Collection $items,
        User $user,
    ): array {
        return $items
            ->sortBy('order')

            /*
             * Primero comprobar el permiso
             * del propio módulo.
             */
            ->filter(
                fn (array $item): bool => $this->userCanAccessItem(
                    user: $user,
                    item: $item,
                )
            )

            /*
             * Construir el objeto público.
             */
            ->map(function (
                array $item
            ) use ($user): ?array {
                $hasChildren = array_key_exists(
                    'children',
                    $item
                );

                $children = [];

                if ($hasChildren) {
                    $children =
                        $this->visibleItems(
                            items: collect(
                                $item['children'] ?? []
                            ),
                            user: $user,
                        );

                    /*
                     * Si es un módulo contenedor
                     * y el usuario no puede acceder
                     * a ninguno de sus hijos,
                     * tampoco mostramos el padre.
                     */
                    if ($children === []) {
                        return null;
                    }
                }

                /*
                 * Solo exponemos información
                 * necesaria para React.
                 *
                 * Nunca:
                 * - permission
                 * - role
                 * - tenant_id
                 * - abilities
                 */
                $navigationItem = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'order' => $item['order'] ?? 0,
                ];

                /*
                 * Son opcionales porque un child
                 * usado como tab podría no necesitarlos.
                 */
                if (isset($item['path'])) {
                    $navigationItem['path'] =
                        $item['path'];
                }

                if (isset($item['icon'])) {
                    $navigationItem['icon'] =
                        $item['icon'];
                }

                if ($hasChildren) {
                    $navigationItem['children'] =
                        $children;
                }

                return $navigationItem;
            })

            /*
             * Quitar padres cuyos children
             * quedaron vacíos.
             */
            ->filter()

            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function userCanAccessItem(
        User $user,
        array $item,
    ): bool {
        $context = app(CurrentTenant::class);
        if (($item['key'] ?? null) === 'cash') {
            if (! $context->isResolved() || ! $user->can('cash.view')) { return false; }
            $tenant = $context->get();
            $branches = app(\App\Services\Branches\BranchAccessService::class)->historicalBranchIds($user, $tenant, 'cash.view');
            $history = \Illuminate\Support\Facades\DB::table('cash_shifts')->where('tenant_id', $tenant->id)->whereIn('branch_id', $branches);
            if (! $user->can('cash.manage')) { $history->where('responsible_user_id', $user->id); }
            return app(EntitlementService::class)->allowsCash($tenant) || $history->exists();
        }
        $feature = $item['feature'] ?? null;
        if ($feature && (! $context->isResolved() || ! app(EntitlementService::class)->allows($context->get(), $feature))) {
            return false;
        }

        $permission =
            $item['permission'] ?? null;

        /*
         * null significa:
         * cualquier miembro válido del tenant.
         */
        if ($permission === null) {
            return true;
        }

        /*
         * Debido a que ResolveTenant ya estableció
         * el team actual de Spatie, can() se evalúa
         * contra el tenant correcto.
         */
        return $user->can($permission);
    }
}
