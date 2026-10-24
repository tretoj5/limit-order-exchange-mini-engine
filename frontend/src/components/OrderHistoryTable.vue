<script setup lang="ts">
import { computed, ref } from 'vue'
import { formatDecimal } from '../lib/decimal'
import { formatOrderDate } from '../lib/format'
import type { Order, OrderSide, OrderStatus, SymbolCode } from '../types'

const props = defineProps<{
  orders: Order[]
  cancellingId: number | null
}>()

const emit = defineEmits<{
  cancel: [orderId: number]
}>()

const statusFilter = ref<'all' | 'open' | 'filled' | 'cancelled'>('all')
const symbolFilter = ref<'all' | SymbolCode>('all')
const sideFilter = ref<'all' | OrderSide>('all')
const statusByFilter: Record<Exclude<typeof statusFilter.value, 'all'>, OrderStatus> = {
  open: 1,
  filled: 2,
  cancelled: 3,
}

const filteredOrders = computed(() => props.orders.filter((order) => {
  const matchesSymbol = symbolFilter.value === 'all' || order.symbol === symbolFilter.value
  const matchesSide = sideFilter.value === 'all' || order.side === sideFilter.value
  const matchesStatus = statusFilter.value === 'all' || order.status === statusByFilter[statusFilter.value]
  return matchesSymbol && matchesSide && matchesStatus
}))

function statusLabel(status: OrderStatus): string {
  return ({ 1: 'Open', 2: 'Filled', 3: 'Cancelled' })[status]
}

function statusClass(status: OrderStatus): string {
  return ({
    1: 'border-amber-300/20 bg-amber-300/10 text-amber-200',
    2: 'border-emerald-300/20 bg-emerald-300/10 text-emerald-200',
    3: 'border-[#414c5b] bg-[#202833] text-[#a6b0bd]',
  })[status]
}
</script>

<template>
  <article class="panel min-w-0 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#202a36] px-5 py-4 sm:px-6">
      <div>
        <h2 class="text-sm font-semibold text-white">Your orders</h2>
        <p class="mt-1 text-[11px] text-[#738092]">Open, filled, and cancelled activity</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <label class="sr-only" for="order-symbol-filter">Filter orders by symbol</label>
        <select id="order-symbol-filter" v-model="symbolFilter" class="rounded-lg border border-[#273241] bg-[#0b1017] px-3 py-2 text-xs text-[#aab4c2]">
          <option value="all">All markets</option>
          <option value="BTC">BTC / USD</option>
          <option value="ETH">ETH / USD</option>
        </select>
        <label class="sr-only" for="order-side-filter">Filter orders by side</label>
        <select id="order-side-filter" v-model="sideFilter" class="rounded-lg border border-[#273241] bg-[#0b1017] px-3 py-2 text-xs text-[#aab4c2]">
          <option value="all">All sides</option>
          <option value="buy">Buy</option>
          <option value="sell">Sell</option>
        </select>
        <label class="sr-only" for="order-status-filter">Filter orders by status</label>
        <select id="order-status-filter" v-model="statusFilter" class="rounded-lg border border-[#273241] bg-[#0b1017] px-3 py-2 text-xs text-[#aab4c2]">
          <option value="all">All statuses</option>
          <option value="open">Open</option>
          <option value="filled">Filled</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>
    <div class="thin-scrollbar overflow-x-auto">
      <table class="w-full min-w-[660px] border-collapse text-left">
        <thead class="bg-[#0d1219] text-[10px] uppercase tracking-[.13em] text-[#687687]">
          <tr>
            <th scope="col" class="px-5 py-3 font-semibold sm:px-6">Market</th>
            <th scope="col" class="px-3 py-3 font-semibold">Side</th>
            <th scope="col" class="px-3 py-3 text-right font-semibold">Limit price</th>
            <th scope="col" class="px-3 py-3 text-right font-semibold">Amount</th>
            <th scope="col" class="px-3 py-3 font-semibold">Status</th>
            <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-[#1c2631] text-xs">
          <tr v-for="order in filteredOrders" :key="order.id" class="text-[#c3ccd7]">
            <td class="px-5 py-3.5 font-semibold text-white sm:px-6">{{ order.symbol }} / USD</td>
            <td class="px-3 py-3.5" :class="order.side === 'buy' ? 'text-[#63dba5]' : 'text-[#ff858e]'">{{ order.side }}</td>
            <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.price) }}</td>
            <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.amount) }}</td>
            <td class="px-3 py-3.5"><span class="rounded-full border px-2 py-1 text-[10px]" :class="statusClass(order.status)">{{ statusLabel(order.status) }}</span></td>
            <td class="px-5 py-3.5 text-right sm:px-6">
              <button
                v-if="order.status === 1"
                type="button"
                :disabled="cancellingId === order.id"
                class="text-[11px] font-semibold text-[#9ca9b8] transition hover:text-white disabled:opacity-50"
                @click="emit('cancel', order.id)"
              >{{ cancellingId === order.id ? 'Cancelling…' : 'Cancel' }}</button>
              <span v-else class="text-[11px] text-[#536071]">{{ formatOrderDate(order.created_at) }}</span>
            </td>
          </tr>
          <tr v-if="!filteredOrders.length"><td colspan="6" class="px-5 py-10 text-center text-xs text-[#657284]">No orders match this filter.</td></tr>
        </tbody>
      </table>
    </div>
  </article>
</template>
