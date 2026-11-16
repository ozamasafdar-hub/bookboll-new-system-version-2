<script setup>
import ChatComposer from '../components/ChatComposer.vue'
import ChatMessageBody from '../components/ChatMessageBody.vue'
import { COMMUNITY_AUDIENCES, communityRole, canContribute, canModerateCommunity, permissionFor, permissionLabel } from '../../lib/communityAccess.js'
import { filterConversations } from '../../lib/communityPresentation.js'
import { askConfirm } from '../../lib/confirm.js'
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { loadInbox, loadThreadWithPeer, sendMessage, markThreadRead, refreshUnread } from '../../lib/social.js'
import { groupKey, loadGroupThread, sendGroupMessage, markCommunityChatRead, isMember, canManage, loadCommunity, leaveCommunity, deleteGroupMessage, updateCommunityChatPermission } from '../../customer/community.js'
import { useSeo } from '../../lib/seo.js'
import { fmt12 } from '../../lib/slots.js'
import { ArrowLeft, Send, MessageCircle, User, UsersRound, Lock, LogOut, Trash2, Search, X, Settings } from 'lucide-vue-next'

useSeo({ title: 'Messages', noindex: true, path: '/messages' })

const route = useRoute()
const router = useRouter()
const me = computed(() => customer.value?.id || null)
// Return to wherever you opened Messages from; fall back to Home on a cold load.
function goBack() { if (window.history.state?.back) router.back(); else router.push('/') }

const convos = ref([])
const inboxQuery = ref('')
const filteredConvos = computed(() => filterConversations(convos.value, inboxQuery.value))
const actionError = ref('')
const leaving = ref(false)
const deleting = ref('')
let requestVersion = 0
const loadingConvos = ref(true)
const peerId = computed(() => route.params.id || null)
const peer = ref(null)
const thread = ref([])
const loadingThread = ref(false)
const draft = ref('')
const sending = ref(false)
const scroller = ref(null)
let poll = null

// ── Community group chats live in this inbox too (route id 'community__<id>') ──
const GROUP_PREFIX = 'community__'
const isGroup = computed(() => String(peerId.value || '').startsWith(GROUP_PREFIX))
const groupCid = computed(() => isGroup.value ? String(peerId.value).slice(GROUP_PREFIX.length) : null)
const groupInfo = ref(null)   // the community (+ viewer membership) behind the open group thread
const amGroupMember = computed(() => {
  const cm = groupInfo.value
  return !!cm && (isMember(cm.membership) || cm.owner_customer_id === me.value)
})
const amGroupManager = computed(() => {
  const cm = groupInfo.value
  return !!cm && (canManage(cm.membership) || cm.owner_customer_id === me.value)
})
const groupLocked = computed(() => permissionFor(groupInfo.value?.settings, 'chat') !== 'members')
const canPostGroup = computed(() => canContribute(groupInfo.value, me.value, 'chat'))
const canModerateGroup = computed(() => canModerateCommunity(groupInfo.value, me.value))
const isGroupOwner = computed(() => communityRole(groupInfo.value, me.value) === 'owner')
const chatPermissionsOpen = ref(false)
const chatPermission = ref('members')
const chatPermissionBusy = ref(false)
const chatPermissionError = ref('')
const chatPermissionMessage = ref('')
const chatPermissionPanel = ref(null)
const chatPermissionButton = ref(null)
async function openChatPermissions() {
  if (!canModerateGroup.value) return
  ++requestVersion // Discard an older poll's settings while this panel is open.
  chatPermission.value = permissionFor(groupInfo.value?.settings, 'chat')
  chatPermissionError.value = ''; chatPermissionMessage.value = ''
  chatPermissionsOpen.value = true
  await nextTick()
  chatPermissionPanel.value?.querySelector('input:checked')?.focus()
}
function closeChatPermissions() {
  if (chatPermissionBusy.value) return
  chatPermissionsOpen.value = false
  chatPermissionButton.value?.focus()
}
async function saveChatPermissions() {
  if (!canModerateGroup.value || chatPermissionBusy.value) return
  const id = groupCid.value, viewer = me.value
  chatPermissionBusy.value = true; chatPermissionError.value = ''
  try {
    const result = await updateCommunityChatPermission(id, chatPermission.value)
    if (id !== groupCid.value || viewer !== me.value) return
    if (result.community) groupInfo.value = result.community
    if (result.error) { chatPermissionError.value = result.error; return }
    chatPermissionMessage.value = 'Chat permissions saved.'
    chatPermissionsOpen.value = false
    chatPermissionButton.value?.focus()
  } catch { if (id === groupCid.value) chatPermissionError.value = 'Could not save chat permissions. Please try again.' }
  finally { chatPermissionBusy.value = false }
}
async function leaveGroup() {
  if (!amGroupMember.value || isGroupOwner.value || leaving.value) return
  const id = groupCid.value
  if (!(await askConfirm({ title: 'Leave ' + groupInfo.value.name + '?', message: 'You will leave the community and its group chat. Private communities require approval to rejoin.', confirmText: 'Leave community', danger: true }))) return
  leaving.value = true; actionError.value = ''
  const result = await leaveCommunity(id, me.value)
  leaving.value = false
  if (result.error) { actionError.value = result.error; return }
  await router.push('/messages')
  await loadList()
}
async function removeMessage(message) {
  if (!canModerateGroup.value || deleting.value) return
  const key = groupKey('community', groupCid.value)
  if (!(await askConfirm({ title: 'Delete this group message?', message: 'It will be removed for everyone in this community.', confirmText: 'Delete message', danger: true }))) return
  deleting.value = message.id; actionError.value = ''
  const result = await deleteGroupMessage(key, message.id)
  deleting.value = ''
  if (result.error) { actionError.value = result.error; return }
  if (key === peerId.value) thread.value = thread.value.filter(item => item.id !== message.id)
  loadList()
}

