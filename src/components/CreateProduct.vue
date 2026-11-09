<script setup lang="ts">
import AttributeField from './AttributeField.vue'
import DictionaryPicker from './DictionaryPicker.vue'
import { ref, computed } from 'vue'
import { ArrowLeft, Plus, Trash2, Save } from '@lucide/vue'
import { createProduct } from '../domain/api'
import { validateDraft, type Category, type Draft, type Product } from '../domain/catalog'
const props = defineProps<{ categories: Category[]; categoryId: number | null }>()
const emit = defineEmits<{ close: []; created: [product: Product] }>()
const draft = ref<Draft>({ name: '', categoryId: props.categoryId ?? props.categories[0]?.id ?? 0, basePrice: null, attributes: [], description: '', shortDescription: '', sku: '' })
const unit = ref('sht'); const status = ref('draft'); const busy = ref(false); const errors = ref<string[]>([])
const price = computed({ get: () => draft.value.basePrice === null ? '' : String(draft.value.basePrice), set: (v: string) => { draft.value.basePrice = v.trim() ? Number(v.replace(',', '.')) : null } })
const dirty = computed(() => !!draft.value.name || !!draft.value.sku || draft.value.basePrice !== null || !!draft.value.description || draft.value.attributes.length > 0)
function close() { if (!busy.value && (!dirty.value || confirm('Закрыть форму без создания товара?'))) emit('close') }
async function submit() {
  errors.value = validateDraft(draft.value, props.categories); if (errors.value.length || busy.value) return
  busy.value = true
  try { emit('created', await createProduct(draft.value, unit.value, status.value)) }
  catch (e) { errors.value = [e instanceof Error ? e.message : 'Не удалось создать товар.'] }
  finally { busy.value = false }
}
defineExpose({ close })
</script>
<template>
  <section><button class="text-button" @click="close"><ArrowLeft :size="17" />К списку товаров</button><div class="page-heading section-gap"><div><h1>Новый товар</h1><p>Создание в тестовом каталоге</p></div></div>
  <form @submit.prevent="submit"><div v-if="errors.length" role="alert" class="error-notice"><p v-for="e in errors" :key="e">{{ e }}</p></div><div class="editor-grid"><div><h2>Основные данные</h2><label>Название товара *<input v-model="draft.name" maxlength="300" required /></label><label>Артикул<input v-model="draft.sku" maxlength="100" placeholder="Можно оставить пустым" /></label><label>Категория *<select v-model="draft.categoryId"><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label><label>Краткое описание<textarea v-model="draft.shortDescription" rows="3" maxlength="10000" /></label><label>Описание<textarea v-model="draft.description" rows="6" maxlength="100000" /></label></div><aside class="editor-aside"><h2>Цена и публикация</h2><label>Основная цена, ₽<input v-model="price" inputmode="decimal" placeholder="Пусто — Узнать цену" /></label><label>Единица продажи *<select v-model="unit"><option value="sht">Штука</option><option value="metr">Метр</option><option value="kg">Килограмм</option><option value="tonna">Тонна</option><option value="m2">Квадратный метр</option></select></label><label>Статус после создания<select v-model="status"><option value="draft">Черновик</option><option value="publish">Опубликован на тесте</option></select></label><div class="info-box"><p>Фотографии добавляются после создания. Отменить создание можно через историю — товар переместится в корзину WordPress.</p></div></aside></div>
  <section class="attributes-editor"><h2>Характеристики</h2><div v-for="(_,i) in draft.attributes" :key="i" class="attribute-row"><AttributeField v-model="draft.attributes[i]!" :index="i" :server="true" /><button type="button" class="icon-button danger" :aria-label="`Удалить характеристику ${i+1}`" @click="draft.attributes.splice(i,1)"><Trash2 :size="18" /></button></div><DictionaryPicker @add="attr=>draft.attributes.push(attr)" /><button type="button" class="button" @click="draft.attributes.push({ name: '', values: [''] })"><Plus :size="16" />Добавить характеристику</button></section>
  <footer class="editor-footer"><span class="muted">Сохранение только на тестовом сайте</span><div><button type="button" class="button" :disabled="busy" @click="close">Отмена</button><button type="submit" class="button primary" :disabled="busy"><Save :size="17" />{{ busy ? 'Создаём…' : 'Создать товар' }}</button></div></footer></form></section>
</template>
