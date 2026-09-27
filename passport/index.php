<?php
/**
 * 8W通行证 —— 用户中心
 *
 * 未登录：登录 / 注册
 * 已登录：账号信息、绑定的游戏内玩家、所属邦国、已授权应用、改密码
 * 管理员：第三方应用管理（API 分发）
 *
 * 所有数据操作都走 /passport/api/v1/*，本文件只负责渲染与交互。
 */

require_once __DIR__ . '/src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\Request;
use W8\Passport\OAuth\Scope;

/**
 * HTML 转义
 */
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$app = Application::instance();
$request = Request::fromGlobals();

$account = null;
try {
    $account = $app->authenticator()->current($request);
} catch (Throwable $e) {
    // 数据库不可用时也要能渲染出页面并给出明确提示
    $fatal = $e->getMessage();
}

$returnTo = (string) $request->query('return', '');
if ($returnTo !== '' && (strpos($returnTo, '/') !== 0 || strpos($returnTo, '//') === 0)) {
    // 只允许站内相对路径，防开放重定向
    $returnTo = '';
}

$adminRoles = array_filter(array_map('trim', explode(',', $app->config()->getString('PASSPORT_ADMIN_ROLES', 'secretary_general'))));
$isAdmin = $account !== null && in_array($account->role(), $adminRoles, true);

$interfaceStatus = array(
    array('邮箱验证码接口', $app->emailVerifier()->isConfigured(), 'EMAIL_API_URL'),
    array('游戏内玩家接口', $app->playerProvider()->isConfigured(), 'PLAYER_API_BASE'),
    array('邦国信息接口', $app->countryProvider()->isConfigured(), 'COUNTRY_API_BASE'),
    array('简幻通接口', $app->simpassVerifier()->isConfigured(), 'SIMPASS_API_URL'),
);

$scopes = Scope::describe();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>8W通行证</title>
<link rel="stylesheet" href="/passport/assets/passport.css">
</head>
<body class="w8-passport">
<div class="w8-wrap<?php echo $account === null ? ' w8-wrap--narrow' : ''; ?>">

    <header class="w8-header">
        <a class="w8-brand" href="/passport/">
            <span class="w8-brand__mark">8W</span>
            <span>8W通行证</span>
        </a>
        <p class="w8-tagline">8W社区统一身份服务 · 一次注册，全站通用</p>
    </header>

<?php if (isset($fatal)): ?>
    <div class="w8-card">
        <div class="w8-alert w8-alert--error">
            通行证服务暂时不可用：<?php echo h($fatal); ?><br>
            请确认数据库已按 <code>database/8w_passport.sql</code> 初始化，且 .env 配置正确。
        </div>
    </div>
