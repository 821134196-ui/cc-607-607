import React, { useEffect, useState } from 'react'
import { api, languageName, formatTime } from '../api.js'
import {Badge, ErrorBox, Alert, Loading} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function DeskReturnPage() {
  const [performances, setPerformances] = useState([])
  const [performanceId, setPerformanceId] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  // 复检录入
  const [form, setForm] = useState({})

  useEffect(() => {
    api('/performances').then((ps) => {
      setPerformances(ps)
      const first = ps[0]
      if (first) setPerformanceId(String(first.id))
    }).catch(setError)
  }, [])

  async function load(pid) {
    setError(null)
    try {
      setData(await api(`/desk/performances/${pid}/assignments`))
    } catch (e) { setError(e) }
  }

  useEffect(() => { if (performanceId) load(performanceId) }, [performanceId])

  async function submitReturn(a, checkResult) {
    const battery = form[`battery_${a.id}`]
    const notes = form[`notes_${a.id}`] || ''
    if (battery === undefined || battery === '') {
      setError(new Error(`请先填写设备 ${a.serial} 的复检电量`))
      return
    }
    setError(null)
    try {
      if (checkResult === 'missing') {
        await api(`/desk/assignments/${a.id}/missing`, { method: 'POST' })
        setNotice(`设备 ${a.serial} 已标记为「未归还」，在找回/复检前不会分配给下一场。`)
      } else {
        await api(`/desk/assignments/${a.id}/return`, {
          method: 'POST',
          body: { battery: Number(battery), check_result: checkResult, fault_notes: checkResult === 'fault' ? notes : null },
        })
        setNotice(checkResult === 'ok'
          ? `设备 ${a.serial} 复检正常，已回到可发放池。`
          : `设备 ${a.serial} 复检发现故障，已进入维修隔离，不会分配给下一场。`)
      }
      load(performanceId)
    } catch (e) { setError(e) }
  }

  const issued = data?.assignments?.filter((a) => a.status === 'issued') || []

  return (
    <div>
      <h2>前台 · 散场收回与复检</h2>
      <p className="muted">收回设备时复检电量与功能。复检故障或标记未归还的设备将被隔离，<strong>不会进入下一场分配</strong>。</p>

      <div className="card">
        <ErrorBox error={error} />
        {notice && <Alert kind="success">{notice}</Alert>}
        <Field label="选择场次">
          <select value={performanceId} onChange={(e) => setPerformanceId(e.target.value)} style={{ maxWidth: 420 }}>
            {performances.map((p) => (
              <option key={p.id} value={p.id}>{p.title}（{formatTime(p.starts_at)}）</option>
            ))}
          </select>
        </Field>

        {!data ? <Loading /> : issued.length === 0 ? (
          <p className="muted">该场次没有「已发放待收回」的设备。</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th scope="col">设备编号</th>
                <th scope="col">观众</th>
                <th scope="col">语言</th>
                <th scope="col">发放电量</th>
                <th scope="col">复检电量%</th>
                <th scope="col">故障说明</th>
                <th scope="col">复检操作</th>
              </tr>
            </thead>
            <tbody>
              {issued.map((a) => (
                <tr key={a.id}>
                  <td><strong>{a.serial}</strong></td>
                  <td>{a.audience}</td>
                  <td>{languageName(a.language)}</td>
                  <td>{a.battery_at_issue}%</td>
                  <td>
                    <input
                      type="number" min="0" max="100"
                      aria-label={`设备 ${a.serial} 复检电量`}
                      value={form[`battery_${a.id}`] ?? ''}
                      onChange={(e) => setForm({ ...form, [`battery_${a.id}`]: e.target.value })}
                      style={{ minHeight: 48, width: 110 }}
                    />
                  </td>
                  <td>
                    <input
                      aria-label={`设备 ${a.serial} 故障说明`}
                      placeholder="复检故障时填写"
                      value={form[`notes_${a.id}`] ?? ''}
                      onChange={(e) => setForm({ ...form, [`notes_${a.id}`]: e.target.value })}
                      style={{ minHeight: 48 }}
                    />
                  </td>
                  <td>
                    <button type="button" className="success" onClick={() => submitReturn(a, 'ok')}>复检正常·收回</button>
                    <button type="button" className="danger" onClick={() => submitReturn(a, 'fault')}>复检故障·隔离</button>
                    <button type="button" className="secondary" onClick={() => submitReturn(a, 'missing')}>未归还</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {data && data.assignments.some((a) => a.status !== 'issued') && (
        <div className="card">
          <h3>本场已处理记录</h3>
          <table>
            <thead><tr><th scope="col">设备</th><th scope="col">状态</th><th scope="col">复检结果</th><th scope="col">归还电量</th><th scope="col">说明</th><th scope="col">时间</th></tr></thead>
            <tbody>
              {data.assignments.filter((a) => a.status !== 'issued').map((a) => (
                <tr key={a.id}>
                  <td>{a.serial}</td>
                  <td><Badge status={a.status} /></td>
                  <td>{a.check_result === 'ok' ? '正常' : a.check_result === 'fault' ? '故障' : a.check_result === 'missing' ? '未归还' : '—'}</td>
                  <td>{a.battery_at_return ?? '—'}%</td>
                  <td>{a.fault_notes || '—'}</td>
                  <td>{formatTime(a.returned_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}