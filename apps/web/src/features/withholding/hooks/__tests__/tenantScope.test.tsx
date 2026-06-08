import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useAuthStore } from '@/stores/authStore';
import { useCompanyStore } from '@/stores/companyStore';

import type {
  CertificateFilters,
  SalesWithholdingTrackingFilters,
  SalesWithholdingTrackingRecord,
  WithholdingCertificate,
  WithholdingRule,
} from '../../types';
import {
  useCreateWithholdingCertificate,
  useCreateWithholdingRule,
  useDeactivateWithholdingRule,
  useDeleteWithholdingCertificate,
  useDeleteWithholdingRule,
  useIssueWithholdingCertificate,
  useMarkCertificateReceived,
  useSalesWithholdingTracking,
  useSalesWithholdingTrackingRecord,
  useSubmitCertificateToTEJ,
  useUpdateWithholdingRule,
  useVoidWithholdingCertificate,
  useWithholdingCertificate,
  useWithholdingCertificates,
  useWithholdingRule,
  useWithholdingRules,
} from '../useWithholding';

const mockFetchWithholdingCertificates = vi.hoisted(() => vi.fn());
const mockFetchWithholdingCertificate = vi.hoisted(() => vi.fn());
const mockCreateWithholdingCertificate = vi.hoisted(() => vi.fn());
const mockIssueWithholdingCertificate = vi.hoisted(() => vi.fn());
const mockVoidWithholdingCertificate = vi.hoisted(() => vi.fn());
const mockSubmitCertificateToTEJ = vi.hoisted(() => vi.fn());
const mockDeleteWithholdingCertificate = vi.hoisted(() => vi.fn());
const mockFetchWithholdingRules = vi.hoisted(() => vi.fn());
const mockFetchWithholdingRule = vi.hoisted(() => vi.fn());
const mockCreateWithholdingRule = vi.hoisted(() => vi.fn());
const mockUpdateWithholdingRule = vi.hoisted(() => vi.fn());
const mockDeleteWithholdingRule = vi.hoisted(() => vi.fn());
const mockDeactivateWithholdingRule = vi.hoisted(() => vi.fn());
const mockFetchSalesWithholdingTracking = vi.hoisted(() => vi.fn());
const mockFetchSalesWithholdingTrackingRecord = vi.hoisted(() => vi.fn());
const mockMarkCertificateReceived = vi.hoisted(() => vi.fn());

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('../../api/withholdingApi', () => ({
  createWithholdingCertificate: mockCreateWithholdingCertificate,
  createWithholdingRule: mockCreateWithholdingRule,
  deactivateWithholdingRule: mockDeactivateWithholdingRule,
  deleteWithholdingCertificate: mockDeleteWithholdingCertificate,
  deleteWithholdingRule: mockDeleteWithholdingRule,
  fetchSalesWithholdingTracking: mockFetchSalesWithholdingTracking,
  fetchSalesWithholdingTrackingRecord: mockFetchSalesWithholdingTrackingRecord,
  fetchWithholdingCertificate: mockFetchWithholdingCertificate,
  fetchWithholdingCertificates: mockFetchWithholdingCertificates,
  fetchWithholdingRule: mockFetchWithholdingRule,
  fetchWithholdingRules: mockFetchWithholdingRules,
  issueWithholdingCertificate: mockIssueWithholdingCertificate,
  markCertificateReceived: mockMarkCertificateReceived,
  submitCertificateToTEJ: mockSubmitCertificateToTEJ,
  updateWithholdingRule: mockUpdateWithholdingRule,
  voidWithholdingCertificate: mockVoidWithholdingCertificate,
}));

const certificateFilters: CertificateFilters = { status: 'draft', year: 2026 };
const trackingFilters: SalesWithholdingTrackingFilters = { filter: 'pending' };

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  });
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  });
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false });
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false });
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  });
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

function cacheKeys(queryClient: QueryClient): unknown[][] {
  return queryClient
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[]);
}

function certificateFixture(id: string): WithholdingCertificate {
  return {
    id,
    certificate_number: `CERT-${id}`,
    year: 2026,
    reference: 'REF-1',
    direction: 'sales',
    direction_label: 'Sales',
    status: 'draft',
    status_label: 'Draft',
    partner_id: 'partner-1',
    partner: { id: 'partner-1', name: 'Partner', vat_number: null },
    document_id: null,
    payment_id: null,
    currency: 'TND',
    gross_amount: '1000.000',
    withholding_rate: '10.000',
    withholding_amount: '100.000',
    net_amount: '900.000',
    rate_percentage: 10,
    withholding_rule_id: 'rule-1',
    rule: { id: 'rule-1', code: 'RULE', name: 'Rule' },
    override_reason: null,
    is_manual_override: false,
    tej_reference: null,
    tej_submitted_at: null,
    is_submitted_to_tej: false,
    certificate_media_id: null,
    hash: null,
    previous_hash: null,
    chain_sequence: null,
    issued_at: null,
    issued_by: null,
    issuer: { id: 'user-1', name: 'Test User' },
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
    can_be_modified: true,
    can_be_issued: true,
    can_be_submitted: false,
    can_be_voided: false,
    gl_account_code: '442100',
  };
}

