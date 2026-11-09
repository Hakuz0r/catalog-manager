import type { Category, Change, Draft, Product } from './catalog'
export interface Permissions { edit: boolean; create: boolean; catalog: boolean; staff: boolean; publish: boolean }
export interface Session { maxUploadBytes?: number; permissions?: Permissions; authenticated: boolean; nonce?: string; user?: { id: number; name: string } }
export interface ProductPage { items: Product[]; total: number; page: number; pages: number; catalogTotal: number }
export interface PageQuery { page: number; per_page: number; search: string; category: number | null; price_state: string; order: string; min_price?: string; max_price?: string }
let nonce = ''
let maxUploadBytes = 8 * 1024 * 1024
export const getUploadLimit = () => maxUploadBytes
export class ApiError extends Error { status: number; constructor(message: string, status: number) { super(message); this.status = status } }
async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(url, { ...options, credentials: 'same-origin', cache: 'no-store', headers: { ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), 'X-UKM-Office': '1', ...(nonce ? { 'X-WP-Nonce': nonce } : {}), ...options.headers } })
  let data
  try { data = await response.json() } catch { throw new ApiError('Сервер вернул неожиданный ответ. Проверьте подключение к тестовой копии.', response.status) }
  if (!response.ok) throw new ApiError(data.message || 'Не удалось выполнить запрос.', response.status)
  return data as T
}
export async function getSession(): Promise<Session> { const s = await request<Session>('/ukm-auth/session'); nonce = s.nonce ?? ''; maxUploadBytes = s.maxUploadBytes ?? maxUploadBytes; return s }
export async function login(login: string, password: string): Promise<Session> { const s = await request<Session>('/ukm-auth/login', { method: 'POST', body: JSON.stringify({ login, password }) }); nonce = s.nonce ?? ''; maxUploadBytes = s.maxUploadBytes ?? maxUploadBytes; return s }
export async function logout() { await request('/ukm-auth/logout', { method: 'POST' }); nonce = '' }
export const getCategories = () => request<Category[]>('/ukm-api/categories')
export function getProducts(query: PageQuery, signal?: AbortSignal) {
  const params = new URLSearchParams()
  Object.entries(query).forEach(([k, v]) => { if (v !== null) params.set(k, String(v)) })
  return request<ProductPage>(`/ukm-api/products?${params}`, { signal })
}
export const saveProduct = (id: number, draft: Draft, revision: string) => request<Product>(`/ukm-api/products/${id}`, { method: 'POST', body: JSON.stringify({ draft, revision }) })
export const getHistory = () => request<Change[]>('/ukm-api/history')
export const undoChange = (id: string) => request<Product>(id.startsWith('e') ? `/ukm-api/events/${id.slice(1)}/undo` : `/ukm-api/history/${id}/undo`, { method: 'POST', body: '{}' })

export const createProduct = (draft: Draft, baseUnit: string, status: string) => request<Product>('/ukm-api/products', { method: 'POST', body: JSON.stringify({ draft, baseUnit, status }) })
export const createCategory = (name: string, parent: number) => request<{ id: number; categories: Category[] }>('/ukm-api/categories', { method: 'POST', body: JSON.stringify({ name, parent }) })
export const savePhotos = (id: number, imageIds: number[], revision: string) => request<Product>(`/ukm-api/products/${id}/photos`, { method: 'POST', body: JSON.stringify({ imageIds, revision }) })
export const uploadPhoto = (id: number, file: File, revision: string) => { const data = new FormData(); data.append('file', file); data.append('revision', revision); return request<Product>(`/ukm-api/products/${id}/media`, { method: 'POST', body: data }) }
export interface Dictionary { id: number; name: string; key: string; registered: boolean; count: number }
export interface Term { id: number; name: string; count: number; revision: string }
export interface TermsPage { items: Term[]; total: number; page: number; attribute: { id: number; name: string; key: string } }
export interface FilterSettings { categoryId: number; name: string; available: string[]; selected: string[]; explicit: boolean; revision: string; url: string }
export interface StaffMember { id: number; login: string; name: string; role: string; active: boolean; revision: string }
export interface StaffPage { items: StaffMember[]; roles: { id: string; name: string }[] }
export const getDictionaries = () => request<Dictionary[]>('/ukm-api/attributes')
export const addDictionary = (name: string) => request<{ id: number; attributes: Dictionary[] }>('/ukm-api/attributes', { method: 'POST', body: JSON.stringify({ name }) })
export const getTerms = (id: number, search = '', page = 1) => request<TermsPage>(`/ukm-api/attributes/${id}/terms?search=${encodeURIComponent(search)}&page=${page}`)
export const addTerm = (id: number, name: string) => request(`/ukm-api/attributes/${id}/terms`, { method: 'POST', body: JSON.stringify({ name }) })
export const renameTerm = (id: number, term: Term, name: string) => request(`/ukm-api/attributes/${id}/terms/${term.id}`, { method: 'POST', body: JSON.stringify({ name, revision: term.revision, confirm: true }) })
export const getFilters = (id: number) => request<FilterSettings>(`/ukm-api/categories/${id}/filters`)
export const saveFilters = async (id: number, settings: FilterSettings, inherit = false) => { await request(`/ukm-api/categories/${id}/filters`, { method: 'POST', body: JSON.stringify({ labels: settings.selected, revision: settings.revision, inherit }) }); return getFilters(id) }
export const getStaff = () => request<StaffPage>('/ukm-api/staff')
export const addStaff = (data: { login: string; password: string; name: string; role: string }) => request('/ukm-api/staff', { method: 'POST', body: JSON.stringify(data) })
export const updateStaff = (member: StaffMember) => request(`/ukm-api/staff/${member.id}`, { method: 'POST', body: JSON.stringify(member) })
export interface ImportJob { job: string; total: number; cursor: number; complete: boolean; failed: { row: number; message: string } | null; errors: { row: number; message: string }[]; preview: { row: number; id: number; name: string; operation: string; changes: string[]; newTerms: { taxonomy: string; name: string }[]; price: number | null; unit: string }[] }
export const previewImport = (rows: Record<string,string>[], categoryId: number | null) => request<ImportJob>('/ukm-api/imports/preview', { method: 'POST', body: JSON.stringify({ rows, categoryId }) })
export const getImport = (job: string) => request<ImportJob>(`/ukm-api/imports/${job}`)
export const applyImport = (job: string, cursor: number) => request<ImportJob>(`/ukm-api/imports/${job}/apply`, { method: 'POST', body: JSON.stringify({ cursor }) })
export interface BulkOperation { mode: 'price' | 'category' | 'attribute'; percent: number; targetCategory: number; attributeName: string; attributeValue: string }
export const getBulkScope = (categoryId: number) => request<{ ids: number[]; total: number; name: string }>('/ukm-api/bulk/scope', { method: 'POST', body: JSON.stringify({ categoryId }) })
export const previewBulkCategory = (categoryId: number, ids: number[], operation: BulkOperation) => request<ImportJob>('/ukm-api/bulk/preview', { method: 'POST', body: JSON.stringify({ categoryId, ids, operation }) })
