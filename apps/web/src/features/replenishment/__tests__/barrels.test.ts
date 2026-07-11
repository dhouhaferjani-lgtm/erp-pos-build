import * as feature from '../index'
import * as components from '../components'
import * as pages from '../pages'

describe('replenishment public barrels', () => {
  it('exports the feature components, pages, API, and query hooks', () => {
    expect(feature.ReplenishmentQueuePage).toBe(pages.ReplenishmentQueuePage)
    expect(feature.CreateTransferDialog).toBe(components.CreateTransferDialog)
    expect(feature.replenishmentApi).toBeDefined()
    expect(feature.useOpenReplenishment).toBeDefined()
  })
})
