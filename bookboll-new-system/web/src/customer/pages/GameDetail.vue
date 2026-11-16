<script setup>
import ChatComposer from '../components/ChatComposer.vue'
import ChatMessageBody from '../components/ChatMessageBody.vue'
import { ref, onMounted, onUnmounted, watch, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { loadGame, loadGameRequests, requestToJoin, respondToRequest, cancelJoinRequest, cancelHostedGame, myRequestForGame, loadCountryIso, updateHostedGame, markAttendance, saveRounds } from '../../customer/apiGames.js'
import { meetupState, STATE_META, lockCountdown } from '../../customer/meetup.js'
import { nextRound, standings, PLAY_FORMATS } from '../../customer/matchmaking.js'
import { groupKey, loadGroupThread, sendGroupMessage, giveStreetCred, myPropsForGame, STREET_CRED_BADGES } from '../../customer/community.js'
import { setCommunityEventPublic } from '../../customer/apiGames.js'
import { saveHostDraft } from '../../customer/hostDraft.js'
import { pushToast } from '../../lib/toast.js'
import { profileStrength } from '../../lib/social.js'
import { fmt12, fmtRange12 } from '../../lib/slots.js'
import { getPrefillPhone, saveCustomerPhone } from '../../customer/customerPhones.js'
import { avatarColor } from '../../lib/avatarColor.js'
import PhoneInput from '../components/PhoneInput.vue'
import JoinModal from '../components/JoinModal.vue'
import FormAnswers from '../../components/FormAnswers.vue'
import { useSeo, eventLd } from '../../lib/seo.js'
import { ArrowLeft, ArrowRight, MapPin, CalendarDays, Clock, Users, Trophy, Check, X, Phone, ShieldCheck, Gift, Share2, CalendarPlus, Copy, Navigation, ChevronLeft, ChevronRight, Maximize2, Sparkles, Pencil, RotateCcw, Hourglass, MessageCircle, Send } from 'lucide-vue-next'
import WhatsappIcon from '../../components/WhatsappIcon.vue'

const route = useRoute()
const router = useRouter()
const game = ref(null)
const loading = ref(true)
const requests = ref([])         // host view
const myReq = ref(null)          // joiner view
const submitting = ref(false)
const err = ref('')

const form = ref({ name: '', phone: '', whatsapp: '', message: '' })
const gameIso = ref('')   // the game's country ISO → phone dial-code default
// Community meetup? Load the club's name for the "part of <community>" chip.
const communityInfo = ref(null)
watch(() => [game.value?.community_id, customer.value?.id], async ([cid]) => {
  communityInfo.value = null
  if (!cid) return
  try { const { loadCommunity } = await import('../../customer/community.js'); communityInfo.value = await loadCommunity(cid, customer.value?.id || null) } catch { /* pre-migration */ }
}, { immediate: true })
const showJoin = ref(false)   // join form (+ waiver) shown as a modal
const joinInitial = computed(() => ({ name: form.value.name, whatsapp: form.value.whatsapp, phone: form.value.phone }))
// Mandatory: must have a Bookboll account before requesting to join.
// A weak profile on a host-approval event gets a one-time "complete your profile"
// modal first (skippable) — hosts accept complete profiles more often.
const showNudge = ref(false)
const nudgeSkipped = ref(false)
async function openJoin() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  if (!instantJoin.value && !nudgeSkipped.value && myStrength.value && myStrength.value.level !== 'strong') {
    showNudge.value = true
    return
  }
  showJoin.value = true
}
function skipNudge() {
  nudgeSkipped.value = true
  showNudge.value = false
  showJoin.value = true
}

// ── Members-only community events: the event is PUBLICLY listed, but joining
// requires accepted community membership first. The join button is replaced by
// a join-the-community step until the viewer is in.
const memberGated = computed(() =>
  !!game.value?.community_id && game.value.community_public !== true && !isHost.value &&
  communityInfo.value?.membership?.status !== 'accepted')
const memberPending = computed(() => memberGated.value && communityInfo.value?.membership?.status === 'pending')
const joiningCommunity = ref(false)
async function joinCommunityFirst() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  if (!communityInfo.value) return
  joiningCommunity.value = true
  const { joinCommunity } = await import('../../customer/community.js')
  const res = await joinCommunity(communityInfo.value, customer.value.id)
  joiningCommunity.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  communityInfo.value = { ...communityInfo.value, membership: res.membership }
  if (res.membership?.status === 'accepted') pushToast(`You're in ${communityInfo.value.name} — you can join this event now 🎉`)
  else pushToast('Request sent — the community admins will review it.')
}

// Profile-strength nudge: a weak profile gets accepted less often, so suggest completing it.
const myStrength = computed(() => isCustomer.value ? profileStrength(customer.value) : null)
const isHost = computed(() => isCustomer.value && game.value && customer.value.id === game.value.host_customer_id)
// max_players 0 = no cap (hangouts) → always joinable.
const isUnlimited = computed(() => game.value && !Number(game.value.max_players))
const spotsLeft = computed(() => {
  if (!game.value) return 0
  if (isUnlimited.value) return Infinity
  return Math.max(0, game.value.max_players - (game.value.joinedCount || 0))
})
const isHangout = computed(() => game.value?.game_type === 'event')
const typeWord = computed(() => game.value?.game_type === 'tournament' ? 'tournament' : isHangout.value ? 'hangout' : 'game')
// Date/time are locked only when tied to a venue court booking (reschedule via the
// provider). An own-spot hangout has no booking, so the host may change them here.
const canEditSchedule = computed(() => !game.value?.booking_id)
const isCancelled = computed(() => game.value?.status === 'cancelled')
// Lifecycle (Open → Waitlist → Locked → Live → Ended), derived from the clock —
// ticks every 30s so the chip/lock flips without a reload.
const lifeTick = ref(Date.now())
let lifeTimer = null
onMounted(() => { lifeTimer = setInterval(() => { lifeTick.value = Date.now() }, 30000) })
onUnmounted(() => { if (lifeTimer) clearInterval(lifeTimer) })
const lifeState = computed(() => { void lifeTick.value; return meetupState(game.value, game.value?.joinedCount) })
const lifeMeta = computed(() => STATE_META[lifeState.value])
const lockLabel = computed(() => { void lifeTick.value; return lockCountdown(game.value) })
const isLockedNow = computed(() => ['locked', 'active', 'archived'].includes(lifeState.value))
// Host's join-request deadline (if set + migrated) — superseded by the lifecycle lock,
// kept for the explanatory copy.
const requestsClosed = computed(() => {
  const d = game.value?.request_deadline
  return (!!d && new Date(d).getTime() < Date.now()) || isLockedNow.value
})
const deadlineLabel = computed(() => {
  const d = game.value?.request_deadline
  if (!d) return ''
  try { return new Date(d).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) } catch { return '' }
})
const isOwnSpot = computed(() => !!game.value?.custom_location_name)
// Luma-style online tickets: host connected Stripe → joiners pay online on acceptance
// (fee split per ticket). Otherwise the default: settle with the host on arrival.
const stripeTickets = computed(() => game.value?.charge?.ticketMode === 'stripe' && Number(game.value?.price_per_person) > 0)
// Instant-join events: no host approval — requestToJoin inserts an accepted row directly.
const instantJoin = computed(() => game.value?.approval_mode === 'instant')
const joinHint = computed(() => {
  const paid = Number(game.value?.price_per_person) > 0
  if (instantJoin.value) {
    if (stripeTickets.value) return 'Pay online now — your spot is confirmed immediately.'
    if (paid) return 'Your spot is confirmed instantly — pay the host on arrival.'
    return "Free — you're in the moment you join."
  }
  if (stripeTickets.value) return 'No payment now — you pay online only when the host accepts you.'
  return paid ? 'No payment now — settle with the host on arrival.' : 'Free to join — no payment needed.'
})
const myAccepted = computed(() => myReq.value?.status === 'accepted')
// Exact address is shown to the host + accepted joiners; or to everyone when not hidden.
const canSeeExact = computed(() => !game.value?.hide_location || isHost.value || myAccepted.value)
const dateLabel = computed(() => game.value?.game_date
  ? new Date(game.value.game_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })
  : 'Flexible')

// Host-uploaded photos (first = cover). Falls back to none → coloured header.
const images = computed(() => {
  const g = game.value
  if (!g) return []
  if (Array.isArray(g.images) && g.images.length) return g.images.filter(Boolean)
  if (Array.isArray(g.photos) && g.photos.length) return g.photos.filter(Boolean)
  return g.photo_url ? [g.photo_url] : []
})
const cover = computed(() => images.value[0] || '')
const headClass = computed(() => game.value?.game_type === 'tournament' ? 'is-tourn' : game.value?.game_type === 'event' ? 'is-event' : 'is-game')

useSeo(() => {
  const g = game.value
  if (!g) return { path: route.path, noindex: !loading.value }
  const name = g.title || g.sport || 'Game'
  const where = g.custom_location_name || g.locationName || ''
  const price = Number(g.price_per_person) || 0
  return {
    title: `${name}${g.game_date ? ' · ' + dateLabel.value : ''}`,
    description: `${g.description || `Join "${name}"`}${where ? ' at ' + where : ''}${g.game_date ? ' on ' + dateLabel.value : ''}. ${price ? g.currency + ' ' + price.toLocaleString() + ' per person' : 'Free to join'} on Bookboll.`,
    image: cover.value,
    path: route.path,
    type: 'article',
    jsonLd: eventLd({
      name, description: g.description, image: cover.value,
      startDate: g.game_date ? `${g.game_date}T${(g.start_time || '00:00')}` : undefined,
      endDate: g.game_date && g.end_time ? `${g.game_date}T${g.end_time}` : undefined,
      locationName: where, city: g.city_name, price: price || undefined, currency: g.currency,
      url: `https://bookboll.com${route.path}`,
    }),
  }
})

// Fullscreen image viewer (lightbox)
const lightbox = ref(false)
const lbIdx = ref(0)
function openLightbox(i) { lbIdx.value = i; lightbox.value = true }
function closeLightbox() { lightbox.value = false }
function lbPrev() { lbIdx.value = (lbIdx.value - 1 + images.value.length) % images.value.length }
function lbNext() { lbIdx.value = (lbIdx.value + 1) % images.value.length }
function onLbKey(e) {
  if (!lightbox.value) return
  if (e.key === 'Escape') closeLightbox()
  else if (e.key === 'ArrowLeft') lbPrev()
  else if (e.key === 'ArrowRight') lbNext()
}
onMounted(() => window.addEventListener('keydown', onLbKey))
onUnmounted(() => window.removeEventListener('keydown', onLbKey))

const pendingCount = computed(() => requests.value.filter(r => r.status === 'pending').length)
const acceptedCount = computed(() => requests.value.filter(r => r.status === 'accepted').length)

const shared = ref(false)
async function share() {
  try { await navigator.clipboard.writeText(window.location.href); shared.value = true; setTimeout(() => { shared.value = false }, 2000) } catch (e) { /* clipboard blocked */ }
}
function scrollToReqs() { document.querySelector('.gd-reqs')?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }

function waLink(num) { return 'https://wa.me/' + String(num || '').replace(/[^\d]/g, '') }

// ── Joiner conveniences: withdraw, share-to-WhatsApp, add-to-calendar, fill bar ──
const cancelling = ref(false)
async function cancelMyRequest() {
  if (!myReq.value || cancelling.value) return
  cancelling.value = true
  const { error } = await cancelJoinRequest(myReq.value.id)
  cancelling.value = false
  if (error) { err.value = error; return }
  myReq.value = null
  game.value = await loadGame(game.value.id)   // free the spot / refresh counts
}

