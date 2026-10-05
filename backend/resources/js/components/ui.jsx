import React from 'react';

export function Card({ title, count, sub, tone = 'slate', children }) {
  const tones = {
    blue: 'border-blue-300 bg-blue-50',
    amber: 'border-amber-300 bg-amber-50',
    red: 'border-red-300 bg-red-50',
    slate: 'border-slate-300 bg-white',
  };
  return (
    <section className={`border rounded-xl p-5 ${tones[tone]}`}>
      <h3 className="text-sm font-semibold text-slate-600">{title}</h3>
      <p className="text-3xl font-bold mt-2 text-slate-900">{count}</p>
      {sub && <p className="text-xs text-slate-500 mt-1">{sub}</p>}
      {children}
    </section>
  );
}

export function Section({ title, children, actions }) {
  return (
    <section className="bg-white border border-slate-200 rounded-xl p-5 mb-5">
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-bold text-slate-800">{title}</h2>
        {actions}
      </div>
      {children}
    </section>
  );
}

export function Button({ children, onClick, disabled, tone = 'slate', size = 'md', type = 'button' }) {
  const tones = {
    slate: 'bg-slate-700 hover:bg-slate-800 text-white',
    blue: 'bg-blue-600 hover:bg-blue-700 text-white',
    green: 'bg-emerald-600 hover:bg-emerald-700 text-white',
    red: 'bg-red-600 hover:bg-red-700 text-white',
    ghost: 'bg-white border border-slate-300 hover:bg-slate-50 text-slate-700',
  };
  const sizes = { sm: 'px-2 py-1 text-xs', md: 'px-4 py-2 text-sm' };
  return (
    <button type={type} onClick={onClick} disabled={disabled}
      className={`rounded ${sizes[size]} ${tones[tone]} disabled:opacity-40 disabled:cursor-not-allowed`}>
      {children}
    </button>
  );
}

export function Badge({ children, tone = 'slate' }) {
  const tones = {
    slate: 'bg-slate-200 text-slate-700',
    green: 'bg-emerald-100 text-emerald-700',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-blue-100 text-blue-700',
  };
  return <span className={`inline-block px-2 py-0.5 rounded text-xs ${tones[tone]}`}>{children}</span>;
}

export function Table({ headers, children }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-slate-500 border-b">
            {headers.map((h) => <th key={h} className="py-2 pr-3 font-medium">{h}</th>)}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
