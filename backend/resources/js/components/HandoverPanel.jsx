import React, { useEffect, useState } from 'react';
import api from '../api';
import { useApp } from '../app';
import { Section, Button, Table, Badge } from './ui';

export default function HandoverPanel() {
  const { meta, windowId, staffId, notify } = useApp();
  const [toId, setToId] = useState('');
  const [list, setList] = useState([]);

  const load = () => api.get('/handovers').then((r) => setList(r.data));
  useEffect(() => { load(); }, []);

  const handover = async () => {
    if (!toId) return notify('请选择接班人', 'warn');
    if (Number(toId) === Number(staffId)) return notify('交班人与接班人不能相同', 'warn');
    try {
      const { data } = await api.post('/handovers', {
        window_id: Number(windowId), from_staff_id: Number(staffId), to_staff_id: Number(toId),
      });
      notify(`交接完成：未归还 ${data.total_outstanding} 件、待赔付 ${data.pending_claim_qty} 件已快照`);
      load();
    } catch (e) { notify(e.message, 'error'); }
  };

  return (
    <div>
      <Section title="换班交接（快照尚未归还数量）">
        <div className="flex gap-2 items-center flex-wrap text-sm">
          <span>窗口：<b>{meta.windows.find((w) => String(w.id) === windowId)?.name}</b></span>
          <span>交班：<b>{meta.staff.find((s) => String(s.id) === staffId)?.name}</b></span>
          <span>接班：</span>
          <select className="border rounded px-2 py-1.5" value={toId} onChange={(e) => setToId(e.target.value)}>
            <option value="">选择接班人</option>
            {meta.staff.filter((s) => String(s.id) !== staffId).map((s) => (
              <option key={s.id} value={s.id}>{s.name}（{s.window?.name || '无固定窗口'}）</option>
            ))}
          </select>
          <Button tone="blue" onClick={handover}>完成交接</Button>
        </div>
      </Section>

      <Section title="历史交接记录">
        <Table headers={['时间', '窗口', '交班', '接班', '未归还合计', '待赔付数量', '明细']}>
          {list.map((h) => (
            <tr key={h.id} className="border-b align-top">
              <td className="py-2 pr-3 text-xs whitespace-nowrap">{h.handed_at?.replace('T', ' ').slice(0, 16)}</td>
              <td>{h.window.code}</td>
              <td>{h.from_staff.name}</td>
              <td>{h.to_staff.name}</td>
              <td><Badge tone="blue">{h.total_outstanding} 件</Badge></td>
              <td><Badge tone="amber">{h.pending_claim_qty} 件</Badge></td>
              <td className="text-xs">
                <details>
                  <summary className="cursor-pointer">查看快照</summary>
                  <div className="mt-1">
                    <p className="font-semibold">按餐具：</p>
                    {(h.outstanding_snapshot.by_utensil || []).map((x, i) => (
                      <span key={i} className="inline-block mr-3">{x.utensil}：{x.qty}</span>
                    ))}
                    <p className="font-semibold mt-1">按原发放单：</p>
                    {(h.outstanding_snapshot.by_issuance || []).map((x, i) => (
                      <div key={i}>{x.issue_no}（{x.issued_at?.slice(0, 16)}）未还 {x.qty}</div>
                    ))}
                  </div>
                </details>
              </td>
            </tr>
          ))}
          {list.length === 0 && <tr><td colSpan="7" className="py-4 text-slate-400 text-sm">暂无交接记录</td></tr>}
        </Table>
      </Section>
    </div>
  );
}
