<script setup>
import CookieConsent from './components/CookieConsent.vue'
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'
import { loadAdminBrand } from './lib/adminBrand.js'
import { loadPlatformTracking } from './lib/brand.js'
import { useStorefrontTracking } from './lib/tracking.js'
import { useHead } from '@unhead/vue'

const route = useRoute()
// The B2B site is a public, indexed marketing funnel ending in /signup, so it
// carries the platform's tags like the storefront does. Load the IDs first:
// useStorefrontTracking() watches platformBrand and injects once they arrive.
onMounted(() => { loadAdminBrand(); loadPlatformTracking() })
useStorefrontTracking()

useHead(computed(() => route.meta?.indexable ? {} : {
  title: 'Bookboll for Business',
  meta: [{ name: 'robots', content: 'noindex, nofollow' }],
}))
</script>

<template>
  <router-view />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
  <CookieConsent scope="business" />
</template>
