// 极简 API 客户端：令牌存 localStorage，自动加 Authorization 头
const TOKEN_KEY = 'caption_access_token'
const USER_KEY = 'caption_access_user'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}
export function getUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null')
  } catch {
    return null
  }
}
export function setSession(token, user) {
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(USER_KEY, JSON.stringify(user))
}
export function clearSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
}

export async function api(path, { method = 'GET', body = null } = {}) {
  const headers = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== null) headers['Content-Type'] = 'application/json'

  const res = await fetch(`/api${path}`, {
    method,
    headers,
    body: body !== null ? JSON.stringify(body) : undefined,
  })

  let data = null
  const text = await res.text()
  if (text) {
    try { data = JSON.parse(text) } catch { data = { raw: text } }
  }

  if (!res.ok) {
    const message = data?.message || `请求失败（${res.status}）`
    const errors = data?.errors || {}
    const err = new Error(message)
    err.status = res.status
    err.errors = errors
    throw err
  }
  return data
}

export const LANGUAGE_NAMES = {
  zh: '中文',
  en: 'English',
  ja: '日本語',
}
export function languageName(code) {
  return LANGUAGE_NAMES[code] || code
}

export function statusLabel(s) {
  return ({
    active: '待领取',
    issued: '已发放',
    returned: '已归还',
    cancelled: '已取消',
    noshow: '未归还',
    available: '可发放',
    assigned: '使用中',
    maintenance: '维修中',
    retired: '已停用',
    pending: '待审批',
    approved: '已批准',
    rejected: '已驳回',
    superseded: '已替换',
    draft: '待批草稿',
    faulty: '归还故障',
    missing: '未归还',
  })[s] || s
}

export function formatTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}
