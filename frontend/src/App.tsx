import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import Layout from './components/Layout'
import { RequireAuth, RequirePermission } from './components/RouteGuards'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import AuditPage from './pages/AuditPage'
import OrganizationPage from './pages/OrganizationPage'
import UsersPage from './pages/UsersPage'

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<Layout />}>
              <Route index element={<HomePage />} />
              <Route element={<RequirePermission permission="organization.manage" />}>
                <Route path="admin/organizacion" element={<OrganizationPage />} />
              </Route>
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
      </AuthProvider>
    </BrowserRouter>
  )
}
