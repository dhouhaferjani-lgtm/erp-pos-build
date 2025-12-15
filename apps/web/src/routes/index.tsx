import { lazy, Suspense } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { Layout } from '../components/layout/Layout'
import { RequireAuth } from '../features/auth'
import { RequirePermission } from '../components/auth'
import { LoadingSpinner } from '../components/ui/LoadingSpinner'

// Lazy loaded pages
const LoginPage = lazy(() => import('../features/auth/LoginPage').then((m) => ({ default: m.LoginPage })))
const Dashboard = lazy(() => import('../features/dashboard/Dashboard').then((m) => ({ default: m.Dashboard })))

// Admin pages
const AdminLoginPage = lazy(() => import('../features/admin/pages/AdminLoginPage').then((m) => ({ default: m.AdminLoginPage })))
const AdminDashboardPage = lazy(() => import('../features/admin/pages/AdminDashboardPage').then((m) => ({ default: m.AdminDashboardPage })))
const TenantsPage = lazy(() => import('../features/admin/pages/TenantsPage').then((m) => ({ default: m.TenantsPage })))
const AuditLogsPage = lazy(() => import('../features/admin/pages/AuditLogsPage').then((m) => ({ default: m.AuditLogsPage })))
const AdminLayout = lazy(() => import('../features/admin/components/AdminLayout').then((m) => ({ default: m.AdminLayout })))
const RequireAdminAuth = lazy(() => import('../features/admin/components/RequireAdminAuth').then((m) => ({ default: m.RequireAdminAuth })))

// Sales module
const CustomerListPage = lazy(() => import('../features/partners/PartnerListPage').then((m) => ({ default: m.PartnerListPage })))
const CustomerDetailPage = lazy(() => import('../features/partners/PartnerDetailPage').then((m) => ({ default: m.PartnerDetailPage })))
const CustomerForm = lazy(() => import('../features/partners/PartnerForm').then((m) => ({ default: m.PartnerForm })))

// Documents - reusing for quotes/orders/invoices
const DocumentListPage = lazy(() => import('../features/documents/DocumentListPage').then((m) => ({ default: m.DocumentListPage })))
const DocumentDetailPage = lazy(() => import('../features/documents/DocumentDetailPage').then((m) => ({ default: m.DocumentDetailPage })))
const DocumentForm = lazy(() => import('../features/documents/DocumentForm').then((m) => ({ default: m.DocumentForm })))
const DeliveryNoteConsolidationPage = lazy(() => import('../features/documents/DeliveryNoteConsolidationPage').then((m) => ({ default: m.DeliveryNoteConsolidationPage })))

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
const BankReconciliationPage = lazy(() => import('../features/treasury/BankReconciliationPage').then((m) => ({ default: m.BankReconciliationPage })))

// Reports module
const ReportsPage = lazy(() => import('../features/reports/ReportsPage').then((m) => ({ default: m.ReportsPage })))

// Inventory module
const ProductListPage = lazy(() => import('../features/inventory/ProductListPage').then((m) => ({ default: m.ProductListPage })))
const ProductDetailPage = lazy(() => import('../features/inventory/ProductDetailPage').then((m) => ({ default: m.ProductDetailPage })))
const ProductForm = lazy(() => import('../features/inventory/ProductForm').then((m) => ({ default: m.ProductForm })))
const StockLevelsPage = lazy(() => import('../features/inventory/StockLevelsPage').then((m) => ({ default: m.StockLevelsPage })))
const StockMovementsPage = lazy(() => import('../features/inventory/StockMovementsPage').then((m) => ({ default: m.StockMovementsPage })))

// Inventory Counting
const CountingDashboardPage = lazy(() => import('../features/inventory-counting/pages/CountingDashboardPage').then((m) => ({ default: m.CountingDashboardPage })))
const CountingListPage = lazy(() => import('../features/inventory-counting/pages/CountingListPage').then((m) => ({ default: m.CountingListPage })))
const CreateCountingPage = lazy(() => import('../features/inventory-counting/pages/CreateCountingPage').then((m) => ({ default: m.CreateCountingPage })))
const CountingDetailPage = lazy(() => import('../features/inventory-counting/pages/CountingDetailPage').then((m) => ({ default: m.CountingDetailPage })))
const CountingReviewPage = lazy(() => import('../features/inventory-counting/pages/CountingReviewPage').then((m) => ({ default: m.CountingReviewPage })))
const DiscrepancyReportPage = lazy(() => import('../features/inventory-counting/pages/DiscrepancyReportPage').then((m) => ({ default: m.DiscrepancyReportPage })))

