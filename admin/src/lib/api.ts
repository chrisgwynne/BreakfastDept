// Centralised, typed request handling for the Breakfast Admin API.
// Same-origin JSON under /breakfast-admin/api/v1. Session cookie auth + CSRF
// header on every mutating request. All errors are normalised to ApiError.
// (Kept off the top-level /api slug, which Kirby reserves for its Panel API.)

export const API_BASE = '/breakfast-admin/api/v1'

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public code: string | null = null,
    public fields: Record<string, string> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

let csrfToken = ''
export function setCsrf(token: string): void {
  csrfToken = token
}

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const init: RequestInit = { method, headers, credentials: 'same-origin' }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(body)
  }
  if (method !== 'GET' && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken
  }

  let res: Response
  try {
    res = await fetch(API_BASE + path, init)
  } catch {
    throw new ApiError(0, 'Network error — please check your connection.')
  }

  const isJson = (res.headers.get('content-type') || '').includes('application/json')
  const payload: unknown = isJson ? await res.json().catch(() => null) : null

  if (!res.ok) {
    const p = (payload ?? {}) as { message?: string; error?: string; code?: string; fields?: Record<string, string> }
    throw new ApiError(
      res.status,
      p.message || p.error || defaultMessage(res.status),
      p.code ?? null,
      p.fields ?? {},
    )
  }
  return (payload as { data?: T })?.data !== undefined ? (payload as { data: T }).data : (payload as T)
}

// Multipart upload (a real file), same auth + CSRF + error handling as request().
// The browser sets the multipart Content-Type/boundary, so we don't.
async function upload<T>(method: Method, path: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken

  let res: Response
  try {
    res = await fetch(API_BASE + path, { method, headers, credentials: 'same-origin', body: form })
  } catch {
    throw new ApiError(0, 'Network error — please check your connection.')
  }
  const isJson = (res.headers.get('content-type') || '').includes('application/json')
  const payload: unknown = isJson ? await res.json().catch(() => null) : null
  if (!res.ok) {
    const p = (payload ?? {}) as { message?: string; error?: string; code?: string; fields?: Record<string, string> }
    throw new ApiError(res.status, p.message || p.error || defaultMessage(res.status), p.code ?? null, p.fields ?? {})
  }
  return (payload as { data?: T })?.data !== undefined ? (payload as { data: T }).data : (payload as T)
}

function defaultMessage(status: number): string {
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'You don’t have access to that.'
  if (status === 404) return 'Not found.'
  if (status === 429) return 'Too many requests — give it a moment.'
  if (status >= 500) return 'Something went wrong on our side.'
  return 'Request failed.'
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  del: <T>(path: string, body?: unknown) => request<T>('DELETE', path, body),
  upload: <T>(path: string, form: FormData, method: Method = 'POST') => upload<T>(method, path, form),
}
