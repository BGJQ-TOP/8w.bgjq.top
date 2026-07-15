# 8W社区 API 接口文档

## 概述


**模型分类**：
- **文字生成**: GLM-4-Flash、GLM-4-Flash-250414、GLM-Z1-Flash、GLM-4.7-Flash、GLM-4.6V-Flash、GLM-4.1V-Thinking-Flash、GLM-4V-Flash
- **图片生成**: CogView-3-Flash
- **视频生成**: CogVideoX-Flash
- **图片理解**: GLM-4V-Flash

---

## 邦国崛起 Mineflayer API 代理

通过 `/bgjq` 路径代理访问邦国崛起 Mineflayer 游戏机器人 API。

**代理基础地址**: `http://YOUR_API_HOST:PORT/bgjq`

**功能**：
- 游戏消息查询
- 游戏指令执行
- 玩家和邦国数据查询（返回结构化 JSON 数据）
- 二维码识别
- WebSocket 实时连接

### 指令接口

#### 获取玩家信息（快捷指令）

```http
POST /bgjq/commands/get-player-info
```

**请求体**
```json
{
  "player_name": "DiLiDaLaDaLa"
}
```

**响应示例**
```json
{
  "success": true,
  "data": {
    "success": true,
    "commandId": 1
  },
  "note": "玩家信息已发送到游戏聊天，机器人将自动收集返回的多行数据并解析为 JSON 存储到数据库"
}
```

#### 获取邦国信息（快捷指令）

```http
POST /bgjq/commands/get-faction-info
```

**请求体**
```json
{
  "faction_name": "测试邦国"
}
```

**响应示例**
```json
{
  "success": true,
  "data": {
    "success": true,
    "commandId": 1
  },
  "note": "邦国信息已发送到游戏聊天，机器人将自动收集返回的多行数据并解析为 JSON 存储到数据库"
}
```

### 数据查询接口

#### 查询玩家信息

```http
GET /bgjq/data/player/:playerName
```

**响应示例**
```json
{
  "success": true,
  "data": {
    "status": "在线",
    "faction": "流浪小镇",
    "firstJoin": "2026-01-01 20:47:29",
    "lastOnline": "现在",
    "armor": ["Iron Helmet", "Iron Chestplate", "Iron Leggings", "Iron Boots"],
    "handItem": "Iron Ingot x55"
  }
}
```

#### 查询邦国信息

```http
GET /bgjq/data/faction/:factionName
```

**响应示例**
```json
{
  "success": true,
  "data": {
    "monarch": "DiLiDaLaDaLa",
    "createDate": "2026-06-13 11:40:53",
    "specialty": "Amethyst Shard",
    "territory": "1 区块",
    "joinMethod": "仅邀请",
    "shield": "未激活",
    "capitalCoords": "-12651, 142, -14454",
    "tech": "无",
    "grantedDiplomacy": "所有邦国: 访问",
    "receivedDiplomacy": "雅典维亚城邦（附属国): 访问, FireIce: 访问, ...",
    "citizens": "DiLiDaLaDaLa (君主)",
    "unlockedLimits": "国民(+0)，职业(+0)"
  }
}
```

详细接口文档请参考 [API 文档.md](../API 文档.md)

---

## 基础信息

- **基础 URL**: `http://YOUR_API_HOST:PORT`
- **认证方式**: Header `x-api-key`（AI 接口需要）
- **请求格式**: JSON

---

## 环境变量

| 变量名 | 说明 |
|--------|------|
| `PORT` | 服务端口，默认 53347 |
| `API_KEY` | 智谱 GLM API Key |
| `APP_API_KEY` | 管理 API Key |
| `DB_HOST` | 数据库地址，默认 127.0.0.1 |
| `DB_USER` | 数据库用户，默认 root |
| `DB_PASSWORD` | 数据库密码 |
| `DB_NAME` | 数据库名称，默认 bgjq_api |
| `QYWX_CORP_ID` | 企业微信 CorpID |
| `QYWX_SECRET` | 企业微信 Secret |
| `QYWX_AGENT_ID` | 企业微信 AgentID |
| `EMAIL_SUBJECT_PREFIX` | 邮件主题前缀，默认 BGJQ |
| `EMAIL_EXPIRE_MINUTES` | 验证码有效期（分钟），默认 5 |

---

## 通用说明

### 认证

所有接口（除 `/` 和 `/health` 外）都需要在 Header 中携带 API Key：

```http
x-api-key: sk-your-api-key
```

### 响应格式

```json
{
  "success": true,
  ...
}
```

### 错误响应

