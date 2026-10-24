<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { errorMessage } from '../lib/api'
import { commission15, formatDecimal, isPositiveDecimal, multiplyToFixed8 } from '../lib/decimal'
import { useAuthStore } from '../stores/auth'
import { useExchangeStore } from '../stores/exchange'
import type { Order, OrderSide, OrderStatus, SymbolCode } from '../types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const exchange = useExchangeStore()
const selectedSide = ref<OrderSide>('buy')
const price = ref('')
const amount = ref('')
const submitError = ref('')
const actionError = ref('')
const isSubmitting = ref(false)
const cancellingId = ref<number | null>(null)
const orderFilter = ref<'all' | 'open' | 'filled' | 'cancelled'>('all')
const tradeMode = computed(() => route.path === '/trade')
const volumePreview = computed(() => multiplyToFixed8(price.value, amount.value))
const feePreview = computed(() => commission15(volumePreview.value))
const reservePreview = computed(() => {
  const volume = volumePreview.value
  const fee = commission15(volume)
  const volumeScaled = BigInt(volume.split('.')[0]) * 100_000_000n
    + BigInt((volume.split('.')[1] ?? '').padEnd(8, '0'))
  const feeScaled = BigInt(fee.split('.')[0]) * 100_000_000n
    + BigInt((fee.split('.')[1] ?? '').padEnd(8, '0'))
  const total = (volumeScaled + feeScaled).toString().padStart(9, '0')
  return `${total.slice(0, -8)}.${total.slice(-8)}`
})
const filteredOrders = computed(() => exchange.orders.filter((order) => {
  if (orderFilter.value === 'all') return true
  const status: Record<Exclude<typeof orderFilter.value, 'all'>, OrderStatus> = {
    open: 1,
    filled: 2,
    cancelled: 3,
  }
  return order.status === status[orderFilter.value]
}))
const quoteAsset = computed(() => exchange.symbol === 'BTC' ? 'BTC' : 'ETH')
const currentAsset = computed(() => exchange.profile?.assets.find((asset) => asset.symbol === quoteAsset.value))

function setSide(side: OrderSide): void {
  selectedSide.value = side
  submitError.value = ''
}

async function loadWorkspace(): Promise<void> {
  try {
    await exchange.refreshAll()
  } catch {
    actionError.value = exchange.error
  }

}

async function refreshWorkspace(): Promise<void> {
  actionError.value = ''
  try {
    await exchange.refreshAll()
  } catch (error) {
    actionError.value = errorMessage(error)
  }
}

