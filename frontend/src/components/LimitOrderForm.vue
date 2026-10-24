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
  <article class="panel p-5 sm:p-6">
    <div class="mb-5 flex items-start justify-between">
      <div>
        <h2 class="text-sm font-semibold text-white">Place limit order</h2>
        <p class="mt-1 text-[11px] text-[#738092]">Orders only execute at your limit or better.</p>
      </div>
      <span class="rounded-md border border-[#273241] bg-[#0b1017] px-2 py-1 text-[10px] text-[#8995a5]">FULL FILL</span>
    </div>

    <div class="mb-5 grid grid-cols-2 rounded-xl border border-[#273241] bg-[#0b1017] p-1" role="group" aria-label="Order side">
      <button
        type="button"
        class="rounded-lg py-2.5 text-xs font-bold transition"
        :class="selectedSide === 'buy' ? 'bg-[#173229] text-[#69e0aa] shadow-sm' : 'text-[#7f8b9b] hover:text-white'"
        :aria-pressed="selectedSide === 'buy'"
        @click="setSide('buy')"
      >Buy {{ symbol }}</button>
      <button
        type="button"
        class="rounded-lg py-2.5 text-xs font-bold transition"
        :class="selectedSide === 'sell' ? 'bg-[#352124] text-[#ff8b94] shadow-sm' : 'text-[#7f8b9b] hover:text-white'"
        :aria-pressed="selectedSide === 'sell'"
        @click="setSide('sell')"
      >Sell {{ symbol }}</button>
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
        Market
        <div class="input-shell flex items-center justify-between rounded-xl px-3.5 py-3">
          <select
            :value="symbol"
            class="w-full appearance-none bg-transparent text-sm font-semibold text-white"
            @change="emit('updateSymbol', ($event.target as HTMLSelectElement).value as SymbolCode)"
          >
            <option value="BTC">Bitcoin (BTC / USD)</option>
            <option value="ETH">Ethereum (ETH / USD)</option>
          </select>
          <span aria-hidden="true" class="text-xs text-[#788596]">⌄</span>
        </div>
      </label>
      <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
        Limit price
        <div class="input-shell flex items-center rounded-xl px-3.5">
          <input
            v-model.trim="price"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0.00"
            required
            aria-label="Limit price in USD"
            class="mono w-full bg-transparent py-3.5 text-sm text-white outline-none placeholder:text-[#485566]"
            @input="clearErrors"
          >
          <span class="text-[11px] text-[#667485]">USD</span>
        </div>
      </label>
      <label class="block space-y-2 text-xs font-medium text-[#9aa6b5]">
        Amount
        <div class="input-shell flex items-center rounded-xl px-3.5">
          <input
            v-model.trim="amount"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            placeholder="0.00000000"
            required
            :aria-label="`Amount in ${symbol}`"
            class="mono w-full bg-transparent py-3.5 text-sm text-white outline-none placeholder:text-[#485566]"
            @input="clearErrors"
          >
          <span class="text-[11px] text-[#667485]">{{ symbol }}</span>
        </div>
      </label>
      <div class="flex justify-between text-[10px] text-[#718093]">
        <span>{{ availableLabel }}</span>
        <span class="mono text-[#aab4c2]">{{ selectedSide === 'buy' ? `$${formatDecimal(available)}` : formatDecimal(available) }}</span>
      </div>

      <div class="space-y-2 rounded-xl border border-[#222e3a] bg-[#0b1017] p-3.5">
        <div class="flex justify-between text-[11px]">
          <span class="text-[#788596]">Estimated volume</span>
          <span class="mono text-[#c7d0dc]">${{ formatDecimal(volumePreview) }}</span>
        </div>
        <div class="flex justify-between text-[11px]">
          <span class="text-[#788596]">Estimated buyer fee · 1.5%</span>
          <span class="mono text-[#c7d0dc]">${{ formatDecimal(feePreview) }}</span>
        </div>
        <div class="my-2 border-t border-[#25313e]"></div>
        <div class="flex justify-between gap-3 text-xs font-semibold">
          <span class="text-[#aab4c2]">{{ reserveLabel }}</span>
          <span class="mono text-right text-white">{{ selectedSide === 'buy' ? '$' : '' }}{{ formatDecimal(reserveAmount) }}{{ selectedSide === 'sell' ? ` ${symbol}` : '' }}</span>
        </div>
      </div>

      <p v-if="validationError" role="alert" class="rounded-lg border border-rose-400/25 bg-rose-400/10 px-3 py-2.5 text-xs text-rose-200">{{ validationError }}</p>
      <p v-else-if="serverError" role="alert" class="rounded-lg border border-rose-400/25 bg-rose-400/10 px-3 py-2.5 text-xs text-rose-200">{{ serverError }}</p>
      <button
        :disabled="submitting"
        class="w-full rounded-xl py-3.5 text-sm font-bold transition disabled:cursor-wait disabled:opacity-60"
        :class="selectedSide === 'buy' ? 'bg-[#54dfa3] text-[#07110d] hover:bg-[#73eab5]' : 'bg-[#ff858e] text-[#21080b] hover:bg-[#ff9ea5]'"
      >{{ submitting ? 'Submitting…' : `Place ${selectedSide} order` }}</button>
    </form>
    <p class="mt-3 text-center text-[10px] leading-5 text-[#657284]">
      Buy orders reserve USD for the limit value and maximum buyer fee. Sell orders lock the asset amount. The buyer pays the fee when a match settles.
    </p>
  </article>
</template>
