import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArchiveIcon, CircleAlertIcon, Download, ListChecksIcon, ShoppingBagIcon, TriangleAlertIcon } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Control } from './inventory-ui'
import { headers } from './workspace-api'
import { errorMessage, quantity } from './utils'

type Stock = { id: string; name: string; category: string; unit: string; quantity: string; minimum: string | null; ideal: string | null; below_minimum: boolean; negative: boolean; suggested_restock: string | null }
type Report = {
  generated_at: string
  branch: { id: string; name: string }
  period: { date_from: string; date_to: string; timezone: string }
  summary: { supplies: number; below_minimum: number; negative_stock: number; sales_total: number; sales_count: number; average_ticket: number; physical_count_days: number }
  alerts: string[]
  shortages: Stock[]
  stock_snapshot: Stock[]
  activity_by_type: { type: string; operations: number; lines: number }[]
  top_consumed: { id: string; name: string; unit: string; quantity: string }[]
  top_products: { name: string; units: number; sales_total: number }[]
}

function dateBefore(days: number) {
  const date = new Date()
  date.setDate(date.getDate() - days)
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

const money = (value: number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value)

export function InventoryReportPanel({ tenant, branch }: { tenant: string; branch: string }) {
  const [dateFrom, setDateFrom] = useState(() => dateBefore(29))
  const [dateTo, setDateTo] = useState(() => dateBefore(0))
  const [downloading, setDownloading] = useState(false)
  const validRange = !!dateFrom && !!dateTo && dateFrom <= dateTo
  const query = useQuery({
    queryKey: ['inventory', tenant, branch, 'intelligence-report', dateFrom, dateTo],
    enabled: !!tenant && !!branch && validRange,
    queryFn: async () => (await api.get<{ data: Report }>('/api/inventory/report', { headers: headers(tenant, branch), params: { date_from: dateFrom, date_to: dateTo } })).data.data,
  })

  async function download() {
    if (!validRange || downloading) return
    setDownloading(true)
    try {
      const response = await api.get('/api/inventory/report/pdf', { headers: headers(tenant, branch), params: { date_from: dateFrom, date_to: dateTo }, responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = `reporte-inventario-${dateFrom}-${dateTo}.pdf`
      link.click()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
    } finally { setDownloading(false) }
  }

  if (!branch) return <p className="text-sm text-muted-foreground">Selecciona una sucursal para generar su reporte.</p>

  return <div className="space-y-5">
    <header>
      <h1 className="text-xl font-semibold tracking-tight">Resumen de inventario</h1>
      <p className="mt-1 text-sm text-muted-foreground">Consulta existencias y actividad de {query.data?.branch.name ?? 'tu sucursal'} durante el período seleccionado.</p>
    </header>

    <section aria-label="Período del reporte" className="border-y py-3">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div className="grid max-w-2xl grid-cols-2 gap-3">
          <Control id="report-date-from" label="Desde">
            <Input id="report-date-from" className="h-10 w-full" type="date" value={dateFrom} max={dateTo || undefined} onChange={event => setDateFrom(event.target.value)} />
          </Control>
          <Control id="report-date-to" label="Hasta">
            <Input id="report-date-to" className="h-10 w-full" type="date" value={dateTo} min={dateFrom || undefined} max={dateBefore(0)} onChange={event => setDateTo(event.target.value)} />
          </Control>
        </div>
        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
          <Button type="button" variant="outline" size="sm" onClick={() => { setDateFrom(dateBefore(29)); setDateTo(dateBefore(0)) }}>Últimos 30 días</Button>
          <Button size="sm" onClick={() => void download()} disabled={!validRange || downloading || query.isPending}>{downloading ? 'Generando PDF…' : <><Download aria-hidden="true" /> Descargar PDF</>}</Button>
        </div>
      </div>
    </section>

    {!validRange && <p role="alert" className="text-sm text-destructive">Selecciona un período válido.</p>}
    {query.isPending ? <p role="status" className="text-sm text-muted-foreground">Preparando reporte…</p> : query.isError ? <p role="alert" className="text-sm text-destructive">{errorMessage(query.error)}</p> : query.data && <ReportContent report={query.data} />}
  </div>
}

function ReportContent({ report }: { report: Report }) {
  const operations = report.activity_by_type.reduce((total, activity) => total + activity.operations, 0)
  const attention = report.stock_snapshot.filter(stock => stock.below_minimum || stock.negative).length

  return <div className="space-y-5">
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <MetricCard title="Insumos registrados" value={String(report.summary.supplies)} subtitle="Configurados para esta sucursal" icon={<ArchiveIcon className="size-5" />} />
      <MetricCard title="Requieren atención" value={String(attention)} subtitle={attention === 1 ? 'Insumo por revisar' : 'Insumos por revisar'} icon={<TriangleAlertIcon className="size-5" />} />
      <MetricCard title="Movimientos" value={String(operations)} subtitle={`${report.summary.physical_count_days} ${report.summary.physical_count_days === 1 ? 'día con conteo físico' : 'días con conteo físico'}`} icon={<ListChecksIcon className="size-5" />} />
      <MetricCard title="Ventas del período" value={money(report.summary.sales_total)} subtitle={`${report.summary.sales_count} ${report.summary.sales_count === 1 ? 'venta cobrada' : 'ventas cobradas'}`} icon={<ShoppingBagIcon className="size-5" />} />
    </div>

    <div className="grid gap-4 sm:grid-cols-3">
      <ShortagesCard className="sm:col-span-2" rows={report.shortages} />
      <ActivityCard activity={report.activity_by_type} />
    </div>

    {report.alerts.length > 0 && <section aria-label="Revisión recomendada" className="border-y py-4"><p className="text-sm font-medium">Revisión recomendada</p><ul className="mt-2 space-y-1 text-sm text-muted-foreground">{report.alerts.map(alert => <li key={alert}>{alert}</li>)}</ul></section>}

    <div className="grid gap-4 lg:grid-cols-2">
      <RankedListCard title="Más consumidos" empty="Sin consumos registrados." rows={report.top_consumed.map(item => ({ name: item.name, detail: `${quantity(item.quantity)} ${item.unit}` }))} />
      <RankedListCard title="Productos más vendidos" empty="Sin ventas cobradas en este período." rows={report.top_products.map(item => ({ name: item.name, detail: `${item.units} ${item.units === 1 ? 'unidad' : 'unidades'}`, value: money(item.sales_total) }))} />
    </div>

    <section className="space-y-3">
      <div>
        <h2 className="text-base font-semibold">Existencias actuales</h2>
        <p className="mt-1 text-sm text-muted-foreground">Fotografía de {report.branch.name} al generar el reporte. El período aplica a ventas y movimientos.</p>
      </div>
      <StockTable rows={report.stock_snapshot} empty="No hay insumos registrados." />
    </section>
  </div>
}

function MetricCard({ title, value, subtitle, icon }: { title: string; value: string; subtitle: string; icon: ReactNode }) {
  return <Card><CardContent className="p-4"><div className="flex items-start justify-between gap-4"><div className="min-w-0"><p className="text-xs text-muted-foreground">{title}</p><p className="mt-1 truncate text-xl font-semibold tracking-tight tabular-nums">{value}</p><p className="mt-1 text-xs text-muted-foreground">{subtitle}</p></div><div className="shrink-0 text-muted-foreground">{icon}</div></div></CardContent></Card>
}

function ShortagesCard({ rows, className }: { rows: Stock[]; className?: string }) {
  return <Card className={className}><CardHeader><CardTitle className="text-sm">Faltantes para surtir</CardTitle><p className="text-xs text-muted-foreground">Cantidad sugerida para llegar al ideal, o al mínimo cuando no hay ideal.</p></CardHeader><CardContent>{rows.length === 0 ? <p className="py-12 text-center text-sm text-muted-foreground">No hay insumos por debajo de su mínimo.</p> : <div className="divide-y">{rows.slice(0, 5).map(stock => <div key={stock.id} className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"><div className="min-w-0"><p className="truncate text-sm font-medium">{stock.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{stock.category} · Hay {quantity(stock.quantity)} {stock.unit}</p></div><div className="shrink-0 text-right"><p className="text-sm font-semibold tabular-nums">{stock.suggested_restock === null ? '—' : `${quantity(stock.suggested_restock)} ${stock.unit}`}</p><p className="mt-0.5 text-xs text-muted-foreground">por surtir</p></div></div>)}</div>}</CardContent></Card>
}

function ActivityCard({ activity }: { activity: Report['activity_by_type'] }) {
  const maximum = Math.max(...activity.map(item => item.operations), 1)
  return <Card><CardHeader><CardTitle className="text-sm">Actividad del período</CardTitle></CardHeader><CardContent className="space-y-5">{activity.length === 0 ? <p className="py-12 text-center text-sm text-muted-foreground">Sin movimientos registrados.</p> : activity.map(item => <div key={item.type}><div className="mb-2 flex items-end justify-between gap-3"><div><p className="text-sm font-medium">{movementLabel(item.type)}</p><p className="text-xs text-muted-foreground">{item.lines} {item.lines === 1 ? 'insumo afectado' : 'insumos afectados'}</p></div><span className="text-sm font-semibold tabular-nums">{item.operations}</span></div><div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${(item.operations / maximum) * 100}%` }} /></div></div>)}</CardContent></Card>
}

function RankedListCard({ title, rows, empty }: { title: string; rows: { name: string; detail: string; value?: string }[]; empty: string }) {
  return <Card><CardHeader><CardTitle className="text-sm">{title}</CardTitle></CardHeader><CardContent>{rows.length === 0 ? <p className="py-12 text-center text-sm text-muted-foreground">{empty}</p> : <div className="divide-y">{rows.slice(0, 5).map((row, index) => <div key={`${row.name}-${index}`} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0"><span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-medium tabular-nums">{index + 1}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-medium">{row.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{row.detail}</p></div>{row.value && <p className="shrink-0 text-sm font-semibold tabular-nums">{row.value}</p>}</div>)}</div>}</CardContent></Card>
}

function movementLabel(type: string) { return ({ initial: 'Inventario inicial', purchase: 'Compra', adjustment: 'Ajuste', sale: 'Venta', waste: 'Merma', production: 'Producción', transfer: 'Transferencia' } as Record<string, string>)[type] ?? type }

function StockTable({ rows, empty }: { rows: Stock[]; empty: string }) {
  return <div className="overflow-hidden rounded-xl border bg-card"><Table><TableHeader><TableRow className="bg-muted/40 hover:bg-muted/40"><TableHead className="pl-5">Insumo</TableHead><TableHead className="text-right">Existencia</TableHead><TableHead className="text-right">Mínimo / ideal</TableHead><TableHead>Estado</TableHead></TableRow></TableHeader><TableBody>{rows.map(stock => <TableRow key={stock.id}><TableCell className="py-4 pl-5"><p className="font-medium">{stock.name}</p><p className="mt-1 text-xs text-muted-foreground">{stock.category}</p></TableCell><TableCell className="text-right tabular-nums">{quantity(stock.quantity)} {stock.unit}</TableCell><TableCell className="text-right tabular-nums">{quantity(stock.minimum)} / {quantity(stock.ideal)} <span className="text-muted-foreground">{stock.unit}</span></TableCell><TableCell className={stock.negative ? 'text-destructive' : stock.below_minimum ? 'text-orange-700' : 'text-muted-foreground'}>{stock.negative ? <span className="inline-flex items-center gap-1.5"><CircleAlertIcon className="size-3.5" />Existencia negativa</span> : stock.below_minimum ? <span className="inline-flex items-center gap-1.5"><TriangleAlertIcon className="size-3.5" />Por debajo del mínimo</span> : 'Disponible'}</TableCell></TableRow>)}</TableBody></Table>{rows.length === 0 && <p className="p-6 text-sm text-muted-foreground">{empty}</p>}</div>
}
