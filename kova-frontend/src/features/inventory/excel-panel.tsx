import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Control } from './inventory-ui'
import { errorMessage } from './utils'
import { downloadExcel, mutation } from './workspace-api'

type Preview = { id: string; count: number; committed: boolean; items?: string[] }
export function ExcelPanel({ tenant, manage }: { tenant: string; manage: boolean }) {
  const client = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<Preview | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function run(action: () => Promise<void>) { if (busy) return; setBusy(true); setError(''); try { await action() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) } }
  return <div className="space-y-6">
    <header><h1 className="text-xl font-semibold">Importar y exportar Excel</h1><p className="mt-1 text-sm text-muted-foreground">Agrega insumos en bloque o descarga tu catálogo actual.</p></header>
    <div className="flex flex-wrap gap-3"><Button variant="outline" disabled={busy} onClick={() => void run(() => downloadExcel(tenant, true))}>Descargar plantilla</Button><Button variant="outline" disabled={busy} onClick={() => void run(() => downloadExcel(tenant, false))}>Exportar insumos</Button></div>
    {manage && <section className="max-w-2xl space-y-5 rounded-xl border bg-card p-6">
      <h2 className="text-base font-semibold">Importar insumos nuevos</h2><p className="text-sm text-muted-foreground">Una fila por presentación. Repite los datos del insumo si tiene varias. No actualiza insumos existentes ni agrega existencias.</p>
      <Control id="inventory-excel" label="Archivo Excel (.xlsx)"><Input id="inventory-excel" type="file" accept=".xlsx" disabled={busy} onChange={e => { setFile(e.target.files?.[0] ?? null); setPreview(null) }} /></Control>
      <p className="text-xs text-muted-foreground">Hasta 2,000 filas y 5 MB. Revisa el archivo antes de confirmar.</p>
      <Button disabled={!file || busy} onClick={() => void run(async () => { const form = new FormData(); form.append('file', file!); setPreview((await mutation(tenant, 'imports', form)).data) })}>{busy ? 'Procesando…' : 'Revisar archivo'}</Button>
      {preview && <div className="space-y-3 border-t pt-4"><h3 className="font-medium">{preview.committed ? 'Importación completada' : 'Revisa la importación'}</h3><p className="text-sm">{preview.count} insumos</p>{!preview.committed && <><ul className="max-h-48 overflow-auto text-sm text-muted-foreground">{preview.items?.map(name => <li key={name}>{name}</li>)}</ul><Button disabled={busy} onClick={() => void run(async () => { setPreview((await mutation(tenant, 'imports/' + preview.id + '/commit', {})).data); await client.invalidateQueries({ queryKey: ['inventory'] }) })}>Confirmar importación</Button></>}</div>}
    </section>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
  </div>
}