// Direct conversations AND community group chats come back merged and sorted.
async function loadList() {
  convos.value = await loadInbox()
  loadingConvos.value = false
}
async function openThread(refresh = false) {
  const version = ++requestVersion
  const id = peerId.value, viewer = me.value
  if (!refresh) { draft.value = ''; actionError.value = ''; peer.value = null; thread.value = []; groupInfo.value = null; chatPermissionsOpen.value = false; chatPermissionMessage.value = '' }
  if (!id || !viewer) return
  if (!refresh) loadingThread.value = true
  const communityChat = String(id).startsWith(GROUP_PREFIX)
  if (communityChat) {
    const cid = String(id).slice(GROUP_PREFIX.length)
    const cm = await loadCommunity(cid, viewer)
    if (version !== requestVersion || id !== peerId.value) return
    groupInfo.value = cm
    peer.value = cm ? { id: cm.id, full_name: cm.name, avatar_url: cm.avatar_image || cm.cover_image || '' } : { id: cid, full_name: 'Community' }
    const messages = communityRole(cm, viewer) !== 'visitor' ? await loadGroupThread(id) : []
    if (version !== requestVersion || id !== peerId.value) return
    thread.value = messages
    if (amGroupMember.value) markCommunityChatRead(cid, viewer)
  } else {
    const result = await loadThreadWithPeer(id)
    if (version !== requestVersion || id !== peerId.value) return
    groupInfo.value = null; peer.value = result.peer || {id,full_name:'Player'}; thread.value = result.messages
    await markThreadRead(viewer, id)
    refreshUnread(viewer)
  }
  loadingThread.value = false
  const convo = convos.value.find(item => item.peerId === id); if (convo) convo.unread = 0
  if (!refresh) { await nextTick(); scrollDown() }
}
function scrollDown() { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight }

async function send(mentions = []) {
  const body = draft.value.trim()
  if (!body || sending.value || !peerId.value) return
  sending.value = true
  actionError.value = ''
  const target = peerId.value
  if (isGroup.value) {
    if (!canPostGroup.value) { sending.value = false; return }
    const r = await sendGroupMessage(groupKey('community', groupCid.value), me.value, body, mentions)
    sending.value = false
    if (r.error) { actionError.value = r.error; return }
    if (target !== peerId.value) { loadList(); return }
    draft.value = ''
    thread.value.push({ ...r.message, senderName: customer.value?.full_name || 'You', senderAvatar: customer.value?.avatar_url || '' })
  } else {
    const r = await sendMessage(me.value, peerId.value, body)
    sending.value = false
    if (r.error) { actionError.value = r.error; return }
    if (target !== peerId.value) { loadList(); return }
    draft.value = ''
    thread.value.push(r.message)
  }
  await nextTick(); scrollDown()
  loadList()
}

