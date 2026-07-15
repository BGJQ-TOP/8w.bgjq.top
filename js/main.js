const API_BASE = '/api/v1';

let currentUser = null;
let currentLang = 'zh';

// 翻译数据
const translations = {
    zh: {
        website_title: '8W社区官方网站 - Minecraft服务器成员国管理与维和平台',
        website_name: '8W社区',
        website_subtitle: '8w Community',
        login: '登录',
        register: '注册',
        logout: '退出',
        world_news: '世界动态',
        bangguo_parliament: '邦国议会',
        un_assembly: '社区大会',
        international_court: '国际法庭',
        world_map: '世界地图',
        global_trade: '全球贸易',
        submit_complaint: '提交投诉',
        publish_trade: '发布交易',
        admin_panel: '管理后台',
        headline_news: '头条新闻',
        history_timeline: '历史时间轴',
        online_representatives: '在线代表',
        member_countries: '成员国列表',
        current_proposals: '当前提案',
        world_conventions: '世界公约',
        ongoing_trials: '正在审理',
        arbitration_archive: '仲裁结果库',
        have_complaint: '有违规行为需要举报？',
        go_to_complaint: '前往投诉页面',
        online_map: '在线地图',
        visit_online_map: '点击访问邦国崛起在线地图',
        open_in_new_tab: '在新标签页中打开',
        global_economy_trade: '全球经济与贸易',
        commodity_trades: '大宗商品交易',
        have_items_to_trade: '有物品需要求购或出售？',
        go_to_publish_trade: '前往发布交易',
        website_motto: '维护和平 · 促进发展 · 共建和谐',
        sponsor_us: '赞助我们',
        server_info: '服务器信息',
        server_address: '地址：bgjq.simpfun.cn',
        online_map_link: '在线地图：<a href="http://bgjq.simpfun.cn" target="_blank">bgjq.simpfun.cn</a>',
        permission_groups: '权限组',
        permission_levels: '秘书长 · 常任理事国 · 邦国外交官',
        permission_levels_2: '观察员 · 维和部队',
        copyright: '© 2026 8W社区. 保留所有权利.',
        disclaimer: '本网站仅用于邦国崛起服务器游戏内用途，不涉及真实政治。',
        support_us: '支持我们',
        support_message: '您的支持将帮助我们维持服务器运行',
        back_to_top: '回到顶部',
        join_qq_group: '加入QQ群：1085806711',
        site_map: '网站地图',
        username: '用户名',
        password: '密码',
        confirm_password: '确认密码',
        game_id: '游戏ID',
        country: '所属邦国（可选）',
        cancel: '取消',
        secretary_general: '秘书长',
        permanent_member: '常任理事国',
        diplomat: '邦国外交官',
        observer: '观察员',
        peacekeeper: '维和部队',
        online_users: '人在线',
        online: '在线',
        offline: '离线',
        government_type: '政体',
        population: '人口',
        territory: '领地',
        diplomacy: '外交',
        neutral: '中立',
        no_declaration: '暂无宣言',
        proposal_type: '提案类型',
        proposal_status: '提案状态',
        proposal_country: '提案国',
        proposer: '提案人',
        vote_results: '投票结果',
        for_vote: '赞成',
        against_vote: '反对',
        abstain_vote: '弃权',
        you_have_voted: '您已经投过票了！',
        vote_success: '投票成功！',
        please_login: '请先登录！',
        only_diplomat: '只有邦国外交官才能发布交易！',
        only_secretary: '只有秘书长才能访问管理后台！',
        fill_all_fields: '请填写所有必填项！',
        complaint_submitted: '投诉已提交！我们会尽快处理。',
        trade_published: '交易信息已发布！',
        news_published: '新闻发布成功！',
        event_added: '事件添加成功！',
        country_created: '邦国创建成功！',
        country_updated: '邦国更新成功！',
        status_toggled: '状态切换成功！',
        country_deleted: '邦国删除成功！',
        user_added: '用户添加成功！',
        user_updated: '用户更新成功！',
        user_deleted: '用户删除成功！',
        news_updated: '新闻更新成功！',
        news_deleted: '新闻删除成功！',
        event_updated: '事件更新成功！',
        event_deleted: '事件删除成功！',
        logout_success: '已退出登录',
        register_success: '注册成功！请登录',
        passwords_not_match: '两次输入的密码不一致',
        network_error: '网络错误',
        buy: '求购',
        sell: '出售',
        newly_published: '新发布',
        publish_time: '发布时间',
        contact_method: '联系方式',
        in_game_contact: '游戏内联系',
        price_negotiable: '价格面议',
        public_services: '公共服务',
        service_list: '服务列表',
        edit_services: '编辑服务',
        add_service: '添加服务',
        service_name: '服务名称',
        service_url: '服务网址',
        back_to_main: '返回主站',
        sitemap_intro: '本页面包含 8W 社区官方网站的所有重要链接，帮助您快速找到所需内容。',
        main_pages: '主要页面',
        homepage: '首页',
        homepage_desc: '包含世界动态、邦国议会、社区大会等主要内容',
        world_news_desc: '显示所有新闻和世界动态',
        parliament_desc: '邦国议会相关信息和提案',
        assembly_desc: '社区大会相关信息和决议',
        court_desc: '国际法庭相关信息和案件',
        services_desc: '提供各种公共服务的列表，包括服务名称和网址',
        complaint_desc: '用于举报违规行为和提交投诉',
        admin_desc: '仅限秘书长访问的管理功能',
        xml_sitemap: 'XML 网站地图',
        xml_sitemap_desc: '供搜索引擎抓取的 XML 格式网站地图',
        other_resources: '其他资源',
        qq_group: 'QQ 群：1085806711',
        qq_group_desc: '8W 社区官方 QQ 群',
        online_map_link_desc: '邦国崛起服务器在线地图',
        complaint_notice: '投诉须知',
        complaint_guideline: '请确保您的投诉内容真实有效，恶意投诉将被追究责任。所有投诉将由维和部队审理。',
        fill_complaint: '填写投诉',
        complaint_title: '投诉标题',
        complaint_country: '被投诉邦国',
        violation_type: '违规类型',
        detailed_description: '详细描述',
        submit_complaint_btn: '提交投诉',
        reset: '重置',
        please_select: '请选择违规类型',
        violation_spam: '高频违规刷屏',
        violation_bug: '利用 BUG 跨界攻击',
        violation_grief: '恶意破坏中立区',
        violation_other: '其他',
        welcome_to_8w: '欢迎来到 8W 社区',
        about_us: '关于我们',
        about_us_content: '8W 社区是邦国崛起服务器的玩家社区组织，致力于维护和平、促进发展、共建和谐的游戏环境。我们提供专业的 Minecraft Java 版服务器，打造公平、有趣的我的世界国战服体验。',
        server_address_content: '服务器地址：bgjq.simpfun.cn，欢迎各位玩家加入我们的大家庭，一起探索这个充满无限可能的世界。',
        headline_news: '头条新闻',
        no_news: '暂无新闻',
        no_news_desc: '当前没有新闻更新，请稍后查看。',
        server_maintenance: '服务器维护公告',
        server_maintenance_desc: '服务器正在进行例行维护，预计很快恢复正常运行。',
        core_features: '核心功能',
        world_news_feature: '世界动态',
        world_news_feature_desc: '了解服务器最新新闻和历史事件',
        parliament_feature: '邦国议会',
        parliament_feature_desc: '查看所有成员国信息和详情',
        assembly_feature: '社区大会',
        assembly_feature_desc: '参与提案投票和查看世界公约',
        court_feature: '国际法庭',
        court_feature_desc: '了解案件审理和仲裁结果',
        server_features: '服务器特色',
        feature_1: '专业的国战系统，让玩家体验真实的国家战争',
        feature_2: '完善的经济系统，支持玩家之间的贸易往来',
        feature_3: '公平的游戏环境，严厉打击作弊行为',
        feature_4: '定期举办各种活动，丰富玩家的游戏体验',
        contact_us: '联系我们',
        country_list: '成员国列表',
        no_countries: '暂无邦国',
        no_countries_desc: '当前没有邦国加入。',
        loading: '加载中',
        loading_countries: '邦国数据正在加载，请稍后刷新页面。',
        government: '政体',
        population: '人口',
        territory: '领地',
        diplomacy: '外交',
        neutral: '中立',
        people: '人',
        chunk: 'Chunk',
        current_proposals: '当前提案',
        no_proposals: '暂无提案',
        no_proposals_desc: '当前没有正在进行投票的提案。',
        proposals_loading: '提案加载中',
        proposals_loading_desc: '提案数据正在加载，请稍后刷新页面。',
        world_conventions: '世界公约',
        no_conventions: '暂无公约',
        no_conventions_desc: '当前没有生效的世界公约。',
        conventions_loading: '公约加载中',
        conventions_loading_desc: '公约数据正在加载，请稍后刷新页面。',
        hearing_cases: '正在审理',
        no_cases: '暂无案件',
        no_cases_desc: '国际法庭目前没有正在审理的案件。',
        cases_loading: '加载中',
        cases_loading_title: '案件加载中',
        cases_loading_desc: '案件数据正在加载，请稍后刷新页面。',
        archive: '仲裁结果库'
    },
    en: {
        website_title: 'BangGuo United Nations Official Website - Minecraft Server Member State Management & Peacekeeping Platform',
        website_name: 'BangGuo United Nations',
        website_subtitle: '8w Community',
        login: 'Login',
        register: 'Register',
        logout: 'Logout',
        world_news: 'World News',
        bangguo_parliament: 'BangGuo Parliament',
        un_assembly: 'UN General Assembly',
        international_court: 'International Court',
        world_map: 'World Map',
        global_trade: 'Global Trade',
        submit_complaint: 'Submit Complaint',
        publish_trade: 'Publish Trade',
        admin_panel: 'Admin Panel',
        headline_news: 'Headline News',
        history_timeline: 'History Timeline',
        online_representatives: 'Online Representatives',
        member_countries: 'Member Countries',
        current_proposals: 'Current Proposals',
        world_conventions: 'World Conventions',
        ongoing_trials: 'Ongoing Trials',
        arbitration_archive: 'Arbitration Archive',
        have_complaint: 'Have any violations to report?',
        go_to_complaint: 'Go to Complaint Page',
        online_map: 'Online Map',
        visit_online_map: 'Click to visit BangGuo online map',
        open_in_new_tab: 'Open in new tab',
        global_economy_trade: 'Global Economy & Trade',
        commodity_trades: 'Commodity Trades',
        have_items_to_trade: 'Have items to buy or sell?',
        go_to_publish_trade: 'Go to Publish Trade',
        website_motto: 'Maintain Peace · Promote Development · Build Harmony',
        sponsor_us: 'Sponsor Us',
        server_info: 'Server Information',
        server_address: 'Address: bgjq.simpfun.cn',
        online_map_link: 'Online Map: <a href="http://bgjq.simpfun.cn" target="_blank">bgjq.simpfun.cn</a>',
        permission_groups: 'Permission Groups',
        permission_levels: 'Secretary General · Permanent Member · Diplomat',
        permission_levels_2: 'Observer · Peacekeeper',
        copyright: '© 2026 BangGuo United Nations. All rights reserved.',
        disclaimer: 'This website is only for in-game use on the BangGuo server, not related to real-world politics.',
        support_us: 'Support Us',
        support_message: 'Your support will help us maintain the server',
        back_to_top: 'Back to Top',
        join_qq_group: 'Join QQ Group: 1085806711',
        site_map: 'Site Map',
        username: 'Username',
        password: 'Password',
        confirm_password: 'Confirm Password',
        game_id: 'Game ID',
        country: 'Affiliated Country (Optional)',
        cancel: 'Cancel',
        secretary_general: 'Secretary General',
        permanent_member: 'Permanent Member',
        diplomat: 'Diplomat',
        observer: 'Observer',
        peacekeeper: 'Peacekeeper',
        online_users: 'online',
        online: 'Online',
        offline: 'Offline',
        government_type: 'Government',
        population: 'Population',
        territory: 'Territory',
        diplomacy: 'Diplomacy',
        neutral: 'Neutral',
        no_declaration: 'No Declaration',
        proposal_type: 'Proposal Type',
        proposal_status: 'Proposal Status',
        proposal_country: 'Proposing Country',
        proposer: 'Proposer',
        vote_results: 'Vote Results',
        for_vote: 'For',
        against_vote: 'Against',
        abstain_vote: 'Abstain',
        you_have_voted: 'You have already voted!',
        vote_success: 'Vote successful!',
        please_login: 'Please login first!',
        only_diplomat: 'Only diplomats can publish trades!',
        only_secretary: 'Only Secretary General can access admin panel!',
        fill_all_fields: 'Please fill in all required fields!',
        complaint_submitted: 'Complaint submitted! We will process it soon.',
        trade_published: 'Trade information published!',
        news_published: 'News published successfully!',
        event_added: 'Event added successfully!',
        country_created: 'Country created successfully!',
        country_updated: 'Country updated successfully!',
        status_toggled: 'Status toggled successfully!',
        country_deleted: 'Country deleted successfully!',
        user_added: 'User added successfully!',
        user_updated: 'User updated successfully!',
        user_deleted: 'User deleted successfully!',
        news_updated: 'News updated successfully!',
        news_deleted: 'News deleted successfully!',
        event_updated: 'Event updated successfully!',
        event_deleted: 'Event deleted successfully!',
        logout_success: 'Logged out successfully',
        register_success: 'Registration successful! Please login',
        passwords_not_match: 'Passwords do not match',
        network_error: 'Network error',
        buy: 'Buy',
        sell: 'Sell',
        newly_published: 'Newly Published',
        publish_time: 'Publish Time',
        contact_method: 'Contact Method',
        in_game_contact: 'In-game contact',
        price_negotiable: 'Price negotiable',
        public_services: 'Public Services - BangGuo United Nations Official Website',
        service_list: 'Service List',
        edit_services: 'Edit Services',
        add_service: 'Add Service',
        service_name: 'Service Name',
        service_url: 'Service URL',
        back_to_main: 'Back to Main Site',
        sitemap_intro: 'This page contains all important links of the BangGuo United Nations official website to help you quickly find the content you need.',
        main_pages: 'Main Pages',
        homepage: 'Homepage',
        homepage_desc: 'Contains main content such as World News, BangGuo Parliament, UN General Assembly, etc.',
        world_news_desc: 'Display all news and world dynamics',
        parliament_desc: 'BangGuo Parliament related information and proposals',
        assembly_desc: 'UN General Assembly related information and resolutions',
        court_desc: 'International Court related information and cases',
        services_desc: 'List of various public services, including service names and URLs',
        complaint_desc: 'Used to report violations and submit complaints',
        admin_desc: 'Management functions accessible only to the Secretary General',
        xml_sitemap: 'XML Sitemap',
        xml_sitemap_desc: 'XML format sitemap for search engine crawling',
        other_resources: 'Other Resources',
        qq_group: 'QQ Group: 1085806711',
        qq_group_desc: 'BangGuo United Nations Official QQ Group',
        online_map_link_desc: 'BangGuo server online map',
        complaint_notice: 'Complaint Notice',
        complaint_guideline: 'Please ensure your complaint is truthful and valid. Malicious complaints will be held accountable. All complaints will be reviewed by Peacekeepers.',
        fill_complaint: 'File Complaint',
        complaint_title: 'Complaint Title',
        complaint_country: 'Accused Country',
        violation_type: 'Violation Type',
        detailed_description: 'Detailed Description',
        submit_complaint_btn: 'Submit Complaint',
        reset: 'Reset',
        please_select: 'Please select violation type',
        violation_spam: 'Spamming',
        violation_bug: 'Bug Exploitation',
        violation_grief: 'Griefing',
        violation_other: 'Other',
        welcome_to_8w: 'Welcome to BangGuo United Nations',
        about_us: 'About Us',
        about_us_content: 'BangGuo United Nations is the official organization of the BangGuo server, committed to maintaining peace, promoting development, and building a harmonious game environment. We provide professional Minecraft Java servers to create a fair and fun world war server experience.',
        server_address_content: 'Server Address: bgjq.simpfun.cn. Welcome players to join our family and explore this world full of infinite possibilities together.',
        headline_news: 'Headline News',
        no_news: 'No News',
        no_news_desc: 'There is no news update at present, please check later.',
        server_maintenance: 'Server Maintenance Notice',
        server_maintenance_desc: 'The server is undergoing routine maintenance and is expected to resume normal operation soon.',
        core_features: 'Core Features',
        world_news_feature: 'World News',
        world_news_feature_desc: 'Learn about the latest server news and historical events',
        parliament_feature: 'BangGuo Parliament',
        parliament_feature_desc: 'View all member country information and details',
        assembly_feature: 'UN General Assembly',
        assembly_feature_desc: 'Participate in proposal voting and view world conventions',
        court_feature: 'International Court',
        court_feature_desc: 'Learn about case hearings and arbitration results',
        server_features: 'Server Features',
        feature_1: 'Professional nation war system, allowing players to experience real national wars',
        feature_2: 'Perfect economic system, supporting trade between players',
        feature_3: 'Fair game environment, cracking down on cheating',
        feature_4: 'Regular events to enrich players gaming experience',
        contact_us: 'Contact Us',
        country_list: 'Member Countries',
        no_countries: 'No Countries',
        no_countries_desc: 'There are no countries currently.',
        loading: 'Loading',
        loading_countries: 'Country data is loading, please refresh the page later.',
        government: 'Government',
        population: 'Population',
        territory: 'Territory',
        diplomacy: 'Diplomacy',
        neutral: 'Neutral',
        people: 'people',
        chunk: 'Chunk',
        current_proposals: 'Current Proposals',
        no_proposals: 'No Proposals',
        no_proposals_desc: 'There are no proposals currently voting.',
        proposals_loading: 'Loading Proposals',
        proposals_loading_desc: 'Proposal data is loading, please refresh the page later.',
        world_conventions: 'World Conventions',
        no_conventions: 'No Conventions',
        no_conventions_desc: 'There are no conventions currently in effect.',
        conventions_loading: 'Loading Conventions',
        conventions_loading_desc: 'Convention data is loading, please refresh the page later.',
        hearing_cases: 'Hearing Cases',
        no_cases: 'No Cases',
        no_cases_desc: 'There are no cases currently being heard by the International Court.',
        cases_loading: 'Loading',
        cases_loading_title: 'Loading Cases',
        cases_loading_desc: 'Case data is loading, please refresh the page later.',
        archive: 'Archive',
        service_list: 'Service List',
        no_services: 'No Services',
        no_services_desc: 'There are no services currently available.',
        service_load_error: 'Service Load Error',
        service_load_error_desc: 'Service data loading failed, please refresh the page later.'
    }
};

