import React, { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, languageName, formatTime } from '../api.js'
import { Badge, ErrorBox, Loading } from '../components/Ui.jsx'

export default function PerformancesPage() {
  const [performances, setPerformances] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    api('/performances').then(setPerformances).catch(setError)
  }, [])

  if (error) return <ErrorBox error={error} />
  if (!performances) return <Loading />

  return (
    <div>
      <h2>选择演出场次</h2>
      <p className="muted">选择一场演出后，再选择字幕语言，即可看到当前可预约数量。</p>

      {performances.map((p) => (
        <article key={p.id} className="card" aria-label={`演出：${p.title}`}>
          <div className="row">
            <h3 style={{ margin: 0 }}>{p.title}</h3>
            <span className="spacer" />
            {p.has_started
              ? <Badge status={p.has_finished ? 'cancelled' : 'maintenance'} />
              : <Badge status="active" />}
          </div>
          <dl className="kv" style={{ margin: '12px 0' }}>
            <dt>场馆</dt><dd>{p.venue}</dd>
            <dt>时间</dt><dd>{formatTime(p.starts_at)} – {formatTime(p.ends_at)}</dd>
          </dl>
          {p.description && <p className="muted">{p.description}</p>}

          <table>
            <caption className="muted" style={{ captionSide: 'top', textAlign: 'left', fontWeight: 700, marginBottom: 6 }}>
              各语言可预约设备数量
            </caption>
            <thead>
              <tr><th scope="col">字幕语言</th><th scope="col">总量</th><th scope="col">已约</th><th scope="col">可预约</th><th scope="col">操作</th></tr>
            </thead>
            <tbody>
              {p.languages.map((l) => (
                <tr key={l.language}>
                  <td style={{ fontWeight: 700 }}>{languageName(l.language)}（{l.language}）</td>
                  <td>{l.capacity}</td>
                  <td>{l.open}</td>
                  <td>
                    <span className={`large-quota ${l.remaining === 0 ? 'quota-zero' : 'quota-ok'}`}>
                      {l.remaining}
                    </span>
                  </td>
                  <td>
                    {p.has_started || l.remaining === 0 ? (
                      <button type="button" disabled>
                        {p.has_started ? '已开演' : '已约满'}
                      </button>
                    ) : (
                      <Link
                        to={`/book/${p.id}?lang=${l.language}`}
                        className="btn"
                        aria-label={`预约 ${p.title} ${languageName(l.language)} 字幕设备，剩余 ${l.remaining} 台`}
                      >
                        预约
                      </Link>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </article>
      ))}
    </div>
  )
}