// Host cancels the whole event (and its linked court booking, if any).
const showCancel = ref(false)
const cancellingEvent = ref(false)
const cancelErr = ref('')
async function confirmCancelEvent() {
  if (cancellingEvent.value) return
  cancellingEvent.value = true; cancelErr.value = ''
  const res = await cancelHostedGame(game.value.id, game.value.booking_id)
  cancellingEvent.value = false
  if (res.error) { cancelErr.value = res.error; return }
  showCancel.value = false
  game.value = await loadGame(game.value.id)   // reload → status 'cancelled' (shows the notice)
}

// How full a capped game is (0–100), for the spots bar.
const fillPct = computed(() => {
  if (!game.value || isUnlimited.value || !game.value.max_players) return 0
  return Math.min(100, Math.round((game.value.joinedCount || 0) / game.value.max_players * 100))
})

// Add-to-calendar (Google) + share-to-WhatsApp links built from the event.
function calStamp(dateStr, timeStr) {
  if (!dateStr) return ''
  const d = dateStr.replace(/-/g, '')
  return timeStr ? d + 'T' + timeStr.slice(0, 8).replace(/:/g, '') : d
}
const gcalUrl = computed(() => {
  const g = game.value
  if (!g || !g.game_date) return null
  const start = calStamp(g.game_date, g.start_time)
  const end = calStamp(g.game_date, g.end_time) || start
  const qs = new URLSearchParams({
    action: 'TEMPLATE',
    text: g.title || g.sport || 'Bookboll event',
    dates: `${start}/${end}`,
    details: (g.description ? g.description + '\n\n' : '') + 'Booked via Bookboll',
    location: g.custom_location_name || g.locationName || '',
  })
  return 'https://calendar.google.com/calendar/render?' + qs.toString()
})
const waShareUrl = computed(() => {
  const g = game.value
  const what = g?.title || g?.sport || 'this event'
  const when = g?.start_time ? ` at ${fmt12(g.start_time)}` : ''
  return 'https://wa.me/?text=' + encodeURIComponent(`Join me at "${what}" on ${dateLabel.value}${when} — ${window.location.href}`)
})
// Directions to a publicly-visible venue/address (only when we can show the spot).
const directionsUrl = computed(() => {
  const g = game.value
  if (!g) return null
  const q = (canSeeExact.value && g.custom_location_address) || g.custom_location_name || g.locationName
  return q ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q) : null
})
// Nudge the host on WhatsApp after requesting — opens their chat with a ready-to-send note.
const notifyHostUrl = computed(() => {
  const g = game.value
  const num = String(g?.host_whatsapp || '').replace(/[^\d]/g, '')
  if (!num) return null
  const what = g.title || g.sport || 'your event'
  const msg = `Hi! I just sent a request to join your event "${what}". Please open this link to view my request and accept or decline it: ${window.location.href}\n\nIf you have any questions or specific requirements about me, just let me know — happy to share more!`
  return `https://wa.me/${num}?text=${encodeURIComponent(msg)}`
})

async function load() {
  loading.value = true; err.value = ''
  game.value = await loadGame(route.params.id)
  if (game.value && game.value.game_type === 'tournament') { router.replace(`/tournaments/${game.value.id}`); return }
  if (game.value) {
    gameIso.value = await loadCountryIso(game.value.country_id)
    if (isCustomer.value) {
      form.value.name = customer.value.full_name || ''
      const saved = getPrefillPhone(game.value.country_id)   // this country's number, else the account's
      form.value.whatsapp = saved
      form.value.phone = saved
      myReq.value = await myRequestForGame(game.value.id, customer.value.id)
    } else {
      myReq.value = null   // guest view: clear any stale request from a prior signed-in session
    }
    // Hosts always see requests; live-rounds or ended events load them for everyone
    // (the roster powers the rounds runner, standings and post-match props).
    if (isCustomer.value && (customer.value.id === game.value.host_customer_id || game.value.play_format || meetupState(game.value) === 'archived')) {
      requests.value = await loadGameRequests(game.value.id)
    }
    roundsDoc.value = game.value.rounds && Array.isArray(game.value.rounds.rounds) ? game.value.rounds : null
    if (isCustomer.value && meetupState(game.value) === 'archived') {
      myProps.value = await myPropsForGame(game.value.id, customer.value.id)
    }
  }
  loading.value = false
  if (route.query.chat === '1' && canMeetChat.value && !meetChatOpen.value) await openMeetChat()
}
onMounted(load)
watch(() => route.params.id, load)
watch(() => customer.value?.id, load)

// The JoinModal collects the form + waiver and emits the data here.
async function submitJoin(data) {
  err.value = ''
  submitting.value = true
  const res = await requestToJoin(game.value.id, {
    customerId: isCustomer.value ? customer.value.id : null,
    name: data.name, phone: data.phone,
    whatsapp: data.whatsapp || data.phone, message: data.message,
    form_answers: data.form_answers || {},
  })
  submitting.value = false
  if (res.error) { err.value = res.error; return }   // modal shows err, stays open
  showJoin.value = false
  saveCustomerPhone(game.value.country_id, data.whatsapp || data.phone)
  myReq.value = res.request
  if (res.earned > 0) pushToast(`🎉 +${res.earned.toLocaleString()} points — check your Rewards!`)
  // Instant join lands as accepted — refresh so the going count/avatars include them.
  if (res.request?.status === 'accepted') game.value = await loadGame(game.value.id)
}

async function decide(req, status) {
  const { error } = await respondToRequest(req.id, status)
  if (error) { err.value = error; return }
  req.status = status
  game.value = await loadGame(game.value.id)   // refresh joined count
}

const statusMeta = {
  pending:    { label: 'Pending — the host will contact you', cls: 'st-pending' },
  accepted:   { label: "You're in! The host will reach out", cls: 'st-accepted' },
  rejected:   { label: 'Not accepted this time', cls: 'st-rejected' },
  waitlisted: { label: "You're on the waitlist — you're next in line when a spot frees up", cls: 'st-pending' },
}

// ── Waitlist: a FULL capped event still takes joiners — queued oldest-first —
//    unless the host turned the waitlist off (allow_waitlist=false). Instant
//    events promote straight to accepted; review events back to the host's queue.
const canWaitlist = computed(() =>
  game.value?.allow_waitlist !== false && !isUnlimited.value && spotsLeft.value <= 0 &&
  !isCancelled.value && !isHost.value && !myReq.value && !requestsClosed.value)
const waitlistedCount = computed(() => requests.value.filter(r => r.status === 'waitlisted').length)

// ── LIVE ROUNDS (round robin / Swiss / king of the court) ──────────────────────
const roundsDoc = ref(null)
const genBusy = ref(false)
const roundsPlayers = computed(() => requests.value
  .filter(r => r.status === 'accepted' && r.joiner_customer_id)
  .map(r => ({ id: r.joiner_customer_id, name: r.joiner_name || 'Player' })))
const playerName = computed(() => Object.fromEntries(roundsPlayers.value.map(p => [p.id, p.name])))
const showRounds = computed(() => !!game.value?.play_format && (roundsDoc.value?.rounds?.length || isHost.value) && ['locked', 'active', 'archived'].includes(lifeState.value))
const currentRound = computed(() => roundsDoc.value?.rounds?.[roundsDoc.value.rounds.length - 1] || null)
const standingsRows = computed(() => roundsDoc.value?.rounds?.length ? standings(roundsDoc.value, roundsPlayers.value) : [])
const formatMeta = computed(() => PLAY_FORMATS[game.value?.play_format] || null)
const teamSize = computed(() => Number(roundsDoc.value?.teamSize) || 2)
// A new round only makes sense once every court in the current one has a winner.
const roundComplete = computed(() => !currentRound.value || currentRound.value.courts.every(c => c.winner))

async function generateRound() {
  if (genBusy.value) return
  genBusy.value = true
  const res = nextRound(roundsDoc.value, roundsPlayers.value, {
    format: game.value.play_format, courts: Number(game.value.courts_count) || 1, teamSize: teamSize.value,
  })
  if (res.error) { err.value = res.error; genBusy.value = false; return }
  const save = await saveRounds(game.value.id, res.doc)
  genBusy.value = false
  if (save.error) { err.value = save.error; return }
  err.value = ''
  roundsDoc.value = res.doc
}

// Tap a side (or Draw) to record the court's result — feeds the live standings.
async function setCourtWinner(round, court, side) {
  if (!isHost.value || court.winner === side) return
  court.winner = side
  await saveRounds(game.value.id, roundsDoc.value)
}

// ── MEETUP CHAT (isolated thread; read-only once the event ends) ────────────────
const meetChatOpen = ref(false)
const meetMsgs = ref([])
const meetText = ref('')
const meetSending = ref(false)
const meetBox = ref(null)
let meetTimer = null
const canMeetChat = computed(() => !!game.value && (isHost.value || myAccepted.value))
async function refreshMeetChat(scroll = false) {
  meetMsgs.value = await loadGroupThread(groupKey('meet', game.value.id))
  if (scroll) await nextTick(), scrollMeetChat()
}
function scrollMeetChat() { try { meetBox.value.scrollTop = meetBox.value.scrollHeight } catch { /* ignore */ } }
async function openMeetChat() {
  meetChatOpen.value = true
  await refreshMeetChat(true)
  meetTimer = setInterval(() => refreshMeetChat(), 12000)
}
function closeMeetChat() { meetChatOpen.value = false; if (meetTimer) { clearInterval(meetTimer); meetTimer = null } }
onUnmounted(() => { if (meetTimer) clearInterval(meetTimer) })
async function sendMeetChat(mentions = []) {
  const txt = meetText.value.trim()
  if (!txt || meetSending.value || lifeState.value === 'archived') return
  meetSending.value = true
  const res = await sendGroupMessage(groupKey('meet', game.value.id), customer.value.id, txt, mentions)
  meetSending.value = false
  if (res.error) { err.value = res.error; return }
  meetText.value = ''
  await refreshMeetChat(true)
}

// ── STREET CRED ("props"): post-match peer badges — social only ─────────────────
const propsOpen = ref(false)
const myProps = ref({})
const propsBusyKey = ref('')
const canGiveProps = computed(() => lifeState.value === 'archived' && isCustomer.value && (isHost.value || myAccepted.value))
// Fellow attendees (+ the host) I can give props to — never myself.
const propsPeople = computed(() => {
  const list = roundsPlayers.value.length ? [...roundsPlayers.value]
    : requests.value.filter(r => r.status === 'accepted' && r.joiner_customer_id).map(r => ({ id: r.joiner_customer_id, name: r.joiner_name || 'Player' }))
  if (game.value?.host_customer_id && !list.some(p => p.id === game.value.host_customer_id)) {
    list.push({ id: game.value.host_customer_id, name: (game.value.host_name || 'Host') + ' (host)' })
  }
  return list.filter(p => p.id !== customer.value?.id)
})
function hasProp(receiverId, badge) { return !!myProps.value[receiverId]?.has?.(badge) }
async function sendProp(receiverId, badge) {
  const key = receiverId + badge
  if (propsBusyKey.value || hasProp(receiverId, badge)) return
  propsBusyKey.value = key
  const res = await giveStreetCred(game.value.id, customer.value.id, receiverId, badge)
  propsBusyKey.value = ''
  if (res.error) { err.value = res.error; return }
  ;(myProps.value[receiverId] ||= new Set()).add(badge)
  myProps.value = { ...myProps.value }
}

