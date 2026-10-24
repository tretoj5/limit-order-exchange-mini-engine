<script setup lang="ts">
import { useToastStore } from '../stores/toast'

const toast = useToastStore()
</script>

<template>
  <div class="pointer-events-none fixed right-5 top-5 z-[100] flex w-[min(24rem,calc(100vw-2.5rem))] flex-col gap-3">
    <div
      v-for="message in toast.messages"
      :key="message.id"
      class="pointer-events-auto flex items-start justify-between gap-4 rounded-lg border bg-[#0a0e1a] px-4 py-3 text-sm text-[#e5e7eb] shadow-xl"
      :class="message.kind === 'error' ? 'border-[#fa3812]/60' : 'border-[#34d399]/40'"
      :role="message.kind === 'error' ? 'alert' : 'status'"
    >
      <span>{{ message.message }}</span>
      <button
        type="button"
        class="shrink-0 text-[#64748b] transition hover:text-[#f1f5f9]"
        aria-label="Dismiss notification"
        @click="toast.dismiss(message.id)"
      >×</button>
    </div>
  </div>
</template>