<?php elseif ($account === null): ?>

    <div class="w8-card">
        <div class="w8-tabs">
            <div class="w8-tab w8-tab--active" id="tab-login" data-panel="panel-login">登录</div>
            <div class="w8-tab" id="tab-register" data-panel="panel-register">注册</div>
        </div>

        <!-- ================= 登录 ================= -->
        <div id="panel-login">
            <div class="w8-alert w8-alert--error" id="login-error" hidden></div>
            <form id="form-login" autocomplete="on">
                <div class="w8-field">
                    <label for="login-identifier">用户名或邮箱</label>
                    <input class="w8-input" type="text" id="login-identifier" name="identifier"
                           autocomplete="username" required>
                </div>
                <div class="w8-field">
                    <label for="login-password">密码</label>
                    <input class="w8-input" type="password" id="login-password" name="password"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="w8-btn w8-btn--block">登录</button>
            </form>
        </div>

        <!-- ================= 注册 ================= -->
        <div id="panel-register" hidden>
            <p class="w8-card__sub">
                注册需要同时验证：邮箱、游戏内玩家名、简幻通身份。
                玩家名会通过权威接口实时校验，请填写服务器内的准确玩家名。
            </p>

            <div class="w8-alert w8-alert--error" id="register-error" hidden></div>
            <div class="w8-alert w8-alert--success" id="register-ok" hidden></div>

            <form id="form-register" autocomplete="off">
                <div class="w8-field">
                    <label for="reg-username">通行证用户名</label>
                    <input class="w8-input" type="text" id="reg-username" name="username"
                           placeholder="3-32 位字母、数字、下划线或短横线" required>
                </div>

                <div class="w8-field">
                    <label for="reg-password">密码</label>
                    <input class="w8-input" type="password" id="reg-password" name="password"
                           placeholder="至少 8 位，不能是纯字母或纯数字" required>
                </div>

                <div class="w8-field">
                    <label for="reg-email">验证邮箱</label>
                    <div class="w8-row">
                        <input class="w8-input" type="email" id="reg-email" name="email"
                               placeholder="you@example.com" required>
                        <button type="button" class="w8-btn w8-btn--ghost" id="btn-email-code">获取验证码</button>
                    </div>
                    <span class="w8-hint" id="email-code-hint">验证码将发送到该邮箱，10 分钟内有效。</span>
                </div>

                <div class="w8-field">
                    <label for="reg-email-code">邮箱验证码</label>
                    <input class="w8-input" type="text" id="reg-email-code" name="email_code"
                           inputmode="numeric" maxlength="6" placeholder="6 位数字" required>
                </div>

                <div class="w8-field">
                    <label for="reg-player">游戏内玩家名</label>
                    <input class="w8-input" type="text" id="reg-player" name="player_name"
                           placeholder="必须与服务器内完全一致" required>
                    <span class="w8-hint">提交时会调用权威接口校验，所属邦国自动识别，无需手工选择。</span>
                </div>

                <div class="w8-field">
                    <label for="reg-simpass-uid">简幻通ID</label>
                    <input class="w8-input" type="text" id="reg-simpass-uid" name="simpass_uid"
                           inputmode="numeric" placeholder="简幻通用户ID" required>
                </div>

                <div class="w8-field">
                    <label for="reg-simpass-code">简幻通验证码</label>
                    <input class="w8-input" type="text" id="reg-simpass-code" name="simpass_code"
                           inputmode="numeric" placeholder="在小程序内获取" required>
                </div>

                <button type="submit" class="w8-btn w8-btn--block">注册并登录</button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="w8-card">
        <h1 class="w8-card__title">你好，<?php echo h($account->username()); ?></h1>
        <p class="w8-card__sub">通行证 UID <code class="w8-mono"><?php echo (int) $account->id(); ?></code></p>

        <table class="w8-kv">
            <tr><th>邮箱</th>
                <td><?php echo h($account->email()); ?>
                    <?php if ($account->isEmailVerified()): ?>
                        <span class="w8-muted">（已验证）</span>
                    <?php else: ?>
                        <span class="w8-muted">（未验证）</span>
                    <?php endif; ?>
                </td></tr>
            <tr><th>游戏内玩家名</th><td><?php echo h($account->playerName()); ?></td></tr>
            <tr><th>玩家ID</th><td><?php echo $account->playerId() !== null ? (int) $account->playerId() : '<span class="w8-muted">待同步</span>'; ?></td></tr>
            <tr><th>所属邦国ID</th><td id="cell-country"><?php echo $account->countryId() !== null ? (int) $account->countryId() : '<span class="w8-muted">无</span>'; ?></td></tr>
            <tr><th>简幻通ID</th><td><?php echo $account->simpassUid() !== null ? (int) $account->simpassUid() : '<span class="w8-muted">未绑定</span>'; ?></td></tr>
            <tr><th>站内角色</th><td><?php echo h($account->role()); ?></td></tr>
            <tr><th>注册时间</th><td><?php echo h((string) $account->createdAt()); ?></td></tr>
        </table>

        <div class="w8-actions">
            <a class="w8-btn w8-btn--ghost" href="/">返回社区首页</a>
            <button type="button" class="w8-btn w8-btn--ghost" id="btn-logout">退出登录</button>
        </div>
    </div>

    <!-- ============ 邦国信息（权威缓存） ============ -->
    <div class="w8-card">
        <h2 class="w8-card__title">我的邦国</h2>
        <p class="w8-card__sub">以下数据来自权威接口的本地缓存，点击可强制同步一次。</p>
        <div id="country-box">
            <p class="w8-muted">加载中…</p>
        </div>
        <div class="w8-actions">
            <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" id="btn-refresh-country">强制同步邦国数据</button>
            <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" id="btn-refresh-player">强制同步玩家数据</button>
        </div>
    </div>

    <!-- ============ 已授权应用 ============ -->
    <div class="w8-card">
        <h2 class="w8-card__title">已授权的第三方应用</h2>
        <p class="w8-card__sub">第三方应用通过 8W通行证登录后，会出现在这里。你可以随时撤销授权。</p>
        <div id="apps-box"><p class="w8-muted">加载中…</p></div>
    </div>

    <!-- ============ 修改密码 ============ -->
    <div class="w8-card">
        <h2 class="w8-card__title">修改密码</h2>
        <p class="w8-card__sub">修改后其它设备上的登录态会全部失效。</p>
        <div class="w8-alert w8-alert--error" id="pwd-error" hidden></div>
        <div class="w8-alert w8-alert--success" id="pwd-ok" hidden></div>
        <form id="form-password">
            <div class="w8-field">
                <label for="pwd-old">当前密码</label>
                <input class="w8-input" type="password" id="pwd-old" name="old_password" required>
            </div>
            <div class="w8-field">
                <label for="pwd-new">新密码</label>
                <input class="w8-input" type="password" id="pwd-new" name="new_password" required>
            </div>
            <button type="submit" class="w8-btn">保存新密码</button>
        </form>
    </div>