document.addEventListener('DOMContentLoaded', function() {
    // 确保加载动画默认是隐藏的
    const globalLoading = document.getElementById('globalLoading');
    if (globalLoading) {
        globalLoading.style.display = 'none !important';
    }
    
    initLanguageSwitch();
    initNavigation();
    initAuth();
    initSponsorButton();
    initMobileMenu();
    
    checkPageAuth();
    
    if (document.getElementById('newsCarousel')) {
        initNewsSlider();
    }
    if (document.getElementById('votingSystem') || document.querySelector('.proposal-item')) {
        initVotingSystem();
    }
    if (document.getElementById('complaintForm')) {
        initComplaintForm();
    }
    if (document.getElementById('tradeForm')) {
        initTradeForm();
    }
    if (document.getElementById('addServiceForm')) {
        initServiceForm();
    }
    initBackToTop();
    initScrollEffects();
    initAdmin();
    loadDynamicData();
});

// 初始化移动菜单
function initMobileMenu() {
    const menuToggle = document.getElementById('menuToggle');
    const navMenu = document.getElementById('navMenu');
    
    if (menuToggle && navMenu) {
        menuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            navMenu.classList.toggle('active');
        });
        
        // 点击菜单项后关闭菜单
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    menuToggle.classList.remove('active');
                    navMenu.classList.remove('active');
                }
            });
        });
        
        // 窗口大小改变时重置菜单状态
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                menuToggle.classList.remove('active');
                navMenu.classList.remove('active');
            }
        });
    }
}

// 初始化语言切换
function initLanguageSwitch() {
    console.log('initLanguageSwitch called');
    
    // 检查是否有保存的语言偏好
    const savedLang = localStorage.getItem('preferredLang');
    if (savedLang && (savedLang === 'zh' || savedLang === 'en')) {
        currentLang = savedLang;
        console.log('Loaded saved language:', savedLang);
    }
    
    // 更新按钮状态
    function updateButtonState() {
        const buttons = document.querySelectorAll('.lang-btn');
        console.log('Found', buttons.length, 'language buttons');
        buttons.forEach(b => {
            const btnLang = b.getAttribute('data-lang');
            console.log('Button lang:', btnLang, 'currentLang:', currentLang);
            if (btnLang === currentLang) {
                b.classList.add('active');
            } else {
                b.classList.remove('active');
            }
        });
    }
    
    // 使用事件委托，绑定到 document 上
    document.addEventListener('click', function(e) {
        console.log('Click event detected, target:', e.target.className);
        // 检查点击的是否是语言切换按钮
        if (e.target.classList.contains('lang-btn')) {
            const lang = e.target.getAttribute('data-lang');
            console.log('Language button clicked:', lang);
            if (!lang) return;
            
            currentLang = lang;
            
            // 更新按钮状态
            updateButtonState();
            
            // 保存语言偏好到 localStorage
            localStorage.setItem('preferredLang', lang);
            
            // 切换语言
            updateLanguage();
            
            console.log('Language switched to:', lang);
        }
    });
    
    // 初始化按钮状态
    updateButtonState();
    
    // 初始化语言
    updateLanguage();
}

