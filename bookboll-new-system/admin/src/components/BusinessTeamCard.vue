<script setup>
import { Building2, ChevronDown, Plus, ExternalLink, Phone, MessageCircle, Pencil, KeyRound, Trash2, Crown, Copy, UsersRound } from 'lucide-vue-next'
import { roleLabel } from '../lib/permissions.js'
import { ownerAccessWarning } from '../lib/businessTeam.js'
const props=defineProps({ group:Object, expanded:Boolean, superadmin:Boolean, currentUserId:[Number,String], website:String, country:String, premium:Boolean, scopeLabel:Function, lastSeen:Function })
defineEmits(['toggle','invite','assign','edit','security','remove','plan','contact','copyWebsite','copyPhone'])
const initials = name => (name || '?').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase()
const blocked = member => ownerAccessWarning(member,props.group.staff,props.currentUserId)
const businessStatus = status => ({active:'Active business',approved:'Active business',pending:'Awaiting approval',suspended:'Suspended business',rejected:'Not approved'}[status] || 'Status unavailable')
</script>
<template>
  <article class="business-entry" :class="{expanded}" :aria-label="group.provider.business_name">
    <div class="business-summary">
      <button class="business-heading" :aria-expanded="expanded" :aria-controls="`business-team-${group.provider.business_id}`" @click="$emit('toggle')">
        <span class="business-symbol"><Building2 :size="19" /></span>
        <span><strong>{{ group.provider.business_name || group.provider.full_name }}</strong><small>@{{ group.provider.username }}<span v-if="country"> / {{ country }}</span></small></span>
      </button>
      <div class="business-counts"><strong>{{ group.provider.owner_count }} {{ group.provider.owner_count===1?'owner':'owners' }}</strong><small>{{ group.staff.length }} team {{ group.staff.length===1?'member':'members' }}</small></div>
      <div class="business-actions">
        <a v-if="website" :href="website" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><ExternalLink :size="14" />{{ group.provider.brand_enabled?'Website':'Preview site' }}</a>
        <button v-if="!group.provider.owner_count" class="btn btn-sm btn-primary" @click="$emit('invite')"><Plus :size="14" />Add owner</button>
        <button class="btn btn-sm" :class="expanded?'btn-secondary':'btn-primary'" :aria-expanded="expanded" :aria-controls="`business-team-${group.provider.business_id}`" :aria-label="`${expanded?'Close':'Manage'} team for ${group.provider.business_name}`" @click="$emit('toggle')">{{ expanded?'Close team':'Manage team' }}<ChevronDown :size="14" :class="{rotated:expanded}" /></button>
      </div>
    </div>
    <div class="business-facts">
      <span class="badge" :class="premium?'badge-warning':'badge-neutral'">{{ premium?'Premium':'Free' }}{{ group.provider.plan_override==='premium'?' · complimentary':'' }}</span>
      <span class="business-state" :class="{warning:!['active','approved'].includes(group.provider.business_status)}">{{ businessStatus(group.provider.business_status) }}</span>
      <span class="website-state">Website {{ group.provider.brand_enabled?'published':'draft' }}</span>
      <span class="contact-actions" v-if="group.provider.contact">
        <a :href="group.provider.contact.call" :aria-label="'Call '+group.provider.business_name"><Phone :size="13" /><span dir="ltr">{{ group.provider.contact.phone }}</span></a>
        <a :href="group.provider.contact.whatsapp" target="_blank" rel="noopener" :aria-label="'WhatsApp '+group.provider.business_name"><MessageCircle :size="14" />WhatsApp</a>
      </span>
      <button v-else-if="superadmin" class="missing-contact" @click="$emit('contact')"><Phone :size="13" />{{group.provider.phone?'Fix phone number':'Add business phone'}}</button>
      <span v-else class="website-state">Business phone missing</span>
    </div>
    <section v-if="expanded" :id="`business-team-${group.provider.business_id}`" class="business-team-panel">
      <div class="business-team-toolbar"><div><h3>Owners &amp; staff</h3><p>Owners share full access. Staff follow their assigned permissions.</p></div><button class="btn btn-sm btn-primary" @click="$emit('invite')"><Plus :size="14" />Add owner or staff</button></div>
      <div v-if="!group.provider.active_owner_count" class="owner-attention">This business needs an active owner to manage its team and website.</div>
      <table v-if="group.staff.length" class="business-members">
        <thead><tr><th>Person</th><th>Venue access</th><th>Sign-in</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody><tr v-for="member in group.staff" :key="member.id">
          <td><div class="member-person"><span class="member-avatar" :class="{owner:member.role==='provider_admin'}">{{ initials(member.full_name) }}</span><div><strong>{{ member.full_name }}<small v-if="String(member.id)===String(currentUserId)" class="you-label">You</small></strong><span class="member-email">{{ member.email }}</span><div class="member-labels"><span class="badge" :class="member.role==='provider_admin'?'badge-info':'badge-neutral'">{{ roleLabel(member.role) }}</span><small v-if="member.is_primary_owner" title="Contact label only. Every owner has the same access.">Primary contact</small></div></div></div></td>
          <td class="member-scope" data-label="Venue access">{{ scopeLabel(member) }}</td>
          <td data-label="Sign-in"><span class="badge" :class="member.status==='active'?'badge-success':'badge-neutral'">{{member.status==='active'?'Active':member.status==='blocked'?'Blocked':'Inactive'}}</span><small class="member-last-seen">{{ member.last_login ? lastSeen(member.last_login) : 'Never signed in' }}</small></td>
          <td class="member-actions"><button class="btn btn-sm btn-secondary" :aria-label="'Edit access for '+member.full_name" @click="$emit('edit',member)"><Pencil :size="13" />Edit access</button>
            <details class="member-more"><summary :aria-label="'More actions for '+member.full_name">More<ChevronDown :size="13" /></summary><div>
              <button v-if="superadmin && String(member.id)!==String(currentUserId)" @click="$emit('security',member)"><KeyRound :size="14" />Password &amp; security</button>
              <button v-if="member.role!=='provider_admin' || superadmin" :disabled="!!blocked(member)" :title="blocked(member)" @click="$emit('remove',member)"><Trash2 :size="14" />Remove team access</button>
              <small v-if="blocked(member)">{{blocked(member)}}</small>
            </div></details>
          </td>
        </tr></tbody>
      </table>
      <p v-else class="business-team-empty">Start by adding an owner. You can add managers and staff afterward.</p>
      <details class="business-settings"><summary>Business settings &amp; existing accounts<ChevronDown :size="14" /></summary><div>
        <button v-if="superadmin" class="btn btn-sm btn-secondary" @click="$emit('plan')"><Crown :size="14" />Manage plan</button>
        <button v-if="superadmin" class="btn btn-sm btn-secondary" @click="$emit('contact')"><Phone :size="14" />Edit business phone</button>
        <button v-if="website" class="btn btn-sm btn-secondary" @click="$emit('copyWebsite')"><Copy :size="14" />Copy website link</button>
        <button v-if="group.provider.contact" class="btn btn-sm btn-secondary" @click="$emit('copyPhone')"><Copy :size="14" />Copy phone</button>
        <button class="btn btn-sm btn-secondary" @click="$emit('assign')"><UsersRound :size="14" />Move platform account here</button>
        <button v-if="group.provider.id" class="btn btn-sm btn-secondary" @click="$emit('edit',group.provider)"><Pencil :size="14" />Business details</button>
      </div><p>Moving an existing platform account requires a review of its new business access.</p></details>
    </section>
  </article>
