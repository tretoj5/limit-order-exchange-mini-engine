<script setup lang="ts">
import { formatDecimal } from '../lib/decimal'
import { formatOrderDate } from '../lib/format'
import type { Trade } from '../types'

defineProps<{
  trades: Trade[]
  realtimeBadge: string
}>()
</script>

<template>
  <article class="panel overflow-hidden">
    <div class="flex items-center justify-between border-b border-[#e5e7eb]/20 px-5 py-5 sm:px-8">
      <div>
        <h2 class="text-sm font-medium text-[#f1f5f9]">Recent matches</h2>
        <p class="mt-1 text-[11px] text-[#64748b]">Live fills for your account</p>
      </div>
      <span class="flex items-center gap-1.5 text-xs text-[#e5e7eb]">
        <i class="h-1.5 w-1.5 rounded-full" :class="realtimeBadge === 'LIVE' ? 'bg-[#34d399]' : 'bg-[#64748b]'"></i>{{ realtimeBadge }}
      </span>
    </div>
    <div v-if="!trades.length" class="grid min-h-[116px] place-items-center px-5 text-center text-xs leading-5 text-[#64748b]">
      Your completed trades will appear here as they happen.
    </div>
    <div v-else class="divide-y divide-[#e5e7eb]/20">
      <div v-for="trade in trades" :key="trade.id" class="flex items-center justify-between px-5 py-4 sm:px-8">
        <div class="flex items-center gap-3">
          <span class="grid h-8 w-8 place-items-center rounded-full border border-[#353845] text-xs font-medium text-[#cbd5e1]">{{ trade.side === 'buy' ? '↗' : '↘' }}</span>
          <div>
            <p class="text-xs font-medium text-[#f1f5f9]">{{ trade.side === 'buy' ? 'Bought' : 'Sold' }} {{ trade.symbol }}</p>
            <p class="mt-1 text-[10px] text-[#64748b]">{{ formatOrderDate(trade.created_at) }}</p>
          </div>
        </div>
        <div class="text-right">
          <p class="mono text-xs text-[#e5e7eb]">${{ formatDecimal(trade.usd_volume) }}</p>
          <p class="mono mt-1 text-[10px] text-[#64748b]">{{ formatDecimal(trade.amount) }} {{ trade.symbol }}</p>
        </div>
      </div>
    </div>
  </article>
</template>
