import React, { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams, Link } from 'react-router-dom'
import { api, languageName } from '../api.js'
import {ErrorBox, Alert} from '../components/Ui.jsx'
import Field from '../components/Field.jsx'

export default function BookingPage() {
  const { performanceId } = useParams()
  const [params] = useSearchParams()
  const navigate = useNavigate()

  const [performance, setPerformance] = useState(null)
  const [language, setLanguage] = useState(params.get('lang') || '')
  const [ticketCode, setTicketCode] = useState('')
  const [phone, setPhone] = useState('')
  const [contactName, setContactName] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api(`/performances/${performanceId}`).then(setPerformance).catch(setError)
  }, [performanceId])

  useEffect(() => {
    if (!language && performance?.languages?.[0]) setLanguage(performance.languages[0].language)
  }, [performance, language])

  const selected = performance?.languages?.find((l) => l.language === language)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/reservations', {
        method: 'POST',
        body: {
          performance_id: Number(performanceId),
          language,
          ticket_code: ticketCode.trim().toUpperCase(),
          contact_phone: phone.trim() || null,
          contact_name: contactName.trim() || null,
        },
      })
      navigate('/my-reservations', { state: { justBooked: true } })
    } catch (err) {
      setError(err)
      setBusy(false)
    }
  }

  if (error && !performance) return <ErrorBox error={error} />
  if (!performance) return <p className="muted">加载中…</p>

  return (
    <div>
      <Link to="/performances" className="small">← 返回场次列表</Link>
      <h2>预约《{performance.title}》</h2>

      <div className="card">
        <ErrorBox error={error} />
        <form onSubmit={submit}>
          <fieldset>
            <legend>第 1 步：选择字幕语言</legend>
            <div role="radiogroup" aria-label="字幕语言">
              {performance.languages.map((l) => (
                <label key={l.language} style={{ display: 'flex', alignItems: 'center', gap: 12, fontWeight: 700 }}>
                  <input
                    type="radio"
                    name="language"
                    value={l.language}
                    checked={language === l.language}
                    onChange={() => setLanguage(l.language)}
                    style={{ width: 26, height: 26, minHeight: 26 }}
                    required
                  />
                  <span style={{ fontSize: '1.15rem' }}>
                    {languageName(l.language)} — 当前可预约
                    <strong className={l.remaining === 0 ? 'quota-zero' : 'quota-ok'} style={{ fontSize: '1.3rem', margin: '0 4px' }}>
                      {l.remaining}
                    </strong>
                    台 / 共 {l.capacity} 台
                  </span>
                </label>
              ))}
            </div>
          </fieldset>

          <fieldset>
            <legend>第 2 步：核对票务信息</legend>
            <Field label="票号（模拟票务接口）" hint={`格式：T-${performanceId}-XXXX，例如 T-${performanceId}-A1B2`}>
              <input
                value={ticketCode}
                onChange={(e) => setTicketCode(e.target.value)}
                placeholder={`T-${performanceId}-A1B2`}
                required
                aria-required="true"
                autoCapitalize="characters"
              />
            </Field>
            <Field label="联系电话（用于领取/归还通知，可选）">
              <input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" placeholder="13800000000" />
            </Field>
            <Field label="称谓（可选）">
              <input value={contactName} onChange={(e) => setContactName(e.target.value)} />
            </Field>
          </fieldset>

          {selected && selected.remaining === 0 && (
            <Alert kind="error">该语言设备已约满，请选择其他语言。</Alert>
          )}

          <button type="submit" disabled={busy || (selected?.remaining === 0)}>
            {busy ? '提交中…' : `确认预约${language ? `（${languageName(language)}）` : ''}`}
          </button>
        </form>
      </div>
    </div>
  )
}