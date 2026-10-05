import React, { useEffect, useState } from 'react';
import api, { yuan, SOURCE_LABEL } from '../api';
import { useApp } from '../app';
import { Section, Button, Table, Badge } from './ui';

export default function ClaimsPanel() {
  const { meta, windowId, staffId, notify } = useApp();
  const [claims, setClaims] = useState([]);
  const [filter, setFilter] = useState('pending');

  // 破损登记
  const [bType, setBType] = useState('');
  const [bQty, setBQty] = useState(1);
  const [bReason, setBReason] = useState('');
  // 丢失登记
  const [codes, setCodes] = useState('');

  const load = () => api.get('/claims', { params: { status: filter } }).then((r) => setClaims(r.data));
  useEffect(() => { load(); }, [filter]);

  const registerBroken = async () => {
    if (!bType) return notify('请选择餐具', 'warn');
    try {
      await api.post('/claims/broken', {
        utensil_type_id: Number(bType), qty: Number(bQty),
        window_id: Number(windowId), staff_id: Number(staffId), reason: bReason,
      });
      notify('破损已登记：库存实物已注销，赔款待收（收款不会回补库存）');
      setBQty(1); setBReason('');
      load();
    } catch (e) { notify(e.message, 'error'); }
  };

  const registerLost = async () => {
    const list = codes.split(/[\s,，]+/).filter(Boolean);
    if (!list.length) return notify('请输入归还码', 'warn');
    try {
      const { data } = await api.post('/claims/lost', {
        codes: list, window_id: Number(windowId), staff_id: Number(staffId),
      });
      notify(`已为 ${list.length} 件餐具登记丢失追赔（实物数量不变，仅由待归还转待赔付）`);
      setCodes('');
      load();
    } catch (e) { notify(e.message, 'error'); }
  };

  const pay = async (claim) => {
    try {
      const { data } = await api.post(`/claims/${claim.id}/pay`, { staff_id: Number(staffId) });
      notify(data.message + `，交易号 ${data.payment.gateway_ref}`);
      load();
    } catch (e) { notify(e.message, 'error'); }
  };

  return (
    <div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <Section title="登记窗口实物破损（物线注销 + 钱线追赔）">
          <div className="space-y-2 text-sm">
            <select className="border rounded px-2 py-1.5 w-full" value={bType} onChange={(e) => setBType(e.target.value)}>
              <option value="">选择餐具</option>
              {meta.utensil_types.map((t) => <option key={t.id} value={t.id}>{t.name}（单价 {yuan(t.unit_price_cents)}）</option>)}
            </select>
            <input type="number" min="1" className="border rounded px-2 py-1.5 w-28" value={bQty} onChange={(e) => setBQty(e.target.value)} />
            <input className="border rounded px-2 py-1.5 w-full" placeholder="破损原因（可选）" value={bReason} onChange={(e) => setBReason(e.target.value)} />
            <Button tone="red" onClick={registerBroken}>登记破损</Button>
            <p className="text-xs text-slate-500">登记时立即写 writeoff 流水减少实物；之后收款只更新赔款状态。</p>
          </div>
        </Section>

        <Section title="登记发放后丢失（按归还码，仅转待赔付）">
          <textarea rows="3" className="border rounded px-2 py-1.5 w-full font-mono text-xs"
            placeholder="粘贴归还码，空格/逗号/换行分隔，如 RABCD… RXYZW…"
            value={codes} onChange={(e) => setCodes(e.target.value)} />
          <div className="mt-2"><Button tone="red" onClick={registerLost}>登记丢失并生成赔付单</Button></div>
          <p className="text-xs text-slate-500 mt-2">餐具在发放时已从库存扣减，登记丢失不改变实物数量，只是从"待归还"变成"待赔付"；一件只能赔一次。</p>
        </Section>
      </div>

      <Section
        title="赔付单与模拟收款"
        actions={
          <select className="border rounded px-2 py-1 text-sm" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="pending">待赔付</option>
            <option value="paid">已赔付</option>
          </select>
        }
      >
        <Table headers={['赔单号', '来源', '餐具', '数量', '金额', '窗口', '状态/交易号', '操作']}>
          {claims.map((c) => (
            <tr key={c.id} className="border-b">
              <td className="py-2 pr-3 font-mono text-xs">{c.claim_no}</td>
              <td>
                <Badge tone={c.source === 'broken_stock' ? 'red' : c.source === 'lost_issued' ? 'amber' : 'blue'}>
                  {SOURCE_LABEL[c.source]}
                </Badge>
                {c.stocktake && <span className="text-xs text-slate-400 ml-1">{c.stocktake.period}</span>}
              </td>
              <td>{c.utensil_type.name}</td>
              <td>{c.qty}</td>
              <td>{yuan(c.amount_cents)}</td>
              <td>{c.window?.code || '—'}</td>
              <td className="text-xs">
                {c.status === 'paid'
                  ? <Badge tone="green">已收款 {c.payment_ref}</Badge>
                  : <Badge tone="amber">待收款</Badge>}
              </td>
              <td>
                {c.status === 'pending' && <Button size="sm" tone="green" onClick={() => pay(c)}>模拟收款</Button>}
              </td>
            </tr>
          ))}
          {claims.length === 0 && <tr><td colSpan="8" className="py-4 text-slate-400 text-sm">暂无记录</td></tr>}
        </Table>
      </Section>
    </div>
  );
}
