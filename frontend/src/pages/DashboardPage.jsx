import React, { useEffect, useState } from 'react'
import { api, formatTime } from '../api.js'
import { Badge, ErrorBox, Loading } from '../components/Ui.jsx'

export default function DashboardPage() {
  const [devices, setDevices] = useState(null)
  const [summary, setSummary] = useState(null)
  const [error, setError] = useState(null)
  const [filter, setFilter] = useState('all')

  async function load() {
    try {
      const data = await api('/desk/devices')
      setDevices(data.devices)
      setSummary(data.summary)
    } catch (e) { setError(e) }
  }

  useEffect(() => { load() }, [])

  if (error) return <ErrorBox error={error} />
  if (!devices) return <Loading />

  const filtered = filter === 'all' ? devices : devices.filter((d) => d.status === filter)

  const stat = (label, value, status) => (
    <div className="card" style={{ textAlign: 'center', padding: '18px' }}>
      <div style={{ fontSize: '2.2rem', fontWeight: 900 }}>{value}</div>
      <div style={{ marginTop: 6 }}><Badge status={status} /></div>
    </div>
  )

  return (
    <div>
      <h2>后台 · 设备占用与检查看板</h2>

      <div className="grid" style={{ gridTemplateColumns: 'repeat(5, 1fr)' }}>
        {stat('设备总数', summary.total, 'retired')}
        {stat('可发放', summary.available, 'available')}
        {stat('使用中（未归还）', summary.assigned, 'assigned')}
        {stat('维修/隔离', summary.maintenance, 'maintenance')}
        {stat('已停用', summary.retired, 'cancelled')}
      </div>

      <div className="card">
        <div className="row" role="group" aria-label="按状态筛选设备">
          <span style={{ fontWeight: 800 }}>筛选：</span>
          {[['all', '全部'], ['available', '可发放'], ['assigned', '使用中'], ['maintenance', '维修/隔离']].map(([v, l]) => (
            <button key={v} type="button"
              className={filter === v ? '' : 'secondary'}
              aria-pressed={filter === v}
              onClick={() => setFilter(v)}>{l}</button>
          ))}
          <span className="spacer" />
          <button type="button" className="secondary" onClick={load}>刷新</button>
        </div>

        <table style={{ marginTop: 12 }}>
          <thead>
            <tr>
              <th scope="col">实物编号</th>
              <th scope="col">型号</th>
              <th scope="col">占用状态</th>
              <th scope="col">当前电量</th>
              <th scope="col">最近检查</th>
              <th scope="col">故障/备注</th>
              <th scope="col">检查时间</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((d) => (
              <tr key={d.id}>
                <td><strong>{d.serial}</strong></td>
                <td>{d.model}</td>
                <td><Badge status={d.status} /></td>
                <td>{d.battery_level}%</td>
                <td>
                  {d.last_check_result === 'ok' ? '正常'
                    : d.last_check_result === 'fault' ? '故障'
                    : d.last_check_result === 'missing' ? '未归还' : '—'}
                </td>
                <td>{d.fault_notes || '—'}</td>
                <td>{formatTime(d.checked_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
