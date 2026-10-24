import { describe, expect, it } from 'vitest'
import { commission15, formatDecimal, isPositiveDecimal, multiplyToFixed8 } from './decimal'

describe('exact decimal helpers', () => {
  it('computes market volume and commission without floating point', () => {
    const volume = multiplyToFixed8('95000', '0.01')

    expect(volume).toBe('950.00000000')
    expect(commission15(volume)).toBe('14.25000000')
  })

  it('rounds commissions half-up at the eighth decimal place', () => {
    expect(commission15('0.00000100')).toBe('0.00000002')
  })

  it('truncates estimated volume at eight decimals like the backend', () => {
    expect(multiplyToFixed8('0.00000003', '0.5')).toBe('0.00000001')
  })

  it('validates positive values and supported decimal precision', () => {
    expect(isPositiveDecimal('0.00000001')).toBe(true)
    expect(isPositiveDecimal('0')).toBe(false)
    expect(isPositiveDecimal('1.000000001')).toBe(false)
    expect(isPositiveDecimal('1e3')).toBe(false)
  })

  it('formats decimal strings without converting through Number', () => {
    expect(formatDecimal('100000.5')).toBe('100,000.50000000')
  })
})
