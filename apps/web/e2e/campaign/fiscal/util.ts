const ISO_SECONDS_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/
const SCALE_THREE_MONEY_PATTERN = /^(-?)(\d+)\.(\d{3})$/

export function formatScaleThreeMoney(value: string, scale: 2 | 3): string {
  const match = SCALE_THREE_MONEY_PATTERN.exec(value)
  if (!match) throw new Error(`Money value must use scale 3: ${value}`)
  if (scale === 3) return value

  const [, sign, whole = '', fraction = ''] = match
  const magnitudeAtScaleThree = BigInt(`${whole}${fraction}`)
  const roundedAtScaleTwo = (magnitudeAtScaleThree + 5n) / 10n
  const digits = roundedAtScaleTwo.toString().padStart(3, '0')
  const formattedSign = sign === '-' && roundedAtScaleTwo !== 0n ? '-' : ''
  return `${formattedSign}${digits.slice(0, -2)}.${digits.slice(-2)}`
}

export function semanticLeafPaths(value: unknown, prefix = ''): string[] {
  if (Array.isArray(value)) {
    return value.flatMap((item, index) => semanticLeafPaths(item, `${prefix}[${String(index)}]`))
  }
  if (typeof value === 'object' && value !== null) {
    return Object.entries(value).flatMap(([key, item]) =>
      semanticLeafPaths(item, prefix === '' ? key : `${prefix}.${key}`))
  }
  return [prefix]
}

export function withMilliseconds(value: string): string {
  if (!ISO_SECONDS_PATTERN.test(value)) {
    throw new Error(`Timestamp must use UTC second precision: ${value}`)
  }
  return value.replace(/Z$/, '.000Z')
}
