import { lazy, Suspense } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { Layout } from '../components/layout/Layout'
import { RequireAuth } from '../features/auth'
import { RequirePermission } from '../components/auth'
import { ModuleGuard } from '../components/guards'
import { LoadingSpinner } from '../components/ui/LoadingSpinner'

// Lazy loaded pages
const LoginPage = lazy(() => import('../features/auth/LoginPage').then((m) => ({ default: m.LoginPage })))
const RegisterPage = lazy(() => import('../features/auth/RegisterPage').then((m) => ({ default: m.RegisterPage })))
const VerifyEmailPage = lazy(() => import('../features/auth/VerifyEmailPage').then((m) => ({ default: m.VerifyEmailPage })))
const ForgotPasswordPage = lazy(() => import('../features/auth/ForgotPasswordPage').then((m) => ({ default: m.ForgotPasswordPage })))
const ResetPasswordPage = lazy(() => import('../features/auth/ResetPasswordPage').then((m) => ({ default: m.ResetPasswordPage })))
const Dashboard = lazy(() => import('../features/dashboard/Dashboard').then((m) => ({ default: m.Dashboard })))

// Admin pages
const AdminLoginPage = lazy(() => import('../features/admin/pages/AdminLoginPage').then((m) => ({ default: m.AdminLoginPage })))
const AdminDashboardPage = lazy(() => import('../features/admin/pages/AdminDashboardPage').then((m) => ({ default: m.AdminDashboardPage })))
const TenantsPage = lazy(() => import('../features/admin/pages/TenantsPage').then((m) => ({ default: m.TenantsPage })))
const AuditLogsPage = lazy(() => import('../features/admin/pages/AuditLogsPage').then((m) => ({ default: m.AuditLogsPage })))
const BillingDashboardPage = lazy(() => import('../features/admin/pages/BillingDashboardPage').then((m) => ({ default: m.BillingDashboardPage })))
const AdminSubscriptionsPage = lazy(() => import('../features/admin/pages/SubscriptionsPage').then((m) => ({ default: m.SubscriptionsPage })))
const AdminInvoicesPage = lazy(() => import('../features/admin/pages/InvoicesPage').then((m) => ({ default: m.InvoicesPage })))
const AdminPaymentsPage = lazy(() => import('../features/admin/pages/PaymentsPage').then((m) => ({ default: m.PaymentsPage })))
const AdminMonitoringPage = lazy(() => import('../features/admin/pages/MonitoringPage').then((m) => ({ default: m.MonitoringPage })))
const CompanyOwnersPage = lazy(() => import('../features/admin/pages/CompanyOwnersPage').then((m) => ({ default: m.CompanyOwnersPage })))
const AdminLayout = lazy(() => import('../features/admin/components/AdminLayout').then((m) => ({ default: m.AdminLayout })))
const RequireAdminAuth = lazy(() => import('../features/admin/components/RequireAdminAuth').then((m) => ({ default: m.RequireAdminAuth })))

// Sales module
const CustomerListPage = lazy(() => import('../features/partners/PartnerListPage').then((m) => ({ default: m.PartnerListPage })))
const CustomerDetailPage = lazy(() => import('../features/partners/PartnerDetailPage').then((m) => ({ default: m.PartnerDetailPage })))
const CustomerForm = lazy(() => import('../features/partners/PartnerForm').then((m) => ({ default: m.PartnerForm })))

// Documents - reusing for quotes/orders/invoices
const DocumentListPage = lazy(() => import('../features/documents/DocumentListPage').then((m) => ({ default: m.DocumentListPage })))
const DocumentForm = lazy(() => import('../features/documents/DocumentForm').then((m) => ({ default: m.DocumentForm })))
const DeliveryNoteConsolidationPage = lazy(() => import('../features/documents/DeliveryNoteConsolidationPage').then((m) => ({ default: m.DeliveryNoteConsolidationPage })))
const CreateCreditNotePage = lazy(() => import('../features/documents/CreateCreditNotePage').then((m) => ({ default: m.CreateCreditNotePage })))
const ReturnNoteListPage = lazy(() => import('../features/documents/ReturnNoteListPage').then((m) => ({ default: m.ReturnNoteListPage })))
const CreateReturnNotePage = lazy(() => import('../features/documents/CreateReturnNotePage').then((m) => ({ default: m.CreateReturnNotePage })))

// Type-specific detail pages
const QuoteDetailPage = lazy(() => import('../features/documents/quotes').then((m) => ({ default: m.QuoteDetailPage })))
const SalesOrderDetailPage = lazy(() => import('../features/documents/sales-orders').then((m) => ({ default: m.SalesOrderDetailPage })))
const InvoiceDetailPage = lazy(() => import('../features/documents/invoices').then((m) => ({ default: m.InvoiceDetailPage })))
const PurchaseOrderDetailPage = lazy(() => import('../features/documents/purchase-orders').then((m) => ({ default: m.PurchaseOrderDetailPage })))
const DeliveryNoteDetailPage = lazy(() => import('../features/documents/delivery-notes').then((m) => ({ default: m.DeliveryNoteDetailPage })))
const CreditNoteDetailPage = lazy(() => import('../features/documents/credit-notes').then((m) => ({ default: m.CreditNoteDetailPage })))
const ReturnNoteDetailPage = lazy(() => import('../features/documents/return-notes').then((m) => ({ default: m.ReturnNoteDetailPage })))

// Purchases module
const GoodsReceiptListPage = lazy(() => import('../features/purchases/GoodsReceiptListPage').then((m) => ({ default: m.GoodsReceiptListPage })))

// Treasury module
const PaymentListPage = lazy(() => import('../features/treasury/PaymentListPage').then((m) => ({ default: m.PaymentListPage })))
const PaymentDetailPage = lazy(() => import('../features/treasury/PaymentDetailPage').then((m) => ({ default: m.PaymentDetailPage })))
const PaymentForm = lazy(() => import('../features/treasury/PaymentForm').then((m) => ({ default: m.PaymentForm })))
const InstrumentListPage = lazy(() => import('../features/treasury/InstrumentListPage').then((m) => ({ default: m.InstrumentListPage })))
const InstrumentDetailPage = lazy(() => import('../features/treasury/InstrumentDetailPage').then((m) => ({ default: m.InstrumentDetailPage })))
const RepositoryListPage = lazy(() => import('../features/treasury/RepositoryListPage').then((m) => ({ default: m.RepositoryListPage })))
const RepositoryDetailPage = lazy(() => import('../features/treasury/RepositoryDetailPage').then((m) => ({ default: m.RepositoryDetailPage })))
const PaymentMethodsPage = lazy(() => import('../features/treasury/PaymentMethodsPage').then((m) => ({ default: m.PaymentMethodsPage })))
const BankReconciliationPage = lazy(() => import('../features/treasury/BankReconciliationPage').then((m) => ({ default: m.BankReconciliationPage })))

