#!/usr/bin/env bash
# 端到端验证（针对运行中的真实 HTTP 服务）：
#   1) 跨窗口归还仍对应原发放记录；重复扫描同一归还码只算一次
#   2) 盘点冻结后，重复确认/修改不会改变结果
#   3) 赔付（钱）与库存（数量）分别对账，同一损失不重复计损
#
# 用法: ./dev reset && scripts/e2e-check.sh [BASE_URL]
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}"
PASS=0; FAIL=0

ok()   { echo "  ✓ $*"; PASS=$((PASS+1)); }
bad()  { echo "  ✕ $*"; FAIL=$((FAIL+1)); }
assert() { # assert <描述> <期望值> <实际值>
  if [ "$2" = "$3" ]; then ok "$1（=$3）"; else bad "$1（期望 $2，实际 $3）"; fi
}

jget() { python3 -c "import json,sys; d=json.load(sys.stdin);
expr=sys.argv[1]
cur=d
for p in expr.split('.'):
    cur = cur[int(p)] if p.isdigit() else cur[p]
print(cur)" "$1"; }

echo "▶ 基础数据"
MASTERS=$(curl -s "$BASE/api/masters")
TRAY=$(echo "$MASTERS" | jget 'utensil_types.0.id')
BOWL=$(echo "$MASTERS" | jget 'utensil_types.1.id')
W1=$(echo "$MASTERS" | jget 'windows.0.id')
W3=$(echo "$MASTERS" | jget 'windows.2.id')
STAFF=$(echo "$MASTERS" | jget 'employees.0.id')
SUP=$(echo "$MASTERS" | jget 'employees.2.id')
echo "  餐具=$TRAY/$BOWL 窗口=$W1/$W3 员工=$STAFF 主管=$SUP"

echo
echo "▶ 场景一：跨窗口归还 + 重复扫码幂等"
ISSUE=$(curl -s -X POST "$BASE/api/issuances" -H 'Content-Type: application/json' \
  -d "{\"utensil_type_id\":$BOWL,\"window_id\":$W1,\"employee_id\":$STAFF,\"qty\":6}")
ISSUE_ID=$(echo "$ISSUE" | jget 'issuance_id')
T1=$(echo "$ISSUE" | python3 -c "import json,sys; print(json.load(sys.stdin)['return_tokens'][0])")
T2=$(echo "$ISSUE" | python3 -c "import json,sys; print(json.load(sys.stdin)['return_tokens'][1])")
ok "一号窗口发放 6 个汤碗（发放单 #$ISSUE_ID）"

SCAN=$(curl -s -X POST "$BASE/api/returns/scan" -H 'Content-Type: application/json' \
  -d "{\"return_token\":\"$T1\",\"window_id\":$W3,\"employee_id\":$STAFF}")
assert "归还挂到原发放单" "$ISSUE_ID" "$(echo "$SCAN" | jget 'issuance_id')"
assert "标记为跨窗口归还" "True" "$(echo "$SCAN" | jget 'cross_window')"
assert "原发放窗口" "一号窗口" "$(echo "$SCAN" | jget 'origin_window')"
assert "实际收件窗口" "回收中心窗口" "$(echo "$SCAN" | jget 'return_window')"

RESCAN=$(curl -s -X POST "$BASE/api/returns/scan" -H 'Content-Type: application/json' \
  -d "{\"return_token\":\"$T1\",\"window_id\":$W3,\"employee_id\":$STAFF}")
assert "重复扫描返回 duplicated_scan" "True" "$(echo "$RESCAN" | jget 'duplicated_scan')"
DETAIL=$(curl -s "$BASE/api/issuances/$ISSUE_ID")
assert "原发放单只计 1 次归还" "1" "$(echo "$DETAIL" | jget 'returned_qty')"
assert "未归还数量仍为 5" "5" "$(echo "$DETAIL" | jget 'outstanding_qty')"

echo
echo "▶ 场景二：丢失赔付收款不改库存 + 月末盘点冻结"
BAL_BEFORE=$(curl -s "$BASE/api/ledger?utensil_type_id=$BOWL" | python3 -c "import json,sys; print(json.load(sys.stdin)['ending_calculation'][0]['ending_balance'])")
LOST=$(curl -s -X POST "$BASE/api/compensations/lost" -H 'Content-Type: application/json' \
  -d "{\"return_token\":\"$T2\",\"window_id\":$W1,\"employee_id\":$STAFF}")
COMP_NO=$(echo "$LOST" | jget 'comp_no')
COMP_ID=$(curl -s "$BASE/api/dashboard" | python3 -c "
import json,sys
items=json.load(sys.stdin)['pending_payments']['items']
print(next(i['comp_id'] for i in items if i['comp_no']=='$COMP_NO'))")
PAY=$(curl -s -X POST "$BASE/api/compensations/$COMP_ID/pay" -H 'Content-Type: application/json' -d '{}')
assert "模拟收款成功" "paid" "$(echo "$PAY" | jget 'payment_status')"
BAL_AFTER=$(curl -s "$BASE/api/ledger?utensil_type_id=$BOWL" | python3 -c "import json,sys; print(json.load(sys.stdin)['ending_calculation'][0]['ending_balance'])")
assert "收款后库存数量不变" "$BAL_BEFORE" "$BAL_AFTER"

COUNT=$(curl -s -X POST "$BASE/api/stock-counts" -H 'Content-Type: application/json' \
  -d "{\"period\":\"2026-10\",\"employee_id\":$STAFF}")
COUNT_ID=$(echo "$COUNT" | jget 'id')
BOWL_LINE=$(echo "$COUNT" | python3 -c "import json,sys; print([l for l in json.load(sys.stdin)['lines'] if l['utensil']=='汤碗'][0]['line_id'])")
SYS_QTY=$(echo "$COUNT" | python3 -c "import json,sys; print([l for l in json.load(sys.stdin)['lines'] if l['utensil']=='汤碗'][0]['system_qty'])")
# 实物再少 2（盘亏）
curl -s -X PATCH "$BASE/api/stock-counts/lines/$BOWL_LINE" -H 'Content-Type: application/json' \
  -d "{\"physical_qty\":$((SYS_QTY-2))}" >/dev/null

# 员工不能确认
STAFF_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/api/stock-counts/$COUNT_ID/confirm" \
  -H 'Content-Type: application/json' -d "{\"employee_id\":$STAFF}")
assert "普通员工确认被拒（422）" "422" "$STAFF_CODE"

CONFIRM=$(curl -s -X POST "$BASE/api/stock-counts/$COUNT_ID/confirm" -H 'Content-Type: application/json' \
  -d "{\"employee_id\":$SUP}")
assert "主管确认后冻结" "True" "$(echo "$CONFIRM" | jget 'frozen')"
BAL_FROZEN=$(curl -s "$BASE/api/ledger?utensil_type_id=$BOWL" | python3 -c "import json,sys; print(json.load(sys.stdin)['ending_calculation'][0]['ending_balance'])")
assert "盘亏 2 已出库（$SYS_QTY -> $((SYS_QTY-2))）" "$((SYS_QTY-2))" "$BAL_FROZEN"

# 冻结后改实物 -> 422
PATCH_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X PATCH "$BASE/api/stock-counts/lines/$BOWL_LINE" \
  -H 'Content-Type: application/json' -d '{"physical_qty":1}')
assert "冻结后修改实物被拒（422）" "422" "$PATCH_CODE"

# 重复确认 -> 200 且数量/赔付不再变化
RECONFIRM_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/api/stock-counts/$COUNT_ID/confirm" \
  -H 'Content-Type: application/json' -d "{\"employee_id\":$SUP}")
assert "重复确认被幂等处理（200）" "200" "$RECONFIRM_CODE"
BAL_REPEAT=$(curl -s "$BASE/api/ledger?utensil_type_id=$BOWL" | python3 -c "import json,sys; print(json.load(sys.stdin)['ending_calculation'][0]['ending_balance'])")
assert "重复确认后数量不变" "$BAL_FROZEN" "$BAL_REPEAT"
SHORTAGE_COMPS=$(curl -s "$BASE/api/reconciliation/2026-10" | python3 -c "import json,sys; print(len(json.load(sys.stdin)['compensations']['stock_shortage']))")
assert "盘亏只生成 1 张赔付单" "1" "$SHORTAGE_COMPS"

echo
echo "▶ 场景三：赔付与库存分别对账"
RECON=$(curl -s "$BASE/api/reconciliation/2026-10")
assert "无重复计损" "True" "$(echo "$RECON" | jget 'no_double_count')"
ISSUED_LOST=$(echo "$RECON" | python3 -c "import json,sys; print(len(json.load(sys.stdin)['compensations']['issued_lost']))")
assert "外借丢失赔付 1 张（发放时已出库）" "1" "$ISSUED_LOST"
PAYMENT_ENTRIES=$(curl -s "$BASE/api/ledger?utensil_type_id=$BOWL" | jget 'ending_calculation.0.payment_entries')
assert "数量流水中赔付收款条目为 0" "0" "$PAYMENT_ENTRIES"

echo
echo "▶ 工作台三栏"
DASH=$(curl -s "$BASE/api/dashboard")
python3 -c "
import json,sys
d=json.load(sys.stdin)
print('  待归还: %d 件 / %d 笔' % (d['pending_returns']['total_qty'], d['pending_returns']['count_of_issuances']))
print('  待赔付: ¥%.2f / %d 张' % (d['pending_payments']['total_amount_yuan'], d['pending_payments']['count']))
print('  实物盘亏: %d 件' % d['inventory_shortages']['total_shortage_qty'])
" <<< "$DASH"

echo
if [ "$FAIL" -eq 0 ]; then
  echo "════════ 全部 $PASS 项验证通过 ════════"
else
  echo "════════ $FAIL 项失败 / $PASS 项通过 ════════"
  exit 1
fi
