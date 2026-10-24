<script setup lang="ts">
import { computed } from 'vue'
import { formatDecimal } from '../lib/decimal'
import type { Orderbook, SymbolCode } from '../types'

const props = defineProps<{
  symbol: SymbolCode
  orderbook: Orderbook
}>()

const maxAmounts = computed(() => ({
  buy: largestAmount(props.orderbook.buy.map((order) => order.amount)),
  sell: largestAmount(props.orderbook.sell.map((order) => order.amount)),
}))

const emit = defineEmits<{
  selectSymbol: [symbol: SymbolCode]
}>()

function barWidth(amount: string, largest: bigint): string {
  const value = toScaledInteger(amount)
  if (largest === 0n) return '0%'

  const percent = (value * 100n) / largest
  return `${percent < 6n ? 6n : percent}%`
}

function largestAmount(amounts: string[]): bigint {
  return amounts.reduce((maximum, amount) => {
    const scaled = toScaledInteger(amount)
    return scaled > maximum ? scaled : maximum
  }, 0n)
}

function toScaledInteger(value: string): bigint {
  const [whole, fraction = ''] = value.split('.')
  return BigInt(whole) * 100_000_000n + BigInt(fraction.padEnd(8, '0'))
}
</script>

<template>
  <article class="panel min-w-0 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#e5e7eb]/20 px-5 py-5 sm:px-8">
      <div>
        <h2 class="text-sm font-medium text-[#f1f5f9]">Market depth</h2>
        <p class="mt-1 text-[11px] text-[#64748b]">Live limit orders · best price first</p>
      </div>
      <div class="flex items-center gap-1 rounded-full border border-[#353845] p-1" aria-label="Select orderbook market">
        <button
          v-for="option in (['BTC', 'ETH'] as const)"
          :key="option"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
          :class="symbol === option ? 'border-[#e5e7eb] text-[#f1f5f9]' : 'border-transparent text-[#64748b] hover:text-[#e5e7eb]'"
          :aria-pressed="symbol === option"
          @click="emit('selectSymbol', option)"
        >{{ option }} / USD</button>
      </div>
    </div>
    <div class="grid grid-cols-2 divide-x divide-[#e5e7eb]/20">
      <div v-for="side in (['buy', 'sell'] as const)" :key="side" class="min-w-0 p-3 sm:p-5">
        <div class="mb-3 flex justify-between gap-2 text-[10px] font-medium uppercase tracking-[.2em] text-[#64748b]">
          <span>{{ side === 'buy' ? 'Buy orders' : 'Sell orders' }}</span>
          <span>Amount · {{ symbol }}</span>
        </div>
        <div class="mb-2 grid grid-cols-2 text-[10px] text-[#64748b]"><span>Price / USD</span><span class="text-right">Amount</span></div>
        <div v-if="!orderbook[side].length" class="grid min-h-[132px] place-items-center text-center text-xs text-[#64748b]">
          No open {{ side }} orders
        </div>
        <div v-else class="thin-scrollbar max-h-[230px] space-y-1 overflow-y-auto">
          <div v-for="order in orderbook[side]" :key="order.id" class="relative grid grid-cols-2 overflow-hidden px-2 py-2 text-xs">
            <div
              class="absolute inset-y-0 right-0 bg-[#e5e7eb]/[.07]"
              :style="{ width: barWidth(order.amount, maxAmounts[side]) }"
            ></div>
            <span class="mono relative text-[#e5e7eb]">{{ formatDecimal(order.price) }}</span>
            <span class="mono relative text-right text-[#cbd5e1]">{{ formatDecimal(order.amount) }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-between border-t border-[#e5e7eb]/20 px-5 py-3 text-[10px] text-[#64748b] sm:px-8">
      <span>Orders refresh after every match</span>
      <span>{{ orderbook.buy.length + orderbook.sell.length }} open {{ symbol }} orders</span>
    </div>
  </article>
</template>
