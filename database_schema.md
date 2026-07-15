# 8W社区 - 数据库结构说明

> 根据代码中的 SQL 推断，项目中仅存在 `php/create_services_table.sql`，其余表结构需按此文档创建或对照。

---

## 1. users（用户）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| username | VARCHAR | 用户名，唯一 |
| password | VARCHAR | 密码（bcrypt 哈希） |
| game_id | VARCHAR | 游戏内 ID |
| country_id | INT, FK → countries.id, NULL | 所属邦国 |
| role | VARCHAR | 角色：observer, diplomat, peacekeeper, permanent_member, secretary_general |
| jhtuid | TEXT, NULL | 外部系统用户 ID（如聚合平台 UID），可选 |
| level | TEXT, NULL | 等级/段位等扩展信息，文本存储，格式自定义 |
| created_at | DATETIME | 创建时间 |

---

## 2. countries（邦国）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| name | VARCHAR | 邦国名称，唯一 |
| declaration | TEXT, NULL | 国家宣言 |
| government_type | VARCHAR | 政体：monarchy, democracy, guild, other |
| population | INT, NULL | 人口（可选） |
| territory_chunks | INT, NULL | 领地块数（Chunk） |
| flag_url | VARCHAR, NULL | 国旗/图标 URL |
| is_active | BOOLEAN | 是否启用（停用邦国用） |
| joined_at | DATETIME | 加入时间 |

---

## 3. sessions（会话 / 登录态）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| user_id | INT, FK → users.id | 用户 ID |
| token | VARCHAR | 会话 token |
| expires_at | DATETIME | 过期时间 |
| ip_address | VARCHAR | 登录 IP |
| user_agent | VARCHAR | 浏览器 UA |

---

## 4. online_players（在线玩家缓存）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| user_id | INT, FK → users.id | 用户 ID |
| game_id | VARCHAR | 游戏 ID（与 MC 在线列表匹配） |
| country_id | INT, FK, NULL | 邦国 ID |
| last_seen | DATETIME | 最后在线时间（登录时更新） |

---

## 5. news（新闻）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| title | VARCHAR | 标题 |
| content | TEXT | 正文 |
| author_id | INT, FK → users.id | 发布者 ID |
| is_headline | BOOLEAN | 是否头条 |
| published_at | DATETIME | 发布时间 |

---

## 6. timeline（历史时间轴）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| date | DATE | 事件日期 |
| title | VARCHAR | 标题 |
| description | TEXT, NULL | 描述 |
| event_type | VARCHAR | 类型：war, peace, construction, diplomatic, other |
| created_at | DATETIME | 创建时间（可选） |

---

## 7. proposals（提案）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| title | VARCHAR | 标题 |
| description | TEXT | 描述 |
| type | VARCHAR | 类型：territory, defense, trade, embargo, event, other |
| proposer_id | INT, FK → users.id | 提案人 ID |
| country_id | INT, FK → countries.id, NULL | 提案邦国 |
| status | VARCHAR | 状态：draft, voting, passed, rejected |
| voting_start | DATETIME, NULL | 投票开始时间 |
| voting_end | DATETIME, NULL | 投票结束时间 |
| created_at | DATETIME | 创建时间 |

---

## 8. votes（投票记录）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| proposal_id | INT, FK → proposals.id | 提案 ID |
| user_id | INT, FK → users.id | 投票用户 |
| country_id | INT, FK, NULL | 用户所属邦国 |
| vote | VARCHAR | 投票：for, against, abstain |
| has_veto | BOOLEAN | 是否常任理事国一票否决 |

---

## 9. conventions（世界公约）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| title | VARCHAR | 公约标题 |
| content | TEXT | 公约内容 |
| proposal_id | INT, FK → proposals.id, NULL | 来源提案 ID |
| enacted_by_user_id | INT, FK → users.id, NULL | 生效操作人 |
| enacted_at | DATETIME | 生效时间 |

---

## 10. cases（国际法庭案件）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| case_number | VARCHAR | 案号（如 案字第001号） |
| title | VARCHAR | 案件标题 |
| description | TEXT | 描述 |
| plaintiff_id | INT, FK → users.id | 投诉人 ID |
| defendant_country_id | INT, FK → countries.id, NULL | 被诉邦国 |
| status | VARCHAR | 状态：filed, hearing, judged, closed |
| judgment | TEXT, NULL | 判决内容 |
| filed_at | DATETIME | 立案时间 |
| judged_at | DATETIME, NULL | 判决时间 |

---

## 11. case_evidence（案件证据）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| case_id | INT, FK → cases.id | 案件 ID |
| uploaded_by_user_id | INT, FK → users.id, NULL | 上传人 |
| uploaded_at | DATETIME | 上传时间 |
| （可能还有 file_url / path 等字段，代码中未显式列出） |  |  |

---

## 12. arbitration_archive（仲裁结果库）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| case_id | INT, FK → cases.id | 原案件 ID |
| case_number | VARCHAR | 案号 |
| title | VARCHAR | 标题 |
| judgment | TEXT | 判决内容 |

---

## 13. diplomatic_relations（外交关系）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| country1_id | INT, FK → countries.id | 邦国 1 |
| country2_id | INT, FK → countries.id | 邦国 2 |
| relation | VARCHAR | 关系：friendly, hostile, neutral, ceasefire |
| set_by_user_id | INT, FK → users.id | 设置人 |

---

## 14. trades（贸易信息）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| type | VARCHAR | buy / sell |
| item_name | VARCHAR | 物品名称 |
| quantity | VARCHAR, NULL | 数量描述 |
| exchange_method | VARCHAR, NULL | 交换方式 |
| country_id | INT, FK, NULL | 发布邦国 |
| posted_by_user_id | INT, FK → users.id | 发布人 |
| status | VARCHAR | active, completed, cancelled |
| created_at | DATETIME | 创建时间 |

---

## 15. services（公共服务）

已有建表脚本：`php/create_services_table.sql`

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT, PK, AUTO_INCREMENT | 主键 |
| name | VARCHAR(255) | 服务名称 |
| url | VARCHAR(255) | 服务网址 |
| created_at | DATETIME | 创建时间 |

---

## 表关系简图

```
users ←→ countries (多对一, country_id)
users ←→ sessions (一对多)
users ←→ online_players (一对一/缓存)
users ←→ news (author_id)
users ←→ proposals (proposer_id)
users ←→ votes (user_id)
users ←→ conventions (enacted_by_user_id)
users ←→ cases (plaintiff_id)
users ←→ case_evidence (uploaded_by_user_id)
users ←→ diplomatic_relations (set_by_user_id)
users ←→ trades (posted_by_user_id)

countries ←→ proposals (country_id)
countries ←→ cases (defendant_country_id)
countries ←→ diplomatic_relations (country1_id, country2_id)
countries ←→ trades (country_id)

proposals ←→ votes (proposal_id)
proposals ←→ conventions (proposal_id)
cases ←→ case_evidence (case_id)
cases ←→ arbitration_archive (case_id)
```

---

## 字符集建议

建表时建议使用：

```sql
DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

与 `php/config.php` 中 `DB_CHARSET` 一致。