// Withholding module
const WithholdingCertificatesList = lazy(() => import('../features/withholding').then((m) => ({ default: m.WithholdingCertificatesList })))
const WithholdingCertificateDetail = lazy(() => import('../features/withholding').then((m) => ({ default: m.WithholdingCertificateDetail })))
const SalesWithholdingTrackingPage = lazy(() => import('../features/withholding/pages/SalesWithholdingTrackingPage').then((m) => ({ default: m.SalesWithholdingTrackingPage })))

// Reports module
// Hub pages
const InventoryHubPage = lazy(() => import('../features/inventory/pages/InventoryHubPage').then((m) => ({ default: m.InventoryHubPage })))
const PosHubPage = lazy(() => import('../features/pos/pages/PosHubPage').then((m) => ({ default: m.PosHubPage })))
const MarketingHubPage = lazy(() => import('../features/marketing').then((m) => ({ default: m.MarketingHubPage })))
const FinanceHubPage = lazy(() => import('../features/finance/pages/FinanceHubPage').then((m) => ({ default: m.FinanceHubPage })))

// Inventory module
const ProductListPage = lazy(() => import('../features/inventory/ProductListPage').then((m) => ({ default: m.ProductListPage })))
const ProductDetailPage = lazy(() => import('../features/inventory/ProductDetailPage').then((m) => ({ default: m.ProductDetailPage })))
const ProductForm = lazy(() => import('../features/inventory/ProductForm').then((m) => ({ default: m.ProductForm })))
const StockLevelsPage = lazy(() => import('../features/inventory/StockLevelsPage').then((m) => ({ default: m.StockLevelsPage })))
const StockMovementsPage = lazy(() => import('../features/inventory/StockMovementsPage').then((m) => ({ default: m.StockMovementsPage })))
const CategoriesPage = lazy(() => import('../features/categories/CategoriesPage').then((m) => ({ default: m.CategoriesPage })))

// Batch & Expiry Tracking
const BatchListPage = lazy(() => import('../features/batches/pages').then((m) => ({ default: m.BatchListPage })))
const BatchDetailPage = lazy(() => import('../features/batches/pages').then((m) => ({ default: m.BatchDetailPage })))
const CreateBatchPage = lazy(() => import('../features/batches/pages').then((m) => ({ default: m.CreateBatchPage })))
const EditBatchPage = lazy(() => import('../features/batches/pages').then((m) => ({ default: m.EditBatchPage })))

// Inventory Counting
const CountingDashboardPage = lazy(() => import('../features/inventory-counting/pages/CountingDashboardPage').then((m) => ({ default: m.CountingDashboardPage })))
const CountingListPage = lazy(() => import('../features/inventory-counting/pages/CountingListPage').then((m) => ({ default: m.CountingListPage })))
const CreateCountingPage = lazy(() => import('../features/inventory-counting/pages/CreateCountingPage').then((m) => ({ default: m.CreateCountingPage })))
const CountingDetailPage = lazy(() => import('../features/inventory-counting/pages/CountingDetailPage').then((m) => ({ default: m.CountingDetailPage })))
const CountingReviewPage = lazy(() => import('../features/inventory-counting/pages/CountingReviewPage').then((m) => ({ default: m.CountingReviewPage })))
const DiscrepancyReportPage = lazy(() => import('../features/inventory-counting/pages/DiscrepancyReportPage').then((m) => ({ default: m.DiscrepancyReportPage })))

// Enrichment module
const EnrichmentQueuePage = lazy(() => import('../features/enrichment/pages/EnrichmentQueuePage').then((m) => ({ default: m.EnrichmentQueuePage })))

// Parts Catalog module
const PartsCatalogPage = lazy(() => import('../features/parts-catalog/pages/PartsCatalogPage').then((m) => ({ default: m.PartsCatalogPage })))
const ArticleDetailPageCatalog = lazy(() => import('../features/parts-catalog/pages/ArticleDetailPage').then((m) => ({ default: m.ArticleDetailPage })))

// Vehicles module
const VehicleListPage = lazy(() => import('../features/vehicles/VehicleListPage').then((m) => ({ default: m.VehicleListPage })))
const VehicleDetailPage = lazy(() => import('../features/vehicles/VehicleDetailPage').then((m) => ({ default: m.VehicleDetailPage })))
const VehicleForm = lazy(() => import('../features/vehicles/VehicleForm').then((m) => ({ default: m.VehicleForm })))

// Company module
const CompanyOnboardingPage = lazy(() => import('../features/company/CompanyOnboardingPage').then((m) => ({ default: m.CompanyOnboardingPage })))