**401 - 认证失败**
```json
{
  "success": false,
  "error": "Invalid API key",
  "code": "INVALID_KEY"
}
```

**403 - 权限不足**
```json
{
  "success": false,
  "error": "Admin access required",
  "code": "ADMIN_REQUIRED"
}
```

**400 - 请求错误**
```json
{
  "success": false,
  "error": "Missing required field: message"
}
```

---

## API 接口

### 1. 健康检查

```http
GET /health
```

**响应**
```json
{
  "status": "ok",
  "timestamp": "2026-05-17T02:00:00.000Z"
}
```

---

### 2. 发送邮箱验证码

发送验证码到指定邮箱。

```http
POST /api/email/send-code
```

**请求体**
```json
{
  "email": "user@example.com",
  "purpose": "register"
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| email | string | 是 | 目标邮箱地址 |
| purpose | string | 否 | 用途标识，如 register、login，默认 verify |

**响应**
```json
{
  "success": true,
  "message": "Verification code sent",
  "expires_in": 300
}
```

**注意**: 需要在环境变量中配置企业微信才能发送邮件。

---

### 3. 验证邮箱验证码

验证验证码是否正确。

```http
POST /api/email/verify-code
```

**请求体**
```json
{
  "email": "user@example.com",
  "code": "123456",
  "purpose": "register"
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| email | string | 是 | 邮箱地址 |
| code | string | 是 | 6位数字验证码 |
| purpose | string | 否 | 用途标识，默认 verify |

**响应**

正确：
```json
{
  "success": true,
  "valid": true,
  "message": "Verification code is valid"
}
```

错误：
```json
{
  "success": true,
  "valid": false,
  "message": "Invalid or expired verification code"
}
```

---

### 4. AI 对话

与 GLM 模型对话，支持多轮对话。

```http
POST /api/ai/chat
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-your-api-key
```

**请求体**
```json
{
  "message": "你好",
  "model": "glm-4-flash",
  "session_id": "user001",
  "temperature": 0.7,
  "clear_history": false
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| message | string | 是 | 用户消息 |
| model | string | 否 | 模型名称，默认 glm-4-flash |
| session_id | string | 否 | 会话 ID，默认 default |
| temperature | number | 否 | 温度参数 0-1，默认 0.7 |
| clear_history | boolean | 否 | 是否清除历史，默认 false |

**可用模型**: glm-4-flash, glm-4-flash-250414, glm-z1-flash, glm-4-7-flash, glm-4-6v-flash, glm-4-1v-thinking-flash, glm-4v-flash

**响应**
```json
{
  "success": true,
  "model": "glm-4-flash",
  "session_id": "user001",
  "response": "你好！有什么可以帮助你的？",
  "usage": {
    "completion_tokens": 15,
    "prompt_tokens": 8,
    "total_tokens": 23
  }
}
```

---

### 3. 内容审核

审核用户提交的文本内容。

```http
POST /api/ai/audit
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-your-api-key
```

**请求体**
```json
{
  "content": "待审核的内容"
}
```

**审核标准**

1. 政治敏感内容
2. 暴力血腥内容
3. 色情低俗内容
4. 赌博诈骗内容
5. 虚假信息
6. 仇恨言论
7. 侵权内容
8. 违法犯罪
9. 垃圾广告
10. 其他违规

**响应**

通过：
```json
{
  "success": true,
  "passed": true,
  "reason": null,
  "content": "这是一条正常的评论..."
}
```

拒绝：
```json
{
  "success": true,
  "passed": false,
  "reason": "政治敏感内容",
  "content": "违规内容..."
}
```

---

### 4. 获取对话历史

```http
GET /api/ai/history/:session_id
```

**Headers**
```http
x-api-key: sk-your-api-key
```

**响应**
```json
{
  "success": true,
  "session_id": "user001",
  "history": [
    { "role": "user", "content": "你好" },
    { "role": "assistant", "content": "你好！" }
  ]
}
```

---

### 5. 清除对话历史

```http
DELETE /api/ai/history/:session_id
```

**Headers**
```http
x-api-key: sk-your-api-key
```

**响应**
```json
{
  "success": true,
  "message": "History cleared for session: user001"
}
```

---

### 6. 图像生成

使用 CogView 模型生成图像。

```http
POST /api/ai/image
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-your-api-key
```

**请求体**
```json
{
  "prompt": "一只可爱的橘猫",
  "model": "cogview-3-flash"
}
```

**响应**
```json
{
  "success": true,
  "model": "cogview-3-flash",
  "imageUrl": "https://..."
}
```

---

### 7. 视频生成

使用 CogVideoX 模型生成视频。

```http
POST /api/ai/video
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-your-api-key
```

**请求体**
```json
{
  "prompt": "一只小猫在草地上奔跑",
  "model": "cogvideox-flash"
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| prompt | string | 是 | 视频描述提示词 |
| model | string | 否 | 模型名称，默认 cogvideox-flash |

**可用模型**: cogvideox-flash

**响应**
```json
{
  "success": true,
  "model": "cogvideox-flash",
  "videoUrl": "https://..."
}
```

---

### 8. 图片理解

使用 GLM-4V 模型理解图片内容。

```http
POST /api/ai/vision
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-your-api-key
```

**请求体**
```json
{
  "message": "这张图片里有什么？",
  "image_url": "https://example.com/image.jpg",
  "model": "glm-4v-flash"
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| message | string | 是 | 关于图片的问题或描述请求 |
| image_url | string | 是 | 图片的 URL 地址 |
| model | string | 否 | 模型名称，默认 glm-4v-flash |

**可用模型**: glm-4v-flash

**响应**
```json
{
  "success": true,
  "model": "glm-4v-flash",
  "response": "这张图片里有一只可爱的猫咪..."
}
```

---

### 9. 获取可用模型

```http
GET /api/ai/models
```

**Headers**
```http
x-api-key: sk-your-api-key
```

**响应**
```json
{
  "success": true,
  "text": ["glm-4-flash", "glm-4-flash-250414", "glm-z1-flash", "glm-4-7-flash", "glm-4-6v-flash", "glm-4-1v-thinking-flash", "glm-4v-flash"],
  "image": ["cogview-3-flash"],
  "video": ["cogvideox-flash"],
  "vision": ["glm-4v-flash"],
  "audit": "glm-4-flash"
}
```

---

## 管理接口（仅 Admin）

### 8. 获取 API Key 列表

```http
GET /api/ai/keys
```

**Headers**
```http
x-api-key: sk-admin-key
```

**响应**
```json
{
  "success": true,
  "keys": [
    { "id": 1, "key_name": "default", "created_at": "2026-05-17T00:00:00.000Z" }
  ]
}
```

---

### 9. 创建 API Key

```http
POST /api/ai/keys
```

**Headers**
```http
Content-Type: application/json
x-api-key: sk-admin-key
```

**请求体**
```json
{
  "key_name": "myapp",
  "role": "user"
}
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| key_name | string | 是 | Key 名称（唯一标识） |
| role | string | 否 | 角色，admin 或 user，默认 user |

**响应**
```json
{
  "success": true,
  "message": "API key 'myapp' created with role 'user'",
  "api_key": "sk-xxxxxxxxxxxx"
}
```

**注意**: 生成的 API Key 只在创建时返回一次，请妥善保存。

---

### 10. 删除 API Key

```http
DELETE /api/ai/keys/:key_name
```

**Headers**
```http
x-api-key: sk-admin-key
```

**注意**: 不能删除 default key。

**响应**
```json
{
  "success": true,
  "message": "API key 'myapp' deleted"
}
```

---

### 11. 获取认证日志

```http
GET /api/ai/auth/logs
```

**Headers**
```http
x-api-key: sk-admin-key
```

**响应**
```json
{
  "success": true,
  "logs": [
    {
      "id": 1,
      "ip": "::1",
      "action": "success",
      "success": 1,
      "reason": "role: admin",
      "user_agent": "curl/8.5.0",
      "created_at": "2026-05-17T00:00:00.000Z"
    }
  ]
}
```

---

### 12. 获取审核日志

```http
GET /api/ai/audit/logs
```

**Headers**
```http
x-api-key: sk-your-api-key
```

**响应**
```json
{
  "success": true,
  "logs": [
    {
      "id": 1,
      "content": "内容",
      "result": "pass",
      "reason": null,
      "model": "glm-4-flash",
      "created_at": "2026-05-17T00:00:00.000Z"
    }
  ]
}
```

---

## 使用示例

### cURL

```bash
# AI 对话
curl -X POST http://YOUR_API_HOST:PORT/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "x-api-key: sk-your-api-key" \
  -d '{"message": "你好"}'

# 内容审核
curl -X POST http://YOUR_API_HOST:PORT/api/ai/audit \
  -H "Content-Type: application/json" \
  -H "x-api-key: sk-your-api-key" \
  -d '{"content": "待审核内容"}'

# 图像生成
curl -X POST http://YOUR_API_HOST:PORT/api/ai/image \
  -H "Content-Type: application/json" \
  -H "x-api-key: sk-your-api-key" \
  -d '{"prompt": "一只猫"}'
```

### Python

```python
import requests

headers = {
    'Content-Type': 'application/json',
    'x-api-key': 'sk-your-api-key'
}

# AI 对话
response = requests.post(
    'http://YOUR_API_HOST:PORT/api/ai/chat',
    headers=headers,
    json={'message': '你好'}
)
print(response.json())

# 内容审核
response = requests.post(
    'http://YOUR_API_HOST:PORT/api/ai/audit',
    headers=headers,
    json={'content': '待审核内容'}
)
result = response.json()
print(result['passed'], result.get('reason'))
```

### JavaScript

```javascript
const response = await fetch('http://YOUR_API_HOST:PORT/api/ai/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'x-api-key': 'sk-your-api-key'
  },
  body: JSON.stringify({ message: '你好' })
});
const data = await response.json();
console.log(data.response);
```

---

## 音乐解析接口

通过 `/music` 路径提供音乐链接解析、下载代理和封面代理服务。支持网易云音乐和 QQ 音乐。

**基础地址**: `http://YOUR_API_HOST:PORT/music`

**支持平台**:
- 网易云音乐: `https://music.163.com/song?id=歌曲ID` 或 `https://music.163.com/#/song?id=歌曲ID`
- QQ音乐: `https://y.qq.com/n/ryqq/songDetail/歌曲Mid`

**注意**: 音乐接口无需 API Key 认证。

---

### 1. 解析音乐链接

解析音乐链接，获取歌曲信息（名称、歌手、专辑、封面、源文件地址）。

**POST 方式**

```http
POST /music/parse
```

**请求体**
```json
{
  "url": "https://music.163.com/song?id=149294"
}
```

**GET 方式**

```http
GET /music/parse?url=https://music.163.com/song?id=149294
```

**参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| url | string | 是 | 音乐页面链接 |

**响应**
```json
{
  "success": true,
  "data": {
    "platform": "网易云音乐",
    "name": "夜的钢琴曲四",
    "artist": "石进",
    "album": "夜的钢琴曲 Demo集",
    "cover": "https://p2.music.126.net/...",
    "source_url": "http://m701.music.126.net/...",
    "song_id": "149294"
  }
}
```

---

### 2. 下载音乐文件（代理）

解析音乐链接并代理下载音频文件。

```http
GET /music/download?url=https://music.163.com/song?id=149294
```

**查询参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| url | string | 是 | 音乐页面链接 |
| filename | string | 否 | 自定义下载文件名，默认为 `歌手 - 歌曲名.mp3` |

**响应**: 音频文件流（`audio/mpeg`），以附件形式下载。

**cURL 示例**
```bash
curl "http://YOUR_API_HOST:PORT/music/download?url=https://music.163.com/song?id=149294" -o music.mp3
```

---

### 3. 下载封面图片（代理）

获取并代理下载专辑封面图片。

```http
GET /music/cover?url=https://music.163.com/song?id=149294
```

**查询参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| url | string | 条件必填 | 音乐页面链接（会解析获取封面） |
| cover_url | string | 条件必填 | 直接提供封面URL（优先使用） |

> `url` 和 `cover_url` 至少提供一个。

**响应**: 图片文件流（`image/jpeg` 等）。

**cURL 示例**
```bash
# 通过音乐链接获取封面
curl "http://YOUR_API_HOST:PORT/music/cover?url=https://music.163.com/song?id=149294" -o cover.jpg

# 直接提供封面URL
curl "http://YOUR_API_HOST:PORT/music/cover?cover_url=https://p2.music.126.net/cover.jpg" -o cover.jpg
```

---

### 4. 流式播放音乐

解析音乐链接并流式传输音频，适用于在线播放场景。

```http
GET /music/stream?url=https://music.163.com/song?id=149294
```

**查询参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| url | string | 是 | 音乐页面链接 |

**响应**: 音频流（`audio/mpeg`），支持 `Accept-Ranges: bytes`。

**使用示例**

在 HTML 中直接播放：
```html
<audio src="http://YOUR_API_HOST:PORT/music/stream?url=https://music.163.com/song?id=149294" controls></audio>
```

---

### 注意事项

1. **VIP 歌曲限制**: 部分 VIP 或付费歌曲可能无法获取播放地址
2. **链接时效性**: 音乐源文件链接可能有时效性，建议获取后尽快使用
3. **版权说明**: 请遵守相关法律法规，仅用于个人学习研究使用
4. **接口变动**: 音乐平台的 API 可能会变动，如遇到问题需要更新代码