function ruleFixture(id: string): WithholdingRule {
  return {
    id,
    country_code: 'TN',
    company_id: 'company-1',
    is_global: false,
    code: 'RULE',
    name: 'Rule',
    description: null,
    display_name: 'Rule',
    transaction_type: 'services',
    transaction_type_label: 'Services',
    partner_tax_status: null,
    partner_tax_status_label: null,
    min_amount: null,
    rate: '0.100',
    rate_percentage: '10.00',
    effective_from: '2026-01-01',
    effective_to: null,
    is_active: true,
    is_effective_now: true,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
  };
}

function trackingFixture(id: string): SalesWithholdingTrackingRecord {
  return {
    id,
    tenantId: 'tenant-A',
    companyId: 'company-1',
    documentId: 'document-1',
    paymentId: null,
    customerId: 'customer-1',
    customerName: 'Customer',
    invoiceAmount: '1000.000',
    withholdingRate: '10.000',
    withholdingAmount: '100.000',
    expectedReceivable: '900.000',
    certificateNumber: null,
    certificateReceived: false,
    certificateReceivedAt: null,
    notes: null,
    createdAt: '2026-05-11T09:00:00Z',
    updatedAt: '2026-05-11T09:00:00Z',
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setTenant('tenant-A', 'company-1');
  mockFetchWithholdingCertificates.mockResolvedValue({
    data: [certificateFixture('certificate-1')],
    meta: { per_page: 15, has_more: false },
    links: { next: null, prev: null },
  });
  mockFetchWithholdingCertificate.mockResolvedValue(certificateFixture('certificate-1'));
  mockCreateWithholdingCertificate.mockResolvedValue(certificateFixture('certificate-new'));
  mockIssueWithholdingCertificate.mockResolvedValue(certificateFixture('certificate-issued'));
  mockVoidWithholdingCertificate.mockResolvedValue(certificateFixture('certificate-voided'));
  mockSubmitCertificateToTEJ.mockResolvedValue(certificateFixture('certificate-submitted'));
  mockDeleteWithholdingCertificate.mockResolvedValue(undefined);
  mockFetchWithholdingRules.mockResolvedValue([ruleFixture('rule-1')]);
  mockFetchWithholdingRule.mockResolvedValue(ruleFixture('rule-1'));
  mockCreateWithholdingRule.mockResolvedValue(ruleFixture('rule-new'));
  mockUpdateWithholdingRule.mockResolvedValue(ruleFixture('rule-1'));
  mockDeleteWithholdingRule.mockResolvedValue(undefined);
  mockDeactivateWithholdingRule.mockResolvedValue(ruleFixture('rule-1'));
  mockFetchSalesWithholdingTracking.mockResolvedValue([trackingFixture('tracking-1')]);
  mockFetchSalesWithholdingTrackingRecord.mockResolvedValue(trackingFixture('tracking-1'));
  mockMarkCertificateReceived.mockResolvedValue(trackingFixture('tracking-1'));
});

afterEach(() => {
  resetTenant();
});

