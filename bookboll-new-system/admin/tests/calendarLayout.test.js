import test from 'node:test'
import assert from 'node:assert/strict'
import { bookingMinutes, layoutCalendarColumn, calendarFocusMinute } from '../src/lib/calendarLayout.js'
test('ten simultaneous bookings remain ten readable cards', () => {
  const result = layoutCalendarColumn(Array.from({length:10}, (_, id) => ({id, start_time:'15:00', end_time:'17:00'})), 1)
  assert.equal(result.events.length, 10)
  assert.equal(result.width, 1840)
  assert.equal(new Set(result.events.map(x => x.leftPct)).size, 10)
  assert.ok(result.events.every(x => x.widthPct * result.width / 100 >= 184))
})
test('adjacent bookings reuse a lane while overlaps stay separate', () => {
  const r = layoutCalendarColumn([{id:1,start_time:'13:00',end_time:'15:00'}, {id:2,start_time:'14:00',end_time:'16:00'}, {id:3,start_time:'16:00',end_time:'17:00'}], 1)
  assert.equal(r.lanes, 2)
  assert.equal(r.events[2].widthPct, 100)
})
test('11 pm to 1 am is two hours ending on the next day', () => {
  const b = {id:1,start_time:'23:00',end_time:'01:00'}
  assert.deepEqual(bookingMinutes(b), {start:1380,end:1500})
  const r = layoutCalendarColumn([b], 2)
  assert.equal(r.events[0].height, 240)
  assert.equal(r.events[0].overnight, true)
  assert.equal(bookingMinutes({start_time:'22:00',end_time:'00:00'}).end, 1440)
})
test('first booking takes priority over a midnight venue opening', () => {
  assert.equal(calendarFocusMinute([{start_time:'07:00',end_time:'08:00'}, {start_time:'15:00',end_time:'17:00'}], [0, 360]), 390)
})
test('real midnight bookings remain visible and empty days have a useful fallback', () => {
  assert.equal(calendarFocusMinute([{start_time:'00:00',end_time:'01:00'}], [420]), 0)
  assert.equal(calendarFocusMinute([], [0]), 420)
  assert.equal(calendarFocusMinute([], [0, 540, 960]), 540)
  assert.equal(calendarFocusMinute([], []), 420)
})
