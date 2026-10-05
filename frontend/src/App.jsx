import React from 'react'
import { Routes, Route, NavLink, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from './AuthContext.jsx'
import LoginPage from './pages/LoginPage.jsx'
import PerformancesPage from './pages/PerformancesPage.jsx'
import BookingPage from './pages/BookingPage.jsx'
import MyReservationsPage from './pages/MyReservationsPage.jsx'
import DeskIssuePage from './pages/DeskIssuePage.jsx'
import DeskReturnPage from './pages/DeskReturnPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import CaptionsPage from './pages/CaptionsPage.jsx'
import DeviceMaintenancePage from './pages/DeviceMaintenancePage.jsx'
import ApprovalsPage from './pages/ApprovalsPage.jsx'

const ROLE_NAME = {
  audience: '观众',
  front_desk: '前台',
  technician: '技术员',
  manager: '负责人',
}

function Shell({ children }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const role = user?.role

  const navItem = (to, label, roles) =>
    roles.includes(role) ? <NavLink to={to}>{label}</NavLink> : null

  return (
    <div className="app">
      <a href="#main" className="skip-link">跳到主要内容</a>
      <header className="app-header">
        <h1>🎭 无障碍字幕设备服务</h1>
        <span className="role-tag" aria-label={`当前角色：${ROLE_NAME[role]}`}>{ROLE_NAME[role]}</span>
        <span className="muted">{user?.name}</span>
        <button className="secondary" type="button" onClick={() => { logout(); navigate('/login') }}>退出登录</button>
      </header>

      <nav className="main-nav" aria-label="主导航">
        {navItem('/performances', '场次与预约', ['audience', 'front_desk', 'manager'])}
        {navItem('/my-reservations', '我的预约', ['audience'])}
        {navItem('/desk/issue', '设备发放', ['front_desk', 'manager'])}
        {navItem('/desk/return', '收回复检', ['front_desk', 'manager'])}
        {navItem('/desk/dashboard', '后台看板', ['front_desk', 'manager'])}
        {navItem('/technician/captions', '字幕管理', ['technician', 'manager'])}
        {navItem('/technician/devices', '设备复检放行', ['technician', 'manager'])}
        {navItem('/manager/approvals', '换稿审批', ['manager'])}
      </nav>

      <main id="main">{children}</main>
    </div>
  )
}

function Home() {
  const { user } = useAuth()
  const first = {
    audience: '/performances',
    front_desk: '/desk/issue',
    technician: '/technician/captions',
    manager: '/manager/approvals',
  }[user?.role] || '/login'
  return <Navigate to={first} replace />
}

export default function App() {
  const { user } = useAuth()
  if (!user) {
    return (
      <Routes>
        <Route path="*" element={<LoginPage />} />
      </Routes>
    )
  }

  const staff = ['front_desk', 'manager'].includes(user.role)

  return (
    <Shell>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/performances" element={<PerformancesPage />} />
        <Route path="/book/:performanceId" element={<BookingPage />} />
        <Route path="/my-reservations" element={<MyReservationsPage />} />

        {staff && <>
          <Route path="/desk/issue" element={<DeskIssuePage />} />
          <Route path="/desk/return" element={<DeskReturnPage />} />
          <Route path="/desk/dashboard" element={<DashboardPage />} />
        </>}

        {['technician', 'manager'].includes(user.role) && <>
          <Route path="/technician/captions" element={<CaptionsPage />} />
          <Route path="/technician/devices" element={<DeviceMaintenancePage />} />
        </>}

        {user.role === 'manager' &&
          <Route path="/manager/approvals" element={<ApprovalsPage />} />}

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Shell>
  )
}
