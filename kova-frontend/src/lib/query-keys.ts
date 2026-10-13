export const queryKeys = {
  tenant: (tenantId: string) =>
    ['tenants', tenantId] as const,

  dashboard: (tenantId: string) =>
    ['tenants', tenantId, 'dashboard'] as const,

  billing: (tenantId: string) =>
    ['tenants', tenantId, 'billing'] as const,

  branches: (tenantId: string) =>
    ['tenants', tenantId, 'branches'] as const,

  products: (tenantId: string) =>
    ['tenants', tenantId, 'products'] as const,
  posProducts: (tenantId: string, branchId: string) => ['tenants', tenantId, 'branches', branchId, 'pos-products'] as const,
  posCategories: (tenantId: string, branchId: string) => ['tenants', tenantId, 'branches', branchId, 'pos-categories'] as const,

  categories: (tenantId: string) =>
    ['tenants', tenantId, 'categories'] as const,

  categoryCatalog: (tenantId: string) =>
    ['tenants', tenantId, 'category-catalog'] as const,

  taxes: (tenantId: string) =>
    ['tenants', tenantId, 'taxes'] as const,

  users: (tenantId: string) =>
    ['tenants', tenantId, 'users'] as const,
}
