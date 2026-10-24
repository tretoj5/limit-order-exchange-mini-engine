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
    <div class="flex items-center justify-between border-b border-[#202a36] px-5 py-4">
      <div>
        <h2 class="text-sm font-semibold text-white">Recent matches</h2>
        <p class="mt-1 text-[11px] text-[#738092]">Live fills for your account</p>
      </div>
      <span class="flex items-center gap-1.5 text-[10px]" :class="realtimeBadge === 'LIVE' ? 'text-[#54dfa3]' : 'text-[#8995a5]'">
        <i class="h-1.5 w-1.5 rounded-full bg-current"></i>{{ realtimeBadge }}
      </span>
    </div>
    <div v-if="!trades.length" class="grid min-h-[116px] place-items-center px-5 text-center text-xs leading-5 text-[#657284]">
      Your completed trades will appear here as they happen.
    </div>
    <div v-else class="divide-y divide-[#202a36]">
      <div v-for="trade in trades" :key="trade.id" class="flex items-center justify-between px-5 py-3.5">
        <div class="flex items-center gap-3">
          <span class="grid h-8 w-8 place-items-center rounded-lg text-xs font-bold" :class="trade.side === 'buy' ? 'bg-emerald-300/10 text-[#68dfa9]' : 'bg-rose-300/10 text-[#ff858e]'">{{ trade.side === 'buy' ? '↗' : '↘' }}</span>
          <div>
            <p class="text-xs font-semibold text-white">{{ trade.side === 'buy' ? 'Bought' : 'Sold' }} {{ trade.symbol }}</p>
            <p class="mt-1 text-[10px] text-[#738092]">{{ formatOrderDate(trade.created_at) }}</p>
          </div>
        </div>
        <div class="text-right">
          <p class="mono text-xs text-white">${{ formatDecimal(trade.usd_volume) }}</p>
          <p class="mono mt-1 text-[10px] text-[#738092]">{{ formatDecimal(trade.amount) }} {{ trade.symbol }}</p>
        </div>
      </div>
    </div>
  </article>
</template>
