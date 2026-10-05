import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.jsx'
import { setStaffId } from './api.js'
import { storage } from './storage.js'
import './styles.css'

const savedStaff = Number(storage.get('staffId')) || null
if (savedStaff) setStaffId(savedStaff)

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </React.StrictMode>,
)
