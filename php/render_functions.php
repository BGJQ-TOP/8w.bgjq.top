<?php
require_once __DIR__ . '/config.php';

function getNewsData($db) {
    $stmt = $db->prepare(" 
        SELECT n.*, 
               u.username as author_name
        FROM news n
        LEFT JOIN users u ON n.author_id = u.id
        ORDER BY n.published_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCountriesData($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               COUNT(u.id) as member_count
        FROM countries c
        LEFT JOIN users u ON c.id = u.country_id
        WHERE c.is_active = TRUE
        GROUP BY c.id
        ORDER BY c.joined_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getTimelineData($db) {
    $stmt = $db->prepare(" 
        SELECT * 
        FROM timeline 
        ORDER BY date DESC, created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getProposalsData($db) {
    $stmt = $db->prepare(" 
        SELECT p.*, 
               c.name as country_name,
               u.username as proposer_name
        FROM proposals p
        JOIN users u ON p.proposer_id = u.id
        LEFT JOIN countries c ON p.country_id = c.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $proposals = $stmt->fetchAll();

    foreach ($proposals as &$proposal) {
        $proposal['votes'] = getVoteStats($db, $proposal['id']);
    }

    return $proposals;
}

function getVoteStats($db, $proposalId) {
    $stmt = $db->prepare(" 
        SELECT 
            vote, 
            COUNT(*) as count
        FROM votes 
        WHERE proposal_id = ?
        GROUP BY vote
    ");
    $stmt->execute([$proposalId]);
    $results = $stmt->fetchAll();

    $stats = ['for' => 0, 'against' => 0, 'abstain' => 0];
    foreach ($results as $row) {
        $stats[$row['vote']] = (int)$row['count'];
    }
    return $stats;
}

function getConventionsData($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               u.username as enacted_by_name
        FROM conventions c
        LEFT JOIN users u ON c.enacted_by_user_id = u.id
        ORDER BY c.enacted_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCasesData($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               p.username as plaintiff_name,
               co.name as defendant_country_name
        FROM cases c
        LEFT JOIN users p ON c.plaintiff_id = p.id
        LEFT JOIN countries co ON c.defendant_country_id = co.id
        ORDER BY c.filed_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getGovernmentTypeName($type, $lang = 'zh') {
    $types = [
        'zh' => [
            'monarchy' => '君主制',
            'democracy' => '民主制',
            'guild' => '公会制',
            'other' => '其他'
        ],
        'en' => [
            'monarchy' => 'Monarchy',
            'democracy' => 'Democracy',
            'guild' => 'Guild System',
            'other' => 'Other'
        ]
    ];
    return $types[$lang][$type] ?? $types[$lang]['other'];
}

function getProposalType($type, $lang = 'zh') {
    $types = [
        'zh' => [
            'territory' => '领土仲裁',
            'defense' => '共同防御',
            'trade' => '贸易协定',
            'embargo' => '禁运令',
            'event' => '活动发起',
            'other' => '其他'
        ],
        'en' => [
            'territory' => 'Territorial Arbitration',
            'defense' => 'Collective Defense',
            'trade' => 'Trade Agreement',
            'embargo' => 'Embargo',
            'event' => 'Event Initiation',
            'other' => 'Other'
        ]
    ];
    return $types[$lang][$type] ?? $types[$lang]['other'];
}

function getProposalStatus($status, $lang = 'zh') {
    $statuses = [
        'zh' => [
            'draft' => '草稿',
            'voting' => '进行中',
            'passed' => '已通过',
            'rejected' => '已拒绝'
        ],
        'en' => [
            'draft' => 'Draft',
            'voting' => 'Voting',
            'passed' => 'Passed',
            'rejected' => 'Rejected'
        ]
    ];
    return $statuses[$lang][$status] ?? $status;
}

function getCaseStatus($status) {
    $statuses = [
        'filed' => '已立案',
        'hearing' => '审理中',
        'judged' => '已判决',
        'closed' => '已结案'
    ];
    return $statuses[$status] ?? $status;
}

function renderNews($news, $index) {
    $date = explode(' ', $news['published_at'])[0];
    $activeClass = $index === 0 ? 'active' : '';
    return <<<HTML
        <div class="news-item nes-container {$activeClass}">
            <span class="news-date">{$date}</span>
            <h4>{$news['title']}</h4>
            <p>{$news['content']}</p>
        </div>
HTML;
}

function renderNewsCarousel($news, $index) {
    $date = explode(' ', $news['published_at'])[0];
    $activeClass = $index === 0 ? 'active' : '';
    return <<<HTML
        <div class="news-carousel-item {$activeClass}">
            <div class="news-carousel-content">
                <span class="news-carousel-date">📅 {$date}</span>
                <h4 class="news-carousel-title">{$news['title']}</h4>
                <p class="news-carousel-desc">{$news['content']}</p>
            </div>
        </div>
HTML;
}

function renderHeadlineNewsCard($news, $index, $total) {
    $dateParts = explode(' ', $news['published_at']);
    $date = $dateParts[0];
    list($year, $month, $day) = explode('-', $date);
    $activeClass = $index === 0 ? 'active' : '';
    $displayStyle = $index === 0 ? 'display: flex;' : 'display: none;';
    
    return <<<HTML
        <div class="headline-news-card {$activeClass}" data-index="{$index}" style="{$displayStyle}">
            <div class="headline-news-date">
                <span class="day">{$day}</span>
                <span class="month">{$month}月</span>
                <span class="year">{$year}</span>
            </div>
            <div class="headline-news-content">
                <h4 class="headline-news-title">{$news['title']}</h4>
                <p class="headline-news-desc">{$news['content']}</p>
            </div>
        </div>
HTML;
}

function renderNewsListItem($news) {
    $dateParts = explode(' ', $news['published_at']);
    $date = $dateParts[0];
    list($year, $month, $day) = explode('-', $date);
    $isHeadline = $news['is_headline'] ?? false;
    $badge = $isHeadline ? '<span class="nes-badge is-warning" style="position: absolute; top: -8px; right: -8px;"><span class="is-warning">⭐ 头条</span></span>' : '';
    $author = $news['author_name'] ?? '社区';
    
    return <<<HTML
        <div class="nes-container with-title news-list-item-nes" style="position: relative; margin-bottom: 20px;">
            {$badge}
            <h3 class="title" style="font-size: 0.9rem;">{$year}年{$month}月{$day}日</h3>
            <div class="news-nes-content">
                <h4 class="news-nes-title" style="margin-bottom: 10px; color: var(--un-blue);">{$news['title']}</h4>
                <p class="news-nes-desc" style="line-height: 1.6;">{$news['content']}</p>
                <div class="news-nes-meta" style="margin-top: 15px; padding-top: 10px; border-top: 2px dashed #ccc; font-size: 0.8rem; color: #666;">
                    <i class="nes-icon is-small star"></i> 发布者：{$author}
                </div>
            </div>
        </div>
HTML;
}

function renderCountry($country, $lang = 'zh') {
    $governmentType = getGovernmentTypeName($country['government_type'], $lang);
    // 优先使用population字段，如果为空或0则使用member_count
    $population = !empty($country['population']) ? intval($country['population']) : (intval($country['member_count'] ?? 0));
    $territory = intval($country['territory_chunks'] ?? 0);
    $declaration = $country['declaration'] ?? ($lang === 'zh' ? '暂无宣言' : 'No Declaration');
    $neutralText = $lang === 'zh' ? '中立' : 'Neutral';
    $governmentLabel = $lang === 'zh' ? '政体' : 'Government';
    $populationLabel = $lang === 'zh' ? '人口' : 'Population';
    $populationUnit = $lang === 'zh' ? '人' : '';
    $territoryLabel = $lang === 'zh' ? '领地' : 'Territory';
    $diplomacyLabel = $lang === 'zh' ? '外交' : 'Diplomacy';

    return <<<HTML
        <div class="country-card nes-container with-title">
            <h3 class="title">{$country['name']}</h3>
            <div class="country-info">
                <div class="country-flag">
                    <i class="nes-icon trophy is-large"></i>
                </div>
                <div class="country-details">
                    <p><strong>{$governmentLabel}：</strong>{$governmentType}</p>
                    <p><strong>{$populationLabel}：</strong>{$population}{$populationUnit}</p>
                    <p><strong>{$territoryLabel}：</strong>{$territory} Chunk</p>
                    <p><strong>{$diplomacyLabel}：</strong><span class="relation-neutral">{$neutralText}</span></p>
                </div>
            </div>
            <p class="country-desc">{$declaration}</p>
        </div>
HTML;
}

function renderTimelineItem($event) {
    return <<<HTML
        <div class="timeline-item">
            <div class="timeline-marker"></div>
            <div class="timeline-content nes-container">
                <span class="timeline-date">{$event['date']}</span>
                <h4>{$event['title']}</h4>
                <p>{$event['description']}</p>
            </div>
        </div>
HTML;
}

function renderProposal($proposal, $lang = 'zh') {
    $votes = $proposal['votes'] ?? ['for' => 0, 'against' => 0, 'abstain' => 0];
    $total = $votes['for'] + $votes['against'] + $votes['abstain'];
    $forPercent = $total > 0 ? ($votes['for'] / $total) * 100 : 0;
    $againstPercent = $total > 0 ? ($votes['against'] / $total) * 100 : 0;
    $abstainPercent = $total > 0 ? ($votes['abstain'] / $total) * 100 : 0;
    
    $proposalType = getProposalType($proposal['type'], $lang);
    $proposalStatus = getProposalStatus($proposal['status'], $lang);
    $statusClass = $proposal['status'] === 'voting' ? 'is-success' : '';
    $countryLabel = $lang === 'zh' ? '提案国' : 'Proposing Country';
    $proposerLabel = $lang === 'zh' ? '提案人' : 'Proposer';
    $forLabel = $lang === 'zh' ? '赞成' : 'For';
    $againstLabel = $lang === 'zh' ? '反对' : 'Against';
    $abstainLabel = $lang === 'zh' ? '弃权' : 'Abstain';
    
    $voteButtons = '';
    if ($proposal['status'] === 'voting') {
        $voteButtons = <<<HTML
            <div class="proposal-vote">
                <button class="nes-btn is-primary vote-btn" data-vote="for">{$forLabel}</button>
                <button class="nes-btn is-error vote-btn" data-vote="against">{$againstLabel}</button>
                <button class="nes-btn vote-btn" data-vote="abstain">{$abstainLabel}</button>
            </div>
HTML;
    }
    
    return <<<HTML
        <div class="proposal-item nes-container" data-id="{$proposal['id']}">
            <div class="proposal-header">
                <span class="proposal-type nes-badge is-warning">{$proposalType}</span>
                <span class="proposal-status nes-badge {$statusClass}">{$proposalStatus}</span>
            </div>
            <h4 class="proposal-title">{$proposal['title']}</h4>
            <p class="proposal-desc">{$proposal['description']}</p>
            <div class="proposal-meta">
                <span>{$countryLabel}：{$proposal['country_name']}</span>
                <span>{$proposerLabel}：{$proposal['proposer_name']}</span>
            </div>
            <div class="vote-results" id="voteResults{$proposal['id']}">
                <div class="vote-bar">
                    <div class="vote-bar-fill for" style="width: {$forPercent}%;">
                        <span class="vote-label">{$forLabel}: {$votes['for']}</span>
                    </div>
                    <div class="vote-bar-fill against" style="width: {$againstPercent}%;">
                        <span class="vote-label">{$againstLabel}: {$votes['against']}</span>
                    </div>
                    <div class="vote-bar-fill abstain" style="width: {$abstainPercent}%;">
                        <span class="vote-label">{$abstainLabel}: {$votes['abstain']}</span>
                    </div>
                </div>
            </div>
            {$voteButtons}
        </div>
HTML;
}

function renderConvention($convention, $lang = 'zh') {
    $dateLabel = $lang === 'zh' ? '生效日期' : 'Effective Date';
    $date = explode(' ', $convention['enacted_at'])[0];
    return <<<HTML
        <div class="convention-item nes-container">
            <h4><i class="nes-icon is-small star"></i> {$convention['title']}</h4>
            <p>{$convention['content']}</p>
            <span class="convention-date">{$dateLabel}：{$date}</span>
        </div>
HTML;
}

function renderHearingCase($case, $lang = 'zh') {
    $status = getCaseStatus($case['status']);
    return <<<HTML
        <div class="court-item nes-container">
            <div class="court-header">
                <span class="court-number">{$case['case_number']}</span>
                <span class="court-status nes-badge is-warning">{$status}</span>
            </div>
            <h4 class="court-title">{$case['title']}</h4>
            <p class="court-desc">{$case['description']}</p>
        </div>
HTML;
}

function renderArchiveCase($case, $lang = 'zh') {
    $judgment = $case['judgment'] ?? ($lang === 'zh' ? '暂无判决' : 'No judgment');
    return <<<HTML
        <div class="archive-item nes-container">
            <h4>{$case['case_number']} - {$case['title']}</h4>
            <p>{$judgment}</p>
        </div>
HTML;
}

function getServicesData($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM services ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function renderService($service) {
    return <<<HTML
        <a href="{$service['url']}" target="_blank" class="service-item-link">
            <div class="service-item">
                <h4 class="service-name">{$service['name']}</h4>
                <p class="service-url">{$service['url']}</p>
            </div>
        </a>
HTML;
}



