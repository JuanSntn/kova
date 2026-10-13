import axios from 'axios'

export const api = axios.create({
  baseURL:
    import.meta.env.VITE_API_URL ||
    'http://localhost:8000',

  headers: {
    'X-Requested-With':
      'XMLHttpRequest',

    Accept:
      'application/json',
  },

  withCredentials: true,

  withXSRFToken: true,
})

let activeTenantId:
  string | null = null
let activeBranchId: string | null = null

export function setApiTenantId(
  tenantId: string | null,
) {
  activeTenantId =
    tenantId
}

export function setApiBranchId(branchId: string | null) { activeBranchId = branchId }

// @deprecated Usa setApiTenantId en su lugar
export function setActiveTenantId(
  tenantId: string | null,
) {
  setApiTenantId(
    tenantId,
  )
}

api.interceptors.request.use(
  (config) => {
    const isApiRequest =
      config.url?.startsWith(
        '/api/',
      ) === true

    const isAuthRequest =
      config.url?.startsWith(
        '/api/auth/',
      ) === true

    if (
      isApiRequest &&
      !isAuthRequest &&
      (activeTenantId || config.headers.has('X-Tenant-ID'))
    ) {
      config.headers.set(
        'X-Tenant-ID',
        config.headers.get('X-Tenant-ID') ?? activeTenantId,
      )
    } else {
      config.headers.delete(
        'X-Tenant-ID',
      )
    }

    if (isApiRequest && !isAuthRequest && (activeBranchId || config.headers.has('X-Branch-ID'))) config.headers.set('X-Branch-ID', config.headers.get('X-Branch-ID') ?? activeBranchId)
    else config.headers.delete('X-Branch-ID')

    /*
     * NO establecer Content-Type aquí.
     *
     * Axios lo decide automáticamente:
     *
     * objeto JS
     * → application/json
     *
     * FormData
     * → multipart/form-data; boundary=...
     */
    return config
  },
)

api.interceptors.response.use(
  (response) =>
    response,

  (error) => {
    const status =
      error.response?.status

    const isSessionProbe =
      error.config?.url ===
      '/api/auth/me'

    if (
      !isSessionProbe &&
      (
        status === 401 ||
        status === 419
      )
    ) {
      setApiTenantId(
        null,
      )
      setApiBranchId(null)

      window.dispatchEvent(
        new CustomEvent(
          'kova:session-expired',
        ),
      )
    }

    return Promise.reject(
      error,
    )
  },
)

export async function getCsrfToken() {
  await api.get(
    '/sanctum/csrf-cookie',
  )
}
