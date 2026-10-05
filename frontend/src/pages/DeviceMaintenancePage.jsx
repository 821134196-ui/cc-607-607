import React, { useEffect, useState } from 'react'
import { api, formatTime } from '../api.js'
import { Badge, ErrorBox, Alert, Loading } from '../components/Ui.jsx'

export default function DeviceMaintenancePage() {
  const [devices, setDevices] = useState(null)
  const [summary, setSummary] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [battery, setBattery] = useState({})
  const [busyId, setBusyId] = useState(null)

  async function load() {
    try {
      const data = await api('/desk/devices')
      setDevices(data.devices)
      setSummary(data.summary)
    } catch (e) { setError(e) }
  }

  useEffect(() => { load() }, [])

  async function release(d) {
    const b = battery[d.id]
    if (b === undefined || b === '') {
      setError(new Error(`请填写设备 ${d.serial} 复检后的电量`))
      return
    }
    setError(null)
    setBusyId(d.id)
    try {
      const res = await api(`/desk/devices/${d.id}/release`, { method: 'POST', body: { battery: Number(b) } })
      setNotice(res.message)
      load()
    } catch (e) { setError(e) } finally { setBusyId(null) }
  }

  if (error && !devices) return <ErrorBox error={error} />
  if (!devices) return <Loading />

  const maintenance = devices.filter((d) => d.status === 'maintenance')

  return (
    <div>
      <h2>技术员 · 维修/找回设备复检放行</h2>
      <p className="muted">故障隔离或演出后未归还的设备都处于「维修中」。检修/找回并复检通过后在此放行，设备才会重新进入可发放池。</p>

      <div className="card row" aria-live="polite">
        <span>当前设备概况：</span>
        <Badge status="available" /> <strong>× {summary?.available}</strong>
        <Badge status="assigned" /> <strong>× {summary?.assigned}</strong>
        <Badge status="maintenance" /> <strong>× {summary?.maintenance}</strong>
      </div>

      <ErrorBox error={error} />
      {notice && <Alert kind="success">{notice}</Alert>}

      <div className="card">
        <h3>待复检设备（{maintenance.length}）</h3>
        {maintenance.length === 0 ? (
          <p className="muted">没有维修/隔离中的设备。</p>
        ) : (
          <table>
            <thead>
              <tr><th scope="col">编号</th><th scope="col">隔离原因</th><th scope="col">最近检查</th><th scope="col">时间</th><th scope="col">复检电量%</th><th scope="col">操作</th></tr>
            </thead>
            <tbody>
              {maintenance.map((d) => (
                <tr key={d.id}>
                  <td><strong>{d.serial}</strong></td>
                  <td>{d.fault_notes || '—'}</td>
                  <td>{d.last_check_result === 'missing' ? '未归还' : d.last_check_result === 'fault' ? '故障' : '—'}</td>
                  <td>{formatTime(d.checked_at)}</td>
                  <td>
                    <input
                      type="number" min="0" max="100"
                      aria-label={`设备 ${d.serial} 复检电量`}
                      value={battery[d.id] ?? ''}
                      onChange={(e) => setBattery({ ...battery, [d.id]: e.target.value })}
                      style={{ width: 110, minHeight: 48 }}
                    />
                  </td>
                  <td>
                    <button type="button" className="success" disabled={busyId === d.id} onClick={() => release(d)}>
                      {busyId === d.id ? '处理中…' : '复检通过·放行'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