<?php if ($isAdmin): ?>
    <!-- ============ 第三方应用管理（API 分发） ============ -->
    <div class="w8-card">
        <h2 class="w8-card__title">第三方应用管理</h2>
        <p class="w8-card__sub">
            为第三方应用签发 client_id / client_secret，它们即可通过 OAuth 2.0 接入 8W通行证。
        </p>

        <div class="w8-alert w8-alert--info">
            授权地址 <code>/oauth/authorize</code>　令牌地址 <code>/oauth/token</code>　
            用户信息 <code>/oauth/userinfo</code>
        </div>

        <div class="w8-alert w8-alert--warn" id="client-secret-box" hidden></div>
        <div class="w8-alert w8-alert--error" id="client-error" hidden></div>

        <div id="clients-box"><p class="w8-muted">加载中…</p></div>

        <h3 class="w8-mt">新建应用</h3>
        <form id="form-client">
            <div class="w8-field">
                <label for="client-name">应用名称</label>
                <input class="w8-input" type="text" id="client-name" required maxlength="64">
            </div>
            <div class="w8-field">
                <label for="client-homepage">应用主页</label>
                <input class="w8-input" type="url" id="client-homepage" placeholder="https://example.com">
            </div>
            <div class="w8-field">
                <label for="client-redirect">回调地址（每行一个）</label>
                <textarea class="w8-input" id="client-redirect" rows="2"
                          placeholder="https://example.com/oauth/callback"></textarea>
            </div>
            <div class="w8-field">
                <label>允许申请的 scope</label>
                <?php foreach ($scopes as $name => $description): ?>
                    <label class="w8-muted" style="font-weight:400">
                        <input type="checkbox" class="client-scope" value="<?php echo h($name); ?>"
                            <?php echo $name === 'basic' ? 'checked' : ''; ?>>
                        <code><?php echo h($name); ?></code> — <?php echo h($description); ?>
                    </label><br>
                <?php endforeach; ?>
            </div>
            <div class="w8-field">
                <label class="w8-muted" style="font-weight:400">
                    <input type="checkbox" id="client-confidential" checked>
                    机密客户端（有服务端，可安全保存 client_secret；纯前端应用请取消勾选并使用 PKCE）
                </label>
            </div>
            <button type="submit" class="w8-btn">创建应用</button>
        </form>
    </div>

    <!-- ============ 接口接入状态 ============ -->
    <div class="w8-card">
        <h2 class="w8-card__title">接口接入状态</h2>
        <p class="w8-card__sub">标为「待接入」的接口会在被调用时明确报错，不会静默放行。</p>
        <table class="w8-kv">
            <?php foreach ($interfaceStatus as $row): ?>
                <tr>
                    <th><?php echo h($row[0]); ?></th>
                    <td>
                        <?php if ($row[1]): ?>
                            <span style="color:var(--w8-success)">已接入</span>
                        <?php else: ?>
                            <span style="color:var(--w8-danger)">待接入（TODO）</span>
                            <span class="w8-muted">— 配置 <code><?php echo h($row[2]); ?></code></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<?php endif; ?>

    <div class="w8-footer">
        8W通行证 · <a href="/">返回 8W社区</a>
    </div>
