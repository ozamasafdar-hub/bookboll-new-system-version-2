// JavaScript string offsets are UTF-16, also used by the API's mention validator.
export function mentionQuery(text, caret = text.length) {
  const prefix = text.slice(0, caret)
  const match = /(?:^|\s)@([^@\n]{0,80})$/.exec(prefix)
  return match ? { start: caret - match[1].length - 1, end: caret, query: match[1] } : null
}

export function moveMentions(before, after, tags) {
  let start = 0, tail = 0
  while (start < before.length && start < after.length && before[start] === after[start]) start++
  while (tail < before.length - start && tail < after.length - start && before[before.length - 1 - tail] === after[after.length - 1 - tail]) tail++
  const end = before.length - tail, shift = after.length - before.length
  return tags.flatMap(tag => {
    if (tag.start + tag.length <= start) return [tag]
    if (tag.start >= end) return [{ ...tag, start: tag.start + shift }]
    return [] // Editing any part of a tag turns it back into ordinary text.
  }).filter(tag => after.slice(tag.start, tag.start + tag.length) === tag.label)
}

export function mentionSegments(body = '', tags = []) {
  const result = []; let cursor = 0
  for (const tag of [...tags].sort((a, b) => a.start - b.start)) {
    if (tag.start < cursor || body.slice(tag.start, tag.start + tag.length) !== tag.label) continue
    if (tag.start > cursor) result.push({ text: body.slice(cursor, tag.start) })
    result.push({ text: tag.label, userId: tag.user_id })
    cursor = tag.start + tag.length
  }
  if (cursor < body.length) result.push({ text: body.slice(cursor) })
  return result
}
