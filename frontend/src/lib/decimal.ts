const SCALE = 100_000_000n
const DECIMAL_PATTERN = /^\d+(?:\.\d{1,8})?$/

export function isPositiveDecimal(value: string, maxWholeDigits = 20): boolean {
  if (!DECIMAL_PATTERN.test(value) || value.split('.')[0]!.length > maxWholeDigits) return false
  return toScaledInteger(value) > 0n
}

export function multiplyToFixed8(left: string, right: string): string {
  if (!DECIMAL_PATTERN.test(left) || !DECIMAL_PATTERN.test(right)) return '0.00000000'
  const product = toScaledInteger(left) * toScaledInteger(right)
  return fromScaledInteger(product / SCALE)
}

export function commission15(volume: string): string {
  if (!DECIMAL_PATTERN.test(volume)) return '0.00000000'
  return fromScaledInteger((toScaledInteger(volume) * 15n + 500n) / 1000n)
}

export function addFixed8(left: string, right: string): string {
  if (!DECIMAL_PATTERN.test(left) || !DECIMAL_PATTERN.test(right)) return '0.00000000'
  return fromScaledInteger(toScaledInteger(left) + toScaledInteger(right))
}

export function compareFixed8(left: string, right: string): -1 | 0 | 1 {
  if (!DECIMAL_PATTERN.test(left) || !DECIMAL_PATTERN.test(right)) {
    throw new TypeError('Expected decimal strings with at most 8 fractional digits.')
  }

  const leftScaled = toScaledInteger(left)
  const rightScaled = toScaledInteger(right)
  return leftScaled < rightScaled ? -1 : leftScaled > rightScaled ? 1 : 0
}

export function formatDecimal(value: string, fractionDigits = 8): string {
  if (!DECIMAL_PATTERN.test(value)) return value
  const [whole, fraction = ''] = value.split('.')
  const groupedWhole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return fractionDigits === 0
    ? groupedWhole
    : `${groupedWhole}.${fraction.padEnd(fractionDigits, '0').slice(0, fractionDigits)}`
}

function toScaledInteger(value: string): bigint {
  const [whole, fraction = ''] = value.split('.')
  return BigInt(whole) * SCALE + BigInt(fraction.padEnd(8, '0'))
}

function fromScaledInteger(value: bigint): string {
  const whole = value / SCALE
  const fraction = (value % SCALE).toString().padStart(8, '0')
  return `${whole}.${fraction}`
}
