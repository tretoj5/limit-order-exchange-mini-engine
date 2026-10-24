<script setup lang="ts">
import { computed, ref } from 'vue'
import { addFixed8, commission15, compareFixed8, formatDecimal, isPositiveDecimal, multiplyToFixed8 } from '../lib/decimal'
import type { OrderInput, OrderSide, Profile, SymbolCode } from '../types'

const props = defineProps<{
  symbol: SymbolCode
  profile: Profile | null
  submitting: boolean
  serverError: string
}>()

const emit = defineEmits<{
  updateSymbol: [symbol: SymbolCode]
  submitOrder: [input: OrderInput]
  clearError: []
}>()

const selectedSide = ref<OrderSide>('buy')
const price = ref('')
const amount = ref('')
const validationError = ref('')
const volumePreview = computed(() => multiplyToFixed8(price.value, amount.value))
const feePreview = computed(() => commission15(volumePreview.value))
const reservePreview = computed(() => addFixed8(volumePreview.value, feePreview.value))
const quoteAsset = computed(() => props.symbol)
const currentAsset = computed(() => props.profile?.assets.find((asset) => asset.symbol === quoteAsset.value))
const available = computed(() => selectedSide.value === 'buy'
  ? props.profile?.balance ?? '0.00000000'
  : currentAsset.value?.amount ?? '0.00000000')
const availableLabel = computed(() => selectedSide.value === 'buy' ? 'Available USD' : `Available ${quoteAsset.value}`)
const reserveLabel = computed(() => selectedSide.value === 'buy' ? 'Max USD reserve' : `Asset locked on placement`)
const reserveAmount = computed(() => selectedSide.value === 'buy' ? reservePreview.value : amount.value || '0.00000000')

function setSide(side: OrderSide): void {
  selectedSide.value = side
  clearErrors()
}

function clearErrors(): void {
  validationError.value = ''
  emit('clearError')
}

function submit(): void {
  clearErrors()

  if (!isPositiveDecimal(price.value, 12) || !isPositiveDecimal(amount.value, 20)) {
    validationError.value = 'Enter a positive price and amount with no more than 8 decimal places.'
    return
  }

  if (!isPositiveDecimal(volumePreview.value)) {
    validationError.value = 'The order value must be greater than zero at 8 decimal places.'
    return
  }

  if (selectedSide.value === 'buy' && props.profile && compareFixed8(reservePreview.value, props.profile.balance) > 0) {
    validationError.value = 'Insufficient USD balance for the order value and maximum buyer fee.'
    return
  }

  if (selectedSide.value === 'sell' && compareFixed8(amount.value, currentAsset.value?.amount ?? '0.00000000') > 0) {
    validationError.value = `Insufficient available ${quoteAsset.value} balance.`
    return
  }

  emit('submitOrder', {
    symbol: props.symbol,
    side: selectedSide.value,
    price: price.value,
    amount: amount.value,
  })
}

function reset(): void {
  price.value = ''
  amount.value = ''
  validationError.value = ''
}

defineExpose({ reset })
</script>