// Vehicles module
const VehicleListPage = lazy(() => import('../features/vehicles/VehicleListPage').then((m) => ({ default: m.VehicleListPage })))
const VehicleDetailPage = lazy(() => import('../features/vehicles/VehicleDetailPage').then((m) => ({ default: m.VehicleDetailPage })))
const VehicleForm = lazy(() => import('../features/vehicles/VehicleForm').then((m) => ({ default: m.VehicleForm })))

// Company module
const CompanyOnboardingPage = lazy(() => import('../features/company/CompanyOnboardingPage').then((m) => ({ default: m.CompanyOnboardingPage })))

// Settings module
const SettingsPage = lazy(() => import('../features/settings/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const UsersPage = lazy(() => import('../features/settings/UsersPage').then((m) => ({ default: m.UsersPage })))
const RolesPage = lazy(() => import('../features/settings/RolesPage').then((m) => ({ default: m.RolesPage })))
const CompanyPage = lazy(() => import('../features/settings/CompanyPage').then((m) => ({ default: m.CompanyPage })))
const LocationsPage = lazy(() => import('../features/settings/LocationsPage').then((m) => ({ default: m.LocationsPage })))

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

// Opening Balances module
const OpeningBalancesPage = lazy(() => import('../features/opening-balances/pages/OpeningBalancesPage').then((m) => ({ default: m.OpeningBalancesPage })))
const OpeningBalanceWizardPage = lazy(() => import('../features/opening-balances/pages/OpeningBalanceWizardPage').then((m) => ({ default: m.OpeningBalanceWizardPage })))

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
          path="audit-logs"
          element={
            <SuspenseWrapper>
              <AuditLogsPage />
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
                  <DocumentDetailPage />
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
                  <DocumentDetailPage />
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
                  <DocumentDetailPage />
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
            path="credit-notes/:id"
            element={
              <RequirePermission moduleKey="sales">
                <SuspenseWrapper>
                  <DocumentDetailPage />
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
                  <DocumentDetailPage />
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
          <Route index element={<Navigate to="/inventory/products" replace />} />

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
                  <DocumentDetailPage />
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
        </Route>

        {/* Vehicles */}
        <Route
          path="vehicles"
          element={
            <RequirePermission moduleKey="vehicles">
              <SuspenseWrapper>
                <VehicleListPage />
              </SuspenseWrapper>
            </RequirePermission>
          }
        />
        <Route
          path="vehicles/new"
          element={
            <RequirePermission permission="vehicles.create">
              <SuspenseWrapper>
                <VehicleForm />
              </SuspenseWrapper>
            </RequirePermission>
          }
        />
        <Route
          path="vehicles/:id"
          element={
            <RequirePermission moduleKey="vehicles">
              <SuspenseWrapper>
                <VehicleDetailPage />
              </SuspenseWrapper>
            </RequirePermission>
          }
        />
        <Route
          path="vehicles/:id/edit"
          element={
            <RequirePermission permission="vehicles.edit">
              <SuspenseWrapper>
                <VehicleForm />
              </SuspenseWrapper>
            </RequirePermission>
          }
        />

        {/* Services Module */}
        <Route path="services">
          <Route
            index
            element={
              <RequirePermission moduleKey="services">
                <SuspenseWrapper>
                  <ServiceListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="new"
            element={
              <RequirePermission permission="services.create">
                <SuspenseWrapper>
                  <ServiceForm />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path="categories"
            element={
              <RequirePermission moduleKey="services">
                <SuspenseWrapper>
                  <ServiceCategoryListPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id"
            element={
              <RequirePermission moduleKey="services">
                <SuspenseWrapper>
                  <ServiceDetailPage />
                </SuspenseWrapper>
              </RequirePermission>
            }
          />
          <Route
            path=":id/edit"
            element={
              <RequirePermission permission="services.edit">
                <SuspenseWrapper>
                  <ServiceForm />
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
        </Route>

        {/* Reports */}
        <Route
          path="reports"
          element={
            <RequirePermission moduleKey="reports">
              <SuspenseWrapper>
                <ReportsPage />
              </SuspenseWrapper>
            </RequirePermission>
          }
        />

        {/* Finance Module */}
        <Route path="finance">
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
    </Routes>
  )
}