// 更新语言
function updateLanguage() {
    try {
        console.log('updateLanguage: currentLang =', currentLang);
        
        // 更新页面标题
        const titleElement = document.querySelector('[data-i18n="website_title"]');
        console.log('titleElement:', titleElement);
        if (titleElement && translations[currentLang] && translations[currentLang].website_title) {
            document.title = translations[currentLang].website_title;
            console.log('Page title updated to:', translations[currentLang].website_title);
        }
        
        // 更新所有带 data-i18n 属性的元素
        const elements = document.querySelectorAll('[data-i18n]');
        console.log('Found', elements.length, 'elements with data-i18n');
        elements.forEach(element => {
            const key = element.getAttribute('data-i18n');
            if (translations[currentLang] && translations[currentLang][key]) {
                const oldContent = element.innerHTML;
                element.innerHTML = translations[currentLang][key];
                console.log('Updated', key, ':', oldContent, '->', element.innerHTML);
            } else {
                console.log('No translation for key:', key, 'in lang', currentLang);
            }
        });
        
        // 更新 select 选项的文本
        const selects = document.querySelectorAll('select[data-i18n-options]');
        selects.forEach(select => {
            const options = select.querySelectorAll('option');
            options.forEach(option => {
                const value = option.getAttribute('value');
                if (value && translations[currentLang]['violation_' + value]) {
                    option.textContent = translations[currentLang]['violation_' + value];
                } else if (value === '' && translations[currentLang].please_select) {
                    option.textContent = translations[currentLang].please_select;
                }
            });
        });
        
        // 更新在线代表计数
        const onlineCountEl = document.getElementById('onlineCount');
        if (onlineCountEl) {
            const count = onlineCountEl.textContent.match(/\d+/);
            if (count && translations[currentLang] && translations[currentLang].online_users) {
                if (currentLang === 'en') {
                    onlineCountEl.textContent = `(${count[0]} ${translations[currentLang].online_users})`;
                } else {
                    onlineCountEl.textContent = `(${count[0]}${translations[currentLang].online_users})`;
                }
            }
        }
        
        // 更新用户面板
        if (currentUser) {
            updateUserPanel();
        }
        
        console.log('Language updated to:', currentLang);
    } catch (error) {
        console.error('Error updating language:', error);
    }
}

// 重写getRoleName函数以支持多语言
function getRoleName(role) {
    return translations[currentLang][role] || role;
}

// 初始化赞助按钮
function initSponsorButton() {
    const sponsorBtn = document.getElementById('sponsorBtn');
    const sponsorModal = document.getElementById('sponsorModal');
    const closeSponsorModal = document.getElementById('closeSponsorModal');
    
    sponsorBtn?.addEventListener('click', () => {
        sponsorModal.classList.add('active');
    });
    
    closeSponsorModal?.addEventListener('click', () => {
        sponsorModal.classList.remove('active');
    });
    
    sponsorModal?.addEventListener('click', (e) => {
        if (e.target === sponsorModal) {
            sponsorModal.classList.remove('active');
        }
    });
}

function checkPageAuth() {
    const path = window.location.pathname;
    
    if (path.includes('complaint.html') || path.includes('trade-publish.php')) {
        setTimeout(() => {
            if (!currentUser) {
                showMessage('请先登录！', 'warning');
                document.getElementById('showLoginBtn')?.click();
            }
        }, 500);
    }
    
    if (path.includes('admin') || path.includes('admin.html')) {
        setTimeout(() => {
            if (!currentUser) {
                showMessage('请先登录！', 'warning');
                document.getElementById('showLoginBtn')?.click();
            } else if (currentUser.role !== 'secretary_general') {
                showMessage('只有秘书长才能访问管理后台！', 'error');
                setTimeout(() => {
                    window.location.href = 'index.html';
                }, 2000);
            }
        }, 500);
    }
}

// 调试日志：是否输出到 console（生产可设为 false）
const DEBUG_LOG = true;
function debugLog(tag, message, data) {
    if (DEBUG_LOG && console && console.groupCollapsed) {
        console.groupCollapsed('[DEBUG] ' + tag + ': ' + message);
        if (data !== undefined) console.log(data);
        console.trace();
        console.groupEnd();
    }
}

async function apiRequest(endpoint, options = {}) {
    const url = API_BASE + endpoint;
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'include'
    };
    const merged = { ...defaultOptions, ...options };
    const bodyPreview = merged.body ? (typeof merged.body === 'string' ? merged.body.substring(0, 200) : '[object]') : null;
    debugLog('apiRequest', '请求', { url, method: merged.method || 'GET', bodyPreview });
    
    try {
        const response = await fetch(url, merged);
        const text = await response.text();
        
        debugLog('apiRequest', '响应', { url, status: response.status, ok: response.ok, textPreview: text ? text.substring(0, 300) : '(空)' });
        
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (parseErr) {
            console.error('[apiRequest] JSON 解析失败:', parseErr.message, '| 原始响应:', text);
            return { error: response.ok ? '响应解析失败' : ('服务器错误 ' + response.status + (text ? ': ' + text.substring(0, 100) : '')) };
        }
        
        if (!response.ok) {
            debugLog('apiRequest', 'HTTP 非 2xx', { status: response.status, data });
            return { error: data.error || ('请求失败 ' + response.status) };
        }
        return data;
    } catch (error) {
        console.error('[apiRequest] 请求异常:', error.message, error);
        return { error: '网络错误: ' + error.message };
    }
}

/**
 * 验证玩家ID（带重试机制）
 * @param {string} gameId 游戏ID
 * @param {number} maxRetries 最大重试次数
 * @returns {Promise<Object>} 验证结果
 */
async function verifyPlayerWithRetry(gameId, maxRetries = 2) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            const result = await apiRequest('/auth.php?action=verify-player&player=' + encodeURIComponent(gameId));
            
            if (!result.error) {
                return result;
            }
            
            // 如果是明确的错误类型，不重试
            if (result.error.includes('玩家ID不存在') || 
                result.error.includes('请求参数错误') ||
                result.error.includes('游戏ID格式')) {
                return result;
            }
            
            // 如果是最后一次尝试，返回错误
            if (attempt === maxRetries) {
                return { error: result.error };
            }
            
            // 显示重试提示
            showMessage(`验证服务暂时不可用，正在重试 (${attempt}/${maxRetries})...`, 'warning');
            
            // 等待后重试（指数退避）
            await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
            
        } catch (error) {
            // 如果是最后一次尝试，返回错误
            if (attempt === maxRetries) {
                return { error: '验证服务暂时不可用，请稍后重试' };
            }
            
            // 显示重试提示
            showMessage(`网络连接异常，正在重试 (${attempt}/${maxRetries})...`, 'warning');
            
            // 等待后重试（指数退避）
            await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
        }
    }
}

function initAuth() {
    checkCurrentUser();
    initLoginModal();
    initRegisterModal();
    initLogout();
}

async function checkCurrentUser() {
    const result = await apiRequest('/auth.php?action=current');
    if (result.success && result.data.user) {
        currentUser = result.data.user;
        updateUserPanel();
        const path = window.location.pathname;
        if (path.includes('admin.html')) {
            await loadUsers();
            await loadCountriesForManage();
            await loadNewsForManage();
            await loadTimelineForManage();
        } else {
            updateAdminVisibility();
        }
    }
}

function updateUserPanel() {
    const notLoggedIn = document.getElementById('userNotLoggedIn');
    const loggedIn = document.getElementById('userLoggedIn');
    const userInfo = document.getElementById('userInfo');
    const roleBadge = document.getElementById('roleBadge');
    
    if (currentUser) {
        if (notLoggedIn) notLoggedIn.style.display = 'none';
        if (loggedIn) loggedIn.style.display = 'flex';
        if (userInfo) userInfo.textContent = currentUser.username;
        if (roleBadge) roleBadge.textContent = getRoleName(currentUser.role);
    } else {
        if (notLoggedIn) notLoggedIn.style.display = 'flex';
        if (loggedIn) loggedIn.style.display = 'none';
    }
}

function getRoleName(role) {
    const roles = {
        'secretary_general': '秘书长',
        'permanent_member': '常任理事国',
        'diplomat': '邦国外交官',
        'observer': '观察员',
        'peacekeeper': '维和部队'
    };
    return roles[role] || role;
}

function initLoginModal() {
    const loginModal = document.getElementById('loginModal');
    const showBtn = document.getElementById('showLoginBtn');
    const closeBtn = document.getElementById('closeLoginModal');
    const form = document.getElementById('loginForm');
    const cancelBtn = form.querySelector('.cancel-btn');

    showBtn?.addEventListener('click', () => loginModal.classList.add('active'));
    closeBtn?.addEventListener('click', () => loginModal.classList.remove('active'));
    cancelBtn?.addEventListener('click', () => loginModal.classList.remove('active'));
    
    loginModal?.addEventListener('click', (e) => {
        if (e.target === loginModal) loginModal.classList.remove('active');
    });

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('login-username')?.value || '';
        const password = document.getElementById('login-password')?.value || '';
        const messageEl = document.getElementById('loginFormMessage');
        
        // 显示全局加载动画
        const globalLoading = document.getElementById('globalLoading');
        if (globalLoading) {
            globalLoading.style.setProperty('display', 'flex', 'important');
        }
        if (messageEl) {
            messageEl.style.display = 'none';
        }

        try {
            const result = await apiRequest('/auth.php?action=login', {
                method: 'POST',
                body: JSON.stringify({ username, password })
            });

            if (result.error) {
                if (messageEl) {
                    showFormMessage(messageEl, result.error, 'error');
                }
            } else {
                currentUser = result.data.user;
                updateUserPanel();
                updateAdminVisibility();
                if (loginModal) {
                    loginModal.classList.remove('active');
                }
                if (form) {
                    form.reset();
                }
                showMessage('登录成功！', 'success');
            }
        } catch (error) {
            if (messageEl) {
                showFormMessage(messageEl, error.message || '登录失败，请检查网络连接', 'error');
            }
        } finally {
            // 隐藏全局加载动画
            const globalLoading = document.getElementById('globalLoading');
            if (globalLoading) {
                globalLoading.style.setProperty('display', 'none', 'important');
            }
        }
    });
}