</div>

<script>
(function () {
    'use strict';

    var API = '/passport/api/v1';
    var API_OAUTH = '/passport/api/oauth';
    var RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
    var IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

    function $(id) { return document.getElementById(id); }

    function show(el, message, kind) {
        if (!el) { return; }
        el.className = 'w8-alert w8-alert--' + (kind || 'error');
        el.innerHTML = message;
        el.hidden = false;
    }

    function hide(el) { if (el) { el.hidden = true; } }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function request(path, options, base) {
        options = options || {};
        return fetch((base || API) + path, {
            method: options.method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: { message: '服务器返回了无法解析的内容（HTTP ' + response.status + '）' } };
            });
        });
    }

    function errorText(result) {
        if (result && result.error) {
            return esc(result.error.message || result.error.code || '请求失败');
        }
        return '请求失败';
    }

    /* ---------------- 标签切换 ---------------- */
    var tabs = document.querySelectorAll('.w8-tab');
    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            Array.prototype.forEach.call(tabs, function (other) {
                other.classList.remove('w8-tab--active');
                var panel = $(other.getAttribute('data-panel'));
                if (panel) { panel.hidden = true; }
            });
            tab.classList.add('w8-tab--active');
            var target = $(tab.getAttribute('data-panel'));
            if (target) { target.hidden = false; }
        });
    });

    /* ---------------- 登录 ---------------- */
    var loginForm = $('form-login');
    if (loginForm) {
        loginForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = loginForm.querySelector('button[type=submit]');
            hide($('login-error'));
            button.disabled = true;

            request('/login', {
                method: 'POST',
                body: {
                    identifier: $('login-identifier').value,
                    password: $('login-password').value
                }
            }).then(function (result) {
                button.disabled = false;
                if (!result.ok) {
                    show($('login-error'), errorText(result));
                    return;
                }
                window.location.href = RETURN_TO || '/passport/';
            });
        });
    }

    /* ---------------- 发送邮箱验证码 ---------------- */
    var emailCodeButton = $('btn-email-code');
    if (emailCodeButton) {
        emailCodeButton.addEventListener('click', function () {
            var email = $('reg-email').value.trim();
            if (!email) {
                show($('register-error'), '请先填写邮箱');
                return;
            }

            emailCodeButton.disabled = true;
            hide($('register-error'));

            request('/email-code', { method: 'POST', body: { email: email, scene: 'register' } })
                .then(function (result) {
                    if (!result.ok) {
                        emailCodeButton.disabled = false;
                        show($('register-error'), errorText(result));
                        return;
                    }

                    var left = 60;
                    var timer = setInterval(function () {
                        left -= 1;
                        emailCodeButton.textContent = left + ' 秒后重发';
                        if (left <= 0) {
                            clearInterval(timer);
                            emailCodeButton.disabled = false;
                            emailCodeButton.textContent = '获取验证码';
                        }
                    }, 1000);
                    emailCodeButton.textContent = '60 秒后重发';
                    $('email-code-hint').textContent = '验证码已发送，请查收邮件（10 分钟内有效）。';
                });
        });
    }

    /* ---------------- 注册 ---------------- */
    var registerForm = $('form-register');
    if (registerForm) {
        registerForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = registerForm.querySelector('button[type=submit]');
            hide($('register-error'));
            button.disabled = true;
            button.textContent = '校验中，请稍候…';

            request('/register', {
                method: 'POST',
                body: {
                    username: $('reg-username').value.trim(),
                    password: $('reg-password').value,
                    email: $('reg-email').value.trim(),
                    email_code: $('reg-email-code').value.trim(),
                    player_name: $('reg-player').value.trim(),
                    simpass_uid: $('reg-simpass-uid').value.trim(),
                    simpass_code: $('reg-simpass-code').value.trim()
                }
            }).then(function (result) {
                button.disabled = false;
                button.textContent = '注册并登录';

                if (!result.ok) {
                    show($('register-error'), errorText(result));
                    return;
                }
                window.location.href = RETURN_TO || '/passport/';
            });
        });
    }

    /* ---------------- 退出 ---------------- */
    var logoutButton = $('btn-logout');
    if (logoutButton) {
        logoutButton.addEventListener('click', function () {
            request('/logout', { method: 'POST' }).then(function () {
                window.location.href = '/passport/';
            });
        });
    }

    /* ---------------- 修改密码 ---------------- */
    var passwordForm = $('form-password');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            hide($('pwd-error'));
            hide($('pwd-ok'));

            request('/password', {
                method: 'POST',
                body: { old_password: $('pwd-old').value, new_password: $('pwd-new').value }
            }).then(function (result) {
                if (!result.ok) {
                    show($('pwd-error'), errorText(result));
                    return;
                }
                passwordForm.reset();
                show($('pwd-ok'), '密码已更新', 'success');
            });
        });
    }

    /* ---------------- 邦国 / 玩家信息 ---------------- */
    function renderCountry(country) {
        if (!country) {
            $('country-box').innerHTML = '<p class="w8-muted">尚未同步到邦国信息。</p>';
            return;
        }
        var players = (country.players || []).map(function (player) {
            return esc(player.player_name) + (player.player_id ? ' <span class="w8-muted">#' + esc(player.player_id) + '</span>' : '');
        }).join('、');

        $('country-box').innerHTML =
            '<table class="w8-kv">' +
            '<tr><th>邦国ID</th><td>' + esc(country.id) + '</td></tr>' +
            '<tr><th>邦国名称</th><td>' + esc(country.name) + '</td></tr>' +
            '<tr><th>邦国宣言</th><td>' + (country.declaration ? esc(country.declaration) : '<span class="w8-muted">无</span>') + '</td></tr>' +
            '<tr><th>领土大小</th><td>' + (country.territory_chunks === null ? '<span class="w8-muted">未知</span>' : esc(country.territory_chunks) + ' Chunk') + '</td></tr>' +
            '<tr><th>邦国玩家列表</th><td>' + (players || '<span class="w8-muted">暂无</span>') + '</td></tr>' +
            '</table>';
    }

    function loadCountry(fresh) {
        var url = '/country?fresh=' + (fresh ? '1' : '0');
        request(url).then(function (result) {
            if (!result.ok) {
                $('country-box').innerHTML = '<p class="w8-muted">' + errorText(result) + '</p>';
                return;
            }
            renderCountry(result.data.country);
        });
    }

    if ($('country-box')) {
        // me 接口里已经带了本地缓存的邦国信息，先渲染，再按需回源
        request('/me').then(function (result) {
            if (result.ok && result.data.country) {
                renderCountry(result.data.country);
            } else if (result.ok) {
                $('country-box').innerHTML = '<p class="w8-muted">尚未同步到邦国信息。</p>';
            }
        });
    }

    var refreshCountry = $('btn-refresh-country');
    if (refreshCountry) {
        refreshCountry.addEventListener('click', function () {
            $('country-box').innerHTML = '<p class="w8-muted">同步中…</p>';
            loadCountry(true);
        });
    }

    var refreshPlayer = $('btn-refresh-player');
    if (refreshPlayer) {
        refreshPlayer.addEventListener('click', function () {
            var name = <?php echo json_encode($account !== null ? $account->playerName() : '', JSON_UNESCAPED_UNICODE); ?>;
            request('/player?fresh=1&name=' + encodeURIComponent(name)).then(function (result) {
                if (!result.ok) {
                    show($('pwd-error'), '玩家同步失败：' + errorText(result));
                    return;
                }
                if (result.data.country) {
                    renderCountry(result.data.country);
                }
                window.location.reload();
            });
        });
    }

    /* ---------------- 已授权应用 ---------------- */
    function loadApps() {
        if (!$('apps-box')) { return; }
        request('/authorized-apps').then(function (result) {
            if (!result.ok) {
                $('apps-box').innerHTML = '<p class="w8-muted">' + errorText(result) + '</p>';
                return;
            }
            var apps = result.data.apps || [];
            if (!apps.length) {
                $('apps-box').innerHTML = '<p class="w8-muted">还没有第三方应用获得授权。</p>';
                return;
            }
            $('apps-box').innerHTML = '<table class="w8-kv">' + apps.map(function (app) {
                return '<tr><th>' + esc(app.name) + '</th><td>' +
                    '<div class="w8-muted">scope：' + esc((app.scopes || []).join(' ')) + '</div>' +
                    '<div class="w8-muted">授权时间：' + esc(app.authorized_at) + '</div>' +
                    '<button class="w8-btn w8-btn--ghost w8-btn--sm" data-client="' + esc(app.client_id) + '">撤销授权</button>' +
                    '</td></tr>';
            }).join('') + '</table>';

            Array.prototype.forEach.call($('apps-box').querySelectorAll('button[data-client]'), function (button) {
                button.addEventListener('click', function () {
                    var clientId = button.getAttribute('data-client');
                    fetch(API + '/authorized-apps?client_id=' + encodeURIComponent(clientId), {
                        method: 'DELETE', credentials: 'same-origin'
                    }).then(loadApps);
                });
            });
        });
    }
    loadApps();

    /* ---------------- 第三方应用管理 ---------------- */
    function loadClients() {
        if (!IS_ADMIN || !$('clients-box')) { return; }
        request('/clients', null, API_OAUTH).then(function (result) {
            if (!result.ok) {
                $('clients-box').innerHTML = '<p class="w8-muted">' + errorText(result) + '</p>';
                return;
            }
            var clients = result.data.clients || [];
            if (!clients.length) {
                $('clients-box').innerHTML = '<p class="w8-muted">还没有登记任何第三方应用。</p>';
                return;
            }
            $('clients-box').innerHTML = '<table class="w8-kv">' + clients.map(function (client) {
                return '<tr><th>' + esc(client.name) + '</th><td>' +
                    '<div class="w8-mono" style="font-size:12.5px">' + esc(client.client_id) + '</div>' +
                    '<div class="w8-muted">scope：' + esc((client.allowed_scopes || []).join(' ')) + '</div>' +
                    '<div class="w8-muted">回调：' + esc((client.redirect_uris || []).join(' , ') || '（无）') + '</div>' +
                    '<div class="w8-muted">状态：' + (client.status === 1 ? '启用' : '已停用') +
                    '　限流：' + esc(client.rate_limit) + '/分钟</div>' +
                    (client.status === 1
                        ? '<button class="w8-btn w8-btn--danger w8-btn--sm" data-id="' + esc(client.id) + '">停用并吊销令牌</button>'
                        : '') +
                    '</td></tr>';
            }).join('') + '</table>';

            Array.prototype.forEach.call($('clients-box').querySelectorAll('button[data-id]'), function (button) {
                button.addEventListener('click', function () {
                    if (!window.confirm('停用后该应用的所有令牌立即失效，确认继续？')) { return; }
                    fetch(API_OAUTH + '/clients?id=' + encodeURIComponent(button.getAttribute('data-id')), {
                        method: 'DELETE', credentials: 'same-origin'
                    }).then(loadClients);
                });
            });
        });
    }
    loadClients();

    var clientForm = $('form-client');
    if (clientForm) {
        clientForm.addEventListener('submit', function (event) {
            event.preventDefault();
            hide($('client-error'));

            var scopes = Array.prototype.filter.call(
                document.querySelectorAll('.client-scope'),
                function (box) { return box.checked; }
            ).map(function (box) { return box.value; });

            request('/clients', {
                method: 'POST',
                body: {
                    name: $('client-name').value.trim(),
                    homepage_url: $('client-homepage').value.trim(),
                    redirect_uris: $('client-redirect').value,
                    allowed_scopes: scopes.join(' '),
                    is_confidential: $('client-confidential').checked
                }
            }, API_OAUTH).then(function (result) {
                if (!result.ok) {
                    show($('client-error'), errorText(result));
                    return;
                }

                var box = $('client-secret-box');
                box.innerHTML =
                    '应用创建成功，请立即保存以下凭据（关闭后无法再次查看）：<br><br>' +
                    'client_id：<code>' + esc(result.data.client_id) + '</code><br>' +
                    (result.data.client_secret
                        ? 'client_secret：<code>' + esc(result.data.client_secret) + '</code>'
                        : '（公开客户端，无 client_secret，请使用 PKCE）');
                box.hidden = false;

                clientForm.reset();
                loadClients();
            });
        });
    }
})();
</script>
</body>
</html>
