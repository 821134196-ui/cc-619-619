# 校园食堂餐具丢失赔付与集中盘点系统

窗口发放/回收餐具、登记破损丢失赔付、月末集中盘点的一体化系统。
**钱（赔款）与物（餐具数量）两条流严格分开**，可分别对账。

## 一条命令启动

```bash
./dev.sh          # 自动准备环境、迁移、播种，启动前后端
./dev.sh --fresh  # 清空数据重新播种
```

启动后访问 **http://127.0.0.1:8000**

- 后端：Laravel 12 + PHP 8.2 + Eloquent + SQLite（`backend/`）
- 前端：React 18 + Vite + Tailwind（`backend/resources/js/`）
- 扫码、收款均为模拟接口（`App\Services\MockPaymentGateway`、前端手工输入归还码）

> 环境若没有 PHP，首次使用需准备便携运行时（见文末「环境备注」）。Node 18+ 与 npm 是必需的。

## 业务模型与核心规则

| 流 | 表 | 说明 |
|---|---|---|
| 物（数量） | `stock_movements` | 只追加流水：opening / issue(-) / return(+) / writeoff(-) / adjust(±) |
| 物（状态） | `issued_units` | 每件一个唯一归还码：out → returned / lost |
| 钱（赔付） | `loss_claims` + `payment_transactions` | 破损 / 发放丢失 / 盘亏追赔；收款只记钱，不写数量流水 |
| 盘点 | `stocktakes` + `stocktake_lines` | open → 提交 → 主管**冻结**（一次性，不可改） |

关键约束：

1. **跨窗口归还对应原记录**：`return_receipts` 同时保存 `issue_window_id`（原发放窗口）与
   `receive_window_id`（实际接收窗口），经 issued_unit → line → issuance 回指原发放单。
2. **重复扫描只算一次**：`return_receipts.code` / `issued_unit_id` 唯一；二次扫描幂等返回
   `duplicate=true`，不再产生归还流水。
3. **赔款不回补库存**：收款不产生任何 `stock_movements`。
   - 窗口实物破损：登记时即写 writeoff（物）并生成赔付单（钱）；
   - 发放后丢失：餐具在发放时已扣库存，登记丢失只把状态从"待归还"转为"待赔付"，实物数量不变。
4. **冻结盘点不可重复操作**：冻结需主管权限；冻结后修改实盘、再次冻结均返回 422，
   adjust 流水与盘亏赔付单只生成一次。
5. **同一损失不重复计损**：冻结时物线 `adjust(-n)` 与钱线盘亏赔付单引用同一盘点单；
   每行写入 `reconciliation` 快照（破损注销数↔破损追赔数、盘亏量↔盘亏追赔量）。
6. **期末实物可推导**：期初 + 发放 + 归还 + 破损注销 + 盘点调整 = 账面应有 = 期末实物。
7. **换班交接**：快照当前未归还数量（按餐具、按原发放单）与待赔付数量。

## 工作台三分区

- **待归还**：发放后仍在外的餐具（按原发放记录跟踪）
- **待赔付**：已登记损失但未收款的赔款（金额）
- **实物盘亏**：已录实盘、盘亏且尚未主管冻结确认的差异

## 验证

```bash
# 1) PHPUnit 集成测试（9 个场景 / 66 个断言）
cd backend && php artisan test --filter=CanteenWorkflowTest

# 2) HTTP 端到端检查（需先 ./dev.sh 启动，45 项断言）
node e2e-check.mjs
```

覆盖：跨窗口归还关联原发放、重复扫码幂等、丢失/收款均不改变实物、
冻结一次性（重复冻结/改数被拒、流水与赔付单不重复）、钱物核对快照一致、
流水推导期末实物、工作台三类计数分离。

## 主要 API

| 方法 | 路径 | 功能 |
|---|---|---|
| POST | `/api/issuances` | 登记发放（生成归还码、issue 流水） |
| POST | `/api/returns/scan` | 扫码归还（任意窗口，幂等） |
| POST | `/api/claims/broken` | 登记窗口实物破损（writeoff + 赔付单） |
| POST | `/api/claims/lost` | 按归还码登记发放丢失（仅转待赔付） |
| POST | `/api/claims/{id}/pay` | 模拟收款（幂等，不回补库存） |
| POST | `/api/stocktakes` | 月末开账（按流水算账面应有数） |
| POST | `/api/stocktakes/{id}/counts` | 录入实盘（冻结后拒绝） |
| POST | `/api/stocktakes/{id}/submit` | 员工提交 |
| POST | `/api/stocktakes/{id}/freeze` | 主管确认冻结（仅一次） |
| GET | `/api/movements` | 数量流水（按月/餐具过滤） |
| POST | `/api/handovers` | 换班交接快照 |
| GET | `/api/dashboard` | 工作台三类待办 |

种子数据：3 个窗口、3 名员工 + 1 名主管（赵敏）、4 类餐具（餐盘/汤碗/筷子/勺子，含期初库存）。

## 环境备注（无 root 的 Linux）

本机没有系统 PHP 时，可用 Debian 包在用户目录组装便携 PHP 8.2（含 pdo_sqlite、mbstring 等）：

```bash
P=~/php-portable && mkdir -p $P/debs/cache $P/root $P/bin
# 从 deb.debian.org 下载 php8.2-cli/common/sqlite3/mbstring/xml/opcache/readline/curl
# 及 libargon2-1、libonig5、libsodium23，逐个 dpkg-deb -x 解包到 $P/root
# 写 $P/etc/php.ini（extension=pdo.so 必须在 pdo_sqlite.so 之前，openssl.cafile 指向系统 CA）
# 用 patchelf 给 php8.2 及扩展 .so 设置 RPATH，避免依赖 LD_LIBRARY_PATH
# composer.phar 放 $P/bin/composer，另包一层 wrapper
```

`dev.sh` 会优先使用 PATH 中的 `php`，否则自动使用 `~/php-portable/bin/php`。
