/** UI estimates only. Authoritative totals always come from checkout/quote. */
export function cents(value: string | number): number {
  const match = String(value).match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!match) return 0
  const result = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
  return Number.isSafeInteger(result) ? result : 0
}
