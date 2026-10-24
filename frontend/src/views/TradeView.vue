<script setup lang="ts">
import { ref } from 'vue'
import LimitOrderForm from '../components/LimitOrderForm.vue'
import { errorMessage } from '../lib/api'
import { useExchangeStore } from '../stores/exchange'
import { useToastStore } from '../stores/toast'
import type { OrderInput, SymbolCode } from '../types'

const exchange = useExchangeStore()
const toast = useToastStore()
const orderForm = ref<{ reset: () => void } | null>(null)
const isSubmitting = ref(false)

async function selectMarket(symbol: SymbolCode): Promise<void> {
  try {
    await exchange.selectSymbol(symbol)
  } catch (error) {
    toast.show(errorMessage(error), 'error')
  }
}

async function submitOrder(input: OrderInput): Promise<void> {
  exchange.error = ''
  isSubmitting.value = true

  try {
    await exchange.placeOrder(input)
    orderForm.value?.reset()
  } catch (error) {
    toast.show(errorMessage(error), 'error')
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-2xl" aria-labelledby="limit-order-heading">
    <h2 id="limit-order-heading" class="sr-only">Limit order form</h2>
    <LimitOrderForm
      ref="orderForm"
      :symbol="exchange.symbol"
      :profile="exchange.profile"
      :submitting="isSubmitting"
      @update-symbol="selectMarket"
      @submit-order="submitOrder"
    />
  </section>
</template>