<template>
  <article class="panel p-6 sm:p-8">
    <div class="mb-5 flex items-start justify-between">
      <div>
        <h2 class="text-sm font-medium text-[#f1f5f9]">Place limit order</h2>
        <p class="mt-1 text-[11px] text-[#64748b]">Orders only execute at your limit or better.</p>
      </div>
      <span class="rounded-full border border-[#353845] px-2 py-1 text-[10px] text-[#cbd5e1]">FULL FILL</span>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-2" role="group" aria-label="Order side">
      <button
        type="button"
        class="ghost-action rounded-full px-3 py-2.5 text-xs transition"
        :class="selectedSide === 'buy' ? 'border-[#e5e7eb] text-[#f1f5f9]' : 'text-[#64748b] hover:text-[#e5e7eb]'"
        :aria-pressed="selectedSide === 'buy'"
        @click="setSide('buy')"
      >Buy {{ symbol }}</button>
      <button
        type="button"
        class="ghost-action rounded-full px-3 py-2.5 text-xs transition"
        :class="selectedSide === 'sell' ? 'border-[#e5e7eb] text-[#f1f5f9]' : 'text-[#64748b] hover:text-[#e5e7eb]'"
        :aria-pressed="selectedSide === 'sell'"
        @click="setSide('sell')"
      >Sell {{ symbol }}</button>
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <label class="block space-y-2 text-xs font-medium text-[#cbd5e1]">
        Market
        <div class="input-shell flex items-center justify-between px-3.5 py-3">
          <select
            :value="symbol"
            class="w-full appearance-none bg-transparent text-sm font-medium text-[#f1f5f9]"
            @change="emit('updateSymbol', ($event.target as HTMLSelectElement).value as SymbolCode)"
          >
            <option value="BTC">Bitcoin (BTC / USD)</option>
            <option value="ETH">Ethereum (ETH / USD)</option>
          </select>
          <span aria-hidden="true" class="text-xs text-[#64748b]">⌄</span>
        </div>
      </label>
      <label class="block space-y-2 text-xs font-medium text-[#cbd5e1]">
        Limit price
        <div class="input-shell flex items-center px-3.5">
          <input
            v-model.trim="price"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0.00"
            required
            aria-label="Limit price in USD"
            class="mono w-full bg-transparent py-3.5 text-sm text-[#e5e7eb] outline-none placeholder:text-[#64748b]"
            @input="clearErrors"
          >
          <span class="text-[11px] text-[#64748b]">USD</span>
        </div>
      </label>
      <label class="block space-y-2 text-xs font-medium text-[#cbd5e1]">
        Amount
        <div class="input-shell flex items-center px-3.5">
          <input
            v-model.trim="amount"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0.00000000"
            required
            :aria-label="`Amount in ${symbol}`"
            class="mono w-full bg-transparent py-3.5 text-sm text-[#e5e7eb] outline-none placeholder:text-[#64748b]"
            @input="clearErrors"
          >
          <span class="text-[11px] text-[#64748b]">{{ symbol }}</span>
        </div>
      </label>
      <div class="flex justify-between text-xs text-[#64748b]">
        <span>{{ availableLabel }}</span>
        <span class="mono text-[#e5e7eb]">{{ selectedSide === 'buy' ? `$${formatDecimal(available)}` : formatDecimal(available) }}</span>
      </div>

      <div class="space-y-3 rounded-lg border border-[#e5e7eb]/20 bg-[#0a0e1a] p-4">
        <div class="flex justify-between text-[11px]">
          <span class="text-[#64748b]">Estimated volume</span>
          <span class="mono text-[#e5e7eb]">${{ formatDecimal(volumePreview) }}</span>
        </div>
        <div class="flex justify-between text-[11px]">
          <span class="text-[#64748b]">Estimated buyer fee · 1.5%</span>
          <span class="mono text-[#e5e7eb]">${{ formatDecimal(feePreview) }}</span>
        </div>
        <div class="my-2 border-t border-[#e5e7eb]/20"></div>
        <div class="flex justify-between gap-3 text-xs font-medium">
          <span class="text-[#cbd5e1]">{{ reserveLabel }}</span>
          <span class="mono text-right text-[#f1f5f9]">{{ selectedSide === 'buy' ? '$' : '' }}{{ formatDecimal(reserveAmount) }}{{ selectedSide === 'sell' ? ` ${symbol}` : '' }}</span>
        </div>
      </div>

      <p v-if="validationError" role="alert" class="rounded-lg border border-[#651a15] bg-[#0a0e1a] px-3 py-2.5 text-xs text-[#cbd5e1]">{{ validationError }}</p>
      <p v-else-if="serverError" role="alert" class="rounded-lg border border-[#651a15] bg-[#0a0e1a] px-3 py-2.5 text-xs text-[#cbd5e1]">{{ serverError }}</p>
      <button
        :disabled="submitting"
        class="primary-action w-full py-3 text-sm disabled:cursor-wait disabled:opacity-60"
      >{{ submitting ? 'Submitting…' : `Place ${selectedSide} order` }}</button>
    </form>
    <p class="mt-4 text-center text-[10px] leading-5 text-[#64748b]">
      Buy orders reserve USD for the limit value and maximum buyer fee. Sell orders lock the asset amount. The buyer pays the fee when a match settles.
    </p>
  </article>
</template>
