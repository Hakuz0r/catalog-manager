<script setup lang="ts">
import AttributeField from './AttributeField.vue'
import DictionaryPicker from './DictionaryPicker.vue'
import PhotoManager from './PhotoManager.vue'
import { computed, onBeforeUnmount, ref } from 'vue'
import { ArrowLeft, ExternalLink, Info, Plus, Save, Trash2, Image as ImageIcon } from '@lucide/vue'
import { clone, draftOf, priceFormat, unitLabels, type Category, type Draft, type Product, type Change, type SaveResult } from '../domain/catalog'
const props = defineProps<{ product: Product; categories: Category[]; changes: Change[]; isServer?: boolean; readonly?: boolean; save: (id: number, draft: Draft, revision?: string) => Promise<SaveResult> }>()
const emit = defineEmits<{ close: []; updated: [product: Product] }>()
const currentProduct = ref(clone(props.product))
const saving = ref(false)
const photoBusy = ref(false)
const form = ref(draftOf(currentProduct.value))
const baseline = ref(JSON.stringify(form.value))
const tab = ref('Основное')
const errors = ref<string[]>([])
const message = ref('')
const dirty = computed(() => JSON.stringify(form.value) !== baseline.value)
const priceInput = computed({ get: () => form.value.basePrice === null ? '' : String(form.value.basePrice), set: (s: string) => { form.value.basePrice = s.trim() === '' ? null : Number(s.replace(',', '.')) } })
const calculated = computed(() => {
  const base = currentProduct.value.baseUnit
  if (!base || form.value.basePrice === null || JSON.stringify(form.value.attributes) !== JSON.stringify(currentProduct.value.attributes)) return []
  const factor = currentProduct.value.unitFactors[base]
  if (!factor) return []
  return Object.entries(currentProduct.value.unitFactors).filter(([unit, f]) => unit !== base && f > 0).map(([unit, f]) => ({ unit, price: Math.round(form.value.basePrice! * factor / f * 100) / 100 }))
})
function close() { if (saving.value || photoBusy.value) return; if (!dirty.value || confirm('Выйти без сохранения изменений?')) emit('close') }
async function saveDraft() {
  if (saving.value) return
  saving.value = true; message.value = ''
  try {
    const result = await props.save(currentProduct.value.id, clone(form.value), currentProduct.value.revision)
    errors.value = result.errors
    if (!errors.value.length) {
      if (result.product) { currentProduct.value = clone(result.product); form.value = draftOf(result.product) }
      baseline.value = JSON.stringify(form.value)
      message.value = props.isServer ? 'Изменения сохранены на тестовом сайте. Запись добавлена в общий журнал.' : 'Черновик сохранён в этом браузере. На сайте товар не изменён.'
    }
  } finally { saving.value = false }
}
function photoSaved(p: Product) { currentProduct.value = clone(p); form.value = draftOf(p); baseline.value = JSON.stringify(form.value); emit('updated', p); message.value = 'Фотографии сохранены на тестовом сайте. Изменение можно отменить через историю.' }
function beforeUnload(e: BeforeUnloadEvent) { if (dirty.value || saving.value || photoBusy.value) e.preventDefault() }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
defineExpose({ dirty, close })
</script>
<template>
  <section class="editor-page">
    <div class="back-row"><button class="text-button" @click="close"><ArrowLeft :size="17" /> К списку товаров</button><span class="muted">Поиск и страница списка сохраняются</span></div>
    <div class="page-heading"><div><h1>{{ form.name || 'Редактирование товара' }}</h1><p>ID: {{ currentProduct.id }} <span class="dot-separator">·</span> {{ categories.find(c => c.id === form.categoryId)?.name }}</p></div><span class="badge amber">{{ isServer ? 'Тестовый сайт' : 'Локальный черновик' }}</span></div>
    <div class="tabs" role="tablist" aria-label="Данные товара"><button v-for="item in ['Основное', 'Цены', 'Характеристики', 'Фото', 'История']" :id="`tab-${item}`" :key="item" role="tab" :aria-selected="tab === item" :class="{ active: tab === item }" @click="tab = item">{{ item }}</button></div>
    <form @submit.prevent="saveDraft"><fieldset :disabled="readonly">
      <div v-if="errors.length" class="error-notice" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></div>
      <p v-if="message" class="success-notice" role="status">{{ message }}</p>
      <div v-if="tab === 'Основное'" class="editor-grid" role="tabpanel" aria-labelledby="tab-Основное">
        <div class="form-main"><h2>Основные данные</h2><label>Название товара <span class="required">*</span><input v-model="form.name" required maxlength="300" /></label><label v-if="isServer">Артикул<input v-model="form.sku" maxlength="100" /></label><label>Категория <span class="required">*</span><select v-model="form.categoryId"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label><label v-if="isServer">Краткое описание<textarea v-model="form.shortDescription" rows="4" /></label><label v-if="isServer">Статус<select v-model="form.status"><option value="draft">Черновик</option><option value="publish">Опубликован</option><option value="pending">На проверке</option><option value="private">Личный</option></select></label><div class="info-box"><Info :size="18" /><p>{{ isServer ? 'Изменения сохраняются в тестовой копии сайта и доступны другим сотрудникам. Боевой каталог не изменяется.' : 'Вы работаете с копией данных от 8 ноября. Сохранённые черновики доступны только в этом браузере.' }}</p></div><a :href="currentProduct.url" target="_blank" rel="noopener noreferrer" class="text-button">Открыть карточку на сайте <ExternalLink :size="15" /></a></div>
        <aside class="editor-aside"><h2>Ключевые характеристики</h2><dl><div v-for="a in form.attributes.slice(0, 6)" :key="a.name"><dt>{{ a.name }}</dt><dd>{{ a.values.join(', ') }}</dd></div></dl><button type="button" class="text-button" @click="tab = 'Характеристики'">Все характеристики →</button></aside>
      </div>
      <div v-else-if="tab === 'Цены'" class="editor-grid" role="tabpanel" aria-labelledby="tab-Цены">
        <div class="form-main"><h2>Основная цена</h2><label>Цена товара, ₽<div class="price-input-row"><input v-model="priceInput" inputmode="decimal" placeholder="Узнать цену" class="large-input" /><select v-if="isServer && (!currentProduct.baseUnit || currentProduct.baseUnit==='native')" v-model="form.baseUnit" aria-label="Основная единица товара"><option :value="undefined">Выберите единицу</option><option value="metr">За метр</option><option value="kg">За килограмм</option><option value="tonna">За тонну</option><option value="sht">За штуку</option><option value="m2">За м²</option></select><span v-else class="unit-box">за {{ unitLabels[currentProduct.baseUnit ?? ''] ?? currentProduct.baseUnit ?? 'неизвестную единицу' }}</span></div></label><p class="muted">Пустое поле — цена не задана. Основная единица сохраняется из данных сайта.</p><h2 class="section-gap">Данные для пересчёта</h2><dl><div v-for="(value, key) in currentProduct.coefficients" :key="key"><dt>{{ ({ kg_per_metre: 'Масса погонного метра, кг/м', metres_per_piece: 'Длина штуки, м', kg_per_piece: 'Масса штуки, кг', square_metres_per_piece: 'Площадь штуки, м²' } as Record<string, string>)[key] ?? key }}</dt><dd>{{ value }}</dd></div></dl><p class="muted">Подтверждённые коэффициенты из аудита. Их редактирование подключим вместе с серверной проверкой.</p></div>
        <aside class="editor-aside"><h2>Расчётные цены</h2><p class="muted">Предпросмотр по сохранённым коэффициентам</p><dl v-if="calculated.length" class="calculated-prices"><div v-for="p in calculated" :key="p.unit"><dt>За {{ unitLabels[p.unit] ?? p.unit }}</dt><dd>{{ priceFormat.format(p.price) }} ₽</dd></div></dl><div v-else class="info-box"><Info :size="18" /><p>Для пересчёта недостаточно подтверждённых данных, не задана цена или изменены характеристики. Новые коэффициенты должен проверить сервер.</p></div><p class="muted">Округление до копеек. Итоговую проверку выполнит сервер при подключении.</p></aside>
      </div>
      <div v-else-if="tab === 'Характеристики'" class="attributes-editor" role="tabpanel" aria-labelledby="tab-Характеристики"><h2>Характеристики товара</h2><p class="muted">Меняйте существующие поля или добавляйте новые в локальный черновик.</p><div v-for="(_, index) in form.attributes" :key="index" class="attribute-row"><AttributeField v-model="form.attributes[index]!" :index="index" :server="!!isServer" /><button type="button" class="icon-button danger" :aria-label="`Удалить характеристику ${index + 1}`" @click="form.attributes.splice(index, 1)"><Trash2 :size="18" /></button></div><DictionaryPicker v-if="isServer" @add="attr=>form.attributes.push(attr)" /><button type="button" class="button" @click="form.attributes.push({ name: '', values: [''] })"><Plus :size="16" />Добавить характеристику</button></div>
      <PhotoManager v-else-if="tab === 'Фото' && isServer" :product="currentProduct" :disabled="dirty || saving || readonly" @saved="photoSaved" @busy="value=>photoBusy=value" />
      <div v-else-if="tab === 'Фото' && !currentProduct.imageUrl" class="empty-state" role="tabpanel" aria-labelledby="tab-Фото"><ImageIcon :size="36" /><h2>Фотографии подключим с сайтом</h2><p>Сохранённая выгрузка не содержит фотографий. Загрузка фото будет работать через защищённый серверный API.</p><a :href="currentProduct.url" target="_blank" rel="noopener noreferrer" class="text-button">Посмотреть товар на сайте <ExternalLink :size="15" /></a></div>
      <div v-else-if="tab === 'Фото'" role="tabpanel" aria-labelledby="tab-Фото" class="photo-view"><h2>Основное фото на тестовом сайте</h2><img :src="currentProduct.imageUrl ?? ''" alt="Фото товара" /><p class="muted">Просмотр текущего изображения. Загрузка и замена фото — следующий этап.</p></div>
      <div v-else class="history-list" role="tabpanel" aria-labelledby="tab-История"><h2>{{ isServer ? 'История изменений на тесте' : 'История локальных изменений' }}</h2><article v-for="c in changes.filter(c => c.productId === currentProduct.id)" :key="c.id"><div><strong>{{ c.undone ? 'Изменение отменено' : 'Сохранён черновик' }}</strong><p class="muted">{{ new Date(c.at).toLocaleString('ru-RU') }}</p></div><span class="badge neutral">{{ isServer ? (c.actor || 'Сотрудник') : 'Этот браузер' }}</span></article><p v-if="!changes.some(c => c.productId === currentProduct.id)" class="muted">У этого товара пока нет сохранённых изменений.</p></div>
      <footer class="editor-footer"><span :class="dirty ? 'unsaved' : 'muted'"><span class="status-dot" />{{ dirty ? 'Есть несохранённые изменения' : 'Изменений для сохранения нет' }}</span><div><button type="button" class="button" @click="close">Отменить</button><button type="submit" class="button primary" :disabled="!dirty || saving"><Save :size="17" />{{ saving ? 'Сохраняем…' : isServer ? 'Сохранить на тестовом сайте' : 'Сохранить черновик' }}</button></div></footer>
    </fieldset></form>
  </section>
</template>
