// Formátování čísla bankovního účtu pro zobrazení.
//
// Banka v GPC/ABO výpisech číslo účtu zero-paduje (např. `0000000112866714`,
// `000019-0000000112866714`). Pro zobrazení vodicí nuly ořízneme — v předčíslí
// i v základní části. Kód banky za „/" (4místný, kde je vodicí nula významná,
// např. `0300`) necháváme beze změny. Hodnota pro API/filtr se NEmění — ořez je
// jen kosmetika displeje (backend si účet stejně normalizuje).

export function formatAccountNumber(account: string | null | undefined): string {
  if (account == null) return ''
  const raw = String(account).trim()
  if (raw === '') return ''

  // Odděl kód banky za '/'(zachováme ho i s lomítkem beze změny).
  const slash = raw.indexOf('/')
  const numPart = slash === -1 ? raw : raw.slice(0, slash)
  const bankPart = slash === -1 ? '' : raw.slice(slash)

  // Předčíslí-základ: vodicí nuly ořízni (lookahead drží aspoň jednu číslici).
  const segs = numPart.split('-').map(s => s.replace(/^0+(?=\d)/, ''))

  let num: string
  if (segs.length === 2) {
    // Celé předčíslí z nul (např. „000000") zahoď — zobraz jen základ.
    const prefix = segs[0] === '' || segs[0] === '0' ? '' : segs[0]
    num = prefix ? `${prefix}-${segs[1]}` : segs[1]
  } else {
    num = segs[0]
  }

  return num + bankPart
}
