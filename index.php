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
    <meta name="description" content="8W社区官方网站 - 专业的Minecraft Java版服务器平台，提供我的世界服务器、MC国战服、邦国崛起游戏等服务。维护和平，促进发展，共建和谐的我的世界国战服社区。">
    <meta name="keywords" content="邦国崛起,社区,Minecraft,服务器,我的世界,MC,我的世界java版,我的世界服务器,我的世界国战服,国战服,邦国,国产服,国内java服务器,我的世界服务器ip地址,我的世界服务器推荐,我的世界服务器搭建,MC国战服JAVA,邦国崛起游戏">
    <meta name="author" content="8W社区">
    <meta name="robots" content="index, follow">
    <meta name="msvalidate.01" content="<?php echo env('BING_SITE_VERIFICATION', 'C33A4C83526E03ED6F8FAAEA1B02E3EF'); ?>" />
    <meta property="og:title" content="8W社区官方网站 - 邦国崛起玩家社区">
    <meta property="og:description" content="8W社区官方网站 - 专业的Minecraft Java版服务器平台，提供我的世界服务器、MC国战服、邦国崛起游戏等服务。维护和平，促进发展，共建和谐的我的世界国战服社区。">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://8w.bgjq.top">
    <meta property="og:image" content="https://8w.bgjq.top/images/Lyizai.webp">
    <meta property="bytedance:published_time" content="2026-03-10T00:00:00+08:00" />
    <meta property="bytedance:lrDate_time" content="2026-03-10T00:00:00+08:00" />
    <meta property="bytedance:updated_time" content="2026-03-10T00:00:00+08:00" />
    <link rel="icon" href="/images/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/images/favicon.ico" type="image/x-icon">
    <link rel="canonical" href="https://8w.bgjq.top">
    <title>8W社区官方网站 - 邦国崛起玩家社区</title>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "8W社区",
      "alternateName": "BangGuo United Nations",
      "url": "https://8w.bgjq.top",
      "logo": "https://8w.bgjq.top/images/Lyizai.webp",
      "description": "8W社区官方网站 - 专业的Minecraft Java版服务器平台，提供我的世界服务器、MC国战服、邦国崛起游戏等服务。维护和平，促进发展，共建和谐的我的世界国战服社区。",
      "publisher": {
        "@type": "Organization",
        "name": "8W社区",
        "url": "https://8w.bgjq.top",
        "logo": {
          "@type": "ImageObject",
          "url": "https://8w.bgjq.top/images/Lyizai.webp"
        }
      },
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://8w.bgjq.top/?q={search_term_string}",
        "query-input": "required name=search_term_string"
      },
      "contactPoint": {
        "@type": "ContactPoint",
        "telephone": "1085806711",
        "contactType": "QQ Group",
        "url": "https://qm.qq.com/q/hELXutcWZy",
        "availableLanguage": ["zh-CN", "en"]
      },
      "inLanguage": ["zh-CN", "en"]
    }
    </script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "8W社区",
      "alternateName": "BangGuo United Nations",
      "url": "https://8w.bgjq.top",
      "logo": "https://8w.bgjq.top/images/Lyizai.webp",
      "description": "邦国崛起服务器的社区组织，维护和平、促进发展、共建和谐。提供我的世界Java版服务器、MC国战服、邦国崛起游戏等服务。",
      "sameAs": [
        "https://qm.qq.com/q/hELXutcWZy"
      ],
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "bgjq.simpfun.cn",
        "description": "Minecraft服务器地址"
      }
    }
    </script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "GameServer",
      "name": "邦国崛起服务器",
      "url": "https://8w.bgjq.top",
      "description": "专业的Minecraft Java版服务器，提供国战服、邦国崛起游戏等服务",
      "game": {
        "@type": "VideoGame",
        "name": "Minecraft",
        "alternateName": "我的世界",
        "gamePlatform": "PC",
        "genre": "Sandbox",
        "description": "我的世界是一款沙盒游戏，玩家可以在三维空间中自由创造和破坏不同种类的方块"
      },
      "serverStatus": "online",
      "serverAddress": "bgjq.simpfun.cn",
      "playersOnline": 0
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
    <link rel="stylesheet" href="/css/nes.min.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <img src="/images/Lyizai.webp" alt="社区Logo" class="logo-img" onerror="this.style.display='none'">
                    <div class="header-text">
                        <h1 data-i18n="website_name">8W 社区</h1>
                        <p class="subtitle" data-i18n="website_subtitle">8w Community</p>
                    </div>
                </div>
                <div class="user-panel" id="userPanel">
                    <div class="language-switch" id="languageSwitch">
                        <button class="lang-btn active" data-lang="zh">中文</button>
                        <button class="lang-btn" data-lang="en">English</button>
                    </div>
                    <div id="userNotLoggedIn">
                        <button class="nes-btn is-primary" id="showLoginBtn">登录</button>
                        <button class="nes-btn" id="showRegisterBtn">注册</button>
                    </div>
                    <div id="userLoggedIn" style="display: none;">
                        <span class="user-info" id="userInfo">用户</span>
                        <span class="role-badge nes-badge" id="roleBadge">观察员</span>
                        <button class="nes-btn" id="logoutBtn">退出</button>
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
                <li><a href="/" class="nav-link active" data-i18n="website_name">首页</a></li>
                <li><a href="/world-news" class="nav-link" data-i18n="world_news">世界动态</a></li>
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
            <section class="section">
                <h2 class="section-title" data-i18n="welcome_to_8w">欢迎来到 8W 社区</h2>
                
                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="about_us">关于我们</h3>
                    <div class="server-info">
                        <p data-i18n="about_us_content">8W 社区是邦国崛起服务器的玩家社区组织，致力于维护和平、促进发展、共建和谐的游戏环境。我们提供专业的<span class="keyword">Minecraft Java 版服务器</span>，打造公平、有趣的<span class="keyword">我的世界国战服</span>体验。</p>
                        <p data-i18n="server_address_content">服务器地址：<strong>bgjq.simpfun.cn</strong>，欢迎各位玩家加入我们的大家庭，一起探索这个充满无限可能的世界。</p>
                    </div>
                </div>

                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="headline_news">头条新闻</h3>
                    <?php
                    try {
                        if ($db) {
                            $news = getNewsData($db);
                            if (!empty($news)) {
                                $totalNews = count($news);
                                echo '<div class="headline-news-wrapper" id="headlineNewsWrapper">';
                                echo '<div class="headline-news-container" id="headlineNewsContainer">';
                                $index = 0;
                                foreach ($news as $item) {
                                    echo renderHeadlineNewsCard($item, $index, $totalNews);
                                    $index++;
                                }
                                echo '</div>';
                                // 服务端渲染控制按钮
                                echo '<div class="headline-news-controls">';
                                echo '<button class="headline-news-btn" onclick="prevHeadlineNews()" title="上一条">&#10094;</button>';
                                echo '<div class="headline-news-dots">';
                                for ($i = 0; $i < $totalNews; $i++) {
                                    $activeClass = $i === 0 ? 'active' : '';
                                    echo '<span class="headline-news-dot ' . $activeClass . '" onclick="goToHeadlineNews(' . $i . ')" data-index="' . $i . '"></span>';
                                }
                                echo '</div>';
                                echo '<button class="headline-news-btn" onclick="nextHeadlineNews()" title="下一条">&#10095;</button>';
                                echo '</div>';
                                echo '</div>';
                            } else {
                                echo '<div class="nes-container with-title" style="text-align: center; padding: 40px;">
                                    <h3 class="title">' . date('Y 年 m 月 d 日') . '</h3>
                                    <i class="nes-icon is-large star" style="margin: 20px 0;"></i>
                                    <h4 style="margin-bottom: 10px; color: var(--un-blue);" data-i18n="no_news">暂无新闻</h4>
                                    <p style="color: #666;" data-i18n="no_news_desc">当前没有新闻更新，请稍后查看。</p>
                                </div>';
                            }
                        } else {
                            throw new Exception('数据库连接失败');
                        }
                    } catch (Exception $e) {
                        echo '<div class="nes-container with-title is-error" style="text-align: center; padding: 40px;">
                              <h3 class="title">' . date('Y 年 m 月 d 日') . '</h3>
                              <i class="nes-icon is-large close" style="margin: 20px 0;"></i>
                              <h4 style="margin-bottom: 10px; color: var(--error-color);" data-i18n="server_maintenance">服务器维护公告</h4>
                              <p style="color: #666;" data-i18n="server_maintenance_desc">服务器正在进行例行维护，预计很快恢复正常运行。</p>
                          </div>';
                    }
                    ?>
                </div>

                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="core_features">核心功能</h3>
                    <div class="feature-grid">
                        <div class="feature-item nes-container">
                            <h4><a href="/world-news" data-i18n="world_news_feature">世界动态</a></h4>
                            <p data-i18n="world_news_feature_desc">了解服务器最新新闻和历史事件</p>
                        </div>
                        <div class="feature-item nes-container">
                            <h4><a href="/parliament" data-i18n="parliament_feature">邦国议会</a></h4>
                            <p data-i18n="parliament_feature_desc">查看所有成员国信息和详情</p>
                        </div>
                        <div class="feature-item nes-container">
                            <h4><a href="/assembly" data-i18n="assembly_feature">社区大会</a></h4>
                            <p data-i18n="assembly_feature_desc">参与提案投票和查看世界公约</p>
                        </div>
                        <div class="feature-item nes-container">
                            <h4><a href="/court" data-i18n="court_feature">国际法庭</a></h4>
                            <p data-i18n="court_feature_desc">了解案件审理和仲裁结果</p>
                        </div>
                    </div>
                </div>

                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="server_features">服务器特色</h3>
                    <ul>
                        <li data-i18n="feature_1">专业的国战系统，让玩家体验真实的国家战争</li>
                        <li data-i18n="feature_2">完善的经济系统，支持玩家之间的贸易往来</li>
                        <li data-i18n="feature_3">公平的游戏环境，严厉打击作弊行为</li>
                        <li data-i18n="feature_4">定期举办各种活动，丰富玩家的游戏体验</li>
                    </ul>
                </div>

                <div class="nes-container with-title">
                    <h3 class="title" data-i18n="contact_us">联系我们</h3>
                    <div style="text-align: center; padding: 20px;">
                        <a href="https://qm.qq.com/q/hELXutcWZy" target="_blank" class="nes-btn is-primary" data-i18n="join_qq_group">
                            <i class="nes-icon is-small message"></i>
                            加入 QQ 群：1085806711
                        </a>
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
                    <img src="/images/Lyizai.webp" alt="8W社区公众号二维码" class="wechat-qr-image">
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
                    <h4 data-i18n="website_name">8W 社区</h4>
                    <p data-i18n="website_motto">维护和平 · 促进发展 · 共建和谐</p>
                    <div style="margin-top: 15px;">
                        <a href="https://qm.qq.com/q/hELXutcWZy" target="_blank" class="nes-btn is-primary" data-i18n="join_qq_group">
                            <i class="nes-icon is-small message"></i>
                            加入 QQ 群：1085806711
                        </a>
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="https://pd.qq.com/s/61ds8hzgr" target="_blank" class="nes-btn is-primary">
                            <i class="nes-icon is-small message"></i>
                            加入 QQ 频道
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
                <p data-i18n="copyright">© 2026 8W 社区。保留所有权利.</p>
                <p data-i18n="disclaimer" style="margin-top: 10px; font-size: 0.8rem; color: #888;">本网站仅用于邦国崛起服务器游戏内用途，不涉及真实政治，严格遵守中华人民共和国法律法规。</p>
            </div>
        </div>
    </footer>

    <div class="modal-overlay" id="sponsorModal">
        <div class="modal nes-container with-title">
            <h3 class="title" data-i18n="support_us">支持我们</h3>
            <button class="close-modal" id="closeSponsorModal">&times;</button>
            <div style="text-align: center; padding: 30px;">
                <p data-i18n="support_message">您的支持将帮助我们维持服务器运行</p>
                <img src="/images/赞赏码.webp" alt="赞赏码" class="sponsor-code" style="max-width: 300px; margin: 20px 0;">
            </div>
        </div>
    </div>

    <!-- 全局加载动画 -->
    <div id="globalLoading" class="global-loading">
        <div class="loading-spinner"></div>
    </div>

    <button class="back-to-top nes-btn" id="backToTop">
        <i class="nes-icon is-small balloon"></i>
        <span>回到顶部</span>
    </button>

    <div class="modal-overlay" id="loginModal">
        <div class="modal nes-container with-title">
            <h3 class="title">登录</h3>
            <button class="close-modal" id="closeLoginModal">&times;</button>
            <form id="loginForm">
                <div class="nes-field">
                    <label for="login-username">用户名</label>
                    <input type="text" id="login-username" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="login-password">密码</label>
                    <input type="password" id="login-password" class="nes-input" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="nes-btn is-primary">登录</button>
                    <button type="button" class="nes-btn cancel-btn">取消</button>
                </div>
                <div class="form-message" id="loginFormMessage"></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal">
        <div class="modal nes-container with-title">
            <h3 class="title">注册</h3>
            <button class="close-modal" id="closeRegisterModal">&times;</button>
            <form id="registerForm">
                <div class="nes-field">
                    <label for="register-username">用户名 <span class="required">*</span></label>
                    <input type="text" id="register-username" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-password">密码 <span class="required">*</span></label>
                    <input type="password" id="register-password" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-password-confirm">确认密码 <span class="required">*</span></label>
                    <input type="password" id="register-password-confirm" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-game-id">游戏ID <span class="required">*</span></label>
                    <input type="text" id="register-game-id" class="nes-input" required>
                </div>
                <div class="nes-field">
                    <label for="register-country">所属邦国（可选）</label>
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
                    <button type="submit" class="nes-btn is-primary">注册</button>
                    <button type="button" class="nes-btn cancel-btn">取消</button>
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


