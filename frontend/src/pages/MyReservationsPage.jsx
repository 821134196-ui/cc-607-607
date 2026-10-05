import React, { useEffect, useState } from 'react'
import { api, languageName, formatTime } from '../api.js'
import {Badge, ErrorBox, Alert, Loading} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function MyReservationsPage() {
  const [items, setItems] = useState(null)
  const [performances, setPerformances] = useState([])
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [rebookingId, setRebookingingId] = useState(null)

  async function load() {
    setError(null)
    try {
      const [rs, ps] = await Promise.all([api('/reservations'), api('/performances')])
      setItems(rs)
      setPerformances(ps)
    } catch (e) { setError(e) }
  }

  useEffect(() => { load() }, [])

  async function cancel(r) {
    if (!window.confirm(`确认取消《${r.performance_title}》的预约吗？取消后立即释放占用。`)) return
    try {
      await api(`/reservations/${r.id}/cancel`, { method: 'POST', body: { reason: '观众自行取消' } })
      setNotice('预约已取消，设备占用已释放。')
      load()
    } catch (e) { setError(e) }
  }

  if (error) return <ErrorBox error={error} />
  if (!items) return <Loading />

  return (
    <div>
      <h2>我的预约</h2>
      {notice && <Alert kind="success">{notice}</Alert>}

      {items.length === 0 && <p className="muted">暂无预约记录，去 <a href="/performances">场次列表</a> 预约吧。</p>}

      {items.map((r) => (
        <article key={r.id} className="card" aria-label={`预约：${r.performance_title}`}>
          <div className="row">
            <h3 style={{ margin: 0 }}>《{r.performance_title}》</h3>
            <span className="spacer" />
            <Badge status={r.status} />
          </div>
          <dl className="kv" style={{ margin: '12px 0' }}>
            <dt>开演时间</dt><dd>{formatTime(r.starts_at)}</dd>
            <dt>字幕语言</dt><dd>{languageName(r.language)}（{r.language}）</dd>
            <dt>预约序号</dt><dd>第 {r.seq} 次</dd>
            {r.device && <dt>发放设备</dt>}
            {r.device && <dd>{r.device.serial}（发放电量 {r.device.battery_at_issue}%）{r.device.return_check ? `，归还复检：${r.device.return_check === 'ok' ? '正常' : '故障'}` : ''}</dd>}
            {r.cancel_reason && <dt>备注</dt>}
            {r.cancel_reason && <dd className="muted">{r.cancel_reason}（{formatTime(r.cancelled_at)}）</dd>}
          </dl>

          {r.status === 'active' && (
            <div className="row">
              <button type="button" onClick={() => setRebookingingId(rebookingId === r.id ? null : r.id)}
                aria-expanded={rebookingId === r.id}>
                {rebookingId === r.id ? '收起改签' : '改签（换场次/语言）'}
              </button>
              <button type="button" className="danger" onClick={() => cancel(r)}>取消预约</button>
            </div>
          )}

          {rebookingId === r.id && (
            <RebookForm
              reservation={r}
              performances={performances}
              onDone={(msg) => { setRebookingingId(null); setNotice(msg); load() }}
              onError={setError}
            />
          )}
        </article>
      ))}
    </div>
  )
}

function RebookForm({ reservation, performances, onDone, onError }) {
  const [targetPerformanceId, setTargetPerformanceId] = useState(reservation.performance_id)
  const [language, setLanguage] = useState(reservation.language)
  const [ticketCode, setTicketCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [formError, setFormError] = useState(null)

  const target = performances.find((p) => p.id === Number(targetPerformanceId))
  const langOptions = target?.languages || []

  async function submit(e) {
    e.preventDefault()
    setFormError(null)
    setBusy(true)
    try {
      const body = {
        performance_id: Number(targetPerformanceId),
        language,
      }
      if (ticketCode.trim()) body.ticket_code = ticketCode.trim().toUpperCase()
      await api(`/reservations/${reservation.id}/rebook`, { method: 'POST', body })
      onDone('改签成功：原预约已释放，新预约已生效。')
    } catch (err) {
      setFormError(err)
      onError(null)
    } finally {
      setBusy(false)
    }
  }

  const same = Number(targetPerformanceId) === reservation.performance_id && language === reservation.language

  return (
    <form onSubmit={submit} style={{ marginTop: 16, borderTop: '3px solid #0b0f14', paddingTop: 14 }}>
      <h4 style={{ margin: '0 0 4px' }}>改签为：</h4>
      <ErrorBox error={formError} />
      <Field label="新场次">
        <select value={targetPerformanceId} onChange={(e) => { setTargetPerformanceId(e.target.value); setLanguage('') }}>
          {performances.map((p) => (
            <option key={p.id} value={p.id} disabled={p.has_started}>
              {p.title}（{formatTime(p.starts_at)}）{p.has_started ? ' — 已开演' : ''}
            </option>
          ))}
        </select>
      </Field>
      <Field label="新字幕语言">
        <select value={language} onChange={(e) => setLanguage(e.target.value)} required>
          <option value="" disabled>请选择语言</option>
          {langOptions.map((l) => (
            <option key={l.language} value={l.language} disabled={l.remaining === 0}>
              {languageName(l.language)} — 可预约 {l.remaining}/{l.capacity}{l.remaining === 0 ? '（已满）' : ''}
            </option>
          ))}
        </select>
      </Field>
      <Field label="新票号（如更换场次则必填）" hint={`格式 T-${targetPerformanceId}-XXXX`}>
        <input value={ticketCode} onChange={(e) => setTicketCode(e.target.value)} placeholder={`T-${targetPerformanceId}-XXXX`} />
      </Field>
      <button type="submit" disabled={busy || same}>
        {busy ? '改签中…' : '确认改签（原占用将立即释放）'}
      </button>
      {same && <p className="small muted" role="note">新场次与语言和当前预约相同，无需改签。</p>}
    </form>
  )
}