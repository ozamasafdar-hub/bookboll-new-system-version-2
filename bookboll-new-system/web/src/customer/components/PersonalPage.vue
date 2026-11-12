<script setup>
import { computed, inject, ref, watch, nextTick, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { isWhiteLabel, brandShows } from '../../lib/brand.js'
import { Smartphone } from 'lucide-vue-next'
import '../personalPages.css'
defineProps({ title: String, description: String, navigation: { type: Boolean, default: true } })
const route = useRoute()
const download = inject('customerAppDownload', null)
const navigationBar = ref(null)
async function revealCurrentPage() {
  await nextTick()
  const bar = navigationBar.value
  const selected = bar?.querySelector('.selected')
  if (!selected) return
  const outer = bar.getBoundingClientRect(), item = selected.getBoundingClientRect()
  bar.scrollBy({ left: item.left < outer.left ? item.left - outer.left : item.right > outer.right ? item.right - outer.right : 0 })
}
onMounted(revealCurrentPage)
watch(() => route.path, revealCurrentPage)
watch(navigationBar, revealCurrentPage)
const links = computed(() => [
  { to: '/my-bookings', label: 'Bookings' },
  ...(brandShows('events') ? [{ to: '/my-games', label: 'Events' }] : []),
  ...(!isWhiteLabel.value ? [{ to: '/my-communities', label: 'Communities' }, { to: `/u/${customer.value?.id}`, label: 'My profile' }] : []),
  { to: '/account', label: 'Account settings' },
])
</script>
<template>
  <div class="personal-page">
    <header v-if="title" class="personal-header">
      <div><h1>{{ title }}</h1><p v-if="description">{{ description }}</p></div>
      <div class="personal-actions"><button v-if="download?.available.value" type="button" class="personal-button" @click="download.open()"><Smartphone :size="17" />Get the app</button><slot name="actions" /></div>
    </header>
    <nav v-if="navigation && isCustomer" ref="navigationBar" class="personal-nav" aria-label="My account pages">
      <router-link v-for="link in links" :key="link.to" :to="link.to" :class="{ selected: route.path.replace(/^\/ar(?=\/)/, '') === link.to }">{{ link.label }}</router-link>
    </nav>
    <slot />
  </div>
</template>
