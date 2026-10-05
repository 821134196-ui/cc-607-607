import React from 'react'
import { statusLabel } from '../api.js'

export function Badge({ status }) {
  return <span className={`badge ${status}`}>{statusLabel(status)}</span>
}

export function Alert({ kind = 'info', children }) {
  if (!children) return null
  return <div role="alert" className={`alert ${kind}`}>{children}</div>
}

export function ErrorBox({ error }) {
  if (!error) return null
  const fieldMsgs = Object.values(error.errors || {}).flat()
  return (
    <Alert kind="error">
      <div>{error.message}</div>
      {fieldMsgs.length > 0 && (
        <ul style={{ margin: '6px 0 0' }}>
          {fieldMsgs.map((m, i) => <li key={i}>{m}</li>)}
        </ul>
      )}
    </Alert>
  )
}

export function Loading() {
  return <p className="muted" aria-live="polite">加载中…</p>
}

export function StatusLegend() {
  return (
    <details className="card" style={{ padding: '12px 18px' }}>
      <summary style={{ fontWeight: 800, fontSize: '1.05rem', cursor: 'pointer' }}>状态说明</summary>
      <div className="row" style={{ marginTop: 10 }}>
        <Badge status="active" />
        <Badge status="issued" />
        <Badge status="returned" />
        <Badge status="cancelled" />
        <Badge status="noshow" />
      </div>
    </details>
  )
}
