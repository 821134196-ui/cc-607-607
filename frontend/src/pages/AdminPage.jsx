import { useCallback, useEffect, useState } from 'react'
import { api, DEVICE_STATUS_LABELS, fmtDateTime, lang } from '../api.js'

const PHASE_LABEL = { issue: '发放核对', return: '散场复检' }

export default function AdminPage() {
  const [tab, setTab] = useState('devices')
  return (
    <section aria-labelledby="ad-title">
      <h2 id="ad-title">后台总览</h2>
      <div className="tabs" role="tablist" aria-label="后台功能">
        <button role="tab" aria-selected={tab === 'devices'} onClick={() => setTab('devices')}>设备占用</button>
        <button role="tab" aria-selected={tab === 'checks'} onClick={() => setTab('checks')}>检查结果</button>
        <button role="tab" aria-selected={tab === 'outstanding'} onClick={() => setTab('outstanding')}>未归还清单</button>
        <button role="tab" aria-selected={tab === 'notifications'} onClick={() => setTab('notifications')}>模拟通知记录</button>
      </div>
      {tab === 'devices' && <DevicesPanel />}
      {tab === 'checks' && <ChecksPanel />}
      {tab === 'outstanding' && <OutstandingPanel />}
      {tab === 'notifications' && <NotificationsPanel />}
    </section>
  )
}

