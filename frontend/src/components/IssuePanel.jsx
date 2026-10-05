import { useState } from 'react'
import { api } from '../api.js'
import { ResultBox, Field } from './ui.jsx'

export default function IssuePanel({ ctx }) {
  const { masters, windowId, employeeId } = ctx
  const [typeId, setTypeId] = useState(String(masters.utensil_types[0]?.id ?? ''))
  const [qty, setQty] = useState(3)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [tokens, setTokens] = useState([])
  const [busy, setBusy] = useState(false)

  const type = masters.utensil_types.find((t) => String(t.id) === String(typeId))

  async function submit() {
    setBusy(true); setError(null); setResult(null)
    try {
      const data = await api.issue({
        utensil_type_id: Number(typeId),
        window_id: Number(windowId),
        employee_id: Number(employeeId),
        qty: Number(qty),
      })
      setResult(`已开出发放单 ${data.issuance_no}，共 ${data.qty} 件，每件生成唯一归还码`)
      setTokens(data.return_tokens)
      ctx.refreshDashboard()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card" style={{ maxWidth: 760 }}>
      <h2>窗口发放登记</h2>
      <p className="note-text">发放即出库（写 issue 数量流水），每件餐具分配一个归还码，供任意窗口扫码归还。</p>
      <div className="form-row">
        <Field label="餐具类型">
          <select value={typeId} onChange={(e) => setTypeId(e.target.value)}>
            {masters.utensil_types.map((t) => (
              <option key={t.id} value={t.id}>{t.name}（在库 {t.current_balance}）</option>
            ))}
          </select>
        </Field>
        <Field label="发放数量">
          <input type="number" min="1" value={qty} onChange={(e) => setQty(e.target.value)} />
        </Field>
      </div>
      <div className="note-text" style={{ marginBottom: 12 }}>
        发放窗口：{masters.windows.find((w) => String(w.id) === String(windowId))?.name}
        {' '}· 经手人：{masters.employees.find((e) => String(e.id) === String(employeeId))?.name}
        {type && ` · 丢失赔付单价 ¥${type.unit_price_yuan.toFixed(2)} / 件`}
      </div>
      <button className="btn" disabled={busy} onClick={submit}>{busy ? '提交中…' : '确认发放'}</button>
      <ResultBox result={result} error={error} />
      {tokens.length > 0 && (
        <div className="tokens-box" title="模拟打印的归还码标签">
          {tokens.map((t) => <div key={t}>{t}</div>)}
        </div>
      )}
    </div>
  )
}
