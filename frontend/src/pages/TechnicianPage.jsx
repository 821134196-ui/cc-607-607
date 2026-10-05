import { useEffect, useMemo, useState } from 'react'
import { api, fmtDateTime, fmtTime, lang } from '../api.js'

const STATE_LABEL = { scheduled: '未开演', live: '演出中', ended: '已结束' }

export default function TechnicianPage() {
  const [screenings, setScreenings] = useState([])
  const [screeningId, setScreeningId] = useState('')
  const [language, setLanguage] = useState('zh')
  const [data, setData] = useState(null)
  const [requests, setRequests] = useState([])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [switchTo, setSwitchTo] = useState('')
  const [switchReason, setSwitchReason] = useState('')
  const [viewContent, setViewContent] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get('/screenings').then((r) => {
      setScreenings(r.data)
      const live = r.data.find((s) => s.state === 'live') || r.data[0]
      if (live) setScreeningId(String(live.id))
    })
  }, [])

  const screening = useMemo(
    () => screenings.find((s) => String(s.id) === screeningId),
    [screenings, screeningId]
  )

  const loadData = () => {
    if (!screeningId) return
    api.get(`/screenings/${screeningId}/subtitles/${language}`)
      .then((r) => setData(r.data))
      .catch((e) => setError(e.message))
    api.get(`/screenings/${screeningId}/subtitle-switch`)
      .then((r) => setRequests(r.data))
      .catch(() => setRequests([]))
  }
  useEffect(loadData, [screeningId, language])

  async function publish() {
    setError(''); setNotice('')
    if (!content.trim()) { setError('字幕内容不能为空'); return }
    setBusy(true)
    try {
      const r = await api.post(`/screenings/${screeningId}/subtitles`, {
        language, title: title.trim(), content,
      })
      setNotice(r.message)
      setTitle(''); setContent('')
      loadData()
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  async function requestSwitch() {
    setError(''); setNotice('')
    if (!switchTo) { setError('请选择要切换到的新版本'); return }
    setBusy(true)
    try {
      const r = await api.post(`/screenings/${screeningId}/subtitle-switch`, {
        language, to_version_id: Number(switchTo), reason: switchReason.trim() || null,
      })
      setNotice(r.message)
      setSwitchTo(''); setSwitchReason('')
      loadData()
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  async function openVersion(v) {
    try {
      const r = await api.get(`/subtitle-versions/${v.id}/content`)
      setViewContent(r.data)
    } catch (e) { setError(e.message) }
  }

  const isLive = data?.screening.state === 'live'
  const switchable = (data?.versions || []).filter((v) => !v.is_current)

  return (
    <section aria-labelledby="tech-title">
      <h2 id="tech-title">技术员 · 字幕版本管理</h2>

      <div className="card">
        <div className="row">
          <div style={{ flex: '1 1 320px' }}>
            <label htmlFor="tech-screen" style={{ marginTop: 0 }}>选择场次（字幕版本必须对应场次）</label>
            <select id="tech-screen" value={screeningId} onChange={(e) => setScreeningId(e.target.value)}>
              {screenings.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.show_title} · {fmtDateTime(s.starts_at)} · {s.venue}（{STATE_LABEL[s.state]}）
                </option>
              ))}
            </select>
          </div>
          <fieldset style={{ margin: 0, border: 'none', padding: 0 }}>
            <legend className="muted" style={{ padding: 0, marginBottom: '0.2rem' }}>语言</legend>
            <div className="row">
              {(screening?.languages || []).map((l) => (
                <button key={l.language}
                  aria-pressed={language === l.language}
                  onClick={() => setLanguage(l.language)}
                  className={language === l.language ? '' : 'btn-secondary'}>
                  {lang(l.language)}
                </button>
              ))}
            </div>
          </fieldset>
        </div>
      </div>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {notice && <div className="alert alert-success" role="status">{notice}</div>}

      {data && (
        <>
          <div className={`card${isLive ? ' live' : ''}`}>
            <div className="spread">
              <h3 style={{ margin: 0 }}>
                当前稿：{data.versions.find((v) => v.is_current)
                  ? `v${data.versions.find((v) => v.is_current).version_no} ${data.versions.find((v) => v.is_current).title}`
                  : '尚未设置'}
              </h3>
              <span className={`badge badge-${data.screening.state}`}>{STATE_LABEL[data.screening.state]}</span>
            </div>
            <p className="muted small">
              场次时间 {fmtDateTime(data.screening.starts_at)} – {fmtDateTime(data.screening.ends_at)}。
              {isLive
                ? ' 演出进行中：发布的新版本只保存为历史版本，不会自动生效；需在此发起换稿并由负责人批准。'
                : data.screening.state === 'ended'
                  ? ' 演出已结束，不能再发布或切换。'
                  : ' 演出前：发布新版本会自动成为当前稿。'}
            </p>
          </div>

          <div className="card">
            <h3>版本历史（旧版本文件永久保留，可查看内容）</h3>
            <div className="tablescroll">
              <table>
                <thead>
                  <tr><th>版本</th><th>名称</th><th>发布人</th><th>发布时间</th><th>文件（SHA-256）</th><th>状态</th><th>操作</th></tr>
                </thead>
                <tbody>
                  {data.versions.length === 0 && (
                    <tr><td colSpan="7" className="muted">暂无版本，请在下方发布第一版字幕。</td></tr>
                  )}
                  {[...data.versions].reverse().map((v) => (
                    <tr key={v.id}>
                      <td><strong>v{v.version_no}</strong></td>
                      <td>{v.title}</td>
                      <td>{v.published_by}</td>
                      <td>{fmtDateTime(v.published_at)}</td>
                      <td className="small mono">{v.file_path}<br />{v.file_hash.slice(0, 16)}…</td>
                      <td>{v.is_current ? <span className="badge badge-approved">当前使用</span> : <span className="badge badge-ended">历史版本</span>}</td>
                      <td><button className="btn-secondary" onClick={() => openVersion(v)}>查看文件内容</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {viewContent && (
            <div className="card" role="dialog" aria-modal="false" aria-label={`字幕文件 ${viewContent.title}`}>
              <div className="spread">
                <h3 style={{ margin: 0 }}>{viewContent.title} <span className="muted small">{viewContent.file_path}</span></h3>
                <button className="btn-secondary" onClick={() => setViewContent(null)}>关闭</button>
              </div>
              <pre className="subtitle-content">{viewContent.content}</pre>
            </div>
          )}

          {data.screening.state !== 'ended' && (
            <div className="card">
              <h3>发布新版本（对应本场 · {lang(language)}）</h3>
              <label htmlFor="sub-title">版本名称（可选）</label>
              <input id="sub-title" value={title} onChange={(e) => setTitle(e.target.value)}
                placeholder={`例如：${lang(language)}字幕·修订稿`} maxLength={100} />
              <label htmlFor="sub-content">字幕内容（SRT 文本）*</label>
              <textarea id="sub-content" value={content} onChange={(e) => setContent(e.target.value)}
                placeholder={'1\n00:00:01,000 --> 00:00:04,000\n台词内容…'} />
              <div style={{ marginTop: '0.7rem' }}>
                <button className="btn-ok" onClick={publish} disabled={busy}>
                  {busy ? '保存中…' : isLive ? '保存为新版本（不切换）' : '发布并设为当前稿'}
                </button>
              </div>
            </div>
          )}

          {isLive && (
            <div className="card live">
              <h3>演出中更换字幕稿（需负责人批准）</h3>
              {requests.filter((r) => r.status === 'pending').length > 0 && (
                <div className="alert alert-warning">本场已有待批准的换稿申请，请等待负责人处理。</div>
              )}
              <label htmlFor="switch-to">选择要切换到的历史新版本</label>
              <select id="switch-to" value={switchTo} onChange={(e) => setSwitchTo(e.target.value)}>
                <option value="">请选择新版本（不能是当前稿）</option>
                {switchable.map((v) => (
                  <option key={v.id} value={v.id}>v{v.version_no} · {v.title}（{fmtDateTime(v.published_at)} 发布）</option>
                ))}
              </select>
              <label htmlFor="switch-reason">换稿原因（将展示给负责人）</label>
              <input id="switch-reason" value={switchReason} onChange={(e) => setSwitchReason(e.target.value)}
                placeholder="例如：第三幕台词时间轴错位，已修正" maxLength={500} />
              <div style={{ marginTop: '0.7rem' }}>
                <button onClick={requestSwitch} disabled={busy}>发起换稿申请</button>
              </div>
            </div>
          )}

          <div className="card">
            <h3>本场换稿申请记录</h3>
            <div className="tablescroll">
              <table>
                <thead>
                  <tr><th>申请时间</th><th>语言</th><th>从</th><th>到</th><th>原因</th><th>状态</th><th>切换时间/审批</th></tr>
                </thead>
                <tbody>
                  {requests.length === 0 && <tr><td colSpan="7" className="muted">暂无换稿申请。</td></tr>}
                  {requests.map((r) => (
                    <tr key={r.id}>
                      <td>{fmtDateTime(r.created_at)}</td>
                      <td>{lang(r.language)}</td>
                      <td>v{r.from_version.version_no}</td>
                      <td>v{r.to_version.version_no}</td>
                      <td>{r.reason || '—'}</td>
                      <td><span className={`badge badge-${r.status}`}>{STATUS(r.status)}</span></td>
                      <td>
                        {r.switched_at ? `已于 ${fmtTime(r.switched_at)} 切换` : '—'}
                        {r.decided_by ? <><br /><span className="small muted">{r.decided_by}{r.decision_note ? `：${r.decision_note}` : ''}</span></> : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </section>
  )
}

function STATUS(s) {
  return { pending: '待批准', approved: '已批准切换', rejected: '已驳回' }[s] || s
}
