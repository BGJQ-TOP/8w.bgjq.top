<?php
/**
 * GET|POST /oauth/authorize
 *
 * OAuth 2.0 授权端点（授权码模式 + PKCE）。
 *
 *   GET  ：校验参数 → 未登录则跳通行证登录 → 渲染授权确认页
 *   POST ：用户点了"同意"或"拒绝" → 回调第三方应用
 *
 * 安全要点：client_id 或 redirect_uri 校验失败时**绝不重定向**，
 * 只渲染错误页，避免把用户送去攻击者控制的地址。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\OAuth\Scope;

$app = Application::instance();
$request = Request::fromGlobals();

try {
    $context = $app->oauth()->resolveAuthorizeContext($request);
} catch (ApiException $e) {
    renderErrorPage($e->getMessage(), $e->httpStatus());
    exit;
}

/** @var array<string,mixed> $client */
$client = $context['client'];
$redirectUri = $context['redirect_uri'];

$params = $app->oauth()->validateAuthorizeParams($request, $client);
if (empty($params['ok'])) {
    header('Location: ' . $app->oauth()->errorRedirect(
        $redirectUri,
        $params['error'],
        $params['error_description'],
        $request->string('state')
    ));
    exit;
}

// 未登录 → 先去通行证登录，登录后回到本页
$account = $app->authenticator()->current($request);
if ($account === null) {
    $returnTo = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/oauth/authorize';
    header('Location: /passport/?return=' . rawurlencode($returnTo));
    exit;
}

// 用户做出决定
if ($request->isPost()) {
    $decision = $request->string('decision');

    if ($decision !== 'allow') {
        header('Location: ' . $app->oauth()->denyAuthorization($redirectUri, $params['state']));
        exit;
    }

    header('Location: ' . $app->oauth()->grantAuthorization($params, $account, $redirectUri));
    exit;
}

renderConsentPage($client, $params, $account, $redirectUri);
exit;


// ============================================================================
//  视图
// ============================================================================

/**
 * 取字符串第一个字符（UTF-8 安全，不依赖 mbstring）
 */
