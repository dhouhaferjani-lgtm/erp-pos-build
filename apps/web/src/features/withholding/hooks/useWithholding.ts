import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
  return useQuery({
    queryKey: ['withholding-certificates', filters],
    queryFn: () => withholdingApi.fetchWithholdingCertificates(filters),
  });
}

/**
 * Fetch single withholding certificate
 */
export function useWithholdingCertificate(id: string) {
  return useQuery({
    queryKey: ['withholding-certificate', id],
    queryFn: () => withholdingApi.fetchWithholdingCertificate(id),
    enabled: !!id,
  });
}

/**
 * Create withholding certificate
 */
export function useCreateWithholdingCertificate() {
  const queryClient = useQueryClient();
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (request: CreateWithholdingCertificateRequest) =>
      withholdingApi.createWithholdingCertificate(request),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['withholding-certificates'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (id: string) =>
      withholdingApi.issueWithholdingCertificate(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] });
      queryClient.invalidateQueries({ queryKey: ['withholding-certificates'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: VoidCertificateRequest }) =>
      withholdingApi.voidWithholdingCertificate(id, request),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] });
      queryClient.invalidateQueries({ queryKey: ['withholding-certificates'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: SubmitToTEJRequest }) =>
      withholdingApi.submitCertificateToTEJ(id, request),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['withholding-certificate', id] });
      queryClient.invalidateQueries({ queryKey: ['withholding-certificates'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: (id: string) =>
      withholdingApi.deleteWithholdingCertificate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['withholding-certificates'] });
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
  return useQuery({
    queryKey: ['withholding-rules', countryCode],
    queryFn: () => withholdingApi.fetchWithholdingRules(countryCode),
  });
}

/**
 * Fetch single withholding rule
 */
export function useWithholdingRule(id: string) {
  return useQuery({
    queryKey: ['withholding-rule', id],
    queryFn: () => withholdingApi.fetchWithholdingRule(id),
    enabled: !!id,
  });
}

/**
 * Create withholding rule
 */
export function useCreateWithholdingRule() {
  const queryClient = useQueryClient();
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.createWithholdingRule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['withholding-rules'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Parameters<typeof withholdingApi.updateWithholdingRule>[1] }) =>
      withholdingApi.updateWithholdingRule(id, data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['withholding-rule', id] });
      queryClient.invalidateQueries({ queryKey: ['withholding-rules'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.deleteWithholdingRule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['withholding-rules'] });
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
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: withholdingApi.deactivateWithholdingRule,
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: ['withholding-rule', id] });
      queryClient.invalidateQueries({ queryKey: ['withholding-rules'] });
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
  return useQuery({
    queryKey: ['sales-withholding-tracking', filters],
    queryFn: () => withholdingApi.fetchSalesWithholdingTracking(filters),
  });
}

/**
 * Fetch single sales withholding tracking record
 */
export function useSalesWithholdingTrackingRecord(id: string) {
  return useQuery({
    queryKey: ['sales-withholding-tracking', id],
    queryFn: () => withholdingApi.fetchSalesWithholdingTrackingRecord(id),
    enabled: !!id,
  });
}

/**
 * Mark certificate as received for sales withholding tracking
 */
export function useMarkCertificateReceived() {
  const queryClient = useQueryClient();
  const { t } = useTranslation('withholding');

  return useMutation({
    mutationFn: ({ id, request }: { id: string; request: MarkCertificateReceivedRequest }) =>
      withholdingApi.markCertificateReceived(id, request),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-withholding-tracking'] });
      toast.success(t('salesWithholding.messages.certificateMarkedReceived'));
    },
    onError: (error: Error) => {
      toast.error(error.message || t('salesWithholding.messages.markReceivedFailed'));
    },
  });
}
