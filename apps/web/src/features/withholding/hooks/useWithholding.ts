import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { tenantScopedKey } from '@/lib/tenantScopedKey';
import { useAuthStore } from '@/stores/authStore';
import { useCompanyStore } from '@/stores/companyStore';
import { toast } from 'sonner';
import { useTranslation } from 'react-i18next';
import type {
  WithholdingPreviewRequest,
  CreateWithholdingCertificateRequest,
  VoidCertificateRequest,
  SubmitToTEJRequest,
  CertificateFilters,
  SalesWithholdingTrackingFilters,
  MarkCertificateReceivedRequest,
} from '../types';
import * as withholdingApi from '../api/withholdingApi';

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey;
    return (
      Array.isArray(k) &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    );
  };
}

/**
 * Preview withholding calculation
 */
export function useWithholdingPreview() {
  return useMutation({
    mutationFn: (request: WithholdingPreviewRequest) =>
      withholdingApi.previewWithholding(request),
  });
}

/**
 * Fetch withholding certificates list
 */
export function useWithholdingCertificates(filters?: CertificateFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['withholding-certificates', filters]),
    queryFn: () => withholdingApi.fetchWithholdingCertificates(filters),
    enabled: tenantId !== null && companyId !== null,
  });
}

/**
 * Fetch single withholding certificate
 */
export function useWithholdingCertificate(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['withholding-certificate', id]),
    queryFn: () => withholdingApi.fetchWithholdingCertificate(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  });
}

/**
 * Create withholding certificate
 */
export function useCreateWithholdingCertificate() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (request: CreateWithholdingCertificateRequest) =>
      withholdingApi.createWithholdingCertificate(request),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('withholding-certificates', tenantId, companyId),
      });
      toast.success(t('messages.certificateCreated'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.calculationFailed'));
    },
  });
}

/**
 * Issue withholding certificate
 */
export function useIssueWithholdingCertificate() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (id: string) =>
      withholdingApi.issueWithholdingCertificate(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('withholding-certificates', tenantId, companyId),
        }),
      ]);
      toast.success(t('messages.certificateIssued'));
    },
    onError: (error: Error) => {
      toast.error(error.message);
    },
  });
}

/**
 * Void withholding certificate
 */
export function useVoidWithholdingCertificate() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: VoidCertificateRequest }) =>
      withholdingApi.voidWithholdingCertificate(id, request),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('withholding-certificates', tenantId, companyId),
        }),
      ]);
      toast.success(t('messages.certificateVoided'));
    },
    onError: (error: Error) => {
      toast.error(error.message);
    },
  });
}

/**
 * Submit certificate to TEJ
 */
export function useSubmitCertificateToTEJ() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: SubmitToTEJRequest }) =>
      withholdingApi.submitCertificateToTEJ(id, request),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('withholding-certificates', tenantId, companyId),
        }),
      ]);
      toast.success(t('messages.tejSubmitted'));
    },
    onError: (error: Error) => {
      toast.error(error.message);
    },
  });
}

/**
 * Delete draft certificate
 */
export function useDeleteWithholdingCertificate() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (id: string) =>
      withholdingApi.deleteWithholdingCertificate(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('withholding-certificates', tenantId, companyId),
      });
      toast.success(t('messages.certificateDeleted'));
    },
    onError: (error: Error) => {
      toast.error(error.message);
    },
  });
}

/**
 * Fetch withholding rules
 */
export function useWithholdingRules(countryCode?: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['withholding-rules', countryCode]),
    queryFn: () => withholdingApi.fetchWithholdingRules(countryCode),
    enabled: tenantId !== null && companyId !== null,
  });
}

/**
 * Fetch single withholding rule
 */
export function useWithholdingRule(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['withholding-rule', id]),
    queryFn: () => withholdingApi.fetchWithholdingRule(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  });
}

/**
 * Create withholding rule
 */
export function useCreateWithholdingRule() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.createWithholdingRule,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('withholding-rules', tenantId, companyId),
      });
      toast.success(t('messages.ruleCreated'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.ruleCreationFailed'));
    },
  });
}

/**
 * Update withholding rule
 */
export function useUpdateWithholdingRule() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Parameters<typeof withholdingApi.updateWithholdingRule>[1] }) =>
      withholdingApi.updateWithholdingRule(id, data),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['withholding-rule', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('withholding-rules', tenantId, companyId),
        }),
      ]);
      toast.success(t('messages.ruleUpdated'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.ruleUpdateFailed'));
    },
  });
}

/**
 * Delete withholding rule
 */
export function useDeleteWithholdingRule() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.deleteWithholdingRule,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('withholding-rules', tenantId, companyId),
      });
      toast.success(t('messages.ruleDeleted'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.ruleDeletionFailed'));
    },
  });
}

/**
 * Deactivate withholding rule
 */
export function useDeactivateWithholdingRule() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.deactivateWithholdingRule,
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['withholding-rule', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('withholding-rules', tenantId, companyId),
        }),
      ]);
      toast.success(t('messages.ruleDeactivated'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.ruleDeactivationFailed'));
    },
  });
}

/**
 * Fetch sales withholding tracking records
 */
export function useSalesWithholdingTracking(filters?: SalesWithholdingTrackingFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['sales-withholding-tracking', filters]),
    queryFn: () => withholdingApi.fetchSalesWithholdingTracking(filters),
    enabled: tenantId !== null && companyId !== null,
  });
}

/**
 * Fetch single sales withholding tracking record
 */
export function useSalesWithholdingTrackingRecord(id: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);

  return useQuery({
    queryKey: tenantScopedKey(['sales-withholding-tracking', id]),
    queryFn: () => withholdingApi.fetchSalesWithholdingTrackingRecord(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  });
}

/**
 * Mark certificate as received for sales withholding tracking
 */
export function useMarkCertificateReceived() {
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null);
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null);
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: MarkCertificateReceivedRequest }) =>
      withholdingApi.markCertificateReceived(id, request),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('sales-withholding-tracking', tenantId, companyId),
      });
      toast.success(t('salesWithholding.messages.certificateMarkedReceived'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('salesWithholding.messages.markReceivedFailed'));
    },
  });
}
