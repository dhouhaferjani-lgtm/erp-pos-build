import { api } from '@/lib/api'

import type {
  CreateExpenseRecurrenceDTO,
  ExpenseRecurrenceTemplate,
} from '../types'

interface RecurrenceResponse {
  data: ExpenseRecurrenceTemplate
}

interface RecurrenceListResponse {
  data: ExpenseRecurrenceTemplate[]
}

export const recurrenceApi = {
  async list(): Promise<ExpenseRecurrenceTemplate[]> {
    const { data } = await api.get<RecurrenceListResponse>('/expense-recurrences')
    return data.data
  },

  async get(id: string): Promise<ExpenseRecurrenceTemplate> {
    const { data } = await api.get<RecurrenceResponse>(`/expense-recurrences/${id}`)
    return data.data
  },

  async create(payload: CreateExpenseRecurrenceDTO): Promise<ExpenseRecurrenceTemplate> {
    const { data } = await api.post<RecurrenceResponse>('/expense-recurrences', payload)
    return data.data
  },

  async update(
    id: string,
    payload: Partial<CreateExpenseRecurrenceDTO>,
  ): Promise<ExpenseRecurrenceTemplate> {
    const { data } = await api.put<RecurrenceResponse>(`/expense-recurrences/${id}`, payload)
    return data.data
  },

  async delete(id: string): Promise<void> {
    await api.delete(`/expense-recurrences/${id}`)
  },

  async pause(id: string): Promise<ExpenseRecurrenceTemplate> {
    const { data } = await api.post<RecurrenceResponse>(`/expense-recurrences/${id}/pause`)
    return data.data
  },

  async resume(id: string): Promise<ExpenseRecurrenceTemplate> {
    const { data } = await api.post<RecurrenceResponse>(`/expense-recurrences/${id}/resume`)
    return data.data
  },
}
