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
  <div class="min-h-screen bg-[#020617] text-[#e5e7eb]">
    <header class="border-b border-[#e5e7eb]/20 bg-[#020617]">
      <div class="mx-auto flex min-h-16 max-w-[1200px] items-center justify-between gap-4 px-5 sm:px-6">
        <RouterLink to="/" class="flex shrink-0 items-center gap-2 text-sm font-medium text-[#f1f5f9]">
          <span class="h-1.5 w-1.5 rounded-full bg-[#fa3812]"></span>
          LIMIT / EXCHANGE
        </RouterLink>
        <nav class="flex items-center gap-5" aria-label="Main navigation">
          <RouterLink to="/" class="border-b-2 py-5 text-xs font-medium transition sm:text-sm" :class="!tradeMode ? 'border-[#e5e7eb] text-[#f1f5f9]' : 'border-transparent text-[#64748b] hover:text-[#e5e7eb]'">Overview</RouterLink>
          <RouterLink to="/trade" class="border-b-2 py-5 text-xs font-medium transition sm:text-sm" :class="tradeMode ? 'border-[#e5e7eb] text-[#f1f5f9]' : 'border-transparent text-[#64748b] hover:text-[#e5e7eb]'">Trade</RouterLink>
        </nav>
        <div class="flex shrink-0 items-center gap-3">
          <span class="hidden items-center gap-2 text-xs text-[#64748b] md:flex">
            <i class="h-1.5 w-1.5 rounded-full" :class="realtimeBadge === 'LIVE' ? 'bg-[#34d399] shadow-[0_0_8px_rgba(250,56,18,0.15)]' : 'bg-[#64748b]'"></i>
            <span class="sr-only">Realtime status: </span>
            {{ realtimeBadge === 'LIVE' ? 'Live' : realtimeBadge === 'SETUP' ? 'Setup required' : 'Connecting' }}
          </span>
          <span class="hidden max-w-32 truncate text-xs text-[#cbd5e1] sm:block">{{ auth.user?.name }}</span>
          <button type="button" class="ghost-action px-3 py-2 text-xs transition hover:border-[#e5e7eb] sm:px-4" @click="logout">Sign out</button>
        </div>
      </div>
    </header>

    <main class="mx-auto min-w-0 max-w-[1200px] px-5 pb-16 sm:px-6">
      <section class="market-ambient -mx-5 mb-8 border-b border-[#e5e7eb]/10 px-5 py-10 sm:-mx-6 sm:px-6">
        <div class="relative mx-auto flex max-w-[1200px] flex-wrap items-end justify-between gap-5">
          <div>
            <p class="category-eyebrow mb-3">{{ tradeMode ? 'Spot markets' : 'Personal workspace' }}</p>
            <h1 class="display-heading text-3xl text-[#f1f5f9] sm:text-4xl">
              <template v-if="tradeMode">Trade with <span class="text-[#64748b]">precision.</span></template>
              <template v-else>Good to see you, <span class="text-[#64748b]">{{ auth.user?.name?.split(' ')[0] ?? 'trader' }}.</span></template>
            </h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-[#64748b]">{{ tradeMode ? 'Fixed-price orders. Full-size fills. No surprises.' : 'Your wallet and open markets, all in one place.' }}</p>
          </div>
          <button type="button" class="ghost-action flex items-center gap-2 px-4 py-2.5 text-xs transition hover:border-[#e5e7eb] disabled:opacity-50" :disabled="exchange.loading" @click="refreshWorkspace">
            <span aria-hidden="true" :class="exchange.loading ? 'animate-spin' : ''">↻</span> Refresh data
          </button>
        </div>
      </section>

      <div v-if="exchange.error || actionError" role="alert" class="mb-6 flex items-start justify-between gap-4 rounded-lg border border-[#651a15] bg-[#0a0e1a] px-4 py-3 text-sm text-[#cbd5e1]">
        <span>{{ actionError || exchange.error }}</span>
        <button type="button" class="text-[#cbd5e1]" aria-label="Dismiss error" @click="actionError = ''; exchange.error = ''">×</button>
      </div>
      <div v-if="exchange.notice" role="status" class="mb-6 flex items-center justify-between gap-3 rounded-lg border border-[#353845] bg-[#0a0e1a] px-4 py-3 text-sm text-[#e5e7eb]">
        {{ exchange.notice }}
        <button type="button" aria-label="Dismiss message" class="text-[#64748b]" @click="exchange.notice = ''">×</button>
      </div>

      <WalletSummary :profile="exchange.profile" />

      <section class="grid min-w-0 items-start gap-6 2xl:grid-cols-[minmax(0,1.55fr)_minmax(330px,.75fr)]">
        <div class="min-w-0 space-y-6">
          <OrderBookPanel :symbol="exchange.symbol" :orderbook="exchange.orderbook" @select-symbol="selectMarket" />
          <OrderHistoryTable :orders="exchange.orders" :cancelling-id="cancellingId" @cancel="cancelOrder" />
        </div>
        <div class="min-w-0 space-y-6">
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
    </main>
  </div>
</template>
