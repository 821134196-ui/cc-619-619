import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { ResultBox, Field } from './ui.jsx'

export default function CompensationPanel({ ctx, dashboard }) {
  const { masters, windowId, employeeId } = ctx
  const [tab, setTab] = useState('lost')
  const [token, setToken] = useState('')
  const [typeId, setTypeId] = useState(String(masters.utensil_types[0]?.id ?? ''))
  const [qty, setQty] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [payMsg, setPayMsg] = useState({})
  const [version, setVersion] = useState(0)
  const [items, setItems] = useState([])

  useEffect(() => {
    api.dashboard().then((d) => setItems(d.pending_payments.items)).catch(() => {})
  }, [version, dashboard])

  async function reportLost() {
    setError(null); setResult(null)
    try {
      const d = await api.reportLost({
        return_token: token.trim(),
        window_id: Number(windowId),
        employee_id: Number(employeeId),
      })
      setResult(`已登记丢失赔付单 ${d.comp_no}，应收 ¥${d.amount_yuan.toFixed(2)}。注意：餐具在发放时已出库，此笔不写数量流水。`)
      setToken(''); setVersion((v) => v + 1); ctx.refreshDashboard()
    } catch (e) { setError(e.message) }
  }

  async function reportDamaged() {
    setError(null); setResult(null)
    try {
      const d = await api.reportDamaged({
        utensil_type_id: Number(typeId),
        qty: Number(qty),
        window_id: Number(windowId),
        employee_id: Number(employeeId),
      })
      setResult(`已登记破损赔付单 ${d.comp_no}，应收 ¥${d.amount_yuan.toFixed(2)}；破损实物已出库（adjust_out）。`)
      setVersion((v) => v + 1); ctx.refreshDashboard()
    } catch (e) { setError(e.message) }
  }

  async function pay(compId, simulateFailure = false) {
    setPayMsg((m) => ({ ...m, [compId]: { loading: true } }))
    try {
      const d = await api.pay(compId, simulateFailure)
      setPayMsg((m) => ({ ...m, [compId]: { ok: d.gateway_status === 'success', text: d.gateway_status === 'failed' ? d.message : `收款成功 ¥${items.find((x) => x.comp_id === compId)?.amount_yuan.toFixed(2)}（库存数量不变）` } }))
      setVersion((v) => v + 1); ctx.refreshDashboard()
    } catch (e) {
      setPayMsg((m) => ({ ...m, [compId]: { ok: false, text: e.message } }))
    }
  }

  return (
    <div className="grid grid-2">
      <div className="card">
        <h2>赔付登记</h2>
        <div className="tabs" style={{ marginBottom: 14 }}>
          <button className={tab === 'lost' ? 'active' : ''} onClick={() => { setTab('lost'); setError(null); setResult(null) }}>借出丢失（按归还码）</button>
          <button className={tab === 'damaged' ? 'active' : ''} onClick={() => { setTab('damaged'); setError(null); setResult(null) }}>库内破损（按类型）</button>
        </div>

        {tab === 'lost' ? (
          <>
            <p className="note-text">外借餐具确认丢失：核销该件的未还责任并生成应赔单。发放当时已出库，赔付不再动库存。</p>
            <div className="form-row">
              <Field label="丢失餐具的归还码">
                <input value={token} onChange={(e) => setToken(e.target.value)} placeholder="RT-…" />
              </Field>
            </div>
            <button className="btn danger" onClick={reportLost}>登记丢失赔付</button>
          </>
        ) : (
          <>
            <p className="note-text">库内破损：实物报废需要出库一条 adjust_out，并生成同等数量的赔付单。</p>
            <div className="form-row">
              <Field label="餐具类型">
                <select value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                  {masters.utensil_types.map((t) => (
                    <option key={t.id} value={t.id}>{t.name}（在库 {t.current_balance}）</option>
                  ))}
                </select>
              </Field>
              <Field label="破损数量">
                <input type="number" min="1" value={qty} onChange={(e) => setQty(e.target.value)} />
              </Field>
            </div>
            <button className="btn danger" onClick={reportDamaged}>登记破损赔付</button>
          </>
        )}
        <ResultBox result={result} error={error} />
      </div>

      <div className="card">
        <h2>待赔付清单与模拟收款</h2>
        <p className="note-text">模拟支付网关：收款只改变赔款状态，绝不把数量加回库存。对同一赔付单重复收款不会重复入账。</p>
        {items.length === 0 && <div className="note-text">暂无待收赔款。</div>}
        {items.map((c) => (
          <div className="row-item" key={c.comp_id}>
            <div className="main">
              <span>{c.kind} · {c.utensil} ×{c.qty}（{c.source}）</span>
              <span className="pill amber">¥{c.amount_yuan.toFixed(2)}</span>
            </div>
            <div className="meta">{c.comp_no} · {c.window}</div>
            <div style={{ marginTop: 6, display: 'flex', gap: 8, alignItems: 'center' }}>
              <button className="btn" onClick={() => pay(c.comp_id, false)}>模拟收款成功</button>
              <button className="btn secondary" onClick={() => pay(c.comp_id, true)}>模拟网关失败</button>
              {payMsg[c.comp_id] && (
                <span className={'pill ' + (payMsg[c.comp_id].ok ? 'green' : 'red')}>{payMsg[c.comp_id].text}</span>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
