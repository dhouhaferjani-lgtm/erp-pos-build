interface PartnerBalanceSnapshot {
  type: 'customer' | 'supplier' | 'both'
  receivable_balance: string | null
  credit_balance: string | null
  payable_balance: string | null
}

export function getNetBalance(partner: PartnerBalanceSnapshot, isCustomerView: boolean): number {
  const receivable = parseFloat(partner.receivable_balance ?? '0')
  const credit = parseFloat(partner.credit_balance ?? '0')
  const payable = parseFloat(partner.payable_balance ?? '0')

  if (partner.type === 'both') {
    return receivable - credit - payable
  }

  if (isCustomerView || partner.type === 'customer') {
    return receivable - credit
  }

  return payable
}
