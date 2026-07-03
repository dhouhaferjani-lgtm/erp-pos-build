import { api } from '@/lib/api'
import type {
  CreateIncomeDTO,
  Income,
  IncomeFilters,
  IncomeListResponse,
  IncomeResponse,
} from '../types'

/**
 * Income API client — mirror of expenseApi.
 */
export const incomeApi = {
  list: async (filters?: IncomeFilters): Promise<IncomeListResponse> => {
    const { data } = await api.get<IncomeListResponse>('/income', { params: filters })
    return data
  },

  get: async (id: string): Promise<Income> => {
    const { data } = await api.get<IncomeResponse>(`/income/${id}`)
    return data.data
  },

  create: async (incomeData: CreateIncomeDTO): Promise<Income> => {
    const { data } = await api.post<IncomeResponse>('/income', incomeData)
    return data.data
  },

  update: async (id: string, incomeData: Partial<CreateIncomeDTO>): Promise<Income> => {
    const { data } = await api.patch<IncomeResponse>(`/income/${id}`, incomeData)
    return data.data
  },

  delete: async (id: string): Promise<void> => {
    await api.delete(`/income/${id}`)
  },

  post: async (id: string): Promise<Income> => {
    const { data } = await api.post<IncomeResponse>(`/income/${id}/post`)
    return data.data
  },
}
