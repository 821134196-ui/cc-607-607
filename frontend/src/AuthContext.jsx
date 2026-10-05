import React, { createContext, useContext, useMemo, useState, useCallback } from 'react'
import { api, setSession, clearSession, getToken, getUser } from './api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => getUser())
  const [token, setToken] = useState(() => getToken())

  const login = useCallback(async (email, password) => {
    const data = await api('/auth/login', { method: 'POST', body: { email, password } })
    setSession(data.token, data.user)
    setToken(data.token)
    setUser(data.user)
    return data.user
  }, [])

  const logout = useCallback(() => {
    clearSession()
    setToken('')
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, token, login, logout }), [user, token, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  return useContext(AuthContext)
}
