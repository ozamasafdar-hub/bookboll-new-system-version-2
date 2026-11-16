import test from 'node:test'
import assert from 'node:assert/strict'
import { mentionQuery, moveMentions, mentionSegments } from '../src/lib/chatMentions.js'
test('mention search follows the caret, supports spaces and ignores email addresses', () => {
  assert.deepEqual(mentionQuery('Hi @Alex S'), {start:3,end:10,query:'Alex S'})
  assert.equal(mentionQuery('alex@example.com'),null)
  assert.deepEqual(mentionQuery('🏸 @Al'), {start:3,end:6,query:'Al'})
})
test('editing before a tag moves it; editing its name removes it', () => {
  const tags=[{user_id:'a',start:3,length:5,label:'@Alex'}]
  assert.equal(moveMentions('Hi @Alex','Hello @Alex',tags)[0].start,6)
  assert.deepEqual(moveMentions('Hi @Alex','Hi @Alec',tags),[])
  assert.equal(moveMentions('Hi @Alex','Hi @Alex!',tags)[0].start,3)
})
test('renders validated mentions as text segments, including emoji offsets', () => {
  assert.deepEqual(mentionSegments('🏸 @Alex <script>',[{user_id:'a',start:3,length:5,label:'@Alex'}]),[{text:'🏸 '},{text:'@Alex',userId:'a'},{text:' <script>'}])
  assert.deepEqual(mentionSegments('Wrong text',[{user_id:'a',start:0,length:5,label:'@Alex'}]),[{text:'Wrong text'}])
})