function initRegisterModal() {
    const registerModal = document.getElementById('registerModal');
    const showBtn = document.getElementById('showRegisterBtn');
    const closeBtn = document.getElementById('closeRegisterModal');
    const form = document.getElementById('registerForm');
    const cancelBtn = form.querySelector('.cancel-btn');

    showBtn?.addEventListener('click', () => registerModal.classList.add('active'));
    closeBtn?.addEventListener('click', () => registerModal.classList.remove('active'));
    cancelBtn?.addEventListener('click', () => registerModal.classList.remove('active'));
    
    registerModal?.addEventListener('click', (e) => {
        if (e.target === registerModal) registerModal.classList.remove('active');
    });

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('register-username')?.value || '';
        const password = document.getElementById('register-password')?.value || '';
        const passwordConfirm = document.getElementById('register-password-confirm')?.value || '';
        const gameId = document.getElementById('register-game-id')?.value || '';
        const countryName = document.getElementById('register-country')?.value || '';
        const jhtUid = document.getElementById('register-jhtuid')?.value || '';
        const jhtVerifyCode = document.getElementById('register-jht-code')?.value || '';
        const messageEl = document.getElementById('registerFormMessage');

        if (password !== passwordConfirm) {
            showMessage('两次输入的密码不一致', 'error');
            return;
        }

        if (!jhtUid.trim() || !jhtVerifyCode.trim()) {
            showMessage('请填写简幻通UID和验证码', 'error');
            return;
        }

        // 显示全局加载动画
        const globalLoading = document.getElementById('globalLoading');
        if (globalLoading) {
            globalLoading.style.setProperty('display', 'flex', 'important');
        }
        if (messageEl) {
            messageEl.style.display = 'none';
        }

        try {
            // 验证玩家ID是否存在（带重试机制）
            const playerIdCheck = await verifyPlayerWithRetry(gameId);
            
            if (playerIdCheck.error) {
                throw new Error(playerIdCheck.error);
            }

            // 验证邦国是否存在（如果不是'流民'）
            let countryData = null;
            if (countryName && countryName.trim() !== '流民') {
                const countryCheck = await apiRequest('/countries.php?action=name&name=' + encodeURIComponent(countryName.trim()));
                
                if (countryCheck.error) {
                    throw new Error('所属邦国不存在');
                }
                
                countryData = countryCheck.data.country;
            }

            const payload = { username, password: '(隐藏)', game_id: gameId, country_name: countryName, jhtuid: jhtUid, verify_code: jhtVerifyCode };
            debugLog('Register', '提交注册', payload);
            const result = await apiRequest('/auth.php?action=register', {
                method: 'POST',
                body: JSON.stringify({ 
                    username, 
                    password, 
                    game_id: gameId, 
                    country_name: countryName,
                    jhtuid: jhtUid,
                    verify_code: jhtVerifyCode
                })
            });

            debugLog('Register', '接口返回', result);
            if (result.error) {
                showMessage(result.error, 'error');
            } else {
                if (registerModal) {
                    registerModal.classList.remove('active');
                }
                if (form) {
                    form.reset();
                }
                showMessage('注册成功！请登录', 'success');
                const loginModal = document.getElementById('loginModal');
                if (loginModal) {
                    loginModal.classList.add('active');
                }
            }
        } catch (error) {
            showMessage(error.message || '验证失败，请检查输入信息', 'error');
        } finally {
            // 隐藏全局加载动画
            const globalLoading = document.getElementById('globalLoading');
            if (globalLoading) {
                globalLoading.style.setProperty('display', 'none', 'important');
            }
        }
    });
}

function initLogout() {
    document.getElementById('logoutBtn')?.addEventListener('click', async () => {
        await apiRequest('/auth.php?action=logout', { method: 'DELETE' });
        currentUser = null;
        updateUserPanel();
        showMessage('已退出登录', 'success');
    });
}

function initNavigation() {
    const navLinks = document.querySelectorAll('.nav-link');
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            if (href.startsWith('#')) {
                e.preventDefault();
                const targetId = href.substring(1);
                const targetSection = document.getElementById(targetId);
                
                if (targetSection) {
                    targetSection.scrollIntoView({ behavior: 'smooth' });
                }
                
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });
    
    const path = window.location.pathname;
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        link.classList.remove('active');
        
        if (href && !href.startsWith('#')) {
            if (path.includes(href)) {
                link.classList.add('active');
            }
        } else if (href === '#home' && (path === '/' || path === '/index.html' || path.endsWith('index.html'))) {
            link.classList.add('active');
        }
    });
    
    window.addEventListener('scroll', function() {
        const path = window.location.pathname;
        if (path !== '/' && path !== '/index.html' && !path.endsWith('index.html')) {
            return;
        }
        
        const sections = document.querySelectorAll('.section');
        let current = '';
        
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            if (window.scrollY >= sectionTop - 200) {
                current = section.getAttribute('id');
            }
        });
        
        if (current) {
            navLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href && href.startsWith('#')) {
                    link.classList.remove('active');
                    if (link.getAttribute('data-section') === current || href === '#' + current) {
                        link.classList.add('active');
                    }
                }
            });
        }
    });
}

let currentHeadlineIndex = 0;
let headlineNewsInterval;
let totalHeadlineNews = 0;

function initNewsSlider() {
    // 检查是否是新的头条新闻轮播
    const headlineWrapper = document.getElementById('headlineNewsWrapper');
    if (headlineWrapper) {
        initHeadlineNewsSlider();
        return;
    }
    
    // 旧的轮播逻辑（兼容index.html）
    const carouselInner = document.getElementById('newsCarouselInner');
    const prevBtn = document.getElementById('prevNews');
    const nextBtn = document.getElementById('nextNews');
    const dotsContainer = document.getElementById('newsDots');
    
    if (!carouselInner) return;
    
    const newsItems = carouselInner.querySelectorAll('.news-carousel-item');
    if (!newsItems.length) return;
    
    // 创建指示点
    dotsContainer.innerHTML = '';
    newsItems.forEach((_, index) => {
        const dot = document.createElement('span');
        dot.classList.add('news-carousel-dot');
        if (index === 0) dot.classList.add('active');
        dot.addEventListener('click', () => goToNews(index));
        dotsContainer.appendChild(dot);
    });
    
    // 绑定按钮事件
    prevBtn?.addEventListener('click', () => prevNews());
    nextBtn?.addEventListener('click', () => nextNews());
    
    // 启动自动播放
    startAutoPlay();
    
    // 鼠标悬停暂停
    const carousel = document.getElementById('newsCarousel');
    carousel?.addEventListener('mouseenter', stopAutoPlay);
    carousel?.addEventListener('mouseleave', startAutoPlay);
}

function initHeadlineNewsSlider() {
    const cards = document.querySelectorAll('.headline-news-card');
    totalHeadlineNews = cards.length;
    
    if (totalHeadlineNews <= 1) return;
    
    // 启动自动播放
    startHeadlineAutoPlay();
    
    // 鼠标悬停暂停
    const wrapper = document.getElementById('headlineNewsWrapper');
    wrapper?.addEventListener('mouseenter', stopHeadlineAutoPlay);
    wrapper?.addEventListener('mouseleave', startHeadlineAutoPlay);
}

// 全局函数，供PHP生成的onclick调用
window.goToHeadlineNews = function(index) {
    const cards = document.querySelectorAll('.headline-news-card');
    const dots = document.querySelectorAll('.headline-news-dot');
    
    if (index < 0 || index >= cards.length) return;
    
    // 隐藏所有卡片
    cards.forEach((card, i) => {
        card.style.display = 'none';
        card.classList.remove('active');
        if (i === index) {
            card.style.display = 'flex';
            card.classList.add('active');
        }
    });
    
    // 更新指示点
    dots.forEach((dot, i) => {
        dot.classList.remove('active');
        if (i === index) dot.classList.add('active');
    });
    
    currentHeadlineIndex = index;
};

window.nextHeadlineNews = function() {
    const cards = document.querySelectorAll('.headline-news-card');
    const nextIndex = (currentHeadlineIndex + 1) % cards.length;
    goToHeadlineNews(nextIndex);
};

window.prevHeadlineNews = function() {
    const cards = document.querySelectorAll('.headline-news-card');
    const prevIndex = (currentHeadlineIndex - 1 + cards.length) % cards.length;
    goToHeadlineNews(prevIndex);
};

function startHeadlineAutoPlay() {
    stopHeadlineAutoPlay();
    headlineNewsInterval = setInterval(window.nextHeadlineNews, 5000);
}

function stopHeadlineAutoPlay() {
    if (headlineNewsInterval) {
        clearInterval(headlineNewsInterval);
        headlineNewsInterval = null;
    }
}

function goToNews(index) {
    const carouselInner = document.getElementById('newsCarouselInner');
    if (!carouselInner) return;
    
    const newsItems = carouselInner.querySelectorAll('.news-carousel-item');
    const dots = document.querySelectorAll('.news-carousel-dot');
    
    if (index < 0 || index >= newsItems.length) return;
    
    // 更新轮播位置
    carouselInner.style.transform = `translateX(-${index * 100}%)`;
    
    // 更新项目状态
    newsItems.forEach((item, i) => {
        item.classList.remove('active');
        if (i === index) item.classList.add('active');
    });
    
    // 更新指示点
    dots.forEach((dot, i) => {
        dot.classList.remove('active');
        if (i === index) dot.classList.add('active');
    });
    
    currentNewsIndex = index;
}

function nextNews() {
    const carouselInner = document.getElementById('newsCarouselInner');
    if (!carouselInner) return;
    
    const newsItems = carouselInner.querySelectorAll('.news-carousel-item');
    const nextIndex = (currentNewsIndex + 1) % newsItems.length;
    goToNews(nextIndex);
}

function prevNews() {
    const carouselInner = document.getElementById('newsCarouselInner');
    if (!carouselInner) return;
    
    const newsItems = carouselInner.querySelectorAll('.news-carousel-item');
    const prevIndex = (currentNewsIndex - 1 + newsItems.length) % newsItems.length;
    goToNews(prevIndex);
}

function startAutoPlay() {
    stopAutoPlay();
    newsInterval = setInterval(nextNews, 5000);
}

function stopAutoPlay() {
    if (newsInterval) {
        clearInterval(newsInterval);
        newsInterval = null;
    }
}

const voteData = {
    1: { for: 3, against: 1, abstain: 1 },
    2: { for: 0, against: 0, abstain: 0 }
};

const userVotes = {};

function initVotingSystem() {
    const voteBtns = document.querySelectorAll('.vote-btn');
    
    voteBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const proposalItem = this.closest('.proposal-item');
            const proposalId = proposalItem.getAttribute('data-id');
            const voteType = this.getAttribute('data-vote');
            
            if (userVotes[proposalId]) {
                showMessage('您已经投过票了！', 'warning', proposalItem);
                return;
            }
            
            userVotes[proposalId] = voteType;
            voteData[proposalId][voteType]++;
            
            updateVoteResults(proposalId);
            highlightVotedBtn(proposalItem, voteType);
            showMessage('投票成功！', 'success', proposalItem);
        });
    });
}

