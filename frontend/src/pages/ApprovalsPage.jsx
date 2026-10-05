import { useEffect, useState, useCallback } from 'react'
import { api, fmtDateTime, fmtTime, lang } from '../api.js'

const STATUS_LABEL = { pending: '待批准', approved: '已批准切换', rejected: '已驳回' }

export default function ApprovalsPage() {
  const [filter, setFilter] = useState('pending')
  const [items, setItems] = useState([])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [notes, setNotes] = useState({})
  const [busyId, setBusyId] = useState(null)

  const load = useCallback(() => {
    api.get(`/subtitle-switch${filter === 'all' ? '' : `?status=${filter}`}`)
      .then((r) => setItems(r.data))
      .catch((e) => setError(e.message))
  }, [filter])

  useEffect(() => { load() }, [load])

  async function decide(id, approve) {
    setError(''); setNotice('')
    if (approve && !window.confirm('批准后将立即把现场字幕切换为新版本，并记录切换时间。确认批准？')) return
    setBusyId(id)
    try {
      const r = await api.post(`/subtitle-switch/${id}/${approve ? 'approve' : 'reject'}`, {
        decision_note: notes[id]?.trim() || null,
      })
      setNotice(r.message)
      load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <section aria-labelledby="ap-title">
      <h2 id="ap-title">负责人 · 演出中换稿审批</h2>
      <p className="muted">
        演出开演后，技术员更换字幕稿必须经此批准。只有点“批准”后现场当前稿才会切换，系统记录切换时间，旧版本文件保留可查。
      </p>

      <div className="tabs" role="tablist" aria-label="审批筛选">
        {[['pending', '待批准'], ['approved', '已批准'], ['rejected', '已驳回'], ['all', '全部记录']].map(([k, label]) => (
          <button key={k} role="tab" aria-selected={filter === k} onClick={() => setFilter(k)}>
            {label}
          </button>
        ))}
      </div>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {notice && <div className="alert alert-success" role="status">{notice}</div>}

      {items.length === 0 && <div className="alert alert-info">没有{STATUS_LABEL[filter] ? STATUS_LABEL[filter] : ''}的换稿申请。</div>}

      {items.map((r) => (
        <div className={`card${r.status === 'pending' ? ' live' : ''}`} key={r.id}>
          <div className="spread">
            <h3 style={{ margin: 0 }}>
              {r.show_title} · {fmtDateTime(r.starts_at)} · {lang(r.language)}字幕
            </h3>
            <span className={`badge badge-${r.status}`}>{STATUS_LABEL[r.status]}</span>
          </div>
          <dl className="kv" style={{ marginTop: '0.6rem' }}>
            <dt>申请技术员</dt><dd>{r.requested_by}（{fmtDateTime(r.created_at)}）</dd>
            <dt>当前稿</dt><dd>v{r.from_version.version_no} 《{r.from_version.title}》</dd>
            <dt>申请切换到</dt><dd><strong>v{r.to_version.version_no} 《{r.to_version.title}》</strong></dd>
            <dt>换稿原因</dt><dd>{r.reason || '（未填写）'}</dd>
            {r.status !== 'pending' && (
              <>
                <dt>审批人</dt><dd>{r.decided_by}</dd>
                <dt>审批意见</dt><dd>{r.decision_note || '—'}</dd>
                {r.switched_at && (<><dt>实际切换时间</dt><dd><strong>{fmtTime(r.switched_at)}</strong></dd></>)}
              </>
            )}
          </dl>

          {r.status === 'pending' && (
            <>
              <label htmlFor={`note-${r.id}`}>审批意见（可选）</label>
              <input id={`note-${r.id}`} value={notes[r.id] || ''} maxLength={500}
                onChange={(e) => setNotes((n) => ({ ...n, [r.id]: e.target.value }))} />
              <div className="row" style={{ marginTop: '0.8rem' }}>
                <button className="btn-ok" disabled={busyId === r.id} onClick={() => decide(r.id, true)}>批准并立即切换</button>
                <button className="btn-danger" disabled={busyId === r.id} onClick={() => decide(r.id, false)}>驳回，继续用原稿</button>
              </div>
            </>
          )}
        </div>
      ))}
    </section>
  )
}
