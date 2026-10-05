import React, { useEffect, useState, useCallback } from 'react'
import { api } from './api.js'
import Dashboard from './components/Dashboard.jsx'
import IssuePanel from './components/IssuePanel.jsx'
import ReturnPanel from './components/ReturnPanel.jsx'
import ShiftPanel from './components/ShiftPanel.jsx'
import CompensationPanel from './components/CompensationPanel.jsx'
import StockCountPanel from './components/StockCountPanel.jsx'
import LedgerPanel from './components/LedgerPanel.jsx'

const TABS = [
  { key: 'dashboard', label: '工作台' },
  { key: 'issue', label: '发放登记' },
  { key: 'return', label: '扫码归还' },
  { key: 'compensation', label: '破损 / 丢失赔付' },
  { key: 'shift', label: '换班交接' },
  { key: 'count', label: '月末盘点' },
  { key: 'ledger', label: '数量流水 / 对账' },
]

export default function App() {
  const [tab, setTab] = useState('dashboard')
  const [masters, setMasters] = useState(null)
  const [dashboard, setDashboard] = useState(null)
  const [windowId, setWindowId] = useState('')
  const [employeeId, setEmployeeId] = useState('')

  useEffect(() => {
    api.masters().then((m) => {
      setMasters(m)
      setWindowId(String(m.windows[0]?.id ?? ''))
      setEmployeeId(String(m.employees[0]?.id ?? ''))
    })
  }, [])

  const refreshDashboard = useCallback(() => {
    api.dashboard().then(setDashboard).catch(() => {})
  }, [])

  useEffect(() => {
    refreshDashboard()
  }, [refreshDashboard, tab])

  if (!masters) return <div className="app">正在加载基础数据……</div>

  const ctx = { masters, windowId, setWindowId, employeeId, setEmployeeId, refreshDashboard }

  return (
    <div className="app">
      <div className="app-header">
        <h1>食堂餐具丢失赔付与集中盘点系统</h1>
        <span className="sub">发放 · 跨窗口归还 · 赔付 · 换班交接 · 月末盘点（模拟扫码 / 模拟收款）</span>
      </div>

      <div className="toolbar">
        <div className="field" style={{ minWidth: 200 }}>
          <label>当前操作窗口</label>
          <select value={windowId} onChange={(e) => setWindowId(e.target.value)}>
            {masters.windows.map((w) => (
              <option key={w.id} value={w.id}>{w.name}</option>
            ))}
          </select>
        </div>
        <div className="field" style={{ minWidth: 200 }}>
          <label>当前经手员工</label>
          <select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
            {masters.employees.map((e) => (
              <option key={e.id} value={e.id}>
                {e.name}（{e.role === 'supervisor' ? '主管' : '窗口员工'}）
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="tabs">
        {TABS.map((t) => (
          <button key={t.key} className={tab === t.key ? 'active' : ''} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'dashboard' && <Dashboard data={dashboard} />}
      {tab === 'issue' && <IssuePanel ctx={ctx} />}
      {tab === 'return' && <ReturnPanel ctx={ctx} />}
      {tab === 'compensation' && <CompensationPanel ctx={ctx} dashboard={dashboard} />}
      {tab === 'shift' && <ShiftPanel ctx={ctx} />}
      {tab === 'count' && <StockCountPanel ctx={ctx} />}
      {tab === 'ledger' && <LedgerPanel ctx={ctx} />}
    </div>
  )
}
