// 端到端 HTTP 验证：跨窗口归还 / 冻结幂等 / 赔付与库存分别对账
const BASE = 'http://127.0.0.1:8000';

let pass = 0, fail = 0;
function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ✅ ${name}`); }
  else { fail++; console.log(`  ❌ ${name} ${extra}`); }
}
async function api(method, path, body) {
  const res = await fetch(BASE + path, {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}

const period = new Date().toISOString().slice(0, 7);

console.log('— 准备：元数据 —');
const meta = (await api('GET', '/api/meta')).json;
const W1 = meta.windows.find((w) => w.code === 'W1').id;
const W2 = meta.windows.find((w) => w.code === 'W2').id;
const E1 = meta.staff.find((s) => s.employee_no === 'E1001').id;
const E2 = meta.staff.find((s) => s.employee_no === 'E1002').id;
const SUP = meta.staff.find((s) => s.role === 'supervisor').id;
const PLATE = meta.utensil_types.find((t) => t.code === 'TP').id;
const BOWL = meta.utensil_types.find((t) => t.code === 'BW').id;

// 期初实物
const mv0 = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
const plateOpen = mv0.filter((m) => m.type === 'opening').reduce((s, m) => s + m.delta, 0);
check('期初餐盘 100', plateOpen === 100, `实际 ${plateOpen}`);

console.log('— 场景1：W1 发放 5 个餐盘 —');
const issue = (await api('POST', '/api/issuances', {
  window_id: W1, staff_id: E1,
  lines: [{ utensil_type_id: PLATE, qty: 5 }],
})).json;
check('发放单生成', issue.issue_no?.startsWith('IS'));
const codes = issue.lines[0].units.map((u) => u.code);
check('生成 5 个归还码', codes.length === 5);

const mvAfterIssue = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('发放后实物 95', mvAfterIssue.at(-1).balance_after === 95, `实际 ${mvAfterIssue.at(-1).balance_after}`);

console.log('— 场景2：在 W2 跨窗口归还第 1 件 —');
const r1 = await api('POST', '/api/returns/scan', { code: codes[0], window_id: W2, staff_id: E2 });
check('跨窗口归还 201', r1.status === 201, `状态 ${r1.status}`);
check('关联原发放窗口 W1', r1.json.receipt.issue_window_id === W1);
check('接收窗口为 W2', r1.json.receipt.issue_window_id !== r1.json.receipt.receive_window_id && r1.json.receipt.receive_window_id === W2);
check('关联原发放单', r1.json.receipt.issuance_id === issue.id);
const mvAfterReturn = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('归还后实物 96', mvAfterReturn.at(-1).balance_after === 96, `实际 ${mvAfterReturn.at(-1).balance_after}`);

console.log('— 场景3：同一归还码重复扫描只算一次 —');
const r2 = await api('POST', '/api/returns/scan', { code: codes[0], window_id: W2, staff_id: E2 });
check('重复扫描返回 200 且 duplicate=true', r2.status === 200 && r2.json.duplicate === true);
const mvDup = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('流水余额仍是 96（未再加）', mvDup.at(-1).balance_after === 96);
check('return 流水只有 1 条', mvDup.filter((m) => m.type === 'return').length === 1);

console.log('— 场景4：2 件发放丢失登记（不动物）+ 收款（不回补库存） —');
const lost = await api('POST', '/api/claims/lost', { codes: [codes[1], codes[2]], window_id: W1, staff_id: E1 });
check('丢失赔付单 201', lost.status === 201, lost.text);
const claim = lost.json[0];
check('赔付金额 = 2×15 = 30 元', claim.amount_cents === 3000, `实际 ${claim.amount_cents}`);
const mvLost = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('登记丢失后实物仍 96', mvLost.at(-1).balance_after === 96, `实际 ${mvLost.at(-1).balance_after}`);

const pay = await api('POST', `/api/claims/${claim.id}/pay`, { staff_id: E2 });
check('模拟收款成功', pay.status === 200 && pay.json.claim.status === 'paid');
check('返回模拟交易号', /MOCKPAY/.test(pay.json.payment.gateway_ref));
const mvPay = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('收款后实物仍 96（钱不回补库存）', mvPay.at(-1).balance_after === 96, `实际 ${mvPay.at(-1).balance_after}`);

const payAgain = await api('POST', `/api/claims/${claim.id}/pay`, { staff_id: E2 });
check('重复收款幂等', payAgain.json.claim.status === 'paid');
const mvPay2 = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('幂等收款后实物仍 96', mvPay2.at(-1).balance_after === 96);

console.log('— 场景5：W2 登记 3 个汤碗破损（writeoff）—');
const broken = await api('POST', '/api/claims/broken', { utensil_type_id: BOWL, qty: 3, window_id: W2, staff_id: E2, reason: '摔碎' });
check('破损赔付单 201', broken.status === 201);
const mvBowl = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${BOWL}`)).json;
check('汤碗 writeoff 后实物 197', mvBowl.at(-1).balance_after === 197, `实际 ${mvBowl.at(-1).balance_after}`);
await api('POST', `/api/claims/${broken.json.id}/pay`, { staff_id: E1 });
const mvBowl2 = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${BOWL}`)).json;
check('破损收款后汤碗仍 197', mvBowl2.at(-1).balance_after === 197);

console.log('— 场景6：月末盘点，主管冻结 —');
const open = await api('POST', '/api/stocktakes', { period, staff_id: E1 });
check('盘点开账 201', open.status === 201, open.text);
const stId = open.json.id;
const plateLine = open.json.lines.find((l) => l.utensil_type_id === PLATE);
// 餐盘账面 96，实盘 94 => 盘亏 2
let rc = await api('POST', `/api/stocktakes/${stId}/counts`, { utensil_type_id: PLATE, counted_qty: 94 });
check('录入餐盘实盘', rc.status === 200);
for (const l of open.json.lines.filter((x) => x.utensil_type_id !== PLATE)) {
  await api('POST', `/api/stocktakes/${stId}/counts`, { utensil_type_id: l.utensil_type_id, counted_qty: l.expected_qty });
}

// 员工不能冻结
const freezeStaff = await api('POST', `/api/stocktakes/${stId}/freeze`, { staff_id: E1 });
check('员工冻结被拒 403', freezeStaff.status === 403, `状态 ${freezeStaff.status}`);

await api('POST', `/api/stocktakes/${stId}/submit`, { staff_id: E1 });
const freeze1 = await api('POST', `/api/stocktakes/${stId}/freeze`, { staff_id: SUP });
check('主管冻结成功', freeze1.status === 200 && freeze1.json.status === 'frozen', freeze1.text);
const frozenPlateLine = freeze1.json.lines.find((l) => l.utensil_type_id === PLATE);
check('餐盘 adjust = -2', frozenPlateLine.adjustment_qty === -2);
check('钱物核对快照一致', frozenPlateLine.reconciliation.consistent === true);
check('破损注销 3 ↔ 追赔 3（汤碗）', (() => {
  const bl = freeze1.json.lines.find((l) => l.utensil_type_id === BOWL);
  return bl.reconciliation.broken_writeoff_qty === 3 && bl.reconciliation.broken_claim_qty === 3 && bl.reconciliation.consistent;
})());
check('发放丢失不进实物差异（丢失2件仍在对账快照）', frozenPlateLine.reconciliation.lost_issued_qty === 2);

const mvFrozen = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('冻结后餐盘实物 = 96 - 2 = 94', mvFrozen.at(-1).balance_after === 94, `实际 ${mvFrozen.at(-1).balance_after}`);

console.log('— 场景7：冻结后重复操作被拒、结果不变 —');
const freeze2 = await api('POST', `/api/stocktakes/${stId}/freeze`, { staff_id: SUP });
check('重复冻结返回 422', freeze2.status === 422, `状态 ${freeze2.status}: ${freeze2.json?.message}`);
const editAfter = await api('POST', `/api/stocktakes/${stId}/counts`, { utensil_type_id: PLATE, counted_qty: 1 });
check('冻结后改实盘被拒', editAfter.status === 422, `状态 ${editAfter.status}`);
const mvRecheck = (await api('GET', `/api/movements?period=${period}&utensil_type_id=${PLATE}`)).json;
check('adjust 流水仍只有 1 条（-2）', mvRecheck.filter((m) => m.type === 'adjust').length === 1 && mvRecheck.find((m) => m.type === 'adjust').delta === -2);
check('实物仍为 94 未被重复操作改变', mvRecheck.at(-1).balance_after === 94);

const shortageClaims = (await api('GET', '/api/claims')).json;
const sc = shortageClaims.filter((c) => c.source === 'stocktake_shortage');
check('盘亏赔付单只生成 1 张（2件）', sc.length === 1 && sc[0].qty === 2, `实际 ${sc.length} 张`);
check('盘亏赔付单关联盘点单', sc[0] && sc[0].stocktake && sc[0].stocktake.period === period);

console.log('— 场景8：工作台三类计数分开 —');
const dash = (await api('GET', '/api/dashboard')).json;
check(`待归还 = 2（发5 − 丢2 − 还1）实际 ${dash.pending_returns.total_qty}`, dash.pending_returns.total_qty === 2);
check('待赔付不含已收（盘亏2件待赔）', dash.pending_claims.total_qty === 2, `实际 ${dash.pending_claims.total_qty}`);
check('实物盘亏为 0（已全部冻结）', dash.stock_shortages.total_qty === 0, `实际 ${dash.stock_shortages.total_qty}`);

console.log('— 场景9：流水推导期末实物 —');
const rows = mvRecheck;
const sum = (t) => rows.filter((m) => m.type === t).reduce((s, m) => s + m.delta, 0);
const derived = 100 + sum('issue') + sum('return') + sum('writeoff') + sum('adjust');
check(`期初100 + 发(${sum('issue')}) + 还(${sum('return')}) + 注销(${sum('writeoff')}) + 调整(${sum('adjust')}) = ${derived} = 94`, derived === 94);
check('赔款收款不出现在数量流水', rows.every((m) => !['payment', 'paid'].includes(m.type)));

console.log('— 场景10：换班交接 —');
const ho = await api('POST', '/api/handovers', { window_id: W1, from_staff_id: E1, to_staff_id: E2 });
check('交接快照未归还 2 件', ho.status === 201 && ho.json.total_outstanding === 2, `实际 ${ho.json.total_outstanding}`);

console.log('— 首页可访问 —');
const home = await fetch(BASE + '/');
check('GET / 返回 200 HTML', home.status === 200 && (await home.text()).includes('id="root"'));

console.log(`\n结果：${pass} 通过，${fail} 失败`);
process.exit(fail ? 1 : 0);