function updateVoteResults(proposalId) {
    const data = voteData[proposalId];
    const total = data.for + data.against + data.abstain;
    
    const forPercent = total > 0 ? (data.for / total) * 100 : 0;
    const againstPercent = total > 0 ? (data.against / total) * 100 : 0;
    const abstainPercent = total > 0 ? (data.abstain / total) * 100 : 0;
    
    const resultsContainer = document.getElementById(`voteResults${proposalId}`);
    if (resultsContainer) {
        const forBar = resultsContainer.querySelector('.vote-bar-fill.for');
        const againstBar = resultsContainer.querySelector('.vote-bar-fill.against');
        const abstainBar = resultsContainer.querySelector('.vote-bar-fill.abstain');
        
        forBar.style.width = forPercent + '%';
        forBar.querySelector('.vote-label').textContent = `赞成: ${data.for}`;
        
        againstBar.style.width = againstPercent + '%';
        againstBar.querySelector('.vote-label').textContent = `反对: ${data.against}`;
        
        abstainBar.style.width = abstainPercent + '%';
        abstainBar.querySelector('.vote-label').textContent = `弃权: ${data.abstain}`;
    }
}

function highlightVotedBtn(proposalItem, voteType) {
    const btns = proposalItem.querySelectorAll('.vote-btn');
    btns.forEach(btn => {
        btn.disabled = true;
        btn.style.opacity = '0.5';
    });
    
    const votedBtn = proposalItem.querySelector(`[data-vote="${voteType}"]`);
    if (votedBtn) {
        votedBtn.style.opacity = '1';
        votedBtn.classList.add('voted');
    }
}

function initComplaintForm() {
    const form = document.getElementById('complaintForm');
    const messageEl = document.getElementById('formMessage');
    const globalLoading = document.getElementById('globalLoading');
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!currentUser) {
            showMessage('请先登录！', 'error');
            document.getElementById('showLoginBtn').click();
            return;
        }
        
        const title = document.getElementById('complaint-title').value;
        const desc = document.getElementById('complaint-desc').value;
        const country = document.getElementById('complaint-country').value;
        const type = document.getElementById('complaint-type').value;
        
        if (!title.trim() || !desc.trim()) {
            showFormMessage(messageEl, '请填写所有必填项！', 'error');
            return;
        }
        
        // 显示全局加载动画
        if (globalLoading) {
            globalLoading.style.display = 'flex !important';
        }
        messageEl.style.display = 'none';
        
        try {
            const result = await apiRequest('/cases.php', {
                method: 'POST',
                body: JSON.stringify({ 
                    title, 
                    description: desc,
                    defendant_country_id: null
                })
            });
            
            if (result.error) {
                showFormMessage(messageEl, result.error, 'error');
            } else {
                showFormMessage(messageEl, '投诉已提交！我们会尽快处理。', 'success');
                form.reset();
                loadCases();
            }
        } catch (error) {
            showFormMessage(messageEl, error.message || '提交失败，请检查网络连接', 'error');
        } finally {
            // 隐藏全局加载动画
            if (globalLoading) {
                globalLoading.style.display = 'none';
            }
        }
    });
}

function initTradeForm() {
    const form = document.getElementById('tradeForm');
    const messageEl = document.getElementById('tradeFormMessage');
    const globalLoading = document.getElementById('globalLoading');
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!currentUser) {
            showMessage('请先登录！', 'error');
            document.getElementById('showLoginBtn').click();
            return;
        }
        
        const type = document.getElementById('trade-type-select').value;
        const item = document.getElementById('trade-item').value;
        const quantity = document.getElementById('trade-quantity').value;
        const exchange = document.getElementById('trade-exchange').value;
        
        if (!item.trim()) {
            showFormMessage(messageEl, '请填写物品名称！', 'error');
            return;
        }
        
        // 显示全局加载动画
        if (globalLoading) {
            globalLoading.style.display = 'flex !important';
        }
        messageEl.style.display = 'none';
        
        try {
            const result = await apiRequest('/trades.php', {
                method: 'POST',
                body: JSON.stringify({ 
                    type, 
                    item_name: item,
                    quantity: quantity || null,
                    exchange_method: exchange || null
                })
            });
            
            if (result.error) {
                showFormMessage(messageEl, result.error, 'error');
            } else {
                showFormMessage(messageEl, '交易信息已发布！', 'success');
                form.reset();
                loadTrades();
            }
        } catch (error) {
            showFormMessage(messageEl, error.message || '提交失败，请检查网络连接', 'error');
        } finally {
            // 隐藏全局加载动画
            if (globalLoading) {
                globalLoading.style.display = 'none';
            }
        }
    });
}

function addTradeItem(form) {
    const typeSelect = document.getElementById('trade-type-select');
    const item = document.getElementById('trade-item').value;
    const quantity = document.getElementById('trade-quantity').value;
    const exchange = document.getElementById('trade-exchange').value;
    
    const tradeList = document.getElementById('tradeList');
    const newItem = document.createElement('div');
    newItem.className = 'trade-item nes-container';
    
    const typeClass = typeSelect.value === 'buy' ? 'is-success' : 'is-error';
    const typeText = typeSelect.value === 'buy' ? '求购' : '出售';
    
    const today = new Date().toISOString().split('T')[0];
    
    newItem.innerHTML = `
        <div class="trade-header">
            <span class="trade-type nes-badge ${typeClass}">${typeText}</span>
            <span class="trade-country nes-badge is-warning">新发布</span>
        </div>
        <h4 class="trade-title">${item}${quantity ? ' ' + quantity : ''}</h4>
        <p class="trade-desc">${exchange || '价格面议'}</p>
        <div class="trade-meta">
            <span>发布时间：${today}</span>
            <span>联系方式：游戏内联系</span>
        </div>
    `;
    
    tradeList.insertBefore(newItem, tradeList.firstChild);
}

function showFormMessage(element, message, type) {
    // 检查message是否是翻译键
    if (translations[currentLang][message]) {
        message = translations[currentLang][message];
    }
    
    element.textContent = message;
    element.className = 'form-message ' + type;
    element.style.display = 'block';
    
    setTimeout(() => {
        element.style.display = 'none';
    }, 5000);
}

function showMessage(text, type, container) {
    // 检查text是否是翻译键
    if (translations[currentLang][text]) {
        text = translations[currentLang][text];
    }
    
    const msg = document.createElement('div');
    msg.className = `toast-message ${type}`;
    msg.textContent = text;
    msg.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 25px;
        background: ${type === 'success' ? '#4CAF50' : type === 'warning' ? '#FF9800' : '#f44336'};
        color: white;
        border-radius: 4px;
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(msg);
    
    // 错误信息显示10秒，成功信息显示3秒
    const displayTime = type === 'error' ? 10000 : 3000;
    
    setTimeout(() => {
        msg.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => msg.remove(), 300);
    }, displayTime);
}

function initBackToTop() {
    const btn = document.getElementById('backToTop');
    
    window.addEventListener('scroll', function() {
        if (window.scrollY > 500) {
            btn.classList.add('visible');
        } else {
            btn.classList.remove('visible');
        }
    });
    
    btn.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function initScrollEffects() {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in');
            }
        });
    }, observerOptions);
    
    const animatedElements = document.querySelectorAll('.nes-container, .country-card, .proposal-item, .news-item');
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(el);
    });
}



function initAdmin() {
    updateAdminVisibility();
    initAddUserForm();
    initNewsManageForm();
    initTimelineManageForm();
    initCountryManageForm();
}



async function updateAdminVisibility() {
    const path = window.location.pathname;
    
    if (path.includes('admin') || path.includes('admin.html')) {
        if (currentUser && currentUser.role === 'secretary_general') {
            await loadUsers();
            await loadCountriesForManage();
            await loadNewsForManage();
            await loadTimelineForManage();
        } else if (currentUser && currentUser.role !== 'secretary_general') {
            showMessage('只有秘书长才能访问管理后台！', 'error');
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 2000);
        }
    } else {
        const adminSections = document.querySelectorAll('.admin-section');
        const adminNavs = document.querySelectorAll('.admin-nav');
        
        if (currentUser && currentUser.role === 'secretary_general') {
            adminSections.forEach(s => s.classList.add('visible'));
            adminNavs.forEach(n => n.classList.add('visible'));
        } else {
            adminSections.forEach(s => s.classList.remove('visible'));
            adminNavs.forEach(n => n.classList.remove('visible'));
        }
    }
}

async function loadNewsForManage() {
    const result = await apiRequest('/news.php');
    const listEl = document.getElementById('newsManageList');
    
    if (!listEl) return;
    
    if (result.error) {
        listEl.innerHTML = `<p class="form-message error">${result.error}</p>`;
        return;
    }
    
    if (result.success && result.data.news) {
        listEl.innerHTML = result.data.news.map(news => `
            <div class="user-item nes-container" data-news-id="${news.id}">
                <div class="user-info-details">
                    <p><strong>${news.title}</strong></p>
                    <p><small>发布于: ${news.published_at} | ${news.is_headline ? '⭐ 头条' : ''}</small></p>
                </div>
                <div class="user-actions">
                    <button class="nes-btn is-primary" onclick="editNews(${news.id})">编辑</button>
                    <button class="nes-btn is-error" onclick="deleteNews(${news.id})">删除</button>
                </div>
            </div>
        `).join('');
    }
}

async function loadTimelineForManage() {
    const result = await apiRequest('/timeline.php');
    const listEl = document.getElementById('timelineManageList');
    
    if (!listEl) return;
    
    if (result.error) {
        listEl.innerHTML = `<p class="form-message error">${result.error}</p>`;
        return;
    }
    
    if (result.success && result.data.timeline) {
        listEl.innerHTML = result.data.timeline.map(item => `
            <div class="user-item nes-container" data-timeline-id="${item.id}">
                <div class="user-info-details">
                    <p><strong>${item.date}</strong> - ${item.title}</p>
                    <p><small>类型: ${getTimelineTypeName(item.event_type)}</small></p>
                </div>
                <div class="user-actions">
                    <button class="nes-btn is-primary" onclick="editTimeline(${item.id})">编辑</button>
                    <button class="nes-btn is-error" onclick="deleteTimeline(${item.id})">删除</button>
                </div>
            </div>
        `).join('');
    }
}

function getTimelineTypeName(type) {
    const types = {
        'war': '战争',
        'peace': '和平',
        'construction': '建设',
        'diplomatic': '外交',
        'other': '其他'
    };
    return types[type] || '其他';
}

function initNewsManageForm() {
    const form = document.getElementById('addNewsForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('news-title').value;
        const content = document.getElementById('news-content').value;
        const isHeadline = document.getElementById('news-headline').checked;
        const messageEl = document.getElementById('addNewsFormMessage');
        
        const result = await apiRequest('/news.php', {
            method: 'POST',
            body: JSON.stringify({ title, content, is_headline: isHeadline })
        });
        
        if (result.error) {
            showFormMessage(messageEl, result.error, 'error');
        } else {
            showFormMessage(messageEl, '新闻发布成功！', 'success');
            form.reset();
            loadNewsForManage();
            loadNews();
        }
    });
}

