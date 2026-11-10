<script setup>
import Payments from '../../src/pages/Payments.vue'
import { perspective, currentUser } from './mocks.js'
function change(value) { perspective.value = value; currentUser.value = { role:value === 'platform' ? 'super_admin' : 'provider_admin' } }
</script>
<template><div style="padding:16px;background:#fff3d8;color:#614200;display:flex;gap:16px;align-items:center"><b>LOCAL QA FIXTURE — NO LIVE DATA</b><button @click="change('platform')">Platform preview</button><button @click="change('provider')">Provider preview</button></div><main style="max-width:1380px;margin:auto"><Payments :key="perspective" /></main></template>
