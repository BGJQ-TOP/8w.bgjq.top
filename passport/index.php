<?php
/**
 * 8W通行证 —— 用户中心
 *
 * 未登录：登录 / 注册
 * 已登录：账号概览、绑定管理（邮箱 / FanVerify 可选绑定）、已授权应用、修改密码
 * 管理员：第三方应用管理（API 分发）、接口接入状态
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

/**
 * 取字符串第一个字符（UTF-8 安全，不依赖 mbstring）
 */
function initial($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '?';
    }
    if (preg_match('/./us', $value, $m) === 1) {
        return h(strtoupper($m[0]));
    }
    return h(strtoupper(substr($value, 0, 1)));
}

$app = Application::instance();
$request = Request::fromGlobals();

$account = null;
$fatal = null;

try {
    $account = $app->authenticator()->current($request);
} catch (Throwable $e) {
    // 数据库不可用时也要能渲染页面并给出明确提示
    $fatal = $e->getMessage();
}

// 只允许站内相对路径，防开放重定向
$returnTo = (string) $request->query('return', '');
if ($returnTo !== '' && (strpos($returnTo, '/') !== 0 || strpos($returnTo, '//') === 0)) {
    $returnTo = '';
}

$adminRoles = array_filter(array_map('trim', explode(',', $app->config()->getString('PASSPORT_ADMIN_ROLES', 'secretary_general'))));
$isAdmin = $account !== null && in_array($account->role(), $adminRoles, true);

// 接口接入状态（管理员可见）：判定依据是各数据源的 isConfigured()
$interfaceStatus = array(
    array('label' => '游戏内玩家', 'ok' => $app->playerProvider()->isConfigured(), 'env' => 'PLAYER_API_BASE', 'required' => true),
    array('label' => '简幻通', 'ok' => $app->simpassVerifier()->isConfigured(), 'env' => 'SIMPASS_API_URL', 'required' => true),
    array('label' => 'FanVerify', 'ok' => $app->fanVerifyVerifier()->isConfigured(), 'env' => 'FANVERIFY_ACCESS_TOKEN', 'required' => false, 'action' => 'fanverify-status'),
    array('label' => '邮箱验证码', 'ok' => $app->emailVerifier()->isConfigured(), 'env' => 'EMAIL_API_URL', 'required' => false),
    array('label' => '邦国信息', 'ok' => $app->countryProvider()->isConfigured(), 'env' => 'COUNTRY_API_BASE', 'required' => false),
);

$scopes = Scope::describe();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#4A7DB5">
<title>8W通行证<?php echo $account !== null ? ' · ' . h($account->username()) : ''; ?></title>
<link rel="icon" href="/images/favicon.ico">
<link rel="stylesheet" href="/passport/assets/passport.css">
</head>
<body class="w8-passport">

<div class="w8-shell">

    <header class="w8-topbar">
        <div class="w8-topbar__inner">
            <a class="w8-brand" href="/passport/">
                <span class="w8-brand__mark">8W</span>
                <span>
                    8W通行证
                    <span class="w8-brand__sub">8W社区统一身份服务</span>
                </span>
            </a>

<?php if ($account !== null): ?>
            <div class="w8-userchip">
                <span class="w8-userchip__name"><?php echo h($account->username()); ?></span>
                <span class="w8-avatar" aria-hidden="true"><?php echo initial($account->username()); ?></span>
            </div>
<?php else: ?>
            <a class="w8-btn w8-btn--ghost w8-btn--sm" href="/">返回社区</a>
<?php endif; ?>
        </div>
    </header>

