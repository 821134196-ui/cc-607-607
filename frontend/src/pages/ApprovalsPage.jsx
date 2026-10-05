import React, { useEffect, useState } from 'react'
import { api, languageName, formatTime } from '../api.js'
import {Badge, ErrorBox, Alert, Loading} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function ApprovalsPage() {
  const [requests, setRequests] = useState(null)
  const [filter, setFilter] = useState('pending')
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [reviewing, setReviewing] = useState(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [preview, setPreview] = useState({}) // id -> script content

  async function load() {
    setError(null)
    try {
      const qs = filter === 'all' ? '' : `?status=${filter}`
      setRequests(await api(`/caption-change-requests${qs}`))
    } catch (e) { setError(e) }
  }

  useEffect(() => { load() }, [filter])

  async function togglePreview(reqId, scriptId) {
    if (preview[reqId]) {
      setPreview({ ...preview, [reqId]: null })
      return
    }
    try {
      const data = await api(`/captions/scripts/${scriptId}`)
      setPreview({ ...preview, [reqId]: data })
    } catch (e) { setError(e) }
  }

  async function review(req, approve) {
    const confirmMsg = approve
      ? '确认批准并立即切换到新字幕版本吗？系统将记录切换时间，旧版本会保留。'
      : '确认驳回该换稿申请吗？当前字幕版本保持不变。'
    if (!window.confirm(confirmMsg)) return
    setBusy(true)
    setError(null)
    try {
      const data = await api(`/caption-change-requests/${req.id}/review`, {
        method: 'POST',
        body: { approve, review_note: note || null },
      })
      setNotice(data.message)
      setReviewing(null)
      setNote('')
      load()
    } catch (e) { setError(e) } finally { setBusy(false) }
  }

  if (error && !requests) return <ErrorBox error={error} />
  if (!requests) return <Loading />

  return (
    <div>
      <h2>负责人 · 演出中换稿审批</h2>
      <p className="muted">只有负责人可以批准或驳回。批准后系统立即切换当前字幕版本、记录切换时间，并保留旧版本文件。</p>

      <div className="card">
        <ErrorBox error={error} />
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="row" role="group" aria-label="按审批状态筛选">
          {[['pending', '待审批'], ['approved', '已批准'], ['rejected', '已驳回'], ['all', '全部']].map(([v, l]) => (
            <button key={v} type="button" className={filter === v ? '' : 'secondary'} aria-pressed={filter === v} onClick={() => setFilter(v)}>{l}</button>
          ))}
        </div>
      </div>

      {requests.length === 0 && <p className="muted">没有{filter === 'pending' ? '待审批' : ''}换稿申请。</p>}

      {requests.map((r) => (
        <article key={r.id} className="card" aria-label={`换稿申请：${r.performance_title} ${languageName(r.language)}`}>
          <div className="row">
            <h3 style={{ margin: 0 }}>《{r.performance_title}》— {languageName(r.language)}</h3>
            <span className="spacer" />
            <Badge status={r.status} />
          </div>
          <dl className="kv" style={{ margin: '12px 0' }}>
            <dt>申请人</dt><dd>{r.requested_by}（{formatTime(r.requested_at)}）</dd>
            <dt>版本变化</dt><dd>v{r.current_version ?? '—'} → <strong>v{r.new_version}</strong></dd>
            <dt>换稿原因</dt><dd>{r.reason}</dd>
            {r.reviewed_by && <dt>审批人</dt>}
            {r.reviewed_by && <dd>{r.reviewed_by}（{formatTime(r.reviewed_at)}）{r.review_note ? `｜批注：${r.review_note}` : ''}</dd>}
            {r.switched_at && <dt>切换时间</dt>}
            {r.switched_at && <dd><strong>{formatTime(r.switched_at)}</strong></dd>}
          </dl>

          <button type="button" className="secondary" aria-expanded={!!preview[r.id]} onClick={() => togglePreview(r.id, r.new_script_id)}>
            {preview[r.id] ? '收起新稿正文' : '查看待批新稿正文'}
          </button>

          {preview[r.id] && (
            <pre style={{ whiteSpace: 'pre-wrap', background: '#fff', border: '3px solid #0b0f14', borderRadius: 8, padding: 14, marginTop: 10 }}>{preview[r.id].content}</pre>
          )}

          {r.status === 'pending' && (
            reviewing === r.id ? (
              <div style={{ marginTop: 12 }}>
                <Field label="审批批注（可选）">
                  <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="例如：同意，注意同步设备端" />
                </Field>
                <button type="button" className="success" disabled={busy} onClick={() => review(r, true)}>确认批准并切换</button>
                <button type="button" className="danger" disabled={busy} onClick={() => review(r, false)}>确认驳回</button>
                <button type="button" className="secondary" onClick={() => { setReviewing(null); setNote('') }}>取消</button>
              </div>
            ) : (
              <div style={{ marginTop: 12 }}>
                <button type="button" onClick={() => setReviewing(r.id)}>审批</button>
              </div>
            )
          )}
        </article>
      ))}
    </div>
  )
}