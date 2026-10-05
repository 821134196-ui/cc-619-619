import React, { useEffect, useState } from 'react';
import api, { yuan } from '../api';
import { Card, Section, Table, Badge } from './ui';

export default function Dashboard({ goto }) {
  const [d, setD] = useState(null);
  useEffect(() => { api.get('/dashboard').then((r) => setD(r.data)); }, []);
  if (!d) return <div className="text-slate-500">加载中…</div>;

  return (
    <div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
        <Card title="① 待归还（在外餐具）" count={d.pending_returns.total_qty} tone="blue"
          sub="发放后尚未收回，按原发放记录跟踪">
          <div className="mt-3 space-y-1">
            {d.pending_returns.by_utensil.map((x) => (
              <div key={x.utensil} className="flex justify-between text-xs text-slate-600">
                <span>{x.utensil}</span><Badge tone="blue">{x.qty} 件</Badge>
              </div>
            ))}
            {d.pending_returns.by_utensil.length === 0 && <p className="text-xs text-slate-400">暂无</p>}
          </div>
        </Card>
        <Card title="② 待赔付（应收赔款）" count={d.pending_claims.total_qty} tone="amber"
          sub={`金额 ${yuan(d.pending_claims.total_amount_cents)}｜与库存数量分开`}>
          <div className="mt-3 space-y-1">
            {d.pending_claims.by_utensil.map((x, i) => (
              <div key={i} className="flex justify-between text-xs text-slate-600">
                <span>{x.utensil}（{x.source === 'broken_stock' ? '破损' : x.source === 'lost_issued' ? '丢失' : '盘亏'}）</span>
                <Badge tone="amber">{x.qty} 件 / {yuan(x.amount_cents)}</Badge>
              </div>
            ))}
            {d.pending_claims.by_utensil.length === 0 && <p className="text-xs text-slate-400">暂无</p>}
          </div>
        </Card>
        <Card title="③ 实物盘亏（待主管确认）" count={d.stock_shortages.total_qty} tone="red"
          sub="实盘少于账面，冻结后才正式计损">
          <div className="mt-3 space-y-1">
            {d.stock_shortages.lines.map((x, i) => (
              <div key={i} className="flex justify-between text-xs text-slate-600">
                <span>{x.period} {x.utensil}{x.submitted ? '（已提交）' : ''}</span>
                <Badge tone="red">缺 {x.shortage_qty}</Badge>
              </div>
            ))}
            {d.stock_shortages.lines.length === 0 && <p className="text-xs text-slate-400">暂无</p>}
          </div>
        </Card>
      </div>

      <Section title="说明：钱与物两条线">
        <ul className="text-sm text-slate-600 list-disc pl-5 space-y-1">
          <li><b>待归还</b>是餐具实物在外的数量；跨窗口归还始终关联原发放单与发放窗口。</li>
          <li><b>待赔付</b>是钱的问题：收款只记支付流水，<b>不会把餐具数量加回库存</b>。</li>
          <li><b>实物盘亏</b>经主管冻结时才写盘点调整（物）并生成盘亏追赔（钱），同一损失两边可核对、不重复计损。</li>
        </ul>
      </Section>

      <Section title="快捷操作">
        <div className="flex gap-2 flex-wrap">
          <button onClick={() => goto('issue')} className="px-4 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700">登记发放</button>
          <button onClick={() => goto('return')} className="px-4 py-2 text-sm rounded bg-emerald-600 text-white hover:bg-emerald-700">扫码归还</button>
          <button onClick={() => goto('claims')} className="px-4 py-2 text-sm rounded bg-amber-600 text-white hover:bg-amber-700">登记破损/丢失、收款</button>
          <button onClick={() => goto('stocktake')} className="px-4 py-2 text-sm rounded bg-red-600 text-white hover:bg-red-700">月末盘点</button>
        </div>
      </Section>
    </div>
  );
}
