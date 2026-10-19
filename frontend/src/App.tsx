import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import Layout from './components/Layout'
import { RequireAuth, RequirePermission } from './components/RouteGuards'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import PlaceholderPage from './pages/PlaceholderPage'

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
                <Route path="admin/organizacion" element={<PlaceholderPage title="Compañías y sucursales" />} />
              </Route>
              <Route element={<RequirePermission permission="users.manage" />}>
                <Route path="admin/usuarios" element={<PlaceholderPage title="Usuarios" />} />
              </Route>
              <Route element={<RequirePermission permission="audit.view" />}>
                <Route path="admin/auditoria" element={<PlaceholderPage title="Auditoría" />} />
              </Route>
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
