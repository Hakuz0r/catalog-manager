<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { RefreshCw, Box, Folder, SlidersHorizontal, Funnel, Upload, History, Users, Search, X, ChevronLeft, ChevronRight, Download, Menu, Pencil, ArrowRightLeft, Check, Undo2, Info } from '@lucide/vue'
import CategoryTree from './components/CategoryTree.vue'
import BulkCategory from './components/BulkCategory.vue'
import ImportPage from './components/ImportPage.vue'
import DictionaryPage from './components/DictionaryPage.vue'
import FiltersPage from './components/FiltersPage.vue'
import StaffPage from './components/StaffPage.vue'
import CreateProduct from './components/CreateProduct.vue'
import * as api from './domain/api'
import ProductTable from './components/ProductTable.vue'
import ProductEditor from './components/ProductEditor.vue'
import { useCatalog } from './composables/useCatalog'
import { clone, draftOf, filterProducts, priceFormat, validateDraft, type Product, type Draft } from './domain/catalog'
const catalog = useCatalog()
const nav = computed(() => [{ name: 'Товары', icon: Box }, { name: 'Категории', icon: Folder }, { name: 'Характеристики', icon: SlidersHorizontal }, { name: 'Фильтры каталога', icon: Funnel }, { name: 'Импорт', icon: Upload }, { name: 'История изменений', icon: History }, { name: 'Сотрудники', icon: Users }].map(item => ({ ...item, later: !catalog.isServer && ['Фильтры каталога','Импорт','Сотрудники'].includes(item.name) })).filter(item => (item.name !== 'Сотрудники' || catalog.permissions.value.staff) && (item.name !== 'Импорт' || catalog.permissions.value.edit)))
const active = ref('Товары'); const menuOpen = ref(false)
const minPrice = ref(''); const maxPrice = ref(''); const showTree = ref(false)
const search = ref(''); const category = ref<number | null>(20348); const priceState = ref('all'); const showFilters = ref(false)
const ascending = ref(true); const page = ref(1); const pageSize = ref(10); const selected = ref<number[]>([])
const creating = ref(false); const createForm = ref<InstanceType<typeof CreateProduct> | null>(null)
const categoryDialog = ref(false); const categoryName = ref(''); const categoryParent = ref(0); const categoryBusy = ref(false); const categoryError = ref('')
async function createCategory() { if (categoryBusy.value) return; categoryBusy.value = true; categoryError.value = ''; try { const result = await api.createCategory(categoryName.value, categoryParent.value); catalog.categories.splice(0, catalog.categories.length, ...result.categories); categoryDialog.value = false; categoryName.value = ''; await catalog.refreshHistory() } catch(e) { categoryError.value = e instanceof Error ? e.message : 'Не удалось создать категорию.' } finally { categoryBusy.value = false } }
function productCreated(product: Product) { creating.value = false; edited.value = product; void catalog.refreshHistory(); void catalog.refreshCategories() }
const edited = ref<Product | null>(null); const editor = ref<InstanceType<typeof ProductEditor> | null>(null)
const filtered = computed(() => catalog.isServer ? catalog.products.value : filterProducts(catalog.products.value, search.value, category.value, priceState.value).filter(p=>(!minPrice.value || (p.basePrice!==null && p.basePrice>=Number(minPrice.value.replace(',','.'))))&&(!maxPrice.value || (p.basePrice!==null && p.basePrice<=Number(maxPrice.value.replace(',','.'))))).sort((a, b) => (ascending.value ? 1 : -1) * a.name.localeCompare(b.name, 'ru', { numeric: true })))
const maxPage = computed(() => Math.max(1, Math.ceil((catalog.isServer ? catalog.total.value : filtered.value.length) / pageSize.value)))
const visible = computed(() => catalog.isServer ? filtered.value : filtered.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
const attributeSummary = computed(() => {
  const attrs = new Map<string, { name: string; count: number; values: Set<string> }>()
  for (const p of catalog.products.value) for (const a of p.attributes) {
    const key = a.name.toLocaleLowerCase('ru'); const item = attrs.get(key) ?? { name: a.name, count: 0, values: new Set<string>() }
    item.count++; a.values.forEach(v => item.values.add(v)); attrs.set(key, item)
  }
  return [...attrs.values()].sort((a, b) => b.count - a.count)
})
watch([search, category, priceState, pageSize, minPrice, maxPrice], () => { page.value = 1 })
watch(maxPage, value => { if (!catalog.isServer) page.value = Math.min(page.value, value) })
function go(name: string) {
  if (creating.value) { createForm.value?.close(); if (creating.value) return }
  if (edited.value) { editor.value?.close(); if (edited.value) return }
  active.value = name; menuOpen.value = false; if (name === 'История изменений') void catalog.refreshHistory()
}
function select(id: number) { selected.value = selected.value.includes(id) ? selected.value.filter(v => v !== id) : [...selected.value, id] }
function selectPage() { const all = visible.value.every(p => selected.value.includes(p.id)); for (const p of visible.value) if (selected.value.includes(p.id) === all) select(p.id) }
function resetFilters() { minPrice.value=''; maxPrice.value=''; search.value = ''; category.value = null; priceState.value = 'all' }
const bulkCategoryOpen = ref(false)
const bulkSaving = ref(false)
const attributeName = ref(''); const attributeValue = ref('')
const bulk = ref<'price' | 'category' | 'attribute' | null>(null); const percent = ref('5'); const targetCategory = ref(20348); const bulkError = ref(''); const previewed = ref(false)
const bulkItems = computed(() => catalog.products.value.filter(p => selected.value.includes(p.id)).map(p => {
  const draft = draftOf(p)
  if (bulk.value === 'price' && p.basePrice !== null) draft.basePrice = Math.round(p.basePrice * (1 + Number(percent.value.replace(',', '.')) / 100) * 100) / 100
  if (bulk.value === 'category') draft.categoryId = targetCategory.value
  if (bulk.value === 'attribute' && attributeName.value.trim()) {
    const key = attributeName.value.trim().toLowerCase(); const i = draft.attributes.findIndex(a => a.name.toLowerCase() === key)
    const attr = { ...(i >= 0 ? draft.attributes[i]! : { name: attributeName.value.trim() }), values: attributeValue.value.split('|').map(v => v.trim()).filter(Boolean) }
    if (i >= 0) draft.attributes[i] = attr; else draft.attributes.push(attr)
  }
  return { p, draft }
}))
function openBulk(mode: 'price' | 'category' | 'attribute') { bulk.value = mode; previewed.value = false; bulkError.value = '' }
function bulkIssues(): string[] {
  if (bulk.value === 'price' && (!percent.value.trim() || !Number.isFinite(Number(percent.value.replace(',', '.'))) || Number(percent.value.replace(',', '.')) <= -100)) return ['Введите процент больше −100. Например, 5 или −5.']
  if (bulk.value === 'attribute' && (!attributeName.value.trim() || !attributeValue.value.trim())) return ['Укажите название характеристики и значение.']
  return bulkItems.value.flatMap(x => validateDraft(x.draft, catalog.categories))
}
function previewBulk() { bulkError.value = bulkIssues()[0] ?? ''; previewed.value = !bulkError.value }
async function applyBulk() {
  if (!previewed.value || bulkIssues().length) return
  if (bulkSaving.value) return
  bulkSaving.value = true
  let completed = 0
  try {
    const plan = [...bulkItems.value]
    for (const x of plan) {
      const result = await catalog.save(x.p.id, clone(x.draft) as Draft, x.p.revision)
      if (result.errors.length) { bulkError.value = `Сохранено ${completed} из ${plan.length}. ${result.errors[0]}`; previewed.value = false; return }
      completed++; selected.value = selected.value.filter(id => id !== x.p.id)
    }
    bulk.value = null; selected.value = []; await catalog.refreshPage()
  } finally { bulkSaving.value = false }

}
watch([percent, targetCategory, attributeName, attributeValue], () => { previewed.value = false })
function closeBulk(e: MouseEvent) { if (!bulkSaving.value && e.target === e.currentTarget) bulk.value = null }
const loginName = ref('catalog_editor'); const password = ref(''); const signingIn = ref(false)
async function signIn() { signingIn.value = true; try { const secret = password.value; password.value = ''; await catalog.signIn(loginName.value, secret) } finally { signingIn.value = false } }
async function signOut() { if (creating.value) { createForm.value?.close(); if (creating.value) return } if (edited.value) { editor.value?.close(); if (edited.value) return } await catalog.signOut() }
onMounted(() => { void catalog.start() })
let queryTimer: ReturnType<typeof setTimeout>
watch([search, category, priceState, ascending, page, pageSize, minPrice, maxPrice, catalog.authenticated], () => {
  clearTimeout(queryTimer)
  if (!catalog.isServer || catalog.syncing.value) return
  selected.value = []
  queryTimer = setTimeout(() => { void catalog.loadPage({ page: page.value, per_page: pageSize.value, search: search.value, category: category.value, price_state: priceState.value, order: ascending.value ? 'asc' : 'desc', min_price: minPrice.value, max_price: maxPrice.value }) }, 250)
})
async function synchronize() {
  if (catalog.syncing.value || catalog.loading.value || edited.value || creating.value || bulk.value || bulkCategoryOpen.value) return
  clearTimeout(queryTimer)
  const currentQuery = () => ({ page: page.value, per_page: pageSize.value, search: search.value, category: category.value, price_state: priceState.value, order: ascending.value ? 'asc' : 'desc', min_price: minPrice.value, max_price: maxPrice.value })
  const query = currentQuery()
  const result = await catalog.synchronize(query)
  if (result !== null) { selected.value = []; if (JSON.stringify(query) !== JSON.stringify(currentQuery())) await catalog.loadPage(currentQuery()); else page.value = result }
}
const totalCount = computed(() => catalog.isServer ? catalog.total.value : filtered.value.length)

</script>
<template>
  <div v-if="catalog.isServer && !catalog.authenticated.value" class="login-page"><form class="login-card" @submit.prevent="signIn"><img src="/logo.svg" alt="Catalog Manager" /><h1>Вход в панель</h1><p class="muted">Тестовый каталог · отдельная учётная запись</p><label>Логин<input v-model="loginName" autocomplete="username" required /></label><label>Пароль<input v-model="password" type="password" autocomplete="current-password" required /></label><p v-if="catalog.connectionError.value" class="error-notice" role="alert">{{ catalog.connectionError.value }}</p><button type="submit" class="button primary" :disabled="signingIn || catalog.loading.value">{{ signingIn ? 'Входим…' : 'Войти' }}</button><p class="field-hint">Доступы сохранены в локальном закрытом файле stage-login.json.</p></form></div>
  <div v-else class="app-shell">
    <div v-if="menuOpen" class="sidebar-scrim" @click="menuOpen = false" />
    <aside class="sidebar" :class="{ opened: menuOpen }">
      <a href="#" class="brand" aria-label="Catalog Manager — товары" @click.prevent="go('Товары')"><img src="/logo.svg" alt="Catalog Manager" /></a>
      <nav aria-label="Основное меню"><button v-for="item in nav" :key="item.name" :class="{ active: active === item.name }" :disabled="item.later" :title="item.later ? 'Подключим на следующем этапе' : item.name" @click="go(item.name)"><component :is="item.icon" :size="20" :stroke-width="1.6" /><span>{{ item.name }}</span><span v-if="item.later" class="nav-soon">позже</span></button></nav>
      <div class="sidebar-bottom"><span class="status-dot" /><div>{{ catalog.isServer ? 'Тестовый сайт' : 'Локальная версия' }}<small>{{ catalog.isServer ? 'Сохранение через API' : 'Демонстрационные данные' }}</small></div></div>
    </aside>
    <div class="workspace">
      <header class="topbar"><div><button class="icon-button mobile-menu" aria-label="Открыть меню" @click="menuOpen = !menuOpen"><Menu :size="22" /></button><span>Панель управления каталогом</span></div><div><span class="preview-badge">{{ catalog.isServer ? catalog.user.value : 'Предпросмотр' }}</span><button v-if="catalog.isServer" class="text-button" @click="signOut">Выйти</button></div></header>
      <div class="environment-banner"><Info :size="16" /><span>{{ catalog.isServer ? 'Тестовая копия: изменения сохраняются на сервере. Боевой сайт не изменяется.' : 'Данные из сохранённой выгрузки. Изменения сохраняются только в этом браузере — на сайт не отправляются.' }}</span></div>
      <div v-if="catalog.storageError.value" class="error-notice" role="alert">{{ catalog.storageError.value }}<button class="text-button" @click="catalog.backup">Скачать черновики</button></div>
      <main><p v-if="catalog.connectionError.value" role="alert" class="error-notice">{{ catalog.connectionError.value }}</p><p v-if="catalog.loading.value" class="loading-notice" role="status">Загружаем каталог…</p>
        <CreateProduct v-if="creating" ref="createForm" :categories="catalog.categories" :category-id="category" @close="creating = false" @created="productCreated" />
        <ProductEditor v-else-if="edited" ref="editor" :key="edited.id" :product="edited" :categories="catalog.categories" :changes="catalog.changes.value" :save="catalog.save" :is-server="catalog.isServer" :readonly="!catalog.permissions.value.edit" @updated="catalog.adopt" @close="edited = null; catalog.refreshPage()" />
        <template v-else-if="active === 'Товары'">
          <div class="page-heading"><div><h1>Товары</h1><p>Каталог интернет-магазина <span class="dot-separator">·</span> {{ catalog.meta.catalogTotal.toLocaleString('ru-RU') }} {{ catalog.isServer ? 'опубликованных товаров на тесте' : 'демонстрационных товаров' }}</p></div><button v-if="catalog.isServer" class="button" :disabled="catalog.syncing.value || catalog.loading.value" title="Заново загрузить актуальные товары и категории с сайта" @click="synchronize"><RefreshCw :size="17" />{{ catalog.syncing.value ? 'Синхронизируем…' : 'Синхронизировать товары' }}</button><span v-if="!catalog.isServer" class="snapshot-count">В предпросмотре: {{ catalog.meta.sampleSize }} товаров</span></div>
          <p v-if="catalog.lastSynced.value" class="muted" role="status">Данные обновлены с сайта в {{ catalog.lastSynced.value }}. Условия поиска сохранены.</p>
          <div class="toolbar"><label class="search-field"><Search :size="19" /><input v-model="search" aria-label="Поиск товаров" placeholder="Поиск по названию, артикулу, ID или ГОСТ…" /><button v-if="search" class="icon-button" aria-label="Очистить поиск" @click="search = ''"><X :size="16" /></button></label><select v-model="category" aria-label="Раздел каталога"><option :value="null">Все разделы</option><option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select><button v-if="catalog.isServer && catalog.permissions.value.create" class="button primary" @click="creating = true">＋ Добавить товар</button><button class="button" :aria-expanded="showFilters" @click="showFilters = !showFilters"><Funnel :size="17" />Фильтры</button></div>
          <div v-if="showFilters" class="filter-panel"><label>Основная цена<select v-model="priceState"><option value="all">Все товары</option><option value="priced">Цена указана</option><option value="missing">Без цены</option></select></label><label>Цена от, ₽<input v-model="minPrice" inputmode="decimal" placeholder="От" /></label><label>Цена до, ₽<input v-model="maxPrice" inputmode="decimal" placeholder="До" /></label><button class="text-button" @click="resetFilters">Сбросить условия</button></div>
          <div class="list-context"><button class="button" :aria-expanded="showTree" @click="showTree=!showTree"><Folder :size="15" />Дерево категорий</button><span>{{ category ? catalog.categories.find(c => c.id === category)?.name : 'Все разделы' }} <span class="dot-separator">/</span> {{ totalCount }} {{ catalog.isServer ? 'найдено' : 'в выборке' }}</span><button v-if="catalog.isServer && catalog.permissions.value.edit && category" class="text-button" @click="bulkCategoryOpen=true">Массовая правка всего раздела</button><button v-if="category || search || priceState !== 'all'" class="text-button" @click="resetFilters"><X :size="14" />Сбросить фильтры</button></div>
          <div v-if="selected.length" class="selection-toolbar"><span><Check :size="18" />Выбрано: {{ selected.length }}</span><button class="button" @click="openBulk('price')"><Pencil :size="16" />Изменить цены</button><button class="button" @click="openBulk('category')"><ArrowRightLeft :size="16" />Перенести в категорию</button><button class="button" @click="openBulk('attribute')"><SlidersHorizontal :size="16" />Изменить характеристики</button><button class="text-button clear-selection" @click="selected = []"><X :size="16" />Снять выбор</button></div>
          <div class="catalog-with-tree" :class="{expanded:showTree}"><CategoryTree v-if="showTree" :categories="catalog.categories" :selected="category" :movable="catalog.permissions.value.edit" @select="id=>category=id" @move="(id,ids)=>{selected=ids;targetCategory=id;openBulk('category')}" /><ProductTable :products="visible" :categories="catalog.categories" :draft-ids="Object.keys(catalog.state.value.drafts)" :selected="selected" :sort-ascending="ascending" :selectable="catalog.permissions.value.edit" @open="p => edited = clone(p)" @select="select" @select-page="selectPage" @sort="ascending = !ascending" /></div>
          <footer class="list-footer"><div><strong>Найдено: {{ totalCount }} товаров</strong><span class="muted">Показано {{ totalCount ? (page - 1) * pageSize + 1 : 0 }}–{{ Math.min(page * pageSize, totalCount) }} из {{ totalCount }}</span></div><div class="pagination"><button class="button icon-button" aria-label="Предыдущая страница" :disabled="page === 1" @click="page--"><ChevronLeft :size="17" /></button><span aria-live="polite">{{ page }} / {{ maxPage }}</span><button class="button icon-button" aria-label="Следующая страница" :disabled="page >= maxPage" @click="page++"><ChevronRight :size="17" /></button><select v-model="pageSize" aria-label="Товаров на странице"><option :value="10">По 10</option><option :value="20">По 20</option><option :value="50">По 50</option></select></div></footer>
        </template>
        <template v-else-if="active === 'Категории'"><div class="page-heading"><div><h1>Категории</h1><p>{{ catalog.isServer ? 'Категории тестового каталога' : 'Разделы, включённые в первый предпросмотр' }}</p></div><button v-if="catalog.isServer && catalog.permissions.value.catalog" class="button primary" @click="categoryDialog = true">＋ Добавить категорию</button></div><CategoryTree :categories="catalog.categories" @select="id=>{category=id;active='Товары';search='';priceState='all'}" /></template>
        <DictionaryPage v-else-if="catalog.isServer && active === 'Характеристики'" :editable="catalog.permissions.value.catalog" @changed="catalog.refreshHistory" />
        <FiltersPage v-else-if="catalog.isServer && active === 'Фильтры каталога'" :categories="catalog.categories" :editable="catalog.permissions.value.catalog" :category-id="category" @changed="catalog.refreshHistory" />
        <ImportPage v-else-if="catalog.isServer && active === 'Импорт'" :categories="catalog.categories" :category-id="category" @changed="catalog.refreshHistory(); catalog.refreshCategories(); catalog.refreshPage()" />
        <StaffPage v-else-if="catalog.isServer && active === 'Сотрудники'" @changed="catalog.refreshHistory" />
        <template v-else-if="active === 'Характеристики'"><div class="page-heading"><div><h1>Характеристики</h1><p>{{ catalog.isServer ? 'Характеристики товаров текущей загруженной страницы. Полный справочник подключим отдельно.' : 'Существующие поля товаров из локальной выборки' }}</p></div></div><div class="table-scroll"><table class="products-table"><thead><tr><th>Характеристика</th><th>Товаров в выборке</th><th>Примеры значений</th></tr></thead><tbody><tr v-for="a in attributeSummary" :key="a.name"><td><strong>{{ a.name }}</strong></td><td>{{ a.count }}</td><td>{{ [...a.values].slice(0, 5).join(' · ') }}</td></tr></tbody></table></div></template>
        <template v-else><div class="page-heading"><div><h1>История изменений</h1><p>{{ catalog.isServer ? 'Последние 100 операций сотрудников на тестовом сайте' : 'Локальные черновики этого браузера' }}</p></div><button class="button" :disabled="!catalog.changes.value.length" @click="catalog.backup"><Download :size="17" />{{ catalog.isServer ? 'Скачать журнал' : 'Скачать черновики' }}</button></div><div v-if="!catalog.changes.value.length" class="empty-state"><History :size="36" /><h2>Изменений пока нет</h2><p>Сохраните черновик товара — здесь появится запись с возможностью отмены.</p></div><div v-else class="history-list"><article v-for="change in catalog.changes.value" :key="change.id"><div><strong>{{ change.productName }}</strong><p class="muted">{{ change.productId ? 'ID ' + change.productId : 'Настройки каталога' }} <span class="dot-separator">·</span> {{ new Date(change.at).toLocaleString('ru-RU') }}<span v-if="change.actor"> · {{ change.actor }}</span></p><span class="badge" :class="change.undone ? 'neutral' : 'amber'">{{ change.undone ? 'Отменено' : change.kind === 'undo' ? 'Отмена правки' : change.kind === 'create' ? 'Создание товара' : change.kind === 'category_create' ? 'Создание раздела' : catalog.isServer ? 'Сохранено на тесте' : 'Локальный черновик' }}</span></div><button class="button" :disabled="!catalog.canUndo(change.id)" @click="catalog.undo(change.id)"><Undo2 :size="17" />Отменить правку</button></article></div></template>
      </main>
    </div>
    <BulkCategory v-if="bulkCategoryOpen && category" :category-id="category" :categories="catalog.categories" @close="bulkCategoryOpen=false" @changed="catalog.refreshHistory();catalog.refreshPage();catalog.refreshCategories()" />
    <div v-if="categoryDialog" class="modal-backdrop" @keydown.esc="!categoryBusy && (categoryDialog = false)"><form class="modal" role="dialog" aria-modal="true" aria-labelledby="category-dialog-title" @submit.prevent="createCategory"><h2 id="category-dialog-title">Новая категория</h2><label>Название категории<input v-model="categoryName" required maxlength="150" /></label><label>Родительский раздел<select v-model="categoryParent"><option :value="0">Верхний уровень</option><option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label><p v-if="categoryError" class="error-notice" role="alert">{{ categoryError }}</p><p class="muted">Создание можно отменить через историю, пока в разделе нет товаров и подразделов.</p><div class="modal-actions"><button type="button" class="button" :disabled="categoryBusy" @click="categoryDialog = false">Отмена</button><button class="button primary" type="submit" :disabled="categoryBusy">{{ categoryBusy ? 'Создаём…' : 'Создать категорию' }}</button></div></form></div>
    <div v-if="bulk" class="modal-backdrop" @click="closeBulk" @keydown.esc="!bulkSaving && (bulk = null)"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="bulk-title" tabindex="-1"><div class="modal-heading"><h2 id="bulk-title">{{ bulk === 'price' ? 'Изменить основные цены' : bulk === 'attribute' ? 'Изменить характеристику' : 'Перенести товары' }}</h2><button class="icon-button" aria-label="Закрыть окно" :disabled="bulkSaving" @click="bulk = null"><X :size="20" /></button></div><p class="muted">Выбрано {{ selected.length }} товаров. {{ catalog.isServer ? 'Изменения будут сохранены на тестовом сайте по одному товару. При ошибке остановимся; успешные правки можно отменить через историю.' : 'Сохраним изменения только в локальные черновики.' }}</p><label v-if="bulk === 'price'">Изменение цены, %<input v-model="percent" inputmode="decimal" placeholder="Например, 5 или −5" /></label><template v-else-if="bulk==='attribute'"><label>Название характеристики<input v-model="attributeName" placeholder="Например, Материал" /></label><label>Новое значение<input v-model="attributeValue" placeholder="Несколько значений разделяются |" /></label><p class="muted">Существующее поле будет заменено, отсутствующее — добавлено. Для общего справочника сначала добавьте новые значения на странице «Характеристики».</p></template><label v-else>Новая категория<select v-model="targetCategory"><option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label><p v-if="bulkError" class="error-notice" role="alert">{{ bulkError }}</p><template v-if="previewed"><p v-if="bulk === 'price'" class="muted">Товары без цены остаются без цены. Прежние значения сохранятся в истории.</p><div class="bulk-preview"><div v-for="x in bulkItems" :key="x.p.id"><span>{{ x.p.name }}</span><strong v-if="bulk === 'price'">{{ x.p.basePrice === null ? 'Без цены' : `${priceFormat.format(x.p.basePrice)} → ${priceFormat.format(x.draft.basePrice!)} ₽` }}</strong><strong v-else-if="bulk==='attribute'">{{ attributeName }}: {{ attributeValue }}</strong><strong v-else>{{ catalog.categories.find(c => c.id === x.draft.categoryId)?.name }}</strong></div></div></template><div class="modal-actions"><button class="button" :disabled="bulkSaving" @click="bulk = null">Отмена</button><button v-if="!previewed" class="button primary" @click="previewBulk">Проверить изменения</button><button v-else class="button primary" :disabled="bulkSaving" @click="applyBulk">{{ bulkSaving ? 'Сохраняем…' : catalog.isServer ? `Сохранить ${selected.length} товаров на тесте` : `Сохранить ${selected.length} черновиков` }}</button></div></section></div>
  </div>
</template>
