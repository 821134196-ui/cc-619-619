import { useState } from 'react'
import { api } from '../api.js'
import { ResultBox } from './ui.jsx'

export default function ShiftPanel({ ctx }) {
  const { masters, windowId, employeeId } = ctx
  const [shiftId, setShiftId] = useState(null)
  const [snapshot, setSnapshot] = useState(null)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)

  async function openShift() {
    setError(null)
    try {
      const d = await api.openShift({ window_id: Number(windowId), employee_id: Number(employeeId) })
      setShiftId(d.shift_id)
      setResult(`已开班 ${d.shift_no}`)
      setSnapshot(null)
    } catch (e) { setError(e.message) }
  }

  async function closeShift() {
    setError(null)
    try {
      const d = await api.closeShift(shiftId, { employee_id: Number(employeeId) })
      setResult(`班次 ${d.shift_no} 已交接关闭`)
      setSnapshot(d.handover_snapshot)
      ctx.refreshDashboard()
    } catch (e) { setError(e.message) }
  }

  const windowName = masters.windows.find((w) => String(w.id) === String(windowId))?.name
  const empName = masters.employees.find((e) => String(e.id) === String(employeeId))?.name

  return (
    <div className="card" style={{ maxWidth: 760 }}>
      <h2>换班交接</h2>
      <p className="note-text">
        交接时固化本班次开出、尚未归还的数量快照，交给下一班继续跟踪。
        未归还责任挂在原发放记录上，换班不会让它消失。
      </p>
      <div className="note-text" style={{ marginBottom: 12 }}>窗口：{windowName} · 员工：{empName}</div>
      <div style={{ display: 'flex', gap: 10 }}>
        <button className="btn" onClick={openShift}>开班（模拟）</button>
        <button className="btn secondary" disabled={!shiftId} onClick={closeShift}>结班并生成交接快照</button>
      </div>
      <ResultBox result={result} error={error} />
      {snapshot && (
        <div className="section-gap">
          <h3>交接时尚未归还</h3>
          {snapshot.length === 0 && <div className="note-text">本班发放均已闭环，无需交接。</div>}
          <table>
            <thead><tr><th>餐具</th><th className="num">未归还数量</th><th className="num">未闭环发放单数</th></tr></thead>
            <tbody>
              {snapshot.map((s, i) => (
                <tr key={i}><td>{s.utensil_type}</td><td className="num">{s.outstanding_qty}</td><td className="num">{s.issuance_count}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
