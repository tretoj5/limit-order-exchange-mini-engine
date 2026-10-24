import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'success' | 'error'

export interface ToastMessage {
  id: number
  message: string
  kind: ToastKind
}

const toastLifetime = 5000

export const useToastStore = defineStore('toast', () => {
  const messages = ref<ToastMessage[]>([])
  const timers = new Map<number, ReturnType<typeof setTimeout>>()
  let nextId = 0

  function dismiss(id: number): void {
    const timer = timers.get(id)
    if (timer !== undefined) clearTimeout(timer)
    timers.delete(id)
    messages.value = messages.value.filter((toast) => toast.id !== id)
  }

  function show(message: string, kind: ToastKind = 'success'): void {
    if (!message) return

    if (messages.value.length === 3) {
      const oldest = messages.value[0]
      if (oldest) dismiss(oldest.id)
    }

    const id = ++nextId
    messages.value = [...messages.value, { id, message, kind }]
    timers.set(id, setTimeout(() => dismiss(id), toastLifetime))
  }

  function clear(): void {
    for (const timer of timers.values()) clearTimeout(timer)
    timers.clear()
    messages.value = []
  }

  return { messages, show, dismiss, clear }
})