async function submitOrder(): Promise<void> {
  submitError.value = ''
  actionError.value = ''
  if (!isPositiveDecimal(price.value) || !isPositiveDecimal(amount.value)) {
    submitError.value = 'Enter a positive price and amount with no more than 8 decimal places.'
    return
  }

  isSubmitting.value = true
  try {
    await exchange.placeOrder({
      symbol: exchange.symbol,
      side: selectedSide.value,
      price: price.value,
      amount: amount.value,
    })
    price.value = ''
    amount.value = ''
  } catch (error) {
    submitError.value = errorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}

async function cancelOrder(order: Order): Promise<void> {
  actionError.value = ''
  cancellingId.value = order.id
  try {
    await exchange.cancelOrder(order.id)
  } catch (error) {
    actionError.value = errorMessage(error)
  } finally {
    cancellingId.value = null
  }
}

async function logout(): Promise<void> {
  actionError.value = ''
  exchange.clear()
  try {
    await auth.logout()
  } catch (error) {
    actionError.value = errorMessage(error)
  } finally {
    await router.push('/login')
  }
}

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

function orderDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

function barWidth(amountValue: string, bookOrders: Order[]): string {
  const largest = bookOrders.reduce((maximum, order) => {
    const [whole, fraction = ''] = order.amount.split('.')
    const value = BigInt(whole) * 100_000_000n + BigInt(fraction.padEnd(8, '0'))
    return value > maximum ? value : maximum
  }, 0n)
  const [whole, fraction = ''] = amountValue.split('.')
  const value = BigInt(whole) * 100_000_000n + BigInt(fraction.padEnd(8, '0'))
  if (largest === 0n) return '0%'
  const percent = Number((value * 100n) / largest)
  return `${Math.max(6, percent)}%`
}

watch(() => exchange.symbol, async (symbol) => {
  try {
    await exchange.selectSymbol(symbol)
  } catch (error) {
    actionError.value = errorMessage(error)
  }
})

onMounted(() => {
  void loadWorkspace()
})

</script>

<template>
  <div class="min-h-screen bg-[#080b10] text-[#e5e7eb]">
    <aside class="fixed inset-y-0 left-0 z-20 hidden w-[250px] flex-col border-r border-[#1d2732] bg-[#0b1017] px-5 py-6 xl:flex">
      <RouterLink to="/" class="flex items-center gap-3 px-2 text-sm font-bold tracking-wide text-white">
        <span class="grid h-10 w-10 place-items-center rounded-xl bg-[#54dfa3] text-[#07110d]">L</span>
        LIMIT <span class="text-[#54dfa3]">/</span> EXCHANGE
      </RouterLink>
      <p class="mb-3 mt-12 px-3 text-[10px] font-bold uppercase tracking-[.22em] text-[#586677]">Workspace</p>
      <nav class="space-y-1">
        <RouterLink to="/" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition" :class="!tradeMode ? 'bg-[#16231f] font-semibold text-[#74e7b5]' : 'text-[#8a96a6] hover:bg-[#131a23] hover:text-white'">
          <span class="w-5 text-center">▦</span> Overview
        </RouterLink>
        <RouterLink to="/trade" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition" :class="tradeMode ? 'bg-[#16231f] font-semibold text-[#74e7b5]' : 'text-[#8a96a6] hover:bg-[#131a23] hover:text-white'">
          <span class="w-5 text-center">⌁</span> Trade
        </RouterLink>
      </nav>
      <div class="mt-auto rounded-2xl border border-[#25313d] bg-[#101720] p-4">
        <div class="mb-3 flex items-center gap-2 text-xs font-semibold text-[#dce4ed]">
          <span class="h-2 w-2 rounded-full bg-[#54dfa3] shadow-[0_0_10px_#54dfa3]"></span>
          Exchange status
        </div>
        <p class="text-[11px] leading-5 text-[#7d8999]">Orders settle atomically. Updates are delivered on your private channel.</p>
        <p class="mt-3 text-[10px] text-[#677485]">Private wallet and order data</p>
      </div>
    </aside>

    <main class="xl:pl-[250px]">
      <header class="sticky top-0 z-10 flex h-[74px] items-center justify-between border-b border-[#1d2732] bg-[#080b10]/90 px-5 backdrop-blur-xl sm:px-8 lg:px-10">
        <RouterLink to="/" class="flex items-center gap-2 text-xs font-bold tracking-wide text-white xl:hidden">
          <span class="grid h-8 w-8 place-items-center rounded-lg bg-[#54dfa3] text-[#07110d]">L</span> LIMIT / EXCHANGE
        </RouterLink>
        <div class="hidden text-sm text-[#8995a5] xl:block">Exchange <span class="mx-2 text-[#394452]">/</span> <span class="text-[#e5e7eb]">{{ tradeMode ? 'Trade' : 'Overview' }}</span></div>
        <div class="flex items-center gap-3 sm:gap-5">
          <span class="hidden items-center gap-2 text-xs text-[#8995a5] sm:flex"><i class="h-2 w-2 rounded-full bg-[#54dfa3]"></i> Systems operational</span>
          <span class="h-5 border-l border-[#293341]"></span>
          <div class="hidden text-right sm:block">
            <p class="text-xs font-semibold text-white">{{ auth.user?.name }}</p>
            <p class="mt-0.5 text-[10px] text-[#718093]">Verified account</p>
          </div>
          <button class="rounded-lg border border-[#2a3542] px-3 py-2 text-xs font-medium text-[#b0bac7] transition hover:border-[#566273] hover:text-white" @click="logout">Sign out</button>
        </div>
      </header>

      <nav class="flex gap-2 border-b border-[#1d2732] px-5 py-2.5 xl:hidden sm:px-8" aria-label="Main navigation">
        <RouterLink to="/" class="rounded-lg px-3 py-2 text-xs font-semibold" :class="!tradeMode ? 'bg-[#16231f] text-[#74e7b5]' : 'text-[#8995a5]'">Overview</RouterLink>
        <RouterLink to="/trade" class="rounded-lg px-3 py-2 text-xs font-semibold" :class="tradeMode ? 'bg-[#16231f] text-[#74e7b5]' : 'text-[#8995a5]'">Trade</RouterLink>
      </nav>

      <div class="mx-auto max-w-[1500px] px-5 pb-12 pt-8 sm:px-8 lg:px-10">
        <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="mb-2 text-[10px] font-bold uppercase tracking-[.23em] text-[#54dfa3]">{{ tradeMode ? 'Spot markets' : 'Personal workspace' }}</p>
            <h1 class="text-[28px] font-semibold tracking-tight text-white sm:text-[34px]">{{ tradeMode ? 'Trade with precision.' : `Good to see you, ${auth.user?.name?.split(' ')[0] ?? 'trader'}.` }}</h1>
            <p class="mt-2 text-sm text-[#7f8b9b]">{{ tradeMode ? 'Fixed-price orders. Full-size fills. No surprises.' : 'Your wallet and open markets, all in one place.' }}</p>
          </div>
          <button class="flex items-center gap-2 rounded-lg border border-[#283340] bg-[#10151d] px-3.5 py-2.5 text-xs font-medium text-[#aeb8c5] transition hover:border-[#4a5868] hover:text-white disabled:opacity-50" :disabled="exchange.loading" @click="refreshWorkspace">
            <span :class="exchange.loading ? 'animate-spin' : ''">↻</span> Refresh data
          </button>
        </div>

        <div v-if="exchange.error || actionError" role="alert" class="mb-5 flex items-start justify-between gap-4 rounded-xl border border-rose-400/25 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
          <span>{{ actionError || exchange.error }}</span>
          <button class="text-rose-100" aria-label="Dismiss error" @click="actionError = ''; exchange.error = ''">×</button>
        </div>
        <div v-if="exchange.notice" role="status" class="mb-5 flex items-center justify-between gap-3 rounded-xl border border-emerald-300/20 bg-emerald-300/[.07] px-4 py-3 text-sm text-emerald-100">
          {{ exchange.notice }}
          <button aria-label="Dismiss message" class="text-emerald-100/70" @click="exchange.notice = ''">×</button>
        </div>

        <section class="mb-6 grid gap-4 md:grid-cols-3">
          <article class="panel relative overflow-hidden p-5 sm:p-6">
            <div class="pointer-events-none absolute -right-4 -top-10 h-32 w-32 rounded-full bg-emerald-300/[.07] blur-3xl"></div>
            <div class="relative flex items-start justify-between">
              <div>
                <p class="text-xs font-medium text-[#8d99a8]">Available USD</p>
                <p class="mono mt-4 text-[25px] font-semibold tracking-tight text-white sm:text-[29px]">${{ formatDecimal(exchange.profile?.balance ?? '0.00000000') }}</p>
                <p class="mt-2 text-[11px] text-[#667485]">USD ready to trade</p>
              </div>
              <span class="grid h-10 w-10 place-items-center rounded-xl border border-emerald-300/15 bg-emerald-300/[.07] text-lg text-[#54dfa3]">$</span>
            </div>
          </article>
          <article v-for="asset in exchange.profile?.assets ?? []" :key="asset.symbol" class="panel p-5 sm:p-6">
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs font-medium text-[#8d99a8]">Available {{ asset.symbol }}</p>
                <p class="mono mt-4 text-[25px] font-semibold tracking-tight text-white sm:text-[29px]">{{ formatDecimal(asset.amount) }}</p>
                <p class="mt-2 text-[11px] text-[#667485]">{{ formatDecimal(asset.locked_amount) }} locked in orders</p>
              </div>
              <span class="grid h-10 w-10 place-items-center rounded-xl border border-[#2b3644] bg-[#141c26] text-xs font-bold text-[#c8d1dc]">{{ asset.symbol }}</span>
            </div>
          </article>
          <article v-if="!exchange.profile?.assets.length" class="panel flex min-h-[150px] items-center justify-center p-6 text-sm text-[#7f8b9b] md:col-span-2">
            Wallet assets will appear when your profile loads.
          </article>
        </section>

        <section class="grid items-start gap-5 2xl:grid-cols-[minmax(0,1.55fr)_minmax(330px,.75fr)]">
          <div class="space-y-5">
            <article class="panel overflow-hidden">
              <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#202a36] px-5 py-4 sm:px-6">
                <div>
                  <h2 class="text-sm font-semibold text-white">Market depth</h2>
                  <p class="mt-1 text-[11px] text-[#738092]">Live limit orders · best price first</p>
                </div>
                <div class="flex items-center gap-1 rounded-lg border border-[#273241] bg-[#0b1017] p-1">
                  <button v-for="option in (['BTC', 'ETH'] as const)" :key="option" class="rounded-md px-3 py-1.5 text-xs font-semibold transition" :class="exchange.symbol === option ? 'bg-[#25352f] text-[#74e7b5]' : 'text-[#8995a5] hover:text-white'" @click="exchange.symbol = option">{{ option }} / USD</button>
                </div>
              </div>
              <div class="grid grid-cols-2 divide-x divide-[#202a36]">
                <div class="p-4 sm:p-5">
                  <div class="mb-3 flex justify-between text-[10px] font-bold uppercase tracking-[.15em] text-[#778495]">
                    <span>Buy orders</span><span>Amount · {{ exchange.symbol }}</span>
                  </div>
                  <div class="mb-2 grid grid-cols-2 text-[10px] text-[#586677]"><span>Price / USD</span><span class="text-right">Amount</span></div>
                  <div v-if="!exchange.orderbook.buy.length" class="grid min-h-[132px] place-items-center text-xs text-[#667384]">No open buy orders</div>
                  <div v-else class="thin-scrollbar max-h-[230px] space-y-1 overflow-y-auto">
                    <div v-for="order in exchange.orderbook.buy" :key="order.id" class="relative grid grid-cols-2 overflow-hidden rounded-md px-2 py-2 text-xs">
                      <div class="absolute inset-y-0 right-0 bg-emerald-300/[.055]" :style="{ width: barWidth(order.amount, exchange.orderbook.buy) }"></div>
                      <span class="mono relative text-[#66dfa8]">{{ formatDecimal(order.price) }}</span>
                      <span class="mono relative text-right text-[#aeb8c5]">{{ formatDecimal(order.amount) }}</span>
                    </div>
                  </div>
                </div>
                <div class="p-4 sm:p-5">
                  <div class="mb-3 flex justify-between text-[10px] font-bold uppercase tracking-[.15em] text-[#778495]">
                    <span>Sell orders</span><span>Amount · {{ exchange.symbol }}</span>
                  </div>
                  <div class="mb-2 grid grid-cols-2 text-[10px] text-[#586677]"><span>Price / USD</span><span class="text-right">Amount</span></div>
                  <div v-if="!exchange.orderbook.sell.length" class="grid min-h-[132px] place-items-center text-xs text-[#667384]">No open sell orders</div>
                  <div v-else class="thin-scrollbar max-h-[230px] space-y-1 overflow-y-auto">
                    <div v-for="order in exchange.orderbook.sell" :key="order.id" class="relative grid grid-cols-2 overflow-hidden rounded-md px-2 py-2 text-xs">
                      <div class="absolute inset-y-0 right-0 bg-rose-300/[.055]" :style="{ width: barWidth(order.amount, exchange.orderbook.sell) }"></div>
                      <span class="mono relative text-[#ff8790]">{{ formatDecimal(order.price) }}</span>
                      <span class="mono relative text-right text-[#aeb8c5]">{{ formatDecimal(order.amount) }}</span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="flex items-center justify-between border-t border-[#202a36] px-5 py-3 text-[10px] text-[#657284] sm:px-6">
                <span>Orders refresh after every match</span>
                <span>{{ exchange.orderbook.buy.length + exchange.orderbook.sell.length }} open {{ exchange.symbol }} orders</span>
              </div>
            </article>

            <article class="panel overflow-hidden">
              <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#202a36] px-5 py-4 sm:px-6">
                <div>
                  <h2 class="text-sm font-semibold text-white">Your orders</h2>
                  <p class="mt-1 text-[11px] text-[#738092]">Open, filled, and cancelled activity</p>
                </div>
                <select v-model="orderFilter" class="rounded-lg border border-[#273241] bg-[#0b1017] px-3 py-2 text-xs text-[#aab4c2]" aria-label="Filter orders by status">
                  <option value="all">All statuses</option><option value="open">Open</option><option value="filled">Filled</option><option value="cancelled">Cancelled</option>
                </select>
              </div>
              <div class="thin-scrollbar overflow-x-auto">
                <table class="w-full min-w-[660px] border-collapse text-left">
                  <thead class="bg-[#0d1219] text-[10px] uppercase tracking-[.13em] text-[#687687]">
                    <tr><th class="px-5 py-3 font-semibold sm:px-6">Market</th><th class="px-3 py-3 font-semibold">Side</th><th class="px-3 py-3 text-right font-semibold">Limit price</th><th class="px-3 py-3 text-right font-semibold">Amount</th><th class="px-3 py-3 font-semibold">Status</th><th class="px-5 py-3 text-right font-semibold sm:px-6">Action</th></tr>
                  </thead>
                  <tbody class="divide-y divide-[#1c2631] text-xs">
                    <tr v-for="order in filteredOrders" :key="order.id" class="text-[#c3ccd7]">
                      <td class="px-5 py-3.5 font-semibold text-white sm:px-6">{{ order.symbol }} / USD</td>
                      <td class="px-3 py-3.5" :class="order.side === 'buy' ? 'text-[#63dba5]' : 'text-[#ff858e]'">{{ order.side }}</td>
                      <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.price) }}</td>
                      <td class="mono px-3 py-3.5 text-right">{{ formatDecimal(order.amount) }}</td>
                      <td class="px-3 py-3.5"><span class="rounded-full border px-2 py-1 text-[10px]" :class="statusClass(order.status)">{{ statusLabel(order.status) }}</span></td>
                      <td class="px-5 py-3.5 text-right sm:px-6">
                        <button v-if="order.status === 1" :disabled="cancellingId === order.id" class="text-[11px] font-semibold text-[#9ca9b8] transition hover:text-white disabled:opacity-50" @click="cancelOrder(order)">{{ cancellingId === order.id ? 'Cancelling…' : 'Cancel' }}</button>
                        <span v-else class="text-[11px] text-[#536071]">{{ orderDate(order.created_at) }}</span>
                      </td>
                    </tr>
                    <tr v-if="!filteredOrders.length"><td colspan="6" class="px-5 py-10 text-center text-xs text-[#657284]">No orders match this filter.</td></tr>
                  </tbody>
                </table>
              </div>
            </article>
          </div>

          <div class="space-y-5">
            <article class="panel p-5 sm:p-6">
              <div class="mb-5 flex items-start justify-between">
                <div>
                  <h2 class="text-sm font-semibold text-white">Place limit order</h2>
                  <p class="mt-1 text-[11px] text-[#738092]">Orders only execute at your limit or better.</p>
                </div>
                <span class="rounded-md border border-[#273241] bg-[#0b1017] px-2 py-1 text-[10px] text-[#8995a5]">FULL FILL</span>
              </div>

              <div class="mb-5 grid grid-cols-2 rounded-xl border border-[#273241] bg-[#0b1017] p-1">
                <button class="rounded-lg py-2.5 text-xs font-bold transition" :class="selectedSide === 'buy' ? 'bg-[#173229] text-[#69e0aa] shadow-sm' : 'text-[#7f8b9b] hover:text-white'" @click="setSide('buy')">Buy {{ exchange.symbol }}</button>
                <button class="rounded-lg py-2.5 text-xs font-bold transition" :class="selectedSide === 'sell' ? 'bg-[#352124] text-[#ff8b94] shadow-sm' : 'text-[#7f8b9b] hover:text-white'" @click="setSide('sell')">Sell {{ exchange.symbol }}</button>
              </div>

              <form class="space-y-4" @submit.prevent="submitOrder">
                <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
                  Market
                  <div class="input-shell flex items-center justify-between rounded-xl px-3.5 py-3">
                    <select :value="exchange.symbol" class="w-full appearance-none bg-transparent text-sm font-semibold text-white" @change="exchange.symbol = ($event.target as HTMLSelectElement).value as SymbolCode">
                      <option value="BTC">Bitcoin <span>BTC / USD</span></option>
                      <option value="ETH">Ethereum <span>ETH / USD</span></option>
                    </select>
                    <span class="text-xs text-[#788596]">⌄</span>
                  </div>
                </label>
                <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
                  Limit price
                  <div class="input-shell flex items-center rounded-xl px-3.5">
                    <input v-model.trim="price" inputmode="decimal" autocomplete="off" placeholder="0.00" class="mono w-full bg-transparent py-3.5 text-sm text-white outline-none placeholder:text-[#485566]">
                    <span class="text-[11px] text-[#667485]">USD</span>
                  </div>
                </label>
                <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
                  Amount
                  <div class="input-shell flex items-center rounded-xl px-3.5">
                    <input v-model.trim="amount" inputmode="decimal" autocomplete="off" placeholder="0.00000000" class="mono w-full bg-transparent py-3.5 text-sm text-white outline-none placeholder:text-[#485566]">
                    <span class="text-[11px] text-[#667485]">{{ exchange.symbol }}</span>
                  </div>
                </label>
                <div class="flex justify-between text-[10px] text-[#718093]">
                  <span>Available {{ selectedSide === 'buy' ? 'USD' : quoteAsset }}</span>
                  <span class="mono text-[#aab4c2]">{{ selectedSide === 'buy' ? `$${formatDecimal(exchange.profile?.balance ?? '0.00000000')}` : formatDecimal(currentAsset?.amount ?? '0.00000000') }}</span>
                </div>

                <div class="space-y-2 rounded-xl border border-[#222e3a] bg-[#0b1017] p-3.5">
                  <div class="flex justify-between text-[11px]"><span class="text-[#788596]">Estimated volume</span><span class="mono text-[#c7d0dc]">${{ formatDecimal(volumePreview) }}</span></div>
                  <div class="flex justify-between text-[11px]"><span class="text-[#788596]">Buyer fee · 1.5%</span><span class="mono text-[#c7d0dc]">${{ formatDecimal(feePreview) }}</span></div>
                  <div class="my-2 border-t border-[#25313e]"></div>
                  <div class="flex justify-between text-xs font-semibold"><span class="text-[#aab4c2]">Max reserve</span><span class="mono text-white">${{ formatDecimal(reservePreview) }}</span></div>
                </div>

                <p v-if="submitError" role="alert" class="rounded-lg border border-rose-400/25 bg-rose-400/10 px-3 py-2.5 text-xs text-rose-200">{{ submitError }}</p>
                <button :disabled="isSubmitting" class="w-full rounded-xl py-3.5 text-sm font-bold transition disabled:cursor-wait disabled:opacity-60" :class="selectedSide === 'buy' ? 'bg-[#54dfa3] text-[#07110d] hover:bg-[#73eab5]' : 'bg-[#ff858e] text-[#21080b] hover:bg-[#ff9ea5]'">
                  {{ isSubmitting ? 'Submitting…' : `Place ${selectedSide} order` }}
                </button>
              </form>
              <p class="mt-3 text-center text-[10px] leading-5 text-[#657284]">Buy orders reserve the limit value plus the 1.5% fee. Unused reserve is returned after a match.</p>
            </article>

            <article class="panel overflow-hidden">
              <div class="flex items-center justify-between border-b border-[#202a36] px-5 py-4">
                <div>
                  <h2 class="text-sm font-semibold text-white">Recent matches</h2>
                  <p class="mt-1 text-[11px] text-[#738092]">Live fills for your account</p>
                </div>
                <span class="flex items-center gap-1.5 text-[10px] text-[#54dfa3]"><i class="h-1.5 w-1.5 rounded-full bg-current"></i> LIVE</span>
              </div>
              <div v-if="!exchange.trades.length" class="grid min-h-[116px] place-items-center px-5 text-center text-xs leading-5 text-[#657284]">Your completed trades will appear here as they happen.</div>
              <div v-else class="divide-y divide-[#202a36]">
                <div v-for="trade in exchange.trades" :key="trade.id" class="flex items-center justify-between px-5 py-3.5">
                  <div class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-lg text-xs font-bold" :class="trade.side === 'buy' ? 'bg-emerald-300/10 text-[#68dfa9]' : 'bg-rose-300/10 text-[#ff858e]'">{{ trade.side === 'buy' ? '↗' : '↘' }}</span>
                    <div><p class="text-xs font-semibold text-white">{{ trade.side === 'buy' ? 'Bought' : 'Sold' }} {{ trade.symbol }}</p><p class="mt-1 text-[10px] text-[#738092]">{{ orderDate(trade.created_at ?? null) }}</p></div>
                  </div>
                  <div class="text-right"><p class="mono text-xs text-white">${{ formatDecimal(trade.usd_volume) }}</p><p class="mono mt-1 text-[10px] text-[#738092]">{{ formatDecimal(trade.amount) }} {{ trade.symbol }}</p></div>
                </div>
              </div>
            </article>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
