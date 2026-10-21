import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import Layout from './components/Layout'
import { ToastProvider } from './components/toast'
import { RequireAuth, RequirePermission } from './components/RouteGuards'
import HomePage from './pages/HomePage'
import ImportDetailPage from './pages/ImportDetailPage'
import ImportsPage from './pages/ImportsPage'
import LoginPage from './pages/LoginPage'
import AgendaPage from './pages/AgendaPage'
import AuditPage from './pages/AuditPage'
import BranchesPage from './pages/BranchesPage'
import CompaniesPage from './pages/CompaniesPage'
import CreditAgingPage from './pages/CreditAgingPage'
import CreditDetailPage from './pages/CreditDetailPage'
import InsightsConcentrationPage from './pages/InsightsConcentrationPage'
import InsightsPage from './pages/InsightsPage'
import InsightsSegmentsPage from './pages/InsightsSegmentsPage'
import InventoryKardexPage from './pages/InventoryKardexPage'
import InventoryMovementsPage from './pages/InventoryMovementsPage'
import InventoryRotationPage from './pages/InventoryRotationPage'
import InventoryShortfallsPage from './pages/InventoryShortfallsPage'
import InventoryStockPage from './pages/InventoryStockPage'
import InventoryUnitsPage from './pages/InventoryUnitsPage'
import CreditPage from './pages/CreditPage'
import CustomerProfilePage from './pages/CustomerProfilePage'
import CustomerSalesReportPage from './pages/CustomerSalesReportPage'
import CustomerTypesPage from './pages/CustomerTypesPage'
import OperatorsPage from './pages/OperatorsPage'
import QuotationDetailPage from './pages/QuotationDetailPage'
import QuotationsPage from './pages/QuotationsPage'
import FollowUpKpisPage from './pages/FollowUpKpisPage'
import ProductsPage from './pages/ProductsPage'
import SellersPage from './pages/SellersPage'
import CustomersPage from './pages/CustomersPage'
import GoalsPage from './pages/GoalsPage'
import SalesDashboardPage from './pages/SalesDashboardPage'
import RolesPage from './pages/RolesPage'
import UsersPage from './pages/UsersPage'

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <ToastProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<Layout />}>
              <Route index element={<HomePage />} />
              <Route element={<RequirePermission permission="sales.view" />}>
                <Route path="ventas" element={<SalesDashboardPage />} />
                <Route path="metas" element={<GoalsPage />} />
                <Route path="productos" element={<ProductsPage />} />
              </Route>
              <Route element={<RequirePermission permission="customers.view" />}>
                <Route path="clientes" element={<CustomersPage />} />
                <Route path="clientes/ficha" element={<CustomerProfilePage />} />
                <Route path="clientes/reporte" element={<CustomerSalesReportPage />} />
                <Route path="cotizaciones" element={<QuotationsPage />} />
                <Route path="cotizaciones/:id" element={<QuotationDetailPage />} />
                <Route element={<RequirePermission permission="interactions.manage" />}>
                  <Route path="agenda" element={<AgendaPage />} />
                  <Route path="seguimiento" element={<FollowUpKpisPage />} />
                </Route>
              </Route>
              <Route element={<RequirePermission permission="insights.view" />}>
                <Route path="inteligencia" element={<InsightsPage />} />
                <Route path="inteligencia/segmentos" element={<InsightsSegmentsPage />} />
                <Route path="inteligencia/concentracion" element={<InsightsConcentrationPage />} />
              </Route>
              <Route element={<RequirePermission permission="inventory.view" />}>
                <Route path="inventario" element={<InventoryStockPage />} />
                <Route path="inventario/kardex" element={<InventoryKardexPage />} />
                <Route path="inventario/faltantes" element={<InventoryShortfallsPage />} />
                <Route path="inventario/rotacion" element={<InventoryRotationPage />} />
                <Route path="inventario/movimientos" element={<InventoryMovementsPage />} />
                <Route path="inventario/unidades" element={<InventoryUnitsPage />} />
              </Route>
              <Route element={<RequirePermission permission="credit.view" />}>
                <Route path="credito" element={<CreditPage />} />
                <Route path="credito/:id" element={<CreditDetailPage />} />
                <Route path="cartera" element={<CreditAgingPage />} />
              </Route>
              <Route element={<RequirePermission permission="customers.manage" />}>
                <Route path="clientes/tipos" element={<CustomerTypesPage />} />
                <Route path="clientes/vendedores" element={<SellersPage />} />
                <Route path="clientes/facturadores" element={<OperatorsPage />} />
              </Route>
              <Route element={<RequirePermission permission="imports.manage" />}>
                <Route path="importaciones" element={<ImportsPage />} />
                <Route path="importaciones/:id" element={<ImportDetailPage />} />
              </Route>
              <Route element={<RequirePermission permission="companies.manage" />}>
                <Route path="admin/companias" element={<CompaniesPage />} />
              </Route>
              <Route element={<RequirePermission permission="branches.manage" />}>
                <Route path="admin/sucursales" element={<BranchesPage />} />
              </Route>
              <Route element={<RequirePermission permission="roles.manage" />}>
                <Route path="admin/roles" element={<RolesPage />} />
              </Route>
              <Route path="admin/organizacion" element={<Navigate to="/admin/companias" replace />} />
              <Route element={<RequirePermission permission="users.manage" />}>
                <Route path="admin/usuarios" element={<UsersPage />} />
              </Route>
              <Route element={<RequirePermission permission="audit.view" />}>
                <Route path="admin/auditoria" element={<AuditPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        </ToastProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}
