import { toMinutes } from './slots.js'

export function bookingMinutes(booking) {
  const start = toMinutes(booking.start_time)
  let end = toMinutes(booking.end_time)
  if (end <= start) end += 1440
  return { start, end }
}

// Open on daytime activity when present. Early bookings remain in the full grid
// and get an explicit jump control, instead of forcing every venue to midnight.
// Callers pass only the currently shown venues.
export function calendarFocusMinute(bookings, openingMinutes = []) {
  const starts = bookings.filter(b => b.start_time && b.end_time).map(b => bookingMinutes(b).start)
  const daytime = starts.filter(m => m >= 6 * 60)
  if (starts.length) return Math.max(0, Math.min(...(daytime.length ? daytime : starts)) - 30)
  const daytimeOpenings = openingMinutes.filter(m => Number.isFinite(m) && m > 0)
  return daytimeOpenings.length ? Math.min(...daytimeOpenings) : 7 * 60
}

// Every booking keeps a readable lane, even on a busy venue's schedule.
export function layoutCalendarColumn(bookings, pixelsPerMinute, dayStart = 0) {
  const items = bookings.filter(b => b.start_time && b.end_time).map(bk => {
    const { start, end } = bookingMinutes(bk)
    return { bk, start, end, lane: 0 }
  }).sort((a, b) => a.start - b.start || a.end - b.end || String(a.bk.id).localeCompare(String(b.bk.id)))
  const events = []
  let maxLanes = 1
  for (let i = 0; i < items.length;) {
    const cluster = [items[i]]
    let end = items[i].end, j = i + 1
    while (j < items.length && items[j].start < end) {
      cluster.push(items[j]); end = Math.max(end, items[j].end); j++
    }
    const laneEnds = []
    for (const item of cluster) {
      let lane = laneEnds.findIndex(end => end <= item.start)
      if (lane < 0) lane = laneEnds.length
      item.lane = lane; laneEnds[lane] = item.end
    }
    const lanes = laneEnds.length
    maxLanes = Math.max(maxLanes, lanes)
    for (const item of cluster) events.push({
      bk: item.bk, top: (item.start - dayStart) * pixelsPerMinute,
      height: (item.end - item.start) * pixelsPerMinute,
      leftPct: item.lane / lanes * 100, widthPct: 100 / lanes,
      overnight: item.end > 1440,
    })
    i = j
  }
  return { events, width: Math.max(240, maxLanes * 184), lanes: maxLanes }
}
