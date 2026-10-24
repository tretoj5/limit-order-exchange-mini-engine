import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useToastStore } from './toast'

describe('toast store', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('dismisses notifications automatically after five seconds', () => {
    vi.useFakeTimers()
    setActivePinia(createPinia())
    const toast = useToastStore()

    toast.show('Order submitted.')
    expect(toast.messages).toHaveLength(1)

    vi.advanceTimersByTime(5000)

    expect(toast.messages).toHaveLength(0)
  })

  it('keeps only the three newest notifications and supports manual dismissal', () => {
    setActivePinia(createPinia())
    const toast = useToastStore()

    toast.show('First')
    toast.show('Second')
    toast.show('Third')
    toast.show('Fourth', 'error')

    expect(toast.messages.map((message) => message.message)).toEqual(['Second', 'Third', 'Fourth'])
    expect(toast.messages[2]?.kind).toBe('error')

    const id = toast.messages[1]?.id
    if (id !== undefined) toast.dismiss(id)

    expect(toast.messages.map((message) => message.message)).toEqual(['Second', 'Fourth'])
    toast.clear()
  })
})
