<script setup lang="ts">
import { ref } from 'vue'
import OrderBookPanel from '../components/OrderBookPanel.vue'
import OrderHistoryTable from '../components/OrderHistoryTable.vue'
import RecentMatches from '../components/RecentMatches.vue'
import WalletSummary from '../components/WalletSummary.vue'
import { errorMessage } from '../lib/api'
import { useExchangeStore } from '../stores/exchange'
import { useToastStore } from '../stores/toast'
import type { SymbolCode } from '../types'

defineProps<{
  realtimeBadge: string
}>()

const exchange = useExchangeStore()
const toast = useToastStore()
const cancellingId = ref<number | null>(null)

async function selectMarket(symbol: SymbolCode): Promise<void> {
  exchange.error = ''
  try {
    await exchange.selectSymbol(symbol)
  } catch (error) {
    toast.show(errorMessage(error), 'error')
  }
}

async function cancelOrder(orderId: number): Promise<void> {
  exchange.error = ''
  cancellingId.value = orderId
  try {
    await exchange.cancelOrder(orderId)
  } catch (error) {
    toast.show(errorMessage(error), 'error')
  } finally {
    cancellingId.value = null
  }
}
</script>

<template>
  <div>
    <WalletSummary :profile="exchange.profile" />

    <section class="grid min-w-0 items-start gap-6 2xl:grid-cols-[minmax(0,1.55fr)_minmax(330px,.75fr)]">
      <div class="min-w-0 space-y-6">
        <OrderBookPanel :symbol="exchange.symbol" :orderbook="exchange.orderbook" @select-symbol="selectMarket" />
        <OrderHistoryTable :orders="exchange.orders" :cancelling-id="cancellingId" @cancel="cancelOrder" />
      </div>
      <RecentMatches :trades="exchange.trades" :realtime-badge="realtimeBadge" />
    </section>
  </div>
</template>
