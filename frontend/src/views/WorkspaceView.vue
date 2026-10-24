<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import LimitOrderForm from '../components/LimitOrderForm.vue'
import OrderBookPanel from '../components/OrderBookPanel.vue'
import OrderHistoryTable from '../components/OrderHistoryTable.vue'
import RecentMatches from '../components/RecentMatches.vue'
import WalletSummary from '../components/WalletSummary.vue'
import { errorMessage } from '../lib/api'
import { subscribeToMatches, unsubscribeFromMatches } from '../lib/realtime'
import { useAuthStore } from '../stores/auth'
import { useExchangeStore } from '../stores/exchange'
import type { OrderInput, SymbolCode } from '../types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const exchange = useExchangeStore()
const orderForm = ref<{ reset: () => void } | null>(null)
const actionError = ref('')
const orderError = ref('')
const isSubmitting = ref(false)
const cancellingId = ref<number | null>(null)
const realtimeStatus = ref('Connecting to live updates')
const tradeMode = computed(() => route.path === '/trade')
const realtimeBadge = computed(() => {
  if (realtimeStatus.value === 'Live updates connected') return 'LIVE'
  if (realtimeStatus.value.startsWith('Set VITE_')) return 'SETUP'
  return 'CONNECTING'
})

async function refreshWorkspace(): Promise<void> {
  actionError.value = ''
  try {
    await exchange.refreshAll()
  } catch (error) {
    actionError.value = errorMessage(error)
  }
}

async function selectMarket(symbol: SymbolCode): Promise<void> {
  actionError.value = ''
  try {
    await exchange.selectSymbol(symbol)
  } catch (error) {
    actionError.value = errorMessage(error)
  }
}

async function loadWorkspace(): Promise<void> {
  await refreshWorkspace()

  if (!auth.user || !auth.token) return

  try {
    realtimeStatus.value = 'Waiting for live updates'
    subscribeToMatches(
      auth.user.id,
      auth.token,
      (payload) => {
        exchange.applyMatched(payload)
        void exchange.refreshAll().catch((error: unknown) => {
          actionError.value = errorMessage(error)
        })
      },
      () => {
        void exchange.refreshAll().catch((error: unknown) => {
          actionError.value = errorMessage(error)
        })
      },
      (status) => {
        realtimeStatus.value = status
      },
    )
  } catch (error) {
    realtimeStatus.value = errorMessage(error)
  }
}

