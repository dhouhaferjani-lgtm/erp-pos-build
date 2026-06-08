import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  addAttributeValue,
  createAttribute,
  deleteAttribute,
  deleteVariant,
  generateVariantMatrix,
  getAttributes,
  getAttributeValues,
  getVariantsForProduct,
  updateVariant,
  type AddAttributeValuePayload,
  type CreateAttributePayload,
  type UpdateVariantPayload,
} from '../api/variantApi'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

export const attributeKeys = {
  all: ['catalogAttributes'] as const,
  lists: () => [...attributeKeys.all, 'list'] as const,
  values: (attributeId: string) =>
    [...attributeKeys.all, 'values', attributeId] as const,
}

export const variantKeys = {
  all: ['catalogVariants'] as const,
  forProduct: (productId: string) =>
    [...variantKeys.all, 'product', productId] as const,
}

function useScope() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return { tenantId, companyId }
}

// --- Attributes ---

export function useAttributes() {
  const { tenantId, companyId } = useScope()
  return useQuery({
    queryKey: tenantScopedKey([...attributeKeys.lists()]),
    queryFn: () => getAttributes(),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useAttributeValues(attributeId: string) {
  const { tenantId, companyId } = useScope()
  return useQuery({
    queryKey: tenantScopedKey([...attributeKeys.values(attributeId)]),
    queryFn: () => getAttributeValues(attributeId),
    enabled: !!attributeId && tenantId !== null && companyId !== null,
  })
}

export function useCreateAttribute() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateAttributePayload) => createAttribute(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: attributeKeys.all })
    },
  })
}

export function useDeleteAttribute() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (attributeId: string) => deleteAttribute(attributeId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: attributeKeys.all })
    },
  })
}

export function useAddAttributeValue(attributeId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: AddAttributeValuePayload) =>
      addAttributeValue(attributeId, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: attributeKeys.all })
    },
  })
}

// --- Variants ---

export function useVariantsForProduct(productId: string) {
  const { tenantId, companyId } = useScope()
  return useQuery({
    queryKey: tenantScopedKey([...variantKeys.forProduct(productId)]),
    queryFn: () => getVariantsForProduct(productId),
    enabled: !!productId && tenantId !== null && companyId !== null,
  })
}

export function useGenerateMatrix(productId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (attributeIds: string[]) =>
      generateVariantMatrix(productId, attributeIds),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: variantKeys.forProduct(productId),
      })
    },
  })
}

export function useUpdateVariant(productId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      variantId,
      payload,
    }: {
      variantId: string
      payload: UpdateVariantPayload
    }) => updateVariant(variantId, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: variantKeys.forProduct(productId),
      })
    },
  })
}

export function useDeleteVariant(productId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (variantId: string) => deleteVariant(variantId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: variantKeys.forProduct(productId),
      })
    },
  })
}
