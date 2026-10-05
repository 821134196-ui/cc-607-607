import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, fmtDateTime, lang } from '../api.js'

const STATE_LABEL = { scheduled: '未开演', live: '演出中', ended: '已结束' }

export default function BookingPage() {
  const navigate = useNavigate()
  const [screenings, setScreenings] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [screeningId, setScreeningId] = useState('')
  const [language, setLanguage] = useState('')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [ticketCode, setTicketCode] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState('')

  useEffect(() => {
    api.get('/screenings')
      .then((res) => setScreenings(res.data))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  const selected = screenings.find((s) => String(s.id) === String(screeningId)) || null
  const [detail, setDetail] = useState(null)

  useEffect(() => {
    setDetail(null)
    if (!screeningId) return
    let alive = true
    api.get(`/screenings/${screeningId}`).then((r) => alive && setDetail(r.data)).catch(() => {})
    return () => { alive = false }
  }, [screeningId])

  function selectScreening(id) {
    setScreeningId(id)
    setLanguage('')
  }

  async function submit(e) {
    e.preventDefault()
    setFormError('')
    if (!screeningId) { setFormError('请先选择一场演出'); return }
    if (!language) { setFormError('请选择字幕语言'); return }
    if (!name.trim()) { setFormError('请填写观众姓名'); return }

    setSubmitting(true)
    try {
      const body = {
        screening_id: Number(screeningId),
        language,
        audience_name: name.trim(),
      }
      if (phone.trim()) body.audience_phone = phone.trim()
      if (ticketCode.trim()) body.ticket_code = ticketCode.trim()
      const res = await api.post('/reservations', body)
      navigate(`/reservations/${res.data.code}`, { state: { justBooked: true } })
    } catch (err) {
      setFormError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section aria-labelledby="booking-title">
      <h2 id="booking-title">预约字幕辅助设备</h2>
      <p className="muted">选择场次和语言后可看到可预约数量。整个流程可用键盘完成：<strong>Tab</strong> 移动，<strong>方向键</strong> 在选项间切换，<strong>Enter</strong> 提交。</p>

      {loading && <p>正在加载场次…</p>}
      {error && <div className="alert alert-error" role="alert">{error}</div>}

      <form onSubmit={submit} noValidate>
        <fieldset>
          <legend>第 1 步：选择演出场次</legend>
          <div className="option-cards" role="radiogroup" aria-label="演出场次">
            {screenings.map((s) => {
              const full = s.state === 'ended'
              return (
                <label key={s.id} className={`option-card${full ? ' full' : ''}`}>
                  <input type="radio" name="screening" value={s.id}
                    checked={String(screeningId) === String(s.id)}
                    disabled={full}
                    onChange={() => selectScreening(s.id)}
                    aria-label={`${s.show_title}，${fmtDateTime(s.starts_at)}，${s.venue}，${STATE_LABEL[s.state]}`} />
                  <span className="oc-title">{s.show_title}</span><br />
                  <span className="oc-meta">{fmtDateTime(s.starts_at)} · {s.venue}</span><br />
                  <span className={`badge badge-${s.state}`}>{STATE_LABEL[s.state]}</span>
                </label>
              )
            })}
          </div>
        </fieldset>

        {selected && (
          <fieldset>
            <legend>第 2 步：选择字幕语言（显示可预约数量）</legend>
            <div className="option-cards" role="radiogroup" aria-label="字幕语言">
              {selected.languages.map((l) => {
                const full = l.remaining <= 0
                return (
                  <label key={l.language} className={`option-card${full ? ' full' : ''}`}>
                    <input type="radio" name="language" value={l.language}
                      checked={language === l.language}
                      disabled={full}
                      onChange={() => setLanguage(l.language)}
                      aria-label={`${lang(l.language)}，剩余 ${l.remaining} 台，共 ${l.quota} 台`} />
                    <span className="oc-title">{lang(l.language)}</span><br />
                    <span className="oc-remaining">可预约 {l.remaining} 台</span><br />
                    <span className="oc-meta">共 {l.quota} 台 · 已约 {l.reserved} 台</span>
                  </label>
                )
              })}
            </div>
            <p className="small muted">
              模拟票号可选填（本场有效票：{detail?.sample_ticket_codes?.join('、') || '加载中…'}）。
              不填票号也可预约。
            </p>
          </fieldset>
        )}

        <fieldset>
          <legend>第 3 步：观众信息</legend>
          <label htmlFor="name">观众姓名 *</label>
          <input id="name" type="text" value={name} onChange={(e) => setName(e.target.value)}
            autoComplete="name" required maxLength={50} />

          <label htmlFor="phone">手机号（用于接收预约通知，可选）</label>
          <input id="phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)}
            autoComplete="tel" inputMode="tel" maxLength={30} />

          <label htmlFor="ticket">票务系统票号（可选，将通过本地模拟票务接口核验）</label>
          <input id="ticket" type="text" value={ticketCode} onChange={(e) => setTicketCode(e.target.value)}
            className="mono" maxLength={50} placeholder="例如 T001-S1" />
        </fieldset>

        {formError && <div className="alert alert-error" role="alert">{formError}</div>}

        <button type="submit" className="btn-lg" disabled={submitting}>
          {submitting ? '正在提交预约…' : '确认预约'}
        </button>
      </form>
    </section>
  )
}
