import React, { useEffect, useState } from 'react'
import { api, languageName, formatTime } from '../api.js'
import {Badge, ErrorBox, Alert, Loading} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function DeskIssuePage() {
  const [performances, setPerformances] = useState([])
  const [performanceId, setPerformanceId] = useState('')
  const [q, setQ] = useState('')
  const [pickups, setPickups] = useState([])
  const [selected, setSelected] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [loading, setLoading] = useState(false)

  // 发放表单字段
  const [serial, setSerial] = useState('')
  const [battery, setBattery] = useState('')
  const [language, setLanguage] = useState('')

  useEffect(() => {
    api('/performances').then((ps) => {
      setPerformances(ps)
      const first = ps.find((p) => !p.has_started) || ps[0]
      if (first) setPerformanceId(String(first.id))
    }).catch(setError)
  }, [])

  async function search(e) {
    e?.preventDefault()
    setLoading(true)
    setError(null)
    try {
      const qs = new URLSearchParams()
      if (performanceId) qs.set('performance_id', performanceId)
      if (q.trim()) qs.set('q', q.trim())
      setPickups(await api(`/desk/pickups?${qs.toString()}`))
    } catch (e) { setError(e) } finally { setLoading(false) }
  }

  useEffect(() => { search() }, [performanceId])

  function choose(r) {
    setSelected(r)
    setSerial('')
    setBattery('')
    setLanguage(r.language)
    setNotice(null)
    setError(null)
  }

  async function issue(e) {
    e.preventDefault()
    setError(null)
    setNotice(null)
    try {
      const result = await api('/desk/assignments', {
        method: 'POST',
        body: {
          reservation_id: selected.reservation_id,
          serial: serial.trim().toUpperCase(),
          battery: Number(battery),
          language,
        },
      })
      setNotice(`设备 ${result.serial} 发放成功，字幕语言 ${languageName(result.language)}，发放电量 ${result.battery_at_issue}%。`)
      setSelected(null)
      search()
    } catch (err) { setError(err) }
  }

  return (
    <div>
      <h2>前台 · 设备发放</h2>
      <p className="muted">发放前必须核对三件事：<strong>实物编号、电量、字幕语言</strong>。未归还或故障设备无法发放。</p>

      <div className="card">
        <ErrorBox error={error} />
        <form onSubmit={search} className="row">
          <Field label="按场次筛选">
            <select value={performanceId} onChange={(e) => setPerformanceId(e.target.value)} style={{ minWidth: 260 }}>
              {performances.map((p) => (
                <option key={p.id} value={p.id}>{p.title}（{formatTime(p.starts_at)}）</option>
              ))}
            </select>
          </Field>
          <Field label="搜索观众姓名 / 电话 / 邮箱">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="输入关键字" style={{ minWidth: 260 }} />
          </Field>
          <button type="submit" className="secondary">查找待领取预约</button>
        </form>

        {loading ? <Loading /> : (
          <table>
            <thead>
              <tr><th scope="col">观众</th><th scope="col">电话</th><th scope="col">场次</th><th scope="col">语言</th><th scope="col">操作</th></tr>
            </thead>
            <tbody>
              {pickups.length === 0 && <tr><td colSpan="5" className="muted">没有待领取的预约</td></tr>}
              {pickups.map((r) => (
                <tr key={r.reservation_id}>
                  <td>{r.audience}<div className="small muted">{r.email}</div></td>
                  <td>{r.contact_phone || '—'}</td>
                  <td>{r.performance_title}</td>
                  <td><strong>{languageName(r.language)}</strong></td>
                  <td><button type="button" onClick={() => choose(r)}>发放设备</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {selected && (
        <div className="card" aria-live="polite">
          <h3>为「{selected.audience}」发放设备 — {selected.performance_title} / {languageName(selected.language)}</h3>
          <Alert kind="info">预约字幕语言：<strong>{languageName(selected.language)}（{selected.language}）</strong>，发放设备语言必须一致。</Alert>
          <form onSubmit={issue}>
            <Field label="① 核对实物编号（设备标签，如 CAP-001）">
              <input
                value={serial}
                onChange={(e) => setSerial(e.target.value)}
                placeholder="CAP-001"
                required
                autoCapitalize="characters"
                autoFocus
                aria-required="true"
              />
            </Field>
            <Field label="② 实测电量（%，低于 40% 不允许发放）">
              <input
                type="number" min="0" max="100"
                value={battery}
                onChange={(e) => setBattery(e.target.value)}
                required
                aria-required="true"
              />
            </Field>
            <Field label="③ 核对字幕语言（必须与预约语言一致）">
              <select value={language} onChange={(e) => setLanguage(e.target.value)} required>
                <option value="zh">中文 zh</option>
                <option value="en">English en</option>
                <option value="ja">日本語 ja</option>
              </select>
            </Field>
            <button type="submit" className="success">三项核对无误，确认发放</button>
            <button type="button" className="secondary" onClick={() => setSelected(null)}>取消</button>
          </form>
        </div>
      )}

      {notice && <Alert kind="success">{notice}</Alert>}
    </div>
  )
}