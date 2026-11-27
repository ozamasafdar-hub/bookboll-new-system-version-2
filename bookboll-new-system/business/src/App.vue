<script setup>
const railwayWorkspace = import.meta.env.VITE_BOOKBOLL_WORKSPACE === 'true'
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
  <div v-if="railwayWorkspace" class="railway-workspace-notice" role="status">Railway workspace · Shared database copy · Real payments and messages disabled</div>
  <router-view />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
</template>

<style>
.railway-workspace-notice { padding: 8px 16px; background: #fff4d6; color: #684a12; text-align: center; font-size: 12px; line-height: 1.4; }
[data-theme="dark"] .railway-workspace-notice { background: #352b1c; color: #ffcf72; }
</style>
