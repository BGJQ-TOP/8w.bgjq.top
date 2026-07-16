<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/php/config.php';
require_once __DIR__ . '/php/render_functions.php';

try {
    $db = getDBConnection();
} catch (Exception $e) {
    echo 'Database connection error: ' . $e->getMessage() . '<br>';
    $db = null;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="8W社区 - 世界动态，了解最新的服务器新闻和事件">
    <meta name="keywords" content="邦国崛起,社区,世界动态,新闻,事件,Minecraft,服务器">
    <meta name="author" content="8W社区">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="世界动态 - 8W社区官方网站">
    <meta property="og:description" content="8W社区 - 世界动态，了解最新的服务器新闻和事件">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://8w.bgjq.top/world-news">
    <meta property="og:image" content="https://8w.bgjq.top/images/logo.webp">
    <meta property="bytedance:published_time" content="2026-03-10T00:00:00+08:00" />
    <meta property="bytedance:lrDate_time" content="2026-03-10T00:00:00+08:00" />
    <meta property="bytedance:updated_time" content="2026-03-10T00:00:00+08:00" />
    <link rel="icon" href="/images/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/images/favicon.ico" type="image/x-icon">
    <link rel="canonical" href="https://8w.bgjq.top/world-news">
    <title>世界动态 - 8W社区</title>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "世界动态 - 8W社区",
      "description": "8W社区 - 世界动态，了解最新的服务器新闻和事件",
      "url": "https://8w.bgjq.top/world-news",
      "breadcrumb": {
        "@type": "BreadcrumbList",
        "itemListElement": [{
          "@type": "ListItem",
          "position": 1,
          "name": "首页",
          "item": "https://8w.bgjq.top/"
        },{
          "@type": "ListItem",
          "position": 2,
          "name": "世界动态",
          "item": "https://8w.bgjq.top/world-news"
        }]
      }
    }
    </script>
    <style>
        @font-face {
            font-family: 'ZPix';
            src: url('/fonts/zpix.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+SC:wght@400;700&family=Noto+Sans+SC:wght@400;500;700&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif:wght@400;700&family=Noto+Sans:wght@400;500;700&display=swap');
    </style>
    <link rel="stylesheet" href="/css/nes.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .language-switch {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }
        .lang-btn {
            padding: 8px 16px;
            border: 2px solid #333;
            background: #fff;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .lang-btn:hover {
            background: var(--un-blue);
            color: white;
        }
        .lang-btn.active {
            background: var(--un-blue);
            color: white;
            font-weight: bold;
        }
    </style>
    <!-- Clarity tracking code for https://8w.bgjq.top/ -->
    <script>
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i+"?ref=bwt";
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "<?php echo env('CLARITY_PROJECT_ID', 'vnqsaapkjs'); ?>");
    </script>
    <!-- 自动收录代码 -->
    <script>
    (function(){
    var el = document.createElement("script");
    el.src = "https://lf1-cdn-tos.bytegoofy.com/goofy/ttzz/push.js?<?php echo env('BYTEDANCE_PUSH_TOKEN', 'd654cdee31e00a2b02619e7f44d2772b0fbac7661d05e8193da5880b90f3b4d119d1c501ebd3301f5e2290626f5b53d078c8250527fa0dfd9783a026ff3cf719'); ?>";
    el.id = "ttzz";
    var s = document.getElementsByTagName("script")[0];
    s.parentNode.insertBefore(el, s);
    })(window)
    </script>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <img src="/images/logo.webp" alt="社区Logo" class="logo-img" onerror="this.style.display='none'">
                    <div class="header-text">
                        <h1 data-i18n="website_name">8W社区</h1>
                        <p class="subtitle" data-i18n="website_subtitle">8w Community</p>
                    </div>
                </div>
                <div class="user-panel" id="userPanel">
                    <div class="language-switch" id="languageSwitch">
                        <button class="lang-btn active" data-lang="zh">中文</button>
                        <button class="lang-btn" data-lang="en">English</button>
                    </div>
                    <div id="userNotLoggedIn">
                        <button class="nes-btn is-primary" id="showLoginBtn" data-i18n="login">登录</button>
                        <button class="nes-btn" id="showRegisterBtn" data-i18n="register">注册</button>
                    </div>
                    <div id="userLoggedIn" style="display: none;">
                        <span class="user-info" id="userInfo">用户</span>
                        <span class="role-badge nes-badge" id="roleBadge">观察员</span>
                        <button class="nes-btn" id="logoutBtn" data-i18n="logout">退出</button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <div class="container">
            <div class="nav-header">
                <button class="menu-toggle" id="menuToggle">
                    <span class="menu-icon"></span>
                    <span class="menu-icon"></span>
                    <span class="menu-icon"></span>
                </button>
            </div>
            <ul class="nav-menu" id="navMenu">
                <li><a href="/" class="nav-link" data-i18n="website_name">首页</a></li>
                <li><a href="/world-news" class="nav-link active" data-i18n="world_news">世界动态</a></li>
                <li><a href="/parliament" class="nav-link" data-i18n="bangguo_parliament">邦国议会</a></li>
                <li><a href="/assembly" class="nav-link" data-i18n="un_assembly">社区大会</a></li>
                <li><a href="/court" class="nav-link" data-i18n="international_court">国际法庭</a></li>
                <li><a href="/services" class="nav-link" data-i18n="public_services">公共服务</a></li>
                <li><a href="/complaint" class="nav-link" data-i18n="submit_complaint">提交投诉</a></li>
                <li><a href="/sitemap" class="nav-link" data-i18n="site_map">网站地图</a></li>
            </ul>
        </div>
    </nav>

    <main>
        <div class="container">
            <section class="section" id="home">
                <h2 class="section-title" data-i18n="world_news">世界动态</h2>
                
                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="headline_news">新闻列表</h3>
                    <div class="news-list">
                        <?php
                        try {
                            if ($db) {
                                $news = getNewsData($db);
                                if (!empty($news)) {
                                    foreach ($news as $item) {
                                        echo renderNewsListItem($item);
                                    }
                                } else {
                                    echo '<div class="nes-container with-title" style="text-align: center; padding: 40px;">
                                        <h3 class="title">' . date('Y年m月d日') . '</h3>
                                        <i class="nes-icon is-large star" style="margin: 20px 0;"></i>
                                        <h4 style="margin-bottom: 10px; color: var(--un-blue);">暂无新闻</h4>
                                        <p style="color: #666;">当前没有新闻更新，请稍后查看。</p>
                                    </div>';
                                }
                            } else {
                                throw new Exception('数据库连接失败');
                            }
                        } catch (Exception $e) {
                            echo '<div class="nes-container with-title is-error" style="text-align: center; padding: 40px;">
                                <h3 class="title">' . date('Y年m月d日') . '</h3>
                                <i class="nes-icon is-large close" style="margin: 20px 0;"></i>
                                <h4 style="margin-bottom: 10px; color: var(--error-color);">服务器维护公告</h4>
                                <p style="color: #666;">服务器正在进行例行维护，预计很快恢复正常运行。</p>
                            </div>';
                        }
                        ?>
                    </div>
                </div>

                <div class="nes-container with-title timeline-container">
                    <h3 class="title" data-i18n="history_timeline">历史时间轴</h3>
                    <div class="timeline">
                        <?php
                        try {
                            if ($db) {
                                $timeline = getTimelineData($db);
                                foreach ($timeline as $event) {
                                    echo renderTimelineItem($event);
                                }
                                if (empty($timeline)) {
                                    echo "<div class=\"timeline-item\">
                                        <div class=\"timeline-marker\"></div>
                                        <div class=\"timeline-content nes-container\">
                                            <span class=\"timeline-date\">" . date('Y-m-d') . "</span>
                                            <h4>暂无历史记录</h4>
                                            <p>历史时间轴暂无记录。</p>
                                        </div>
                                    </div>";
                                }
                            } else {
                                throw new Exception('数据库连接失败');
                            }
                        } catch (Exception $e) {
                            echo "<div class=\"timeline-item\">
                                <div class=\"timeline-marker\"></div>
                                <div class=\"timeline-content nes-container\">
                                    <span class=\"timeline-date\">" . date('Y-m-d') . "</span>
                                    <h4>时间轴加载中</h4>
                                    <p>历史时间轴数据正在加载，请稍后刷新页面。</p>
                                </div>
                            </div>";
                        }
                        ?>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <footer>
        <div class="container">
            <!-- 公众号模块 -->
            <div class="wechat-section">
                <h4>关注公众号</h4>
                <div class="wechat-qr-container">
                    <img src="/images/logo.webp" alt="8W社区公众号二维码" class="wechat-qr-image">
                    <div class="wechat-text">
                        扫描二维码关注8W社区公众号<br>
                        获取最新服务器动态和活动信息
                    </div>
                    <div class="wechat-hint">
                        长按图片保存二维码，使用微信扫描关注
                    </div>
                </div>
            </div>
            
            <div class="footer-content">
                <div class="footer-section">
                    <h4 data-i18n="website_name">8W社区</h4>
                    <p data-i18n="website_motto">维护和平 · 促进发展 · 共建和谐</p>
                    <div style="margin-top: 15px;">
                        <a href="https://qm.qq.com/q/hELXutcWZy" target="_blank" class="nes-btn is-primary" data-i18n="join_qq_group">
                            <i class="nes-icon is-small message"></i>
                            加入QQ群：1085806711
                        </a>
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="https://pd.qq.com/s/61ds8hzgr" target="_blank" class="nes-btn is-primary">
                            <i class="nes-icon is-small message"></i>
                            加入QQ频道
                        </a>
                    </div>
                </div>
                <div class="footer-section">
                    <h4 data-i18n="server_info">服务器信息</h4>
                    <p data-i18n="server_address">地址：bgjq.simpfun.cn</p>
                    <p data-i18n="online_map_link">在线地图：<a href="http://bgjq.simpfun.cn" target="_blank">bgjq.simpfun.cn</a></p>
                </div>
            </div>
            <div class="footer-bottom">
                <p data-i18n="copyright">© 2026 8W社区. 保留所有权利.</p>
                <p data-i18n="disclaimer" style="margin-top: 10px; font-size: 0.8rem; color: #888;">本网站仅用于邦国崛起服务器游戏内用途，不涉及真实政治，严格遵守中华人民共和国法律法规。</p>
            </div>
        </div>
    </footer>

    <!-- 全局加载动画 -->
    <div id="globalLoading" class="global-loading">
        <div class="loading-spinner"></div>
    </div>

    <button class="back-to-top nes-btn" id="backToTop">
        <i class="nes-icon is-small balloon"></i>
        <span data-i18n="back_to_top">回到顶部</span>
    </button>

    <div class="modal-overlay" id="loginModal">
        <div class="modal nes-container with-title">
            <h3 class="title" data-i18n="login">登录</h3>
            <button class="close-modal" id="closeLoginModal">&times;</button>
            <form id="loginForm">
                <div class="nes-field">
                    <label for="login-username" data-i18n="username">用户名</label>
                    <input type="text" id="login-username" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="login-password" data-i18n="password">密码</label>
                    <input type="password" id="login-password" class="nes-input" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="nes-btn is-primary" data-i18n="login">登录</button>
                    <button type="button" class="nes-btn cancel-btn" data-i18n="cancel">取消</button>
                </div>
                <div class="form-message" id="loginFormMessage"></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal">
        <div class="modal nes-container with-title">
            <h3 class="title" data-i18n="register">注册</h3>
            <button class="close-modal" id="closeRegisterModal">&times;</button>
            <form id="registerForm">
                <div class="nes-field">
                    <label for="register-username" data-i18n="username">用户名 <span class="required">*</span></label>
                    <input type="text" id="register-username" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-password" data-i18n="password">密码 <span class="required">*</span></label>
                    <input type="password" id="register-password" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-password-confirm" data-i18n="confirm_password">确认密码 <span class="required">*</span></label>
                    <input type="password" id="register-password-confirm" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-game-id" data-i18n="game_id">游戏ID <span class="required">*</span></label>
                    <input type="text" id="register-game-id" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-country" data-i18n="country">所属邦国（可选）</label>
                    <input type="text" id="register-country" class="nes-input" placeholder="如果未加入邦国，请填写'流民'">
                </div>
                <div class="nes-field">
                    <label for="register-jhtuid">简幻通UID <span class="required">*</span></label>
                    <input type="text" id="register-jhtuid" class="nes-input" placeholder="请输入简幻通UID" required>
                </div>
                <div class="nes-field">
                    <label for="register-jht-code">简幻通验证码 <span class="required">*</span></label>
                    <input type="text" id="register-jht-code" class="nes-input" placeholder="请输入简幻通验证码" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="nes-btn is-primary" data-i18n="register">注册</button>
                    <button type="button" class="nes-btn cancel-btn" data-i18n="cancel">取消</button>
                </div>
                <div class="form-message" id="registerFormMessage"></div>
                <div class="loading-container" id="registerLoading" style="display: none; text-align: center; padding: 20px;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top: 10px;">正在验证信息正确性...</p>
                </div>
            </form>
        </div>
    </div>

    <script src="/js/main.js"></script>
</body>
</html>