// ── Host: edit the published event (safe fields — venue/time stay booking-bound) ──
const showEdit = ref(false)
const editSaving = ref(false)
const editErr = ref('')
const editF = ref({})
function openEditEvent() {
  const g = game.value
  editF.value = {
    title: g.title || '', description: g.description || '',
    skill_level: g.skill_level || 'all',
    price_per_person: Number(g.price_per_person) || 0,
    max_players: Number(g.max_players) || 0,
    host_name: g.host_name || '', host_whatsapp: g.host_whatsapp || '', host_phone: g.host_phone || '',
    game_date: g.game_date || '', start_time: (g.start_time || '').slice(0, 5), end_time: (g.end_time || '').slice(0, 5),
  }
  editErr.value = ''
  showEdit.value = true
}
async function saveEditEvent() {
  if (editSaving.value) return
  if (!String(editF.value.title).trim()) { editErr.value = `Give your ${typeWord.value} a title.`; return }
  const patch = {
    title: String(editF.value.title).trim(),
    description: editF.value.description,
    skill_level: editF.value.skill_level,
    price_per_person: Number(editF.value.price_per_person) || 0,
    max_players: Number(editF.value.max_players) || 0,
    host_name: editF.value.host_name, host_whatsapp: editF.value.host_whatsapp, host_phone: editF.value.host_phone,
  }
  // Own-spot hangouts (no venue booking) may reschedule right here.
  if (canEditSchedule.value) {
    if (!editF.value.game_date) { editErr.value = 'Pick a date.'; return }
    patch.game_date = editF.value.game_date
    patch.start_time = editF.value.start_time || null
    patch.end_time = editF.value.end_time || null
  }
  editSaving.value = true; editErr.value = ''
  const res = await updateHostedGame(game.value.id, patch)
  editSaving.value = false
  if (res.error) { editErr.value = res.error; return }
  showEdit.value = false
  await load()   // fresh counts + fields (a raised cap may have promoted waitlisted joiners)
}

// ── Community event visibility: members-only vs also-public (host/admin toggle) ──
const togglingVis = ref(false)
async function toggleEventPublic() {
  if (togglingVis.value) return
  togglingVis.value = true
  const next = game.value.community_public !== true
  const res = await setCommunityEventPublic(game.value.id, next)
  togglingVis.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  game.value = { ...game.value, community_public: next }
  pushToast(next ? 'Now listed in public Events too.' : 'Now members-only — hidden from public Events.')
}

// ── Host again: duplicate this event into the wizard (new date/court picked there) ──
function hostAgain() {
  const g = game.value
  saveHostDraft({
    type: g.game_type === 'event' ? 'event' : 'game',
    step: 1,   // land on Basics so everything is reviewable before re-picking the time
    basics: {
      title: g.title || '', sport: g.sport || '', skill_level: g.skill_level || 'all',
      max_players: Number(g.max_players) || 10, price_per_person: Number(g.price_per_person) || 0,
      description: g.description || '', approval_mode: g.approval_mode || 'review',
      limit_seats: Number(g.max_players) > 0,
      images: Array.isArray(g.images) ? [...g.images] : [],
      host_name: g.host_name || '', host_whatsapp: g.host_whatsapp || '', host_phone: g.host_phone || '',
    },
  })
  router.push('/host-game')
}
</script>