async function submitOrder(input: OrderInput): Promise<void> {
  orderError.value = ''
  actionError.value = ''
  isSubmitting.value = true

  try {
    await exchange.placeOrder(input)
    orderForm.value?.reset()
  } catch (error) {
    orderError.value = errorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}

async function cancelOrder(orderId: number): Promise<void> {
  actionError.value = ''
  cancellingId.value = orderId
  try {
    await exchange.cancelOrder(orderId)
  } catch (error) {
    actionError.value = errorMessage(error)
  } finally {
    cancellingId.value = null
  }
}

async function logout(): Promise<void> {
  actionError.value = ''
  unsubscribeFromMatches()
  exchange.clear()
  try {
    await auth.logout()
  } catch (error) {
    actionError.value = errorMessage(error)
  } finally {
    await router.push('/login')
  }
}

onMounted(() => {
  void loadWorkspace()
})

onBeforeUnmount(() => {
  unsubscribeFromMatches()
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
      <nav class="space-y-1" aria-label="Workspace">
        <RouterLink to="/" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition" :class="!tradeMode ? 'bg-[#16231f] font-semibold text-[#74e7b5]' : 'text-[#8a96a6] hover:bg-[#131a23] hover:text-white'">
          <span aria-hidden="true" class="w-5 text-center">▦</span> Overview
        </RouterLink>
        <RouterLink to="/trade" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition" :class="tradeMode ? 'bg-[#16231f] font-semibold text-[#74e7b5]' : 'text-[#8a96a6] hover:bg-[#131a23] hover:text-white'">
          <span aria-hidden="true" class="w-5 text-center">⌁</span> Trade
        </RouterLink>
      </nav>
      <div class="mt-auto rounded-2xl border border-[#25313d] bg-[#101720] p-4">
        <div class="mb-3 flex items-center gap-2 text-xs font-semibold text-[#dce4ed]">
          <span class="h-2 w-2 rounded-full bg-[#54dfa3] shadow-[0_0_10px_#54dfa3]"></span>
          Exchange status
        </div>
        <p class="text-[11px] leading-5 text-[#7d8999]">Orders settle atomically. Updates are delivered on your private channel.</p>
        <p class="mt-3 text-[10px] text-[#677485]">{{ realtimeStatus }}</p>
      </div>
    </aside>

    <main class="xl:pl-[250px]">
      <header class="sticky top-0 z-10 flex h-[74px] items-center justify-between border-b border-[#1d2732] bg-[#080b10]/90 px-5 backdrop-blur-xl sm:px-8 lg:px-10">
        <RouterLink to="/" class="flex items-center gap-2 text-xs font-bold tracking-wide text-white xl:hidden">
          <span class="grid h-8 w-8 place-items-center rounded-lg bg-[#54dfa3] text-[#07110d]">L</span> LIMIT / EXCHANGE
        </RouterLink>
        <div class="hidden text-sm text-[#8995a5] xl:block">Exchange <span class="mx-2 text-[#394452]">/</span> <span class="text-[#e5e7eb]">{{ tradeMode ? 'Trade' : 'Overview' }}</span></div>
        <div class="flex items-center gap-3 sm:gap-5">
          <span class="hidden items-center gap-2 text-xs text-[#8995a5] sm:flex">
            <i class="h-2 w-2 rounded-full" :class="realtimeBadge === 'LIVE' ? 'bg-[#54dfa3]' : 'bg-amber-300'"></i>
            {{ realtimeBadge === 'LIVE' ? 'Live updates' : realtimeBadge === 'SETUP' ? 'Pusher setup required' : 'Connecting' }}
          </span>
          <span class="h-5 border-l border-[#293341]"></span>
          <div class="hidden text-right sm:block">
            <p class="text-xs font-semibold text-white">{{ auth.user?.name }}</p>
            <p class="mt-0.5 text-[10px] text-[#718093]">Exchange account</p>
          </div>
          <button type="button" class="rounded-lg border border-[#2a3542] px-3 py-2 text-xs font-medium text-[#b0bac7] transition hover:border-[#566273] hover:text-white" @click="logout">Sign out</button>
        </div>
      </header>

      <nav class="flex gap-2 border-b border-[#1d2732] px-5 py-2.5 xl:hidden sm:px-8" aria-label="Main navigation">
        <RouterLink to="/" class="rounded-lg px-3 py-2 text-xs font-semibold" :class="!tradeMode ? 'bg-[#16231f] text-[#74e7b5]' : 'text-[#8995a5]'">Overview</RouterLink>
        <RouterLink to="/trade" class="rounded-lg px-3 py-2 text-xs font-semibold" :class="tradeMode ? 'bg-[#16231f] text-[#74e7b5]' : 'text-[#8995a5]'">Trade</RouterLink>
      </nav>

      <div class="mx-auto min-w-0 max-w-[1500px] px-5 pb-12 pt-8 sm:px-8 lg:px-10">
        <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="mb-2 text-[10px] font-bold uppercase tracking-[.23em] text-[#54dfa3]">{{ tradeMode ? 'Spot markets' : 'Personal workspace' }}</p>
            <h1 class="text-[28px] font-semibold tracking-tight text-white sm:text-[34px]">{{ tradeMode ? 'Trade with precision.' : `Good to see you, ${auth.user?.name?.split(' ')[0] ?? 'trader'}.` }}</h1>
            <p class="mt-2 text-sm text-[#7f8b9b]">{{ tradeMode ? 'Fixed-price orders. Full-size fills. No surprises.' : 'Your wallet and open markets, all in one place.' }}</p>
          </div>
          <button type="button" class="flex items-center gap-2 rounded-lg border border-[#283340] bg-[#10151d] px-3.5 py-2.5 text-xs font-medium text-[#aeb8c5] transition hover:border-[#4a5868] hover:text-white disabled:opacity-50" :disabled="exchange.loading" @click="refreshWorkspace">
            <span aria-hidden="true" :class="exchange.loading ? 'animate-spin' : ''">↻</span> Refresh data
          </button>
        </div>

        <div v-if="exchange.error || actionError" role="alert" class="mb-5 flex items-start justify-between gap-4 rounded-xl border border-rose-400/25 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
          <span>{{ actionError || exchange.error }}</span>
          <button type="button" class="text-rose-100" aria-label="Dismiss error" @click="actionError = ''; exchange.error = ''">×</button>
        </div>
        <div v-if="exchange.notice" role="status" class="mb-5 flex items-center justify-between gap-3 rounded-xl border border-emerald-300/20 bg-emerald-300/[.07] px-4 py-3 text-sm text-emerald-100">
          {{ exchange.notice }}
          <button type="button" aria-label="Dismiss message" class="text-emerald-100/70" @click="exchange.notice = ''">×</button>
        </div>

        <WalletSummary :profile="exchange.profile" />

        <section class="grid min-w-0 items-start gap-5 2xl:grid-cols-[minmax(0,1.55fr)_minmax(330px,.75fr)]">
          <div class="min-w-0 space-y-5">
            <OrderBookPanel :symbol="exchange.symbol" :orderbook="exchange.orderbook" @select-symbol="selectMarket" />
            <OrderHistoryTable :orders="exchange.orders" :cancelling-id="cancellingId" @cancel="cancelOrder" />
          </div>
          <div class="min-w-0 space-y-5">
            <LimitOrderForm
              ref="orderForm"
              :symbol="exchange.symbol"
              :profile="exchange.profile"
              :submitting="isSubmitting"
              :server-error="orderError"
              @update-symbol="selectMarket"
              @submit-order="submitOrder"
              @clear-error="orderError = ''"
            />
            <RecentMatches :trades="exchange.trades" :realtime-badge="realtimeBadge" />
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
