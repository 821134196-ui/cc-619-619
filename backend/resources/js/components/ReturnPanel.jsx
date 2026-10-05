import React, { useState } from 'react';
import api from '../api';
import { useApp } from '../app';
import { Section, Button, Badge } from './ui';

export default function ReturnPanel() {
  const { meta, windowId, staffId, notify } = useApp();
  const [code, setCode] = useState('');
  const [last, setLast] = useState(null);
  const [history, setHistory] = useState([]);

  const scan = async () => {
    if (!code.trim()) return;
    try {
      const { data } = await api.post('/returns/scan', {
        code: code.trim(), window_id: Number(windowId), staff_id: Number(staffId),
      });
      setLast(data);
      setHistory((h) => [{ code: code.trim(), ...data }, ...h].slice(0, 20));
      notify(data.message, data.duplicate ? 'warn' : 'ok');
      setCode('');
    } catch (e) {
      setLast({ error: e.message, code: code.trim() });
      notify(e.message, 'error');
    }
  };

  const winName = (id) => meta.windows.find((w) => w.id === id)?.name || id;
  const receipt = last?.receipt;
  const cross = receipt && receipt.issue_window_id !== receipt.receive_window_id;

  return (
    <div>
      <Section title={`扫码归还（当前接收窗口：${winName(Number(windowId))}）`}>
        <div className="flex gap-2">
          <input autoFocus value={code} onChange={(e) => setCode(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && scan()}
            placeholder="扫描或输入归还码（如 RXXXXXXXXXXXXXXXXX）"
            className="flex-1 border rounded px-3 py-2 font-mono text-sm" />
          <Button tone="green" onClick={scan}>登记归还</Button>
        </div>
        <p className="text-xs text-slate-500 mt-2">
          其他窗口扫到餐具也在此登记：系统自动关联原发放窗口与原发放单；同一归还码重复扫描只算一次。
        </p>

        {last && (
          <div className={`mt-4 border rounded-lg p-4 text-sm ${last.error ? 'border-red-300 bg-red-50' : last.duplicate ? 'border-amber-300 bg-amber-50' : 'border-emerald-300 bg-emerald-50'}`}>
            {last.error ? (
              <p className="text-red-700">❌ {last.code}：{last.error}</p>
            ) : (
              <div className="space-y-1">
                <p>
                  {last.duplicate ? '⚠️ 重复扫描（只算一次）' : '✅ 归还登记成功'}
                  {cross && <Badge tone="blue">跨窗口归还</Badge>}
                </p>
                <p>归还码：<span className="font-mono">{receipt.code}</span></p>
                <p>原发放窗口：<b>{winName(receipt.issue_window_id)}</b>
                  {' '}→ 实际接收窗口：<b>{winName(receipt.receive_window_id)}</b></p>
                <p>关联原发放单 ID：{receipt.issuance_id}｜接收人：{meta.staff.find((s) => s.id === receipt.receiver_staff_id)?.name}</p>
              </div>
            )}
          </div>
        )}
      </Section>

      <Section title="本次操作记录">
        {history.length === 0 && <p className="text-sm text-slate-400">暂无</p>}
        <ul className="text-sm space-y-1">
          {history.map((h, i) => (
            <li key={i} className="flex items-center gap-2">
              <span className="font-mono text-xs text-slate-500">{h.code}</span>
              {h.duplicate ? <Badge tone="amber">重复·只算一次</Badge> : <Badge tone="green">已登记</Badge>}
              {h.receipt && h.receipt.issue_window_id !== h.receipt.receive_window_id && <Badge tone="blue">跨窗口</Badge>}
            </li>
          ))}
        </ul>
      </Section>
    </div>
  );
}