function firstChar($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '?';
    }
    if (preg_match('/./us', $value, $matches) === 1) {
        return htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars(substr($value, 0, 1), ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string,mixed> $client
 * @param array<string,mixed> $params
 * @param \W8\Passport\Identity\Account $account
 */
function renderConsentPage(array $client, array $params, $account, $redirectUri)
{
    $appName = htmlspecialchars((string) $client['name'], ENT_QUOTES, 'UTF-8');
    $initial = firstChar($client['name']);
    $logo = isset($client['logo_url']) && $client['logo_url'] !== ''
        ? '<img src="' . htmlspecialchars((string) $client['logo_url'], ENT_QUOTES, 'UTF-8') . '" alt="">'
        : $initial;

    $homepage = isset($client['homepage_url']) && $client['homepage_url'] !== ''
        ? '<div class="w8-consent__meta">' . htmlspecialchars((string) $client['homepage_url'], ENT_QUOTES, 'UTF-8') . '</div>'
        : '';

    $scopeItems = '';
    foreach ($params['scopes'] as $scope) {
        $description = isset(Scope::MAP[$scope]) ? Scope::MAP[$scope] : $scope;
        $scopeItems .= '<li>'
            . '<span class="w8-scopes__check" aria-hidden="true">✓</span>'
            . '<span class="w8-scopes__body">'
            . '<span class="w8-scopes__name">' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span class="w8-scopes__desc">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</span></li>';
    }

    // 授权请求参数原样带回，保证 POST 时能重新走一遍完整校验
    $carried = array(
        'client_id'             => $client['client_id'],
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => Scope::toString($params['scopes']),
        'state'                 => $params['state'],
        'code_challenge'        => $params['code_challenge'],
        'code_challenge_method' => $params['code_challenge_method'],
    );

    $hidden = '';
    foreach ($carried as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $hidden .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
    }

    $accountName = htmlspecialchars($account->username(), ENT_QUOTES, 'UTF-8');
    $playerName = htmlspecialchars($account->playerName(), ENT_QUOTES, 'UTF-8');
    $safeRedirect = htmlspecialchars((string) $redirectUri, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#4A7DB5">
<title>授权 {$appName} 使用你的 8W通行证</title>
<link rel="icon" href="/images/favicon.ico">
<link rel="stylesheet" href="/passport/assets/passport.css">
</head>
<body class="w8-passport">
<div class="w8-shell">
    <header class="w8-topbar">
        <div class="w8-topbar__inner">
            <a class="w8-brand" href="/passport/">
                <span class="w8-brand__mark">8W</span>
                <span>8W通行证<span class="w8-brand__sub">8W社区统一身份服务</span></span>
            </a>
        </div>
    </header>

    <main class="w8-main w8-main--narrow">
        <div class="w8-card w8-card--hero">
            <div class="w8-card__body">
                <h1 class="w8-card__title">授权请求</h1>
                <p class="w8-card__sub">以下应用正在申请访问你的通行证信息</p>

                <div class="w8-consent__app">
                    <span class="w8-consent__logo">{$logo}</span>
                    <span>
                        <span class="w8-consent__name">{$appName}</span>
                        {$homepage}
                    </span>
                </div>

                <p class="w8-label">将向该应用提供</p>
                <ul class="w8-scopes">{$scopeItems}</ul>

                <form method="post" action="/oauth/authorize">
                    {$hidden}
                    <div class="w8-cluster w8-mt-5" style="gap:10px">
                        <button type="submit" name="decision" value="deny"
                                class="w8-btn w8-btn--ghost" style="flex:1">拒绝</button>
                        <button type="submit" name="decision" value="allow"
                                class="w8-btn" style="flex:2">同意授权</button>
                    </div>
                </form>
            </div>

            <div class="w8-card__foot" style="justify-content:flex-start">
                <table class="w8-kv" style="width:100%">
                    <tr><th>当前通行证</th><td>{$accountName}</td></tr>
                    <tr><th>游戏内玩家</th><td>{$playerName}</td></tr>
                    <tr><th>授权后回调</th><td><code>{$safeRedirect}</code></td></tr>
                </table>
            </div>
        </div>

        <p class="w8-consent__footnote">
            你可以随时在 <a href="/passport/">通行证中心</a> 查看并撤销已授权的应用。
        </p>

        <div class="w8-footer">8W通行证 · 8W社区统一身份服务</div>
    </main>
</div>
</body>
</html>
HTML;
}

function renderErrorPage($message, $status = 400)
{
    http_response_code((int) $status);
    $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>授权请求无效</title>
<link rel="icon" href="/images/favicon.ico">
<link rel="stylesheet" href="/passport/assets/passport.css">
</head>
<body class="w8-passport">
<div class="w8-shell">
    <header class="w8-topbar">
        <div class="w8-topbar__inner">
            <a class="w8-brand" href="/passport/">
                <span class="w8-brand__mark">8W</span>
                <span>8W通行证<span class="w8-brand__sub">8W社区统一身份服务</span></span>
            </a>
        </div>
    </header>

    <main class="w8-main w8-main--narrow">
        <div class="w8-card">
            <div class="w8-card__body">
                <h1 class="w8-card__title">授权请求无效</h1>
                <div class="w8-alert w8-alert--error w8-mb-4">
                    <span class="w8-alert__icon" aria-hidden="true">!</span>
                    <div class="w8-alert__body">{$safe}</div>
                </div>
                <p class="w8-muted">
                    出于安全考虑，本次请求没有被重定向到第三方地址。请回到应用重新发起授权。
                </p>
            </div>
        </div>

        <div class="w8-footer">8W通行证 · 8W社区统一身份服务</div>
    </main>
</div>
</body>
</html>
HTML;
}