<?php if ($fatal !== null): ?>

    <main class="w8-main w8-main--narrow">
        <div class="w8-card">
            <div class="w8-card__body">
                <div class="w8-alert w8-alert--error">
                    <span class="w8-alert__icon" aria-hidden="true">!</span>
                    <div class="w8-alert__body">
                        <strong>通行证服务暂时不可用</strong><br>
                        <?php echo h($fatal); ?><br><br>
                        请确认数据库已按 <code>database/8w_passport.sql</code> 初始化，且 <code>.env</code> 配置正确。
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php elseif ($account === null): ?>

    <!-- ==================== 未登录：登录 / 注册 ==================== -->
    <main class="w8-main w8-main--narrow">
        <div class="w8-card w8-card--hero">
            <div class="w8-card__body">

                <div class="w8-tabs" role="tablist">
                    <button type="button" class="w8-tab w8-tab--active" id="tab-login"
                            role="tab" aria-selected="true" aria-controls="panel-login" data-panel="panel-login">登录</button>
                    <button type="button" class="w8-tab" id="tab-register"
                            role="tab" aria-selected="false" aria-controls="panel-register" data-panel="panel-register">注册</button>
                </div>

                <!-- ---------- 登录 ---------- -->
                <div class="w8-panel" id="panel-login" role="tabpanel" aria-labelledby="tab-login">
                    <div class="w8-alert w8-alert--error" id="login-error" role="alert" hidden></div>

                    <form id="form-login" novalidate>
                        <div class="w8-field">
                            <label class="w8-label" for="login-identifier">用户名或邮箱</label>
                            <input class="w8-input" type="text" id="login-identifier" name="identifier"
                                   autocomplete="username" autocapitalize="none" spellcheck="false"
                                   placeholder="输入用户名或已绑定的邮箱" required>
                        </div>

                        <div class="w8-field">
                            <label class="w8-label" for="login-password">密码</label>
                            <div class="w8-password">
                                <input class="w8-input" type="password" id="login-password" name="password"
                                       autocomplete="current-password" placeholder="输入密码" required>
                                <button type="button" class="w8-password__toggle" data-toggle-password="login-password"
                                        aria-label="显示密码">👁</button>
                            </div>
                        </div>

                        <button type="submit" class="w8-btn w8-btn--lg w8-btn--block">登录</button>
                    </form>

                    <p class="w8-consent__footnote">
                        登录即表示同意 8W社区的相关约定。<br>
                        忘记密码？请通过简幻通联系社区管理员。
                    </p>
                </div>

                <!-- ---------- 注册 ---------- -->
                <div class="w8-panel" id="panel-register" role="tabpanel" aria-labelledby="tab-register" hidden>
                    <div class="w8-alert w8-alert--error" id="register-error" role="alert" hidden></div>

                    <form id="form-register" novalidate>
                        <div class="w8-field">
                            <label class="w8-label" for="reg-username">通行证用户名</label>
                            <input class="w8-input" type="text" id="reg-username" name="username"
                                   autocomplete="username" autocapitalize="none" spellcheck="false"
                                   placeholder="3-32 位字母、数字、下划线或短横线" required>
                        </div>

                        <div class="w8-field">
                            <label class="w8-label" for="reg-password">密码</label>
                            <div class="w8-password">
                                <input class="w8-input" type="password" id="reg-password" name="password"
                                       autocomplete="new-password" placeholder="至少 8 位" required>
                                <button type="button" class="w8-password__toggle" data-toggle-password="reg-password"
                                        aria-label="显示密码">👁</button>
                            </div>
                            <div class="w8-meter" id="pw-meter" aria-hidden="true">
                                <span class="w8-meter__bar"></span>
                                <span class="w8-meter__bar"></span>
                                <span class="w8-meter__bar"></span>
                                <span class="w8-meter__bar"></span>
                                <span class="w8-meter__text"></span>
                            </div>
                            <span class="w8-hint">至少 8 位，不能是纯字母或纯数字。</span>
                        </div>

                        <div class="w8-field">
                            <label class="w8-label" for="reg-player">游戏内玩家名</label>
                            <input class="w8-input" type="text" id="reg-player" name="player_name"
                                   autocapitalize="none" spellcheck="false"
                                   placeholder="必须与服务器内完全一致" required>
                            <span class="w8-hint">提交时会调用权威接口实时校验，所属邦国自动识别，无需手工选择。</span>
                        </div>

                        <div class="w8-field">
                            <label class="w8-label" for="reg-simpass-uid">简幻通ID</label>
                            <input class="w8-input" type="text" id="reg-simpass-uid" name="simpass_uid"
                                   inputmode="numeric" autocomplete="off" placeholder="简幻通用户ID" required>
                        </div>

                        <div class="w8-field">
                            <label class="w8-label" for="reg-simpass-code">简幻通验证码</label>
                            <input class="w8-input" type="text" id="reg-simpass-code" name="simpass_code"
                                   inputmode="numeric" autocomplete="one-time-code" maxlength="8"
                                   placeholder="在小程序内获取" required>
                        </div>

                        <!-- ---------- 可选绑定 ---------- -->
                        <div class="w8-card__head" style="padding:0 0 12px; border-bottom:none; margin-top:24px;">
                            <div>
                                <div class="w8-card__title">可选绑定</div>
                                <div class="w8-card__sub">现在不绑也能注册，之后随时可以在通行证中心补绑或解绑。</div>
                            </div>
                        </div>

                        <div class="w8-stack w8-stack--tight w8-mb-4">
                            <label class="w8-check">
                                <input type="checkbox" id="opt-email">
                                <span class="w8-check__text">
                                    <strong>绑定验证邮箱</strong>
                                    <span id="opt-email-note">用于接收通知与找回密码</span>
                                </span>
                            </label>
                            <label class="w8-check">
                                <input type="checkbox" id="opt-fanverify">
                                <span class="w8-check__text">
                                    <strong>绑定 FanVerify 账号</strong>
                                    <span id="opt-fanverify-note">可选的身份凭据</span>
                                </span>
                            </label>
                        </div>

                        <div id="block-email" hidden>
                            <div class="w8-field">
                                <label class="w8-label" for="reg-email">验证邮箱</label>
                                <div class="w8-inputgroup">
                                    <input class="w8-input" type="email" id="reg-email" name="email"
                                           autocomplete="email" autocapitalize="none" spellcheck="false"
                                           placeholder="you@example.com">
                                    <button type="button" class="w8-btn w8-btn--ghost" id="btn-email-code">获取验证码</button>
                                </div>
                                <span class="w8-hint" id="email-code-hint">验证码将发送到该邮箱，10 分钟内有效。</span>
                            </div>
                            <div class="w8-field">
                                <label class="w8-label" for="reg-email-code">邮箱验证码</label>
                                <input class="w8-input" type="text" id="reg-email-code" name="email_code"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                       placeholder="6 位数字">
                            </div>
                        </div>

                        <div id="block-fanverify" hidden>
                            <div class="w8-field">
                                <label class="w8-label" for="reg-fanverify-uid">FanVerify 账号ID</label>
                                <input class="w8-input" type="text" id="reg-fanverify-uid" name="fanverify_uid"
                                       inputmode="numeric" autocomplete="off" placeholder="FanVerify 账号ID">
                            </div>
                            <div class="w8-field">
                                <label class="w8-label" for="reg-fanverify-code">FanVerify 验证码</label>
                                <input class="w8-input" type="text" id="reg-fanverify-code" name="fanverify_code"
                                       inputmode="numeric" autocomplete="one-time-code" maxlength="8"
                                       placeholder="在 FanVerify 内获取">
                            </div>
                        </div>

                        <button type="submit" class="w8-btn w8-btn--lg w8-btn--block w8-mt-5">注册并登录</button>
                    </form>
                </div>

            </div>
        </div>

        <div class="w8-footer">
            8W通行证 · <a href="/">返回 8W社区</a>
        </div>
    </main>

<?php else: ?>

    <!-- ==================== 已登录：用户中心 ==================== -->
    <main class="w8-main">
        <div class="w8-dashboard">

            <nav class="w8-nav" aria-label="通行证导航">
                <a class="w8-nav__item w8-nav__item--active" href="#overview">
                    <span class="w8-nav__icon" aria-hidden="true">◈</span>概览
                </a>
                <a class="w8-nav__item" href="#bindings">
                    <span class="w8-nav__icon" aria-hidden="true">⛓</span>绑定管理
                </a>
                <a class="w8-nav__item" href="#apps">
                    <span class="w8-nav__icon" aria-hidden="true">◎</span>已授权应用
                </a>
                <a class="w8-nav__item" href="#security">
                    <span class="w8-nav__icon" aria-hidden="true">⚿</span>账号安全
                </a>
<?php if ($isAdmin): ?>
                <a class="w8-nav__item" href="#clients">
                    <span class="w8-nav__icon" aria-hidden="true">⚙</span>第三方应用
                </a>
                <a class="w8-nav__item" href="#interfaces">
                    <span class="w8-nav__icon" aria-hidden="true">⇄</span>接口状态
                </a>
