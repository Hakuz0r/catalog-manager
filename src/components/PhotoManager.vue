<script setup lang="ts">
import { ref, watch } from 'vue'
import { Upload, Star, Trash2 } from '@lucide/vue'
import { uploadPhoto, savePhotos, getUploadLimit } from '../domain/api'
import type { Product } from '../domain/catalog'
const props = defineProps<{ product: Product; disabled?: boolean }>()
const emit = defineEmits<{ saved: [product: Product]; busy: [value: boolean] }>()
const limit = getUploadLimit(); const limitLabel = limit >= 1024 * 1024 ? `${Math.floor(limit/1024/1024)} МБ` : `${Math.floor(limit/1024)} КБ`
const busy = ref(false); watch(busy,value=>emit('busy',value)); const error = ref(''); const fileInput = ref<HTMLInputElement | null>(null)
async function upload(files: FileList | File[] | null) {
  if (!files?.length || props.disabled || busy.value) return
  busy.value = true; error.value = ''; let product = props.product
  try {
    for (const file of Array.from(files)) { if (!['image/jpeg','image/png','image/webp'].includes(file.type)) throw new Error('Разрешены JPG, PNG и WebP.'); if (file.size > limit) throw new Error(`Файл больше ${limitLabel}.`); product = await uploadPhoto(product.id, file, product.revision!); emit('saved', product) }
  } catch (e) { error.value = e instanceof Error ? e.message : 'Ошибка загрузки.' }
  finally { busy.value = false; if (fileInput.value) fileInput.value.value = '' }
}
async function reorder(id: number, remove = false) {
  if (props.disabled || busy.value) return
  if (remove && !confirm('Убрать фото из карточки? Файл останется в медиатеке и может быть восстановлен через историю.')) return
  const ids = (props.product.images ?? []).map(i => i.id).filter(i => i !== id)
  if (!remove) ids.unshift(id)
  busy.value = true; error.value = ''
  try { emit('saved', await savePhotos(props.product.id, ids, props.product.revision!)) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Не удалось сохранить фотографии.' }
  finally { busy.value = false }
}
</script>
<template>
  <section class="photo-manager"><h2>Фотографии товара</h2><p class="muted">Первое фото — основное. Изменения сразу сохраняются на тесте и записываются в историю.</p><p v-if="disabled" class="info-box">Сначала сохраните остальные изменения товара.</p><p v-if="error" class="error-notice" role="alert">{{ error }}</p>
  <div class="upload-zone" :class="{ disabled: disabled || busy }" @dragover.prevent @drop.prevent="upload($event.dataTransfer?.files ?? null)"><Upload :size="26" /><p>{{ busy ? 'Сохраняем фотографии…' : 'Перетащите изображения сюда' }}</p><button type="button" class="button" :disabled="disabled || busy" @click="fileInput?.click()">Выбрать файлы</button><small>JPG, PNG, WebP · до {{ limitLabel }} · до 20 фото</small><input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" aria-label="Загрузить фотографии" :disabled="disabled || busy" @change="upload(($event.target as HTMLInputElement).files)" /></div>
  <div class="photo-grid"><article v-for="(image,i) in product.images ?? []" :key="image.id"><img :src="image.url" :alt="image.alt || 'Фото товара'" /><span v-if="i===0" class="badge neutral">Основное</span><div><button type="button" class="button" :disabled="disabled || busy || i===0" @click="reorder(image.id)"><Star :size="15" />Основное</button><button type="button" class="icon-button danger" :disabled="disabled || busy" :aria-label="`Удалить фото ${i+1} из карточки`" @click="reorder(image.id,true)"><Trash2 :size="17" /></button></div></article></div></section>
</template>
