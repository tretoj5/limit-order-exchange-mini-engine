<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { errorMessage } from '../lib/api'
import { subscribeToMatches, unsubscribeFromMatches } from '../lib/realtime'
import { useAuthStore } from '../stores/auth'
import { useExchangeStore } from '../stores/exchange'
import { useToastStore } from '../stores/toast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const exchange = useExchangeStore()
const toast = useToastStore()
const realtimeStatus = ref('Connecting to live updates')
const tradeMode = computed(() => route.path === '/trade')
const realtimeBadge = computed(() => {
  if (realtimeStatus.value === 'Live updates connected') return 'LIVE'
  if (realtimeStatus.value.startsWith('Set VITE_')) return 'SETUP'
  return 'CONNECTING'
})

async function syncWorkspaceData(): Promise<void> {
  try {
    await exchange.refreshAll()
  } catch (error) {
    exchange.error = errorMessage(error)
  }
}

async function loadWorkspace(): Promise<void> {
  await syncWorkspaceData()

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
          exchange.error = errorMessage(error)
        })
      },
      (status) => {
        realtimeStatus.value = status
      },
    )
  } catch (error) {
    realtimeStatus.value = errorMessage(error)
    toast.show(realtimeStatus.value, 'error')
  }
}

async function logout(): Promise<void> {
  unsubscribeFromMatches()
  exchange.clear()
  toast.clear()
  try {
    await auth.logout()
  } catch (error) {
    toast.show(errorMessage(error), 'error')
  } finally {
    await router.push('/login')
  }
}

watch(() => exchange.error, (message) => {
  if (!message) return
  toast.show(message, 'error')
  exchange.error = ''
})

watch(() => exchange.notice, (message) => {
  if (!message) return
  toast.show(message)
  exchange.notice = ''
})

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
        <div class="relative mx-auto max-w-[1200px]">
          <div>
            <p class="category-eyebrow mb-3">{{ tradeMode ? 'Spot markets' : 'Personal workspace' }}</p>
            <h1 class="display-heading text-3xl text-[#f1f5f9] sm:text-4xl">
              <template v-if="tradeMode">Trade with <span class="text-[#64748b]">precision.</span></template>
              <template v-else>Good to see you, <span class="text-[#64748b]">{{ auth.user?.name?.split(' ')[0] ?? 'trader' }}.</span></template>
            </h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-[#64748b]">{{ tradeMode ? 'Fixed-price orders. Full-size fills. No surprises.' : 'Your wallet and open markets, all in one place.' }}</p>
          </div>
        </div>
      </section>

      <RouterView v-slot="{ Component }">
        <component :is="Component" :realtime-badge="realtimeBadge" />
      </RouterView>
    </main>
  </div>
</template>
