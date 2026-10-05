# 校园食堂餐具丢失赔付与集中盘点系统

食堂窗口日常发放 / 回收餐具，月末核对实物库存；**赔款（钱）与餐具是否归还（数量）两条线分别处理**。

技术栈：PHP 8.2 · Laravel 11 · Eloquent · SQLite · React 18 · Vite。扫码与收款均为模拟接口。

## 一条命令启动

```bash
./dev up        # 迁移+种子+演示数据，同时启动前端(5173)与后端(8000)
./dev test      # 9 个 PHPUnit 用例（84 断言）
./dev reset     # 重置数据库并重写演示数据
scripts/e2e-check.sh   # 对运行中的服务做 20 项真实 HTTP 端到端验证
```

启动后：

- 前端工作台：http://127.0.0.1:5173
- 后端 API：http://127.0.0.1:8000/api （Vite 已把 `/api` 代理到 8000）
- 演示身份：王芳 / 李强（窗口员工），**周敏（主管，盘点确认/冻结）**

> 环境无系统 PHP 时，脚本会使用 `~/.local/bin/php`（本项目在无 root 的 Debian 上
> 以 deb 解包方式安装了 PHP 8.2 及 sqlite/mbstring/xml/curl/zip 扩展）。

## 业务模型

| 模块 | 说明 |
|---|---|
| 发放 `issuances` + 明细 `issuance_items` | 窗口发放即出库，**每件餐具一个唯一归还码** |
| 归还 `returns` | 任意窗口可扫码收回，记录始终关联**原发放单**；`cross_window` 标记跨窗口 |
| 赔付 `compensations` | 破损 / 丢失登记 + 模拟收款；**收款只改钱的状态，绝不写数量流水** |
| 换班 `shifts` | 结班固化「尚未归还数量」快照，未还责任随原发放单延续 |
| 盘点 `stock_counts(+lines)` | 月末账实核对，**主管确认后整单冻结** |
| 数量流水 `stock_ledger` | initial/issue/return/adjust_in/adjust_out，期末实物 = 流水合计 |
| 收款 `payment_attempts` | 模拟支付网关回调留痕（含模拟失败） |

### 工作台三栏（彼此独立）

1. **待归还**：已发放未收回（未被赔付核销）的数量；
2. **待赔付**：已登记破损/丢失但赔款未收；
3. **实物盘亏**：盘点草稿中账实不符、待主管确认的行。

## 关键不变量（如何被保证）

- **跨窗口归还对应原记录**：归还记录强制带 `issuance_id`/`issuance_item_id`，
  收件窗口只写入 `return_window_id`；每件餐具在 `returns.issuance_item_id` 上有唯一约束。
- **重复扫描只算一次**：`issuance_items` 每件只有 out/returned/lost 三态；
  已归还的码再次扫描直接幂等回放首次记录，不新增流水、不重复计数。
- **收款不把餐具加回库存**：`StockLedgerService` 是数量变动的唯一入口，
  赔付/收款流程从不调用它；流水中可审计地看到 `payment_entries = 0`。
- **盘点冻结**：`stock_counts.status = confirmed` 后，修改实物返回 422，
  重复确认幂等回放，不再产生任何调整流水或赔付单。
- **不重复计损**：
  - 外借丢失：餐具在**发放时已出库**，赔付只核销未还责任、不写流水，月末不可能再盘亏一次；
  - 库内破损：登记当时已出 `adjust_out`，月末账实自然一致；
  - 真盘亏：确认时 1 条 `adjust_out` + 1 张赔付单，同指一个盘点行
    （`stock_count_line_id` 唯一），`assertNoDoubleCount()` 硬性核对。
- **数量流水可解释期末实物**：`期末实物 = initial + Σ(issue/return/adjust)`，
  前端「数量流水/对账」页逐行展示带符号变动与结存。

## 验证结果

- `./dev test` → 9 passed / 84 assertions，覆盖跨窗口归还、扫码幂等、冻结防重复、
  主管权限、钱数分账、盘亏一一对应、丢失后拒绝归还等；
- `scripts/e2e-check.sh` → 20/20 通过（真实 HTTP：发放 → 跨窗口归还 → 重复扫码 →
  丢失赔付收款 → 员工确认被拒 → 主管冻结 → 冻结后改写被拒 → 重复确认幂等 → 对账单）。

## 目录

```
backend/
  app/Models/            Eloquent 模型
  app/Services/          IssuanceService / CompensationService /
                         StockCountService / StockLedgerService / ShiftService / DashboardService
  app/Http/Controllers/  CanteenController（/api/*）
  database/migrations|seeders
  tests/Feature/         CanteenFlowTest（服务层）+ CanteenApiTest（HTTP 全链路）
frontend/src/components/ 工作台/发放/归还/赔付/换班/盘点/流水 七个面板
dev                      一条命令启动脚本
scripts/e2e-check.sh     端到端验证脚本
```