<template>
  <div>
    <router-link :to="communityInfo ? `/communities/${communityInfo.id}` : '/games'" class="cback"><ArrowLeft :size="16" /> {{ communityInfo ? communityInfo.name : 'All events' }}</router-link>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!game" class="cmsg">Game not found.</div>
    <template v-else>
      <!-- Luma-inspired top: poster shown IN FULL (left) + title & key facts (right).
           The old wide hero cropped square/portrait event posters into a thin slice —
           here the image keeps its natural aspect ratio, so it's always fully visible. -->
      <div class="gd-top">
        <div class="gd-top-media">
          <button v-if="cover" type="button" class="gd-poster" @click="openLightbox(0)">
            <img :src="cover" alt="" />
            <span class="gd-poster-zoom"><Maximize2 :size="16" /></span>
          </button>
          <div v-else class="gd-cover-fb" :class="headClass">
            <component :is="game.game_type === 'event' ? CalendarDays : Trophy" :size="42" />
            <span>{{ game.sport || (game.game_type === 'tournament' ? 'Tournament' : game.game_type === 'event' ? 'Hangout' : 'Game') }}</span>
          </div>
          <div v-if="images.length > 1" class="gd-gallery">
            <button v-for="(img, j) in images.slice(1)" :key="img" type="button" class="gd-gallery-img" :style="{ backgroundImage: `url(${img})` }" @click="openLightbox(j + 1)"></button>
          </div>
        </div>
        <div class="gd-headinfo">
          <div class="gd-badge" :class="headClass"><component :is="game.game_type === 'event' ? CalendarDays : Trophy" :size="14" /> {{ game.game_type === 'tournament' ? 'Tournament' : game.game_type === 'event' ? 'Hangout' : 'Game' }}</div>
          <router-link v-if="communityInfo" :to="`/communities/${communityInfo.id}`" class="gd-community"><Users :size="13" /> {{ communityInfo.name }}</router-link>
          <h1>{{ game.title || game.sport }}</h1>
          <div v-if="game.sport" class="gd-sport">{{ game.sport }}<span v-if="game.skill_level && game.skill_level !== 'all'"> · {{ game.skill_level }} level</span></div>
          <!-- Lifecycle strip: where the event is in Open → Waitlist → Locked → Live → Ended -->
          <div v-if="lifeState !== 'cancelled'" class="gd-life" :class="'st-' + lifeState">
            <span class="gd-life-chip">{{ lifeMeta.label }}</span>
            <span v-if="lifeState === 'open' && lockLabel" class="gd-life-t"><Clock :size="12" /> Roster locks in {{ lockLabel }} — free to drop out until then{{ stripeTickets ? ' (auto-refund)' : '' }}</span>
            <span v-else-if="lifeState === 'waitlisted'" class="gd-life-t">Full — new joins queue and are auto-promoted if a spot frees up</span>
            <span v-else-if="lifeState === 'locked'" class="gd-life-t">Roster is final — no self-serve joins or drops</span>
            <span v-else-if="lifeState === 'active'" class="gd-life-t">Being played right now</span>
            <span v-else-if="lifeState === 'archived'" class="gd-life-t">This event has ended</span>
          </div>
          <div class="gd-meta">
            <div class="fact"><CalendarDays :size="18" /><div><b>{{ dateLabel }}</b><span v-if="game.start_time">{{ game.end_time ? fmtRange12(game.start_time, game.end_time) : fmt12(game.start_time) }}</span></div></div>
            <div class="fact"><MapPin :size="18" /><div>
              <b>{{ game.custom_location_name || game.locationName || 'Venue' }}</b>
              <span v-if="isOwnSpot">
                <template v-if="canSeeExact && game.custom_location_address">{{ game.custom_location_address }}</template>
                <template v-else-if="game.hide_location">Exact spot shared once you're accepted</template>
                <template v-else>Host's own spot</template>
              </span>
              <span v-else>Meet at the venue</span>
            </div></div>
            <div class="fact"><Users :size="18" /><div>
              <b v-if="isUnlimited">{{ game.joinedCount }} joined</b>
              <b v-else>{{ spotsLeft }} of {{ game.max_players }} spots left</b>
              <span>{{ isUnlimited ? 'Open to all' : game.joinedCount + ' joined' }}</span>
            </div></div>
          </div>
        </div>
      </div>

      <div v-if="isCancelled" class="gd-cancelled"><X :size="16" /> This event has been cancelled by the host.</div>

      <div class="gd-grid">
        <div class="gd-main">
          <!-- Who's going — avatar stack + first names (the social proof row) -->
          <div v-if="game.joinedCount" class="gd-going">
            <div class="gd-going-stack">
              <span v-for="(p, i) in (game.goingAvatars || []).slice(0, 5)" :key="i" class="gd-go-av"
                :style="p.avatar ? { backgroundImage: `url(${p.avatar})` } : { background: avatarColor(p.name), color: '#fff' }" :title="p.name">{{ p.avatar ? '' : (p.name || '?')[0].toUpperCase() }}</span>
            </div>
            <div class="gd-going-txt">
              <b>{{ game.joinedCount }} going</b>
              <span v-if="game.goingNames && game.goingNames.length"> · {{ game.goingNames.join(', ') }}<template v-if="game.joinedCount > game.goingNames.length"> and {{ game.joinedCount - game.goingNames.length }} other{{ game.joinedCount - game.goingNames.length === 1 ? '' : 's' }}</template></span>
            </div>
          </div>

          <div v-if="Number(game.prize_pot) > 0" class="gd-prize">
            <Gift :size="22" />
            <div><b>{{ game.currency }} {{ Number(game.prize_pot).toLocaleString() }} prize pot</b>
            <span>{{ game.prize_note || 'Paid by the host directly to the winner(s).' }}</span></div>
          </div>

          <template v-if="game.description">
            <h2 class="gd-sec-h">About this event</h2>
            <p class="gd-desc">{{ game.description }}</p>
          </template>

          <div class="gd-pay">
            <ShieldCheck :size="18" />
            <div v-if="stripeTickets"><b>Pay {{ game.currency }} {{ Number(game.price_per_person).toLocaleString() }} online when the host accepts you.</b>
            Processed securely via the host's Stripe account — nothing to bring in cash, and if your request is declined nothing is charged.</div>
            <div v-else-if="Number(game.price_per_person) > 0"><b>Pay the host {{ game.currency }} {{ Number(game.price_per_person).toLocaleString() }} per person, on arrival.</b>
            Bookboll doesn't handle this payment — you settle it directly with the host in cash or however you both agree.</div>
            <div v-else><b>Free to join.</b> Just request below — no payment needed.</div>
          </div>

          <!-- Location — venue name + address + directions in one obvious block -->
          <div v-if="!isOwnSpot && (game.locationName || game.locationAddress)" class="gd-loc-sec">
            <h2 class="gd-sec-h">Location</h2>
            <div class="gd-loc-card">
              <MapPin :size="18" />
              <div class="gd-loc-info">
                <b>{{ game.locationName }}</b>
                <span v-if="game.locationAddress">{{ game.locationAddress }}</span>
              </div>
              <a v-if="directionsUrl" :href="directionsUrl" target="_blank" rel="noopener" class="gd-loc-go"><Navigation :size="14" /> Directions</a>
            </div>
          </div>

          <!-- HOST VIEW: requests live on their own page (keeps this page about the event) -->
          <div v-if="isHost" class="gd-reqs">
            <router-link :to="`/games/${game.id}/requests`" class="gd-reqlink">
              <span class="gd-reqlink-ic"><Users :size="20" /></span>
              <span class="gd-reqlink-tx">
                <b>Manage join requests</b>
                <span>{{ pendingCount }} pending · {{ acceptedCount }} confirmed<template v-if="waitlistedCount"> · {{ waitlistedCount }} waitlisted</template></span>
              </span>
              <span class="gd-reqlink-go"><span v-if="pendingCount" class="gd-reqlink-badge">{{ pendingCount }}</span> Open <ArrowRight :size="16" /></span>
            </router-link>
          </div>

          <!-- LIVE ROUNDS: the game-day engine (host taps winners; everyone sees standings) -->
          <div v-if="showRounds" class="gd-rounds">
            <h2 class="gd-sec-h">{{ formatMeta?.label || 'Live rounds' }} <span class="gd-rounds-sub">{{ game.courts_count || 1 }} court{{ (game.courts_count || 1) > 1 ? 's' : '' }} · {{ teamSize }}v{{ teamSize }}</span></h2>

            <div v-if="standingsRows.length" class="gd-standings">
              <div v-for="(s, i) in standingsRows" :key="s.id" class="gd-stand" :class="{ top: i === 0 && s.wins > 0 }">
                <span class="gd-stand-n">{{ i + 1 }}</span>
                <span class="gd-stand-name">{{ s.name }}</span>
                <span class="gd-stand-wl">{{ s.wins }}W<template v-if="s.draws"> – {{ s.draws }}D</template> – {{ s.losses }}L</span>
                <span v-if="i === 0 && s.wins > 0" class="gd-stand-r"><Trophy :size="12" /></span>
              </div>
            </div>

            <div v-for="rd in (roundsDoc?.rounds || [])" :key="rd.n" class="gd-round">
              <div class="gd-round-h">Round {{ rd.n }}<span v-if="rd.sitOut?.length" class="gd-round-sit">sitting out: {{ rd.sitOut.map(id => playerName[id] || 'Player').join(', ') }}</span></div>
              <div class="gd-courts">
                <div v-for="c in rd.courts" :key="c.court" class="gd-court" :class="{ done: c.winner }">
                  <div class="gd-court-h">Court {{ c.court }}</div>
                  <button type="button" class="gd-court-side" :class="{ win: c.winner === 'home', lose: c.winner === 'away', tap: isHost }"
                    :disabled="!isHost" @click="setCourtWinner(rd, c, 'home')">
                    <span>{{ c.home.map(id => playerName[id] || 'Player').join(' + ') }}</span>
                    <Trophy v-if="c.winner === 'home'" :size="13" />
                  </button>
                  <div class="gd-court-vs">vs</div>
                  <button type="button" class="gd-court-side" :class="{ win: c.winner === 'away', lose: c.winner === 'home', tap: isHost }"
                    :disabled="!isHost" @click="setCourtWinner(rd, c, 'away')">
                    <span>{{ c.away.map(id => playerName[id] || 'Player').join(' + ') }}</span>
                    <Trophy v-if="c.winner === 'away'" :size="13" />
                  </button>
                  <button v-if="isHost || c.winner === 'draw'" type="button" class="gd-court-draw" :class="{ on: c.winner === 'draw' }"
                    :disabled="!isHost" @click="setCourtWinner(rd, c, 'draw')">½ Draw{{ c.winner === 'draw' ? 'n' : '' }}</button>
                </div>
              </div>
            </div>

            <div v-if="isHost && lifeState !== 'archived'" class="gd-rounds-bar">
              <button type="button" class="gd-genround" :disabled="genBusy || !roundComplete || roundsPlayers.length < teamSize * 2" @click="generateRound">
                <Sparkles :size="15" /> {{ genBusy ? 'Generating…' : `Generate round ${(roundsDoc?.rounds?.length || 0) + 1}` }}
              </button>
              <span v-if="!roundComplete" class="gd-rounds-hint">Tap each court's winner to finish round {{ currentRound?.n }} first.</span>
              <span v-else-if="roundsPlayers.length < teamSize * 2" class="gd-rounds-hint">Needs at least {{ teamSize * 2 }} accepted players.</span>
              <span v-else-if="isHost && !(roundsDoc?.rounds?.length)" class="gd-rounds-hint">{{ formatMeta?.hint }}</span>
            </div>
          </div>

          <!-- Post-match PROPS: give fellow players a badge (social only) -->
          <div v-if="canGiveProps && propsPeople.length" class="gd-props-cta">
            <button type="button" class="gd-props-btn" @click="propsOpen = true">🤝 Give props to the players</button>
          </div>
        </div>

        <!-- SIDEBAR -->
        <aside class="gd-side">
          <div class="gd-price"><template v-if="Number(game.price_per_person) > 0">{{ game.currency }} {{ Number(game.price_per_person).toLocaleString() }}<span>/person</span></template><template v-else>Free</template></div>
          <div v-if="stripeTickets" class="gd-price-sub">Paid online via Stripe when you're accepted.</div>
          <div v-else-if="Number(game.price_per_person) > 0" class="gd-price-sub">Settled with the host on arrival — cash or as you agree.</div>
          <div class="gd-host">
            <template v-if="game.community_id && communityInfo">
              <span class="gd-host-av gd-host-av-comm" :style="communityInfo.avatar_image ? { backgroundImage: `url(${communityInfo.avatar_image})` } : { background: avatarColor(communityInfo.name) }"><Users v-if="!communityInfo.avatar_image" :size="14" /></span>
              <span>Hosted by <router-link :to="`/communities/${communityInfo.id}`" class="gd-host-link">{{ communityInfo.name }}</router-link><template v-if="!/communit(y|ies)\s*$/i.test(communityInfo.name || '')"> community</template></span>
            </template>
            <template v-else>
              <span class="gd-host-av" :style="game.hostAvatar ? { backgroundImage: `url(${game.hostAvatar})` } : { background: avatarColor(game.host_name) }">{{ game.hostAvatar ? '' : ((game.host_name || '?')[0] || '?').toUpperCase() }}</span>
              <span>Hosted by <router-link v-if="game.host_customer_id" :to="`/u/${game.host_customer_id}`" class="gd-host-link">{{ (game.host_name||'A player').split(' ')[0] }}</router-link><b v-else>{{ (game.host_name||'A player').split(' ')[0] }}</b></span>
            </template>
          </div>
          <div v-if="Number(game.prize_pot) > 0" class="gd-side-prize"><Gift :size="15" /> {{ game.currency }} {{ Number(game.prize_pot).toLocaleString() }} prize pot</div>

          <!-- cancelled: no actions, just a notice -->
          <div v-if="isCancelled" class="gd-cancelled-side"><X :size="16" /> This event was cancelled.</div>

          <!-- host's own game: management snapshot -->
          <div v-else-if="isHost" class="gd-manage">
            <!-- prominent lifecycle banner (Ended / Live now / Locked) right where the host looks -->
            <div v-if="['locked', 'active', 'archived'].includes(lifeState)" class="gd-mstate" :class="'st-' + lifeState">
              <span class="gd-mstate-dot"></span>
              <b>{{ lifeMeta.label }}</b>
              <small>{{ lifeState === 'archived' ? `· ${isHangout ? 'hangout' : game.game_type === 'tournament' ? 'tournament' : 'game'} is over — mark who showed up in requests` : lifeState === 'active' ? '· being played now' : '· roster is final' }}</small>
            </div>
            <div class="gd-manage-stats">
              <div class="gms"><b>{{ game.joinedCount || 0 }}</b><span>{{ isUnlimited ? 'joined' : 'of ' + game.max_players }}</span></div>
              <div class="gms"><b :class="{ 'gms-hot': pendingCount }">{{ pendingCount }}</b><span>pending</span></div>
              <div class="gms"><b>{{ acceptedCount }}</b><span>accepted</span></div>
              <div v-if="waitlistedCount" class="gms"><b>{{ waitlistedCount }}</b><span>waitlist</span></div>
            </div>
            <!-- Community event: members-only vs also-public toggle -->
            <div v-if="game.community_id" class="gd-vis">
              <div class="gd-vis-info">
                <component :is="game.community_public ? Sparkles : ShieldCheck" :size="16" />
                <div>
                  <b>{{ game.community_public ? 'Open to all' : 'Members only' }}</b>
                  <small>{{ game.community_public ? 'Anyone on Bookboll can join.' : 'Listed publicly — but only community members can join.' }}</small>
                </div>
              </div>
              <button type="button" class="gd-vis-toggle" :class="{ on: game.community_public }" :disabled="togglingVis" :aria-pressed="game.community_public === true" @click="toggleEventPublic"><span class="gd-vis-knob"></span></button>
            </div>
            <button v-if="pendingCount" class="gd-review" @click="scrollToReqs"><Users :size="15" /> Review {{ pendingCount }} request{{ pendingCount > 1 ? 's' : '' }}</button>
            <button class="gd-edit-event" @click="openEditEvent"><Pencil :size="15" /> Edit details</button>
            <button class="gd-again" @click="hostAgain"><RotateCcw :size="15" /> Host this again</button>
            <button class="gd-cancel-event" @click="showCancel = true"><X :size="15" /> Cancel event</button>
          </div>

          <!-- joiner who already requested: status + manage their request -->
          <div v-else-if="myReq" class="gd-mine">
            <div class="gd-myreq" :class="statusMeta[myReq.status]?.cls">
              <div class="gd-myreq-t"><Check v-if="myReq.status === 'accepted'" :size="16" /><Clock v-else-if="myReq.status === 'pending'" :size="16" /><Hourglass v-else-if="myReq.status === 'waitlisted'" :size="16" /><X v-else :size="16" /> {{ statusMeta[myReq.status]?.label }}</div>
            </div>
            <template v-if="myReq.status === 'accepted'">
              <p v-if="stripeTickets" class="gd-paid-note"><ShieldCheck :size="13" /> Ticket paid online via the host's Stripe (simulated) — nothing to bring in cash.</p>
              <a v-if="game.host_whatsapp" :href="waLink(game.host_whatsapp)" target="_blank" class="gd-wa-host"><WhatsappIcon :size="16" /> Message the host</a>
              <a v-if="directionsUrl" :href="directionsUrl" target="_blank" class="gd-side-link"><Navigation :size="15" /> Get directions</a>
              <!-- Self-serve drop only until the lock — then the roster is final. -->
              <button v-if="!isLockedNow" class="gd-cancel" :disabled="cancelling" @click="cancelMyRequest">
                <X :size="15" /> {{ cancelling ? 'Leaving…' : (stripeTickets ? "Can't make it? Leave (auto-refund)" : "Can't make it? Leave") }}
              </button>
              <div v-else-if="lifeState === 'locked'" class="gd-lock-note"><Clock :size="13" /> Roster locked — to drop out now, message the host{{ stripeTickets ? ' (ticket is non-refundable after lock)' : '' }}.</div>
            </template>
            <template v-if="myReq.status === 'pending'">
              <a v-if="notifyHostUrl" :href="notifyHostUrl" target="_blank" class="gd-wa-host"><WhatsappIcon :size="16" /> Message the host</a>
              <button class="gd-cancel" :disabled="cancelling" @click="cancelMyRequest"><X :size="15" /> {{ cancelling ? 'Cancelling…' : 'Cancel my request' }}</button>
            </template>
            <template v-if="myReq.status === 'waitlisted'">
              <button class="gd-cancel" :disabled="cancelling" @click="cancelMyRequest"><X :size="15" /> {{ cancelling ? 'Leaving…' : 'Leave the waitlist' }}</button>
            </template>
            <router-link v-else-if="myReq.status === 'rejected'" to="/games" class="gd-side-link">Find another event</router-link>
          </div>

          <!-- requests closed by the host's deadline -->
          <template v-else-if="requestsClosed">
            <div class="gd-closed"><Clock :size="16" /> Requests closed{{ deadlineLabel ? ' on ' + deadlineLabel : '' }}. The host is no longer taking new requests.</div>
          </template>

          <!-- not requested yet: spots filling + how it works + join -->
          <template v-else-if="spotsLeft > 0">
            <div v-if="!isUnlimited" class="gd-spots">
              <div class="gd-spots-top"><span>{{ spotsLeft }} {{ spotsLeft === 1 ? 'spot' : 'spots' }} left</span><span class="gd-spots-frac">{{ game.joinedCount || 0 }}/{{ game.max_players }}</span></div>
              <div class="gd-spots-bar"><div class="gd-spots-fill" :style="{ width: fillPct + '%' }"></div></div>
            </div>
            <div class="gd-how">
              <div class="gd-how-h">How joining works</div>
              <ol v-if="instantJoin">
                <li><b>Join</b> — {{ stripeTickets ? 'pay online and your spot is confirmed instantly' : 'your spot is confirmed instantly' }}.</li>
                <li><b>Show up</b> — you get the host's contact and directions right away.</li>
              </ol>
              <ol v-else>
                <li><b>Request</b> — say a quick hello to the host.</li>
                <li><b>Host accepts</b> — they choose their players.</li>
                <li><b>You're in</b> — get their contact and show up.</li>
              </ol>
            </div>
            <div v-if="memberGated" class="gd-mgate">
              <div class="gd-mgate-t"><ShieldCheck :size="15" /> {{ communityInfo?.name || 'Community' }} members only</div>
              <p>This event is for community members. Join {{ communityInfo?.name || 'the community' }} first — once you're in, you can {{ instantJoin ? 'join' : 'request to join' }} the event.</p>
              <p v-if="memberPending" class="gd-mgate-pending"><Hourglass :size="13" /> Your request to join {{ communityInfo?.name || 'the community' }} is pending admin approval.</p>
              <button v-else class="gd-join" :disabled="joiningCommunity || !communityInfo" @click="joinCommunityFirst">
                {{ joiningCommunity ? 'Joining…' : (communityInfo?.is_private ? 'Request to join the community' : `Join ${communityInfo?.name || 'the community'}`) }}
              </button>
              <router-link v-if="communityInfo" :to="`/communities/${communityInfo.id}`" class="gd-mgate-link">See the community first →</router-link>
            </div>
            <template v-else>
              <button class="gd-join" :disabled="submitting" @click="openJoin">{{ instantJoin ? (stripeTickets ? 'Pay & join' : 'Join now') : 'Request to join' }}</button>
              <p class="gd-form-hint">{{ joinHint }}</p>
            </template>
          </template>

          <!-- full: instant-join events queue a waitlist; review events just close -->
          <template v-else-if="canWaitlist">
            <div class="gd-full gd-full-wl"><Users :size="16" /> This {{ isHangout ? 'hangout' : 'game' }} is full</div>
            <div v-if="memberGated" class="gd-mgate-mini"><ShieldCheck :size="14" /> Members-only — join {{ communityInfo?.name || 'the community' }} first to use the waitlist.</div>
            <template v-else>
              <button class="gd-join gd-join-wl" :disabled="submitting" @click="openJoin"><Hourglass :size="15" /> Join the waitlist</button>
              <p class="gd-form-hint">{{ instantJoin ? "If someone drops out you take their spot automatically — we'll let you know on WhatsApp." : "If a spot frees up, your request goes to the host for approval — oldest first." }}</p>
            </template>
          </template>
          <div v-else class="gd-full"><Users :size="16" /> This {{ isHangout ? 'hangout' : 'game' }} is full</div>

          <!-- universal: share + save to calendar (any viewer) -->
          <div v-if="!isCancelled" class="gd-actions">
            <button class="gd-act" @click="share"><component :is="shared ? Check : Copy" :size="15" /> {{ shared ? 'Copied!' : 'Copy link' }}</button>
            <a class="gd-act" :href="waShareUrl" target="_blank"><Share2 :size="15" /> Share</a>
            <a v-if="gcalUrl && (isHost || myAccepted)" class="gd-act gd-act-wide" :href="gcalUrl" target="_blank"><CalendarPlus :size="15" /> Add to calendar</a>
          </div>
        </aside>
      </div>

      <!-- mobile-only docked join bar — mirrors the sidebar join context exactly
           (game loaded, not cancelled, not the host, no request yet, requests open, spots left) -->
      <div v-if="!isCancelled && !isHost && !myReq && !requestsClosed && !memberGated && (spotsLeft > 0 || canWaitlist)" class="gd-dock">
        <div class="gd-dock-price"><template v-if="Number(game.price_per_person) > 0">{{ game.currency }} {{ Number(game.price_per_person).toLocaleString() }}<span>/person</span></template><template v-else>Free</template></div>
        <button class="gd-dock-btn" :disabled="submitting" @click="openJoin">{{ canWaitlist ? 'Join the waitlist' : (instantJoin ? (stripeTickets ? 'Pay & join' : 'Join now') : 'Request to join') }}</button>
      </div>

      <!-- Weak-profile nudge: shown once when a host-approval join starts, always skippable -->
      <div v-if="showNudge" class="gd-modal-back" @click.self="showNudge = false">
        <div class="gd-modal">
          <h3 class="gd-nudge-mh"><Sparkles :size="18" /> Boost your acceptance odds</h3>
          <p>Hosts are more likely to accept players with a complete profile. Yours is <b>{{ myStrength?.score || 0 }}% complete</b>.</p>
          <div class="gd-modal-foot">
            <button class="gd-modal-keep" @click="skipNudge">Skip for now</button>
            <router-link to="/account" class="gd-nudge-go">Complete my profile</router-link>
          </div>
        </div>
      </div>

      <JoinModal :open="showJoin" kind="game" :instant="instantJoin" :default-iso="gameIso" :busy="submitting" :error="err" :initial="joinInitial" :custom-form="game.signup_form" @submit="submitJoin" @close="showJoin = false" />

      <!-- Event chat FAB (accepted players + host; read-only after the event ends) -->
      <button v-if="canMeetChat && !isCancelled" type="button" class="gd-chatfab" @click="openMeetChat" aria-label="Event chat">
        <MessageCircle :size="22" />
      </button>
      <div v-if="meetChatOpen" class="gd-chat-back" @click.self="closeMeetChat">
        <div class="gd-chat">
          <div class="gd-chat-head">
            <span><MessageCircle :size="16" /> {{ game.title || 'Event chat' }}</span>
            <button type="button" class="gd-chat-x" @click="closeMeetChat"><X :size="18" /></button>
          </div>
          <div ref="meetBox" class="gd-chat-body" data-no-translate>
            <div v-if="!meetMsgs.length" class="gd-chat-empty">Only the people going see this chat — sort the logistics here.</div>
            <div v-for="m in meetMsgs" :key="m.id" class="gd-chat-msg" :class="{ mine: m.sender_id === customer?.id }">
              <div v-if="m.sender_id !== customer?.id" class="gd-chat-who">{{ m.senderName }}</div>
              <div class="gd-chat-bubble"><ChatMessageBody :message="m" :viewer-id="customer?.id" /></div>
            </div>
          </div>
          <div v-if="lifeState === 'archived'" class="gd-chat-ended"><Clock :size="14" /> Event ended — chat is read-only.</div>
          <ChatComposer v-else v-model="meetText" :thread-key="groupKey('meet', game.id)" :busy="meetSending" placeholder="Message everyone going…" @send="sendMeetChat" />
          <p v-if="err" role="alert">{{ err }}</p>
        </div>
      </div>

      <!-- Props sheet: badge fellow players (once each per badge) -->
      <div v-if="propsOpen" class="gd-chat-back" @click.self="propsOpen = false">
        <div class="gd-chat gd-props">
          <div class="gd-chat-head">
            <span>🤝 Give props</span>
            <button type="button" class="gd-chat-x" @click="propsOpen = false"><X :size="18" /></button>
          </div>
          <div class="gd-props-body">
            <p class="gd-props-hint">Tap a badge to award it — it shows on their profile. One of each per player.</p>
            <div v-for="p in propsPeople" :key="p.id" class="gd-props-row">
              <span class="gd-props-name">{{ p.name }}</span>
              <div class="gd-props-badges">
                <button v-for="(b, key) in STREET_CRED_BADGES" :key="key" type="button" class="gd-props-badge"
                  :class="{ on: hasProp(p.id, key) }" :disabled="propsBusyKey === p.id + key || hasProp(p.id, key)"
                  :title="b.label" @click="sendProp(p.id, key)">{{ b.emoji }}<span>{{ b.label }}</span></button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- host: confirm event cancellation -->
      <div v-if="showCancel" class="gd-modal-back" @click.self="showCancel = false">
        <div class="gd-modal">
          <h3>Cancel this {{ isHangout ? 'hangout' : 'game' }}?</h3>
          <p>
            It'll be removed for everyone who joined or requested.
            <template v-if="game.booking_id"> Your venue booking at <b>{{ game.locationName || 'the venue' }}</b> on <b>{{ dateLabel }}{{ game.start_time ? ', ' + fmt12(game.start_time) : '' }}</b> will be cancelled as well.</template>
          </p>
          <div v-if="cancelErr" class="gd-err">{{ cancelErr }}</div>
          <div class="gd-modal-foot">
            <button class="gd-modal-keep" :disabled="cancellingEvent" @click="showCancel = false">Keep it</button>
            <button class="gd-modal-cancel" :disabled="cancellingEvent" @click="confirmCancelEvent">{{ cancellingEvent ? 'Cancelling…' : 'Yes, cancel' }}</button>
          </div>
        </div>
      </div>

      <!-- host: edit the published event (safe fields — bottom sheet on phones) -->
      <div v-if="showEdit" class="gd-modal-back" @click.self="showEdit = false">
        <div class="gd-modal gd-edit-modal">
          <h3><Pencil :size="17" /> Edit {{ typeWord }} details</h3>
          <p v-if="canEditSchedule" class="gd-edit-note">You're hosting at your own spot, so you can change the date &amp; time below.</p>
          <p v-else class="gd-edit-note">The venue, date and time are tied to your court booking — to reschedule, contact the venue, or cancel and host again.</p>
          <div class="gd-ef">
            <label>Title
              <input v-model="editF.title" type="text" />
            </label>
            <label>About your {{ typeWord }}
              <textarea v-model="editF.description" rows="3"></textarea>
            </label>
            <div v-if="canEditSchedule" class="gd-ef-row">
              <label>Date
                <input v-model="editF.game_date" type="date" />
              </label>
              <label>Start
                <input v-model="editF.start_time" type="time" />
              </label>
              <label>End
                <input v-model="editF.end_time" type="time" />
              </label>
            </div>
            <div class="gd-ef-row">
              <label v-if="!isHangout">Skill level
                <select v-model="editF.skill_level">
                  <option value="all">All levels</option><option value="beginner">Beginner</option>
                  <option value="intermediate">Intermediate</option><option value="advanced">Advanced</option>
                </select>
              </label>
              <label>Price / person ({{ game.currency }})
                <input v-model.number="editF.price_per_person" type="number" min="0" inputmode="decimal" />
              </label>
              <label>Max players
                <input v-model.number="editF.max_players" type="number" min="0" inputmode="numeric" />
              </label>
            </div>
            <div class="gd-ef-row">
              <label>Your name
                <input v-model="editF.host_name" type="text" />
              </label>
              <label>WhatsApp
                <input v-model="editF.host_whatsapp" type="tel" inputmode="tel" />
              </label>
            </div>
            <p class="gd-edit-hint">Raising max players lets waitlisted people in automatically. Everyone already accepted keeps their spot.</p>
          </div>
          <p v-if="editErr" class="gd-edit-err">{{ editErr }}</p>
          <div class="gd-modal-foot">
            <button class="gd-modal-keep" @click="showEdit = false">Cancel</button>
            <button class="gd-edit-save" :disabled="editSaving" @click="saveEditEvent">{{ editSaving ? 'Saving…' : 'Save changes' }}</button>
          </div>
        </div>
      </div>

      <!-- fullscreen image viewer -->
      <Teleport to="body">
        <div v-if="lightbox" class="gd-lb" @click.self="closeLightbox">
          <button class="gd-lb-x" @click="closeLightbox" aria-label="Close"><X :size="22" /></button>
          <button v-if="images.length > 1" class="gd-lb-nav prev" @click.stop="lbPrev" aria-label="Previous"><ChevronLeft :size="26" /></button>
          <img :src="images[lbIdx]" class="gd-lb-img" alt="" />
          <button v-if="images.length > 1" class="gd-lb-nav next" @click.stop="lbNext" aria-label="Next"><ChevronRight :size="26" /></button>
          <div v-if="images.length > 1" class="gd-lb-count">{{ lbIdx + 1 }} / {{ images.length }}</div>
        </div>
      </Teleport>
    </template>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
