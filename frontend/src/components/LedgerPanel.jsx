import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { Field } from './ui.jsx'

const TYPE_LABEL = {
  initial: '期初',
  issue: '发放出库',
  return: '归还入库',
  adjust_in: '盘盈调整',
  adjust_out: '盘亏/破损出库',
}

export default function LedgerPanel({ ctx }) {
  const { masters } = ctx
  const [typeId, setTypeId] = useState('')
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7))
  const [data, setData] = useState(null)

  async function load() {
    const params = {}
    if (typeId) params.utensil_type_id = typeId
    if (period) params.period = period
    setData(await api.ledger(params))
  }

  useEffect(() => { load() }, [typeId, period])

  return (
    <div className="card">
      <h2>库存数量流水（期末实物的唯一计算依据）</h2>
      <p className="note-text">
        期末实物 = 期初 initial + 各笔带符号变动之和。赔付收款不出现在流水中——
        钱与数量分账，这也是「收款不会把餐具加回库存」的可审计证据。
      </p>
      <div className="toolbar">
        <Field label="餐具类型">
          <select value={typeId} onChange={(e) => setTypeId(e.target.value)}>
            <option value="">全部</option>
            {masters.utensil_types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </Field>
        <Field label="月份">
          <input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} />
        </Field>
        <button className="btn secondary" onClick={load}>刷新</button>
      </div>

      {data && (
        <>
          <table style={{ marginBottom: 16 }}>
            <thead>
              <tr>
                <th>餐具</th><th>流水类型</th><th className="num">变动</th>
                <th className="num">结存</th><th>关联单据</th><th>时间</th>
              </tr>
            </thead>
            <tbody>
              {data.entries.length === 0 && <tr><td colSpan="6" style={{ textAlign: 'center', color: 'var(--ink-3)' }}>该筛选下无流水</td></tr>}
              {data.entries.map((l) => (
                <tr key={l.id}>
                  <td>{l.utensil}</td>
                  <td>{TYPE_LABEL[l.type] ?? l.type}</td>
                  <td className={'num ' + (l.qty_delta < 0 ? 'delta-neg' : 'delta-pos')}>
                    {l.qty_delta > 0 ? `+${l.qty_delta}` : l.qty_delta}
                  </td>
                  <td className="num">{l.balance_after}</td>
                  <td style={{ fontSize: 12, color: 'var(--ink-2)' }}>
                    {l.ref_type ? `${l.ref_type}#${l.ref_id}` : '—'}
                  </td>
                  <td style={{ fontSize: 12 }}>{l.at}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <h3>期末实物计算</h3>
          <table>
            <thead><tr><th>餐具</th><th className="num">流水合计</th><th className="num">期末实物（=最后结存）</th><th>其中赔付收款条目</th></tr></thead>
            <tbody>
              {data.ending_calculation.map((c, i) => (
                <tr key={i}>
                  <td>{c.utensil}</td>
                  <td className="num">{c.sum_delta}</td>
                  <td className="num"><strong>{c.ending_balance}</strong></td>
                  <td>{c.payment_entries}（收款不写数量流水）</td>
                </tr>
              ))}
              {data.ending_calculation.length === 0 && <tr><td colSpan="4" style={{ textAlign: 'center', color: 'var(--ink-3)' }}>无数据</td></tr>}
            </tbody>
          </table>
        </>
      )}
    </div>
  )
}
