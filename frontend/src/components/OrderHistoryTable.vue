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
    1: 'border-[#353845] text-[#e5e7eb]',
    2: 'border-[#cbd5e1] text-[#cbd5e1]',
    3: 'border-[#475569] text-[#64748b]',
  })[status]
}
</script>

<template>
  <article class="panel min-w-0 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e5e7eb]/20 px-5 py-5 sm:px-8">
      <div>
        <h2 class="text-sm font-medium text-[#f1f5f9]">Your orders</h2>
        <p class="mt-1 text-[11px] text-[#64748b]">Open, filled, and cancelled activity</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <label class="sr-only" for="order-symbol-filter">Filter orders by symbol</label>
        <select id="order-symbol-filter" v-model="symbolFilter" class="select-control text-xs">
          <option value="all">All markets</option>
          <option value="BTC">BTC / USD</option>
          <option value="ETH">ETH / USD</option>
        </select>
        <label class="sr-only" for="order-side-filter">Filter orders by side</label>
        <select id="order-side-filter" v-model="sideFilter" class="select-control text-xs">
          <option value="all">All sides</option>
          <option value="buy">Buy</option>
          <option value="sell">Sell</option>
        </select>
        <label class="sr-only" for="order-status-filter">Filter orders by status</label>
        <select id="order-status-filter" v-model="statusFilter" class="select-control text-xs">
          <option value="all">All statuses</option>
          <option value="open">Open</option>
          <option value="filled">Filled</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>
    <div class="thin-scrollbar overflow-x-auto">
      <table class="w-full min-w-[660px] border-collapse text-left">
        <thead class="text-[10px] uppercase tracking-[.2em] text-[#64748b]">
          <tr>
            <th scope="col" class="px-5 py-3 font-semibold sm:px-6">Market</th>
            <th scope="col" class="px-3 py-3 font-semibold">Side</th>
            <th scope="col" class="px-3 py-3 text-right font-semibold">Limit price</th>
            <th scope="col" class="px-3 py-3 text-right font-semibold">Amount</th>
            <th scope="col" class="px-3 py-3 font-semibold">Status</th>
            <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-[#e5e7eb]/20 text-xs">
          <tr v-for="order in filteredOrders" :key="order.id" class="text-[#e5e7eb]">
            <td class="px-5 py-3.5 font-medium text-[#f1f5f9] sm:px-8">{{ order.symbol }} / USD</td>
            <td class="px-3 py-3.5 text-[#cbd5e1]">{{ order.side }}</td>
            <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.price) }}</td>
            <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.amount) }}</td>
            <td class="px-3 py-3.5"><span class="rounded-full border px-2 py-1 text-[10px] font-medium" :class="statusClass(order.status)">{{ statusLabel(order.status) }}</span></td>
            <td class="px-5 py-3.5 text-right sm:px-8">
              <button
                v-if="order.status === 1"
                type="button"
                :disabled="cancellingId === order.id"
                class="ghost-action px-3 py-1.5 text-[11px] transition hover:border-[#e5e7eb] disabled:opacity-50"
                @click="emit('cancel', order.id)"
              >{{ cancellingId === order.id ? 'Cancelling…' : 'Cancel' }}</button>
              <span v-else class="text-[11px] text-[#64748b]">{{ formatOrderDate(order.created_at) }}</span>
            </td>
          </tr>
          <tr v-if="!filteredOrders.length"><td colspan="6" class="px-5 py-10 text-center text-xs text-[#64748b]">No orders match this filter.</td></tr>
        </tbody>
      </table>
    </div>
  </article>
</template>
