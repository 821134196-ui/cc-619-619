import React, { createContext, useContext, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import api from './api';
import Dashboard from './components/Dashboard';
import IssuePanel from './components/IssuePanel';
import ReturnPanel from './components/ReturnPanel';
import ClaimsPanel from './components/ClaimsPanel';
import StocktakePanel from './components/StocktakePanel';
import MovementsPanel from './components/MovementsPanel';
import HandoverPanel from './components/HandoverPanel';

const AppCtx = createContext(null);
export const useApp = () => useContext(AppCtx);

const TABS = [
  ['dashboard', '工作台'],
  ['issue', '发放登记'],
  ['return', '扫码归还'],
  ['claims', '破损/丢失赔付'],
  ['stocktake', '月末盘点'],
  ['movements', '数量流水'],
  ['handover', '换班交接'],
];

function Toast({ toast }) {
  if (!toast) return null;
  const color = toast.kind === 'error' ? 'bg-red-600' : toast.kind === 'warn' ? 'bg-amber-600' : 'bg-emerald-600';
  return (
    <div className={`fixed top-4 right-4 z-50 ${color} text-white px-4 py-3 rounded-lg shadow-lg max-w-md text-sm`}>
      {toast.text}
    </div>
  );
}

function App() {
  const [meta, setMeta] = useState(null);
  const [tab, setTab] = useState('dashboard');
  const [windowId, setWindowId] = useState('');
  const [staffId, setStaffId] = useState('');
  const [toast, setToast] = useState(null);

  const notify = (text, kind = 'ok') => {
    setToast({ text, kind });
    setTimeout(() => setToast(null), 3500);
  };

  useEffect(() => {
    api.get('/meta').then(({ data }) => {
      setMeta(data);
      if (data.windows[0]) setWindowId(String(data.windows[0].id));
      const firstStaff = data.staff.find((s) => s.role === 'staff');
      if (firstStaff) setStaffId(String(firstStaff.id));
    });
  }, []);

  if (!meta) return <div className="p-8 text-slate-500">加载中…</div>;

  const currentStaff = meta.staff.find((s) => String(s.id) === staffId);
  const ctx = { meta, windowId, setWindowId, staffId, setStaffId, currentStaff, notify, reloadMeta: () => api.get('/meta').then((r) => setMeta(r.data)) };

  return (
    <AppCtx.Provider value={ctx}>
      <Toast toast={toast} />
      <header className="bg-slate-800 text-white">
        <div className="max-w-7xl mx-auto px-5 py-4 flex items-center gap-4 flex-wrap">
          <h1 className="text-lg font-bold whitespace-nowrap">🍽️ 餐具赔付与集中盘点系统</h1>
          <div className="flex items-center gap-2 text-sm ml-auto">
            <label>当前窗口</label>
            <select className="text-slate-800 rounded px-2 py-1" value={windowId} onChange={(e) => setWindowId(e.target.value)}>
              {meta.windows.map((w) => <option key={w.id} value={w.id}>{w.code} {w.name}</option>)}
            </select>
            <label>操作员</label>
            <select className="text-slate-800 rounded px-2 py-1" value={staffId} onChange={(e) => setStaffId(e.target.value)}>
              {meta.staff.map((s) => <option key={s.id} value={s.id}>{s.name}{s.role === 'supervisor' ? '（主管）' : ''}</option>)}
            </select>
          </div>
        </div>
        <nav className="max-w-7xl mx-auto px-5 flex gap-1 flex-wrap">
          {TABS.map(([key, label]) => (
            <button key={key} onClick={() => setTab(key)}
              className={`px-4 py-2 text-sm rounded-t-lg ${tab === key ? 'bg-slate-100 text-slate-900 font-semibold' : 'text-slate-300 hover:bg-slate-700'}`}>
              {label}
            </button>
          ))}
        </nav>
      </header>
      <main className="max-w-7xl mx-auto p-5">
        {tab === 'dashboard' && <Dashboard goto={setTab} />}
        {tab === 'issue' && <IssuePanel />}
        {tab === 'return' && <ReturnPanel />}
        {tab === 'claims' && <ClaimsPanel />}
        {tab === 'stocktake' && <StocktakePanel />}
        {tab === 'movements' && <MovementsPanel />}
        {tab === 'handover' && <HandoverPanel />}
      </main>
    </AppCtx.Provider>
  );
}

createRoot(document.getElementById('root')).render(<App />);
