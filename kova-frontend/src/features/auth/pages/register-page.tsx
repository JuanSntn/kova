import {
  BarChart3,
  CheckCircle2,
  CreditCard,
  FileText,
  LayoutDashboard,
  Package,
  Plus,
  ShoppingBag,
  ShoppingCart,
  TrendingUp,
  type LucideIcon,
} from 'lucide-react'

import { SignupForm } from '@/components/signup-form'
import { AuthLayout } from '@/features/auth/components/auth-layout'

export function RegisterPage() {
  return (
    <AuthLayout>
      <div className="grid h-full w-full lg:grid-cols-[46%_54%]">
        {/*
        |--------------------------------------------------------------------------
        | Form
        |--------------------------------------------------------------------------
        */}

        <section className="flex h-full items-center justify-center bg-background px-6 sm:px-10 lg:px-12 xl:px-16">
          <div className="w-full max-w-md">
            <SignupForm />
          </div>
        </section>

        {/*
        |--------------------------------------------------------------------------
        | Showcase
        |--------------------------------------------------------------------------
        */}

        <RegisterShowcase />
      </div>
    </AuthLayout>
  )
}

/*
|--------------------------------------------------------------------------
| Showcase
|--------------------------------------------------------------------------
*/

function RegisterShowcase() {
  return (
    <section className="relative hidden h-full overflow-hidden border-l bg-muted/20 lg:flex lg:flex-col">
      {/*
       * Decoración muy ligera.
       */}

      <div className="pointer-events-none absolute -right-40 -top-40 size-[420px] rounded-full bg-primary/8 blur-3xl" />

      <div className="pointer-events-none absolute -bottom-44 left-10 size-[420px] rounded-full bg-primary/5 blur-3xl" />

      <div className="relative z-10 flex h-full flex-col px-9 py-7 xl:px-12 xl:py-9">
        {/*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */}

        <div className="max-w-xl">
          <div className="flex items-center gap-2 text-xs font-medium text-primary">
            <CheckCircle2 className="size-4" />

            Empieza gratis
          </div>

          <h2 className="mt-4 max-w-lg text-3xl font-semibold leading-tight tracking-tight">
            Controla tu negocio desde un solo lugar.
          </h2>

          <p className="mt-3 max-w-lg text-sm leading-6 text-muted-foreground">
            Registra ventas, administra productos y consulta la información
            más importante de tu operación.
          </p>
        </div>

        {/*
        |--------------------------------------------------------------------------
        | Dashboard preview
        |--------------------------------------------------------------------------
        */}

        <div className="flex min-h-0 flex-1 items-center justify-center py-5">
          <div className="w-full max-w-[660px]">
            <div className="overflow-hidden rounded-2xl border bg-background shadow-xl shadow-black/5">
              <div className="grid h-[445px] grid-cols-[170px_minmax(0,1fr)]">
                {/*
                |--------------------------------------------------------------------------
                | Sidebar
                |--------------------------------------------------------------------------
                */}

                <MockSidebar />

                {/*
                |--------------------------------------------------------------------------
                | Dashboard
                |--------------------------------------------------------------------------
                */}

                <div className="min-w-0 bg-muted/20">
                  <div className="flex h-14 items-center justify-between border-b bg-background px-5">
                    <div>
                      <p className="text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
                        Resumen
                      </p>

                      <p className="text-sm font-semibold">
                        Hola, Juan
                      </p>
                    </div>

                    <div className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                      JL
                    </div>
                  </div>

                  <div className="space-y-4 p-5">
                    {/*
                    |--------------------------------------------------------------------------
                    | Quick actions
                    |--------------------------------------------------------------------------
                    */}

                    <div className="grid grid-cols-4 gap-2.5">
                      <QuickAction
                        icon={ShoppingCart}
                        label="Nueva venta"
                      />

                      <QuickAction
                        icon={ShoppingBag}
                        label="Ventas"
                      />

                      <QuickAction
                        icon={Package}
                        label="Producto"
                      />

                      <QuickAction
                        icon={FileText}
                        label="Ticket"
                      />
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Main metrics
                    |--------------------------------------------------------------------------
                    */}

                    <div className="grid grid-cols-[1.35fr_0.85fr] gap-3">
                      <SalesChartCard />

                      <RevenueCard />
                    </div>

                    {/*
                    |--------------------------------------------------------------------------
                    | Bottom metrics
                    |--------------------------------------------------------------------------
                    */}

                    <div className="grid grid-cols-3 gap-3">
                      <SmallMetric
                        icon={ShoppingCart}
                        label="Ventas"
                        value="72"
                      />

                      <SmallMetric
                        icon={Package}
                        label="Productos"
                        value="18"
                      />

                      <SmallMetric
                        icon={CreditCard}
                        label="Ticket promedio"
                        value="$117"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/*
            |--------------------------------------------------------------------------
            | Bottom copy
            |--------------------------------------------------------------------------
            */}

            <div className="mt-4">
              <p className="text-sm font-medium">
                Simple para empezar. Preparado para crecer.
              </p>

              <p className="mt-1 text-xs leading-5 text-muted-foreground">
                Crea tu negocio y registra tu primera venta en minutos.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

/*
|--------------------------------------------------------------------------
| Sidebar preview
|--------------------------------------------------------------------------
*/

