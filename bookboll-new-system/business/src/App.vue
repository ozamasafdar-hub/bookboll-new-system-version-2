<script setup>
const railwayWorkspace = import.meta.env.VITE_BOOKBOLL_WORKSPACE === 'true'
import CookieConsent from './components/CookieConsent.vue'
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'
import { loadAdminBrand } from './lib/adminBrand.js'
import { useHead } from '@unhead/vue'

const route = useRoute()
onMounted(() => { loadAdminBrand() })

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
  <CookieConsent scope="business" />
</template>

<style>
.railway-workspace-notice { padding: 8px 16px; background: #fff4d6; color: #684a12; text-align: center; font-size: 12px; line-height: 1.4; }
[data-theme="dark"] .railway-workspace-notice { background: #352b1c; color: #ffcf72; }
</style>
