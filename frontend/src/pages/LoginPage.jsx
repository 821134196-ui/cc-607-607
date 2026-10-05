import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../AuthContext.jsx'
import {ErrorBox} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

const DEMO = [
  { label: '观众', email: 'audience@example.com' },
  { label: '前台', email: 'desk@example.com' },
  { label: '技术员', email: 'tech@example.com' },
  { label: '负责人', email: 'manager@example.com' },
]

export default function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('audience@example.com')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const user = await login(email.trim(), password)
      const home = {
        audience: '/performances',
        front_desk: '/desk/issue',
        technician: '/technician/captions',
        manager: '/manager/approvals',
      }[user.role] || '/'
      navigate(home)
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  return (
    <div className="app login-wrap">
      <h1 style={{ fontSize: '1.8rem' }}>🎭 无障碍字幕设备预约系统</h1>
      <div className="card">
        <h2>登录</h2>
        <ErrorBox error={error} />
        <form onSubmit={submit}>
          <Field label="邮箱">
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              required
              aria-required="true"
            />
          </Field>
          <Field label="密码">
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
              aria-required="true"
            />
          </Field>
          <button type="submit" disabled={busy}>{busy ? '登录中…' : '登录'}</button>
        </form>

        <hr style={{ margin: '22px 0', borderWidth: 2 }} />
        <p className="muted" style={{ fontWeight: 700 }}>演示账号一键填充（密码均为 <code>password</code>）：</p>
        <div className="demo-accounts row">
          {DEMO.map((d) => (
            <button key={d.email} type="button" className="secondary"
              onClick={() => { setEmail(d.email); setPassword('password') }}>
              {d.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}