function fmt(iso) { return fmt12(new Date(iso)) }
function dayLabel(iso) { return new Date(iso).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }
function showDay(i) { if (i === 0) return true; return new Date(thread.value[i].created_at).toDateString() !== new Date(thread.value[i - 1].created_at).toDateString() }
function initial(n) { return (n || '?').trim()[0]?.toUpperCase() || '?' }

onMounted(() => {
  if (!isCustomer.value) return
  loadList(); openThread()
  // Light polling so a new incoming message appears without a manual refresh.
  poll = setInterval(async () => {
    if (peerId.value && !sending.value && !deleting.value && !leaving.value && !chatPermissionsOpen.value && !chatPermissionBusy.value) await openThread(true)
    loadList(); refreshUnread(me.value)
  }, 12000)
})
onUnmounted(() => { if (poll) clearInterval(poll) })
watch(peerId, () => openThread())
watch(me, () => { if (me.value) { loadList(); openThread() } else { requestVersion++; convos.value=[]; thread.value=[]; groupInfo.value=null } })
</script>

<template>
  <div class="ms">
    <template v-if="!isCustomer">
      <div class="ms-signin">
        <MessageCircle :size="34" />
        <h1>Your messages</h1>
        <p>Sign in to message other players and hosts.</p>
        <router-link to="/account" class="ms-signin-btn">Sign in</router-link>
      </div>
    </template>

    <template v-else>
      <div class="ms-wrap" :class="{ 'has-thread': peerId }">
        <!-- Conversation list -->
        <aside class="ms-list">
          <div class="ms-h-row">
            <button type="button" class="ms-topback" @click="goBack" aria-label="Back"><ArrowLeft :size="18" /></button>
            <h1 class="ms-h">Messages</h1>
          </div>
          <label class="ms-inbox-search"><Search :size="18" /><input v-model="inboxQuery" type="search" aria-label="Search conversations" placeholder="Search messages or people" /></label>
          <div v-if="!loadingConvos && convos.length && !filteredConvos.length" class="ms-msg">No conversations match “{{ inboxQuery }}”.<button class="discover-text-button" @click="inboxQuery=''">Clear search</button></div>
          <div v-if="loadingConvos" class="ms-msg">Loading…</div>
          <div v-else-if="!convos.length" class="ms-msg">No conversations yet. Visit a player's profile and tap <b>Message</b> to start one.</div>
          <router-link v-for="c in filteredConvos" :key="c.peerId" :to="`/messages/${c.peerId}`" class="ms-convo" :class="{ active: c.peerId === peerId }" data-no-translate>
            <span class="ms-av" :class="{ img: c.peer.avatar_url, grp: c.group }" :style="c.peer.avatar_url ? { backgroundImage: `url(${c.peer.avatar_url})` } : {}">
              <UsersRound v-if="c.group && !c.peer.avatar_url" :size="18" />
              <span v-else-if="!c.peer.avatar_url">{{ initial(c.peer.full_name) }}</span>
            </span>
            <span class="ms-convo-mid">
              <span class="ms-convo-name">{{ c.peer.full_name || 'Player' }}<em v-if="c.group" class="ms-grp-tag">Community</em></span>
              <span class="ms-convo-last">{{ c.lastMine ? 'You: ' : '' }}{{ c.lastBody }}</span>
            </span>
            <span v-if="c.unread" class="ms-badge">{{ c.unread }}</span>
          </router-link>
        </aside>

        <!-- Thread -->
        <section class="ms-thread">
          <template v-if="peerId">
            <div class="ms-thread-head">
              <router-link to="/messages" class="ms-back"><ArrowLeft :size="18" /></router-link>
              <router-link v-if="peer" :to="isGroup ? `/communities/${groupCid}` : `/u/${peer.id}`" class="ms-peer" data-no-translate>
                <span class="ms-av sm" :class="{ img: peer.avatar_url, grp: isGroup }" :style="peer.avatar_url ? { backgroundImage: `url(${peer.avatar_url})` } : {}">
                  <UsersRound v-if="isGroup && !peer.avatar_url" :size="15" />
                  <span v-else-if="!peer.avatar_url">{{ initial(peer.full_name) }}</span>
                </span>
                <span class="ms-peer-mid">
                  <b>{{ peer.full_name || 'Player' }}</b>
                  <small v-if="isGroup">{{ permissionLabel(permissionFor(groupInfo?.settings, 'chat')) }} can send · Members can read</small>
                </span>
              </router-link>
              <button v-if="isGroup && canModerateGroup" ref="chatPermissionButton" type="button" class="ms-head-icon ms-permission-button" title="Chat permissions" aria-label="Chat permissions" :aria-expanded="chatPermissionsOpen" aria-controls="chat-permissions" @click="chatPermissionsOpen ? closeChatPermissions() : openChatPermissions()"><Settings :size="18" /><span>Permissions</span></button>
              <button v-if="isGroup && amGroupMember" type="button" class="ms-head-icon danger" :disabled="leaving || isGroupOwner" :title="isGroupOwner ? 'The owner must transfer ownership before leaving' : 'Leave community'" :aria-label="isGroupOwner ? 'Community owner cannot leave' : 'Leave community'" @click="leaveGroup"><LogOut :size="18" /></button>
            </div>
            <section v-if="chatPermissionsOpen && canModerateGroup" id="chat-permissions" ref="chatPermissionPanel" class="ms-permission-panel" aria-label="Chat permissions" @keydown.esc.stop="closeChatPermissions">
              <div class="ms-permission-title"><div><h2>Who can send messages?</h2><p>All community members can still read the conversation.</p></div><button type="button" :disabled="chatPermissionBusy" aria-label="Close chat permissions" @click="closeChatPermissions"><X :size="18" /></button></div>
              <fieldset :disabled="chatPermissionBusy"><legend class="ms-visually-hidden">Who can send messages?</legend><label v-for="audience in COMMUNITY_AUDIENCES" :key="audience.value" :class="{selected: chatPermission === audience.value}"><input v-model="chatPermission" type="radio" name="chat-permission" :value="audience.value" /><span><strong>{{ audience.label }}</strong><small>{{ audience.detail }}</small></span></label></fieldset>
              <p v-if="chatPermission === 'admins' && !amGroupManager" class="ms-permission-hint">With Admins only, moderators can manage the chat but cannot send messages.</p>
              <p v-if="chatPermissionError" role="alert" class="ms-action-error">{{ chatPermissionError }}</p>
              <div class="ms-permission-actions"><router-link v-if="amGroupManager" :to="'/communities/' + groupCid + '?settings=1'">Community settings</router-link><button type="button" :disabled="chatPermissionBusy" @click="closeChatPermissions">Cancel</button><button type="button" class="ms-permission-save" :disabled="chatPermissionBusy || chatPermission === permissionFor(groupInfo?.settings, 'chat')" @click="saveChatPermissions">{{ chatPermissionBusy ? 'Saving…' : 'Save permissions' }}</button></div>
            </section>
            <p v-if="chatPermissionMessage" class="ms-permission-saved" role="status">{{ chatPermissionMessage }}</p>
            <p v-if="actionError" class="ms-action-error" role="alert">{{ actionError }}</p>
            <div ref="scroller" class="ms-stream" data-no-translate>
              <div v-if="loadingThread" class="ms-msg">Loading…</div>
              <div v-else-if="isGroup && !amGroupMember" class="ms-msg">Only members of {{ peer?.full_name || 'this community' }} can read the group chat. <router-link :to="`/communities/${groupCid}`">Visit the community to join →</router-link></div>
              <div v-else-if="!thread.length" class="ms-msg">{{ isGroup ? (groupLocked ? 'Updates from the community team will appear here.' : 'Say hi — every member sees this chat.') : 'Say hi 👋' }}</div>
              <template v-else v-for="(m, i) in thread" :key="m.id">
                <div v-if="showDay(i)" class="ms-day">{{ dayLabel(m.created_at) }}</div>
                <div class="ms-row" :class="{ mine: m.sender_id === me }">
                  <!-- Group chats: every sender's photo, tap → their profile -->
                  <router-link v-if="isGroup && m.sender_id !== me" :to="`/u/${m.sender_id}`" class="ms-mav" :class="{ img: m.senderAvatar }" :style="m.senderAvatar ? { backgroundImage: `url(${m.senderAvatar})` } : {}" :title="`${m.senderName} — view profile`">
                    <span v-if="!m.senderAvatar">{{ initial(m.senderName) }}</span>
                  </router-link>
                  <div class="ms-bubble" :class="{ mine: m.sender_id === me }">
                    <router-link v-if="isGroup && m.sender_id !== me" :to="`/u/${m.sender_id}`" class="ms-who">{{ m.senderName }}</router-link>
                    <ChatMessageBody :message="m" :viewer-id="me" />
                    <span class="ms-bubble-time">{{ fmt(m.created_at) }}</span>
                  </div>
                  <button v-if="isGroup && canModerateGroup" type="button" class="ms-delete-message" :disabled="!!deleting" :aria-label="'Delete message from ' + (m.senderName || 'member')" title="Delete for everyone" @click="removeMessage(m)"><Trash2 :size="15" /></button>
                </div>
              </template>
            </div>
            <ChatComposer v-if="!isGroup || canPostGroup" :key="peerId" v-model="draft" :thread-key="isGroup ? peerId : ''" :busy="sending" :placeholder="isGroup ? `Message ${peer?.full_name || 'the community'}…` : 'Write a message…'" @send="send" />
            <div v-else-if="amGroupMember" class="ms-readonly"><Lock :size="14" /> {{ permissionLabel(permissionFor(groupInfo?.settings, 'chat')) }} can send messages. You can read the conversation.</div>
          </template>
          <div v-else class="ms-empty"><MessageCircle :size="40" /><span>Select a conversation</span></div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ms-head-icon.ms-permission-button { width:auto; padding-inline:12px; gap:7px; }
