// Selection always carries the exact visible allocation IDs, never a filter predicate.
export function entryAllocations(entry) {
  return entry.bookings ? entry.bookings.flatMap(booking => booking.allocations) : [entry]
}
export function selectionState(entry, selected) {
  const ids = entryAllocations(entry).map(row => String(row.id))
  const count = ids.filter(id => selected.has(id)).length
  return { checked:ids.length > 0 && count === ids.length, partial:count > 0 && count < ids.length }
}
export function updateSelection(selected, entries, checked) {
  const next = new Map(selected)
  for (const entry of entries) for (const row of entryAllocations(entry)) {
    if (checked) next.set(String(row.id),row)
    else next.delete(String(row.id))
  }
  if (next.size > 500) throw new Error('Select up to 500 allocations at a time. Narrow the filters for a smaller batch.')
  return next
}
