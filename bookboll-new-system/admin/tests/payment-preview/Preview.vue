<script setup>
import Payments from '../../src/pages/Payments.vue'
import { perspective, currentUser } from './mocks.js'
import { adminTheme, toggleAdminTheme } from '../../src/lib/adminTheme.js'
function change(value) { perspective.value = value; currentUser.value = { role:value === 'platform' ? 'super_admin' : 'provider_admin' } }
</script>
<template><div style="padding:16px;background:var(--warning-bg);color:var(--warning-ink);display:flex;gap:16px;align-items:center"><b>LOCAL QA FIXTURE — NO LIVE DATA</b><button @click="change('platform')">Platform preview</button><button @click="change('provider')">Provider preview</button><button @click="toggleAdminTheme">{{ adminTheme === 'dark' ? 'Light mode' : 'Dark mode' }}</button></div><main style="max-width:1380px;margin:auto"><Payments :key="perspective" /></main></template>