function initTimelineManageForm() {
    const form = document.getElementById('addTimelineForm');
    const today = new Date().toISOString().split('T')[0];
    const dateInput = document.getElementById('timeline-date');
    if (dateInput) dateInput.value = today;
    
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const date = document.getElementById('timeline-date').value;
        const title = document.getElementById('timeline-title').value;
        const description = document.getElementById('timeline-desc').value;
        const eventType = document.getElementById('timeline-type').value;
        const messageEl = document.getElementById('addTimelineFormMessage');
        
        const result = await apiRequest('/timeline.php', {
            method: 'POST',
            body: JSON.stringify({ date, title, description, event_type: eventType })
        });
        
        if (result.error) {
            showFormMessage(messageEl, result.error, 'error');
        } else {
            showFormMessage(messageEl, '事件添加成功！', 'success');
            form.reset();
            if (dateInput) dateInput.value = today;
            loadTimelineForManage();
            loadTimeline();
        }
    });
}

async function editNews(newsId) {
    const result = await apiRequest(`/news.php?id=${newsId}`);
    if (result.error) {
        showMessage(result.error, 'error');
        return;
    }
    
    const news = result.data.news;
    const newTitle = prompt('输入新标题:', news.title);
    const newContent = prompt('输入新内容:', news.content);
    const newIsHeadline = confirm('设为头条新闻?');
    
    const updateData = {};
    if (newTitle) updateData.title = newTitle;
    if (newContent) updateData.content = newContent;
    if (newIsHeadline !== undefined) updateData.is_headline = newIsHeadline;
    
    if (Object.keys(updateData).length > 0) {
        const updateResult = await apiRequest(`/news.php?id=${newsId}`, {
            method: 'PUT',
            body: JSON.stringify(updateData)
        });
        
        if (updateResult.error) {
            showMessage(updateResult.error, 'error');
        } else {
            showMessage('新闻更新成功！', 'success');
            loadNewsForManage();
            loadNews();
        }
    }
}

async function deleteNews(newsId) {
    if (!confirm('确定要删除这条新闻吗？')) return;
    
    const result = await apiRequest(`/news.php?id=${newsId}`, {
        method: 'DELETE'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage('新闻删除成功！', 'success');
        loadNewsForManage();
        loadNews();
    }
}

async function editTimeline(itemId) {
    const result = await apiRequest(`/timeline.php?id=${itemId}`);
    if (result.error) {
        showMessage(result.error, 'error');
        return;
    }
    
    const item = result.data.item;
    const newDate = prompt('输入新日期 (YYYY-MM-DD):', item.date);
    const newTitle = prompt('输入新标题:', item.title);
    const newDesc = prompt('输入新描述:', item.description || '');
    const newType = prompt('输入新类型 (war/peace/construction/diplomatic/other):', item.event_type);
    
    const updateData = {};
    if (newDate) updateData.date = newDate;
    if (newTitle) updateData.title = newTitle;
    if (newDesc !== undefined) updateData.description = newDesc;
    if (newType) updateData.event_type = newType;
    
    if (Object.keys(updateData).length > 0) {
        const updateResult = await apiRequest(`/timeline.php?id=${itemId}`, {
            method: 'PUT',
            body: JSON.stringify(updateData)
        });
        
        if (updateResult.error) {
            showMessage(updateResult.error, 'error');
        } else {
            showMessage('事件更新成功！', 'success');
            loadTimelineForManage();
            loadTimeline();
        }
    }
}

async function deleteTimeline(itemId) {
    if (!confirm('确定要删除这个事件吗？')) return;
    
    const result = await apiRequest(`/timeline.php?id=${itemId}`, {
        method: 'DELETE'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage('事件删除成功！', 'success');
        loadTimelineForManage();
        loadTimeline();
    }
}

async function loadCountriesForManage() {
    const result = await apiRequest('/countries.php?action=all');
    const listEl = document.getElementById('countryManageList');
    
    if (!listEl) return;
    
    if (result.error) {
        listEl.innerHTML = `<p class="form-message error">${result.error}</p>`;
        return;
    }
    
    if (result.success && result.data.countries) {
        listEl.innerHTML = result.data.countries.map(country => `
            <div class="user-item nes-container" data-country-id="${country.id}">
                <div class="user-info-details">
                    <p><strong>${country.name}</strong> ${country.is_active ? '' : '<span class="nes-badge is-error">已停用</span>'}</p>
                    <p><small>政体: ${getGovernmentTypeName(country.government_type)} | 成员: ${country.member_count}人 | 领地: ${country.territory_chunks || 0} Chunks</small></p>
                    <p><small>加入时间: ${country.joined_at.split(' ')[0]}</small></p>
                </div>
                <div class="user-actions">
                    <button class="nes-btn is-primary" onclick="refreshCountryData('${country.name}', ${country.id})">刷新数据</button>
                    <button class="nes-btn is-primary" onclick="editCountry(${country.id})">编辑</button>
                    <button class="nes-btn is-warning" onclick="toggleCountry(${country.id})">${country.is_active ? '停用' : '激活'}</button>
                    <button class="nes-btn is-error" onclick="deleteCountry(${country.id})">删除</button>
                </div>
            </div>
        `).join('');
    }
}

async function refreshCountryData(countryName, countryId) {
    try {
        // 使用API获取邦国数据
        console.log('开始刷新邦国数据:', countryName, countryId);
        const countryCheck = await apiRequest('/countries.php?action=name&name=' + encodeURIComponent(countryName));
        
        if (countryCheck.error) {
            console.error('获取邦国信息失败:', countryCheck.error);
            throw new Error('获取邦国信息失败');
        }
        
        console.log('获取到的邦国数据:', countryCheck.data.country);
        const countryData = countryCheck.data.country;
        
        // 从API数据中提取领地信息
        let territoryChunks = 0;
        if (countryData.territory) {
            const territoryMatch = countryData.territory.match(/\d+/);
            if (territoryMatch) {
                territoryChunks = parseInt(territoryMatch[0]);
                console.log('提取到的领地信息:', territoryChunks);
            }
        }
        
        // 准备更新数据
        const updateData = {
            territory_chunks: territoryChunks,
            declaration: countryData.declaration || '',
            population: countryData.population || 0
        };
        console.log('准备更新的数据:', updateData);
        
        // 确保至少有一个字段被更新
        let hasValidData = false;
        Object.keys(updateData).forEach(key => {
            if (updateData[key] !== null && updateData[key] !== undefined) {
                hasValidData = true;
            }
        });
        
        if (!hasValidData) {
            showMessage('没有可更新的数据', 'warning');
            return;
        }
        
        // 更新邦国数据
        const updateResult = await apiRequest(`/countries.php?id=${countryId}`, {
            method: 'PUT',
            body: JSON.stringify(updateData)
        });
        
        console.log('更新结果:', updateResult);
        if (updateResult.error) {
            showMessage(updateResult.error, 'error');
        } else {
            // 重新加载邦国列表
            await loadCountriesForManage();
            showMessage(`邦国 ${countryName} 数据已刷新`, 'success');
        }
    } catch (error) {
        console.error('刷新邦国数据失败:', error);
        showMessage(error.message || '刷新邦国数据失败', 'error');
    }
}

function initCountryManageForm() {
    const form = document.getElementById('addCountryForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('country-name').value;
        const governmentType = document.getElementById('country-government').value;
        const population = document.getElementById('country-population').value;
        const territoryChunks = document.getElementById('country-territory').value;
        const declaration = document.getElementById('country-declaration').value;
        const messageEl = document.getElementById('addCountryFormMessage');
        
        try {
            // 使用API获取邦国数据
            const countryCheck = await apiRequest('/countries.php?action=name&name=' + encodeURIComponent(name.trim()));
            
            if (countryCheck.error) {
                throw new Error('邦国不存在');
            }
            
            const countryData = countryCheck.data.country;
            
            // 从API数据中提取领地信息（如果需要）
            let territory = territoryChunks;
            if (countryData.territory) {
                // 尝试从领地字符串中提取数字
                const territoryMatch = countryData.territory.match(/\d+/);
                if (territoryMatch) {
                    territory = territoryMatch[0];
                }
            }
            
            const result = await apiRequest('/countries.php', {
                method: 'POST',
                body: JSON.stringify({ 
                    name, 
                    government_type: governmentType,
                    population: population ? parseInt(population) : null,
                    territory_chunks: territory ? parseInt(territory) : null,
                    declaration: declaration || countryData.declaration || ''
                })
            });
            
            if (result.error) {
                showFormMessage(messageEl, result.error, 'error');
            } else {
                showFormMessage(messageEl, '邦国创建成功！', 'success');
                form.reset();
                loadCountriesForManage();
                loadCountriesData();
            }
        } catch (error) {
            showFormMessage(messageEl, error.message || '获取邦国信息失败', 'error');
        }
    });
}

async function editCountry(countryId) {
    const result = await apiRequest(`/countries.php?id=${countryId}`);
    if (result.error) {
        showMessage(result.error, 'error');
        return;
    }
    
    const country = result.data.country;
    const newDeclaration = prompt('输入新宣言:', country.declaration || '');
    const newPopulation = prompt('输入新人口:', country.population || '');
    const newTerritory = prompt('输入新领地 Chunks:', country.territory_chunks || '');
    
    const updateData = {};
    if (newDeclaration !== null) updateData.declaration = newDeclaration;
    if (newPopulation) updateData.population = parseInt(newPopulation);
    if (newTerritory) updateData.territory_chunks = parseInt(newTerritory);
    
    if (Object.keys(updateData).length > 0) {
        const updateResult = await apiRequest(`/countries.php?id=${countryId}`, {
            method: 'PUT',
            body: JSON.stringify(updateData)
        });
        
        if (updateResult.error) {
            showMessage(updateResult.error, 'error');
        } else {
            showMessage('邦国更新成功！', 'success');
            loadCountriesForManage();
            loadCountriesData();
        }
    }
}

async function toggleCountry(countryId) {
    if (!confirm('确定要切换邦国状态吗？')) return;
    
    const result = await apiRequest(`/countries.php?id=${countryId}&action=toggle`, {
        method: 'PUT'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage(result.message || '状态切换成功！', 'success');
        loadCountriesForManage();
        loadCountriesData();
    }
}

async function deleteCountry(countryId) {
    if (!confirm('确定要删除这个邦国吗？这将同时移除所有成员的邦国关联！')) return;
    
    const result = await apiRequest(`/countries.php?id=${countryId}`, {
        method: 'DELETE'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage('邦国删除成功！', 'success');
        loadCountriesForManage();
        loadCountriesData();
    }
}

async function loadUsers() {
    const result = await apiRequest('/users.php');
    const userList = document.getElementById('userList');
    
    if (!userList) return;
    
    if (result.error) {
        userList.innerHTML = `<p class="form-message error">${result.error}</p>`;
        return;
    }
    
    if (result.success && result.data.users) {
        userList.innerHTML = result.data.users.map(user => `
            <div class="user-item nes-container" data-user-id="${user.id}">
                <div class="user-info-details">
                    <p><strong>${user.username}</strong> (${user.game_id})</p>
                    <p>角色: ${getRoleName(user.role)} | 邦国: ${user.country_name || '无'}</p>
                </div>
                <div class="user-actions">
                    <button class="nes-btn is-primary" onclick="editUser(${user.id})">编辑</button>
                    <button class="nes-btn is-error" onclick="deleteUser(${user.id})">删除</button>
                </div>
            </div>
        `).join('');
    }
}

function initAddUserForm() {
    const form = document.getElementById('addUserForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('admin-username').value;
        const password = document.getElementById('admin-password').value;
        const gameId = document.getElementById('admin-game-id').value;
        const countryName = document.getElementById('admin-country').value;
        const role = document.getElementById('admin-role').value;
        const messageEl = document.getElementById('addUserFormMessage');
        
        const result = await apiRequest('/users.php', {
            method: 'POST',
            body: JSON.stringify({ username, password, game_id: gameId, country_name: countryName, role })
        });
        
        if (result.error) {
            showFormMessage(messageEl, result.error, 'error');
        } else {
            showFormMessage(messageEl, '用户添加成功！', 'success');
            form.reset();
            loadUsers();
        }
    });
}

async function editUser(userId) {
    const result = await apiRequest(`/users.php?id=${userId}`);
    if (result.error) {
        showMessage(result.error, 'error');
        return;
    }
    
    const user = result.data.user;
    const newPassword = prompt('输入新密码（留空则不修改）:');
    
    // 角色选择选项
    const roleOptions = [
        { value: 'observer', label: '观察员' },
        { value: 'diplomat', label: '邦国外交官' },
        { value: 'peacekeeper', label: '维和部队' },
        { value: 'permanent_member', label: '常任理事国' },
        { value: 'secretary_general', label: '秘书长' }
    ];
    
    // 生成角色选择提示
    const rolePrompt = roleOptions.map((option, index) => `${index + 1}. ${option.label} (${option.value})`).join('\n');
    const roleChoice = prompt(`请选择新角色:\n${rolePrompt}\n\n当前角色: ${getRoleName(user.role)} (${user.role})\n\n请输入数字选择角色:`, '');
    
    let newRole = user.role;
    if (roleChoice && !isNaN(roleChoice) && roleChoice >= 1 && roleChoice <= roleOptions.length) {
        newRole = roleOptions[roleChoice - 1].value;
    }
    
    const newCountry = prompt('输入新邦国名称（留空则不修改）:', user.country_name || '');
    
    const updateData = {};
    if (newPassword) updateData.password = newPassword;
    if (newRole) updateData.role = newRole;
    if (newCountry !== undefined) updateData.country_name = newCountry;
    
    if (Object.keys(updateData).length > 0) {
        const updateResult = await apiRequest(`/users.php?id=${userId}`, {
            method: 'PUT',
            body: JSON.stringify(updateData)
        });
        
        if (updateResult.error) {
            showMessage(updateResult.error, 'error');
        } else {
            showMessage('用户更新成功！', 'success');
            loadUsers();
        }
    }
}

async function deleteUser(userId) {
    if (!confirm('确定要删除这个用户吗？')) return;
    
    const result = await apiRequest(`/users.php?id=${userId}`, {
        method: 'DELETE'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage('用户删除成功！', 'success');
        loadUsers();
    }
}

async function loadDynamicData() {
    const path = window.location.pathname;
    if (path === '/' || path === '/index.html' || path === '/index.php' || path.endsWith('index.html') || path.endsWith('index.php')) {
        await loadNews();
        await loadCountriesData();
        await loadProposalsData();
        await loadConventions();
        await loadCases();
        await loadTimeline();
        await loadServerInfo();
    } else if (path.includes('services.php') || path.includes('services.html')) {
        // 服务列表已由 PHP 直接生成，无需 JS 渲染
        // 仅加载管理员编辑功能（如果存在）
        await loadAdminFeatures();
    } else if (path.includes('admin.html')) {
        if (currentUser && currentUser.role === 'secretary_general') {
            await loadUsers();
            await loadCountriesForManage();
            await loadNewsForManage();
            await loadTimelineForManage();
        }
    }
}

// 加载管理员功能（用于服务页面，不覆盖 PHP 渲染的内容）
async function loadAdminFeatures() {
    // 仅显示编辑区域（仅秘书长可见），不覆盖服务列表
    const editSection = document.getElementById('editSection');
    if (editSection) {
        if (currentUser && currentUser.role === 'secretary_general') {
            editSection.classList.add('visible');
        } else {
            editSection.classList.remove('visible');
        }
    }
}

// 保留 loadServices 函数供其他可能的使用场景，但不再在页面加载时自动调用
async function loadServices() {
    const result = await apiRequest('/services.php');
    const services = result.success && result.data.services ? result.data.services : [
        { id: 1, name: '交易系统', url: 'https://trade.bgjq.top' },
        { id: 2, name: '在线地图', url: 'http://bgjq.simpfun.cn' }
    ];

    const list = document.getElementById('serviceList');
    if (list) {
        list.innerHTML = services.map(service => `
            <a href="${service.url}" target="_blank" class="service-item-link">
                <div class="service-item">
                    <div class="service-name">${service.name}</div>
                    <div class="service-url">${service.url}</div>
                    ${currentUser && currentUser.role === 'secretary_general' ? `
                        <div class="service-actions">
                            <button class="nes-btn is-primary" onclick="event.stopPropagation(); editService(${service.id})">编辑</button>
                            <button class="nes-btn is-error" onclick="event.stopPropagation(); deleteService(${service.id})">删除</button>
                        </div>
                    ` : ''}
                </div>
            </a>
        `).join('');
    }

    // 显示编辑区域（仅秘书长可见）
    const editSection = document.getElementById('editSection');
    if (editSection) {
        if (currentUser && currentUser.role === 'secretary_general') {
            editSection.classList.add('visible');
        } else {
            editSection.classList.remove('visible');
        }
    }
}

function initServiceForm() {
    const form = document.getElementById('addServiceForm');
    const messageEl = document.getElementById('addServiceFormMessage');
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!currentUser) {
            showMessage('请先登录！', 'error');
            document.getElementById('showLoginBtn').click();
            return;
        }
        
        if (currentUser.role !== 'secretary_general') {
            showMessage('只有秘书长才能编辑服务！', 'error');
            return;
        }
        
        const name = document.getElementById('service-name').value;
        const url = document.getElementById('service-url').value;
        
        if (!name.trim() || !url.trim()) {
            showFormMessage(messageEl, '请填写所有必填项！', 'error');
            return;
        }
        
        // 调用API添加服务
        const result = await apiRequest('/services.php', {
            method: 'POST',
            body: JSON.stringify({ name, url })
        });
        
        if (result.error) {
            showFormMessage(messageEl, result.error, 'error');
        } else {
            showFormMessage(messageEl, '服务添加成功！', 'success');
            form.reset();
            await loadServices();
        }
    });
}