<?php endif; ?>
                <a class="w8-nav__item" href="/">
                    <span class="w8-nav__icon" aria-hidden="true">←</span>返回社区
                </a>
            </nav>

            <div class="w8-stack">

                <!-- ---------- 概览 ---------- -->
                <section class="w8-card" id="overview">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">账号概览</div>
                            <div class="w8-card__sub">通行证 UID <code><?php echo (int) $account->id(); ?></code></div>
                        </div>
                        <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" id="btn-logout">退出登录</button>
                    </div>
                    <div class="w8-card__body">
                        <table class="w8-kv">
                            <tr>
                                <th>用户名</th>
                                <td><?php echo h($account->username()); ?></td>
                            </tr>
                            <tr>
                                <th>游戏内玩家</th>
                                <td><?php echo h($account->playerName()); ?></td>
                            </tr>
                            <tr>
                                <th>玩家ID</th>
                                <td><?php echo $account->playerId() !== null ? (int) $account->playerId() : '<span class="w8-muted">待同步</span>'; ?></td>
                            </tr>
                            <tr>
                                <th>所属邦国</th>
                                <td id="cell-country"><?php echo $account->countryId() !== null ? (int) $account->countryId() : '<span class="w8-muted">无</span>'; ?></td>
                            </tr>
                            <tr>
                                <th>站内角色</th>
                                <td><span class="w8-badge w8-badge--info"><?php echo h($account->role()); ?></span></td>
                            </tr>
                            <tr>
                                <th>注册时间</th>
                                <td><?php echo h((string) $account->createdAt()); ?></td>
                            </tr>
                        </table>

                        <div class="w8-cluster w8-mt-5">
                            <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" id="btn-refresh-player">同步玩家数据</button>
                            <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" id="btn-refresh-country">同步邦国数据</button>
                        </div>
                    </div>
                </section>

                <!-- ---------- 绑定管理 ---------- -->
                <section class="w8-card" id="bindings">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">绑定管理</div>
                            <div class="w8-card__sub">
                                游戏内玩家名与简幻通为必填且不可解绑；邮箱与 FanVerify 为可选绑定，随时可绑可解。
                            </div>
                        </div>
                    </div>
                    <div class="w8-card__body">
                        <div class="w8-bindings" id="bindings-list">
                            <div class="w8-skeleton" style="height:64px"></div>
                            <div class="w8-skeleton" style="height:64px"></div>
                        </div>
                        <p class="w8-hint w8-mt-4">
                            绑定与解绑都会改变账号的找回途径，因此都需要输入当前密码。
                        </p>
                    </div>
                </section>

                <!-- ---------- 已授权应用 ---------- -->
                <section class="w8-card" id="apps">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">已授权的第三方应用</div>
                            <div class="w8-card__sub">第三方应用通过 8W通行证登录后会出现在这里，你可以随时撤销授权。</div>
                        </div>
                    </div>
                    <div class="w8-card__body">
                        <div id="apps-box">
                            <div class="w8-skeleton" style="height:20px"></div>
                        </div>
                    </div>
                </section>

                <!-- ---------- 账号安全 ---------- -->
                <section class="w8-card" id="security">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">修改密码</div>
                            <div class="w8-card__sub">修改后其它设备上的登录态会全部失效。</div>
                        </div>
                    </div>
                    <div class="w8-card__body">
                        <div class="w8-alert w8-alert--error" id="pwd-error" role="alert" hidden></div>

                        <form id="form-password" novalidate>
                            <div class="w8-field">
                                <label class="w8-label" for="pwd-old">当前密码</label>
                                <div class="w8-password">
                                    <input class="w8-input" type="password" id="pwd-old" name="old_password"
                                           autocomplete="current-password" required>
                                    <button type="button" class="w8-password__toggle" data-toggle-password="pwd-old"
                                            aria-label="显示密码">👁</button>
                                </div>
                            </div>
                            <div class="w8-field">
                                <label class="w8-label" for="pwd-new">新密码</label>
                                <div class="w8-password">
                                    <input class="w8-input" type="password" id="pwd-new" name="new_password"
                                           autocomplete="new-password" placeholder="至少 8 位" required>
                                    <button type="button" class="w8-password__toggle" data-toggle-password="pwd-new"
                                            aria-label="显示密码">👁</button>
                                </div>
                            </div>
                            <button type="submit" class="w8-btn">保存新密码</button>
                        </form>
                    </div>
                </section>

<?php if ($isAdmin): ?>
                <!-- ---------- 第三方应用管理 ---------- -->
                <section class="w8-card" id="clients">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">第三方应用管理</div>
                            <div class="w8-card__sub">
                                为第三方应用签发 client_id / client_secret，它们即可通过 OAuth 2.0 接入 8W通行证。
                            </div>
                        </div>
                    </div>
                    <div class="w8-card__body">

                        <div class="w8-alert w8-alert--info w8-mb-4">
                            <span class="w8-alert__icon" aria-hidden="true">i</span>
                            <div class="w8-alert__body">
                                授权地址 <code>/oauth/authorize</code>　令牌地址 <code>/oauth/token</code>　
                                用户信息 <code>/oauth/userinfo</code>
                            </div>
                        </div>

                        <div class="w8-alert w8-alert--success" id="client-secret-box" hidden></div>
                        <div class="w8-alert w8-alert--error" id="client-error" role="alert" hidden></div>

                        <div id="clients-box" class="w8-mb-4">
                            <div class="w8-skeleton" style="height:20px"></div>
                        </div>

                        <details class="w8-mt-5">
                            <summary class="w8-strong" style="cursor:pointer; padding: 8px 0;">＋ 新建应用</summary>
                            <form id="form-client" class="w8-mt-4" novalidate>
                                <div class="w8-field">
                                    <label class="w8-label" for="client-name">应用名称</label>
                                    <input class="w8-input" type="text" id="client-name" maxlength="64" required>
                                </div>
                                <div class="w8-field">
                                    <label class="w8-label" for="client-homepage">应用主页<span class="w8-label__optional">可选</span></label>
                                    <input class="w8-input" type="url" id="client-homepage" placeholder="https://example.com">
                                </div>
                                <div class="w8-field">
                                    <label class="w8-label" for="client-redirect">回调地址<span class="w8-label__optional">每行一个</span></label>
                                    <textarea class="w8-input w8-textarea" id="client-redirect" rows="2"
                                              placeholder="https://example.com/oauth/callback"></textarea>
                                </div>
                                <div class="w8-field">
                                    <label class="w8-label">允许申请的 scope</label>
                                    <div class="w8-stack w8-stack--tight">
<?php foreach ($scopes as $name => $description): ?>
                                        <label class="w8-check">
                                            <input type="checkbox" class="client-scope" value="<?php echo h($name); ?>"
                                                <?php echo $name === 'basic' ? 'checked' : ''; ?>>
                                            <span class="w8-check__text">
                                                <strong><?php echo h($name); ?></strong>
                                                <span><?php echo h($description); ?></span>
                                            </span>
                                        </label>
