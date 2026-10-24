<script setup lang="ts">
import { formatDecimal } from '../lib/decimal'
import type { Profile } from '../types'

defineProps<{
  profile: Profile | null
}>()
</script>

<template>
  <section class="mb-6 grid gap-4 md:grid-cols-3" aria-label="Wallet balances">
    <article class="panel relative overflow-hidden p-6 sm:p-8">
      <div class="relative flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-[#64748b]">Available USD</p>
          <p class="mono mt-4 text-[25px] font-medium tracking-tight text-[#f1f5f9] sm:text-[29px]">${{ formatDecimal(profile?.balance ?? '0.00000000') }}</p>
          <p class="mt-2 text-[11px] text-[#64748b]">USD ready to trade</p>
        </div>
        <span class="grid h-10 w-10 place-items-center rounded-full border border-[#353845] text-lg text-[#cbd5e1]">$</span>
      </div>
    </article>
    <article v-for="asset in profile?.assets ?? []" :key="asset.symbol" class="panel p-6 sm:p-8">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-[#64748b]">Available {{ asset.symbol }}</p>
          <p class="mono mt-4 text-[25px] font-medium tracking-tight text-[#f1f5f9] sm:text-[29px]">{{ formatDecimal(asset.amount) }}</p>
          <p class="mt-2 text-[11px] text-[#64748b]">{{ formatDecimal(asset.locked_amount) }} locked in orders</p>
        </div>
        <span class="grid h-10 w-10 place-items-center rounded-full border border-[#353845] text-xs font-medium text-[#cbd5e1]">{{ asset.symbol }}</span>
      </div>
    </article>
    <article v-if="profile && profile.assets.length === 0" class="panel flex min-h-[150px] items-center justify-center p-6 text-sm text-[#64748b] md:col-span-2">
      No asset balances yet.
    </article>
    <article v-else-if="!profile" class="panel flex min-h-[150px] items-center justify-center p-6 text-sm text-[#64748b] md:col-span-2" role="status">
      Wallet balances are loading.
    </article>
  </section>
</template>