async function editService(serviceId) {
    const newName = prompt('输入新服务名称:');
    const newUrl = prompt('输入新服务网址:');
    
    if (newName && newUrl) {
        // 调用API更新服务
        const result = await apiRequest(`/services.php?id=${serviceId}`, {
            method: 'PUT',
            body: JSON.stringify({ name: newName, url: newUrl })
        });
        
        if (result.error) {
            showMessage(result.error, 'error');
        } else {
            showMessage('服务更新成功！', 'success');
            await loadServices();
        }
    }
}

async function deleteService(serviceId) {
    if (!confirm('确定要删除这个服务吗？')) return;
    
    // 调用API删除服务
    const result = await apiRequest(`/services.php?id=${serviceId}`, {
        method: 'DELETE'
    });
    
    if (result.error) {
        showMessage(result.error, 'error');
    } else {
        showMessage('服务删除成功！', 'success');
        await loadServices();
    }
}

async function loadServerInfo() {
    const result = await apiRequest('/server.php');
    if (result.success && result.data.server) {
        const server = result.data.server;
        const onlineCountEl = document.getElementById('onlineCount');
        const onlineRepsEl = document.getElementById('onlineReps');
        const serverInfoSection = document.querySelector('#home .nes-container.with-title:nth-child(3)');
        
        if (onlineCountEl) {
            const onlineCount = server.online_players.filter(p => p.online !== false).length;
            onlineCountEl.textContent = `(${onlineCount}人在线)`;
        }
        
        if (onlineRepsEl) {
            if (server.online_players.length > 0) {
                onlineRepsEl.innerHTML = server.online_players.map(player => `
                    <div class="rep-item nes-container ${player.online === false ? 'offline' : ''}">
                        <span class="rep-country nes-badge ${player.country === '养老院' ? 'is-primary' : 'is-warning'}">${player.country}</span>
                        <span class="rep-name">${player.name}</span>
                        ${player.online !== false ? '<span class="rep-status nes-icon is-small star"></span>' : '<span class="rep-status"></span>'}
                    </div>
                `).join('');
            } else {
                onlineRepsEl.innerHTML = '<div class="rep-item nes-container offline"><span class="rep-name">暂无在线代表</span></div>';
            }
        }
        
        // 在在线代表部分显示MOTD信息
        if (serverInfoSection && server.motd) {
            const motdEl = document.createElement('div');
            motdEl.className = 'server-motd';
            motdEl.innerHTML = `<p class="nes-text is-primary">${server.motd}</p>`;
            
            // 检查是否已经存在MOTD元素
            const existingMotd = serverInfoSection.querySelector('.server-motd');
            if (existingMotd) {
                existingMotd.replaceWith(motdEl);
            } else {
                // 在标题下方插入MOTD
                const titleEl = serverInfoSection.querySelector('.title');
                if (titleEl) {
                    titleEl.insertAdjacentElement('afterend', motdEl);
                }
            }
        }
    }
}

async function loadNews() {
    // 如果页面已经有服务端渲染的新闻，不需要重新加载
    const headlineWrapper = document.getElementById('headlineNewsWrapper');
    if (headlineWrapper) {
        // 服务端已经渲染了新闻，只需要初始化轮播
        initHeadlineNewsSlider();
        return;
    }
    
    // 旧的逻辑（兼容index.html）
    const result = await apiRequest('/news.php');
    if (result.success && result.data.news) {
        const carouselInner = document.getElementById('newsCarouselInner');
        const dotsContainer = document.getElementById('newsDots');
        if (carouselInner && dotsContainer && result.data.news.length > 0) {
            carouselInner.innerHTML = result.data.news.map((news, index) => {
                const date = news.published_at.split(' ')[0];
                return `
                <div class="news-carousel-item ${index === 0 ? 'active' : ''}">
                    <div class="news-carousel-content">
                        <span class="news-carousel-date">${date}</span>
                        <h4 class="news-carousel-title">${news.title}</h4>
                        <p class="news-carousel-desc">${news.content}</p>
                    </div>
                </div>
            `}).join('');
            
            // 重新初始化轮播
            initNewsSlider();
        }
    }
}

