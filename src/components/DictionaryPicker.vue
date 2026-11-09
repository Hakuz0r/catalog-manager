<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getDictionaries, type Dictionary } from '../domain/api'
import type { Attribute } from '../domain/catalog'
const emit=defineEmits<{add:[attribute:Attribute]}>();const dictionaries=ref<Dictionary[]>([]);const chosen=ref(0);const error=ref('')
onMounted(async()=>{try{dictionaries.value=(await getDictionaries()).filter(d=>d.registered)}catch(e){error.value=e instanceof Error?e.message:'Ошибка загрузки'}})
function add(){const d=dictionaries.value.find(d=>d.id===chosen.value);if(d){emit('add',{name:d.name,key:d.key,id:d.id,taxonomy:true,values:[]});chosen.value=0}}
</script>
<template><div class="inline-create dictionary-picker"><select v-model="chosen" aria-label="Выбрать общий справочник"><option :value="0">Добавить характеристику из справочника…</option><option v-for="d in dictionaries" :key="d.id" :value="d.id">{{ d.name }} · {{ d.key }}</option></select><button type="button" class="button" :disabled="!chosen" @click="add">Добавить из справочника</button><p v-if="error" class="error-notice">{{ error }}</p></div></template>
