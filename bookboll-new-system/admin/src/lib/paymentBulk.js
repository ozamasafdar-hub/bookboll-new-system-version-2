// Selection is an exact snapshot, either checked rows or every server-filtered match.
export const collectionMethods = [
  { value:'cash', label:'Cash' }, { value:'card', label:'Card terminal' },
  { value:'cheque', label:'Cheque' }, { value:'wire_transfer', label:'Bank transfer' },
]
export const collectionMethodLabel = method => collectionMethods.find(m => m.value === (method === 'credit_card' ? 'card' : method))?.label || method
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
  return next
}

// Keep a booking's sibling evidence together so an earlier batch cannot stale a
// later batch's preview. Also keep every allocation of an original receipt together.
export function paymentBatches(rows, limit = 500) {
  const groups = new Map()
  for (const row of rows) {
    const key = row.booking_id ? `b${row.booking_id}` : row.subscription_id ? `s${row.subscription_id}` : `p${String(row.id).split(':')[0]}`
    if (!groups.has(key)) groups.set(key,new Set())
    groups.get(key).add(String(row.id))
  }
  const batches=[]; let current=[]
  for (const ids of groups.values()) {
    if (ids.size > limit) throw new Error(`One booking has more than ${limit} selected allocations. Narrow the selection for that booking.`)
    if (current.length + ids.size > limit) { batches.push(current); current=[] }
    current.push(...ids)
  }
  if (current.length) batches.push(current)
  return batches
}

export function combineBulkPreviews(plans) {
  const changes=plans.flatMap(p => p.data.changes), excluded=plans.flatMap(p => p.data.excluded), totals=new Map()
  for (const plan of plans) for (const impact of plan.data.impact) {
    const digits=new Intl.NumberFormat('en',{style:'currency',currency:impact.currency}).resolvedOptions().maximumFractionDigits
    const scale=10 ** digits
    if (!totals.has(impact.currency)) totals.set(impact.currency,{currency:impact.currency,scale})
    const total=totals.get(impact.currency)
    for (const [key,value] of Object.entries(impact)) if (key !== 'currency') total[key]=(total[key] || 0)+Math.round(Number(value)*scale)
  }
  return { ...plans[0]?.data, changes,excluded,allocation_count:changes.length,
    impact:[...totals.values()].map(({currency,scale,...amounts}) => ({currency,...Object.fromEntries(Object.entries(amounts).map(([key,value]) => [key,value/scale]))})) }
}
