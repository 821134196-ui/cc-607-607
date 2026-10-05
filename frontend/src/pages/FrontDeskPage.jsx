import { useCallback, useEffect, useState } from 'react'
import { api, fmtDateTime, lang } from '../api.js'

export default function FrontDeskPage() {
  const [tab, setTab] = useState('issue')
  return (
    <section aria-labelledby="fd-title">
      <h2 id="fd-title">前台工作台</h2>
      <div className="tabs" role="tablist" aria-label="前台功能">
        <button role="tab" aria-selected={tab === 'issue'} onClick={() => setTab('issue')}>① 凭证查询与设备发放</button>
        <button role="tab" aria-selected={tab === 'return'} onClick={() => setTab('return')}>② 散场收回与复检</button>
      </div>
      {tab === 'issue' ? <IssuePanel /> : <ReturnPanel />}
    </section>
  )
}

function IssuePanel() {
  const [code, setCode] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [deviceId, setDeviceId] = useState('')
  const [battery, setBattery] = useState(100)
  const [physicalOk, setPhysicalOk] = useState(false)
  const [langConfirmed, setLangConfirmed] = useState(false)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [success, setSuccess] = useState('')

  async function lookup(e) {
    e?.preventDefault()
    setError(''); setData(null); setSuccess('')
    if (!code.trim()) { setError('请输入预约凭证号'); return }
    try {
      const r = await api.get(`/frontdesk/reservations/${code.trim().toUpperCase()}`)
      setData(r.data)
      setDeviceId('')
      setBattery(r.data.available_devices[0]?.battery_percent ?? 100)
    } catch (e2) { setError(e2.message) }
  }

  async function issue() {
    setError(''); setSuccess('')
    if (!deviceId) { setError('请扫描/选择实物设备编号'); return }
    if (!physicalOk) { setError('必须确认实物编号与外观核对通过'); return }
    if (!langConfirmed) { setError('必须确认设备字幕语言与预约语言一致'); return }
    setBusy(true)
    try {
      const r = await api.post(`/frontdesk/reservations/${data.code}/issue`, {
        device_id: Number(deviceId),
        battery_percent: Number(battery),
        physical_ok: physicalOk,
        language_confirmed: langConfirmed,
        note: note || null,
      })
      setSuccess(r.message)
      setData(null)
      setCode(''); setPhysicalOk(false); setLangConfirmed(false); setNote('')
    } catch (e2) { setError(e2.message) } finally { setBusy(false) }
  }

  return (
    <div>
      <form className="card" onSubmit={lookup}>
        <h3>1. 输入观众预约凭证号</h3>
        <div className="row">
          <label htmlFor="fd-code" style={{ margin: 0, minWidth: '7rem' }}>凭证号</label>
          <input id="fd-code" className="mono" style={{ width: '220px' }}
            value={code} onChange={(e) => setCode(e.target.value)} placeholder="R…" />
          <button type="submit">查询预约</button>
        </div>
      </form>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {success && <div className="alert alert-success" role="status">{success}</div>}

      {data && (
        <div className="card">
          <h3>2. 核对预约信息</h3>
          <dl className="kv">
            <dt>凭证</dt><dd className="mono">{data.code}（{data.status}）</dd>
            <dt>观众</dt><dd>{data.audience_name}</dd>
            <dt>剧目场次</dt><dd>{data.show_title} · {fmtDateTime(data.starts_at)} · {data.venue}</dd>
            <dt>预约字幕语言</dt><dd><strong>{lang(data.language)}</strong></dd>
          </dl>

          {data.active_device && (
            <div className="alert alert-warning">
              该预约已发放设备 {data.active_device.serial}，不能重复发放。如需更换请先收回旧设备。
            </div>
          )}

          {!data.active_device && data.status === 'booked' && (
            <>
              <h3>3. 发放核对（编号 · 电量 · 字幕语言）</h3>
              <label htmlFor="fd-device">实物设备编号（只列出可发放且支持 {lang(data.language)} 的设备；故障/未归还设备不出现）</label>
              <select id="fd-device" value={deviceId} onChange={(e) => {
                setDeviceId(e.target.value)
                const d = data.available_devices.find((x) => String(x.id) === e.target.value)
                if (d) setBattery(d.battery_percent)
              }}>
                <option value="">请选择 / 扫码选择设备</option>
                {data.available_devices.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.serial} · 电量 {d.battery_percent}% · 支持 {d.supported_languages.map(lang).join('/')}
                  </option>
                ))}
              </select>
              {data.available_devices.length === 0 && (
                <div className="alert alert-error">没有可发放的匹配设备（可能全部占用或故障）</div>
              )}

              <label htmlFor="fd-battery">实测电量（%），低于 20% 不可发放</label>
              <input id="fd-battery" type="number" min="0" max="100" style={{ width: '160px' }}
                value={battery} onChange={(e) => setBattery(e.target.value)} />

              <CheckConfirms
                physicalOk={physicalOk} setPhysicalOk={setPhysicalOk}
                langConfirmed={langConfirmed} setLangConfirmed={setLangConfirmed}
                language={data.language} serial={data.available_devices.find((x) => String(x.id) === String(deviceId))?.serial}
              />

              <label htmlFor="fd-note">备注（可选）</label>
              <input id="fd-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />

              <div style={{ marginTop: '1rem' }}>
                <button className="btn-ok" onClick={issue} disabled={busy}>
                  {busy ? '发放中…' : '核对无误，发放设备'}
                </button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}

function CheckConfirms({ physicalOk, setPhysicalOk, langConfirmed, setLangConfirmed, language, serial }) {
  return (
    <fieldset>
      <legend>逐项确认（必须全部勾选）</legend>
      <label style={{ display: 'flex', gap: '0.7rem', alignItems: 'center', margin: '0.4rem 0' }}>
        <input type="checkbox" style={{ width: '1.6em', height: '1.6em' }}
          checked={physicalOk} onChange={(e) => setPhysicalOk(e.target.checked)} />
        <span>实物编号 {serial ? <strong className="mono">{serial}</strong> : '（待选设备）'} 与所选设备一致，外观与按键正常</span>
      </label>
      <label style={{ display: 'flex', gap: '0.7rem', alignItems: 'center', margin: '0.4rem 0' }}>
        <input type="checkbox" style={{ width: '1.6em', height: '1.6em' }}
          checked={langConfirmed} onChange={(e) => setLangConfirmed(e.target.checked)} />
        <span>开机自检字幕语言为 <strong>{lang(language)}</strong>，与预约一致</span>
      </label>
    </fieldset>
  )
}

function ReturnPanel() {
  const [items, setItems] = useState([])
  const [screeningId, setScreeningId] = useState('')
  const [screenings, setScreenings] = useState([])
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [form, setForm] = useState({})

  const load = useCallback(() => {
    const qs = screeningId ? `?screening_id=${screeningId}` : ''
    api.get(`/frontdesk/outstanding${qs}`).then((r) => setItems(r.data)).catch((e) => setError(e.message))
  }, [screeningId])

  useEffect(() => { api.get('/screenings').then((r) => setScreenings(r.data)) }, [])
  useEffect(() => { load() }, [load])

  function getForm(id) {
    return form[id] || { battery_percent: 50, physical_ok: false, language_confirmed: false, faulty: false, note: '' }
  }
  function update(id, patch) {
    setForm((f) => ({ ...f, [id]: { ...getForm(id), ...patch } }))
  }

  async function submitReturn(id) {
    setError(''); setSuccess('')
    const v = getForm(id)
    try {
      const r = await api.post('/frontdesk/return', { assignment_id: id, ...v })
      setSuccess(r.message)
      load()
    } catch (e) { setError(e.message) }
  }

  return (
    <div>
      <div className="card">
        <div className="row">
          <label htmlFor="ret-screen" style={{ margin: 0 }}>按场次筛选：</label>
          <select id="ret-screen" style={{ width: '360px' }} value={screeningId}
            onChange={(e) => setScreeningId(e.target.value)}>
            <option value="">全部未归还设备</option>
            {screenings.map((s) => (
              <option key={s.id} value={s.id}>{s.show_title} · {fmtDateTime(s.starts_at)}</option>
            ))}
          </select>
          <button className="btn-secondary" onClick={load}>刷新列表</button>
        </div>
      </div>

      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {success && <div className="alert alert-success" role="status">{success}</div>}

      {items.length === 0 && <div className="alert alert-info">没有未归还设备。</div>}

      {items.map((it) => {
        const v = getForm(it.assignment_id)
        return (
          <div className="card" key={it.assignment_id}>
            <div className="spread">
              <h3 style={{ margin: 0 }}>设备 <span className="mono">{it.device_serial}</span></h3>
              <span className="badge badge-assigned">未归还</span>
            </div>
            <dl className="kv">
              <dt>对应凭证</dt><dd className="mono">{it.reservation_code} · {it.audience_name}</dd>
              <dt>场次</dt><dd>{it.show_title} · {fmtDateTime(it.starts_at)}</dd>
              <dt>预约语言</dt><dd>{lang(it.language)}</dd>
              <dt>发放时间</dt><dd>{fmtDateTime(it.issued_at)}</dd>
            </dl>

            <label>归还实测电量（%）
              <input type="number" min="0" max="100" style={{ width: '160px' }} value={v.battery_percent}
                onChange={(e) => update(it.assignment_id, { battery_percent: Number(e.target.value) })} />
            </label>
            <fieldset>
              <legend>散场复检</legend>
              <label style={{ display: 'flex', gap: '0.7rem', alignItems: 'center', margin: '0.4rem 0' }}>
                <input type="checkbox" style={{ width: '1.6em', height: '1.6em' }} checked={v.physical_ok}
                  onChange={(e) => update(it.assignment_id, { physical_ok: e.target.checked, faulty: e.target.checked ? v.faulty : true })} />
                外观/编号正常，无损坏
              </label>
              <label style={{ display: 'flex', gap: '0.7rem', alignItems: 'center', margin: '0.4rem 0' }}>
                <input type="checkbox" style={{ width: '1.6em', height: '1.6em' }} checked={v.language_confirmed}
                  onChange={(e) => update(it.assignment_id, { language_confirmed: e.target.checked })} />
                字幕语言配置仍为 {lang(it.language)}
              </label>
              <label style={{ display: 'flex', gap: '0.7rem', alignItems: 'center', margin: '0.4rem 0' }}>
                <input type="checkbox" style={{ width: '1.6em', height: '1.6em' }} checked={v.faulty}
                  onChange={(e) => update(it.assignment_id, { faulty: e.target.checked, physical_ok: e.target.checked ? false : v.physical_ok })} />
                <strong style={{ color: 'var(--danger)' }}>发现故障（勾选后设备将被锁定，不能分配给下一场）</strong>
              </label>
            </fieldset>
            <label>故障/备注说明
              <input value={v.note} maxLength={500}
                onChange={(e) => update(it.assignment_id, { note: e.target.value })} />
            </label>
            <div style={{ marginTop: '0.8rem' }}>
              <button className="btn-ok" onClick={() => submitReturn(it.assignment_id)}>
                收回设备并完成复检
              </button>
            </div>
          </div>
        )
      })}
    </div>
  )
}
