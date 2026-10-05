// 对 localStorage 的安全访问（隐私模式/SSR 下不崩溃）
const mem = new Map()

function ls() {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null
  } catch {
    return null
  }
}

export const storage = {
  get(key) {
    try {
      const v = ls()?.getItem(key)
      return v === undefined ? null : (v ?? (mem.has(key) ? mem.get(key) : null))
    } catch {
      return mem.get(key) ?? null
    }
  },
  set(key, value) {
    try {
      const store = ls()
      if (store) { store.setItem(key, value); return }
    } catch {
      // 落到内存
    }
    mem.set(key, value)
  },
  remove(key) {
    try {
      ls()?.removeItem(key)
    } catch {
      // ignore
    }
    mem.delete(key)
  },
}
