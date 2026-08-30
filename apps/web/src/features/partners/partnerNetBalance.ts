import { bcsub } from '../../lib/decimal'

interface PartnerBalanceSnapshot {
  type: 'customer' | 'supplier' | 'both'
  receivable_balance: string | null
  credit_balance: string | null
  payable_balance: string | null
  net_balance?: string | null
}

export function getCustomerCreditExposure(
  partner: Pick<PartnerBalanceSnapshot, 'receivable_balance' | 'credit_balance'>,
): string {
  return bcsub(partner.receivable_balance ?? '0', partner.credit_balance ?? '0')
}

export function getNetBalance(partner: PartnerBalanceSnapshot, isCustomerView: boolean): string {
  if (partner.net_balance !== undefined && partner.net_balance !== null) {
    return partner.net_balance
  }

  const receivable = partner.receivable_balance ?? '0'
  const credit = partner.credit_balance ?? '0'
  const payable = partner.payable_balance ?? '0'

  if (partner.type === 'both') {
    return bcsub(bcsub(receivable, credit), payable)
  }

  if (isCustomerView || partner.type === 'customer') {
    return getCustomerCreditExposure(partner)
  }

  return payable
}
