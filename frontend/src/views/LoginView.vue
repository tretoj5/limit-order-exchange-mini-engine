<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '../lib/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isRegister = computed(() => route.meta.register === true)
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const busy = ref(false)

async function submit(): Promise<void> {
  error.value = ''
  busy.value = true
  try {
    if (isRegister.value) {
      await auth.register(name.value, email.value, password.value, passwordConfirmation.value)
    } else {
      await auth.login(email.value, password.value)
    }
    await router.push('/')
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-5 py-12">
    <div class="pointer-events-none absolute -left-40 top-1/4 h-96 w-96 rounded-full bg-emerald-400/10 blur-[110px]"></div>
    <div class="pointer-events-none absolute -right-32 bottom-0 h-96 w-96 rounded-full bg-cyan-500/10 blur-[120px]"></div>
    <div class="relative grid w-full max-w-5xl overflow-hidden rounded-[28px] border border-[#202a36] bg-[#0d1219] shadow-2xl shadow-black/40 lg:grid-cols-[1.05fr_.95fr]">
      <section class="hidden min-h-[650px] flex-col justify-between border-r border-[#202a36] bg-[radial-gradient(ellipse_at_25%_20%,rgba(49,153,112,.16),transparent_45%),linear-gradient(150deg,#101923,#0b1016)] p-12 lg:flex">
        <RouterLink to="/" class="flex w-fit items-center gap-3 text-sm font-semibold tracking-wide text-white">
          <span class="grid h-10 w-10 place-items-center rounded-xl bg-[#54dfa3] text-[#07110d]">L</span>
          LIMIT / EXCHANGE
        </RouterLink>
        <div>
          <p class="mb-5 text-xs font-semibold uppercase tracking-[.24em] text-[#54dfa3]">A clearer way to trade</p>
          <h1 class="max-w-md text-5xl font-semibold leading-[1.08] tracking-tight text-white">Markets move.<br>Stay in control.</h1>
          <p class="mt-6 max-w-sm text-base leading-7 text-[#8794a5]">Place precise limit orders, keep your funds accounted for, and follow every match live.</p>
          <div class="mt-10 flex items-center gap-3 text-sm text-[#aab4c2]">
            <span class="h-2 w-2 rounded-full bg-[#54dfa3] shadow-[0_0_14px_#54dfa3]"></span>
            Secure private order updates
          </div>
        </div>
        <p class="text-xs text-[#667282]">PostgreSQL-backed · Fixed-point settlement · Live matching</p>
      </section>

      <section class="flex min-h-[600px] flex-col justify-center px-7 py-12 sm:px-12">
        <RouterLink to="/" class="mb-10 flex w-fit items-center gap-3 text-sm font-semibold tracking-wide text-white lg:hidden">
          <span class="grid h-9 w-9 place-items-center rounded-xl bg-[#54dfa3] text-[#07110d]">L</span>
          LIMIT / EXCHANGE
        </RouterLink>
        <div class="mb-8">
          <p class="mb-3 text-xs font-semibold uppercase tracking-[.2em] text-[#54dfa3]">{{ isRegister ? 'Create your account' : 'Welcome back' }}</p>
          <h2 class="text-3xl font-semibold tracking-tight text-white">{{ isRegister ? 'Join the exchange' : 'Sign in to trade' }}</h2>
          <p class="mt-3 text-sm text-[#8491a2]">{{ isRegister ? 'Set up a wallet and start placing limit orders.' : 'Access your wallet, orderbook, and live trades.' }}</p>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
          <label v-if="isRegister" class="block space-y-2 text-sm text-[#aab4c2]">
            Name
            <input v-model.trim="name" autocomplete="name" required class="input-shell block w-full rounded-xl px-4 py-3 text-white placeholder:text-[#566171]" placeholder="Your name">
          </label>
          <label class="block space-y-2 text-sm text-[#aab4c2]">
            Email
            <input v-model.trim="email" type="email" autocomplete="email" required class="input-shell block w-full rounded-xl px-4 py-3 text-white placeholder:text-[#566171]" placeholder="you@example.com">
          </label>
          <label class="block space-y-2 text-sm text-[#aab4c2]">
            Password
            <input v-model="password" type="password" :autocomplete="isRegister ? 'new-password' : 'current-password'" minlength="8" required class="input-shell block w-full rounded-xl px-4 py-3 text-white placeholder:text-[#566171]" placeholder="At least 8 characters">
          </label>
          <label v-if="isRegister" class="block space-y-2 text-sm text-[#aab4c2]">
            Confirm password
            <input v-model="passwordConfirmation" type="password" autocomplete="new-password" minlength="8" required class="input-shell block w-full rounded-xl px-4 py-3 text-white placeholder:text-[#566171]" placeholder="Repeat your password">
          </label>

          <p v-if="error" role="alert" class="rounded-xl border border-rose-400/25 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{{ error }}</p>
          <button :disabled="busy" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#54dfa3] px-4 py-3.5 text-sm font-bold text-[#07110d] transition hover:bg-[#73eab5] disabled:cursor-wait disabled:opacity-60">
            {{ busy ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in' }}
            <span aria-hidden="true">↗</span>
          </button>
        </form>

        <p class="mt-7 text-center text-sm text-[#8491a2]">
          {{ isRegister ? 'Already have an account?' : 'New to the exchange?' }}
          <RouterLink :to="isRegister ? '/login' : '/register'" class="ml-1 font-semibold text-[#54dfa3] hover:text-[#8af0c2]">
            {{ isRegister ? 'Sign in' : 'Create account' }}
          </RouterLink>
        </p>
        <div v-if="!isRegister" class="mt-8 rounded-xl border border-[#273241] bg-[#0a0f15] px-4 py-3 text-xs leading-5 text-[#8491a2]">
          Demo account <span class="mono text-[#c7d0dc]">demo@exchange.test</span>
          <span class="mx-1 text-[#4d5968]">·</span>
          password <span class="mono text-[#c7d0dc]">password</span>
        </div>
      </section>
    </div>
  </main>
</template>
