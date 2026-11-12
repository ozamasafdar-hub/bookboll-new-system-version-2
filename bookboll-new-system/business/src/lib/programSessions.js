/**
 * programSessions.js — rule-based class-program reservations.
 *
 * A program's recurrence (days + time + duration + start/optional end) is the
 * single source of truth for the calendar slots it reserves. We DON'T materialise
 * a booking row per session anymore — sessions are derived on the fly for whatever
 * date range is on screen, and a facility booking is blocked at creation time if it
 * collides with a live program's recurrence.
 *
 * One-off cancellations ("exceptions") are the only thing stored as rows: a
 * `bookings` row with origin='class_program', booking_status='cancelled' for that
 * program + date. Pass those in as a Set of `${program_id}|${date}` keys.
 */
import { rowState } from './approvalState.js'
import { fmtRange12 } from './slots'

export function addMinutesToTime(timeStr, minutes) {
  const [h, m] = (timeStr || '00:00').split(':').map(Number)
  const total = h * 60 + m + (minutes || 0)
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}
/** A program shows sessions only while it is live: active status + approved & not paused. */
export function programIsLive(p) {
  return !!p && p.status === 'active' && rowState(p) === 'active'
}

/**
 * Latest paid-through date per program, so a paused/archived/de-activated program
 * keeps its slots reserved until its current subscribers' next billing date — then
 * frees them. Returns { [programId]: 'YYYY-MM-DD' }. Build once and pass to the
 * functions below. (A live program ignores this; it reserves on its own schedule.)
 */
export function windDownMap(subscriptions) {
  const m = {}
  for (const s of subscriptions || []) {
    const stillPaid = (s.status === 'active' || s.trial_status === 'in_trial') && s.next_billing_at
    if (!stillPaid) continue
    if (!m[s.program_id] || s.next_billing_at > m[s.program_id]) m[s.program_id] = s.next_billing_at
  }
  return m
}

/**
 * The window within [fromISO,toISO] that a program actually reserves, or null if it
 * reserves nothing.
 *   • Live program → its own schedule (ongoing, or until end_date for fixed).
 *   • Not live (paused / inactive / archived) → only until its wind-down date
 *     (its subscribers' latest next-billing date) so paid members keep their slots.
 */
function reservationWindow(p, fromISO, toISO, windDownUntil = {}) {
  let from = fromISO
  if (p.start_date && p.start_date > from) from = p.start_date
  let to = toISO
  if (programIsLive(p)) {
    if (p.program_type === 'fixed_duration' && p.end_date && p.end_date < to) to = p.end_date
  } else {
    const wd = windDownUntil[p.id]
    if (!wd) return null           // not live and no paid subscribers → slots free
    if (wd < to) to = wd
  }
  return from > to ? null : { from, to }
}

function datesForRule(mask, fromISO, toISO) {
  const set = new Set(mask || [])
  if (!set.size || !fromISO || !toISO || fromISO > toISO) return []
  const out = []
  const cur = new Date(fromISO + 'T00:00:00')
  const end = new Date(toISO + 'T00:00:00')
  while (cur <= end) {
    // Build the date string from LOCAL parts (cur.getDay() is local), not via
    // toISOString() which would shift the date by a day in non-UTC timezones.
    if (set.has(cur.getDay())) {
      out.push(`${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}-${String(cur.getDate()).padStart(2, '0')}`)
    }
    cur.setDate(cur.getDate() + 1)
  }
  return out
}

/**
 * Derive booking-shaped session objects for all live programs across [fromISO,toISO].
 * @param exceptions Set of `${program_id}|${date}` cancelled occurrences.
 */
export function generateProgramSessions(programs, fromISO, toISO, { listings = [], exceptions = new Set(), windDownUntil = {} } = {}) {
  return [] // Membership listings do not create calendar reservations.
  const sessions = []
  for (const p of programs || []) {
    if (!p.day_of_week_mask?.length || !p.session_time) continue
    const range = reservationWindow(p, fromISO, toISO, windDownUntil)
    if (!range) continue
    const { from, to } = range
    const dates = datesForRule(p.day_of_week_mask, from, to)
    if (!dates.length) continue
    const startTime = p.session_time.slice(0, 5)
    const endTime = addMinutesToTime(startTime, Number(p.session_duration_minutes) || 60)
    // Academy sessions are shown at the location level only — informational, never
    // tied to a specific listing/court (so they don't block bookable slots).
    for (const d of dates) {
      if (exceptions.has(`${p.id}|${d}`)) continue
      sessions.push({
        id: `pgm-${p.id}-${d}`,
        program_id: p.id,
        listing_id: null,
        location_id: p.location_id || null,
        booking_date: d,
        start_time: startTime,
        end_time: endTime,
        origin: 'class_program',
        booking_status: 'confirmed',
        payment_status: 'skipped',
        payment_method: 'payment_skipped',
        notes: p.name,
        total: 0,
        players: 0,
        _derived: true,
      })
    }
  }
  return sessions
}

/**
 * The date of the Nth scheduled session on/after startISO, given a day-of-week mask.
 * Used for the "1 free session" trial: the 2nd session date is when billing starts.
 * Returns 'YYYY-MM-DD' or null if the program has no recurrence / none found in a year.
 */
export function nthSessionDate(startISO, dayMask, n) {
  const set = new Set(dayMask || [])
  if (!set.size || !startISO || n < 1) return null
  const cur = new Date(startISO + 'T00:00:00')
  let count = 0
  for (let i = 0; i < 400; i++) {
    if (set.has(cur.getDay())) {
      if (++count === n) {
        return `${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, '0')}-${String(cur.getDate()).padStart(2, '0')}`
      }
    }
    cur.setDate(cur.getDate() + 1)
  }
  return null
}

/** Human label for a program's recurrence, e.g. "Mon & Wed · 18:00–19:00 · ongoing". */
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
export function reservationSummary(p) {
  if (!p?.day_of_week_mask?.length || !p?.session_time) return ''
  const days = [...p.day_of_week_mask].sort((a, b) => a - b).map(d => DOW[d]).join(' & ')
  const start = p.session_time.slice(0, 5)
  const end = addMinutesToTime(start, Number(p.session_duration_minutes) || 60)
  const horizon = (p.program_type === 'fixed_duration' && p.end_date)
    ? `until ${new Date(p.end_date + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`
    : 'ongoing'
  return `${days} · ${fmtRange12(start, end, '–')} · ${horizon}`
}