<?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="w8-field">
                                    <label class="w8-check">
                                        <input type="checkbox" id="client-confidential" checked>
                                        <span class="w8-check__text">
                                            <strong>机密客户端</strong>
                                            <span>有服务端、能安全保存 client_secret。纯前端应用请取消勾选并使用 PKCE。</span>
                                        </span>
                                    </label>
                                </div>
                                <button type="submit" class="w8-btn">创建应用</button>
                            </form>
                        </details>
                    </div>
                </section>

                <!-- ---------- 接口接入状态 ---------- -->
                <section class="w8-card" id="interfaces">
                    <div class="w8-card__head">
                        <div>
                            <div class="w8-card__title">接口接入状态</div>
                            <div class="w8-card__sub">
                                标为「待接入」的接口在被调用时会明确返回 501，不会静默放行未验证的身份。
                            </div>
                        </div>
                    </div>
                    <div class="w8-card__body">
                        <div class="w8-alert w8-alert--info w8-mb-4" id="fv-status-box" hidden></div>
                        <table class="w8-kv">
<?php foreach ($interfaceStatus as $row): ?>
                            <tr>
                                <th><?php echo h($row['label']); ?><?php echo $row['required'] ? '' : ' <span class="w8-badge w8-badge--off">可选</span>'; ?></th>
                                <td>
<?php if ($row['ok']): ?>
                                    <span class="w8-badge w8-badge--ok"><span class="w8-dot"></span>已接入</span>
<?php else: ?>
                                    <span class="w8-badge w8-badge--todo"><span class="w8-dot"></span>待接入</span>
                                    <span class="w8-muted">配置 <code><?php echo h($row['env']); ?></code></span>
<?php endif; ?>
<?php if (!empty($row['action']) && $row['ok']): ?>
                                    <button type="button" class="w8-btn w8-btn--ghost w8-btn--sm"
                                            id="btn-fanverify-status">自检</button>
<?php endif; ?>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </table>
                    </div>
                </section>
<?php endif; ?>

            </div>
        </div>

        <div class="w8-footer">
            8W通行证 · <a href="/">返回 8W社区</a>
        </div>
    </main>

<?php endif; ?>

</div>

<div class="w8-toasts" id="toasts" role="status" aria-live="polite"></div>

