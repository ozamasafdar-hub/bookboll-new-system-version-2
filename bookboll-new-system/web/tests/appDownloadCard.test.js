import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileStyle } from '@vue/compiler-sfc'

test('contrast palettes apply to their cards without changing the page theme variables', () => {
  for (const name of ['AppDownloadCard']) {
    const {descriptor}=parse(readFileSync(new URL(`../src/components/${name}.vue`,import.meta.url),'utf8'))
    const css=compileStyle({source:descriptor.styles[0].content,id:'data-v-theme-check',scoped:true}).code
    const rules=css.match(/html\.[^{}]+\{[^}]+\}/g)
    assert.ok(rules?.length)
    for (const rule of rules) assert.match(rule.split('{')[0],/\[data-v-theme-check\]/)
  }
})