function DevicesPanel() {
  const [data, setData] = useState(null)
  const [status, setStatus] = useState('')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  const load = useCallback(() => {
    api.get(`/admin/devices${status ? `?status=${status}` : ''}`).then((r) => setData(r)).catch((e) => setError(e.message))
  }, [status])
  useEffect(() => { load() }, [load])

  async function repair(id, serial) {
    if (!window.confirm(`确认设备 ${serial} 已维修并复检通过，恢复为可发放？`)) return
    try {
      const r = await api.post(`/admin/devices/${id}/repair`)
      setNotice(r.message); load()
    } catch (e) { setError(e.message) }
  }

  if (!data) return <p>加载中…</p>

  return (
    <div>
      {error && <div className="alert alert-error" role="alert">{error}</div>}
      {notice && <div className="alert alert-success" role="status">{notice}</div>}

      <div className="stat-row">
        <div className="stat"><div className="n">{data.summary.total}</div><div className="l">设备总数</div></div>
        <div className="stat ok"><div className="n">{data.summary.available}</div><div className="l">可发放</div></div>
        <div className="stat busy"><div className="n">{data.summary.assigned}</div><div className="l">占用中（未归还）</div></div>
        <div className="stat bad"><div className="n">{data.summary.faulty}</div><div className="l">故障锁定</div></div>
      </div>

      <div className="card" style={{ marginTop: '1rem' }}>
        <div className="row">
          <label htmlFor="dev-filter" style={{ margin: 0 }}>状态筛选：</label>
          <select id="dev-filter" style={{ width: '220px' }} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">全部</option>
            <option value="available">可发放</option>
            <option value="assigned">占用中</option>
            <option value="faulty">故障锁定</option>
          </select>
          <button className="btn-secondary" onClick={load}>刷新</button>
        </div>
      </div>

      <div className="tablescroll card">
        <table>
          <thead>
            <tr><th>实物编号</th><th>状态</th><th>电量</th><th>支持语言</th><th>占用对象（凭证/观众/场次）</th><th>故障备注</th><th>操作</th></tr>
          </thead>
          <tbody>
            {data.data.map((d) => (
              <tr key={d.id}>
                <td className="mono"><strong>{d.serial}</strong></td>
                <td><span className={`badge badge-${d.status}`}>{DEVICE_STATUS_LABELS[d.status]}</span></td>
                <td>{d.battery_percent}%</td>
                <td>{d.supported_languages.map(lang).join(' / ')}</td>
                <td>
                  {d.occupied_by ? (
                    <>
                      <span className="mono">{d.occupied_by.reservation_code}</span> · {d.occupied_by.audience_name}
                      <br /><span className="small muted">{d.occupied_by.show_title} · {fmtDateTime(d.occupied_by.starts_at)} · {lang(d.occupied_by.language)}</span>
                      <br /><span className="small muted">发放于 {fmtDateTime(d.occupied_by.issued_at)}</span>
                    </>
                  ) : <span className="muted">—</span>}
                </td>
                <td>{d.fault_note || '—'}</td>
                <td>
                  {d.status === 'faulty'
                    ? <button className="btn-secondary" onClick={() => repair(d.id, d.serial)}>维修恢复</button>
                    : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ChecksPanel() {
  const [items, setItems] = useState(null)
  useEffect(() => {
    api.get('/admin/checks').then((r) => setItems(r.data))
  }, [])
  if (!items) return <p>加载中…</p>
  return (
    <div className="tablescroll card">
      <table>
        <thead>
          <tr><th>时间</th><th>阶段</th><th>设备</th><th>剧目/凭证</th><th>电量</th><th>实物核对</th><th>语言确认</th><th>结果</th><th>检查人</th><th>备注</th></tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan="10" className="muted">暂无检查记录。</td></tr>}
          {items.map((c) => (
            <tr key={c.id}>
              <td className="small">{fmtDateTime(c.checked_at)}</td>
              <td>{PHASE_LABEL[c.phase]}</td>
              <td className="mono">{c.device_serial}</td>
              <td>{c.show_title}<br /><span className="small mono">{c.reservation_code}</span></td>
              <td>{c.battery_percent}%</td>
              <td>{c.physical_ok ? '✓ 通过' : '✗ 异常'}</td>
              <td>{c.language_confirmed ? '✓ 一致' : '✗ 不一致'}</td>
              <td><span className={`badge badge-${c.result === 'pass' ? 'completed' : 'faulty'}`}>
                {c.result === 'pass' ? '合格' : '不合格'}
              </span></td>
              <td>{c.inspector}</td>
              <td className="small">{c.note || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OutstandingPanel() {
  const [items, setItems] = useState(null)
  useEffect(() => {
    api.get('/admin/outstanding').then((r) => setItems(r.data))
  }, [])
  if (!items) return <p>加载中…</p>
  return (
    <div>
      <div className="alert alert-warning">
        下列设备存在未归还发放记录，系统禁止把它们分配给下一场；散场复检为故障的设备另会进入“故障锁定”。
      </div>
      <div className="tablescroll card">
        <table>
          <thead>
            <tr><th>设备编号</th><th>凭证</th><th>观众</th><th>语言</th><th>剧目/场次</th><th>发放时间</th></tr>
          </thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan="6" className="muted">所有设备均已归还。</td></tr>}
            {items.map((a) => (
              <tr key={a.assignment_id}>
                <td className="mono"><strong>{a.device_serial}</strong></td>
                <td className="mono">{a.reservation_code}</td>
                <td>{a.audience_name}</td>
                <td>{lang(a.language)}</td>
                <td>{a.show_title} · {fmtDateTime(a.starts_at)}</td>
                <td>{fmtDateTime(a.issued_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function NotificationsPanel() {
  const [items, setItems] = useState(null)
  useEffect(() => {
    api.get('/admin/notifications').then((r) => setItems(r.data))
  }, [])
  if (!items) return <p>加载中…</p>
  return (
    <div className="card">
      <p className="muted">本地模拟通知服务：预约成功等通知“发送”后落库于此（真实环境对接短信/PUSH 网关）。</p>
      {items.length === 0 && <p>暂无通知。</p>}
      {items.map((n) => (
        <div className="card" key={n.id} style={{ marginBottom: '0.7rem', padding: '0.8rem 1rem' }}>
          <div className="spread">
            <strong>[{n.channel === 'sms' ? '短信' : '推送'}] {n.subject}</strong>
            <span className="small muted">{fmtDateTime(n.sent_at)} → {n.to}</span>
          </div>
          <p style={{ margin: '0.3rem 0 0' }}>{n.body}</p>
        </div>
      ))}
    </div>
  )
}
