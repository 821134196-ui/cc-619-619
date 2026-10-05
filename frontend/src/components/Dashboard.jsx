export default function Dashboard({ data }) {
  if (!data) return <div className="card">加载中……</div>

  const pr = data.pending_returns
  const pp = data.pending_payments
  const is_ = data.inventory_shortages

  return (
    <div>
      <div className="grid grid-3" style={{ marginBottom: 16 }}>
        <div className="stat blue">
          <div className="label"><span className="dot blue" />待归还（外借未收回）</div>
          <div className="value">{pr.total_qty}<span className="unit">件 · {pr.count_of_issuances} 笔发放</span></div>
        </div>
        <div className="stat amber">
          <div className="label"><span className="dot amber" />待赔付（破损 / 丢失未收款）</div>
          <div className="value">¥{pp.total_amount_yuan.toFixed(2)}<span className="unit">· {pp.count} 张赔付单</span></div>
        </div>
        <div className="stat red">
          <div className="label"><span className="dot red" />实物盘亏（待主管确认）</div>
          <div className="value">{is_.total_shortage_qty}<span className="unit">件{is_.draft_period ? ` · ${is_.draft_period} 盘点草稿` : ' · 无草稿'}</span></div>
        </div>
      </div>

      <div className="grid grid-3">
        <section className="column blue">
          <header>
            <span className="title"><span className="dot blue" />待归还</span>
            <span className="pill blue">{pr.total_qty} 件</span>
          </header>
          <div className="body">
            {pr.items.length === 0 && <div className="empty">暂无外借未还</div>}
            {pr.items.map((r) => (
              <div className="row-item" key={r.issuance_id}>
                <div className="main">
                  <span>{r.utensil}</span>
                  <span className="pill blue">未还 {r.outstanding_qty}</span>
                </div>
                <div className="meta">
                  单号 {r.issuance_no} · 原发放窗口：{r.origin_window}
                  {' '}· 发 {r.issued_qty} / 还 {r.returned_qty} / 赔 {r.lost_qty}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="column amber">
          <header>
            <span className="title"><span className="dot amber" />待赔付</span>
            <span className="pill amber">{pp.count} 张</span>
          </header>
          <div className="body">
            {pp.items.length === 0 && <div className="empty">暂无待收赔款</div>}
            {pp.items.map((c) => (
              <div className="row-item" key={c.comp_id}>
                <div className="main">
                  <span>{c.kind} · {c.utensil} ×{c.qty}</span>
                  <span className="pill amber">¥{c.amount_yuan.toFixed(2)}</span>
                </div>
                <div className="meta">{c.comp_no} · {c.window} · 来源：{c.source}</div>
              </div>
            ))}
          </div>
        </section>

        <section className="column red">
          <header>
            <span className="title"><span className="dot red" />实物盘亏</span>
            <span className="pill red">{is_.total_shortage_qty} 件</span>
          </header>
          <div className="body">
            {is_.items.length === 0 && <div className="empty">无待确认盘亏（已冻结或无差异）</div>}
            {is_.items.map((s) => (
              <div className="row-item" key={s.stock_count_line_id}>
                <div className="main">
                  <span>{s.utensil}</span>
                  <span className="pill red">盘亏 {s.shortage_qty}</span>
                </div>
                <div className="meta">
                  {s.period} · 账面 {s.system_qty} / 实物 {s.physical_qty} · {s.status}
                </div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  )
}
