import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(new URL('../src/lib/holdSession.js', import.meta.url), 'utf8').replace('export function', 'function')
test('reservation identity persists across reloads', () => {
  const values = new Map()
  const localStorage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }
  const first = vm.createContext({ localStorage, crypto: { randomUUID: () => 'a'.repeat(36) } })
  vm.runInContext(source, first)
  assert.equal(vm.runInContext('holdToken()', first), 'a'.repeat(36))
  const reload = vm.createContext({ localStorage, crypto: { randomUUID: () => 'b'.repeat(36) } })
  vm.runInContext(source, reload)
  assert.equal(vm.runInContext('holdToken()', reload), 'a'.repeat(36))
})
test('blocked storage retains a unique in-memory identity instead of sharing anon-device', () => {
  let calls = 0
  const context = vm.createContext({ localStorage: { getItem() { throw Error('blocked') } }, crypto: { randomUUID: () => `random-browser-${++calls}` } })
  vm.runInContext(source, context)
  assert.equal(vm.runInContext('holdToken()', context), 'random-browser-1')
  assert.equal(vm.runInContext('holdToken()', context), 'random-browser-1')
})