<script>
(function () {
    'use strict';

    var API = '/passport/api/v1';
    var API_OAUTH = '/passport/api/oauth';
    var RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
    var IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var PLAYER_NAME = <?php echo json_encode($account !== null ? $account->playerName() : '', JSON_UNESCAPED_UNICODE); ?>;

    function $(id) { return document.getElementById(id); }

    /* ---------------- 通用工具 ---------------- */

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

    function toast(message, kind) {
        var box = $('toasts');
        if (!box) { return; }

        var el = document.createElement('div');
        el.className = 'w8-toast' + (kind ? ' w8-toast--' + kind : '');
        el.innerHTML = esc(message);
        box.appendChild(el);

        setTimeout(function () {
            el.classList.add('w8-toast--leaving');
            setTimeout(function () { el.remove(); }, 260);
        }, 3200);
    }

    function showAlert(el, message, kind) {
        if (!el) { return; }
        // 保留 w8-mb-4：showAlert 会整体重写 className，把外层给的间距一起带上
        el.className = 'w8-alert w8-alert--' + (kind || 'error') + ' w8-mb-4';
        el.innerHTML = '<span class="w8-alert__icon" aria-hidden="true">!</span>'
            + '<div class="w8-alert__body">' + message + '</div>';
        el.hidden = false;
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function hide(el) { if (el) { el.hidden = true; } }

    function setLoading(button, loading) {
        if (!button) { return; }
        button.classList.toggle('w8-btn--loading', !!loading);
        button.disabled = !!loading;
    }

    /* ---------------- 密码显示 / 隐藏 ---------------- */
    Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-password]'), function (button) {
        button.addEventListener('click', function () {
            var input = $(button.getAttribute('data-toggle-password'));
            if (!input) { return; }
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? '👁' : '🙈';
            button.setAttribute('aria-label', showing ? '显示密码' : '隐藏密码');
        });
    });

    /* ---------------- 密码强度 ---------------- */
    function scorePassword(value) {
        if (!value) { return 0; }
        var score = 0;
        if (value.length >= 8) { score++; }
        if (value.length >= 12) { score++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
        if (/\d/.test(value)) { score++; }
        if (/[^A-Za-z0-9]/.test(value)) { score++; }
        return Math.min(4, score);
    }

    var pwInput = $('reg-password');
    var pwMeter = $('pw-meter');
    if (pwInput && pwMeter) {
        var bars = pwMeter.querySelectorAll('.w8-meter__bar');
        var label = pwMeter.querySelector('.w8-meter__text');
        var words = ['太弱', '较弱', '一般', '较强', '很强'];

        pwInput.addEventListener('input', function () {
            var score = scorePassword(pwInput.value);
            Array.prototype.forEach.call(bars, function (bar, index) {
                bar.className = 'w8-meter__bar' + (index < score ? ' w8-meter__bar--on-' + score : '');
            });
            label.textContent = pwInput.value ? words[score] : '';
        });
    }

    /* ---------------- 标签页 ---------------- */
    var tabs = document.querySelectorAll('.w8-tab');
    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            Array.prototype.forEach.call(tabs, function (other) {
                other.classList.remove('w8-tab--active');
                other.setAttribute('aria-selected', 'false');
                var panel = $(other.getAttribute('data-panel'));
                if (panel) { panel.hidden = true; }
            });
            tab.classList.add('w8-tab--active');
            tab.setAttribute('aria-selected', 'true');
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

            if (!$('login-identifier').value.trim() || !$('login-password').value) {
                showAlert($('login-error'), '请填写账号和密码');
                return;
            }

            setLoading(button, true);
            request('/login', {
                method: 'POST',
                body: { identifier: $('login-identifier').value.trim(), password: $('login-password').value }
            }).then(function (result) {
                setLoading(button, false);
                if (!result.ok) {
                    showAlert($('login-error'), errorText(result));
                    return;
                }
                window.location.href = RETURN_TO || '/passport/';
            });
        });
    }

    /* ---------------- 可选绑定开关 ---------------- */
    function wireOptionalToggle(checkboxId, blockId, noteId, message) {
        var checkbox = $(checkboxId);
        var block = $(blockId);
        if (!checkbox || !block) { return; }
        checkbox.addEventListener('change', function () {
            block.hidden = !checkbox.checked;
            if (checkbox.checked && noteId && $(noteId)) { $(noteId).textContent = message; }
        });
    }

    wireOptionalToggle('opt-email', 'block-email', 'opt-email-note', '已选择绑定，请填写邮箱并获取验证码');
    wireOptionalToggle('opt-fanverify', 'block-fanverify', 'opt-fanverify-note', '已选择绑定，请填写 FanVerify 账号ID与验证码');

    /* ---------------- 发送邮箱验证码 ---------------- */
    var emailCodeButton = $('btn-email-code');
    if (emailCodeButton) {
        emailCodeButton.addEventListener('click', function () {
            var email = $('reg-email').value.trim();
            if (!email) {
                showAlert($('register-error'), '请先填写邮箱');
                return;
            }

            setLoading(emailCodeButton, true);
            hide($('register-error'));

            request('/email-code', { method: 'POST', body: { email: email, scene: 'register' } })
                .then(function (result) {
                    if (!result.ok) {
                        setLoading(emailCodeButton, false);
                        showAlert($('register-error'), errorText(result));
                        return;
                    }

                    var left = 60;
                    emailCodeButton.textContent = left + ' 秒后重发';
                    $('email-code-hint').textContent = '验证码已发送，请查收邮件（10 分钟内有效）。';

                    var timer = setInterval(function () {
                        left -= 1;
                        emailCodeButton.textContent = left + ' 秒后重发';
                        if (left <= 0) {
                            clearInterval(timer);
                            setLoading(emailCodeButton, false);
                            emailCodeButton.textContent = '获取验证码';
                        }
                    }, 1000);
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

            var payload = {
                username: $('reg-username').value.trim(),
                password: $('reg-password').value,
                player_name: $('reg-player').value.trim(),
                simpass_uid: $('reg-simpass-uid').value.trim(),
                simpass_code: $('reg-simpass-code').value.trim()
            };

            if (!$('opt-email').checked) {
                // 没勾选就不提交邮箱字段，避免把空串当成"填了邮箱但没填验证码"
                payload.email = '';
                payload.email_code = '';
            } else {
                payload.email = $('reg-email').value.trim();
                payload.email_code = $('reg-email-code').value.trim();
            }

            if ($('opt-fanverify').checked) {
                payload.fanverify_uid = $('reg-fanverify-uid').value.trim();
                payload.fanverify_code = $('reg-fanverify-code').value.trim();
            }

            if (!payload.username || !payload.password || !payload.player_name) {
                showAlert($('register-error'), '请填写用户名、密码和游戏内玩家名');
                return;
            }
            if (payload.password.length < 8) {
                showAlert($('register-error'), '密码至少需要 8 个字符');
                return;
            }
            if ($('opt-email').checked && (!payload.email || !payload.email_code)) {
                showAlert($('register-error'), '勾选了绑定邮箱，就请填写邮箱并获取验证码');
                return;
            }
            if ($('opt-fanverify').checked && (!payload.fanverify_uid || !payload.fanverify_code)) {
                showAlert($('register-error'), '勾选了绑定 FanVerify，就请填写账号ID与验证码');
                return;
            }

            setLoading(button, true);
            request('/register', { method: 'POST', body: payload }).then(function (result) {
                setLoading(button, false);
                if (!result.ok) {
                    showAlert($('register-error'), errorText(result));
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
            var button = passwordForm.querySelector('button[type=submit]');
            hide($('pwd-error'));

            setLoading(button, true);
            request('/password', {
                method: 'POST',
                body: { old_password: $('pwd-old').value, new_password: $('pwd-new').value }
            }).then(function (result) {
                setLoading(button, false);
                if (!result.ok) {
                    showAlert($('pwd-error'), errorText(result));
                    return;
                }
                passwordForm.reset();
                toast('密码已更新，其它设备上的登录态已失效', 'success');
            });
        });
    }

    /* ---------------- 绑定管理 ---------------- */
    var BINDING_ICONS = { player: '🎮', simpass: '🔗', email: '✉️', fanverify: '🛡️' };

    function bindingRow(key, item) {
        var badge = item.bound
            ? '<span class="w8-badge w8-badge--ok"><span class="w8-dot"></span>已绑定</span>'
            : '<span class="w8-badge w8-badge--off">未绑定</span>';

        if (item.required) {
            badge += ' <span class="w8-badge w8-badge--required">必填</span>';
        }

        // FanVerify 风险标签：这是平台侧对该账号的公开标记，必须显眼
        if (item.tag) {
            badge += ' <span class="w8-badge w8-badge--todo">⚠ ' + esc(item.tag) + '</span>';
        }

        var value = item.bound && item.value !== null && item.value !== ''
            ? '<div class="w8-binding__value">' + esc(item.value) + (item.detail ? ' · ' + esc(item.detail) : '') + '</div>'
            : (item.detail ? '<div class="w8-binding__value">' + esc(item.detail) + '</div>' : '');

        var actions = '';
        if (item.bindable) {
            if (!item.available) {
                actions = '<span class="w8-badge w8-badge--todo"><span class="w8-dot"></span>接口待接入</span>';
            } else if (item.bound) {
                actions = '<button type="button" class="w8-btn w8-btn--danger-ghost w8-btn--sm"'
                    + ' data-unbind="' + esc(key) + '">解绑</button>';
            } else {
                actions = '<button type="button" class="w8-btn w8-btn--ghost w8-btn--sm"'
                    + ' data-bind="' + esc(key) + '">绑定</button>';
            }
        }

        return ''
            + '<div class="w8-binding' + (item.bound ? ' w8-binding--bound' : '') + '" data-binding="' + esc(key) + '">'
            +   '<span class="w8-binding__icon" aria-hidden="true">' + (BINDING_ICONS[key] || '•') + '</span>'
            +   '<div class="w8-binding__main">'
            +     '<div class="w8-binding__name">' + esc(item.label) + badge + '</div>'
            +     value
            +   '</div>'
            +   '<div class="w8-binding__actions">' + actions + '</div>'
            + '</div>'
            + '<div class="w8-panel" id="binding-form-' + esc(key) + '" hidden></div>';
    }

    function bindingFormHtml(key, bound) {
        var passwordField = ''
            + '<div class="w8-field">'
            +   '<label class="w8-label" for="bind-' + key + '-password">当前密码</label>'
            +   '<input class="w8-input" type="password" id="bind-' + key + '-password"'
            +   ' autocomplete="current-password" placeholder="确认是你本人操作" required>'
            + '</div>';

        if (bound) {
            return '<div class="w8-card w8-mb-4"><div class="w8-card__body">'
                + '<p class="w8-muted w8-mb-4">解绑后将不再能通过该方式找回账号。</p>'
                + passwordField
                + '<div class="w8-cluster w8-cluster--end">'
                +   '<button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" data-cancel="' + key + '">取消</button>'
                +   '<button type="button" class="w8-btn w8-btn--danger w8-btn--sm" data-confirm-unbind="' + key + '">确认解绑</button>'
                + '</div></div></div>';
        }

        if (key === 'email') {
            return '<div class="w8-card w8-mb-4"><div class="w8-card__body">'
                + '<div class="w8-field">'
                +   '<label class="w8-label" for="bind-email-value">验证邮箱</label>'
                +   '<div class="w8-inputgroup">'
                +     '<input class="w8-input" type="email" id="bind-email-value" placeholder="you@example.com">'
                +     '<button type="button" class="w8-btn w8-btn--ghost" id="bind-email-send">获取验证码</button>'
                +   '</div>'
                +   '<span class="w8-hint" id="bind-email-hint">验证码将发送到该邮箱，10 分钟内有效。</span>'
                + '</div>'
                + '<div class="w8-field">'
                +   '<label class="w8-label" for="bind-email-code">邮箱验证码</label>'
                +   '<input class="w8-input" type="text" id="bind-email-code" inputmode="numeric" maxlength="6" placeholder="6 位数字">'
                + '</div>'
                + passwordField
                + '<div class="w8-cluster w8-cluster--end">'
                +   '<button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" data-cancel="email">取消</button>'
                +   '<button type="button" class="w8-btn w8-btn--sm" data-confirm-bind="email">确认绑定</button>'
                + '</div></div></div>';
        }

        return '<div class="w8-card w8-mb-4"><div class="w8-card__body">'
            + '<div class="w8-tabs" style="margin-bottom:16px">'
            +   '<button type="button" class="w8-tab w8-tab--active" data-fv-method="scan">扫码绑定</button>'
            +   '<button type="button" class="w8-tab" data-fv-method="manual">手填绑定</button>'
            + '</div>'

            // 扫码：申请 OTP → 出二维码 → 轮询 → 自动绑定
            + '<div id="fv-panel-scan">'
            +   '<p class="w8-muted w8-mb-4">用 <strong>FanVerify 微信小程序</strong>扫描二维码并确认，即可完成绑定。</p>'
            +   '<div class="w8-alert w8-alert--error" id="fv-scan-error" role="alert" hidden></div>'
            +   '<button type="button" class="w8-btn w8-btn--block" id="fv-scan-start">生成二维码</button>'
            +   '<div class="w8-mt-4" id="fv-qr-box" hidden style="text-align:center">'
            +     '<img id="fv-qr-img" alt="FanVerify 绑定二维码"'
            +     ' style="width:100%;max-width:220px;height:auto;border:1px solid var(--w8-line);border-radius:12px;background:#fff;padding:8px">'
            +     '<p class="w8-muted w8-mt-3" id="fv-scan-status">等待扫码…</p>'
            +   '</div>'
            + '</div>'

            // 手填：账号ID + 动态验证码
            + '<div id="fv-panel-manual" hidden>'
            +   '<div class="w8-field">'
            +     '<label class="w8-label" for="bind-fanverify-uid">FanVerify 账号ID</label>'
            +     '<input class="w8-input" type="text" id="bind-fanverify-uid" inputmode="numeric" placeholder="FanVerify 账号ID">'
            +   '</div>'
            +   '<div class="w8-field">'
            +     '<label class="w8-label" for="bind-fanverify-code">动态验证码</label>'
            +     '<input class="w8-input" type="text" id="bind-fanverify-code" inputmode="numeric" maxlength="8" placeholder="小程序里显示的动态验证码">'
            +     '<span class="w8-hint">在 FanVerify 微信小程序里查看当前动态验证码。</span>'
            +   '</div>'
            + '</div>'

            + passwordField
            + '<div class="w8-cluster w8-cluster--end">'
            +   '<button type="button" class="w8-btn w8-btn--ghost w8-btn--sm" data-cancel="fanverify">取消</button>'
            +   '<button type="button" class="w8-btn w8-btn--sm" data-confirm-bind="fanverify" id="fv-confirm" hidden>确认绑定</button>'
            + '</div></div></div>';
    }

    var currentBindings = {};

    /* ---------------- FanVerify 扫码流程 ---------------- */

    var fvPollTimer = null;

    function stopFanVerifyPolling() {
        if (fvPollTimer) {
            clearInterval(fvPollTimer);
            fvPollTimer = null;
        }
    }

    function resetFanVerifyScan() {
        stopFanVerifyPolling();
        var button = $('fv-scan-start');
        if (button) { button.hidden = false; setLoading(button, false); }
        var box = $('fv-qr-box');
        if (box) { box.hidden = true; }
    }

    function wireFanVerifyForm() {
        resetFanVerifyScan();
        hide($('fv-scan-error'));

        var tabs = document.querySelectorAll('[data-fv-method]');
        var scanPanel = $('fv-panel-scan');
        var manualPanel = $('fv-panel-manual');
        var confirmButton = $('fv-confirm');

        function selectMethod(method) {
            Array.prototype.forEach.call(tabs, function (tab) {
                tab.classList.toggle('w8-tab--active', tab.getAttribute('data-fv-method') === method);
            });
            if (scanPanel) { scanPanel.hidden = method !== 'scan'; }
            if (manualPanel) { manualPanel.hidden = method !== 'manual'; }
            // 扫码走自动绑定，不需要"确认绑定"按钮
            if (confirmButton) { confirmButton.hidden = method !== 'manual'; }
            if (method !== 'scan') { resetFanVerifyScan(); }
        }

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () { selectMethod(tab.getAttribute('data-fv-method')); });
        });
        selectMethod('scan');

        var startButton = $('fv-scan-start');
        if (!startButton) { return; }

        startButton.addEventListener('click', function () {
            var password = $('bind-fanverify-password').value;
            if (!password) {
                toast('请先填写当前密码', 'error');
                return;
            }

            hide($('fv-scan-error'));
            setLoading(startButton, true);

            request('/fanverify-otp', { method: 'POST', body: { password: password } }).then(function (result) {
                if (!result.ok) {
                    setLoading(startButton, false);
                    showAlert($('fv-scan-error'), errorText(result));
                    return;
                }

                startButton.hidden = true;
                var box = $('fv-qr-box');
                box.hidden = false;
                // 二维码是一次性的，加个时间戳避免浏览器复用缓存
                $('fv-qr-img').src = result.data.qr_url + '&t=' + Date.now();
                $('fv-scan-status').textContent = '等待扫码…';

                startFanVerifyPolling(result.data.otp, result.data.expires_in || 180, password);
            });
        });
    }

    function startFanVerifyPolling(otp, ttlSeconds, password) {
        stopFanVerifyPolling();

        var deadline = Date.now() + ttlSeconds * 1000;
        // FanVerify 侧对同一 OTP 有 5 秒最小查询间隔，这里 3 秒一次，
        // 撞上限流就跳过本轮继续等
        fvPollTimer = setInterval(function () {
            if (Date.now() > deadline) {
                resetFanVerifyScan();
                $('fv-scan-status').textContent = '二维码已过期，请重新生成。';
                return;
            }

            request('/fanverify-otp?otp=' + encodeURIComponent(otp)).then(function (result) {
                if (!result.ok) {
                    resetFanVerifyScan();
                    showAlert($('fv-scan-error'), errorText(result));
                    return;
                }

                var status = result.data.status;

                if (status === 'rate_limit') {
                    $('fv-scan-status').textContent = '查询过于频繁，稍后继续…';
                    return;
                }

                if (status !== 'ok') {
                    $('fv-scan-status').textContent = '等待扫码…';
                    return;
                }

                stopFanVerifyPolling();
                $('fv-scan-status').textContent = '已确认，正在绑定…';

                request('/bindings', {
                    method: 'POST',
                    body: { type: 'fanverify', otp: otp, password: password }
                }).then(function (bindResult) {
                    if (!bindResult.ok) {
                        showAlert($('fv-scan-error'), errorText(bindResult));
                        resetFanVerifyScan();
                        return;
                    }
                    toast('FanVerify 绑定成功', 'success');
                    renderBindings(bindResult.data.bindings);
                });
            });
        }, 3000);
    }

    function renderBindings(bindings) {
        var box = $('bindings-list');
        if (!box) { return; }

        currentBindings = bindings;
        var html = '';
        ['player', 'simpass', 'email', 'fanverify'].forEach(function (key) {
            if (bindings[key]) { html += bindingRow(key, bindings[key]); }
        });
        box.innerHTML = html;
        wireBindingActions();
    }

    function openBindingPanel(key, bound) {
        // 先关掉其它面板，避免同时展开一堆
        Array.prototype.forEach.call(document.querySelectorAll('.w8-panel[id^="binding-form-"]'), function (panel) {
            panel.hidden = true;
            panel.innerHTML = '';
        });

        var panel = $('binding-form-' + key);
        if (!panel) { return; }
        panel.innerHTML = bindingFormHtml(key, bound);
        panel.hidden = false;
        wireBindingForm(key, bound);
    }

    function wireBindingForm(key, bound) {
        // FanVerify 有扫码 / 手填两条路径，单独接管
        if (key === 'fanverify' && !bound) {
            wireFanVerifyForm();
        }

        var sendButton = $('bind-email-send');
        if (sendButton) {
            sendButton.addEventListener('click', function () {
                var email = $('bind-email-value').value.trim();
                if (!email) { toast('请先填写邮箱', 'error'); return; }

                setLoading(sendButton, true);
                request('/email-code', { method: 'POST', body: { email: email, scene: 'bind' } })
                    .then(function (result) {
                        if (!result.ok) {
                            setLoading(sendButton, false);
                            toast(result.error ? result.error.message : '发送失败', 'error');
                            return;
                        }
                        var left = 60;
                        sendButton.textContent = left + ' 秒后重发';
                        $('bind-email-hint').textContent = '验证码已发送，请查收邮件。';
                        var timer = setInterval(function () {
                            left -= 1;
                            sendButton.textContent = left + ' 秒后重发';
                            if (left <= 0) {
                                clearInterval(timer);
                                setLoading(sendButton, false);
                                sendButton.textContent = '获取验证码';
                            }
                        }, 1000);
                    });
            });
        }

        var confirmBind = document.querySelector('[data-confirm-bind="' + key + '"]');
        if (confirmBind) {
            confirmBind.addEventListener('click', function () {
                var body = { type: key, password: $('bind-' + key + '-password').value };

                if (key === 'email') {
                    body.email = $('bind-email-value').value.trim();
                    body.code = $('bind-email-code').value.trim();
                } else {
                    body.uid = $('bind-fanverify-uid').value.trim();
                    body.code = $('bind-fanverify-code').value.trim();
                }

                setLoading(confirmBind, true);
                request('/bindings', { method: 'POST', body: body }).then(function (result) {
                    setLoading(confirmBind, false);
                    if (!result.ok) {
                        toast(result.error ? result.error.message : '绑定失败', 'error');
                        return;
                    }
                    toast('绑定成功', 'success');
                    renderBindings(result.data.bindings);
                });
            });
        }

        var confirmUnbind = document.querySelector('[data-confirm-unbind="' + key + '"]');
        if (confirmUnbind) {
            confirmUnbind.addEventListener('click', function () {
                // 密码走请求体，不放查询串：URL 会进访问日志、浏览器历史与 Referer
                setLoading(confirmUnbind, true);
                request('/bindings', {
                    method: 'DELETE',
                    body: { type: key, password: $('bind-' + key + '-password').value }
                }).then(function (result) {
                    setLoading(confirmUnbind, false);
                    if (!result.ok) {
                        toast(result.error ? result.error.message : '解绑失败', 'error');
                        return;
                    }
                    toast('已解绑', 'success');
                    renderBindings(result.data.bindings);
                });
            });
        }

        var cancel = document.querySelector('[data-cancel="' + key + '"]');
        if (cancel) {
            cancel.addEventListener('click', function () {
                var panel = $('binding-form-' + key);
                if (panel) { panel.hidden = true; panel.innerHTML = ''; }
            });
        }
    }

    function wireBindingActions() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-bind]'), function (button) {
            button.addEventListener('click', function () {
                openBindingPanel(button.getAttribute('data-bind'), false);
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-unbind]'), function (button) {
            button.addEventListener('click', function () {
                openBindingPanel(button.getAttribute('data-unbind'), true);
            });
        });
    }

    function loadBindings() {
        if (!$('bindings-list')) { return; }
        request('/bindings').then(function (result) {
            if (!result.ok) {
                $('bindings-list').innerHTML = '<div class="w8-empty">' + errorText(result) + '</div>';
                return;
            }
            renderBindings(result.data.bindings);
        });
    }
    loadBindings();

    /* ---------------- 已授权应用 ---------------- */
    function loadApps() {
        if (!$('apps-box')) { return; }
        request('/authorized-apps').then(function (result) {
            if (!result.ok) {
                $('apps-box').innerHTML = '<div class="w8-empty">' + errorText(result) + '</div>';
                return;
            }
            var apps = result.data.apps || [];
            if (!apps.length) {
                $('apps-box').innerHTML = '<div class="w8-empty">'
                    + '<span class="w8-empty__icon" aria-hidden="true">◎</span>'
                    + '还没有第三方应用获得授权。</div>';
                return;
            }

            $('apps-box').innerHTML = '<div class="w8-bindings">' + apps.map(function (app) {
                return '<div class="w8-binding">'
                    + '<span class="w8-binding__icon" aria-hidden="true">◎</span>'
                    + '<div class="w8-binding__main">'
                    +   '<div class="w8-binding__name">' + esc(app.name) + '</div>'
                    +   '<div class="w8-binding__value">scope：' + esc((app.scopes || []).join(' ')) + '</div>'
                    +   '<div class="w8-binding__value">授权时间：' + esc(app.authorized_at) + '</div>'
                    + '</div>'
                    + '<div class="w8-binding__actions">'
                    +   '<button type="button" class="w8-btn w8-btn--danger-ghost w8-btn--sm"'
                    +   ' data-revoke="' + esc(app.client_id) + '">撤销授权</button>'
                    + '</div></div>';
            }).join('') + '</div>';

            Array.prototype.forEach.call($('apps-box').querySelectorAll('[data-revoke]'), function (button) {
                button.addEventListener('click', function () {
                    var clientId = button.getAttribute('data-revoke');
                    setLoading(button, true);
                    request('/authorized-apps?client_id=' + encodeURIComponent(clientId), { method: 'DELETE' })
                        .then(function () {
                            toast('已撤销该应用的授权', 'success');
                            loadApps();
                        });
                });
            });
        });
    }
    loadApps();

    /* ---------------- 玩家 / 邦国同步 ---------------- */
    function renderCountry(country) {
        var cell = $('cell-country');
        if (cell) {
            cell.textContent = country && country.name ? country.name + '（' + country.id + '）' : '无';
        }
        if (country) {
            toast('邦国数据已同步', 'success');
        }
    }

    var refreshCountry = $('btn-refresh-country');
    if (refreshCountry) {
        refreshCountry.addEventListener('click', function () {
            setLoading(refreshCountry, true);
            request('/me').then(function (result) {
                if (result.ok && result.data.account && result.data.account.country_id) {
                    return request('/country?fresh=1&id=' + encodeURIComponent(result.data.account.country_id));
                }
                return { ok: false, error: { message: '当前通行证未关联邦国' } };
            }).then(function (result) {
                setLoading(refreshCountry, false);
                if (!result.ok) {
                    toast(result.error ? result.error.message : '同步失败', 'error');
                    return;
                }
                renderCountry(result.data.country);
            });
        });
    }

    var refreshPlayer = $('btn-refresh-player');
    if (refreshPlayer) {
        refreshPlayer.addEventListener('click', function () {
            setLoading(refreshPlayer, true);
            request('/player?fresh=1&name=' + encodeURIComponent(PLAYER_NAME)).then(function (result) {
                setLoading(refreshPlayer, false);
                if (!result.ok) {
                    toast(result.error ? result.error.message : '同步失败', 'error');
                    return;
                }
                if (result.data.country) { renderCountry(result.data.country); }
                toast('玩家数据已同步', 'success');
            });
        });
    }

    /* ---------------- 管理员：FanVerify 自检 ---------------- */
    var fvStatusButton = $('btn-fanverify-status');
    if (fvStatusButton) {
        fvStatusButton.addEventListener('click', function () {
            var box = $('fv-status-box');
            setLoading(fvStatusButton, true);

            request('/fanverify-status').then(function (result) {
                setLoading(fvStatusButton, false);
                if (!result.ok) {
                    showAlert(box, errorText(result), 'error');
                    return;
                }

                var data = result.data;
                if (!data.ok) {
                    showAlert(box, '<strong>FanVerify 不可用</strong><br>' + esc(data.error || '未知错误'), 'error');
                    return;
                }

                var dev = data.developer || {};
                showAlert(box,
                    '<strong>FanVerify 连接正常</strong><br>'
                    + '绑定账号 UID：' + esc(dev.bind_uid === null ? '—' : dev.bind_uid)
                    + '　模式：' + esc(dev.mode || '—')
                    + '　要求等级：' + esc(dev.need_end_level === null ? '—' : dev.need_end_level) + '<br>'
                    + '签发时间：' + esc(dev.issued_at || '—')
                    + (dev.service_message ? '<br>服务公告：' + esc(dev.service_message) : ''),
                    'success');
            });
        });
    }

    /* ---------------- 管理员：第三方应用 ---------------- */
    function loadClients() {
        if (!IS_ADMIN || !$('clients-box')) { return; }
        request('/clients', null, API_OAUTH).then(function (result) {
            if (!result.ok) {
                $('clients-box').innerHTML = '<div class="w8-empty">' + errorText(result) + '</div>';
                return;
            }
            var clients = result.data.clients || [];
            if (!clients.length) {
                $('clients-box').innerHTML = '<div class="w8-empty">还没有登记任何第三方应用。</div>';
                return;
            }

            $('clients-box').innerHTML = '<div class="w8-bindings">' + clients.map(function (client) {
                var statusBadge = client.status === 1
                    ? '<span class="w8-badge w8-badge--ok"><span class="w8-dot"></span>启用</span>'
                    : '<span class="w8-badge w8-badge--off">已停用</span>';

                return '<div class="w8-binding">'
                    + '<span class="w8-binding__icon" aria-hidden="true">⚙</span>'
                    + '<div class="w8-binding__main">'
                    +   '<div class="w8-binding__name">' + esc(client.name) + statusBadge + '</div>'
                    +   '<div class="w8-binding__value w8-mono">' + esc(client.client_id) + '</div>'
                    +   '<div class="w8-binding__value">scope：' + esc((client.allowed_scopes || []).join(' '))
                    +     '　限流：' + esc(client.rate_limit) + '/分钟</div>'
                    +   '<div class="w8-binding__value">回调：' + esc((client.redirect_uris || []).join(' , ') || '（无）') + '</div>'
                    + '</div>'
                    + '<div class="w8-binding__actions">'
                    + (client.status === 1
                        ? '<button type="button" class="w8-btn w8-btn--danger-ghost w8-btn--sm"'
                          + ' data-disable="' + esc(client.id) + '">停用</button>'
                        : '')
                    + '</div></div>';
            }).join('') + '</div>';

            Array.prototype.forEach.call($('clients-box').querySelectorAll('[data-disable]'), function (button) {
                button.addEventListener('click', function () {
                    if (!window.confirm('停用后该应用的所有令牌立即失效，确认继续？')) { return; }
                    setLoading(button, true);
                    fetch(API_OAUTH + '/clients?id=' + encodeURIComponent(button.getAttribute('data-disable')), {
                        method: 'DELETE', credentials: 'same-origin'
                    }).then(function () {
                        toast('应用已停用', 'success');
                        loadClients();
                    });
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
                    showAlert($('client-error'), errorText(result));
                    return;
                }

                var box = $('client-secret-box');
                box.className = 'w8-alert w8-alert--warn w8-mb-4';
                box.innerHTML = '<span class="w8-alert__icon" aria-hidden="true">!</span>'
                    + '<div class="w8-alert__body">'
                    + '应用创建成功，请立即保存以下凭据（关闭后无法再次查看）：<br><br>'
                    + 'client_id：<code>' + esc(result.data.client_id) + '</code><br>'
                    + (result.data.client_secret
                        ? 'client_secret：<code>' + esc(result.data.client_secret) + '</code>'
                        : '（公开客户端，无 client_secret，请使用 PKCE）')
                    + '</div>';
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