/* ── Luma-inspired top block: poster (left, shown in full) + title/facts (right) ── */
.gd-top { display: grid; grid-template-columns: minmax(240px, 330px) 1fr; gap: 26px; align-items: start; }
.gd-top-media { display: flex; flex-direction: column; gap: 10px; }

/* Poster keeps its NATURAL aspect ratio — never cropped. Click opens the lightbox. */
.gd-poster { position: relative; display: block; width: 100%; padding: 0; border: 1px solid var(--border, #e2e8f0); border-radius: 20px; overflow: hidden; cursor: zoom-in; background: var(--bg, #f1f5f9); box-shadow: 0 10px 30px rgba(15,23,42,.10); }
.gd-poster img { display: block; width: 100%; height: auto; }
.gd-poster-zoom { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; background: rgba(15,23,42,.55); color: #fff; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; }
.gd-poster:hover .gd-poster-zoom { opacity: 1; }

/* No photo → branded gradient placeholder in the poster slot (keeps the old header hues) */
.gd-cover-fb { aspect-ratio: 4 / 3; border-radius: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #fff; font-weight: 800; font-size: 15px; text-transform: capitalize; }
.gd-cover-fb.is-game { background: linear-gradient(135deg,#2563eb,#1d4ed8); }
.gd-cover-fb.is-tourn { background: linear-gradient(135deg,#f43f5e,#e11d48); }
.gd-cover-fb.is-event { background: linear-gradient(135deg,#f59e0b,#d97706); }

/* Title column — type badge is a tinted pill now that it sits on the page, not on a photo */
.gd-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; padding: 5px 12px; border-radius: 999px; }
.gd-badge.is-game { background: var(--info-bg, #dbeafe); color: var(--info-ink, #1d4ed8); }
.gd-badge.is-tourn { background: var(--danger-bg, #ffe4e6); color: #e11d48; }
.gd-badge.is-event { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); }
.gd-community { display: inline-flex; align-items: center; gap: 5px; margin-left: 8px; font-size: 12.5px; font-weight: 800; color: #0d9488; background: var(--success-bg, #f0fdfa); border: 1px solid var(--success-border, #99f6e4); padding: 5px 12px; border-radius: 999px; text-decoration: none; }
.gd-community:hover { border-color: #0d9488; }
/* Lifecycle strip (Open → Waitlist → Locked → Live → Ended) */
.gd-life { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-top: 10px; font-size: 13px; }
.gd-life-chip { flex-shrink: 0; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: 4px 11px; border-radius: 999px; }
.gd-life.st-open .gd-life-chip { background: var(--success-bg, #dcfce7); color: var(--success-ink, #15803d); }
.gd-life.st-waitlisted .gd-life-chip { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.gd-life.st-locked .gd-life-chip { background: var(--bg, #e2e8f0); color: var(--text-secondary, #334155); }
.gd-life.st-active .gd-life-chip { background: var(--success-bg, #dcfce7); color: var(--success-ink, #15803d); animation: gdpulse 1.6s ease infinite; }
.gd-life.st-archived .gd-life-chip { background: var(--bg, #f1f5f9); color: var(--text-light, #94a3b8); }
@keyframes gdpulse { 50% { opacity: .55; } }
.gd-life-t { display: inline-flex; align-items: center; gap: 5px; color: var(--text-muted, #64748b); font-weight: 600; }
.gd-lock-note { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 10px; padding: 8px 11px; margin-top: 10px; }

/* Live rounds runner + standings */
.gd-rounds { margin-top: 26px; }
.gd-rounds-sub { font-size: 12.5px; font-weight: 700; color: var(--text-light, #94a3b8); margin-left: 8px; text-transform: none; }
.gd-standings { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 6px 12px; margin-bottom: 14px; }
.gd-stand { display: flex; align-items: center; gap: 10px; padding: 7px 2px; font-size: 13.5px; }
.gd-stand + .gd-stand { border-top: 1px solid var(--border, #f1f5f9); }
.gd-stand-n { width: 20px; height: 20px; border-radius: 50%; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gd-stand.top .gd-stand-n { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); }
.gd-stand-name { flex: 1; min-width: 0; font-weight: 700; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gd-stand-wl { font-weight: 800; color: var(--text-secondary, #475569); font-variant-numeric: tabular-nums; }
.gd-stand-r { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: var(--violet-ink, #4338ca); background: var(--violet-bg, #eef2ff); padding: 2px 9px; border-radius: 999px; }
.gd-stand.top .gd-stand-r svg { color: var(--warning-ink, #ca8a04); }
.gd-round { margin-bottom: 12px; }
.gd-round-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #0d9488; margin-bottom: 8px; }
.gd-round-sit { font-weight: 600; text-transform: none; letter-spacing: 0; color: var(--text-light, #94a3b8); margin-left: 8px; }
.gd-courts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(250px, 100%), 1fr)); gap: 10px; }
.gd-court { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 9px; }
.gd-court.done { border-color: var(--success-border, #99f6e4); }
.gd-court-h { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 6px; }
.gd-court-side { display: flex; align-items: center; justify-content: space-between; gap: 6px; width: 100%; text-align: left; background: var(--canvas, #f8fafc); border: 1.5px solid transparent; border-radius: 8px; padding: 9px 11px; font: inherit; font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); min-height: 42px; }
.gd-court-side.tap { cursor: pointer; }
.gd-court-side.tap:hover { border-color: #5eead4; }
.gd-court-side.win { background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); }
.gd-court-side.lose { opacity: .55; }
.gd-court-side svg { color: var(--warning-ink, #ca8a04); flex-shrink: 0; }
.gd-court-vs { text-align: center; font-size: 10.5px; font-weight: 800; color: #cbd5e1; margin: 3px 0; }
.gd-court-draw { display: block; width: 100%; margin-top: 6px; background: none; border: 1.5px dashed var(--border, #e2e8f0); color: var(--text-muted, #64748b); font: inherit; font-size: 11.5px; font-weight: 800; padding: 6px; border-radius: 8px; cursor: pointer; }
.gd-court-draw.on { background: var(--warning-bg, #fef3c7); border-color: var(--warning-border, #fcd34d); border-style: solid; color: var(--warning-ink, #92400e); }
.gd-court-draw:disabled { cursor: default; }
.gd-rounds-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
.gd-genround { display: inline-flex; align-items: center; gap: 7px; background: #0d9488; color: #fff; font-weight: 800; font-size: 14px; padding: 12px 20px; border: none; border-radius: 999px; cursor: pointer; }
.gd-genround:disabled { opacity: .5; cursor: default; }
.gd-rounds-hint { font-size: 12.5px; color: var(--text-muted, #64748b); font-weight: 600; }

/* Event chat (FAB + bottom sheet) */
.gd-chatfab { position: fixed; right: 18px; bottom: 86px; z-index: 80; width: 54px; height: 54px; border-radius: 50%; border: none; background: var(--brand, #e11d48); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 26px rgba(15,23,42,.3); }
.gd-chat-back { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.5); display: flex; align-items: flex-end; justify-content: center; }
.gd-chat { width: 100%; max-width: 560px; background: var(--surface, #ffffff); border-radius: 20px 20px 0 0; display: flex; flex-direction: column; max-height: 82vh; }
.gd-chat-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border, #e2e8f0); }
.gd-chat-head span { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 15px; color: var(--text, #0f172a); min-width: 0; }
.gd-chat-x { background: none; border: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 4px; }
.gd-chat-body { flex: 1; overflow-y: auto; padding: 14px 16px; min-height: 200px; }
.gd-chat-empty { text-align: center; color: var(--text-light, #94a3b8); font-size: 13.5px; padding: 28px 0; }
.gd-chat-msg { margin-bottom: 10px; display: flex; flex-direction: column; align-items: flex-start; }
.gd-chat-msg.mine { align-items: flex-end; }
.gd-chat-who { font-size: 11px; font-weight: 800; color: var(--text-light, #94a3b8); margin: 0 4px 2px; }
.gd-chat-bubble { max-width: 78%; background: var(--bg, #f1f5f9); color: var(--text, #0f172a); border-radius: 14px; padding: 9px 13px; font-size: 14px; line-height: 1.4; overflow-wrap: anywhere; white-space: pre-wrap; }
.gd-chat-msg.mine .gd-chat-bubble { background: var(--brand, #e11d48); color: #fff; }
.gd-chat-bar { display: flex; gap: 8px; padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); }
.gd-chat-bar input { flex: 1; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 11px 16px; font: inherit; font-size: 15px; outline: none; background: none; color: var(--text, #0f172a); }
.gd-chat-send { width: 44px; height: 44px; border-radius: 50%; border: none; background: var(--brand, #e11d48); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.gd-chat-send:disabled { opacity: .5; }
.gd-chat-ended { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 13px calc(13px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); background: var(--canvas, #f8fafc); }

/* Props (street cred) */
.gd-props-cta { margin-top: 18px; }
.gd-props-btn { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--warning-bg, #fffbeb); border: 1.5px solid var(--warning-border, #fde68a); color: var(--warning-ink, #b45309); font: inherit; font-weight: 800; font-size: 14.5px; padding: 13px; border-radius: 14px; cursor: pointer; }
.gd-props-body { padding: 12px 16px calc(16px + env(safe-area-inset-bottom, 0px)); overflow-y: auto; }
.gd-props-hint { font-size: 12.5px; color: var(--text-muted, #64748b); margin: 0 0 12px; }
.gd-props-row { padding: 10px 0; border-top: 1px solid var(--border, #f1f5f9); }
.gd-props-name { display: block; font-size: 14px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 7px; }
.gd-props-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.gd-props-badge { display: inline-flex; align-items: center; gap: 5px; border: 1.5px solid var(--border, #e2e8f0); background: none; font: inherit; font-size: 12px; font-weight: 700; color: var(--text-secondary, #475569); padding: 7px 11px; border-radius: 999px; cursor: pointer; }
.gd-props-badge.on { background: var(--warning-bg, #fef3c7); border-color: var(--warning-border, #fcd34d); color: var(--warning-ink, #92400e); cursor: default; }
.gd-props-badge:disabled:not(.on) { opacity: .6; }
@media (min-width: 761px) {
  .gd-chat-back { align-items: center; padding: 20px; }
  .gd-chat { border-radius: 20px; }
  .gd-chatfab { bottom: 26px; }
}
.gd-headinfo h1 { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); margin: 12px 0 4px; line-height: 1.15; overflow-wrap: anywhere; }
.gd-sport { font-size: 15px; color: var(--text-muted, #64748b); text-transform: capitalize; }
.gd-meta { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }

/* extra photos — thumbnails under the poster */
.gd-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 8px; }
.gd-gallery-img { height: 64px; width: 100%; border-radius: 10px; background-size: cover; background-position: center; border: 1px solid var(--border, #e2e8f0); display: block; padding: 0; cursor: zoom-in; transition: transform .12s; }
.gd-gallery-img:hover { transform: translateY(-2px); }

/* fullscreen lightbox (teleported to body, outside the dark-mode invert) */
.gd-lb { position: fixed; inset: 0; z-index: 200; background: rgba(2,6,23,.93); display: flex; align-items: center; justify-content: center; padding: 30px; }
.gd-lb-img { max-width: 92vw; max-height: 88vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); }
.gd-lb-x { position: absolute; top: 16px; right: 16px; width: 42px; height: 42px; border-radius: 50%; border: none; background: rgba(255,255,255,.15); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.gd-lb-x:hover { background: rgba(255,255,255,.28); }
.gd-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 48px; height: 48px; border-radius: 50%; border: none; background: rgba(255,255,255,.15); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.gd-lb-nav:hover { background: rgba(255,255,255,.28); }
.gd-lb-nav.prev { left: 16px; }
.gd-lb-nav.next { right: 16px; }
.gd-lb-count { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 14px; font-weight: 700; background: rgba(0,0,0,.45); padding: 5px 14px; border-radius: 999px; }

/* event cancellation */
.gd-cancelled { display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--danger-bg, #fef2f2); border: 1.5px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c); font-weight: 700; font-size: 14.5px; text-align: center; padding: 12px; border-radius: 12px; margin-top: 16px; }
.gd-cancelled-side { display: flex; align-items: center; justify-content: center; gap: 7px; background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c); font-weight: 700; font-size: 14px; text-align: center; padding: 14px; border-radius: 12px; }
.gd-cancel-event { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--surface, #ffffff); color: var(--danger-ink, #b91c1c); font-weight: 700; font-size: 13.5px; padding: 10px; border: 1.5px solid var(--danger-border, #fecaca); border-radius: 10px; cursor: pointer; margin-top: 8px; }
.gd-cancel-event:hover { background: var(--danger-bg, #fef2f2); }
.gd-edit-event, .gd-again { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--surface, #ffffff); color: var(--text-secondary, #334155); font-weight: 700; font-size: 13.5px; padding: 10px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; cursor: pointer; margin-top: 8px; min-height: 42px; }
.gd-edit-event:hover, .gd-again:hover { border-color: var(--brand); color: var(--brand-ink, var(--brand)); }

/* Waitlist join CTA (full instant-join events) */
.gd-full-wl { margin-bottom: 10px; }
.gd-join-wl { background: #4338ca; }
.gd-join-wl:hover { background: #3730a3; }

/* Edit-details modal — form fields sized for thumbs (≥16px stops iOS zoom) */
.gd-edit-modal { max-width: 460px; max-height: 90vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.gd-edit-modal h3 { display: flex; align-items: center; gap: 8px; }
.gd-edit-note { font-size: 13px !important; color: var(--text-muted, #64748b) !important; }
.gd-ef { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; }
.gd-ef label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #475569); }
.gd-ef input, .gd-ef select, .gd-ef textarea { width: 100%; box-sizing: border-box; font-size: 16px; font-weight: 500; color: var(--text, #0f172a); padding: 10px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff); }
.gd-ef textarea { resize: vertical; }
.gd-ef-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
.gd-edit-hint { font-size: 12.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0 !important; }
.gd-edit-err { color: var(--danger-ink, #b91c1c); font-weight: 600; font-size: 13.5px; }
.gd-edit-save { flex: 1; font-weight: 800; font-size: 14px; padding: 11px; border: none; border-radius: 10px; cursor: pointer; background: var(--brand); color: #fff; }
.gd-edit-save:disabled { opacity: .6; cursor: default; }
@media (max-width: 640px) {
  /* Phone: the edit modal becomes a bottom sheet */
  .gd-modal-back { align-items: flex-end; padding: 0; }
  .gd-edit-modal { max-width: none; border-radius: 18px 18px 0 0; padding-bottom: calc(22px + env(safe-area-inset-bottom)); }
}
.gd-modal-back { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; z-index: 60; padding: 20px; }
.gd-modal { background: var(--surface, #ffffff); border-radius: 16px; padding: 22px; max-width: 390px; width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.gd-modal h3 { font-size: 18px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 8px; }
.gd-modal p { font-size: 14px; color: var(--text-secondary, #475569); line-height: 1.55; margin: 0 0 16px; }
.gd-modal p b { color: var(--text, #0f172a); }
.gd-modal-foot { display: flex; gap: 10px; }
.gd-modal-keep, .gd-modal-cancel { flex: 1; font-weight: 800; font-size: 14px; padding: 11px; border: none; border-radius: 10px; cursor: pointer; }
.gd-modal-keep { background: var(--bg, #f1f5f9); color: var(--text-secondary, #334155); }
.gd-modal-cancel { background: #dc2626; color: #fff; }
.gd-modal-keep:disabled, .gd-modal-cancel:disabled { opacity: .6; cursor: default; }
.gd-grid { display: grid; grid-template-columns: 1fr 320px; gap: 24px; margin-top: 20px; }
.fact { display: flex; align-items: center; gap: 11px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; color: var(--brand-ink, var(--brand)); min-width: 0; }
.fact > svg { flex-shrink: 0; }
.fact div { display: flex; flex-direction: column; color: var(--text, #0f172a); min-width: 0; }
.fact b { font-size: 14.5px; }
.fact span { font-size: 12.5px; color: var(--text-light, #94a3b8); }
.gd-desc { font-size: 15px; color: var(--text-secondary, #334155); line-height: 1.6; margin: 10px 0 18px; }
.gd-sec-h { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); margin: 22px 0 8px; padding-bottom: 7px; border-bottom: 1px solid var(--border, #eef2f6); }

/* Who's going — overlapping avatars + names line */
.gd-going { display: flex; align-items: center; gap: 10px; margin: 16px 0 4px; }
.gd-going-stack { display: flex; align-items: center; flex-shrink: 0; }
.gd-go-av { width: 30px; height: 30px; border-radius: 50%; background: #cbd5e1; background-size: cover; background-position: center; color: var(--text-secondary, #475569); font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #fff; margin-left: -9px; box-shadow: 0 1px 4px rgba(15,23,42,.08); }
.gd-go-av:first-child { margin-left: 0; }
.gd-going-txt { font-size: 13.5px; color: var(--text-muted, #64748b); min-width: 0; }
.gd-going-txt b { color: var(--text, #0f172a); }

/* Location block */
.gd-loc-sec { margin-top: 4px; }
.gd-loc-card { display: flex; align-items: center; gap: 12px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.gd-loc-card > svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.gd-loc-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.gd-loc-info b { font-size: 14.5px; color: var(--text, #0f172a); }
.gd-loc-info span { font-size: 13px; color: var(--text-muted, #64748b); }
.gd-loc-go { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; font-size: 13px; font-weight: 800; color: var(--brand-ink, var(--brand)); text-decoration: none; background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); padding: 9px 14px; border-radius: 999px; }
.gd-pay { display: flex; gap: 12px; background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border: 1.5px solid var(--success-border, #99f6e4); border-radius: 14px; padding: 16px; color: var(--brand-ink, var(--brand)); }
.gd-prize { display: flex; align-items: center; gap: 12px; background: linear-gradient(135deg,var(--warning-bg, #fffbeb),var(--warning-bg, #fef3c7)); border: 1.5px solid var(--warning-border, #fcd34d); border-radius: 14px; padding: 14px 16px; color: var(--warning-ink, #b45309); margin: 16px 0; }
.gd-prize b { display: block; font-size: 17px; font-weight: 900; color: var(--warning-ink, #92400e); }
.gd-prize span { font-size: 13px; color: #a16207; }
.gd-side-prize { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 800; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fef3c7); border: 1px solid var(--warning-border, #fde68a); padding: 6px 11px; border-radius: 999px; margin-bottom: 14px; }
.gd-pay div { font-size: 13.5px; color: var(--text-secondary, #334155); line-height: 1.5; }
.gd-pay b { color: var(--text, #0f172a); }

.gd-reqs { margin-top: 28px; }
.gd-reqlink { display: flex; align-items: center; gap: 12px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; text-decoration: none; }
.gd-reqlink:hover { border-color: var(--brand-accent, #93c5fd); }
.gd-reqlink-ic { flex-shrink: 0; width: 40px; height: 40px; border-radius: 11px; background: color-mix(in srgb, var(--brand) 12%, var(--surface, white)); color: var(--brand-ink, var(--brand)); display: flex; align-items: center; justify-content: center; }
.gd-reqlink-tx { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.gd-reqlink-tx b { font-size: 15px; color: var(--text, #0f172a); }
.gd-reqlink-tx span { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.gd-reqlink-go { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; font-weight: 800; font-size: 13.5px; color: var(--brand-ink, var(--brand)); }
.gd-reqlink-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--brand); color: #fff; font-size: 11.5px; font-weight: 800; }
.gd-reqs h2 { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); display: flex; align-items: center; gap: 10px; }
.gd-count { font-size: 13px; background: #0f172a; color: #fff; border-radius: 999px; padding: 2px 10px; }
.gd-hosthint { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 6px 0 14px; }
.gd-noreq { color: var(--text-light, #94a3b8); padding: 24px; text-align: center; background: var(--canvas, #f8fafc); border-radius: 12px; }
.reqcard { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; margin-bottom: 12px; }
.reqcard.rs-accepted { border-color: var(--success-border, #86efac); background: var(--success-bg, #f0fdf4); }
.reqcard.rs-rejected { opacity: .55; }
.reqcard-top { display: flex; align-items: center; gap: 12px; }
.req-av { width: 40px; height: 40px; border-radius: 50%; background: #0f172a; color: #fff; font-weight: 800; font-size: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.reqcard.rs-accepted .req-av { background: #16a34a; }
.req-id { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.req-name { font-size: 16px; font-weight: 800; color: var(--text, #0f172a); }
.req-pill { font-size: 11.5px; font-weight: 800; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; }
.rp-pending { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.rp-accepted { background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); }
.rp-rejected { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #991b1b); }
.rp-waitlisted { background: var(--violet-bg, #e0e7ff); color: #3730a3; }
.req-msg { font-size: 14px; color: var(--text-secondary, #475569); font-style: italic; margin: 8px 0; }
.req-contact { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; }
.req-wa { display: inline-flex; align-items: center; gap: 6px; background: #25d366; color: #fff; font-weight: 700; font-size: 13px; padding: 7px 13px; border-radius: 999px; text-decoration: none; }
.req-tel { display: inline-flex; align-items: center; gap: 6px; background: var(--bg, #f1f5f9); color: var(--text-secondary, #334155); font-weight: 700; font-size: 13px; padding: 7px 13px; border-radius: 999px; text-decoration: none; }
.req-actions { display: flex; gap: 10px; margin-top: 6px; }
.req-accept, .req-reject { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 800; font-size: 14px; padding: 10px; border-radius: 10px; border: none; cursor: pointer; }
.req-accept { background: #16a34a; color: #fff; }
.req-reject { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #b91c1c); }

.gd-side { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; height: fit-content; position: sticky; top: 20px; }
.gd-price { font-size: 28px; font-weight: 900; color: var(--text, #0f172a); }
.gd-price span { font-size: 15px; font-weight: 600; color: var(--text-light, #94a3b8); }
.gd-price-sub { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 2px; }
.gd-host { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-muted, #64748b); margin: 10px 0 16px; }
.gd-host-av { width: 26px; height: 26px; border-radius: 50%; background: var(--brand); background-size: cover; background-position: center; color: #fff; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gd-host-av-comm svg { color: #fff; }
.gd-host b { color: var(--text, #0f172a); }
.gd-host-link { color: var(--brand-ink, var(--brand)); font-weight: 800; text-decoration: none; }
.gd-host-link:hover { text-decoration: underline; }
.req-name-link { text-decoration: none; }
.req-name-link:hover { color: var(--brand-ink, var(--brand)); text-decoration: underline; }
.gd-mstate { display: flex; align-items: center; gap: 7px; font-size: 13px; padding: 10px 13px; border-radius: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.gd-mstate b { font-weight: 800; text-transform: uppercase; letter-spacing: .03em; font-size: 12.5px; }
.gd-mstate small { font-weight: 600; opacity: .85; }
.gd-mstate-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.gd-mstate.st-archived { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }
.gd-mstate.st-active { background: var(--success-bg, #dcfce7); color: var(--success-ink, #15803d); }
.gd-mstate.st-active .gd-mstate-dot { animation: gdpulse 1.6s ease infinite; }
.gd-mstate.st-locked { background: var(--bg, #e2e8f0); color: var(--text-secondary, #334155); }
.gd-manage-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
.gd-vis { display: flex; align-items: center; gap: 10px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 10px 12px; margin-bottom: 10px; }
.gd-vis-info { flex: 1; min-width: 0; display: flex; align-items: center; gap: 9px; color: #0d9488; }
.gd-vis-info b { display: block; font-size: 13px; font-weight: 800; color: var(--text, #0f172a); }
.gd-vis-info small { display: block; font-size: 11.5px; color: var(--text-muted, #64748b); line-height: 1.35; margin-top: 1px; }
.gd-vis-toggle { flex-shrink: 0; width: 44px; height: 26px; border-radius: 999px; border: none; background: #cbd5e1; position: relative; cursor: pointer; transition: background .15s; }
.gd-vis-toggle.on { background: #0d9488; }
.gd-vis-toggle:disabled { opacity: .6; }
.gd-vis-knob { position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: var(--surface, #fff); transition: transform .15s; }
.gd-vis-toggle.on .gd-vis-knob { transform: translateX(18px); }
.gms { background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 10px 4px; text-align: center; }
.gms b { display: block; font-size: 20px; font-weight: 900; color: var(--text, #0f172a); line-height: 1; margin-bottom: 3px; }
.gms-hot { color: var(--warning-ink, #d97706) !important; }
.gms span { font-size: 11px; color: var(--text-light, #94a3b8); font-weight: 600; }
.gd-review { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 7px; background: var(--brand); color: #fff; font-weight: 800; font-size: 14.5px; padding: 12px; border: none; border-radius: 12px; cursor: pointer; margin-bottom: 8px; }
.gd-review:hover { background: #0e6a63; }
.gd-mine { display: flex; flex-direction: column; gap: 10px; }
.gd-cancel { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--surface, #ffffff); color: var(--danger-ink, #b91c1c); font-weight: 700; font-size: 13.5px; padding: 10px; border: 1.5px solid var(--danger-border, #fecaca); border-radius: 10px; cursor: pointer; }
.gd-cancel:hover:not(:disabled) { background: var(--danger-bg, #fef2f2); }
.gd-cancel:disabled { opacity: .6; cursor: default; }
.gd-side-link { display: inline-flex; align-items: center; justify-content: center; gap: 6px; color: var(--brand-ink, var(--brand)); font-weight: 700; font-size: 13.5px; text-decoration: none; padding: 4px; }
.gd-side-link:hover { text-decoration: underline; }
.gd-myreq { border-radius: 12px; padding: 16px; }
.gd-myreq-t { font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 7px; }
.st-pending { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.st-accepted { background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); }
.st-rejected { background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }
.gd-wa-host { display: inline-flex; align-items: center; gap: 7px; background: #25d366; color: #fff; font-weight: 800; padding: 10px 16px; border-radius: 999px; text-decoration: none; margin-top: 12px; font-size: 14px; }
.gd-paid-note { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; font-weight: 700; color: var(--success-ink, #166534); background: var(--success-bg, #f0fdf4); border: 1px solid var(--success-border, #bbf7d0); border-radius: 9px; padding: 8px 10px; margin: 10px 0 0; line-height: 1.4; }
.gd-paid-note svg { flex-shrink: 0; margin-top: 1px; }
.gd-form-t { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); }
.gd-form-hint { font-size: 13px; color: var(--text-muted, #64748b); margin: 5px 0 12px; }
.gd-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 12px 14px; font-size: 15px; margin-bottom: 10px; outline: none; }
.gd-phone { margin-bottom: 10px; }
.gd-in:focus { border-color: var(--brand-accent); }
.gd-ta { resize: vertical; font-family: inherit; }
.gd-err { font-size: 13px; color: var(--danger-ink, #dc2626); margin-bottom: 10px; }
.gd-closed { display: flex; align-items: flex-start; gap: 8px; background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c); border-radius: 12px; padding: 12px 14px; font-size: 13.5px; font-weight: 600; line-height: 1.45; }
.gd-join { width: 100%; background: var(--brand); color: #fff; font-weight: 800; font-size: 15.5px; padding: 14px; border: none; border-radius: 12px; cursor: pointer; }
.gd-join:disabled { opacity: .6; }
/* Weak-profile nudge modal (shown from the Join button, skippable) */
.gd-modal h3.gd-nudge-mh { display: flex; align-items: center; gap: 8px; color: var(--warning-ink, #b45309); }
.gd-nudge-go { flex: 1; display: inline-flex; align-items: center; justify-content: center; background: var(--brand); color: #fff; font-weight: 800; font-size: 14px; padding: 11px; border-radius: 10px; text-decoration: none; text-align: center; }
.gd-full { font-size: 15px; font-weight: 700; color: var(--danger-ink, #dc2626); text-align: center; padding: 16px; background: var(--danger-bg, #fef2f2); border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 7px; }

/* spots-filling bar (capped games, before requesting) */
.gd-spots { margin-bottom: 14px; }
.gd-spots-top { display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 700; color: var(--text, #0f172a); margin-bottom: 6px; }
.gd-spots-frac { color: var(--text-light, #94a3b8); font-weight: 600; }
.gd-spots-bar { height: 7px; border-radius: 999px; background: var(--border, #e2e8f0); overflow: hidden; }
.gd-spots-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg,var(--brand-accent),var(--brand)); transition: width .3s; }

/* "how joining works" explainer */
.gd-how { background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px 14px 14px; margin-bottom: 14px; }
.gd-how-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-bottom: 8px; }
.gd-how ol { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 6px; }
.gd-how li { font-size: 13px; color: var(--text-secondary, #475569); line-height: 1.4; }
.gd-how li b { color: var(--text, #0f172a); }

/* universal share / save actions */
.gd-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border, #e2e8f0); }
.gd-act { display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--surface, #ffffff); color: var(--text-secondary, #334155); font-weight: 700; font-size: 13px; padding: 10px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; cursor: pointer; text-decoration: none; }
.gd-act:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.gd-act-wide { grid-column: 1 / -1; }

/* mobile docked join bar — desktop uses the sidebar CTA, so hidden by default */
.gd-dock { display: none; }

@media (max-width: 760px) {
  /* primary Join CTA docked flush to the screen bottom (flow route: shell nav is hidden
     and .cust-main already reserves calc(92px + safe-area) bottom padding for this bar) */
  .gd-dock { position: fixed; left: 0; right: 0; bottom: 0; z-index: 40; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--surface, #ffffff); border-top: 1px solid var(--border, #e2e8f0); box-shadow: 0 -6px 20px rgba(15,23,42,.08); padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); }
  .gd-dock-price { font-size: 18px; font-weight: 900; color: var(--text, #0f172a); min-width: 0; }
  .gd-dock-price span { font-size: 12.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
  .gd-dock-btn { flex-shrink: 0; background: var(--brand); color: #fff; font-weight: 800; font-size: 15px; padding: 13px 22px; border: none; border-radius: 12px; cursor: pointer; }
  .gd-dock-btn:disabled { opacity: .6; }

  /* 1 + 9: single column, and lift the sidebar (price + primary CTA + host stats/review) ABOVE the long left column */
  .gd-grid { display: flex; flex-direction: column; grid-template-columns: 1fr; gap: 16px; margin-top: 16px; }
  .gd-side { position: static; order: -1; padding: 16px; border-radius: 16px; }
  .gd-main { order: 0; }

  /* 2: top block stacks — poster first (full width, natural ratio), then title + facts.
     align-items must reset to stretch: the desktop grid's `start` would otherwise make
     the flex-column children shrink to content width (tiny fallback card). */
  .gd-top { display: flex; flex-direction: column; align-items: stretch; gap: 14px; }
  .gd-poster, .gd-cover-fb { border-radius: 16px; }
  .gd-cover-fb { aspect-ratio: 16 / 9; }
  .gd-poster-zoom { opacity: 1; }   /* no hover on touch — keep the zoom hint visible */
  .gd-headinfo h1 { font-size: 22px; margin-top: 10px; }

  /* long unbroken titles / host text must never push past the card edge */
  .gd-headinfo h1, .gd-sport, .gd-desc { overflow-wrap: anywhere; }

  /* 4 + 10: tighten fact cards, keep long addresses from causing sideways scroll */
  .gd-meta { gap: 8px; margin-top: 12px; }
  .fact { padding: 11px 13px; }
  .fact b, .fact span { overflow-wrap: anywhere; }

  /* 5: secondary photos become a single horizontal scroll strip (never stacks into extra rows) */
  .gd-gallery { display: flex; overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 8px; margin-bottom: 12px; }
  .gd-gallery-img { flex: 0 0 88px; height: 72px; width: 88px; }

  /* 7: de-emphasize the secondary explainer so the primary Join CTA reads first */
  .gd-how { padding: 12px; margin-bottom: 10px; }

  /* 6: cancel-confirm dialog becomes a bottom sheet with ≥44px footer buttons */
  .gd-modal-back { align-items: flex-end; padding: 0; }
  .gd-modal { max-width: none; width: 100%; border-radius: 16px 16px 0 0; }
  .gd-modal-keep, .gd-modal-cancel { padding: 13px; }

  /* 8: raise touch targets to ≥44px for sidebar/host controls */
  .gd-side-link { padding: 10px; }
  .gd-cancel, .gd-cancel-event { padding: 12px; }
  .gd-act { padding: 12px; }
  .req-contact { flex-wrap: wrap; }
  .req-wa, .req-tel { padding: 10px 14px; }
  .req-wa { min-width: 44px; min-height: 44px; justify-content: center; }

  /* host manage stats: keep 3 cells comfortable down to 344px */
  .gd-manage-stats { gap: 6px; }
  .gms span { white-space: nowrap; }
}

/* narrow phones: give the lightbox image room between the nav arrows */
@media (max-width: 600px) {
  .gd-lb { padding: 12px; }
  .gd-lb-nav { width: 42px; height: 42px; }
  .gd-lb-nav.prev { left: 6px; }
  .gd-lb-nav.next { right: 6px; }
  .gd-lb-x { top: 10px; right: 10px; }
}
.gd-mgate { border: 1.5px solid var(--success-border, #99f6e4); background: var(--success-bg, #f0fdfa); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 8px; }
.gd-mgate-t { display: flex; align-items: center; gap: 7px; font-weight: 800; font-size: 14px; color: var(--success-ink, #0f766e); }
.gd-mgate p { margin: 0; font-size: 12.5px; color: var(--text-secondary, #475569); line-height: 1.5; }
.gd-mgate-pending { display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--warning-ink, #b45309) !important; }
.gd-mgate-link { font-size: 12.5px; font-weight: 700; color: var(--brand, #0d9488); text-decoration: none; }
.gd-mgate-mini { display: flex; align-items: center; gap: 7px; border: 1px solid var(--success-border, #99f6e4); background: var(--success-bg, #f0fdfa); border-radius: 10px; padding: 9px 12px; font-size: 12.5px; font-weight: 700; color: var(--success-ink, #0f766e); }
</style>
