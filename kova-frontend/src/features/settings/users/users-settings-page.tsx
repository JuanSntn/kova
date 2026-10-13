import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CopyIcon, EyeIcon, EyeOffIcon, KeyRoundIcon, PlusIcon, PowerIcon, Trash2Icon, UserRoundIcon } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { useSession } from '@/features/auth/session-context'
import { getBranches } from '@/features/settings/branches/api'
import { activateUser, createUser, deleteUser, getUsers, resetUserPassword, suspendUser, syncUserBranches, updateUser, userApiError, type StaffPayload, type TeamMember } from './api'

const roleLabels = { owner: 'Dueño', cashier: 'Caja', waiter: 'Mesero', kitchen: 'Cocina', bar: 'Barra' }
const emptyForm: StaffPayload = { name: '', username: '', password: '', password_confirmation: '', role: 'waiter', branch_ids: [] }

function passwordRules(password: string) {
  return {
    length: password.length >= 8,
    uppercase: /\p{Lu}/u.test(password),
    lowercase: /\p{Ll}/u.test(password),
    number: /\p{N}/u.test(password),
  }
}

function hasValidPassword(password: string) {
  return Object.values(passwordRules(password)).every(Boolean)
}

export function UsersSettingsPage() {
  const { activeTenantId } = useSession()
  const client = useQueryClient()
  const key = ['tenants', activeTenantId, 'settings-users']
  const users = useQuery({ queryKey: key, queryFn: getUsers, enabled: Boolean(activeTenantId) })
  const branches = useQuery({ queryKey: ['tenants', activeTenantId, 'branches'], queryFn: getBranches, enabled: Boolean(activeTenantId) })
  const [editing, setEditing] = useState<TeamMember | 'new' | null>(null)
  const [form, setForm] = useState<StaffPayload>(emptyForm)
  const [passwordFor, setPasswordFor] = useState<TeamMember | null>(null)
  const [newPassword, setNewPassword] = useState('')
  const [showFormPassword, setShowFormPassword] = useState(false)
  const [showNewPassword, setShowNewPassword] = useState(false)
  const operable = useMemo(() => branches.data?.branches.filter(branch => branch.isActive && branch.billingEnabled) ?? [], [branches.data])

  const refresh = () => client.invalidateQueries({ queryKey: key })
  const save = useMutation({
    mutationFn: async () => {
      if (editing === 'new') return createUser(form)
      if (!editing) return
      await updateUser(editing.id, { name: form.name, username: form.username, role: form.role })
      return syncUserBranches(editing.id, form.branch_ids)
    },
    onSuccess: async () => { toast.success(editing === 'new' ? 'Usuario creado.' : 'Usuario actualizado.'); setEditing(null); await refresh() },
    onError: error => toast.error(userApiError(error)),
  })
  const stateMutation = useMutation({
    mutationFn: (member: TeamMember) => member.status === 'active' ? suspendUser(member.id) : activateUser(member.id),
    onSuccess: async () => { toast.success('Estado actualizado.'); await refresh() },
    onError: error => toast.error(userApiError(error)),
  })
  const deleteMutation = useMutation({
    mutationFn: (member: TeamMember) => deleteUser(member.id),
    onSuccess: async () => { toast.success('Usuario eliminado del equipo.'); await refresh() },
    onError: error => toast.error(userApiError(error)),
  })
  const passwordMutation = useMutation({
    mutationFn: () => passwordFor ? resetUserPassword(passwordFor.id, newPassword) : Promise.resolve(),
    onSuccess: () => { toast.success('Contraseña actualizada. Las sesiones anteriores se cerraron.'); setPasswordFor(null); setNewPassword(''); setShowNewPassword(false) },
    onError: error => toast.error(userApiError(error)),
  })

  function openNew() { setForm({ ...emptyForm, branch_ids: operable[0] ? [operable[0].id] : [] }); setShowFormPassword(false); setEditing('new') }
  function openEdit(member: TeamMember) {
    setForm({ name: member.name, username: member.username ?? '', password: '', password_confirmation: '', role: member.role === 'cashier' || member.role === 'kitchen' || member.role === 'bar' ? member.role : 'waiter', branch_ids: member.branches.map(branch => branch.id) })
    setEditing(member)
  }

  if (users.isPending) return <div className="space-y-4"><Skeleton className="h-10 w-56" /><Skeleton className="h-72 w-full" /></div>
  const usage = users.data?.meta.users

  return <div className="space-y-7">
    <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div><h1 className="text-xl font-semibold tracking-tight">Equipo</h1><p className="mt-1 text-sm text-muted-foreground">Administra quién puede entrar y en qué sucursales trabaja.</p></div>
      {usage?.can_create ? <Button onClick={openNew}><PlusIcon className="size-4" />Agregar usuario</Button> : null}
    </header>

    <div className="flex items-center justify-between border-y py-4">
      <div><p className="text-sm font-medium">Usuarios activos</p><p className="text-sm text-muted-foreground">El dueño también ocupa un lugar.</p></div>
      <p className="text-sm font-semibold">{usage?.current ?? 0} de {usage?.unlimited ? 'ilimitados' : usage?.limit ?? 0}</p>
    </div>
    {!usage?.can_create && !usage?.unlimited ? <p className="rounded-md bg-muted px-4 py-3 text-sm">Tu plan alcanzó el límite de usuarios activos. Suspende un usuario o cambia de plan para agregar otro.</p> : null}

    <section className="overflow-hidden rounded-lg border" aria-label="Usuarios del equipo">
      {users.data?.data.map(member => <div key={member.id} className="flex flex-col gap-3 border-b p-4 last:border-b-0 sm:flex-row sm:items-center">
        <div className="flex min-w-0 flex-1 items-center gap-3"><span className="grid size-9 place-items-center rounded-full bg-muted"><UserRoundIcon className="size-4" /></span><div className="min-w-0"><p className="truncate text-sm font-medium">{member.name}</p><p className="truncate text-xs text-muted-foreground">{member.username ? `@${member.username}` : 'Cuenta principal'} · {member.role ? roleLabels[member.role] : 'Sin rol'}</p></div></div>
        <div className="flex flex-wrap items-center gap-1.5"><span className="text-xs text-muted-foreground">Sucursales:</span>{member.account_type === 'tenant_local' ? member.branches.map(branch => <Badge key={branch.id} variant="secondary">{branch.name}</Badge>) : <Badge variant="secondary">Todas las sucursales</Badge>}<Badge variant={member.status === 'active' ? 'default' : 'outline'}>{member.status === 'active' ? 'Activo' : 'Suspendido'}</Badge></div>
        {member.account_type === 'tenant_local' ? <div className="flex gap-2 sm:ml-3"><Button size="sm" variant="outline" onClick={() => openEdit(member)}>Editar</Button><Button size="icon-sm" variant="outline" aria-label="Restablecer contraseña" onClick={() => setPasswordFor(member)}><KeyRoundIcon className="size-4" /></Button><Button size="icon-sm" variant="outline" aria-label={member.status === 'active' ? 'Suspender usuario' : 'Reactivar usuario'} onClick={() => stateMutation.mutate(member)}><PowerIcon className="size-4" /></Button><Button size="icon-sm" variant="outline" className="text-destructive hover:text-destructive" aria-label="Eliminar usuario" disabled={deleteMutation.isPending} onClick={() => { if (window.confirm(`¿Eliminar a ${member.name} del equipo? Se conservará su historial operativo.`)) deleteMutation.mutate(member) }}><Trash2Icon className="size-4" /></Button></div> : null}
      </div>)}</section>

    <section className="space-y-3"><div><h2 className="text-base font-semibold">Acceso del equipo</h2><p className="text-sm text-muted-foreground">Cada sucursal tiene su propia dirección de acceso.</p></div><div className="grid gap-3 lg:grid-cols-2">{users.data?.meta.staff_login.branches.map(branch => <Card key={branch.id}><CardHeader className="pb-3"><CardTitle className="text-sm">{branch.name}</CardTitle></CardHeader><CardContent className="flex items-center gap-4"><QRCodeSVG value={branch.url} size={72} aria-label={`QR de acceso para ${branch.name}`} /><div className="min-w-0 flex-1"><p className="truncate text-xs text-muted-foreground">{branch.url}</p><Button className="mt-3" size="sm" variant="outline" onClick={() => { void navigator.clipboard.writeText(branch.url); toast.success('Enlace copiado.') }}><CopyIcon className="size-4" />Copiar enlace</Button></div></CardContent></Card>)}</div></section>

    <Sheet open={editing !== null} onOpenChange={open => { if (!open && !save.isPending) setEditing(null) }}><SheetContent className="overflow-y-auto sm:max-w-md"><SheetHeader><SheetTitle>{editing === 'new' ? 'Agregar usuario' : 'Editar usuario'}</SheetTitle><SheetDescription>El acceso y los límites se validan nuevamente en el servidor.</SheetDescription></SheetHeader><div className="space-y-5 px-4 pb-6">
      <Field label="Nombre"><Input value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} /></Field>
      <Field label="Usuario"><Input autoCapitalize="none" value={form.username} onChange={event => setForm({ ...form, username: event.target.value })} /></Field>
      {editing === 'new' ? <Field label="Contraseña"><PasswordInput id="staff-password" visible={showFormPassword} onVisibilityChange={setShowFormPassword} autoComplete="new-password" aria-describedby="staff-password-requirements" aria-invalid={form.password.length > 0 && !hasValidPassword(form.password)} value={form.password} onChange={event => setForm({ ...form, password: event.target.value, password_confirmation: event.target.value })} /><PasswordRequirements id="staff-password-requirements" password={form.password} /></Field> : null}
      <Field label="Rol"><Select value={form.role} onValueChange={value => setForm({ ...form, role: value as StaffPayload['role'] })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="cashier">Caja</SelectItem><SelectItem value="waiter">Mesero</SelectItem><SelectItem value="kitchen">Cocina</SelectItem><SelectItem value="bar">Barra</SelectItem></SelectContent></Select></Field>
      <fieldset className="space-y-3"><legend className="text-sm font-medium">Sucursales</legend>{operable.map(branch => <label key={branch.id} className="flex items-center gap-3 text-sm"><Checkbox checked={form.branch_ids.includes(branch.id)} onCheckedChange={checked => setForm({ ...form, branch_ids: checked ? [...form.branch_ids, branch.id] : form.branch_ids.filter(id => id !== branch.id) })} />{branch.name}</label>)}</fieldset>
      <Button className="w-full" disabled={save.isPending || !form.name || !form.username || !form.branch_ids.length || (editing === 'new' && !hasValidPassword(form.password))} onClick={() => save.mutate()}>{save.isPending ? 'Guardando…' : 'Guardar usuario'}</Button>
    </div></SheetContent></Sheet>

    <Sheet open={passwordFor !== null} onOpenChange={open => { if (!open) { setPasswordFor(null); setNewPassword(''); setShowNewPassword(false) } }}><SheetContent className="sm:max-w-md"><SheetHeader><SheetTitle>Nueva contraseña</SheetTitle><SheetDescription>{passwordFor?.name} tendrá que iniciar sesión otra vez.</SheetDescription></SheetHeader><div className="space-y-4 px-4"><Field label="Contraseña"><PasswordInput id="staff-reset-password" visible={showNewPassword} onVisibilityChange={setShowNewPassword} autoComplete="new-password" aria-describedby="staff-reset-password-requirements" aria-invalid={newPassword.length > 0 && !hasValidPassword(newPassword)} value={newPassword} onChange={event => setNewPassword(event.target.value)} /><PasswordRequirements id="staff-reset-password-requirements" password={newPassword} /></Field><Button className="w-full" disabled={passwordMutation.isPending || !hasValidPassword(newPassword)} onClick={() => passwordMutation.mutate()}>Actualizar contraseña</Button></div></SheetContent></Sheet>
  </div>
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <div className="space-y-2"><Label>{label}</Label>{children}</div> }

function PasswordInput({ visible, onVisibilityChange, className, ...props }: React.ComponentProps<typeof Input> & { visible: boolean; onVisibilityChange: (visible: boolean) => void }) {
  return <div className="relative"><Input {...props} type={visible ? 'text' : 'password'} className={`pr-10 ${className ?? ''}`} /><button type="button" className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2" aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={visible} onClick={() => onVisibilityChange(!visible)}>{visible ? <EyeOffIcon className="size-4" /> : <EyeIcon className="size-4" />}</button></div>
}

function PasswordRequirements({ id, password }: { id: string; password: string }) {
  const rules = passwordRules(password)
  const requirements = [
    ['Mínimo 8 caracteres', rules.length],
    ['Una letra mayúscula', rules.uppercase],
    ['Una letra minúscula', rules.lowercase],
    ['Un número', rules.number],
  ] as const

  return <div id={id} className="space-y-1.5 text-xs" aria-live="polite">
    <p className="text-muted-foreground">La contraseña debe incluir:</p>
    <ul className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
      {requirements.map(([label, valid]) => <li key={label} className={password.length > 0 && valid ? 'text-primary' : 'text-muted-foreground'}>{label}</li>)}
    </ul>
  </div>
}