function MockSidebar() {
  return (
    <aside className="flex h-full flex-col bg-slate-900 text-slate-300">
      <div className="px-4 py-5">
        <div className="flex items-center gap-2.5">
          <div className="grid size-8 grid-cols-2 gap-0.5 rounded-lg bg-primary p-1.5">
            <span className="rounded-[2px] bg-primary-foreground" />
            <span className="rounded-[2px] bg-primary-foreground/70" />
            <span className="rounded-[2px] bg-primary-foreground/70" />
            <span className="rounded-[2px] bg-primary-foreground" />
          </div>

          <span className="text-sm font-semibold text-white">
            Kova
          </span>
        </div>
      </div>

      <div className="px-3">
        <div className="mb-4 flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2.5 text-[11px] font-medium text-white">
          <Plus className="size-3.5" />

          Nueva venta
        </div>

        <nav className="space-y-1">
          <SidebarItem
            icon={LayoutDashboard}
            label="Inicio"
            active
          />

          <SidebarItem
            icon={Package}
            label="Productos"
          />

          <SidebarItem
            icon={ShoppingBag}
            label="Ventas"
          />

          <SidebarItem
            icon={BarChart3}
            label="Reportes"
          />

          <SidebarItem
            icon={CreditCard}
            label="Suscripción"
          />
        </nav>
      </div>

      <div className="mt-auto border-t border-white/10 px-4 py-4">
        <p className="text-[9px] text-slate-500">
          Kova POS
        </p>
      </div>
    </aside>
  )
}

function SidebarItem({
  icon: Icon,
  label,
  active = false,
}: {
  icon: LucideIcon
  label: string
  active?: boolean
}) {
  return (
    <div
      className={[
        'flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-[10px] font-medium',
        active
          ? 'bg-primary/15 text-primary'
          : 'text-slate-400',
      ].join(' ')}
    >
      <Icon className="size-3.5" />

      <span>
        {label}
      </span>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Quick actions
|--------------------------------------------------------------------------
*/

function QuickAction({
  icon: Icon,
  label,
}: {
  icon: LucideIcon
  label: string
}) {
  return (
    <div className="rounded-xl border bg-background p-3 shadow-sm">
      <div className="flex size-7 items-center justify-center rounded-lg bg-primary/10">
        <Icon className="size-3.5 text-primary" />
      </div>

      <p className="mt-2 text-[9px] font-medium leading-tight">
        {label}
      </p>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Sales chart
|--------------------------------------------------------------------------
*/

function SalesChartCard() {
  return (
    <div className="rounded-xl border bg-background p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[10px] font-medium text-muted-foreground">
            Ventas
          </p>

          <p className="mt-1 text-2xl font-semibold tracking-tight">
            $8,450
          </p>
        </div>

        <div className="flex items-center gap-1 text-[9px] font-medium text-emerald-600">
          <TrendingUp className="size-3" />

          12.4%
        </div>
      </div>

      <div className="relative mt-5 h-[115px] overflow-hidden">
        <div className="absolute inset-0 flex flex-col justify-between">
          <div className="border-t border-dashed" />
          <div className="border-t border-dashed" />
          <div className="border-t border-dashed" />
          <div className="border-t border-dashed" />
        </div>

        <svg
          viewBox="0 0 320 110"
          preserveAspectRatio="none"
          className="absolute inset-0 size-full overflow-visible"
          aria-hidden
        >
          <path
            d="
              M 0 82
              C 28 70, 45 75, 68 67
              C 95 57, 105 35, 130 46
              C 156 58, 163 88, 190 73
              C 216 60, 226 28, 250 37
              C 274 45, 284 24, 320 30
            "
            fill="none"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            className="text-primary"
          />
        </svg>
      </div>

      <div className="mt-2 flex justify-between text-[8px] text-muted-foreground">
        <span>01 Ago</span>
        <span>08 Ago</span>
        <span>15 Ago</span>
        <span>22 Ago</span>
      </div>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Revenue
|--------------------------------------------------------------------------
*/

function RevenueCard() {
  return (
    <div className="rounded-xl border bg-background p-4 shadow-sm">
      <p className="text-[10px] font-medium text-muted-foreground">
        Transacciones
      </p>

      <p className="mt-1 text-2xl font-semibold tracking-tight">
        72
      </p>

      <div className="mt-5 space-y-4">
        <PaymentRow
          label="Efectivo"
          value="52%"
          percentage={52}
        />

        <PaymentRow
          label="Tarjeta"
          value="35%"
          percentage={35}
        />

        <PaymentRow
          label="Transferencia"
          value="13%"
          percentage={13}
        />
      </div>
    </div>
  )
}

function PaymentRow({
  label,
  value,
  percentage,
}: {
  label: string
  value: string
  percentage: number
}) {
  return (
    <div>
      <div className="flex justify-between gap-2 text-[9px]">
        <span className="text-muted-foreground">
          {label}
        </span>

        <span className="font-medium">
          {value}
        </span>
      </div>

      <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
        <div
          className="h-full rounded-full bg-primary"
          style={{
            width: `${percentage}%`,
          }}
        />
      </div>
    </div>
  )
}

/*
|--------------------------------------------------------------------------
| Small metrics
|--------------------------------------------------------------------------
*/

function SmallMetric({
  icon: Icon,
  label,
  value,
}: {
  icon: LucideIcon
  label: string
  value: string
}) {
  return (
    <div className="rounded-xl border bg-background p-3 shadow-sm">
      <div className="flex items-center gap-2">
        <div className="flex size-7 items-center justify-center rounded-lg bg-primary/10">
          <Icon className="size-3.5 text-primary" />
        </div>

        <p className="text-[9px] text-muted-foreground">
          {label}
        </p>
      </div>

      <p className="mt-2 text-lg font-semibold tracking-tight">
        {value}
      </p>
    </div>
  )
}