// Settings module
const SettingsPage = lazy(() => import('../features/settings/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const SetupChecklistPage = lazy(() => import('../features/settings/pages/SetupChecklistPage').then((m) => ({ default: m.SetupChecklistPage })))
const UsersPage = lazy(() => import('../features/settings/UsersPage').then((m) => ({ default: m.UsersPage })))
const RolesPage = lazy(() => import('../features/settings/RolesPage').then((m) => ({ default: m.RolesPage })))
const CompanyPage = lazy(() => import('../features/settings/CompanyPage').then((m) => ({ default: m.CompanyPage })))
const TaxSettingsPage = lazy(() => import('../features/settings/TaxSettingsPage').then((m) => ({ default: m.TaxSettingsPage })))
const LocationsPage = lazy(() => import('../features/settings/LocationsPage').then((m) => ({ default: m.LocationsPage })))
const InventorySettings = lazy(() => import('../features/settings/components/InventorySettings').then((m) => ({ default: m.InventorySettings })))
const UnitsSettingsPage = lazy(() => import('../features/uom').then((m) => ({ default: m.UnitsSettingsPage })))
const PosRefundPoliciesPage = lazy(() => import('../features/settings/pages/PosRefundPoliciesPage').then((m) => ({ default: m.PosRefundPoliciesPage })))

// Finance module
const ChartOfAccountsPage = lazy(() => import('../features/finance/pages/ChartOfAccountsPage').then((m) => ({ default: m.ChartOfAccountsPage })))
const GeneralLedgerPage = lazy(() => import('../features/finance/pages/GeneralLedgerPage').then((m) => ({ default: m.GeneralLedgerPage })))
const TrialBalancePage = lazy(() => import('../features/finance/pages/TrialBalancePage').then((m) => ({ default: m.TrialBalancePage })))
const ProfitLossPage = lazy(() => import('../features/finance/pages/ProfitLossPage').then((m) => ({ default: m.ProfitLossPage })))
const BalanceSheetPage = lazy(() => import('../features/finance/pages/BalanceSheetPage').then((m) => ({ default: m.BalanceSheetPage })))
const AgedReceivablesPage = lazy(() => import('../features/finance/pages/AgedReceivablesPage').then((m) => ({ default: m.AgedReceivablesPage })))
const AgedPayablesPage = lazy(() => import('../features/finance/pages/AgedPayablesPage').then((m) => ({ default: m.AgedPayablesPage })))
const JournalEntryListPage = lazy(() => import('../features/finance/pages/JournalEntryListPage').then((m) => ({ default: m.JournalEntryListPage })))
const JournalEntryForm = lazy(() => import('../features/finance/pages/JournalEntryForm').then((m) => ({ default: m.JournalEntryForm })))
const JournalEntryDetailPage = lazy(() => import('../features/finance/pages/JournalEntryDetailPage').then((m) => ({ default: m.JournalEntryDetailPage })))

// VAT Reporting module
const VatPeriodsPage = lazy(() => import('../features/vat-reporting/pages/VatPeriodsPage').then((m) => ({ default: m.VatPeriodsPage })))
const VatReportPage = lazy(() => import('../features/vat-reporting/pages/VatReportPage').then((m) => ({ default: m.VatReportPage })))

// Pricing module
const PriceListListPage = lazy(() => import('../features/pricing/PriceListListPage').then((m) => ({ default: m.PriceListListPage })))
const PriceListDetailPage = lazy(() => import('../features/pricing/PriceListDetailPage').then((m) => ({ default: m.PriceListDetailPage })))
const PriceListForm = lazy(() => import('../features/pricing/PriceListForm').then((m) => ({ default: m.PriceListForm })))

// Import module
const ImportDashboardPage = lazy(() => import('../features/import/pages/ImportDashboardPage').then((m) => ({ default: m.ImportDashboardPage })))
const ImportWizardPage = lazy(() => import('../features/import/pages/ImportWizardPage').then((m) => ({ default: m.ImportWizardPage })))
const ImportHistoryPage = lazy(() => import('../features/import/pages/ImportHistoryPage').then((m) => ({ default: m.ImportHistoryPage })))

// Services module
const ServiceListPage = lazy(() => import('../features/services/ServiceListPage').then((m) => ({ default: m.ServiceListPage })))
const ServiceDetailPage = lazy(() => import('../features/services/ServiceDetailPage').then((m) => ({ default: m.ServiceDetailPage })))
const ServiceForm = lazy(() => import('../features/services/ServiceForm').then((m) => ({ default: m.ServiceForm })))
const ServiceCategoryListPage = lazy(() => import('../features/services/ServiceCategoryListPage').then((m) => ({ default: m.ServiceCategoryListPage })))
const WorkshopBundleListPage = lazy(() => import('../features/workshop-bundles/pages/BundleListPage').then((m) => ({ default: m.BundleListPage })))
const WorkshopBundleCreatePage = lazy(() => import('../features/workshop-bundles/pages/BundleCreatePage').then((m) => ({ default: m.BundleCreatePage })))
const WorkshopBundleDetailPage = lazy(() => import('../features/workshop-bundles/pages/BundleDetailPage').then((m) => ({ default: m.BundleDetailPage })))

// Opening Balances module
const OpeningBalancesPage = lazy(() => import('../features/opening-balances/pages/OpeningBalancesPage').then((m) => ({ default: m.OpeningBalancesPage })))
const OpeningBalanceWizardPage = lazy(() => import('../features/opening-balances/pages/OpeningBalanceWizardPage').then((m) => ({ default: m.OpeningBalanceWizardPage })))

// Expenses module
const ExpenseListPage = lazy(() => import('../features/expenses/pages/ExpenseListPage').then((m) => ({ default: m.ExpenseListPage })))
const ExpenseFormPage = lazy(() => import('../features/expenses/pages/ExpenseFormPage').then((m) => ({ default: m.ExpenseFormPage })))
const ExpenseDetailPage = lazy(() => import('../features/expenses/pages/ExpenseDetailPage').then((m) => ({ default: m.ExpenseDetailPage })))
const ExpenseCategoryPage = lazy(() => import('../features/expenses/pages/ExpenseCategoryPage').then((m) => ({ default: m.ExpenseCategoryPage })))

// Compliance module
const FraudSettingsPage = lazy(() => import('../features/compliance/pages/FraudSettingsPage').then((m) => ({ default: m.FraudSettingsPage })))
const FraudAlertsPage = lazy(() => import('../features/compliance/pages/FraudAlertsPage').then((m) => ({ default: m.FraudAlertsPage })))
const ComplianceExportPage = lazy(() => import('../features/compliance/pages/ComplianceExportPage').then((m) => ({ default: m.ComplianceExportPage })))

// Catalog module (Composite Items & Modifiers)
const CompositeItemListPage = lazy(() => import('../features/catalog').then((m) => ({ default: m.CompositeItemListPage })))
const CompositeItemFormPage = lazy(() => import('../features/catalog').then((m) => ({ default: m.CompositeItemFormPage })))
const ModifierGroupListPage = lazy(() => import('../features/catalog').then((m) => ({ default: m.ModifierGroupListPage })))
const ModifierGroupFormPage = lazy(() => import('../features/catalog').then((m) => ({ default: m.ModifierGroupFormPage })))

// Menu module
const MenuListPage = lazy(() => import('../features/menu').then((m) => ({ default: m.MenuListPage })))
const MenuFormPage = lazy(() => import('../features/menu').then((m) => ({ default: m.MenuFormPage })))

// Promotions module
const PromotionListPage = lazy(() => import('../features/promotions').then((m) => ({ default: m.PromotionListPage })))
const PromotionFormPage = lazy(() => import('../features/promotions').then((m) => ({ default: m.PromotionFormPage })))
const CouponListPage = lazy(() => import('../features/coupons').then((m) => ({ default: m.CouponListPage })))
const CouponFormPage = lazy(() => import('../features/coupons').then((m) => ({ default: m.CouponFormPage })))

// Parapharmacy module
const IngredientListPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.IngredientListPage })))
const IngredientFormPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.IngredientFormPage })))
const CertificationListPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.CertificationListPage })))
const CertificationFormPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.CertificationFormPage })))
const HealthClaimListPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.HealthClaimListPage })))
const HealthClaimFormPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.HealthClaimFormPage })))
const KeyComponentListPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.KeyComponentListPage })))
const KeyComponentFormPage = lazy(() => import('../features/parapharmacy/pages').then((m) => ({ default: m.KeyComponentFormPage })))

