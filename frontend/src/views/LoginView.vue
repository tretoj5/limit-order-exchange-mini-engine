<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()
const isRegister = computed(() => route.meta.register === true)
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)

async function submit(): Promise<void> {
  busy.value = true
  try {
    if (isRegister.value) {
      await auth.register(name.value, email.value, password.value, passwordConfirmation.value)
    } else {
      await auth.login(email.value, password.value)
    }
    await router.push('/')
  } catch (cause) {
    toast.show(errorMessage(cause), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="market-ambient relative flex min-h-screen items-center justify-center overflow-hidden bg-[#020617] px-5 py-12">
    <section class="relative w-full max-w-md">
      <RouterLink to="/" class="mb-12 flex w-fit items-center gap-2 text-sm font-medium tracking-wide text-[#f1f5f9]">
        <span class="h-1.5 w-1.5 rounded-full bg-[#fa3812]"></span>
        LIMIT / EXCHANGE
      </RouterLink>
      <div class="mb-8">
        <p class="category-eyebrow mb-3">{{ isRegister ? 'Create your account' : 'Welcome back' }}</p>
        <h1 class="display-heading text-3xl text-[#f1f5f9] sm:text-4xl">{{ isRegister ? 'Join the exchange' : 'Sign in to trade' }}</h1>
        <p class="mt-3 text-sm leading-6 text-[#64748b]">{{ isRegister ? 'Set up a wallet and start placing limit orders.' : 'Access your wallet, orderbook, and live trades.' }}</p>
      </div>

      <form class="space-y-5" @submit.prevent="submit">
        <label v-if="isRegister" class="block space-y-2 text-sm text-[#cbd5e1]">
          Name
          <input v-model.trim="name" autocomplete="name" required class="input-shell block w-full px-4 py-3 text-[#f1f5f9] placeholder:text-[#64748b]" placeholder="Your name">
        </label>
        <label class="block space-y-2 text-sm text-[#cbd5e1]">
          Email
          <input v-model.trim="email" type="email" autocomplete="email" required class="input-shell block w-full px-4 py-3 text-[#f1f5f9] placeholder:text-[#64748b]" placeholder="you@example.com">
        </label>
        <label class="block space-y-2 text-sm text-[#cbd5e1]">
          Password
          <input v-model="password" type="password" :autocomplete="isRegister ? 'new-password' : 'current-password'" minlength="8" required class="input-shell block w-full px-4 py-3 text-[#f1f5f9] placeholder:text-[#64748b]" placeholder="At least 8 characters">
        </label>
        <label v-if="isRegister" class="block space-y-2 text-sm text-[#cbd5e1]">
          Confirm password
          <input v-model="passwordConfirmation" type="password" autocomplete="new-password" minlength="8" required class="input-shell block w-full px-4 py-3 text-[#f1f5f9] placeholder:text-[#64748b]" placeholder="Repeat your password">
        </label>

        <button :disabled="busy" class="primary-action flex w-full items-center justify-center gap-2 px-5 py-3 text-sm disabled:cursor-wait disabled:opacity-60">
          {{ busy ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in' }}
          <span aria-hidden="true" class="transition-colors">↗</span>
        </button>
      </form>

      <p class="mt-7 text-center text-sm text-[#64748b]">
        {{ isRegister ? 'Already have an account?' : 'New to the exchange?' }}
        <RouterLink :to="isRegister ? '/login' : '/register'" class="ml-1 font-medium text-[#34d399] hover:text-[#f1f5f9]">
          {{ isRegister ? 'Sign in' : 'Create account' }}
        </RouterLink>
      </p>
      <div v-if="!isRegister" class="mt-8 border-t border-[#e5e7eb]/20 pt-5 text-xs leading-5 text-[#64748b]">
        Demo account <span class="mono text-[#cbd5e1]">demo@exchange.test</span>
        <span class="mx-1 text-[#475569]">·</span>
        password <span class="mono text-[#cbd5e1]">password</span>
      </div>
    </section>
  </main>
</template>
