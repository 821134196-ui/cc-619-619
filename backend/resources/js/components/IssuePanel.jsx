import React, { useEffect, useState } from 'react';
import api from '../api';
import { useApp } from '../app';
import { Section, Button, Table, Badge } from './ui';

export default function IssuePanel() {
  const { meta, windowId, staffId, notify } = useApp();
  const [lines, setLines] = useState([{ utensil_type_id: '', qty: 1 }]);
  const [records, setRecords] = useState(null);

  const load = () => api.get('/issuances').then((r) => setRecords(r.data));
  useEffect(() => { load(); }, []);

  const submit = async () => {
    try {
      const payload = {
        window_id: Number(windowId), staff_id: Number(staffId),
        lines: lines.filter((l) => l.utensil_type_id).map((l) => ({ utensil_type_id: Number(l.utensil_type_id), qty: Number(l.qty) })),
      };
      if (!payload.lines.length) return notify('请选择餐具', 'warn');
      const { data } = await api.post('/issuances', payload);
      const codes = data.lines.flatMap((l) => l.units.map((u) => u.code));
      notify(`发放成功，单号 ${data.issue_no}，生成 ${codes.length} 个归还码`);
      setLines([{ utensil_type_id: '', qty: 1 }]);
      load();
    } catch (e) { notify(e.message, 'error'); }
  };

  const total = lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);

  return (
    <div>
      <Section title={`登记发放（${meta.windows.find((w) => String(w.id) === windowId)?.name}）`}>
        {lines.map((l, i) => (
          <div key={i} className="flex gap-2 mb-2 items-center">
            <select className="border rounded px-2 py-1.5 text-sm flex-1" value={l.utensil_type_id}
              onChange={(e) => setLines(lines.map((x, j) => j === i ? { ...x, utensil_type_id: e.target.value } : x))}>
              <option value="">选择餐具</option>
              {meta.utensil_types.map((t) => <option key={t.id} value={t.id}>{t.name}（赔价 ¥{(t.unit_price_cents / 100).toFixed(2)}）</option>)}
            </select>
            <input type="number" min="1" className="border rounded px-2 py-1.5 text-sm w-24" value={l.qty}
              onChange={(e) => setLines(lines.map((x, j) => j === i ? { ...x, qty: e.target.value } : x))} />
            <Button tone="ghost" size="sm" onClick={() => setLines(lines.filter((_, j) => j !== i))}>删除</Button>
          </div>
        ))}
        <div className="flex gap-2 mt-3">
          <Button tone="ghost" size="sm" onClick={() => setLines([...lines, { utensil_type_id: '', qty: 1 }])}>+ 添加种类</Button>
          <span className="text-sm text-slate-500 ml-2 self-center">共 {total} 件</span>
          <Button tone="blue" onClick={submit} >确认发放</Button>
        </div>
      </Section>

      <Section title="最近发放单（含归还码）" actions={<Button size="sm" onClick={load}>刷新</Button>}>
        <Table headers={['单号', '窗口', '经手', '时间', '明细 / 归还码']}>
          {(records || []).map((iss) => (
            <tr key={iss.id} className="border-b">
              <td className="py-2 pr-3 font-mono text-xs">{iss.issue_no}</td>
              <td>{iss.window.code}</td>
              <td>{iss.staff.name}</td>
              <td className="text-xs">{iss.issued_at?.replace('T', ' ').slice(0, 16)}</td>
              <td>
                {iss.lines.map((ln) => (
                  <details key={ln.id} className="mb-1">
                    <summary className="cursor-pointer text-xs">
                      <Badge tone="blue">{ln.qty} 件</Badge>{' '}
                      <span className="text-slate-600">
                        {meta.utensil_types.find((t) => t.id === ln.utensil_type_id)?.name}｜
                        待还 {ln.units.filter((u) => u.status === 'out').length} /
                        已还 {ln.units.filter((u) => u.status === 'returned').length} /
                        丢失 {ln.units.filter((u) => u.status === 'lost').length}
                      </span>
                    </summary>
                    <div className="pl-3 py-1 font-mono text-[11px] text-slate-500 break-all">
                      {ln.units.map((u) => (
                        <span key={u.id} className="inline-block mr-2 mb-1">
                          {u.code}
                          {u.status === 'returned' && <Badge tone="green">已还</Badge>}
                          {u.status === 'lost' && <Badge tone="red">丢失</Badge>}
                        </span>
                      ))}
                    </div>
                  </details>
                ))}
              </td>
            </tr>
          ))}
          {records && records.length === 0 && <tr><td colSpan="5" className="py-4 text-slate-400 text-sm">暂无发放记录</td></tr>}
        </Table>
      </Section>
    </div>
  );
}