async function loadCountriesData() {
    const result = await apiRequest('/countries.php');
    if (result.success && result.data.countries) {
        const grid = document.getElementById('countryGrid');
        if (grid) {
            grid.innerHTML = result.data.countries.map(country => `
                <div class="country-card nes-container with-title">
                    <h3 class="title">${country.name}</h3>
                    <div class="country-info">
                        <div class="country-flag">
                            <i class="nes-icon trophy is-large"></i>
                        </div>
                        <div class="country-details">
                            <p><strong>${translations[currentLang].government_type}：</strong>${getGovernmentTypeName(country.government_type)}</p>
                            <p><strong>${translations[currentLang].population}：</strong>${country.population || country.member_count || 0}${currentLang === 'zh' ? '人' : ''}</p>
                            <p><strong>${translations[currentLang].territory}：</strong>${country.territory_chunks || 0} Chunk</p>
                            <p><strong>${translations[currentLang].diplomacy}：</strong><span class="relation-neutral">${translations[currentLang].neutral}</span></p>
                        </div>
                    </div>
                    <p class="country-desc">${country.declaration || translations[currentLang].no_declaration}</p>
                </div>
            `).join('');
        }
    }
}

function getGovernmentTypeName(type) {
    const types = {
        zh: {
            'monarchy': '君主制',
            'democracy': '民主制',
            'guild': '公会制',
            'other': '其他'
        },
        en: {
            'monarchy': 'Monarchy',
            'democracy': 'Democracy',
            'guild': 'Guild System',
            'other': 'Other'
        }
    };
    return types[currentLang][type] || types[currentLang].other;
}

async function loadProposalsData() {
    const result = await apiRequest('/proposals.php');
    if (result.success && result.data.proposals) {
        const list = document.getElementById('proposalList');
        if (list) {
            list.innerHTML = result.data.proposals.map(proposal => {
                const votes = proposal.votes || { for: 0, against: 0, abstain: 0 };
                const total = votes.for + votes.against + votes.abstain;
                const forPercent = total > 0 ? (votes.for / total) * 100 : 0;
                const againstPercent = total > 0 ? (votes.against / total) * 100 : 0;
                const abstainPercent = total > 0 ? (votes.abstain / total) * 100 : 0;
                
                return `
                    <div class="proposal-item nes-container" data-id="${proposal.id}">
                        <div class="proposal-header">
                            <span class="proposal-type nes-badge is-warning">${getProposalType(proposal.type)}</span>
                            <span class="proposal-status nes-badge ${proposal.status === 'voting' ? 'is-success' : ''}">${getProposalStatus(proposal.status)}</span>
                        </div>
                        <h4 class="proposal-title">${proposal.title}</h4>
                        <p class="proposal-desc">${proposal.description}</p>
                        <div class="proposal-meta">
                            <span>${translations[currentLang].proposal_country}：${proposal.country_name || '未知'}</span>
                            <span>${translations[currentLang].proposer}：${proposal.proposer_name || '未知'}</span>
                        </div>
                        <div class="vote-results" id="voteResults${proposal.id}">
                            <div class="vote-bar">
                                <div class="vote-bar-fill for" style="width: ${forPercent}%;">
                                    <span class="vote-label">${translations[currentLang].for_vote}: ${votes.for}</span>
                                </div>
                                <div class="vote-bar-fill against" style="width: ${againstPercent}%;">
                                    <span class="vote-label">${translations[currentLang].against_vote}: ${votes.against}</span>
                                </div>
                                <div class="vote-bar-fill abstain" style="width: ${abstainPercent}%;">
                                    <span class="vote-label">${translations[currentLang].abstain_vote}: ${votes.abstain}</span>
                                </div>
                            </div>
                        </div>
                        ${proposal.status === 'voting' ? `
                            <div class="proposal-vote">
                                <button class="nes-btn is-primary vote-btn" data-vote="for">${translations[currentLang].for_vote}</button>
                                <button class="nes-btn is-error vote-btn" data-vote="against">${translations[currentLang].against_vote}</button>
                                <button class="nes-btn vote-btn" data-vote="abstain">${translations[currentLang].abstain_vote}</button>
                            </div>
                        ` : ''}
                    </div>
                `;
            }).join('');
        }
    }
}

function getProposalType(type) {
    const types = {
        zh: {
            'territory': '领土仲裁',
            'defense': '共同防御',
            'trade': '贸易协定',
            'embargo': '禁运令',
            'event': '活动发起',
            'other': '其他'
        },
        en: {
            'territory': 'Territorial Arbitration',
            'defense': 'Collective Defense',
            'trade': 'Trade Agreement',
            'embargo': 'Embargo',
            'event': 'Event Initiation',
            'other': 'Other'
        }
    };
    return types[currentLang][type] || types[currentLang].other;
}

function getProposalStatus(status) {
    const statuses = {
        zh: {
            'draft': '草稿',
            'voting': '进行中',
            'passed': '已通过',
            'rejected': '已拒绝'
        },
        en: {
            'draft': 'Draft',
            'voting': 'Voting',
            'passed': 'Passed',
            'rejected': 'Rejected'
        }
    };
    return statuses[currentLang][status] || status;
}

async function loadConventions() {
    const result = await apiRequest('/conventions.php');
    if (result.success && result.data.conventions) {
        const list = document.querySelector('.convention-list');
        if (list) {
            list.innerHTML = result.data.conventions.map(convention => `
                <div class="convention-item nes-container">
                    <h4><i class="nes-icon is-small star"></i> ${convention.title}</h4>
                    <p>${convention.content}</p>
                    <span class="convention-date">生效日期：${convention.enacted_at.split(' ')[0]}</span>
                </div>
            `).join('');
        }
    }
}

async function loadCases() {
    const result = await apiRequest('/cases.php');
    if (result.success && result.data.cases) {
        const hearingList = document.querySelector('.court-list');
        const archiveList = document.querySelector('.archive-list');
        
        if (hearingList) {
            const hearingCases = result.data.cases.filter(c => ['filed', 'hearing'].includes(c.status));
            hearingList.innerHTML = hearingCases.length > 0 ? hearingCases.map(c => `
                <div class="court-item nes-container">
                    <div class="court-header">
                        <span class="court-number">${c.case_number}</span>
                        <span class="court-status nes-badge is-warning">${getCaseStatus(c.status)}</span>
                    </div>
                    <h4 class="court-title">${c.title}</h4>
                    <p class="court-desc">${c.description}</p>
                </div>
            `).join('') : `
                <div class="court-item nes-container">
                    <div class="court-header">
                        <span class="court-number">（暂无案件）</span>
                    </div>
                    <h4 class="court-title">暂无正在审理的案件</h4>
                    <p class="court-desc">国际法庭目前没有正在审理的案件。</p>
                </div>
            `;
        }
        
        if (archiveList) {
            const closedCases = result.data.cases.filter(c => c.status === 'closed');
            archiveList.innerHTML = closedCases.length > 0 ? closedCases.map(c => `
                <div class="archive-item nes-container">
                    <h4>${c.case_number} - ${c.title}</h4>
                    <p>${c.judgment || '暂无判决'}</p>
                </div>
            `).join('') : `
                <div class="archive-item nes-container">
                    <h4>（暂无记录）</h4>
                    <p>历史仲裁记录将在此处存档，作为日后类似案件的判例。</p>
                </div>
            `;
        }
    }
}

function getCaseStatus(status) {
    const statuses = {
        'filed': '已立案',
        'hearing': '审理中',
        'judged': '已判决',
        'closed': '已结案'
    };
    return statuses[status] || status;
}

async function loadTrades() {
    const result = await apiRequest('/trades.php');
    if (result.success && result.data.trades) {
        const list = document.getElementById('tradeList');
        if (list) {
            list.innerHTML = result.data.trades.map(trade => `
                <div class="trade-item nes-container">
                    <div class="trade-header">
                        <span class="trade-type nes-badge ${trade.type === 'buy' ? 'is-success' : 'is-error'}">${trade.type === 'buy' ? '求购' : '出售'}</span>
                        <span class="trade-country nes-badge is-primary">${trade.country_name || '未知'}</span>
                    </div>
                    <h4 class="trade-title">${trade.item_name}${trade.quantity ? ' ' + trade.quantity : ''}</h4>
                    <p class="trade-desc">${trade.exchange_method || '价格面议'}</p>
                    <div class="trade-meta">
                        <span>发布时间：${trade.created_at.split(' ')[0]}</span>
                        <span>发布者：${trade.poster_name || '未知'}</span>
                    </div>
                </div>
            `).join('');
        }
    }
}

function initSponsorButton() {
    const sponsorBtn = document.getElementById('sponsorBtn');
    const sponsorModal = document.getElementById('sponsorModal');
    const closeBtn = document.getElementById('closeSponsorModal');
    
    if (sponsorBtn && sponsorModal && closeBtn) {
        sponsorBtn.addEventListener('click', () => {
            sponsorModal.classList.add('active');
        });
        
        closeBtn.addEventListener('click', () => {
            sponsorModal.classList.remove('active');
        });
        
        sponsorModal.addEventListener('click', (e) => {
            if (e.target === sponsorModal) {
                sponsorModal.classList.remove('active');
            }
        });
    }
}

async function loadTimeline() {
    const result = await apiRequest('/timeline.php');
    if (result.success && result.data.timeline) {
        const timeline = document.querySelector('.timeline');
        if (timeline) {
            timeline.innerHTML = result.data.timeline.map(event => `
                <div class="timeline-item">
                    <div class="timeline-marker"></div>
                    <div class="timeline-content nes-container">
                        <span class="timeline-date">${event.date}</span>
                        <h4>${event.title}</h4>
                        <p>${event.description || ''}</p>
                    </div>
                </div>
            `).join('');
        }
    }
}

function updateUserPanel() {
    const notLoggedIn = document.getElementById('userNotLoggedIn');
    const loggedIn = document.getElementById('userLoggedIn');
    const userInfo = document.getElementById('userInfo');
    const roleBadge = document.getElementById('roleBadge');
    
    if (currentUser) {
        notLoggedIn.style.display = 'none';
        loggedIn.style.display = 'flex';
        userInfo.textContent = currentUser.username;
        roleBadge.textContent = getRoleName(currentUser.role);
        updateAdminVisibility();
        checkPageAuth();
    } else {
        notLoggedIn.style.display = 'flex';
        loggedIn.style.display = 'none';
        updateAdminVisibility();
    }
}



