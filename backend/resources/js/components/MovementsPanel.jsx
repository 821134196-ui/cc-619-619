import React, { useEffect, useState } from 'react';
import api from '../api';
import { useApp } from '../app';
import { Section, Table, Badge } from './ui';

const TYPE_LABEL = { opening: '期初', issue: '发放', return: '归还', writeoff: '破损注销', adjust: '盘点调整' };
const TONE = { opening: 'slate', issue: 'blue', return: 'green', writeoff: 'red', adjust: 'amber' };

export default function MovementsPanel() {
  const { meta } = useApp();
  const [typeId, setTypeId] = useState('');
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [rows, setRows] = useState([]);

  const load = () =>
    api.get('/movements', { params: { utensil_type_id: typeId || undefined, period: period || undefined } })
      .then((r) => setRows(r.data));
  useEffect(() => { load(); }, [typeId, period]);

  // 按餐具分组，便于说明期末实物如何逐笔得出
  const groups = {};
  rows.forEach((r) => { (groups[r.utensil_type_id] ||= []).push(r); });

  return (
    <Section title="餐具数量流水（只追加；期末实物 = 期初 + Σ流水）"
      actions={
        <div className="flex gap-2 text-sm">
          <select className="border rounded px-2 py-1" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
            <option value="">全部餐具</option>
            {meta.utensil_types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          <input type="month" className="border rounded px-2 py-1" value={period} onChange={(e) => setPeriod(e.target.value)} />
        </div>
      }>
      {Object.entries(groups).map(([tid, list]) => (
        <div key={tid} className="mb-6">
          <h3 className="font-semibold text-slate-700 mb-2">{list[0].utensil_type.code} {list[0].utensil_type.name}</h3>
          <Table headers={['时间', '类型', '变动', '变动后实物', '窗口', '关联单据', '备注']}>
            {list.map((m) => (
              <tr key={m.id} className="border-b">
                <td className="py-1.5 pr-3 text-xs whitespace-nowrap">{m.occurred_at?.replace('T', ' ').slice(0, 16)}</td>
                <td><Badge tone={TONE[m.type]}>{TYPE_LABEL[m.type]}</Badge></td>
                <td className={m.delta < 0 ? 'text-red-600' : 'text-emerald-700'}>{m.delta > 0 ? `+${m.delta}` : m.delta}</td>
                <td className="font-semibold">{m.balance_after}</td>
                <td>{m.window?.code || '—'}</td>
                <td className="text-xs">{m.ref_type}{m.ref_id ? `#${m.ref_id}` : ''}</td>
                <td className="text-xs text-slate-500">{m.note}</td>
              </tr>
            ))}
            <tr className="bg-slate-50">
              <td colSpan="3" className="py-1.5 text-right text-xs text-slate-500">期末实物：</td>
              <td className="font-bold">{list[list.length - 1].balance_after}</td>
              <td colSpan="3"></td>
            </tr>
          </Table>
        </div>
      ))}
      {rows.length === 0 && <p className="text-sm text-slate-400">该月暂无流水</p>}
      <p className="text-xs text-slate-500 mt-2">
        注意：流水只有 opening / issue / return / writeoff / adjust 五种。<b>赔款收款不出现在这里</b>，
        收款记录在赔付单的支付流水中——钱与物分别对账。
      </p>
    </Section>
  );
}
