import React, { useId, cloneElement, isValidElement } from 'react'

export function Field({ label, children, hint }) {
  const id = useId()
  const labelled = isValidElement(children)
    ? cloneElement(children, { id: children.props.id || id })
    : children
  return (
    <div>
      <label htmlFor={id}>{label}</label>
      {labelled}
      {hint && <p className="small muted" style={{ margin: '4px 0 0' }} id={`${id}-hint`}>{hint}</p>}
    </div>
  )
}

export default Field
