import React, { useEffect, useState } from 'react';
import api from '../api';
import { useApp } from '../app';
import { Section, Button, Table, Badge } from './ui';

const monthNow = () => new Date().toISOString().slice(0, 7);

export default function StocktakePanel() {
  const { staffId, currentStaff, notify } = useApp();
  const [list, setList] = useState([]);
  const [period, setPeriod] = useState(monthNow());
  const [active, setActive] = useState(null);
  const [counts, setCounts] = useState({});

  const loadList = () => api.get('/stocktakes').then((r) => setList(r.data));
  useEffect(() => { loadList(); }, []);

  const openOne = async () => {
    try {
      const { data } = await api.post('/stocktakes', { period, staff_id: Number(staffId) });
      notify(`盘点单 ${period} 已开账`);
      openDetail(data);
      loadList();
    } catch (e) { notify(e.message, 'error'); }
  };

  const openDetail = async (row) => {
    const { data } = await api.get(`/stocktakes/${row.id}`);
    setActive(data);
    const c = {};
    data.lines.forEach((l) => { if (l.counted_qty !== null) c[l.utensil_type_id] = String(l.counted_qty); });
    setCounts(c);
  };

  const saveCount = async (line) => {
    const q = Number(counts[line.utensil_type_id]);
    if (Number.isNaN(q)) return;
    try {
      await api.post(`/stocktakes/${active.id}/counts`, {
        utensil_type_id: line.utensil_type_id, counted_qty: q,
      });
      const { data } = await api.get(`/stocktakes/${active.id}`);
      setActive(data);
      notify(`${line.utensil_type.name} 实盘已保存（差异 ${q - line.expected_qty}）`);
    } catch (e) { notify(e.message, 'error'); }
  };

  const submit = async () => {
    try {
      await api.post(`/stocktakes/${active.id}/submit`, { staff_id: Number(staffId) });
      notify('盘点已提交，等待主管确认');
      await openDetail(active); loadList();
    } catch (e) { notify(e.message, 'error'); }
  };

  const freeze = async () => {
    if (!currentStaff || currentStaff.role !== 'supervisor') return notify('只有主管可以确认冻结', 'error');
    try {
      const { data } = await api.post(`/stocktakes/${active.id}/freeze`, { staff_id: Number(staffId) });
      notify(`盘点 ${data.period} 已冻结：调整落账、盘亏追赔单已生成；再次确认会被拒绝`);
      await openDetail(data); loadList();
    } catch (e) { notify(e.message, 'error'); }
  };

  const frozen = active?.status === 'frozen';

  return (
    <div>
      <Section title="月末盘点">
        <div className="flex gap-2 items-center flex-wrap">
          <input type="month" className="border rounded px-2 py-1.5 text-sm" value={period} onChange={(e) => setPeriod(e.target.value)} />
          <Button tone="blue" onClick={openOne}>开账（按流水生成账面应有数）</Button>
          <span className="text-xs text-slate-500 ml-auto">
            {currentStaff?.role === 'supervisor' ? '当前为主管，可确认冻结' : '当前为员工，只能录入与提交'}
          </span>
        </div>

        <div className="mt-4">
          <Table headers={['月份', '状态', '提交时间', '冻结时间', '冻结人', '操作']}>
            {list.map((s) => (
              <tr key={s.id} className="border-b">
                <td className="py-2 pr-3 font-semibold">{s.period}</td>
                <td>{s.status === 'frozen' ? <Badge tone="green">已冻结</Badge> : <Badge tone="amber">进行中</Badge>}</td>
                <td className="text-xs">{s.submitted_at?.replace('T', ' ').slice(0, 16) || '—'}</td>
                <td className="text-xs">{s.frozen_at?.replace('T', ' ').slice(0, 16) || '—'}</td>
                <td>{s.freezer?.name || '—'}</td>
                <td><Button size="sm" tone="ghost" onClick={() => openDetail(s)}>查看/录入</Button></td>
              </tr>
            ))}
            {list.length === 0 && <tr><td colSpan="6" className="py-4 text-slate-400 text-sm">暂无盘点单</td></tr>}
          </Table>
        </div>
      </Section>

      {active && (
        <Section title={`盘点单 ${active.period}（${frozen ? '已冻结，不可更改' : active.submitted_at ? '已提交，待主管确认' : '录入中'}）`}>
          <Table headers={['餐具', '期初', '发放', '归还', '破损注销', '账面应有', '实盘', '调整', '钱物核对']}>
            {active.lines.map((l) => {
              const diff = counts[l.utensil_type_id] !== undefined ? Number(counts[l.utensil_type_id]) - l.expected_qty : null;
              const r = l.reconciliation;
              return (
                <tr key={l.id} className="border-b align-top">
                  <td className="py-2 pr-3 font-medium">{l.utensil_type.name}</td>
                  <td>{l.opening_qty}</td>
                  <td>-{l.issued_qty}</td>
                  <td>+{l.returned_qty}</td>
                  <td>-{l.writeoff_qty}</td>
                  <td className="font-semibold">{l.expected_qty}</td>
                  <td>
                    {frozen ? l.counted_qty : (
                      <div className="flex gap-1">
                        <input type="number" min="0" className="border rounded px-1 py-0.5 w-20 text-sm"
                          value={counts[l.utensil_type_id] ?? ''}
                          onChange={(e) => setCounts({ ...counts, [l.utensil_type_id]: e.target.value })} />
                        <Button size="sm" onClick={() => saveCount(l)}>存</Button>
                      </div>
                    )}
                  </td>
                  <td>
                    {l.counted_qty !== null || diff !== null ? (
                      <Badge tone={(frozen ? l.adjustment_qty : diff) < 0 ? 'red' : (frozen ? l.adjustment_qty : diff) > 0 ? 'blue' : 'slate'}>
                        {(frozen ? l.adjustment_qty : diff) > 0 ? '+' : ''}{frozen ? l.adjustment_qty : diff ?? '—'}
                      </Badge>
                    ) : '—'}
                  </td>
                  <td className="text-xs text-slate-600">
                    {r ? (
                      <div className="space-y-0.5">
                        <div>破损：注销 {r.broken_writeoff_qty} ↔ 追赔 {r.broken_claim_qty}</div>
                        <div>盘亏追赔 {r.shortage_claim_qty} 件{/* 与调整 {r.shortage_claim_qty} 同量 */}</div>
                        <div>发放丢失 {r.lost_issued_qty}（不进实物差异）；期末在外 {r.outstanding_qty}</div>
                        {r.consistent ? <Badge tone="green">钱物核对一致</Badge> : <Badge tone="red">不一致</Badge>}
                      </div>
                    ) : <span className="text-slate-400">冻结后生成</span>}
                  </td>
                </tr>
              );
            })}
          </Table>

          {!frozen && (
            <div className="mt-4 flex gap-2">
              {!active.submitted_at
                ? <Button tone="blue" onClick={submit}>员工提交盘点</Button>
                : <Button tone="green" onClick={freeze} disabled={currentStaff?.role !== 'supervisor'}>
                    {currentStaff?.role === 'supervisor' ? '主管确认并冻结' : '等待主管确认'}
                  </Button>}
            </div>
          )}
          {frozen && active.summary && (
            <div className="mt-4 text-sm text-slate-600 border-t pt-3">
              冻结结果：盘亏共 <b>{active.summary.total_shortage_qty}</b> 件，
              追赔金额 <b>¥{(active.summary.total_shortage_amount_cents / 100).toFixed(2)}</b>，
              已在数量流水写入 adjust，并按同一盘点单生成赔付单。
            </div>
          )}
        </Section>
      )}
    </div>
  );
}
