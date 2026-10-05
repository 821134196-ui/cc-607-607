# 🎭 剧场无障碍字幕设备预约与归还系统

为需要字幕辅助的观众提供「按场次 + 语言」预约字幕设备；前台负责发放核对与散场复检；技术员负责按场次发布字幕，演出中换稿必须经负责人审批。

技术栈：**PHP 8.2 · Laravel 12 · Eloquent · SQLite · React 18 · Vite**。票务与通知接口均为**本地模拟**（`App\Services\TicketService` / `NotificationService`，通知落库 `notification_logs`，不真正外发）。

---

## 一条命令启动

```bash
./start.sh            # 首次自动装依赖、迁移、填充演示数据，然后同时起前后端
./start.sh --reset    # 重置数据库并重新填充演示数据
```

- 前端（观众/前台/技术员/负责人同一入口，按角色显示菜单）：<http://127.0.0.1:5173>
- 后端 API：<http://127.0.0.1:8000/api> ｜ 健康检查：<http://127.0.0.1:8000/up>
- `Ctrl+C` 同时停止前后端。

> 无 root 环境：仓库在 `~/.local/php` 内置了用户级 PHP 8.2（含 pdo_sqlite/mbstring/curl/zip/xml 等扩展）。`start.sh` 会自动识别；系统已装 PHP/Composer 时则优先用系统的。

### 演示账号（密码均为 `password`）

| 角色 | 邮箱 | 能做什么 |
|---|---|---|
| 观众 | `audience@example.com` | 查场次/语言余量、预约、改签、取消 |
| 前台 | `desk@example.com` | 发放核对、收回复检、设备占用看板 |
| 技术员 | `tech@example.com` | 按场次发布字幕、演出中发起换稿申请、设备复检放行 |
| 负责人 | `manager@example.com` | 审批换稿申请 |

模拟票号格式：`T-<场次ID>-XXXX`，例如 `T-1-A1B2`。

---

## 业务流程

### 观众
1. 选择场次 → 选择字幕语言（中/英/日），页面显示**总容量 / 已约 / 可预约**。
2. 填写票号（模拟票务验票）与联系方式后预约。
3. **改签**：可选新场次/语言；系统在**同一个数据库事务**里把原预约置为 `cancelled`（释放占用）再建立新预约。目标场次已满等失败会整体回滚，旧预约不会丢。
4. 取消同样立即释放容量。

### 前台
- **发放**：必须核对 ① 实物编号 ② 实测电量（<40% 拒绝）③ 字幕语言（必须与预约一致）。
- 设备处于「使用中（未归还）」或「维修中」时**不能发放**；同一设备存在未归还发放记录时数据库唯一索引也会兜底拒绝。
- **散场收回**：复检 `ok` → 设备回到可发放池；复检 `fault` → 进入维修隔离；可标记 `missing`（未归还）。三类都不会流向下一场。
- **复检放行**：技术员对维修/找回的设备检修后，在「设备复检放行」页录入复检电量并放行，设备才重新进入可发放池。
- 后台看板显示每台设备的占用状态、电量、最近检查结果与时间。

### 技术员 / 负责人
- 字幕版本**必须绑定场次 + 语言**，每次发布版本号自增，正文落不可变快照文件并记录 SHA-256。
- **开演前**：技术员可直接发布新版本（旧版转为 `superseded` 但文件保留）。
- **开演后**：不能直接换稿。技术员发起申请 → 负责人批准/驳回；批准时才切换当前版本、记录 `switched_at` 与审批人。技术员无权审批自己的申请。
- 历史版本（含旧文件正文、校验和、切换时间）随时可查。

---

## 数据模型（Eloquent / SQLite）

```
users(role, api_token)
performances ── performance_languages(language, capacity)
reservations(user, performance, language, status, seq, cancelled_at…)
devices(serial, status, battery_level, last_check_result, fault_notes…)
device_assignments(device, reservation, performance, 发放核对字段, 复检字段)
caption_scripts(performance, language, version_no, content, file_path, checksum, status, activated_at)
caption_change_requests(performance, language, new/current_script, status, 申请人/审批人, switched_at)
notification_logs(本地模拟短信/邮件)
```

防重复 / 防超售由 **事务 + `lockForUpdate` 行锁 + SQLite 部分唯一索引** 共同保证：
- `reservations (user_id, performance_id) WHERE status IN ('active','issued')` 唯一；
- `device_assignments (device_id) WHERE status='issued'` 唯一；
- `caption_scripts (performance_id, language) WHERE status='active'` 唯一。

## 主要 API（均需 `Authorization: Bearer <token>`）

```
POST /api/auth/login
GET  /api/performances | GET /api/performances/{id}
POST /api/reservations | POST /api/reservations/{id}/rebook | /cancel | GET /api/reservations
GET  /api/desk/devices | GET /api/desk/pickups
POST /api/desk/assignments | /{id}/return | /{id}/missing
POST /api/desk/devices/{id}/release                                     # 技术员复检放行维修设备
POST /api/performances/{id}/captions                                   # 技术员（开演前直接发布）
POST /api/performances/{id}/caption-change-requests                    # 技术员（演出中申请）
POST /api/caption-change-requests/{id}/review   { approve, review_note }  # 仅负责人
GET  /api/performances/{id}/captions/{lang}/history | /api/captions/scripts/{id}
```

---

## 无障碍（观众页面）

- **大字**：根字号 20px 起，关键余量用 1.5rem+ 数字；控件最小高度 48–56px。
- **高对比**：近黑文字/纯白底、3px 粗边框，状态不仅靠颜色（同时有中文文字徽标）。
- **全键盘操作**：所有可交互元素都是原生 `button/a/input/select`（无 div 点击陷阱）；提供「跳到主要内容」跳转链接；`:focus-visible` 用粗黄框 + 黑外环清晰标示焦点；表单 `<label htmlFor>` 与控件程序化关联；单选语言组用 `radiogroup`。

## 运行自动化测试

```bash
cd backend
php artisan test          # 16 个 Feature 测试
```

覆盖三项必须验证的行为：
- **改签释放旧占用**：旧单 `cancelled`、容量归还、改签失败原子回滚、取消后可重约、容量不可超售；
- **设备不重复分配**：未归还设备跨场次拒绝、故障/未归还隔离、正常归还回池、发放三项核对；
- **演出中换稿需批准且有历史**：直接发布被拦、未批准不切换、技术员无权审批、批准后旧版保留并记录切换时间、旧文件校验和完整。
