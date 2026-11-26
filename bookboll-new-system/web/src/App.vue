<script setup>
const railwayWorkspace = import.meta.env.VITE_BOOKBOLL_WORKSPACE === 'true'
import { onMounted } from 'vue'
import CustomerLayout from './customer/CustomerLayout.vue'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import InputDialog from './components/InputDialog.vue'

onMounted(() => {
  document.documentElement.classList.remove('adm-shell')
})
</script>

<template>
  <div v-if="railwayWorkspace" class="railway-workspace-notice" role="status">Railway workspace · Shared database copy · Real payments and messages disabled</div>
  <CustomerLayout />
  <ToastHost />
  <ConfirmDialog />
  <InputDialog />
</template>

<style>
.railway-workspace-notice { padding: 8px 16px; background: #fff4d6; color: #684a12; text-align: center; font-size: 12px; line-height: 1.4; }
[data-theme="dark"] .railway-workspace-notice { background: #352b1c; color: #ffcf72; }
</style>