// Workshop/Technician module (HRM-lite)
const WorkshopTechniciansTeamListPage = lazy(() => import('../features/workshop-technicians/pages/TeamListPage').then((m) => ({ default: m.TeamListPage })))
const WorkshopTechnicianDetailPage = lazy(() => import('../features/workshop-technicians/pages/TechnicianDetailPage').then((m) => ({ default: m.TechnicianDetailPage })))
const WorkshopPayrollExportPage = lazy(() => import('../features/workshop-technicians/pages/PayrollExportPage').then((m) => ({ default: m.PayrollExportPage })))

// Workshop/WorkOrder module (Spec B)
const WorkshopWorkOrderListPage = lazy(() => import('../features/workshop-work-orders/pages/WorkOrderListPage').then((m) => ({ default: m.WorkOrderListPage })))
const WorkshopWorkOrderDetailPage = lazy(() => import('../features/workshop-work-orders/pages/WorkOrderDetailPage').then((m) => ({ default: m.WorkOrderDetailPage })))
const WorkshopWorkOrderCreatePage = lazy(() => import('../features/workshop-work-orders/pages/WorkOrderCreatePage').then((m) => ({ default: m.WorkOrderCreatePage })))

// Scheduling module (Spec D)
const SchedulerPage = lazy(() => import('../features/scheduling/pages/SchedulerPage').then((m) => ({ default: m.SchedulerPage })))
const SchedulingAppointmentDetailPage = lazy(() => import('../features/scheduling/pages/AppointmentDetailPage').then((m) => ({ default: m.AppointmentDetailPage })))
const SchedulingCapacityReportPage = lazy(() => import('../features/scheduling/pages/CapacityReportPage').then((m) => ({ default: m.CapacityReportPage })))

// CRM module
const CrmCompanyListPage = lazy(() => import('../features/crm/pages/CompanyListPage').then((m) => ({ default: m.CompanyListPage })))
const CrmContactListPage = lazy(() => import('../features/crm/pages/ContactListPage').then((m) => ({ default: m.ContactListPage })))
const CrmContactFormPage = lazy(() => import('../features/crm/pages/ContactFormPage').then((m) => ({ default: m.ContactFormPage })))
const CrmContactDetailPage = lazy(() => import('../features/crm/pages/ContactDetailPage').then((m) => ({ default: m.ContactDetailPage })))

// POS module
const POSTerminalsPage = lazy(() => import('../pages/POS/Terminals').then((m) => ({ default: m.TerminalsPage })))
const POSTransactionsPage = lazy(() => import('../pages/POS/POSTransactions').then((m) => ({ default: m.POSTransactions })))
const POSShiftsPage = lazy(() => import('../pages/POS/POSShiftsDashboard').then((m) => ({ default: m.POSShiftsDashboard })))
const ShiftHistoryPage = lazy(() => import('../features/pos/pages/ShiftHistoryPage/ShiftHistoryPage').then((m) => ({ default: m.ShiftHistoryPage })))
const ZReportListPage = lazy(() => import('../features/pos/pages/ZReportListPage/ZReportListPage').then((m) => ({ default: m.ZReportListPage })))
const AnalyticsDashboardPage = lazy(() => import('../features/pos/pages/AnalyticsDashboardPage').then((m) => ({ default: m.AnalyticsDashboardPage })))
const ZReportDetailPage = lazy(() => import('../features/pos/pages/ZReportDetailPage/ZReportDetailPage').then((m) => ({ default: m.ZReportDetailPage })))
const ReceiptSearchPage = lazy(() => import('../features/pos/pages/ReceiptSearchPage/ReceiptSearchPage').then((m) => ({ default: m.ReceiptSearchPage })))
const OrdersPage = lazy(() => import('../features/pos/pages/OrdersPage').then((m) => ({ default: m.OrdersPage })))
const KitchenDisplayPage = lazy(() => import('../features/pos/pages/KitchenDisplayPage/KitchenDisplayPage').then((m) => ({ default: m.KitchenDisplayPage })))
const TableManagementPage = lazy(() => import('../features/pos/pages/TableManagementPage/TableManagementPage').then((m) => ({ default: m.TableManagementPage })))

// Loyalty module
const LoyaltyProgramListPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.ProgramListPage })))
const LoyaltyProgramFormPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.ProgramFormPage })))
const LoyaltyProgramDetailPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.ProgramDetailPage })))
const LoyaltyMemberListPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.MemberListPage })))
const LoyaltyMemberFormPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.MemberFormPage })))
const LoyaltyMemberDetailPage = lazy(() => import('../features/loyalty').then((m) => ({ default: m.MemberDetailPage })))
const VoucherListPage = lazy(() => import('../features/vouchers/pages/VoucherListPage').then((m) => ({ default: m.VoucherListPage })))
const VoucherDetailPage = lazy(() => import('../features/vouchers/pages/VoucherDetailPage').then((m) => ({ default: m.VoucherDetailPage })))

// Legal pages
const PrivacyPolicyPage = lazy(() => import('../pages/legal/PrivacyPolicyPage').then((m) => ({ default: m.PrivacyPolicyPage })))
const TermsOfServicePage = lazy(() => import('../pages/legal/TermsOfServicePage').then((m) => ({ default: m.TermsOfServicePage })))

// Progression module
const GrowthPage = lazy(() => import('../features/progression').then((m) => ({ default: m.GrowthPage })))
const ProgressionModulesPage = lazy(() => import('../features/progression').then((m) => ({ default: m.ModulesPage })))

function SuspenseWrapper({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<LoadingSpinner fullScreen />}>
      {children}
    </Suspense>
  )
}

