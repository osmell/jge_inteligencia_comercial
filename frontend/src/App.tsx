import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import Layout from './components/Layout'
import { ToastProvider } from './components/toast'
import { RequireAuth, RequirePermission } from './components/RouteGuards'
import HomePage from './pages/HomePage'
import ImportDetailPage from './pages/ImportDetailPage'
import ImportsPage from './pages/ImportsPage'
import LoginPage from './pages/LoginPage'
import AuditPage from './pages/AuditPage'
import BranchesPage from './pages/BranchesPage'
import CompaniesPage from './pages/CompaniesPage'
import CustomerProfilePage from './pages/CustomerProfilePage'
import CustomerTypesPage from './pages/CustomerTypesPage'
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
              </Route>
              <Route element={<RequirePermission permission="customers.view" />}>
                <Route path="clientes" element={<CustomersPage />} />
                <Route path="clientes/ficha" element={<CustomerProfilePage />} />
              </Route>
              <Route element={<RequirePermission permission="customers.manage" />}>
                <Route path="clientes/tipos" element={<CustomerTypesPage />} />
                <Route path="clientes/vendedores" element={<SellersPage />} />
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
