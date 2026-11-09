import { computed, ref, reactive } from 'vue'
import * as api from '../domain/api'
import type { PageQuery } from '../domain/api'
import snapshot from '../data/catalog-snapshot.json'
import { clone, draftOf, readSavedState, STORAGE_KEY, validateDraft, type Category, type Draft, type Product } from '../domain/catalog'

// A composable groups reactive data and actions. The UI never calls WordPress here.
export function useCatalog() {
  const isServer = import.meta.env.VITE_CATALOG_MODE === 'stage' && !new URLSearchParams(location.search).has('demo')
  const source = ref<Product[]>(isServer ? [] : snapshot.products as unknown as Product[])
  const meta = reactive({ ...snapshot.meta })
  const authenticated = ref(!isServer)
  const user = ref('')
  const permissions = ref<api.Permissions>({ edit: !isServer, create: !isServer, catalog: !isServer, staff: !isServer, publish: !isServer })
  const loading = ref(false)
  const syncing = ref(false)
  const lastSynced = ref<string>('')
  let sessionGeneration = 0
  const connectionError = ref('')
  const total = ref(0)
  const historyLoading = ref(false)
  const undoing = ref(false)
  let historyRequest = 0
  const serverChanges = ref<import('../domain/catalog').Change[]>([])
  let controller: AbortController | null = null
  let lastQuery: PageQuery = { page: 1, per_page: 10, search: '', category: 20348, price_state: 'all', order: 'asc' }
  const categories = reactive<Category[]>(isServer ? [] : snapshot.categories as Category[])
  let stored: string | null = null
  try { stored = localStorage.getItem(STORAGE_KEY) } catch { /* Private browsing may deny access. */ }
  const state = ref(readSavedState(isServer ? null : stored, source.value, categories))
  const storageError = ref('')
  const products = computed(() => source.value.map(p => ({ ...p, ...(isServer ? {} : state.value.drafts[p.id] ?? {}) })))
  const changes = computed(() => isServer ? serverChanges.value : [...state.value.changes].reverse())
  function persist() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state.value)); storageError.value = '' }
    catch { storageError.value = 'Браузер не смог сохранить черновики. До перезагрузки они остаются в памяти — скачайте резервную копию.' }
  }
  async function save(productId: number, draft: Draft, revision?: string): Promise<import('../domain/catalog').SaveResult> {
    if (isServer) {
      const errors = validateDraft(draft, categories)
      if (errors.length) return { errors }
      if (!revision) return { errors: ['Нет версии товара. Откройте его заново.'] }
      try {
        const product = await api.saveProduct(productId, draft, revision)
        source.value = source.value.map(p => p.id === productId ? product : p)
        await refreshHistory().catch(() => { connectionError.value = 'Товар сохранён, но журнал не удалось обновить. Перезагрузите страницу.' })
        return { errors: [], product }
      } catch (e) { return { errors: [e instanceof Error ? e.message : 'Не удалось сохранить товар.'] } }
    }
    const errors = validateDraft(draft, categories)
    const original = source.value.find(p => p.id === productId)
    if (!original) return { errors: ['Товар не найден.'] }
    if (errors.length) return { errors }
    const before = state.value.drafts[productId] ?? null
    const current = before ?? draftOf(original)
    if (JSON.stringify(current) === JSON.stringify(draft)) return { errors: [] }
    state.value.changes.push({ id: crypto.randomUUID(), productId, productName: draft.name, at: new Date().toISOString(), before: clone(before), after: clone(draft), undone: false })
    state.value.drafts[productId] = clone(draft)
    persist()
    return { errors: [] }
  }
  function canUndo(changeId: string): boolean {
    if (isServer) return !historyLoading.value && !undoing.value && !!serverChanges.value.find(c => c.id === changeId)?.canUndo
    const c = state.value.changes.find(c => c.id === changeId)
    if (!c || c.undone) return false
    // Only the last un-reverted change for a product can be undone.
    return state.value.changes.filter(x => x.productId === c.productId && !x.undone).at(-1)?.id === c.id
  }
  async function undo(changeId: string) {
    if (isServer) {
      if (undoing.value || historyLoading.value) return
      undoing.value = true
      try { await api.undoChange(changeId); await Promise.all([refreshHistory(), loadPage(lastQuery), refreshCategories()]) }
      catch (e) { connectionError.value = e instanceof Error ? e.message : 'Отмена не удалась.' }
      finally { undoing.value = false }
      return
    }
    const c = state.value.changes.find(c => c.id === changeId)
    if (!c || !canUndo(changeId)) return
    if (c.before === null) delete state.value.drafts[c.productId]
    else state.value.drafts[c.productId] = clone(c.before)
    c.undone = true
    persist()
  }
  function backup() {
    const blob = new Blob([JSON.stringify(isServer ? serverChanges.value : state.value, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob); const a = document.createElement('a')
    a.href = url; a.download = `uralkm-${isServer ? 'history' : 'drafts'}-${new Date().toISOString().slice(0, 10)}.json`; a.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  }
  async function refreshHistory() { const generation = sessionGeneration; if (!isServer || !authenticated.value) return; const request = ++historyRequest; historyLoading.value = true; try { const history = await api.getHistory(); if (generation === sessionGeneration && authenticated.value && request === historyRequest) serverChanges.value = history } finally { if (request === historyRequest) historyLoading.value = false } }
  async function loadPage(query: PageQuery) {
    if (!isServer || !authenticated.value) return
    const generation = sessionGeneration
    lastQuery = { ...query }; controller?.abort(); const current = new AbortController(); controller = current
    loading.value = true; connectionError.value = ''
    try {
      const data = await api.getProducts(query, current.signal)
      if (current.signal.aborted || generation !== sessionGeneration) return
      source.value = data.items; total.value = data.total; meta.catalogTotal = data.catalogTotal
    } catch (e) {
      if (current.signal.aborted || generation !== sessionGeneration) return
      connectionError.value = e instanceof Error ? e.message : 'Не удалось загрузить каталог.'
      if (e instanceof api.ApiError && [401, 403].includes(e.status)) authenticated.value = false
    } finally { if (controller === current) loading.value = false }
  }
  async function start() {
    if (!isServer) return
    loading.value = true
    try {
      const generation = sessionGeneration
      const session = await api.getSession(); if (generation !== sessionGeneration) return; authenticated.value = session.authenticated; user.value = session.user?.name ?? ''; if (session.permissions) permissions.value = session.permissions
      if (session.authenticated) { const freshCategories = await api.getCategories(); if (generation !== sessionGeneration) return; categories.splice(0, categories.length, ...freshCategories); await Promise.all([loadPage(lastQuery), refreshHistory()]) }
    } catch (e) { connectionError.value = e instanceof Error ? e.message : 'Нет связи с тестовой копией.' }
    finally { loading.value = false }
  }
  async function signIn(login: string, password: string) {
    connectionError.value = ''
    try { await api.login(login, password); await start() }
    catch (e) { connectionError.value = e instanceof Error ? e.message : 'Вход не удался.' }
  }
  async function signOut() {
    try { await api.logout(); sessionGeneration++; controller?.abort(); authenticated.value = false; source.value = []; serverChanges.value = []; categories.splice(0); user.value = ''; lastSynced.value = ''; permissions.value = { edit: false, create: false, catalog: false, staff: false, publish: false } }
    catch (e) { connectionError.value = e instanceof Error ? e.message : 'Выход не удался.' }
  }
  async function synchronize(query: PageQuery): Promise<number | null> {
    if (!isServer || !authenticated.value || syncing.value) return null
    syncing.value = true; connectionError.value = ''; controller?.abort()
    const generation = sessionGeneration
    try {
      const session = await api.getSession()
      if (generation !== sessionGeneration) return null
      if (!session.authenticated) throw new api.ApiError('Сессия завершена. Войдите заново.', 401)
      if (session.permissions) permissions.value = session.permissions
      user.value = session.user?.name ?? ''
      const [freshCategories, history, result] = await Promise.all([api.getCategories(), api.getHistory(), api.getProducts(query)])
      if (generation !== sessionGeneration) return null
      const lastPage = Math.max(1, Math.ceil(result.total / query.per_page))
      const nextQuery = { ...query, page: Math.min(query.page, lastPage) }
      const data = nextQuery.page === query.page ? result : await api.getProducts(nextQuery)
      if (generation !== sessionGeneration) return null
      controller?.abort(); lastQuery = nextQuery
      categories.splice(0, categories.length, ...freshCategories); historyRequest++; historyLoading.value = false; serverChanges.value = history
      source.value = data.items; total.value = data.total; meta.catalogTotal = data.catalogTotal
      lastSynced.value = new Date().toLocaleTimeString('ru-RU')
      return nextQuery.page
    } catch (e) {
      if (generation !== sessionGeneration) return null
      connectionError.value = e instanceof Error ? e.message : 'Не удалось синхронизировать каталог.'
      if (e instanceof api.ApiError && [401, 403].includes(e.status)) { sessionGeneration++; controller?.abort(); authenticated.value = false; source.value = []; serverChanges.value = []; categories.splice(0) }
      return null
    } finally { syncing.value = false }
  }
  async function refreshCategories() { const generation = sessionGeneration; if (isServer && authenticated.value) { const freshCategories = await api.getCategories(); if (generation === sessionGeneration && authenticated.value) categories.splice(0, categories.length, ...freshCategories) } }
  function adopt(product: Product) { source.value = source.value.map(p => p.id === product.id ? product : p); void refreshHistory().catch(() => { connectionError.value = 'Данные сохранены, но журнал не удалось обновить.' }) }
  return { historyLoading, undoing, synchronize, syncing, lastSynced, permissions, refreshCategories, adopt, meta, isServer, authenticated, user, loading, connectionError, total, start, signIn, signOut, loadPage, refreshPage: () => loadPage(lastQuery), refreshHistory, categories, products, changes, state, storageError, save, canUndo, undo, backup }
}
