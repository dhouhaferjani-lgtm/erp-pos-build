import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, apiPost, apiPatch, apiDelete, getErrorMessage } from '../../../lib/api'

export type AdditionalCostType = 'shipping' | 'customs' | 'insurance' | 'handling' | 'other'

export interface AdditionalCost {
  id: string
  document_id: string
  cost_type: AdditionalCostType
  description: string
  amount: string
  created_at: string
  updated_at: string
}

export interface CreateAdditionalCostData {
  cost_type: AdditionalCostType
  description: string
  amount: number
}

export interface UpdateAdditionalCostData {
  cost_type?: AdditionalCostType
  description?: string
  amount?: number
}

interface AdditionalCostsResponse {
  data: AdditionalCost[]
}

interface AdditionalCostResponse {
  data: AdditionalCost
}

/**
 * Fetch additional costs for a document
 */
export function useAdditionalCosts(documentId: string | undefined) {
  return useQuery({
    queryKey: ['additional-costs', documentId],
    queryFn: async () => {
      const response = await api.get<AdditionalCostsResponse>(
        `/documents/${documentId}/additional-costs`
      )
      return response.data
    },
    enabled: !!documentId,
  })
}

/**
 * Create a new additional cost
 */
export function useCreateAdditionalCost(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: CreateAdditionalCostData) => {
      const response = await apiPost<AdditionalCostResponse>(
        `/documents/${documentId}/additional-costs`,
        data
      )
      return response
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['additional-costs', documentId] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', documentId] })
      toast.success('Additional cost created')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Update an additional cost
 */
export function useUpdateAdditionalCost(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ costId, data }: { costId: string; data: UpdateAdditionalCostData }) => {
      const response = await apiPatch<AdditionalCostResponse>(
        `/documents/${documentId}/additional-costs/${costId}`,
        data
      )
      return response
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['additional-costs', documentId] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', documentId] })
      toast.success('Additional cost updated')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Delete an additional cost
 */
export function useDeleteAdditionalCost(documentId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (costId: string) => {
      await apiDelete(`/documents/${documentId}/additional-costs/${costId}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['additional-costs', documentId] })
      void queryClient.invalidateQueries({ queryKey: ['document', 'purchase_order', documentId] })
      toast.success('Additional cost deleted')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

// ===== Landed Cost Breakdown =====

export interface LandedCostLineAllocation {
  line_id: string
  product_name: string
  description: string
  quantity: number
  unit_price: number
  line_total: number
  allocated_costs: number
  landed_unit_cost: number
  proportion: number
}

export interface LandedCostBreakdownData {
  document_id: string
  total_additional_costs: number
  allocations: LandedCostLineAllocation[]
}

interface LandedCostBreakdownResponse {
  data: LandedCostBreakdownData
}

/**
 * Fetch landed cost breakdown for a document
 * Shows how additional costs are allocated across document lines
 */
export function useLandedCostBreakdown(documentId: string | undefined) {
  return useQuery({
    queryKey: ['landed-cost-breakdown', documentId],
    queryFn: async () => {
      const response = await api.get<LandedCostBreakdownResponse>(
        `/documents/${documentId}/landed-cost-breakdown`
      )
      return response.data.data
    },
    enabled: !!documentId,
  })
}