.ms-permission-button span { font-size:12px; font-weight:650; }
.ms-permission-panel { padding:20px; background:var(--surface); border-bottom:1px solid var(--border); max-height:70dvh; overflow:auto; flex-shrink:0; }
.ms-permission-title { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }.ms-permission-title h2 { font-size:17px; margin:0 0 6px; }.ms-permission-title p,.ms-permission-hint { font-size:13px; line-height:1.5; color:var(--text-muted); margin:0 0 14px; }
.ms-permission-panel button { min-height:44px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); padding:8px 12px; cursor:pointer; }.ms-permission-panel button:disabled { opacity:.5; cursor:default; }
.ms-permission-title button { display:grid; place-items:center; min-width:44px; }
.ms-permission-panel fieldset { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; border:0; padding:0; margin:0 0 16px; }
.ms-permission-panel label { display:flex; align-items:flex-start; gap:8px; border:1px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; }.ms-permission-panel label.selected { border-color:var(--brand); background:var(--brand-tint); }.ms-permission-panel input { margin:3px 0 0; accent-color:var(--brand); flex-shrink:0; }.ms-permission-panel strong { display:block; font-size:13px; }.ms-permission-panel small { display:block; font-size:12px; color:var(--text-muted); line-height:1.5; margin-top:5px; }
.ms-permission-actions { display:flex; justify-content:flex-end; align-items:center; flex-wrap:wrap; gap:10px; }.ms-permission-actions a { color:var(--brand-ink,var(--brand)); font-size:13px; margin-inline-end:auto; padding-block:12px; }.ms-permission-actions .ms-permission-save { color:#fff; background:var(--brand); border-color:var(--brand); font-weight:650; }
.ms-permission-panel :is(button,input,a):focus-visible { outline:2px solid var(--brand); outline-offset:3px; }.ms-permission-saved { color:var(--brand-ink,var(--brand)); font-size:13px; margin:0; padding:12px 20px; border-bottom:1px solid var(--border); }.ms-visually-hidden { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
@media(max-width:760px) { .ms-permission-button span { display:none; }.ms-head-icon.ms-permission-button { padding:10px; }.ms-permission-panel { padding:16px; }.ms-permission-panel fieldset { grid-template-columns:1fr; gap:8px; }.ms-permission-panel label { padding:10px; }.ms-permission-actions a { flex-basis:100%; } }
.ms-signin { text-align: center; color: var(--text-light, #94a3b8); padding: 60px 20px; }
.ms-signin svg { color: var(--brand-ink, var(--brand)); }
.ms-signin h1 { font-size: 22px; font-weight: 800; color: var(--text, #0f172a); margin: 12px 0 6px; }
.ms-signin-btn { display: inline-block; margin-top: 14px; background: var(--brand); color: #fff; font-weight: 800; padding: 11px 24px; border-radius: 999px; text-decoration: none; }

.ms-wrap { display: grid; grid-template-columns: 320px 1fr; gap: 16px; height: calc(100vh - 220px); min-height: 460px; }
.ms-list { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow-y: auto; padding: 8px; }
.ms-h-row { display: flex; align-items: center; gap: 4px; margin: 8px 6px 10px; }
.ms-topback { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; flex-shrink: 0; }
.ms-topback:hover { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 10%, transparent); }
.ms-h { font-size: 18px; font-weight: 800; color: var(--text, #0f172a); margin: 0; }
.ms-msg { padding: 20px; color: var(--text-light, #94a3b8); font-size: 13.5px; line-height: 1.5; }
.ms-convo { display: flex; align-items: center; gap: 11px; padding: 10px 11px; border-radius: 12px; text-decoration: none; }
.ms-convo:hover { background: var(--canvas, #f8fafc); }
.ms-convo.active { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.ms-av { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0; }
.ms-av.img { background-size: cover; background-position: center; }
.ms-av.sm { width: 36px; height: 36px; font-size: 13px; }
.ms-convo-mid { min-width: 0; flex: 1; display: flex; flex-direction: column; }
.ms-convo-name { font-size: 14.5px; font-weight: 800; color: var(--text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-convo-last { font-size: 12.5px; color: var(--text-light, #94a3b8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-badge { background: var(--brand); color: #fff; font-size: 11px; font-weight: 800; border-radius: 999px; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; padding: 0 6px; flex-shrink: 0; }

.ms-thread { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; }
.ms-thread-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--border, #e2e8f0); }
.ms-back { display: none; color: var(--text-muted, #64748b); }
.ms-peer { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text, #0f172a); font-size: 15px; }
.ms-stream { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 6px; }
.ms-day { text-align: center; font-size: 11.5px; color: var(--text-light, #94a3b8); font-weight: 700; margin: 10px 0 4px; }
.ms-row { display: flex; align-items: flex-end; gap: 8px; }
.ms-row.mine { justify-content: flex-end; }
/* Group chats: sender avatar beside the bubble — tap to open their profile */
.ms-mav { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; flex-shrink: 0; text-decoration: none; margin-bottom: 2px; }
.ms-mav.img { background-size: cover; background-position: center; }
.ms-mav:hover { box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 40%, transparent); }
.ms-who { display: block; font-size: 11.5px; font-weight: 800; color: var(--brand-ink, var(--brand)); text-decoration: none; margin-bottom: 2px; }
.ms-who:hover { text-decoration: underline; }
.ms-bubble { max-width: 75%; background: var(--bg, #f1f5f9); color: var(--text, #0f172a); padding: 9px 13px; border-radius: 16px 16px 16px 4px; font-size: 14.5px; line-height: 1.4; }
.ms-bubble.mine { background: var(--brand); color: #fff; border-radius: 16px 16px 4px 16px; }
.ms-grp-tag { font-style: normal; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); padding: 2px 7px; border-radius: 999px; margin-inline-start: 6px; }
.ms-av.grp:not(.img) { background: var(--brand-tint); color: var(--brand-ink); }
.ms-peer-mid { display: flex; flex-direction: column; min-width: 0; }
.ms-peer-mid small { font-size: 11.5px; color: var(--text-light, #94a3b8); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-lock { margin-left: auto; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid var(--border, #e2e8f0); background: none; color: var(--text-muted, #64748b); cursor: pointer; flex-shrink: 0; }
.ms-lock.on { border-color: var(--brand); color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.ms-readonly { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 13px; border-top: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 600; color: var(--text-muted, #64748b); }
.ms-bubble-body { white-space: pre-wrap; word-break: break-word; }
.ms-bubble-time { display: block; font-size: 10px; opacity: .6; margin-top: 3px; text-align: right; }
.ms-compose { display: flex; gap: 10px; padding: 12px 14px; border-top: 1px solid var(--border, #e2e8f0); }
.ms-compose input { flex: 1; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 11px 16px; font-size: 14.5px; outline: none; color: var(--text, #0f172a); }
.ms-compose input:focus { border-color: var(--brand-accent); }
.ms-compose button { width: 44px; height: 44px; border-radius: 50%; border: none; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.ms-compose button:disabled { opacity: .5; cursor: not-allowed; }
.ms-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--text-muted); }

@media (max-width: 760px) {
  .ms-wrap { grid-template-columns: 1fr; height: auto; }
  .ms-wrap.has-thread .ms-list { display: none; }
  .ms-wrap:not(.has-thread) .ms-thread { display: none; }
  /* Reserve room for the header + floating bottom nav (12px offset + ~88px clearance)
     so the compose bar stays in view without page scroll. 100vh fallback first. */
  .ms-thread { height: calc(100vh - 240px); height: calc(100dvh - 240px); min-height: 0; }
  .ms-back {
    display: inline-flex;
    padding: 8px;
    margin: -8px 0 -8px -8px;
    min-width: 44px;
    min-height: 44px;
    align-items: center;
    justify-content: center;
  }
  .ms-thread-head { padding: 8px 12px; }
  .ms-stream { padding: 12px; }
  .ms-bubble { max-width: 85%; }
  .ms-compose { padding-bottom: calc(12px + env(safe-area-inset-bottom)); }
  .ms-compose input { font-size: 16px; }
  /* Pass-2 type scale: legible bubble timestamps, proper page h1, card-title band */
  .ms-bubble-time { font-size: 11px; }
  .ms-h { font-size: 20px; line-height: 1.2; }
  .ms-convo-name { font-size: 15px; }
  /* 360px: long unbroken peer names must ellipsize, not widen the thread head */
  .ms-peer { min-width: 0; flex: 1; }
  .ms-peer b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; display: block; }
}

.ms-inbox-search { display: flex; align-items: center; gap: 8px; margin: 4px 6px 16px; padding: 0 11px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text-muted); }.ms .ms-inbox-search input:not([type=radio]):not([type=checkbox]) { min-width: 0; width: 100%; border: 0; outline: 0; box-shadow: none; background: none; padding: 12px 0; font: inherit; font-size: 14px; color: var(--text); }.ms-inbox-search:focus-within { outline: 2px solid var(--brand-ink); outline-offset: 2px; }
.ms-thread { min-width: 0; }.ms-peer { flex: 1; min-width: 0; }.ms-peer b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }.ms-head-icon { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 42px; height: 42px; border: 1px solid var(--border); border-radius: 9px; color: var(--text-muted); background: var(--surface); cursor: pointer; }.ms-head-icon.danger { color: var(--danger-ink); }.ms-head-icon:disabled { opacity: .4; cursor: not-allowed; }.ms-action-error { color: var(--danger-ink); background: var(--danger-bg); padding: 12px 16px; margin: 0; font-size: 13px; line-height: 1.5; }
.ms-delete-message { min-width: 34px; min-height: 36px; background: none; border: 0; border-radius: 8px; color: var(--text-muted); cursor: pointer; flex-shrink: 0; }.ms-delete-message:hover { color: var(--danger-ink); background: var(--danger-bg); }.ms-delete-message:disabled { opacity: .5; }.ms :is(button,a,input):focus-visible { outline: 2px solid var(--brand-ink); outline-offset: 2px; }.ms-readonly { line-height: 1.5; text-align: center; }.ms-grp-tag { display: none; }.ms-convo { min-height: 72px; }.ms-convo-last { margin-top: 4px; }
@media(max-width:760px) { .ms-thread-head { padding: 12px 10px; gap: 8px; }.ms-head-icon { width: 38px; height: 42px; }.ms-peer-mid small { max-width: 100%; font-size: 11px; }.ms .ms-inbox-search input:not([type=radio]):not([type=checkbox]) { font-size: 16px; } }
</style>
