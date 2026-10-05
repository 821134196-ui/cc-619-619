import { useState } from 'react'
import { api } from '../api.js'
import { ResultBox, Field } from './ui.jsx'

export default function StockCountPanel({ ctx }) {
  const { masters, employeeId } = ctx
  const currentEmployee = masters.employees.find((e) => String(e.id) === String(employeeId))
  const isSupervisor = currentEmployee?.role === 'supervisor'

  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7))
  const [count, setCount] = useState(null)
  const [recon, setRecon] = useState(null)
  const [error, setError] = useState(null)
  const [info, setInfo] = useState(null)

  async function createDraft() {
    setError(null); setInfo(null); setRecon(null)
    try {
      setCount(await api.createCount({ period, employee_id: Number(employeeId) }))
    } catch (e) { setError(e.message) }
  }

  async function updatePhysical(lineId, value) {
    const qty = Math.max(0, Number(value) || 0)
    // 本地即时反映
    setCount((c) => ({
      ...c,
      lines: c.lines.map((l) =>
        l.line_id === lineId ? { ...l, physical_qty: qty, variance: qty - l.system_qty } : l),
    }))
    try {
      await api.setPhysical(lineId, qty)
    } catch (e) { setError(e.message) }
  }

  async function confirm() {
    setError(null); setInfo(null)
    try {
      const c = await api.confirmCount(count.id, Number(employeeId))
      setCount(c)
      setInfo('盘点已确认并冻结；重复确认或修改都不会再改变结果。')
      const r = await api.reconciliation(period)
      setRecon(r)
      ctx.refreshDashboard()
    } catch (e) { setError(e.message) }
  }

  async function loadRecon() {
    setError(null)
    try { setRecon(await api.reconciliation(period)) } catch (e) { setError(e.message) }
  }

  return (
    <div className="card">
      <h2>月末集中盘点（主管确认后冻结）</h2>
      <p className="note-text">
        账面数量由数量流水实时计算；录入实物后自动产生差异。确认时：盘亏出 1 条 adjust_out +
        生成 1 张赔付单（同指一个盘点行），盘盈补入；账实相符则不动库存。
        外借丢失与库内破损早已离账，不会在盘点里重复计损。
      </p>

      <div className="form-row">
        <Field label="盘点月份">
          <input type="month" value={period} disabled={count && count.frozen} onChange={(e) => setPeriod(e.target.value)} />
        </Field>
        {!count && <button className="btn" onClick={createDraft}>创建盘点草稿</button>}
        {!count && <button className="btn secondary" onClick={loadRecon}>查看该月对账单</button>}
      </div>

      {!isSupervisor && (
        <div className="result warn">当前不是主管身份：可以创建草稿、录入实物，但确认按钮需切换到主管员工。</div>
      )}

      <ResultBox error={error} warn={null} />
      {info && <div className="result">✓ {info}</div>}

      {count && (
        <>
          {count.frozen && (
            <div className="frozen-banner">
              🔒 {count.period} 盘点单已冻结（确认人：{count.confirmed_by ?? '—'}
              {count.confirmed_at ? ` · ${count.confirmed_at}` : ''}）。任何重复确认/修改均被拒绝或幂等回放。
            </div>
          )}
          <table>
            <thead>
              <tr>
                <th>餐具</th><th className="num">账面数量</th>
                <th className="num">实物数量</th><th className="num">差异</th><th>处理</th>
              </tr>
            </thead>
            <tbody>
              {count.lines.map((l) => (
                <tr key={l.line_id}>
                  <td>{l.utensil}</td>
                  <td className="num">{l.system_qty}</td>
                  <td className="num" style={{ width: 130 }}>
                    <input
                      type="number" min="0" value={l.physical_qty}
                      disabled={count.frozen}
                      onChange={(e) => updatePhysical(l.line_id, e.target.value)}
                      style={{ width: 90, padding: '4px 8px' }}
                    />
                  </td>
                  <td className={'num ' + (l.variance < 0 ? 'delta-neg' : l.variance > 0 ? 'delta-pos' : '')}>
                    {l.variance > 0 ? `+${l.variance}` : l.variance}
                  </td>
                  <td>
                    {l.disposition === 'adjusted' && <span className="pill amber">已出调整</span>}
                    {l.disposition === 'reconciled' && <span className="pill green">账实相符</span>}
                    {l.disposition === 'pending' && <span className="pill gray">待确认</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="section-gap" style={{ display: 'flex', gap: 10 }}>
            <button className="btn danger" disabled={count.frozen || !isSupervisor} onClick={confirm}>
              {isSupervisor ? '主管确认差异并冻结' : '仅主管可确认'}
            </button>
            {count.frozen && <button className="btn secondary" onClick={loadRecon}>查看赔付 × 盘点对账单</button>}
          </div>
        </>
      )}

      {recon && (
        <div className="section-gap">
          <h3>{recon.period} 赔付 × 盘点 对账单</h3>
          <div className="grid grid-3" style={{ marginBottom: 12 }}>
            <div className="stat neutral"><div className="label">已收赔款</div><div className="value">¥{(recon.totals.comp_amount_paid / 100).toFixed(2)}</div></div>
            <div className="stat amber"><div className="label">未收赔款</div><div className="value">¥{(recon.totals.comp_amount_unpaid / 100).toFixed(2)}</div></div>
            <div className={'stat ' + (recon.no_double_count ? 'neutral' : 'red')}>
              <div className="label">同一损失重复计损校验</div>
              <div className="value" style={{ fontSize: 22 }}>{recon.no_double_count ? '通过 ✓' : '异常 ✕'}</div>
            </div>
          </div>
          <div className="grid grid-3">
            <ReconGroup title="外借丢失（发放时已出库）" items={recon.compensations.issued_lost} />
            <ReconGroup title="库内破损（登记时已出库）" items={recon.compensations.damaged} />
            <ReconGroup title="盘点盘亏（调整与赔付一一对应）" items={recon.compensations.stock_shortage} />
          </div>
        </div>
      )}
    </div>
  )
}

function ReconGroup({ title, items }) {
  return (
    <div className="card" style={{ padding: 12 }}>
      <h3>{title}</h3>
      {(!items || items.length === 0) && <div className="note-text">无</div>}
      {items?.map((it, i) => (
        <div className="row-item" key={i}>
          <div className="main"><span>{it.comp_no} · {it.utensil} ×{it.qty}</span>
            <span className={'pill ' + (it.payment_status === 'paid' ? 'green' : 'amber')}>
              {it.payment_status === 'paid' ? '已赔' : '未赔'} ¥{(it.amount / 100).toFixed(2)}
            </span>
          </div>
          <div className="meta">{it.stock_effect}</div>
        </div>
      ))}
    </div>
  )
}
