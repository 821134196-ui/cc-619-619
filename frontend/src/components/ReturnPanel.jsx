import { useState } from 'react'
import { api } from '../api.js'
import { ResultBox, Field } from './ui.jsx'

export default function ReturnPanel({ ctx }) {
  const { masters, windowId, employeeId } = ctx
  const [token, setToken] = useState('')
  const [scans, setScans] = useState([])
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function scan() {
    if (!token.trim()) return
    setBusy(true); setError(null)
    try {
      const data = await api.scanReturn({
        return_token: token.trim(),
        window_id: Number(windowId),
        employee_id: Number(employeeId),
      })
      setScans((s) => [{
        token: token.trim(),
        duplicated: data.duplicated_scan,
        cross: data.cross_window,
        msg: data.message,
        origin: data.origin_window,
        ret: data.return_window,
        no: data.issuance_no,
      }, ...s].slice(0, 8))
      ctx.refreshDashboard()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false); setToken('')
    }
  }

  const windowName = masters.windows.find((w) => String(w.id) === String(windowId))?.name

  return (
    <div className="grid grid-2">
      <div className="card">
        <h2>扫码归还（任意窗口可收）</h2>
        <p className="note-text">
          归还始终关联【原发放单】；若收件窗口 ≠ 原发放窗口，自动标记为跨窗口归还。
          同一归还码重复扫描只计一次，重复入库不会发生。
        </p>
        <div className="form-row">
          <Field label={`归还码（当前收件窗口：${windowName}）`}>
            <input
              value={token}
              onChange={(e) => setToken(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && scan()}
              placeholder="RT-xxxxxxxx-0001-XXXXXX"
              autoFocus
            />
          </Field>
        </div>
        <button className="btn" disabled={busy} onClick={scan}>{busy ? '识别中…' : '模拟扫码'}</button>
        <ResultBox error={error} />
      </div>

      <div className="card">
        <h2>最近扫码结果</h2>
        {scans.length === 0 && <div className="note-text">暂无记录。可先到「发放登记」生成归还码，再切换到其他窗口扫码验证跨窗口归还。</div>}
        {scans.map((s, i) => (
          <div className="row-item" key={i}>
            <div className="main">
              <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: 12 }}>{s.token}</span>
              {s.duplicated
                ? <span className="pill gray">重复扫描 · 只计一次</span>
                : s.cross
                  ? <span className="pill blue">跨窗口归还</span>
                  : <span className="pill green">本窗口归还</span>}
            </div>
            <div className="meta">{s.msg} · 原发放单 {s.no}（{s.origin} → {s.ret}）</div>
          </div>
        ))}
      </div>
    </div>
  )
}
