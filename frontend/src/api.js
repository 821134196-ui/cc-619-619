const BASE = '/api'

async function request(path, options = {}) {
  const res = await fetch(BASE + path, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    ...options,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || `请求失败 (${res.status})`)
  }
  return data
}

export const api = {
  masters: () => request('/masters'),
  dashboard: () => request('/dashboard'),

  issue: (payload) => request('/issuances', { method: 'POST', body: JSON.stringify(payload) }),
  issuance: (id) => request(`/issuances/${id}`),

  scanReturn: (payload) => request('/returns/scan', { method: 'POST', body: JSON.stringify(payload) }),

  openShift: (payload) => request('/shifts', { method: 'POST', body: JSON.stringify(payload) }),
  closeShift: (id, payload) => request(`/shifts/${id}/close`, { method: 'POST', body: JSON.stringify(payload) }),

  reportLost: (payload) => request('/compensations/lost', { method: 'POST', body: JSON.stringify(payload) }),
  reportDamaged: (payload) => request('/compensations/damaged', { method: 'POST', body: JSON.stringify(payload) }),
  pay: (id, simulateFailure = false) =>
    request(`/compensations/${id}/pay`, {
      method: 'POST',
      body: JSON.stringify({ simulate_failure: simulateFailure }),
    }),

  createCount: (payload) => request('/stock-counts', { method: 'POST', body: JSON.stringify(payload) }),
  count: (id) => request(`/stock-counts/${id}`),
  setPhysical: (lineId, physicalQty) =>
    request(`/stock-counts/lines/${lineId}`, { method: 'PATCH', body: JSON.stringify({ physical_qty: physicalQty }) }),
  confirmCount: (id, employeeId) =>
    request(`/stock-counts/${id}/confirm`, { method: 'POST', body: JSON.stringify({ employee_id: employeeId }) }),
  reconciliation: (period) => request(`/reconciliation/${period}`),

  ledger: (params = {}) => {
    const qs = new URLSearchParams(params).toString()
    return request(`/ledger${qs ? '?' + qs : ''}`)
  },
}
