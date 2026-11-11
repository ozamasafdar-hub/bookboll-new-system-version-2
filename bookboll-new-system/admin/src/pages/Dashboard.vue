<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BookOpen } from 'lucide-vue-next'
import PaymentAnalytics from '../components/PaymentAnalytics.vue'
import ProgramActivity from '../components/ProgramActivity.vue'
import Bookings from './Bookings.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
const { can, currentOwnerUserId }=useCurrentUser()
const route=useRoute(), router=useRouter()
const analyticsTab=computed(()=>route.query.tab==='payments' && can(CAP.ACCEPT_PAYMENTS) ? 'payments' : route.query.tab==='programs' ? 'programs' : 'activity')
function setAnalyticsTab(tab){ router.replace({query:{...route.query,tab:tab==='activity' ? undefined : tab}}) }
</script>
<template>
  <div class="page">
    <div class="page-header"><div><h1 class="page-title">Business analytics</h1><div class="page-subtitle">Booking activity and payment performance for your business.</div></div><router-link to="/bookings" class="btn btn-primary"><BookOpen :size="16" />Manage bookings</router-link></div>
    <nav class="analytics-tabs" aria-label="Analytics views"><button :aria-pressed="analyticsTab==='activity'" :class="{active:analyticsTab==='activity'}" @click="setAnalyticsTab('activity')">Bookings &amp; activity</button><button v-if="can(CAP.ACCEPT_PAYMENTS)" :aria-pressed="analyticsTab==='payments'" :class="{active:analyticsTab==='payments'}" @click="setAnalyticsTab('payments')">Payments &amp; revenue</button><button :aria-pressed="analyticsTab==='programs'" :class="{active:analyticsTab==='programs'}" @click="setAnalyticsTab('programs')">Memberships &amp; events</button></nav>
    <PaymentAnalytics v-if="analyticsTab==='payments'" :key="currentOwnerUserId || 'platform'" />
    <Bookings v-else-if="analyticsTab==='activity'" analytics :key="currentOwnerUserId || 'platform'" />
    <ProgramActivity v-else :key="currentOwnerUserId || 'platform'" />
  </div>
</template>
<style scoped>
.analytics-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--border);margin-bottom:24px}.analytics-tabs button{padding:13px 16px;border:0;border-bottom:2px solid transparent;background:none;color:var(--muted);font:inherit;font-size:14px;font-weight:600;cursor:pointer}.analytics-tabs button.active{color:var(--primary);border-bottom-color:var(--primary)}.analytics-tabs button:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
</style>
