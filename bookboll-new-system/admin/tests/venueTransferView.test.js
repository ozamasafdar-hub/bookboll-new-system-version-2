/**
 * The venue transfer dialog's explanatory half.
 *
 * The figures come from the server and are tested with the flow. What is
 * tested here is what the operator is TOLD before they commit: the two periods
 * cannot overlap, the dialog never claims money will move, and a mode that
 * asserts nothing about the past does not display invented zeros as if it had.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as finance from '../src/lib/finance.js'

const SOURCE = readFileSync(new URL('../src/components/VenueTransfer.vue', import.meta.url), 'utf8')

function setup(t, props = {}) {
  let code = compileScript(parse(SOURCE).descriptor, { id: 'vt' }).content
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      code = code.slice(0, node.start) + node.specifiers.map(s =>
        `const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`
      ).join('\n') + code.slice(node.end)
    } else if (node.type === 'ExportDefaultDeclaration') {
      code = code.slice(0, node.start) + 'return ' + code.slice(node.declaration.start)
    }
  }
  const noop = () => {}
  const imports = new Proxy({
    vue: { ...vue, onMounted: noop },
    '../lib/finance.js': finance,
    '../lib/financeApi.js': { financeRequest: async () => ({ ok: true, data: { can_transfer: true, businesses: [{ id: 20, name: 'Al Hilal sports hub', owner_user_id: '5' }] } }) },
  }, { get: (o, k) => o[k] || new Proxy({}, { get: () => noop }) })

  const scope = vue.effectScope()
  t.after(() => scope.stop())

  return scope.run(() => new Function('imports', code)(imports)
    .setup(vue.reactive({ venueId: 1, venueName: 'Al Sadd', currentOwner: 'Operated by Bookboll', ...props }), { expose: noop, emit: noop }))
}

/** The same formatting the dialog uses, so these assert the DATE, not a locale. */
const shown = iso => new Date(`${iso}T00:00:00Z`)
  .toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })

const legacy = { business_id: 20, effective_from: '2026-10-22', history_mode: 'legacy_deposit_fee', from_date: '2026-10-01', to_date: '2026-10-21' }

// ── the cut-off ────────────────────────────────────────────────────────────

test('the two periods may not touch, and the dialog says so before the server does', async t => {
  const s = setup(t)
  await vue.nextTick()
  Object.assign(s.form.value, legacy)
  assert.equal(s.dateIssue.value, '', 'a legal range must not be flagged')
  assert.equal(s.historyDays.value, 21, 'the range is inclusive of both ends')

  // The exact mistake the screen exists to prevent: history running INTO the
  // day the new business takes over, so one booking belongs to both periods.
  s.form.value.to_date = '2026-10-22'
  assert.ok(s.dateIssue.value.includes(shown('2026-10-22')),
    `the message must name the cut-off; got: ${s.dateIssue.value}`)
  assert.equal(s.historyDays.value, 0, 'an invalid range must not report a span')

  s.form.value.to_date = '2026-09-01'
  assert.match(s.dateIssue.value, /on or after/, 'an inverted range is its own message')
})

test('it stays silent about dates the operator has not reached yet', async t => {
  const s = setup(t)
  await vue.nextTick()
  Object.assign(s.form.value, { ...legacy, from_date: '', to_date: '' })
  assert.equal(s.dateIssue.value, '', 'nagging about an empty field reads as a failure they caused')

  s.form.value.history_mode = 'preserve'
  s.form.value.to_date = '2099-01-01'
  assert.equal(s.dateIssue.value, '', 'preserve mode has no history range to validate')
})

// ── what the operator is promised ──────────────────────────────────────────

test('it never claims money will move, in either mode', async t => {
  const s = setup(t)
  await vue.nextTick()

  for (const mode of ['preserve', 'legacy_deposit_fee']) {
    Object.assign(s.form.value, { ...legacy, history_mode: mode })
    const all = s.effects.value.map(r => r.text).join(' ')

    assert.match(all, /No money is moved/, `${mode}: the costliest misreading of this screen`)
    assert.match(all, /no payout is created/i)
    assert.match(all, /does not settle or end them/)
    assert.ok(s.effects.value.some(r => r.tone === 'no'), `${mode}: the "will not" lines must be marked as such`)
  }
})

test('it names the destination and the period it is asserting about', async t => {
  const s = setup(t)
  await vue.nextTick()
  Object.assign(s.form.value, legacy)
  const all = s.effects.value.map(r => r.text).join(' ')

  assert.match(all, /Al Hilal sports hub becomes the operator of Al Sadd/)
  assert.ok(all.includes(`${shown('2026-10-01')} – ${shown('2026-10-21')}`),
    'the period it asserts about must be spelled out, not left as "the range"')
  assert.match(all, /cash Al Hilal sports hub already collected/)
})

test('preserve mode promises the past is untouched, and claims nothing about it', async t => {
  const s = setup(t)
  await vue.nextTick()
  Object.assign(s.form.value, { ...legacy, history_mode: 'preserve' })

  assert.match(s.effects.value.map(r => r.text).join(' '), /stays exactly as recorded/)

  // The server returns zeros here deliberately. Rendering them as "0 deposits
  // re-labelled" would present an assertion the transfer never made.
  s.preview.value = { eligible_count: 0, pending_arrival_count: 0, excluded_count: 0, landlord_obligation_count: 2, totals: [] }
  assert.deepEqual(s.reviewStats.value.map(r => r.label), ['Recorded landlord agreements'])
  assert.equal(s.reviewStats.value[0].tone, 'warn', 'rent that survives the transfer is not a neutral fact')
})

test('a count the server did not send is shown as unknown, never as zero', async t => {
  const s = setup(t)
  await vue.nextTick()
  Object.assign(s.form.value, legacy)
  s.preview.value = { landlord_obligation_count: 0, totals: [] }

  assert.deepEqual(s.reviewStats.value.map(r => r.value), ['—', '—', '—', '0'])
})

// ── it has to survive dark mode ────────────────────────────────────────────

test('every token the dialog reads is defined by the theme, in both palettes', () => {
  const styles = SOURCE.slice(SOURCE.indexOf('<style'))
  const theme = readFileSync(new URL('../src/dashboardTheme.css', import.meta.url), 'utf8')
  const [light, dark] = theme.split('html.adm-dark')
  const tokens = [...new Set([...styles.matchAll(/var\((--[a-z0-9-]+)/g)].map(m => m[1]))]

  assert.ok(tokens.length > 8, 'the style block should be reading theme tokens, not literals')
  for (const token of tokens) {
    assert.ok(light.includes(`${token}:`), `${token} is used but never defined`)
    assert.ok(dark.includes(`${token}:`), `${token} has no dark value — the panel would stay light`)
  }

  // Backgrounds are stated, not inherited: these panels keep their own colour
  // in both themes, so their text has to be stated against it.
  for (const rule of ['.vt-seg-past', '.vt-seg-future', '.vt-stat-warn']) {
    assert.match(styles, new RegExp(`\\${rule}[^}]*background:`), `${rule} must paint its own background`)
  }
})
