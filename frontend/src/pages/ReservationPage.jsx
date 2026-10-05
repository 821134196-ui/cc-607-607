import { useCallback, useEffect, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { api, fmtDateTime, lang, STATUS_LABELS } from '../api.js'

export default function ReservationPage() {
  const { code } = useParams()
  const navigate = useNavigate()
  const location = useLocation()

  const [res, setRes] = useState(null)
  const [screenings, setScreenings] = useState([])
  const [notice, setNotice] = useState(location.state?.justBooked ? '预约成功，凭证如下，散场前请凭此领取设备。' : '')
  const [error, setError] = useState('')

  const [rescheduling, setRescheduling] = useState(false)
  const [newScreeningId, setNewScreeningId] = useState('')
  const [newLanguage, setNewLanguage] = useState('')
  const [rescheduleError, setRescheduleError] = useState('')
  const [busy, setBusy] = useState(false)

  const [lookupCode, setLookupCode] = useState('')

  const load = useCallback(() => {
    api.get(`/reservations/${code}`)
      .then((r) => { setRes(r.data); setError('') })
      .catch((e) => setError(e.message))
  }, [code])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    api.get('/screenings').then((r) => setScreenings(r.data)).catch(() => {})
  }, [])

  const newScreening = screenings.find((s) => String(s.id) === String(newScreeningId))
  const canReschedule = res && ['booked'].includes(res.status)
  const active = res && !['released', 'cancelled'].includes(res.status)

  async function doReschedule() {
    setRescheduleError('')
    if (!newScreeningId || !newLanguage) {
      setRescheduleError('请选择新的场次和语言')
      return
    }
    if (Number(newScreeningId) === res.screening_id && newLanguage === res.language) {
      setRescheduleError('新场次/语言与当前相同，无需改签')
      return
    }
    setBusy(true)
    try {
      const r = await api.post(`/reservations/${code}/reschedule`, {
        screening_id: Number(newScreeningId),
        language: newLanguage,
        audience_name: res.audience_name,
      })
      setRescheduling(false)
      navigate(`/reservations/${r.data.new.code}`, {
        state: { notice: `改签成功：原凭证 ${r.data.old.code} 占用已释放，新凭证 ${r.data.new.code} 生效。` },
      })
    } catch (e) {
      setRescheduleError(e.message)
    } finally {
      setBusy(false)
    }
  }

  async function doCancel() {
    if (!window.confirm(`确认取消预约 ${code}？取消后设备配额立即释放。`)) return
    setBusy(true)
    try {
      await api.post(`/reservations/${code}/cancel`)
      load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  function findByCode(e) {
    e.preventDefault()
    if (lookupCode.trim()) navigate(`/reservations/${lookupCode.trim().toUpperCase()}`)
  }

  return (
    <section aria-labelledby="res-title">
      <h2 id="res-title">我的预约</h2>

      <div className="card">
        <form className="row" onSubmit={findByCode}>
          <label htmlFor="lookup" style={{ margin: 0 }}>凭凭证号查询：</label>
          <input id="lookup" className="mono" style={{ width: '220px' }}
            value={lookupCode} onChange={(e) => setLookupCode(e.target.value)} placeholder="R…" />
          <button type="submit" className="btn-secondary">查询</button>
        </form>
      </div>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {!res && !error && <p>正在加载预约…</p>}

      {res && (
        <>
          {notice && <div className="alert alert-success" role="status">{notice}</div>}
          <div className="card">
            <div className="spread">
              <h3 style={{ margin: 0 }}>预约凭证</h3>
              <span className={`badge badge-${res.status}`}>{STATUS_LABELS[res.status] || res.status}</span>
            </div>
            <p className="code-box" aria-label="预约凭证号">{res.code}</p>
            <dl className="kv">
              <dt>剧目</dt><dd>{res.show_title}</dd>
              <dt>场次</dt><dd>{fmtDateTime(res.starts_at)} · {res.venue}</dd>
              <dt>字幕语言</dt><dd>{lang(res.language)}</dd>
              <dt>观众</dt><dd>{res.audience_name}</dd>
              {res.device && (<><dt>已发设备</dt><dd className="mono">{res.device.serial}</dd></>)}
              {res.release_reason && (<><dt>释放原因</dt><dd>{res.release_reason}</dd></>)}
            </dl>
          </div>

          {active && (
            <div className="card">
              <h3>改签 / 取消</h3>
              {res.status === 'checked_in' && (
                <div className="alert alert-info">设备已发放。如需改签请先到前台归还设备；散场归还后预约即完成。</div>
              )}
              {res.status === 'completed' && (
                <div className="alert alert-success">设备已归还复检，感谢使用无障碍服务。</div>
              )}
              <div className="row">
                {canReschedule && (
                  <button className="btn-secondary" onClick={() => setRescheduling((v) => !v)}
                    aria-expanded={rescheduling}>
                    {rescheduling ? '收起改签' : '我要改签（换场次/语言）'}
                  </button>
                )}
                {canReschedule && (
                  <button className="btn-danger" onClick={doCancel} disabled={busy}>取消预约并释放配额</button>
                )}
              </div>

              {rescheduling && (
                <fieldset style={{ marginTop: '1rem' }}>
                  <legend>改签：选择新场次与语言（原预约将被释放）</legend>
                  <div className="alert alert-warning">
                    提交后系统会在同一事务内先释放原预约 {code} 的占用，再建立新预约；若新场次已满，改签失败且原预约保持有效。
                  </div>
                  <label htmlFor="new-screening">新场次</label>
                  <select id="new-screening" value={newScreeningId} onChange={(e) => { setNewScreeningId(e.target.value); setNewLanguage('') }}>
                    <option value="">请选择场次</option>
                    {screenings.filter((s) => s.state !== 'ended').map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.show_title} · {fmtDateTime(s.starts_at)} · {s.venue}（{STATE(s.state)}）
                      </option>
                    ))}
                  </select>

                  {newScreening && (
                    <>
                      <p className="muted" style={{ marginTop: '0.8rem' }}>新语言（实时可预约数量）：</p>
                      <div className="option-cards" role="radiogroup" aria-label="新字幕语言">
                        {newScreening.languages.map((l) => (
                          <label key={l.language} className={`option-card${l.remaining <= 0 ? ' full' : ''}`}>
                            <input type="radio" name="new-language" value={l.language}
                              checked={newLanguage === l.language} disabled={l.remaining <= 0}
                              onChange={() => setNewLanguage(l.language)}
                              aria-label={`${lang(l.language)}，剩余 ${l.remaining} 台`} />
                            <span className="oc-title">{lang(l.language)}</span><br />
                            <span className="oc-remaining">可预约 {l.remaining} 台</span><br />
                            <span className="oc-meta">共 {l.quota} 台</span>
                          </label>
                        ))}
                      </div>
                    </>
                  )}
                  {rescheduleError && <div className="alert alert-error" role="alert">{rescheduleError}</div>}
                  <div className="row" style={{ marginTop: '0.8rem' }}>
                    <button className="btn-ok" onClick={doReschedule} disabled={busy}>
                      {busy ? '处理中…' : '确认改签'}
                    </button>
                    <button type="button" className="btn-secondary" onClick={() => setRescheduling(false)}>放弃</button>
                  </div>
                </fieldset>
              )}
            </div>
          )}
        </>
      )}
    </section>
  )
}

function STATE(s) {
  return { scheduled: '未开演', live: '演出中', ended: '已结束' }[s] || s
}