describe('withholding hooks tenant scope', () => {
  it('wraps withholding read query keys with the active tenant and company (.778-.779, .788-.789, .796-.797)', async () => {
    const queryClient = createPersistentQueryClient();
    const wrapper = makeWrapper(queryClient);

    const { result } = renderHook(() => ({
      certificate: useWithholdingCertificate('certificate-1'),
      certificates: useWithholdingCertificates(certificateFilters),
      rule: useWithholdingRule('rule-1'),
      rules: useWithholdingRules('TN'),
      tracking: useSalesWithholdingTracking(trackingFilters),
      trackingRecord: useSalesWithholdingTrackingRecord('tracking-1'),
    }), { wrapper });

    await waitFor(() => {
      expect(result.current.certificate.isSuccess).toBe(true);
      expect(result.current.certificates.isSuccess).toBe(true);
      expect(result.current.rule.isSuccess).toBe(true);
      expect(result.current.rules.isSuccess).toBe(true);
      expect(result.current.tracking.isSuccess).toBe(true);
      expect(result.current.trackingRecord.isSuccess).toBe(true);
    });

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['withholding-certificate', 'certificate-1', 'tenant-A', 'company-1'],
      ['withholding-certificates', certificateFilters, 'tenant-A', 'company-1'],
      ['withholding-rule', 'rule-1', 'tenant-A', 'company-1'],
      ['withholding-rules', 'TN', 'tenant-A', 'company-1'],
      ['sales-withholding-tracking', trackingFilters, 'tenant-A', 'company-1'],
      ['sales-withholding-tracking', 'tracking-1', 'tenant-A', 'company-1'],
    ]));
  });

  it('does not fetch withholding reads without tenant/company scope', () => {
    resetTenant();
    const queryClient = createPersistentQueryClient();
    const wrapper = makeWrapper(queryClient);

    renderHook(() => ({
      certificate: useWithholdingCertificate('certificate-1'),
      certificates: useWithholdingCertificates(certificateFilters),
      rule: useWithholdingRule('rule-1'),
      rules: useWithholdingRules('TN'),
      tracking: useSalesWithholdingTracking(trackingFilters),
      trackingRecord: useSalesWithholdingTrackingRecord('tracking-1'),
    }), { wrapper });

    expect(mockFetchWithholdingCertificate).not.toHaveBeenCalled();
    expect(mockFetchWithholdingCertificates).not.toHaveBeenCalled();
    expect(mockFetchWithholdingRule).not.toHaveBeenCalled();
    expect(mockFetchWithholdingRules).not.toHaveBeenCalled();
    expect(mockFetchSalesWithholdingTracking).not.toHaveBeenCalled();
    expect(mockFetchSalesWithholdingTrackingRecord).not.toHaveBeenCalled();
  });

  it('bounds withholding mutation invalidation to the active tenant cache (.780-.787, .790-.795, .798)', async () => {
    const queryClient = createPersistentQueryClient();
    const wrapper = makeWrapper(queryClient);
    let certificateListCalls = 0;
    let certificateDetailCalls = 0;
    let ruleListCalls = 0;
    let ruleDetailCalls = 0;
    let trackingListCalls = 0;
    let trackingDetailCalls = 0;

    mockFetchWithholdingCertificates.mockImplementation(async () => {
      certificateListCalls += 1;
      return {
        data: [certificateFixture(`certificate-list-${certificateListCalls}`)],
        meta: { per_page: 15, has_more: false },
        links: { next: null, prev: null },
      };
    });
    mockFetchWithholdingCertificate.mockImplementation(async () => {
      certificateDetailCalls += 1;
      return certificateFixture(`certificate-detail-${certificateDetailCalls}`);
    });
    mockFetchWithholdingRules.mockImplementation(async () => {
      ruleListCalls += 1;
      return [ruleFixture(`rule-list-${ruleListCalls}`)];
    });
    mockFetchWithholdingRule.mockImplementation(async () => {
      ruleDetailCalls += 1;
      return ruleFixture(`rule-detail-${ruleDetailCalls}`);
    });
    mockFetchSalesWithholdingTracking.mockImplementation(async () => {
      trackingListCalls += 1;
      return [trackingFixture(`tracking-list-${trackingListCalls}`)];
    });
    mockFetchSalesWithholdingTrackingRecord.mockImplementation(async () => {
      trackingDetailCalls += 1;
      return trackingFixture(`tracking-detail-${trackingDetailCalls}`);
    });

    queryClient.setQueryData(
      ['withholding-certificates', certificateFilters, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-certificates-preserved' },
    );
    queryClient.setQueryData(
      ['withholding-certificate', 'certificate-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-certificate-preserved' },
    );
    queryClient.setQueryData(
      ['withholding-rules', 'TN', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-rules-preserved' },
    );
    queryClient.setQueryData(
      ['withholding-rule', 'rule-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-rule-preserved' },
    );
    queryClient.setQueryData(
      ['sales-withholding-tracking', trackingFilters, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-tracking-preserved' },
    );
    queryClient.setQueryData(
      ['sales-withholding-tracking', 'tracking-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-tracking-record-preserved' },
    );

    const { result: reads } = renderHook(() => ({
      certificate: useWithholdingCertificate('certificate-1'),
      certificates: useWithholdingCertificates(certificateFilters),
      rule: useWithholdingRule('rule-1'),
      rules: useWithholdingRules('TN'),
      tracking: useSalesWithholdingTracking(trackingFilters),
      trackingRecord: useSalesWithholdingTrackingRecord('tracking-1'),
    }), { wrapper });
    await waitFor(() => {
      expect(reads.current.certificate.isSuccess).toBe(true);
      expect(reads.current.certificates.isSuccess).toBe(true);
      expect(reads.current.rule.isSuccess).toBe(true);
      expect(reads.current.rules.isSuccess).toBe(true);
      expect(reads.current.tracking.isSuccess).toBe(true);
      expect(reads.current.trackingRecord.isSuccess).toBe(true);
      expect(certificateListCalls).toBe(1);
      expect(certificateDetailCalls).toBe(1);
      expect(ruleListCalls).toBe(1);
      expect(ruleDetailCalls).toBe(1);
      expect(trackingListCalls).toBe(1);
      expect(trackingDetailCalls).toBe(1);
    });

    const { result: mutations } = renderHook(() => ({
      createCertificate: useCreateWithholdingCertificate(),
      issueCertificate: useIssueWithholdingCertificate(),
      voidCertificate: useVoidWithholdingCertificate(),
      submitCertificate: useSubmitCertificateToTEJ(),
      deleteCertificate: useDeleteWithholdingCertificate(),
      createRule: useCreateWithholdingRule(),
      updateRule: useUpdateWithholdingRule(),
      deleteRule: useDeleteWithholdingRule(),
      deactivateRule: useDeactivateWithholdingRule(),
      markCertificateReceived: useMarkCertificateReceived(),
    }), { wrapper });

    await act(async () => {
      await mutations.current.createCertificate.mutateAsync({
        direction: 'sales',
        partner_id: 'partner-1',
        currency: 'TND',
        gross_amount: '1000.000',
      });
    });
    await waitFor(() => {
      expect(certificateListCalls).toBe(2);
      expect(certificateDetailCalls).toBe(1);
    });

    await act(async () => {
      await mutations.current.issueCertificate.mutateAsync('certificate-1');
    });
    await waitFor(() => {
      expect(certificateListCalls).toBe(3);
      expect(certificateDetailCalls).toBe(2);
    });

    await act(async () => {
      await mutations.current.voidCertificate.mutateAsync({
        id: 'certificate-1',
        request: { reason: 'duplicate' },
      });
    });
    await waitFor(() => {
      expect(certificateListCalls).toBe(4);
      expect(certificateDetailCalls).toBe(3);
    });

    await act(async () => {
      await mutations.current.submitCertificate.mutateAsync({
        id: 'certificate-1',
        request: { tej_reference: 'TEJ-1' },
      });
    });
    await waitFor(() => {
      expect(certificateListCalls).toBe(5);
      expect(certificateDetailCalls).toBe(4);
    });

    await act(async () => {
      await mutations.current.deleteCertificate.mutateAsync('certificate-1');
    });
    await waitFor(() => {
      expect(certificateListCalls).toBe(6);
      expect(certificateDetailCalls).toBe(4);
    });

    await act(async () => {
      await mutations.current.createRule.mutateAsync({
        country_code: 'TN',
        code: 'RULE',
        name: 'Rule',
        rate: 10,
        effective_from: '2026-01-01',
      });
    });
    await waitFor(() => {
      expect(ruleListCalls).toBe(2);
      expect(ruleDetailCalls).toBe(1);
    });

    await act(async () => {
      await mutations.current.updateRule.mutateAsync({
        id: 'rule-1',
        data: { name: 'Updated rule' },
      });
    });
    await waitFor(() => {
      expect(ruleListCalls).toBe(3);
      expect(ruleDetailCalls).toBe(2);
    });

    await act(async () => {
      await mutations.current.deleteRule.mutateAsync('rule-1');
    });
    await waitFor(() => {
      expect(ruleListCalls).toBe(4);
      expect(ruleDetailCalls).toBe(2);
    });

    await act(async () => {
      await mutations.current.deactivateRule.mutateAsync('rule-1');
    });
    await waitFor(() => {
      expect(ruleListCalls).toBe(5);
      expect(ruleDetailCalls).toBe(3);
    });

    await act(async () => {
      await mutations.current.markCertificateReceived.mutateAsync({
        id: 'tracking-1',
        request: { certificate_number: 'CERT-1' },
      });
    });
    await waitFor(() => {
      expect(trackingListCalls).toBe(2);
      expect(trackingDetailCalls).toBe(2);
    });

    expect(queryClient.getQueryData([
      'withholding-certificates',
      certificateFilters,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-certificates-preserved' });
    expect(queryClient.getQueryData([
      'withholding-certificate',
      'certificate-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-certificate-preserved' });
    expect(queryClient.getQueryData([
      'withholding-rules',
      'TN',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-rules-preserved' });
    expect(queryClient.getQueryData([
      'withholding-rule',
      'rule-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-rule-preserved' });
    expect(queryClient.getQueryData([
      'sales-withholding-tracking',
      trackingFilters,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-tracking-preserved' });
    expect(queryClient.getQueryData([
      'sales-withholding-tracking',
      'tracking-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-tracking-record-preserved' });
  });
});
