export interface Attribute { name: string; values: string[]; key?: string; taxonomy?: boolean; id?: number }
export interface Product {
  id: number
  revision?: string
  imageUrl?: string | null
  images?: { id: number; url: string; alt: string }[]
  status?: string
  description?: string
  shortDescription?: string
  sku?: string
  name: string
  categoryId: number
  url: string
  basePrice: number | null
  baseUnit: string | null
  attributes: Attribute[]
  calculatedPrices: Record<string, number>
  unitFactors: Record<string, number>
  coefficients: Record<string, number>
  calculationAvailable: boolean
}
export interface Category { parent?: number; id: number; name: string; catalogCount: number; sampleCount: number }
export interface Draft { name: string; categoryId: number; basePrice: number | null; attributes: Attribute[]; baseUnit?: string; description?: string; shortDescription?: string; sku?: string; status?: string }
export interface Change {
  id: string
  productId: number
  productName: string
  at: string
  before: Draft | null
  after: Draft
  undone: boolean
  canUndo?: boolean
  actor?: string
  kind?: string
}
export interface SavedState { version: 1; drafts: Record<string, Draft>; changes: Change[] }
export const STORAGE_KEY = 'uralkm-office.local-drafts.v1'
export const unitLabels: Record<string, string> = {
  metr: 'м', tonna: 'т', kg: 'кг', sht: 'шт.', m2: 'м²', gramm: 'г', buhta: 'бухта',
}
export const priceFormat = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
export const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
export function draftOf(product: Product): Draft {
  return clone({ name: product.name, categoryId: product.categoryId, basePrice: product.basePrice, attributes: product.attributes, ...(product.description !== undefined ? { description: product.description } : {}), ...(product.shortDescription !== undefined ? { shortDescription: product.shortDescription } : {}), ...(product.sku !== undefined ? { sku: product.sku } : {}), ...(product.status !== undefined ? { status: product.status } : {}) })
}
export function validateDraft(draft: Draft, categories: Category[]): string[] {
  const errors: string[] = []
  if (!draft.name.trim()) errors.push('Укажите название товара.')
  if (!categories.some(c => c.id === draft.categoryId)) errors.push('Выберите раздел из списка.')
  if (draft.basePrice !== null && (!Number.isFinite(draft.basePrice) || draft.basePrice <= 0)) errors.push('Цена должна быть больше нуля. Пустое поле означает «Узнать цену».')
  if (draft.attributes.some(a => !a.name.trim() || !a.values.length || a.values.some(v => !v.trim()))) errors.push('Заполните название и значение каждой характеристики или удалите пустую строку.')
  const names = draft.attributes.map(a => a.taxonomy && a.key ? `tax:${a.key}` : `local:${a.name.trim().toLocaleLowerCase('ru')}`)
  if (new Set(names).size !== names.length) errors.push('Названия характеристик не должны повторяться.')
  return errors
}
export function filterProducts(products: Product[], search: string, categoryId: number | null, priceState: string): Product[] {
  const query = search.toLocaleLowerCase('ru').trim()
  return products.filter(p => (categoryId === null || p.categoryId === categoryId)
    && (priceState !== 'missing' || p.basePrice === null)
    && (priceState !== 'priced' || p.basePrice !== null)
    && (!query || `${p.id} ${p.name} ${p.attributes.map(a => a.values.join(' ')).join(' ')}`.toLocaleLowerCase('ru').includes(query)))
}
function isDraft(value: unknown): value is Draft {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return typeof v.name === 'string' && Number.isSafeInteger(v.categoryId)
    && (v.basePrice === null || (typeof v.basePrice === 'number' && Number.isFinite(v.basePrice) && v.basePrice > 0))
    && Array.isArray(v.attributes) && v.attributes.every(a => typeof a === 'object' && a !== null && typeof a.name === 'string' && Array.isArray(a.values) && a.values.every((s: unknown) => typeof s === 'string'))
}
export function readSavedState(raw: string | null, products: Product[], categories: Category[]): SavedState {
  const empty: SavedState = { version: 1, drafts: {}, changes: [] }
  if (!raw) return empty
  try {
    const parsed = JSON.parse(raw)
    if (parsed.version !== 1 || typeof parsed.drafts !== 'object' || !Array.isArray(parsed.changes)) return empty
    const ids = new Set(products.map(p => p.id))
    for (const [id, draft] of Object.entries(parsed.drafts ?? {})) {
      if (ids.has(Number(id)) && isDraft(draft) && !validateDraft(draft, categories).length) empty.drafts[id] = draft
    }
    empty.changes = parsed.changes.filter((c: Change) => c && typeof c.id === 'string' && ids.has(c.productId)
      && typeof c.productName === 'string' && typeof c.at === 'string' && Number.isFinite(Date.parse(c.at))
      && typeof c.undone === 'boolean' && (c.before === null || isDraft(c.before)) && isDraft(c.after))
    return empty
  } catch { return empty }
}

export interface SaveResult { errors: string[]; product?: Product }
