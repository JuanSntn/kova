export const queryKeys = {
  tenant: (tenantId: string) =>
    ['tenants', tenantId] as const,

  dashboard: (tenantId: string) =>
    ['tenants', tenantId, 'dashboard'] as const,

  billing: (tenantId: string) =>
    ['tenants', tenantId, 'billing'] as const,

  products: (tenantId: string) =>
    ['tenants', tenantId, 'products'] as const,

  categories: (tenantId: string) =>
    ['tenants', tenantId, 'categories'] as const,

  taxes: (tenantId: string) =>
    ['tenants', tenantId, 'taxes'] as const,

  users: (tenantId: string) =>
    ['tenants', tenantId, 'users'] as const,
}