export function AppRoutes() {
  return (
    <Routes>
      {/* Public routes */}
      <Route
        path="/login"
        element={
          <SuspenseWrapper>
            <LoginPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/register"
        element={
          <SuspenseWrapper>
            <RegisterPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/verify-email"
        element={
          <SuspenseWrapper>
            <VerifyEmailPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/forgot-password"
        element={
          <SuspenseWrapper>
            <ForgotPasswordPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/reset-password"
        element={
          <SuspenseWrapper>
            <ResetPasswordPage />
          </SuspenseWrapper>
        }
      />

      {/* Legal pages (public) */}
      <Route
        path="/privacy"
        element={
          <SuspenseWrapper>
            <PrivacyPolicyPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/terms"
        element={
          <SuspenseWrapper>
            <TermsOfServicePage />
          </SuspenseWrapper>
        }
      />

      {/* Admin routes */}
      <Route
        path="/admin/login"
        element={
          <SuspenseWrapper>
            <AdminLoginPage />
          </SuspenseWrapper>
        }
      />
      <Route
        path="/admin"
        element={
          <SuspenseWrapper>
            <RequireAdminAuth>
              <AdminLayout />
            </RequireAdminAuth>
          </SuspenseWrapper>
        }
      >
        <Route index element={<Navigate to="/admin/dashboard" replace />} />
        <Route
          path="dashboard"
          element={
            <SuspenseWrapper>
              <AdminDashboardPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="tenants"
          element={
            <SuspenseWrapper>
              <TenantsPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="company-owners"
          element={
            <SuspenseWrapper>
              <CompanyOwnersPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="audit-logs"
          element={
            <SuspenseWrapper>
              <AuditLogsPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="billing"
          element={
            <SuspenseWrapper>
              <BillingDashboardPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="billing/subscriptions"
          element={
            <SuspenseWrapper>
              <AdminSubscriptionsPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="billing/invoices"
          element={
            <SuspenseWrapper>
              <AdminInvoicesPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="billing/payments"
          element={
            <SuspenseWrapper>
              <AdminPaymentsPage />
            </SuspenseWrapper>
          }
        />
        <Route
          path="monitoring"
          element={
            <SuspenseWrapper>
              <AdminMonitoringPage />
            </SuspenseWrapper>
          }
        />
      </Route>

      {/* Company Onboarding (full-page without layout) */}
      <Route
        path="/company-onboarding"
        element={
          <RequireAuth>
            <SuspenseWrapper>
              <CompanyOnboardingPage />
            </SuspenseWrapper>
          </RequireAuth>
        }
      />

      {/* Protected routes */}
      <Route
        path="/"
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        {/* Redirect root to dashboard */}
        <Route index element={<Navigate to="/dashboard" replace />} />

        {/* Dashboard */}
        <Route
          path="dashboard"
          element={
            <SuspenseWrapper>
              <Dashboard />
            </SuspenseWrapper>
          }
        />

        {/* Sales Module */}
        <Route path="sales">
          <Route index element={<Navigate to="/sales/customers" replace />} />

          {/* Customers (filtered partners) */}
          <Route
            path="customers"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <CustomerListPage partnerType="customer" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="customers/new"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <CustomerForm partnerType="customer" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="customers/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <CustomerDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="customers/:id/edit"
            element={
              <RequirePermission permission="sales.edit">
                <SuspenseWrapper>
                  <CustomerForm partnerType="customer" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Quotes */}
          <Route
            path="quotes"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <DocumentListPage documentType="quote" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="quotes/new"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="quote" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="quotes/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <QuoteDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="quotes/:id/edit"
            element={
              <RequirePermission permission="sales.edit">
                <SuspenseWrapper>
                  <DocumentForm documentType="quote" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Sales Orders */}
          <Route
            path="orders"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <DocumentListPage documentType="sales_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/new"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="sales_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <SalesOrderDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/:id/edit"
            element={
              <RequirePermission permission="sales.edit">
                <SuspenseWrapper>
                  <DocumentForm documentType="sales_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Invoices */}
          <Route
            path="invoices"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <DocumentListPage documentType="invoice" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="invoices/new"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="invoice" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="invoices/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <InvoiceDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="invoices/:id/edit"
            element={
              <RequirePermission permission="sales.edit">
                <SuspenseWrapper>
                  <DocumentForm documentType="invoice" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Credit Notes */}
          <Route
            path="credit-notes"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <DocumentListPage documentType="credit_note" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="credit-notes/new"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="credit_note" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="credit-notes/create"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <CreateCreditNotePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="credit-notes/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <CreditNoteDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Return Notes */}
          <Route
            path="return-notes"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <ReturnNoteListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="return-notes/create"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <CreateReturnNotePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="return-notes/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <ReturnNoteDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Purchases Module */}
        <Route path="purchases">
          <Route index element={<Navigate to="/purchases/suppliers" replace />} />

          {/* Suppliers (filtered partners) */}
          <Route
            path="suppliers"
            element={
              <RequirePermission moduleKey="purchases">
                <SuspenseWrapper>
                  <CustomerListPage partnerType="supplier" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="suppliers/new"
            element={
              <RequirePermission permission="purchases.create">
                <SuspenseWrapper>
                  <CustomerForm partnerType="supplier" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="suppliers/:id"
            element={
              <RequirePermission moduleKey="purchases">
                <SuspenseWrapper>
                  <CustomerDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="suppliers/:id/edit"
            element={
              <RequirePermission permission="purchases.edit">
                <SuspenseWrapper>
                  <CustomerForm partnerType="supplier" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Purchase Orders */}
          <Route
            path="orders"
            element={
              <RequirePermission moduleKey="purchases">
                <SuspenseWrapper>
                  <DocumentListPage documentType="purchase_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/new"
            element={
              <RequirePermission permission="purchases.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="purchase_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/:id"
            element={
              <RequirePermission moduleKey="purchases">
                <SuspenseWrapper>
                  <PurchaseOrderDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="orders/:id/edit"
            element={
              <RequirePermission permission="purchases.edit">
                <SuspenseWrapper>
                  <DocumentForm documentType="purchase_order" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Goods Receipts */}
          <Route
            path="receipts"
            element={
              <RequirePermission moduleKey="purchases">
                <SuspenseWrapper>
                  <GoodsReceiptListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Inventory Module */}
        <Route path="inventory">
          <Route index element={
            <RequirePermission moduleKey="inventory">
              <SuspenseWrapper>
                <InventoryHubPage />
              </SuspenseWrapper>
            </RequirePermission>
          } />

          <Route
            path="products"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <ProductListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="products/new"
            element={
              <RequirePermission permission="inventory.create">
                <SuspenseWrapper>
                  <ProductForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="products/:id"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <ProductDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="products/:id/edit"
            element={
              <RequirePermission permission="inventory.edit">
                <SuspenseWrapper>
                  <ProductForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="stock"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <StockLevelsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="movements"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <StockMovementsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="categories"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CategoriesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Batches */}
          <Route
            path="batches"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <BatchListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="batches/new"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CreateBatchPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="batches/:uuid/edit"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <EditBatchPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="batches/:uuid"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <BatchDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Delivery Notes */}
          <Route
            path="delivery-notes"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <DocumentListPage documentType="delivery_note" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="delivery-notes/new"
            element={
              <RequirePermission permission="inventory.create">
                <SuspenseWrapper>
                  <DocumentForm documentType="delivery_note" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="delivery-notes/consolidate"
            element={
              <RequirePermission permission="sales.create">
                <SuspenseWrapper>
                  <DeliveryNoteConsolidationPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="delivery-notes/:id"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <DeliveryNoteDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Return Notes */}
          <Route
            path="return-notes"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <DocumentListPage documentType="return_note" />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="return-notes/new"
            element={
              <RequirePermission permission="inventory.create">
                <SuspenseWrapper>
                  <CreateReturnNotePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="return-notes/:id"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <ReturnNoteDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Inventory Counting */}
          <Route
            path="counting"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CountingDashboardPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="counting/list"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CountingListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="counting/create"
            element={
              <RequirePermission permission="inventory.create">
                <SuspenseWrapper>
                  <CreateCountingPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="counting/:id"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CountingDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="counting/:id/review"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <CountingReviewPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="counting/:id/report"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <DiscrepancyReportPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="enrichment-results"
            element={
              <RequirePermission moduleKey="inventory">
                <SuspenseWrapper>
                  <EnrichmentQueuePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Parts Catalog */}
        <Route
          path="parts-catalog"
          element={
            <ModuleGuard module="PlatformIntegration">
              <RequirePermission moduleKey="parts_catalog">
                <SuspenseWrapper>
                  <PartsCatalogPage />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />
        <Route
          path="parts-catalog/:articleId"
          element={
            <ModuleGuard module="PlatformIntegration">
              <RequirePermission moduleKey="parts_catalog">
                <SuspenseWrapper>
                  <ArticleDetailPageCatalog />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />

        {/* Vehicles */}
        <Route
          path="vehicles"
          element={
            <ModuleGuard module="Vehicle">
              <RequirePermission moduleKey="vehicles">
                <SuspenseWrapper>
                  <VehicleListPage />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />
        <Route
          path="vehicles/new"
          element={
            <ModuleGuard module="Vehicle">
              <RequirePermission permission="vehicles.create">
                <SuspenseWrapper>
                  <VehicleForm />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />
        <Route
          path="vehicles/:id"
          element={
            <ModuleGuard module="Vehicle">
              <RequirePermission moduleKey="vehicles">
                <SuspenseWrapper>
                  <VehicleDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />
        <Route
          path="vehicles/:id/edit"
          element={
            <ModuleGuard module="Vehicle">
              <RequirePermission permission="vehicles.edit">
                <SuspenseWrapper>
                  <VehicleForm />
                </SuspenseWrapper>
              </RequirePermission>
            </ModuleGuard>
          }
        />

        {/* Services Module */}
        <Route path="services">
          <Route
            index
            element={
              <ModuleGuard module="Workshop">
                <RequirePermission moduleKey="services">
                  <SuspenseWrapper>
                    <ServiceListPage />
                  </SuspenseWrapper>
                </RequirePermission>
              </ModuleGuard>
            }
          />
          <Route
            path="new"
            element={
              <ModuleGuard module="Workshop">
                <RequirePermission permission="services.create">
                  <SuspenseWrapper>
                    <ServiceForm />
                  </SuspenseWrapper>
                </RequirePermission>
              </ModuleGuard>
            }
          />
          <Route
            path="categories"
            element={
              <ModuleGuard module="Workshop">
                <RequirePermission moduleKey="services">
                  <SuspenseWrapper>
                    <ServiceCategoryListPage />
                  </SuspenseWrapper>
                </RequirePermission>
              </ModuleGuard>
            }
          />
          <Route
            path=":id"
            element={
              <ModuleGuard module="Workshop">
                <RequirePermission moduleKey="services">
                  <SuspenseWrapper>
                    <ServiceDetailPage />
                  </SuspenseWrapper>
                </RequirePermission>
              </ModuleGuard>
            }
          />
          <Route
            path=":id/edit"
            element={
              <ModuleGuard module="Workshop">
                <RequirePermission permission="services.edit">
                  <SuspenseWrapper>
                    <ServiceForm />
                  </SuspenseWrapper>
                </RequirePermission>
              </ModuleGuard>
            }
          />
        </Route>

        {/* Workshop Work Orders Module (Spec B) */}
        <Route path="workshop/work-orders">
          <Route
            index
            element={
              <RequirePermission permission="work-orders.view">
                <SuspenseWrapper>
                  <WorkshopWorkOrderListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="new"
            element={
              <RequirePermission permission="work-orders.create">
                <SuspenseWrapper>
                  <WorkshopWorkOrderCreatePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id"
            element={
              <RequirePermission permission="work-orders.view">
                <SuspenseWrapper>
                  <WorkshopWorkOrderDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Scheduling Module (Spec D) */}
        <Route path="scheduling">
          <Route
            index
            element={
              <RequirePermission permission="scheduling.appointments.view">
                <SuspenseWrapper>
                  <SchedulerPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="appointments/:id"
            element={
              <RequirePermission permission="scheduling.appointments.view">
                <SuspenseWrapper>
                  <SchedulingAppointmentDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="capacity"
            element={
              <RequirePermission permission="scheduling.appointments.view">
                <SuspenseWrapper>
                  <SchedulingCapacityReportPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Workshop Service Bundles Module */}
        <Route path="workshop/bundles">
          <Route
            index
            element={
              <RequirePermission permission="workshop-bundles.view">
                <SuspenseWrapper>
                  <WorkshopBundleListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="new"
            element={
              <RequirePermission permission="workshop-bundles.manage">
                <SuspenseWrapper>
                  <WorkshopBundleCreatePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id"
            element={
              <RequirePermission permission="workshop-bundles.view">
                <SuspenseWrapper>
                  <WorkshopBundleDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Expenses Module */}
        <Route path="expenses">
          <Route
            index
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <ExpenseListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="new"
            element={
              <RequirePermission permission="treasury.create">
                <SuspenseWrapper>
                  <ExpenseFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="categories"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <ExpenseCategoryPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <ExpenseFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id/view"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <ExpenseDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id/edit"
            element={
              <RequirePermission permission="treasury.edit">
                <SuspenseWrapper>
                  <ExpenseFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Treasury Module */}
        <Route path="treasury">
          <Route index element={<Navigate to="/treasury/payments" replace />} />

          <Route
            path="payments"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <PaymentListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="payments/new"
            element={
              <RequirePermission permission="treasury.create">
                <SuspenseWrapper>
                  <PaymentForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="payments/:id"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <PaymentDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="instruments"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <InstrumentListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="instruments/:id"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <InstrumentDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="payment-methods"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <PaymentMethodsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="repositories"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <RepositoryListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="repositories/:id"
            element={
              <RequirePermission moduleKey="treasury">
                <SuspenseWrapper>
                  <RepositoryDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="reconciliation"
            element={
              <RequirePermission permission="repositories.manage">
                <SuspenseWrapper>
                  <BankReconciliationPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Withholding Certificates */}
          <Route
            path="withholding-certificates"
            element={
              <RequirePermission permission="withholding.view">
                <SuspenseWrapper>
                  <WithholdingCertificatesList />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="withholding-certificates/:id"
            element={
              <RequirePermission permission="withholding.view">
                <SuspenseWrapper>
                  <WithholdingCertificateDetail />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Sales Withholding Tracking */}
          <Route
            path="sales-withholding-tracking"
            element={
              <RequirePermission permission="withholding.view">
                <SuspenseWrapper>
                  <SalesWithholdingTrackingPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Reports - redirect to Finance hub */}
        <Route path="reports" element={<Navigate to="/finance" replace />} />

        {/* Marketing Hub */}
        <Route
          path="marketing"
          element={
            <SuspenseWrapper>
              <MarketingHubPage />
            </SuspenseWrapper>
          }
        />

        {/* Finance Module */}
        <Route path="finance">
          <Route index element={
            <RequirePermission moduleKey="finance">
              <SuspenseWrapper>
                <FinanceHubPage />
              </SuspenseWrapper>
            </RequirePermission>
          } />
          <Route
            path="chart-of-accounts"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <ChartOfAccountsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="ledger"
            element={
              <RequirePermission permission="journal.view">
                <SuspenseWrapper>
                  <GeneralLedgerPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="trial-balance"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <TrialBalancePage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="profit-loss"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <ProfitLossPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="balance-sheet"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <BalanceSheetPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="aged-receivables"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <AgedReceivablesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="aged-payables"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <AgedPayablesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="journal-entries"
            element={
              <RequirePermission permission="journal.view">
                <SuspenseWrapper>
                  <JournalEntryListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="journal-entries/create"
            element={
              <RequirePermission permission="journal.create">
                <SuspenseWrapper>
                  <JournalEntryForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="journal-entries/:id"
            element={
              <RequirePermission permission="journal.view">
                <SuspenseWrapper>
                  <JournalEntryDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="vat-periods"
            element={
              <RequirePermission moduleKey="reports">
                <SuspenseWrapper>
                  <VatPeriodsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="vat-report/:id"
            element={
              <RequirePermission moduleKey="reports">
                <SuspenseWrapper>
                  <VatReportPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Pricing Module */}
        <Route path="pricing">
          <Route index element={<Navigate to="/pricing/price-lists" replace />} />
          <Route
            path="price-lists"
            element={
              <RequirePermission permission="pricing.view">
                <SuspenseWrapper>
                  <PriceListListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="price-lists/new"
            element={
              <RequirePermission permission="pricing.manage">
                <SuspenseWrapper>
                  <PriceListForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="price-lists/:id"
            element={
              <RequirePermission permission="pricing.view">
                <SuspenseWrapper>
                  <PriceListDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="price-lists/:id/edit"
            element={
              <RequirePermission permission="pricing.manage">
                <SuspenseWrapper>
                  <PriceListForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Settings */}
        <Route path="settings">
          <Route
            index
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <SettingsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="users"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <UsersPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="roles"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <RolesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="company"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <CompanyPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="tax"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <TaxSettingsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="chart-of-accounts"
            element={
              <RequirePermission permission="accounts.view">
                <SuspenseWrapper>
                  <ChartOfAccountsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="locations"
            element={
              <RequirePermission permission="inventory.view">
                <SuspenseWrapper>
                  <LocationsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="inventory"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <InventorySettings />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="pos-refund-policies"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <PosRefundPoliciesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="units"
            element={
              <RequirePermission permission="uom.view">
                <SuspenseWrapper>
                  <UnitsSettingsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Import Wizard */}
          <Route
            path="import"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <ImportDashboardPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="import/history"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <ImportHistoryPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="import/:type"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <ImportWizardPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Opening Balances */}
          <Route
            path="opening-balances"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <OpeningBalancesPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="opening-balances/:type"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <OpeningBalanceWizardPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          {/* Compliance */}
          {/* Setup Checklist */}
          <Route
            path="setup"
            element={
              <SuspenseWrapper>
                <SetupChecklistPage />
              </SuspenseWrapper>
            }
          />

          <Route
            path="compliance/fraud-settings"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <FraudSettingsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="compliance/fraud-alerts"
            element={
              <RequirePermission moduleKey="settings">
                <SuspenseWrapper>
                  <FraudAlertsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="compliance/export"
            element={
              <RequirePermission moduleKey="pos">
                <SuspenseWrapper>
                  <ComplianceExportPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Catalog Module (Composite Items & Modifiers) */}
        <Route path="catalog">
          <Route
            path="composite-items"
            element={
              <RequirePermission permission="composite-items.view">
                <SuspenseWrapper>
                  <CompositeItemListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="composite-items/new"
            element={
              <RequirePermission permission="composite-items.create">
                <SuspenseWrapper>
                  <CompositeItemFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="composite-items/:id/edit"
            element={
              <RequirePermission permission="composite-items.view">
                <SuspenseWrapper>
                  <CompositeItemFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="modifier-groups"
            element={
              <RequirePermission permission="modifier-groups.view">
                <SuspenseWrapper>
                  <ModifierGroupListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="modifier-groups/new"
            element={
              <RequirePermission permission="modifier-groups.manage">
                <SuspenseWrapper>
                  <ModifierGroupFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="modifier-groups/:id/edit"
            element={
              <RequirePermission permission="modifier-groups.manage">
                <SuspenseWrapper>
                  <ModifierGroupFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="menus"
            element={
              <RequirePermission permission="composite-items.view">
                <SuspenseWrapper>
                  <MenuListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="menus/new"
            element={
              <RequirePermission permission="composite-items.create">
                <SuspenseWrapper>
                  <MenuFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="menus/:id/edit"
            element={
              <RequirePermission permission="composite-items.view">
                <SuspenseWrapper>
                  <MenuFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Parapharmacy Module */}
        <Route path="parapharmacy">
          <Route
            path="ingredients"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <IngredientListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="ingredients/new"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <IngredientFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="ingredients/:id"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <IngredientFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="certifications"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <CertificationListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="certifications/new"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <CertificationFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="certifications/:id"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <CertificationFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="health-claims"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <HealthClaimListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="health-claims/new"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <HealthClaimFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="health-claims/:id"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <HealthClaimFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />

          <Route
            path="key-components"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <KeyComponentListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="key-components/new"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <KeyComponentFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="key-components/:id"
            element={
              <RequirePermission permission="settings.manage">
                <SuspenseWrapper>
                  <KeyComponentFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* CRM Module */}
        <Route path="crm">
          <Route index element={<Navigate to="/crm/contacts" replace />} />
          <Route
            path="companies"
            element={
              <RequirePermission moduleKey="partners">
                <SuspenseWrapper>
                  <CrmCompanyListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="contacts"
            element={
              <RequirePermission moduleKey="contacts">
                <SuspenseWrapper>
                  <CrmContactListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="contacts/new"
            element={
              <RequirePermission permission="contacts.create">
                <SuspenseWrapper>
                  <CrmContactFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="contacts/:id"
            element={
              <RequirePermission moduleKey="contacts">
                <SuspenseWrapper>
                  <CrmContactDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="contacts/:id/edit"
            element={
              <RequirePermission permission="contacts.update">
                <SuspenseWrapper>
                  <CrmContactFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Workshop module — Technician profiles (HRM-lite) */}
        <Route path="workshop">
          <Route index element={<Navigate to="/workshop/technicians" replace />} />
          <Route
            path="technicians"
            element={
              <RequirePermission permission="workshop.technicians.view">
                <SuspenseWrapper>
                  <WorkshopTechniciansTeamListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="technicians/:id"
            element={
              <RequirePermission permission="workshop.technicians.view">
                <SuspenseWrapper>
                  <WorkshopTechnicianDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="payroll-exports"
            element={
              <RequirePermission permission="workshop.payroll.view">
                <SuspenseWrapper>
                  <WorkshopPayrollExportPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* POS Module - Administrative Pages (Inside Layout) */}
        <Route path="pos">
          <Route index element={
            <RequirePermission moduleKey="pos">
              <SuspenseWrapper>
                <PosHubPage />
              </SuspenseWrapper>
            </RequirePermission>
          } />
          {/* Terminals - Admin page for managing POS terminals */}
          <Route
            path="terminals"
            element={
              <RequirePermission permission="pos.manage_terminals">
                <SuspenseWrapper>
                  <POSTerminalsPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Shift History */}
          <Route
            path="shift-history"
            element={
              <RequirePermission permission="pos.manage_shifts">
                <SuspenseWrapper>
                  <ShiftHistoryPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Z-Reports */}
          <Route
            path="z-reports"
            element={
              <RequirePermission permission="pos.view_reports">
                <SuspenseWrapper>
                  <ZReportListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="z-reports/:zNumber"
            element={
              <RequirePermission permission="pos.view_reports">
                <SuspenseWrapper>
                  <ZReportDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Analytics */}
          <Route
            path="analytics"
            element={
              <RequirePermission permission="pos.view_reports">
                <SuspenseWrapper>
                  <AnalyticsDashboardPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Receipt Search */}
          <Route
            path="receipts"
            element={
              <RequirePermission permission="pos.view_receipts">
                <SuspenseWrapper>
                  <ReceiptSearchPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Orders */}
          <Route
            path="orders"
            element={
              <RequirePermission permission="pos.operate_terminal">
                <SuspenseWrapper>
                  <OrdersPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Table Management */}
          <Route
            path="tables"
            element={
              <RequirePermission permission="pos.manage_tables">
                <SuspenseWrapper>
                  <TableManagementPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Promotions */}
          <Route
            path="promotions"
            element={
              <RequirePermission permission="promotions.view">
                <SuspenseWrapper>
                  <PromotionListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="promotions/new"
            element={
              <RequirePermission permission="promotions.manage">
                <SuspenseWrapper>
                  <PromotionFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="promotions/:id/edit"
            element={
              <RequirePermission permission="promotions.manage">
                <SuspenseWrapper>
                  <PromotionFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Coupons */}
          <Route
            path="coupons"
            element={
              <RequirePermission permission="coupons.view">
                <SuspenseWrapper>
                  <CouponListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="coupons/new"
            element={
              <RequirePermission permission="coupons.manage">
                <SuspenseWrapper>
                  <CouponFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="coupons/:id/edit"
            element={
              <RequirePermission permission="coupons.manage">
                <SuspenseWrapper>
                  <CouponFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Loyalty Programs */}
          <Route
            path="loyalty/programs"
            element={
              <RequirePermission permission="loyalty.view">
                <SuspenseWrapper>
                  <LoyaltyProgramListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/programs/new"
            element={
              <RequirePermission permission="loyalty.manage">
                <SuspenseWrapper>
                  <LoyaltyProgramFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/programs/:id"
            element={
              <RequirePermission permission="loyalty.view">
                <SuspenseWrapper>
                  <LoyaltyProgramDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/programs/:id/edit"
            element={
              <RequirePermission permission="loyalty.manage">
                <SuspenseWrapper>
                  <LoyaltyProgramFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Loyalty Members */}
          <Route
            path="loyalty/members"
            element={
              <RequirePermission permission="loyalty.view">
                <SuspenseWrapper>
                  <LoyaltyMemberListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/members/new"
            element={
              <RequirePermission permission="loyalty.manage">
                <SuspenseWrapper>
                  <LoyaltyMemberFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/members/:id"
            element={
              <RequirePermission permission="loyalty.view">
                <SuspenseWrapper>
                  <LoyaltyMemberDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="loyalty/members/:id/edit"
            element={
              <RequirePermission permission="loyalty.manage">
                <SuspenseWrapper>
                  <LoyaltyMemberFormPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          {/* Vouchers & Credits */}
          <Route
            path="vouchers"
            element={
              <RequirePermission moduleKey="pos">
                <SuspenseWrapper>
                  <VoucherListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="vouchers/:id"
            element={
              <RequirePermission moduleKey="pos">
                <SuspenseWrapper>
                  <VoucherDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
        </Route>

        {/* Progression Module */}
        <Route path="growth">
          <Route
            index
            element={
              <SuspenseWrapper>
                <GrowthPage />
              </SuspenseWrapper>
            }
          />
          <Route
            path="modules"
            element={
              <SuspenseWrapper>
                <ProgressionModulesPage />
              </SuspenseWrapper>
            }
          />
        </Route>

        {/* Legacy redirects for backward compatibility */}
        <Route path="partners" element={<Navigate to="/sales/customers" replace />} />
        <Route path="partners/*" element={<Navigate to="/sales/customers" replace />} />
        <Route path="documents" element={<Navigate to="/sales/invoices" replace />} />
        <Route path="documents/*" element={<Navigate to="/sales/invoices" replace />} />
        <Route path="products" element={<Navigate to="/inventory/products" replace />} />

        {/* Catch-all redirect */}
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Route>

      {/* POS Module - Fullscreen Transaction Interface (Outside Layout) */}
      <Route
        path="/pos/transactions"
        element={
          <RequireAuth>
            <RequirePermission permission="pos.operate_terminal">
              <SuspenseWrapper>
                <POSTransactionsPage />
              </SuspenseWrapper>
            </RequirePermission>
          </RequireAuth>
        }
      />
      <Route
        path="/pos/shifts"
        element={
          <RequireAuth>
            <RequirePermission permission="pos.manage_shifts">
              <SuspenseWrapper>
                <POSShiftsPage />
              </SuspenseWrapper>
            </RequirePermission>
          </RequireAuth>
        }
      />
      {/* KDS - Fullscreen Kitchen Display (Outside Layout) */}
      <Route
        path="/pos/kitchen"
        element={
          <RequireAuth>
            <RequirePermission permission="pos.operate_terminal">
              <SuspenseWrapper>
                <KitchenDisplayPage />
              </SuspenseWrapper>
            </RequirePermission>
          </RequireAuth>
        }
      />
    </Routes>
  )
}
