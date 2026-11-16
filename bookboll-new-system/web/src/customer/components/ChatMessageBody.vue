<script setup>
import { computed } from 'vue'
import { mentionSegments } from '../../lib/chatMentions.js'
const props = defineProps({ message: { type: Object, required: true }, viewerId: String })
const parts = computed(() => mentionSegments(props.message.body, props.message.mentions || []))
</script>
<template><span class="chat-message-text"><template v-for="(part, i) in parts" :key="i"><mark v-if="part.userId" :class="{ 'chat-mention-me': part.userId === viewerId }">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span></template>
<style scoped>
.chat-message-text{white-space:pre-wrap;overflow-wrap:anywhere}.chat-message-text mark{color:inherit;background:#3b82f633;border-radius:4px;font-weight:700;padding:1px 3px}.chat-message-text .chat-mention-me{background:#14b8a655;box-shadow:inset 0 -1px #14b8a6}
</style>
