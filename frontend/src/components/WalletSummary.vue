<script setup lang="ts">
import { formatDecimal } from '../lib/decimal'
import type { Profile } from '../types'

defineProps<{
  profile: Profile | null
}>()
</script>

<template>
  <section class="mb-6 grid gap-4 md:grid-cols-3" aria-label="Wallet balances">
    <article class="panel relative overflow-hidden p-5 sm:p-6">
      <div class="pointer-events-none absolute -right-4 -top-10 h-32 w-32 rounded-full bg-emerald-300/[.07] blur-3xl"></div>
      <div class="relative flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-[#8d99a8]">Available USD</p>
          <p class="mono mt-4 text-[25px] font-semibold tracking-tight text-white sm:text-[29px]">${{ formatDecimal(profile?.balance ?? '0.00000000') }}</p>
          <p class="mt-2 text-[11px] text-[#667485]">USD ready to trade</p>
        </div>
        <span class="grid h-10 w-10 place-items-center rounded-xl border border-emerald-300/15 bg-emerald-300/[.07] text-lg text-[#54dfa3]">$</span>
      </div>
    </article>
    <article v-for="asset in profile?.assets ?? []" :key="asset.symbol" class="panel p-5 sm:p-6">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-[#8d99a8]">Available {{ asset.symbol }}</p>
          <p class="mono mt-4 text-[25px] font-semibold tracking-tight text-white sm:text-[29px]">{{ formatDecimal(asset.amount) }}</p>
          <p class="mt-2 text-[11px] text-[#667485]">{{ formatDecimal(asset.locked_amount) }} locked in orders</p>
        </div>
        <span class="grid h-10 w-10 place-items-center rounded-xl border border-[#2b3644] bg-[#141c26] text-xs font-bold text-[#c8d1dc]">{{ asset.symbol }}</span>
      </div>
    </article>
    <article v-if="profile && profile.assets.length === 0" class="panel flex min-h-[150px] items-center justify-center p-6 text-sm text-[#7f8b9b] md:col-span-2">
      No asset balances yet.
    </article>
    <article v-else-if="!profile" class="panel flex min-h-[150px] items-center justify-center p-6 text-sm text-[#7f8b9b] md:col-span-2" role="status">
      Wallet balances are loading.
    </article>
  </section>
</template>