</template>
<style scoped>
.business-entry{border:1px solid var(--border);border-radius:12px;background:var(--surface);margin-bottom:12px;min-width:0}.business-entry.expanded{border-color:var(--primary-border,var(--primary))}.business-summary{display:flex;align-items:center;gap:22px;padding:18px 20px 12px}.business-heading{display:flex;align-items:center;gap:12px;flex:1;min-width:0;text-align:left;background:none;border:0;color:var(--text);padding:0;cursor:pointer}.business-heading strong{display:block;font-size:15px;line-height:1.5;overflow-wrap:anywhere}.business-heading small{display:block;color:var(--text-muted);font-size:12px;margin-top:3px;overflow-wrap:anywhere}.business-symbol{flex-shrink:0;width:36px;height:36px;display:grid;place-items:center;border-radius:8px;color:var(--primary-ink);background:var(--primary-soft)}.business-counts{min-width:90px;font-size:13px}.business-counts small{display:block;font-size:12px;color:var(--text-muted);margin-top:4px}.business-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.rotated{transform:rotate(180deg)}.business-facts{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:0 20px 16px 68px;font-size:12px}.business-state{color:var(--text-muted)}.business-state.warning{color:var(--warning-ink)}.website-state{color:var(--text-muted)}.contact-actions{margin-left:auto;display:flex;gap:18px;flex-wrap:wrap}.contact-actions a,.missing-contact{display:inline-flex;align-items:center;gap:6px;color:var(--primary-ink);font-size:12px}.missing-contact{border:0;background:none;padding:0;cursor:pointer;margin-left:auto;text-decoration:underline;text-underline-offset:3px}.business-team-panel{border-top:1px solid var(--border);background:var(--bg);border-radius:0 0 12px 12px}.business-team-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px}.business-team-toolbar h3{font-size:14px;margin:0}.business-team-toolbar p{font-size:12px;color:var(--text-muted);margin:5px 0 0}.owner-attention{margin:0 20px 16px;padding:12px;background:var(--warning-bg);color:var(--warning-ink);border-radius:8px;font-size:13px}.business-members{width:100%;border-collapse:collapse;table-layout:auto}.business-members th{text-align:left;font-size:12px;font-weight:500;padding:10px 20px;border-block:1px solid var(--border);color:var(--text-muted);text-transform:none;letter-spacing:normal}.business-members td{padding:16px 20px;border-bottom:1px solid var(--border);vertical-align:middle}.member-person{display:flex;align-items:flex-start;gap:10px;min-width:180px}.member-person strong{display:block;font-size:13px;line-height:1.5;overflow-wrap:anywhere}.member-avatar{width:28px;height:28px;flex-shrink:0;display:grid;place-items:center;font-size:10px;border-radius:50%;background:var(--surface-hover);color:var(--text-muted)}.member-avatar.owner{background:var(--primary-soft);color:var(--primary-ink)}.member-email{display:block;font-size:12px;color:var(--text-muted);overflow-wrap:anywhere;max-width:300px}.member-labels{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px}.member-labels small,.member-last-seen{font-size:11px;color:var(--text-muted)}.you-label{font-size:11px;color:var(--text-muted);font-weight:400;margin-left:8px}.member-last-seen{display:block;margin-top:6px;white-space:nowrap}.member-scope{font-size:12px;color:var(--text-muted);max-width:190px}.member-actions{width:145px}.member-actions>.btn{white-space:nowrap}.member-more{margin-top:7px;position:relative;font-size:12px}.member-more summary{display:inline-flex;gap:5px;align-items:center;cursor:pointer;color:var(--text-muted);padding:4px}.member-more>div{position:absolute;right:0;top:100%;z-index:8;min-width:225px;max-width:270px;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 6px 18px #0002}.member-more button{display:flex;align-items:center;gap:8px;width:100%;border:0;background:none;color:var(--text);padding:10px;text-align:left;font-size:12px;border-radius:5px;cursor:pointer}.member-more button:hover{background:var(--surface-hover)}.member-more button:disabled{opacity:.5;cursor:not-allowed}.member-more small{display:block;padding:4px 10px;color:var(--text-muted);line-height:1.5}.business-settings{padding:15px 20px;color:var(--text-muted);font-size:12px}.business-settings summary{display:flex;gap:8px;align-items:center;cursor:pointer;width:fit-content}.business-settings>div{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.business-settings p{font-size:12px;margin-bottom:0}.business-team-empty{padding:16px 20px;margin:0;color:var(--text-muted);font-size:13px}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}button:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
@media(max-width:1100px){.business-summary{flex-wrap:wrap;gap:12px}.business-heading{flex-basis:60%}.business-actions{margin-left:48px}.business-facts{padding-left:20px}.contact-actions{margin-left:0}}
@media(max-width:650px){.business-summary{padding:16px}.business-heading{flex-basis:70%}.business-counts{min-width:65px}.business-actions{margin-left:0;flex-basis:100%}.business-facts{padding:0 16px 16px;gap:12px}.contact-actions{flex-basis:100%}.business-team-toolbar{padding:16px;align-items:flex-start;flex-direction:column}.business-members,.business-members tbody,.business-members tr,.business-members td{display:block;width:auto}.business-members thead{display:none}.business-members tr{padding:14px 16px;border-top:1px solid var(--border)}.business-members td{border:0;padding:5px 0}.member-person{min-width:0}.member-scope{max-width:none}.member-email{max-width:none}.member-actions{display:flex!important;align-items:center;gap:14px;position:relative}.member-more{margin-top:0;position:static}.member-more>div{right:0;left:auto}.member-last-seen{display:inline-block;margin-left:9px}.business-members td[data-label]::before{content:attr(data-label) ': ';color:var(--text-muted);font-size:12px}.business-settings{padding:16px}.business-team-toolbar p{max-width:40ch}}
</style>
