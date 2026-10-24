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
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#202a36] px-5 py-4 sm:px-6">
      <div>
        <h2 class="text-sm font-semibold text-white">Market depth</h2>
        <p class="mt-1 text-[11px] text-[#738092]">Live limit orders · best price first</p>
      </div>
      <div class="flex items-center gap-1 rounded-lg border border-[#273241] bg-[#0b1017] p-1" aria-label="Select orderbook market">
        <button
          v-for="option in (['BTC', 'ETH'] as const)"
          :key="option"
          type="button"
          class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
          :class="symbol === option ? 'bg-[#25352f] text-[#74e7b5]' : 'text-[#8995a5] hover:text-white'"
          :aria-pressed="symbol === option"
          @click="emit('selectSymbol', option)"
        >{{ option }} / USD</button>
      </div>
    </div>
    <div class="grid grid-cols-2 divide-x divide-[#202a36]">
      <div v-for="side in (['buy', 'sell'] as const)" :key="side" class="min-w-0 p-3 sm:p-5">
        <div class="mb-3 flex justify-between gap-2 text-[10px] font-bold uppercase tracking-[.15em] text-[#778495]">
          <span>{{ side === 'buy' ? 'Buy orders' : 'Sell orders' }}</span>
          <span>Amount · {{ symbol }}</span>
        </div>
        <div class="mb-2 grid grid-cols-2 text-[10px] text-[#586677]"><span>Price / USD</span><span class="text-right">Amount</span></div>
        <div v-if="!orderbook[side].length" class="grid min-h-[132px] place-items-center text-center text-xs text-[#667384]">
          No open {{ side }} orders
        </div>
        <div v-else class="thin-scrollbar max-h-[230px] space-y-1 overflow-y-auto">
          <div v-for="order in orderbook[side]" :key="order.id" class="relative grid grid-cols-2 overflow-hidden rounded-md px-2 py-2 text-xs">
            <div
              class="absolute inset-y-0 right-0"
              :class="side === 'buy' ? 'bg-emerald-300/[.055]' : 'bg-rose-300/[.055]'"
              :style="{ width: barWidth(order.amount, maxAmounts[side]) }"
            ></div>
            <span class="mono relative" :class="side === 'buy' ? 'text-[#66dfa8]' : 'text-[#ff8790]'">{{ formatDecimal(order.price) }}</span>
            <span class="mono relative text-right text-[#aeb8c5]">{{ formatDecimal(order.amount) }}</span>
          </div>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-between border-t border-[#202a36] px-5 py-3 text-[10px] text-[#657284] sm:px-6">
      <span>Orders refresh after every match</span>
      <span>{{ orderbook.buy.length + orderbook.sell.length }} open {{ symbol }} orders</span>
    </div>
  </article>
</template>
