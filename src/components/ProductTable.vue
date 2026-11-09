<script setup lang="ts">
import { ref } from 'vue'
const failedImages = ref<number[]>([])
import { ArrowUpDown, ChevronRight, Package } from '@lucide/vue'
import { priceFormat, unitLabels, type Product, type Category } from '../domain/catalog'
const props = defineProps<{ products: Product[]; categories: Category[]; draftIds: string[]; selected: number[]; sortAscending: boolean; selectable?: boolean }>()
const emit = defineEmits<{ open: [product: Product]; select: [id: number]; selectPage: []; sort: [] }>()
function startDrag(event:DragEvent,id:number){event.dataTransfer?.setData('application/x-ukm-products',JSON.stringify(props.selected.includes(id)?props.selected:[id]));if(event.dataTransfer)event.dataTransfer.effectAllowed='move'}
</script>
<template>
  <div class="table-scroll">
    <table class="products-table">
      <thead><tr>
        <th class="check-cell"><input type="checkbox" :disabled="selectable===false" aria-label="Выбрать товары на странице" :checked="products.length > 0 && products.every(p => selected.includes(p.id))" :indeterminate="products.some(p => selected.includes(p.id)) && !products.every(p => selected.includes(p.id))" @change="emit('selectPage')" /></th>
        <th><button class="table-heading" @click="emit('sort')">Название <ArrowUpDown :size="14" /><span class="sr-only">{{ sortAscending ? 'по возрастанию' : 'по убыванию' }}</span></button></th>
        <th>ID товара</th><th>Категория</th><th class="numeric">Основная цена</th><th>Изменения</th><th><span class="sr-only">Открыть</span></th>
      </tr></thead>
      <tbody>
        <tr :draggable="selectable!==false" @dragstart="startDrag($event,p.id)" v-for="p in products" :key="p.id" :class="{ selected: selected.includes(p.id) }">
          <td class="check-cell"><input type="checkbox" :disabled="selectable===false" :aria-label="`Выбрать ${p.name}`" :checked="selected.includes(p.id)" @change="emit('select', p.id)" /></td>
          <td><button class="product-name" @click="emit('open', p)"><span class="product-thumbnail"><img v-if="p.imageUrl && !failedImages.includes(p.id)" :src="p.imageUrl" alt="" loading="lazy" @error="failedImages.push(p.id)" /><Package v-else :size="24" :stroke-width="1.4" /></span><span>{{ p.name }}</span></button></td>
          <td class="muted id-cell">{{ p.id }}</td><td class="category-cell">{{ categories.find(c => c.id === p.categoryId)?.name }}</td>
          <td class="numeric price-cell"><template v-if="p.basePrice !== null">{{ priceFormat.format(p.basePrice) }} <span class="currency">₽ <span class="muted">/ {{ unitLabels[p.baseUnit ?? ''] ?? p.baseUnit ?? 'ед. не определена' }}</span></span></template><span v-else class="muted">Узнать цену</span></td>
          <td><span v-if="draftIds.includes(String(p.id))" class="badge amber">Черновик</span><span v-else class="badge neutral">Без правок</span></td>
          <td><button class="icon-button" :aria-label="`Открыть товар ${p.id}`" @click="emit('open', p)"><ChevronRight :size="17" /></button></td>
        </tr>
        <tr v-if="!products.length"><td colspan="7"><div class="empty-state"><Package :size="32" /><strong>Товары не найдены</strong><p>Попробуйте другое название или сбросьте условия поиска.</p></div></td></tr>
      </tbody>
    </table>
  </div>
</template>
