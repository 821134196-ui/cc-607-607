import { useEffect, useState } from 'react'
import { NavLink, Navigate, Route, Routes } from 'react-router-dom'
import { api, setStaffId } from './api.js'
import { storage } from './storage.js'
import BookingPage from './pages/BookingPage.jsx'
import ReservationPage from './pages/ReservationPage.jsx'
import FrontDeskPage from './pages/FrontDeskPage.jsx'
import TechnicianPage from './pages/TechnicianPage.jsx'
import ApprovalsPage from './pages/ApprovalsPage.jsx'
import AdminPage from './pages/AdminPage.jsx'

const NAV = [
  { to: '/', label: '观众预约', end: true },
  { to: '/frontdesk', label: '前台工作台' },
  { to: '/technician', label: '技术员·字幕' },
  { to: '/approvals', label: '负责人审批' },
  { to: '/admin', label: '后台总览' },
]

const ROLE_LABEL = { frontdesk: '前台', technician: '技术员', manager: '负责人' }

export default function App() {
  const [staffList, setStaffList] = useState(null)
  const [staff, setStaff] = useState(() => {
    const raw = storage.get('staff')
    return raw ? JSON.parse(raw) : null
  })

  async function ensureStaffList() {
    if (!staffList) {
      const res = await api.get('/staff')
      setStaffList(res.data)
      return res.data
    }
    return staffList
  }

  function chooseStaff(user) {
    setStaff(user)
    setStaffId(user.id)
    storage.set('staff', JSON.stringify(user))
    storage.set('staffId', String(user.id))
  }

  function clearStaff() {
    setStaff(null)
    setStaffId(null)
    storage.remove('staff')
    storage.remove('staffId')
  }

  return (
    <div className="app">
      <a className="skip-link" href="#main-content">跳到主要内容</a>
      <header className="app-header">
        <div className="top">
          <h1>剧场无障碍字幕设备系统</h1>
          <nav aria-label="主导航">
            {NAV.map((n) => (
              <NavLink key={n.to} to={n.to} end={n.end}>
                {n.label}
              </NavLink>
            ))}
          </nav>
        </div>
      </header>

      <main id="main-content">
        <Routes>
          <Route path="/" element={<BookingPage />} />
          <Route path="/reservations/:code" element={<ReservationPage onStaffNeed={ensureStaffList} />} />
          <Route path="/frontdesk" element={
            <RequireStaff staff={staff} staffList={staffList} onLoad={ensureStaffList} onChoose={chooseStaff}
              role="frontdesk" title="前台工作台">
              <FrontDeskPage staff={staff} />
            </RequireStaff>
          } />
          <Route path="/technician" element={
            <RequireStaff staff={staff} staffList={staffList} onLoad={ensureStaffList} onChoose={chooseStaff}
              role="technician" title="技术员·字幕管理">
              <TechnicianPage staff={staff} />
            </RequireStaff>
          } />
          <Route path="/approvals" element={
            <RequireStaff staff={staff} staffList={staffList} onLoad={ensureStaffList} onChoose={chooseStaff}
              role="manager" title="负责人审批">
              <ApprovalsPage staff={staff} />
            </RequireStaff>
          } />
          <Route path="/admin" element={
            <RequireStaff staff={staff} staffList={staffList} onLoad={ensureStaffList} onChoose={chooseStaff}
              role="any" title="后台总览">
              <AdminPage staff={staff} />
            </RequireStaff>
          } />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>

      <footer>
        演示系统 · 票务与通知接口为本地模拟 · 大字高对比，支持 Tab / Enter / 空格 键盘操作
        {staff && (
          <span className="staff-chip" style={{ marginLeft: '0.8rem' }}>
            当前员工身份：<strong>{staff.name}</strong>（{ROLE_LABEL[staff.role]}）
            {' '}<button className="btn-secondary" style={{ padding: '0.2rem 0.7rem', fontSize: '0.9rem' }}
              onClick={clearStaff}>切换身份</button>
          </span>
        )}
      </footer>
    </div>
  )
}

function RequireStaff({ staff, staffList, onLoad, onChoose, role, title, children }) {
  const [list, setList] = useState(staffList)
  const [loaded, setLoaded] = useState(Boolean(staffList))

  useEffect(() => {
    if (loaded) return
    let alive = true
    onLoad().then((l) => { if (alive) { setList(l); setLoaded(true) } })
    return () => { alive = false }
  }, [loaded, onLoad])

  if (staff) {
    if (role !== 'any' && staff.role !== role) {
      return (
        <section>
          <h2>{title}</h2>
          <div className="alert alert-warning">
            当前身份「{staff.name}」是{ROLE_LABEL[staff.role]}，本页面需要{ROLE_LABEL[role]}身份。
            请点击页脚“切换身份”。
          </div>
        </section>
      )
    }
    return children
  }

  if (!loaded) {
    return <p>正在加载员工名单…</p>
  }

  const options = list.filter((u) => role === 'any' || u.role === role)

  return (
    <section>
      <h2>{title}</h2>
      <div className="card">
        <p className="muted">请选择当前操作员工（演示用身份切换，真实环境为登录鉴权）：</p>
        <div className="option-cards" role="group" aria-label="选择员工身份">
          {options.map((u) => (
            <button key={u.id} className="option-card" onClick={() => onChoose(u)}
              style={{ textAlign: 'left' }}>
              <span className="oc-title">{u.name}</span>
              <span className="oc-meta">{ROLE_LABEL[u.role]}</span>
            </button>
          ))}
        </div>
      </div>
    </section>
  )
}
