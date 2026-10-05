import React, { useEffect, useState } from 'react'
import { api, languageName, formatTime } from '../api.js'
import {Badge, ErrorBox, Alert, Loading} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function CaptionsPage() {
  const [performances, setPerformances] = useState([])
  const [performanceId, setPerformanceId] = useState('')
  const [performance, setPerformance] = useState(null)
  const [language, setLanguage] = useState('zh')
  const [history, setHistory] = useState([])
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [showHistoryId, setShowHistoryId] = useState(null)
  const [oldContent, setOldContent] = useState(null)
  const [loading, setLoading] = useState(false)

  // 新字幕稿表单
  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api('/performances').then((ps) => {
      setPerformances(ps)
      if (ps[0]) setPerformanceId(String(ps[0].id))
    }).catch(setError)
  }, [])

  async function loadHistory(pid, lang) {
    setLoading(true)
    setError(null)
    try {
      const data = await api(`/performances/${pid}/captions/${lang}/history`)
      setHistory(data.scripts)
      setPerformance(data)
    } catch (e) { setError(e) } finally { setLoading(false) }
  }

  useEffect(() => {
    if (performanceId) loadHistory(performanceId, language)
  }, [performanceId, language])

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const p = performances.find((x) => x.id === Number(performanceId))
      if (p.has_started) {
        // 演出中：发起换稿申请（需负责人批准）
        await api(`/performances/${performanceId}/caption-change-requests`, {
          method: 'POST',
          body: { language, title, content, reason },
        })
        setNotice('演出已开演：换稿申请已提交，需负责人批准后才会切换，旧版文件会保留。')
      } else {
        await api(`/performances/${performanceId}/captions`, {
          method: 'POST',
          body: { language, title, content },
        })
        setNotice('开演前：新字幕版本已直接发布为当前版本，旧版本已保留。')
      }
      setTitle(''); setContent(''); setReason('')
      loadHistory(performanceId, language)
    } catch (err) { setError(err) } finally { setBusy(false) }
  }

  async function viewOld(s) {
    if (showHistoryId === s.id) { setShowHistoryId(null); setOldContent(null); return }
    try {
      const data = await api(`/captions/scripts/${s.id}`)
      setOldContent(data)
      setShowHistoryId(s.id)
    } catch (e) { setError(e) }
  }

  const currentPerf = performances.find((x) => x.id === Number(performanceId))
  const isLive = currentPerf?.has_started && !currentPerf?.has_finished
  const isFinished = currentPerf?.has_finished

  return (
    <div>
      <h2>技术员 · 字幕版本管理</h2>
      <p className="muted">字幕版本必须对应具体场次与语言。演出开始后更换字幕稿，<strong>必须发起申请并由负责人批准</strong>，系统会记录切换时间并保留所有旧文件。</p>

      <div className="card">
        <ErrorBox error={error} />
        <div className="row">
          <Field label="场次">
            <select value={performanceId} onChange={(e) => setPerformanceId(e.target.value)} style={{ minWidth: 320 }}>
              {performances.map((p) => (
                <option key={p.id} value={p.id}>{p.title}（{formatTime(p.starts_at)}）{p.has_finished ? ' · 已结束' : p.has_started ? ' · 演出中' : ' · 未开演'}</option>
              ))}
            </select>
          </Field>
          <Field label="语言">
            <select value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="zh">中文 zh</option>
              <option value="en">English en</option>
              <option value="ja">日本語 ja</option>
            </select>
          </Field>
        </div>
      </div>

      <div className="card">
        <h3>历史版本（旧文件永久保留，可随时查看正文）</h3>
        {loading ? <Loading /> : history.length === 0 ? (
          <p className="muted">该场次此语言暂无字幕版本。</p>
        ) : (
          <table>
            <thead>
              <tr><th scope="col">版本</th><th scope="col">标题</th><th scope="col">状态</th><th scope="col">发布/切换时间</th><th scope="col">校验和</th><th scope="col">查看</th></tr>
            </thead>
            <tbody>
              {[...history].reverse().map((s) => (
                <React.Fragment key={s.id}>
                  <tr>
                    <td><strong>v{s.version_no}</strong></td>
                    <td>{s.title}</td>
                    <td><Badge status={s.status} /></td>
                    <td>{s.status === 'active' ? `生效 ${formatTime(s.activated_at)}` : `发布 ${formatTime(s.published_at)}`}</td>
                    <td className="small" style={{ fontFamily: 'monospace' }}>{s.checksum.slice(0, 12)}…</td>
                    <td><button type="button" className="secondary" aria-expanded={showHistoryId === s.id} onClick={() => viewOld(s)}>{showHistoryId === s.id ? '收起正文' : '查看正文'}</button></td>
                  </tr>
                  {showHistoryId === s.id && oldContent && (
                    <tr><td colSpan="6">
                      <pre style={{ whiteSpace: 'pre-wrap', background: '#fff', border: '3px solid #0b0f14', borderRadius: 8, padding: 14, margin: 0 }}>{oldContent.content}</pre>
                      <p className="small muted" style={{ margin: '6px 0 0' }}>
                        文件：{oldContent.file_path || '（仅数据库版本）'} ｜ 完整性校验：
                        {oldContent.file_intact === true ? '✅ 与校验和一致（未被篡改）' : oldContent.file_intact === false ? '❌ 文件缺失或不一致' : '—'}
                      </p>
                    </td></tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="card">
        <h3>{isLive ? '演出中 · 发起换稿申请（需负责人批准）' : isFinished ? '演出已结束' : '开演前 · 直接发布新版本'}</h3>
        {isLive && <Alert kind="error">演出已开始。提交后不会立即切换，必须等待负责人批准；批准时记录切换时间，当前版本保留为历史文件。</Alert>}
        {isFinished && <Alert kind="info">该场次已结束，通常不再更换字幕稿。</Alert>}
        <form onSubmit={submit}>
          <Field label="新版本标题">
            <input value={title} onChange={(e) => setTitle(e.target.value)} required placeholder="例如：河流·中文 v2（修订第三段）" />
          </Field>
          <Field label="字幕稿正文（SRT 风格，可粘贴）">
            <textarea value={content} onChange={(e) => setContent(e.target.value)} required
              placeholder={"1\n00:00:01 --> 00:00:05\n第一句字幕……\n"} />
          </Field>
          {isLive && (
            <Field label="换稿原因（将展示给负责人）">
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} required minLength={2}
                style={{ minHeight: 90 }} placeholder="例如：第三段台词现场调整，需同步字幕" />
            </Field>
          )}
          <button type="submit" disabled={busy || isFinished}>
            {busy ? '提交中…' : isLive ? '提交换稿申请' : '发布新版本'}
          </button>
        </form>
        {notice && <Alert kind="success">{notice}</Alert>}
      </div>
    </div>
  )
}