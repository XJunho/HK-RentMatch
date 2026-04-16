<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$user = current_user();

// 读取筛选参数（GET）
$region    = isset($_GET['region']) ? trim($_GET['region']) : '';
$school    = normalize_school_code($_GET['school'] ?? '') ?? '';
$minPrice  = isset($_GET['min_price']) ? (float) $_GET['min_price'] : 0;
$maxPrice  = isset($_GET['max_price']) ? (float) $_GET['max_price'] : 0;
$period    = isset($_GET['period']) ? trim($_GET['period']) : '';
$source    = isset($_GET['source']) ? trim($_GET['source']) : '';
$gender    = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$keyword   = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$sort      = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
$page      = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize  = 9;
if (!in_array($sort, ['newest', 'price-asc', 'price-desc'], true)) {
    $sort = 'newest';
}

// 首页统计数据
$heroStats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM posts WHERE status = 'active') AS active_posts,
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM applications WHERE status = 'accepted') AS matched
")->fetch(PDO::FETCH_ASSOC);
$statActivePosts = number_format((int)($heroStats['active_posts'] ?? 0));
$statTotalUsers  = number_format((int)($heroStats['total_users']  ?? 0));
$statMatched     = number_format((int)($heroStats['matched']      ?? 0));

// 读取 Tab 类型参数（兼容旧值 roommate-source / roommate-nosource）
$rawTab = isset($_GET['tab']) ? trim($_GET['tab']) : 'rent';
if (in_array($rawTab, ['roommate-source', 'roommate-nosource'], true)) {
    $tab = 'roommate';
    if ($source === '') {
        $source = $rawTab === 'roommate-source' ? 'source' : 'nosource';
    }
} else {
    $tab = in_array($rawTab, ['rent', 'roommate', 'sublet'], true) ? $rawTab : 'rent';
}

// 构建 WHERE 条件，根据当前 tab 展示对应类型且处于 active 状态的帖子
$where  = ['p.status = :status'];
$params = [':status' => 'active'];

if ($tab === 'roommate') {
    $where[] = "p.type IN ('roommate-source', 'roommate-nosource')";
} else {
    $where[] = 'p.type = :type';
    $params[':type'] = $tab;
}

if ($region !== '') {
    $where[]            = 'p.region = :region';
    $params[':region'] = $region;
}

if ($school !== '') {
    // 筛选值为英文简称（HKU/CityU…），发帖 school_scope 可能存中文全称或简称/混排，需同时匹配
    $schoolMap = school_options_map();
    if (array_key_exists($school, $schoolMap)) {
        $label   = (string) $schoolMap[$school];
        $cnName  = '';
        if (preg_match('/^(.+)\s+([A-Za-z0-9]+)\s*$/u', $label, $m)) {
            $cnName = trim($m[1]);
        }
        $where[]                      = '(p.school_scope LIKE :school_scope_code OR p.school_scope LIKE :school_scope_cn)';
        $params[':school_scope_code'] = '%' . $school . '%';
        $params[':school_scope_cn']   = $cnName !== '' ? ('%' . $cnName . '%') : ('%' . $school . '%');
    } else {
        $where[]            = 'p.school_scope LIKE :school';
        $params[':school'] = '%' . $school . '%';
    }
}

if ($minPrice > 0) {
    $where[]              = 'p.price >= :min_price';
    $params[':min_price'] = $minPrice;
}

if ($maxPrice > 0 && $maxPrice >= $minPrice) {
    $where[]              = 'p.price <= :max_price';
    $params[':max_price'] = $maxPrice;
}

if (in_array($period, ['short', 'medium', 'long'], true)) {
    // 转租专区：租期筛选仅按 remaining_months（剩余租期）；remaining_months 为空的记录不参与租期筛选
    // 非转租专区：仍按 rent_period 直接筛选。
    if ($tab === 'sublet') {
        if ($period === 'short') {
            $where[] = '(p.remaining_months IS NOT NULL AND p.remaining_months <= 6)';
        } elseif ($period === 'medium') {
            $where[] = '(p.remaining_months IS NOT NULL AND p.remaining_months >= 7 AND p.remaining_months <= 12)';
        } else {
            $where[] = '(p.remaining_months IS NOT NULL AND p.remaining_months >= 13)';
        }
    } else {
        $where[]          = 'p.rent_period = :period';
        $params[':period'] = $period;
    }
}

if ($tab === 'roommate' && in_array($source, ['source', 'nosource'], true)) {
    $where[] = 'p.type = :roommate_type';
    $params[':roommate_type'] = $source === 'source' ? 'roommate-source' : 'roommate-nosource';
}

if ($tab === 'roommate' && in_array($gender, ['male', 'female', 'any'], true)) {
    $where[] = 'p.gender_requirement = :gender';
    $params[':gender'] = $gender;
}

if ($keyword !== '') {
    // 两处 LIKE 须用不同占位符名；PDO 不能对同名 :kw 绑定两次
    $kw                     = '%' . $keyword . '%';
    $where[]                = '(p.title LIKE :kw_title OR p.content LIKE :kw_content)';
    $params[':kw_title']   = $kw;
    $params[':kw_content'] = $kw;
}

$whereSql = implode(' AND ', $where);

// 统计总数
$countSql = "SELECT COUNT(*) FROM posts p WHERE {$whereSql}";
$stmt     = $pdo->prepare($countSql);
$stmt->execute($params);
$totalPosts = (int) $stmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalPosts / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $pageSize;

$orderBySql = match ($sort) {
    'price-asc'  => 'p.price ASC, p.created_at DESC',
    'price-desc' => 'p.price DESC, p.created_at DESC',
    default      => 'p.created_at DESC',
};

// 查询当前页数据（关联用户以展示昵称/角色）
$listSql = "
    SELECT
        p.*,
        u.username,
        u.role   AS user_role,
        u.school AS user_school,
        u.phone  AS user_phone
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT {$pageSize} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$posts = $stmt->fetchAll();
$favoritePostIds = [];
if (!empty($user) && in_array((string) ($user['role'] ?? ''), ['student', 'admin'], true) && !empty($posts)) {
    $postIds = array_values(array_unique(array_map(static fn(array $item): int => (int) $item['id'], $posts)));
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $favSql = "SELECT post_id FROM favorites WHERE user_id = ? AND post_id IN ({$placeholders})";
    $favStmt = $pdo->prepare($favSql);
    $favStmt->execute(array_merge([(int) $user['id']], $postIds));
    $favoritePostIds = array_map('intval', $favStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

// 构建分页链接基础 query（保留其他筛选参数）
$queryParams = $_GET;
if ($school === '') {
    unset($queryParams['school']);
} else {
    $queryParams['school'] = $school;
}
unset($queryParams['page']);
$baseQuery = http_build_query($queryParams);
$baseUrl   = 'index.php' . ($baseQuery !== '' ? ('?' . $baseQuery . '&') : '?');

include __DIR__ . '/includes/header.php';
?>

    <!-- Hero Section -->
    <?php if (!$user): ?>
    <section class="hero">
        <h1>香港研究生租房匹配平台</h1>
        <p>为港硕学生提供租房、找室友、转租的一站式服务</p>
        <button class="btn btn-primary btn-hero-light" type="button" onclick="openModal('registerModal')">
            立即加入
        </button>
        <div class="hero-stats">
            <div class="stat-item">
                <div class="stat-number"><?php echo htmlspecialchars($statActivePosts); ?></div>
                <div class="stat-label">活跃房源</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo htmlspecialchars($statTotalUsers); ?></div>
                <div class="stat-label">注册用户</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo htmlspecialchars($statMatched); ?></div>
                <div class="stat-label">成功匹配</div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-container">
        <!-- Tab Navigation -->
        <!-- <div class="tab-nav">
            <button class="tab-btn <?php echo $tab === 'rent' ? 'active' : ''; ?>" data-tab="rent">
                🏠 租房专区
            </button>
            <button class="tab-btn <?php echo $tab === 'roommate' ? 'active' : ''; ?>" data-tab="roommate">
                👥 找室友
            </button>
            <button class="tab-btn <?php echo $tab === 'sublet' ? 'active' : ''; ?>" data-tab="sublet">
                🔄 转租专区
            </button>
        </div> -->

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form id="filterForm" method="GET">
            <input type="hidden" name="tab" id="currentTab" value="<?php echo htmlspecialchars($tab); ?>">
            <input type="hidden" name="sort" id="sortInput" value="<?php echo htmlspecialchars($sort); ?>">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label">所属区域</label>
                    <select class="filter-select" id="filterRegion" name="region">
                        <option value="">全部区域</option>
                        <option value="中西区" <?php echo $region === '中西区' ? 'selected' : ''; ?>>中西区</option>
                        <option value="东区" <?php echo $region === '东区' ? 'selected' : ''; ?>>东区</option>
                        <option value="南区" <?php echo $region === '南区' ? 'selected' : ''; ?>>南区</option>
                        <option value="湾仔区" <?php echo $region === '湾仔区' ? 'selected' : ''; ?>>湾仔区</option>
                        <option value="九龙城区" <?php echo $region === '九龙城区' ? 'selected' : ''; ?>>九龙城区</option>
                        <option value="观塘区" <?php echo $region === '观塘区' ? 'selected' : ''; ?>>观塘区</option>
                        <option value="深水埗区" <?php echo $region === '深水埗区' ? 'selected' : ''; ?>>深水埗区</option>
                        <option value="黄大仙区" <?php echo $region === '黄大仙区' ? 'selected' : ''; ?>>黄大仙区</option>
                        <option value="油尖旺区" <?php echo $region === '油尖旺区' ? 'selected' : ''; ?>>油尖旺区</option>
                        <option value="离岛区" <?php echo $region === '离岛区' ? 'selected' : ''; ?>>离岛区</option>
                        <option value="葵青区" <?php echo $region === '葵青区' ? 'selected' : ''; ?>>葵青区</option>
                        <option value="北区" <?php echo $region === '北区' ? 'selected' : ''; ?>>北区</option>
                        <option value="西贡区" <?php echo $region === '西贡区' ? 'selected' : ''; ?>>西贡区</option>
                        <option value="沙田区" <?php echo $region === '沙田区' ? 'selected' : ''; ?>>沙田区</option>
                        <option value="大埔区" <?php echo $region === '大埔区' ? 'selected' : ''; ?>>大埔区</option>
                        <option value="荃湾区" <?php echo $region === '荃湾区' ? 'selected' : ''; ?>>荃湾区</option>
                        <option value="屯门区" <?php echo $region === '屯门区' ? 'selected' : ''; ?>>屯门区</option>
                        <option value="元朗区" <?php echo $region === '元朗区' ? 'selected' : ''; ?>>元朗区</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">学校范围</label>
                    <select class="filter-select" id="filterSchool" name="school">
                        <option value="">全部学校</option>
                        <?php foreach (school_option_groups() as $groupLabel => $groupOptions): ?>
                            <optgroup label="<?php echo htmlspecialchars($groupLabel); ?>">
                                <?php foreach ($groupOptions as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $school === $code ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(school_short_name($code)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">租金范围</label>
                    <div class="price-range">
                        <input type="number" class="filter-input" placeholder="最低" id="minPrice" name="min_price" min="0" step="500" value="<?php echo $minPrice > 0 ? htmlspecialchars((string) $minPrice) : ''; ?>">
                        <span>-</span>
                        <input type="number" class="filter-input" placeholder="最高" id="maxPrice" name="max_price" min="0" step="500" value="<?php echo $maxPrice > 0 ? htmlspecialchars((string) $maxPrice) : ''; ?>">
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-label">租期</label>
                    <select class="filter-select" id="filterPeriod" name="period">
                        <option value="">全部</option>
                        <option value="short" <?php echo $period === 'short' ? 'selected' : ''; ?>>6个月以下</option>
                        <option value="medium" <?php echo $period === 'medium' ? 'selected' : ''; ?>>6个月-1年</option>
                        <option value="long" <?php echo $period === 'long' ? 'selected' : ''; ?>>1年及以上</option>
                    </select>
                </div>

                <div class="filter-group" id="filterSourceGroup" style="<?php echo $tab === 'roommate' ? '' : 'display:none;'; ?>">
                    <label class="filter-label">房源情况</label>
                    <select class="filter-select" id="filterSource" name="source">
                        <option value="">全部</option>
                        <option value="source" <?php echo $source === 'source' ? 'selected' : ''; ?>>有房源</option>
                        <option value="nosource" <?php echo $source === 'nosource' ? 'selected' : ''; ?>>无房源</option>
                    </select>
                </div>

                <div class="filter-group" id="filterGenderGroup" style="<?php echo $tab === 'roommate' ? '' : 'display:none;'; ?>">
                    <label class="filter-label">性别要求</label>
                    <select class="filter-select" id="filterGender" name="gender">
                        <option value="">全部</option>
                        <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>男生</option>
                        <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>女生</option>
                        <option value="any" <?php echo $gender === 'any' ? 'selected' : ''; ?>>不限</option>
                    </select>
                </div>

                <div class="filter-group filter-group-flex">
                    <label class="filter-label">关键字搜索</label>
                    <div class="search-box">
                        <span class="search-icon">🔍</span>
                        <input
                            type="text"
                            class="search-input"
                            placeholder="搜索标题、描述..."
                            id="searchKeyword"
                            name="keyword"
                            value="<?php echo htmlspecialchars($keyword); ?>"
                        >
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-reset" onclick="resetFilters()">重置</button>
                    <button type="button" class="btn btn-primary" onclick="applyFilters()">搜索</button>
                </div>
            </div>
            </form>
        </div>

        <!-- Posts Header -->
        <div class="posts-header">
            <div class="posts-count">
                共找到 <strong id="postCount"><?php echo $totalPosts; ?></strong> 条结果
            </div>
            <select class="sort-select" id="sortSelect">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>最新发布</option>
                <option value="price-asc" <?php echo $sort === 'price-asc' ? 'selected' : ''; ?>>租金从低到高</option>
                <option value="price-desc" <?php echo $sort === 'price-desc' ? 'selected' : ''; ?>>租金从高到低</option>
            </select>
        </div>

        <!-- Posts Grid -->
        <div class="posts-grid" id="postsGrid">
            <?php if (empty($posts)): ?>
                <div class="empty-state">
                    暂无符合条件的房源，请尝试调整筛选条件。
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $images      = parse_post_images($post['images'] ?? null);
                    $fallback1   = 'https://images.unsplash.com/photo-1502672023488-70e25813eb80?w=400&h=300&fit=crop';
                    $fallback2   = 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=300&h=200&fit=crop';
                    $fallback3   = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=300&h=200&fit=crop';
                    $cardImage   = resolve_post_image_url($images[0] ?? '') ?: $fallback1;
                    $detailMain  = resolve_post_image_url($images[0] ?? '') ?: $fallback1;
                    $detailThumb1 = resolve_post_image_url($images[1] ?? '') ?: ($detailMain ?: $fallback2);
                    $detailThumb2 = resolve_post_image_url($images[2] ?? '') ?: ($detailThumb1 ?: $fallback3);
                    $price      = (float) $post['price'];
                    $regionText = $post['region'] ?: '未知区域';
                    $schoolText = $post['school_scope'] ?: '不限学校';
                    $metroText  = $post['metro_stations'] ?: '未提供地铁站';
                    $author     = $post['username'] ?: '匿名用户';
                    $createdAt  = $post['created_at'] ?? '';
                    $createdDate = $createdAt ? date('Y-m-d', strtotime($createdAt)) : '';
                    $floorText  = $post['floor'] ?: '-';
                    $contentText = isset($post['content']) ? trim((string) $post['content']) : '';
                    $contentText = preg_replace('/\s+/', ' ', $contentText) ?: '暂无描述。';
                    $periodLabel = '1年及以上';
                    if (($post['rent_period'] ?? '') === 'short') {
                        $periodLabel = '6个月以下';
                    } elseif (($post['rent_period'] ?? '') === 'medium') {
                        $periodLabel = '6个月-1年';
                    }
                    $roleLabelMap = ['landlord' => '🏢 房源供给方', 'admin' => '⚙️ 管理员', 'student' => '🎓 港硕学生'];
                    $roleLabel = $roleLabelMap[$post['user_role'] ?? 'student'] ?? '🎓 港硕学生';
                    $authorSchoolText = school_display_name($post['user_school'] ?? null);
                    $authorRoleText = $roleLabel . ($authorSchoolText !== '' ? (' · ' . $authorSchoolText) : '');
                    $postType = $post['type'] ?? 'rent';
                    if (in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true)) {
                        $contactText = '📞 如需联系，请申请';
                    } elseif (!empty($user) && !empty($post['user_phone'])) {
                        $contactText = '📞 联系方式：' . $post['user_phone'];
                    } elseif (!empty($user)) {
                        $contactText = '📞 联系方式：登录用户可见';
                    } else {
                        $contactText = '📞 登录后查看联系方式';
                    }
                    $badgeClass = match($postType) {
                        'roommate-source'   => 'badge-roommate-source',
                        'roommate-nosource' => 'badge-roommate-nosource',
                        'sublet'            => 'badge-sublet',
                        default             => 'badge-rent',
                    };
                    $badgeText = match($postType) {
                        'roommate-source'   => '🏘️ 有房找室友',
                        'roommate-nosource' => '🔍 无房找室友',
                        'sublet'            => '🔄 转租',
                        default             => '🏠 租房',
                    };
                    $priceLabel = ($postType === 'roommate-nosource') ? '预算HKD/月':'HKD/月';
                    $genderMap  = ['male' => '仅限男生', 'female' => '仅限女生', 'any' => '男女不限'];
                    $genderReq  = $genderMap[$post['gender_requirement'] ?? ''] ?? '-';
                    $needCount  = !empty($post['need_count']) ? ((int)$post['need_count'] . ' 人') : '-';
                    $remainingMonthsText = !empty($post['remaining_months']) ? ((int)$post['remaining_months'] . ' 个月') : '-';
                    $moveInDateText = !empty($post['move_in_date']) ? (string)$post['move_in_date'] : '-';
                    $renewableMap = ['yes' => '可续租', 'no' => '不可续租'];
                    $renewableText = $renewableMap[$post['renewable'] ?? ''] ?? '-';
                    ?>
                    <div
                        class="post-card"
                        data-post-id="<?php echo (int) $post['id']; ?>"
                        data-type="<?php echo htmlspecialchars($postType); ?>"
                        data-title="<?php echo htmlspecialchars($post['title']); ?>"
                        data-price="<?php echo number_format($price, 0); ?>"
                        data-price-label="<?php echo htmlspecialchars($priceLabel); ?>"
                        data-region="<?php echo htmlspecialchars($regionText); ?>"
                        data-metro="<?php echo htmlspecialchars($metroText); ?>"
                        data-school="<?php echo htmlspecialchars($schoolText); ?>"
                        data-floor="<?php echo htmlspecialchars($floorText); ?>"
                        data-period="<?php echo htmlspecialchars($periodLabel); ?>"
                        data-content="<?php echo htmlspecialchars($contentText); ?>"
                        data-author="<?php echo htmlspecialchars($author); ?>"
                        data-owner-id="<?php echo (int) ($post['user_id'] ?? 0); ?>"
                        data-author-initial="<?php echo htmlspecialchars(mb_substr($author, 0, 1, 'UTF-8')); ?>"
                        data-author-role="<?php echo htmlspecialchars($authorRoleText); ?>"
                        data-created-date="<?php echo htmlspecialchars($createdDate); ?>"
                        data-contact="<?php echo htmlspecialchars($contactText); ?>"
                        data-gender-req="<?php echo htmlspecialchars($genderReq); ?>"
                        data-need-count="<?php echo htmlspecialchars($needCount); ?>"
                        data-remaining-months="<?php echo htmlspecialchars($remainingMonthsText); ?>"
                        data-move-in-date="<?php echo htmlspecialchars($moveInDateText); ?>"
                        data-renewable="<?php echo htmlspecialchars($renewableText); ?>"
                        data-image-main="<?php echo htmlspecialchars($detailMain); ?>"
                        data-image-thumb1="<?php echo htmlspecialchars($detailThumb1); ?>"
                        data-image-thumb2="<?php echo htmlspecialchars($detailThumb2); ?>"
                        onclick="openPostDetail(<?php echo (int) $post['id']; ?>)"
                    >
                        <div class="card-image-wrapper">
                            <?php if ($postType === 'roommate-nosource'): ?>
                                <div class="card-image card-image-placeholder" style="background:linear-gradient(135deg,#e8eaf6 0%,#c5cae9 100%);display:flex;align-items:center;justify-content:center;font-size:2.5rem;">🔍</div>
                            <?php else: ?>
                                <img
                                    src="<?php echo htmlspecialchars($cardImage); ?>"
                                    alt="<?php echo htmlspecialchars($post['title']); ?>"
                                    class="card-image"
                                >
                            <?php endif; ?>
                            <span class="card-badge <?php echo htmlspecialchars($badgeClass); ?>"><?php echo $badgeText; ?></span>
                            <button class="card-favorite <?php echo in_array((int) $post['id'], $favoritePostIds, true) ? 'active' : ''; ?>" onclick="event.stopPropagation(); toggleFavorite(<?php echo (int) $post['id']; ?>, this)">
                            </button>
                        </div>
                        <div class="card-content">
                            <h3 class="card-title"><?php echo htmlspecialchars($post['title']); ?></h3>
                            <div class="card-price"><?php echo number_format($price, 0); ?> <span class="card-price-label" style="font-size:0.75rem;font-weight:400;color:var(--text-secondary);"><?php echo htmlspecialchars($priceLabel); ?></span></div>
                            <div class="card-tags">
                                <span class="tag tag-region"><?php echo htmlspecialchars($regionText); ?></span>
                                <span class="tag tag-metro">🚇 <?php echo htmlspecialchars($metroText); ?></span>
                                <span class="tag tag-school"><?php echo htmlspecialchars($schoolText); ?></span>
                            </div>
                            <div class="card-footer">
                                <div class="card-author">
                                    <div class="author-avatar">
                                        <?php echo htmlspecialchars(mb_substr($author, 0, 1, 'UTF-8')); ?>
                                    </div>
                                    <span class="author-name"><?php echo htmlspecialchars($author); ?></span>
                                </div>
                                <span class="card-time">
                                    <?php echo htmlspecialchars($createdDate); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a class="page-btn" href="<?php echo htmlspecialchars($baseUrl . 'page=' . ($page - 1)); ?>">‹</a>
            <?php else: ?>
                <button class="page-btn" disabled>‹</button>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === $page): ?>
                    <button class="page-btn active" disabled><?php echo $i; ?></button>
                <?php else: ?>
                    <a class="page-btn" href="<?php echo htmlspecialchars($baseUrl . 'page=' . $i); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a class="page-btn" href="<?php echo htmlspecialchars($baseUrl . 'page=' . ($page + 1)); ?>">›</a>
            <?php else: ?>
                <button class="page-btn" disabled>›</button>
            <?php endif; ?>
        </div>
    </main>

    <!-- Floating Action Button -->
    <button class="fab" onclick="isLoggedIn ? window.location.href='<?php echo htmlspecialchars(project_base_url('post/create.php')); ?>' : openModal('loginModal')" title="发布">+</button>

    <!-- Login Modal -->
    <div class="modal-overlay" id="loginModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">欢迎回来</h2>
                <button class="modal-close" onclick="closeModal('loginModal')">×</button>
            </div>
            <div class="modal-body">
                <form id="loginForm" onsubmit="handleLogin(event)">
                    <div class="form-group">
                        <label class="form-label">邮箱地址 <span class="required">*</span></label>
                        <input type="email" class="form-input" name="email" placeholder="请输入邮箱" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">密码 <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-input" name="password" placeholder="请输入密码" required>
                            <button type="button" class="password-toggle" aria-label="查看密码">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="form-hint">密码为8-20位，包含大小写字母和数字</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">登录</button>
                </form>
                <div class="divider"><span>或</span></div>
                <button class="btn btn-outline btn-block" onclick="switchModal('loginModal', 'registerModal')">
                    没有账号？立即注册
                </button>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal-overlay" id="registerModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">创建账号</h2>
                <button class="modal-close" onclick="closeModal('registerModal')">×</button>
            </div>
            <div class="modal-body">
                <form id="registerForm" onsubmit="handleRegister(event)">
                    <input type="hidden" name="role" id="registerRole" value="student">
                    <div class="form-group">
                        <label class="form-label">选择身份 <span class="required">*</span></label>
                        <div class="role-cards">
                            <div class="role-card selected" onclick="selectRole(this, 'student')">
                                <div class="role-icon">🎓</div>
                                <div class="role-name">港硕学生</div>
                                <div class="role-desc">可发布找室友/转租<br>可收藏/申请</div>
                            </div>
                            <div class="role-card" onclick="selectRole(this, 'landlord')">
                                <div class="role-icon">🏢</div>
                                <div class="role-name">房源供给方</div>
                                <div class="role-desc">仅可发布租房<br>不可收藏/申请</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="schoolGroup">
                        <label class="form-label">所属学校 <span class="required">*</span></label>
                        <select class="form-select" id="registerSchool" name="school">
                            <option value="">请选择学校</option>
                            <?php foreach (school_options_map() as $code => $label): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">昵称 <span class="required">*</span></label>
                        <input type="text" class="form-input" placeholder="2-20位中英文、数字" id="registerNickname" name="username">
                    </div>

                    <div class="form-group">
                        <label class="form-label">性别 <span class="required">*</span></label>
                        <div class="form-radio-group">
                            <label class="form-radio">
                                <input type="radio" name="gender" value="male"> 男
                            </label>
                            <label class="form-radio">
                                <input type="radio" name="gender" value="female"> 女
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">邮箱地址 <span class="required">*</span></label>
                        <input type="email" class="form-input" placeholder="请输入邮箱" id="registerEmail" name="email">
                    </div>

                    <div class="form-group">
                        <label class="form-label">电话号码 <span class="required">*</span></label>
                        <input type="tel" class="form-input" placeholder="香港8位数字，如98765432" id="registerPhone" name="phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label">密码 <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-input" placeholder="8-20位，含大小写字母和数字" id="registerPassword" name="password">
                            <button type="button" class="password-toggle" aria-label="查看密码">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">确认密码 <span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-input" placeholder="请再次输入密码" id="registerPasswordConfirm" name="password_confirm">
                            <button type="button" class="password-toggle" aria-label="查看密码">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">注册</button>
                </form>
                <div class="divider"><span>或</span></div>
                <button class="btn btn-outline btn-block" onclick="switchModal('registerModal', 'loginModal')">
                    已有账号？立即登录
                </button>
            </div>
        </div>
    </div>

    <!-- Post Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal detail-modal">
            <div class="modal-header">
                <div class="modal-title-group">
                    <span class="card-badge badge-rent">🏠 租房</span>
                    <h2 class="modal-title">房源详情</h2>
                </div>
                <button class="modal-close" onclick="closeModal('detailModal')">×</button>
            </div>
            <div class="detail-slider" id="detailSlider">
                <button class="slider-arrow slider-arrow-prev" id="sliderPrevBtn" onclick="detailSliderPrev()" aria-label="上一张">&#8249;</button>
                <img id="detailSliderImage" src="" alt="房源图片">
                <button class="slider-arrow slider-arrow-next" id="sliderNextBtn" onclick="detailSliderNext()" aria-label="下一张">&#8250;</button>
                <div class="slider-dots" id="sliderDots"></div>
            </div>
            <div class="detail-info">
                <div class="detail-badge-row">
                    <div class="card-tags">
                        <span class="tag tag-region" id="detailRegion">-</span>
                        <span class="tag tag-metro" id="detailMetro">🚇 -</span>
                        <span class="tag tag-school" id="detailSchool">-</span>
                    </div>
                </div>
                <h3 class="detail-title" id="detailTitle">-</h3>
                <div class="detail-price"><span id="detailPrice">0</span> <span id="detailPriceLabel" style="font-size:0.875rem;font-weight:400;color:var(--text-secondary);">HKD/月</span></div>

                <div class="detail-meta">
                    <div class="meta-item" id="detailFloorItem">
                        <div class="meta-icon">📍</div>
                        <div>
                            <div class="meta-label">楼层</div>
                            <div class="meta-value" id="detailFloor">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailPeriodItem">
                        <div class="meta-icon">📅</div>
                        <div>
                            <div class="meta-label">租期</div>
                            <div class="meta-value" id="detailPeriod">-</div>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-icon">🏫</div>
                        <div>
                            <div class="meta-label">学校范围</div>
                            <div class="meta-value" id="detailSchoolMeta">-</div>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-icon">🚇</div>
                        <div>
                            <div class="meta-label">地铁站</div>
                            <div class="meta-value" id="detailMetroMeta">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailGenderReqItem" style="display:none">
                        <div class="meta-icon">👤</div>
                        <div>
                            <div class="meta-label">性别要求</div>
                            <div class="meta-value" id="detailGenderReq">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailNeedCountItem" style="display:none">
                        <div class="meta-icon">👥</div>
                        <div>
                            <div class="meta-label">需求人数</div>
                            <div class="meta-value" id="detailNeedCount">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailRemainingMonthsItem" style="display:none">
                        <div class="meta-icon">⏳</div>
                        <div>
                            <div class="meta-label">剩余租期</div>
                            <div class="meta-value" id="detailRemainingMonths">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailMoveInDateItem" style="display:none">
                        <div class="meta-icon">🗓️</div>
                        <div>
                            <div class="meta-label">最早入住日期</div>
                            <div class="meta-value" id="detailMoveInDate">-</div>
                        </div>
                    </div>
                    <div class="meta-item" id="detailRenewableItem" style="display:none">
                        <div class="meta-icon">♻️</div>
                        <div>
                            <div class="meta-label">是否可续租</div>
                            <div class="meta-value" id="detailRenewable">-</div>
                        </div>
                    </div>
                </div>

                <div class="detail-desc" id="detailDesc">暂无描述。</div>

                <div class="detail-author">
                    <div class="author-info">
                        <div class="author-detail-avatar" id="detailAuthorAvatar">?</div>
                        <div>
                            <div class="author-detail-name" id="detailAuthorName">匿名用户</div>
                            <div class="author-detail-role" id="detailAuthorRole">-</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm-muted" id="detailCreatedDate"></div>
                        <div class="text-primary-strong" id="detailContact">📞 登录后查看联系方式</div>
                    </div>
                </div>
            </div>
            <div class="detail-actions">
                <button class="btn btn-outline" id="detailFavoriteBtn" onclick="toggleFavoriteDetail(this)">
                    🤍 收藏
                </button>
                <button class="btn btn-primary" id="detailPrimaryBtn" onclick="openDetailPrimaryAction()">
                    发送申请
                </button>
            </div>
        </div>
    </div>

    <!-- Apply Modal -->
    <div class="modal-overlay" id="applyModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">发送申请</h2>
                <button class="modal-close" onclick="closeModal('applyModal')">×</button>
            </div>
            <div class="modal-body">
                <form id="applyForm" onsubmit="handleApply(event)">
                    <div class="form-group">
                        <label class="form-label">申请理由 <span class="required">*</span></label>
                        <textarea class="form-input no-resize" name="message" rows="4" placeholder="请简要介绍自己，说明租房意向（500字以内）" maxlength="500" required></textarea>
                        <div class="form-hint">最多500字</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">提交申请</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Post Type Modal -->
    <div class="modal-overlay" id="postModal">
        <div class="modal post-modal">
            <div class="modal-header">
                <h2 class="modal-title">发布帖子</h2>
                <button class="modal-close" onclick="closeModal('postModal')">×</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:20px;">不同类型对应不同的信息表单，请根据实际需求选择</p>
                <div class="post-type-cards">
                    <div class="post-type-card" data-type="rent" onclick="selectPostType(this,'rent')">
                        <div class="post-type-check">✓</div>
                        <div class="post-type-icon">🏠</div>
                        <div class="post-type-name">发布租房</div>
                        <div class="post-type-desc">房源供给方发布<br>出租房源信息</div>
                        <span class="post-type-role">🏢 房源供给方</span>
                    </div>
                    <div class="post-type-card" data-type="roommate-source" onclick="selectPostType(this,'roommate-source')">
                        <div class="post-type-check">✓</div>
                        <div class="post-type-icon">🏘️</div>
                        <div class="post-type-name">已有房源 找室友</div>
                        <div class="post-type-desc">已租房源<br>寻找合租室友</div>
                        <span class="post-type-role">🎓 港硕学生</span>
                    </div>
                    <div class="post-type-card" data-type="roommate-nosource" onclick="selectPostType(this,'roommate-nosource')">
                        <div class="post-type-check">✓</div>
                        <div class="post-type-icon">🔍</div>
                        <div class="post-type-name">无房源 找室友</div>
                        <div class="post-type-desc">暂无房源<br>寻找合租伙伴</div>
                        <span class="post-type-role">🎓 港硕学生</span>
                    </div>
                </div>
                <div class="post-modal-footer">
                    <?php if (!isset($user) || !$user): ?>
                    <button class="btn btn-primary btn-post-next" id="goPublishBtn"
                            onclick="closeModal('postModal'); openModal('loginModal');">
                        登录后发布
                    </button>
                    <?php else: ?>
                    <button class="btn btn-primary btn-post-next" id="goPublishBtn"
                            onclick="goToPublish()">
                        下一步：填写信息 →
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php if (isset($_GET['toast']) && $_GET['toast'] === 'published'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast('发布成功！', 'success');
    if (window.history && window.history.replaceState) {
        const url = window.location.pathname + (window.location.search.replace(/[?&]toast=published/, '').replace(/^&/, '?') || '');
        window.history.replaceState(null, '', url);
    }
});
</script>
<?php endif; ?>

<?php if (isset($_GET['login']) && !$user): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    openModal('loginModal');
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname);
    }
});
</script>
<?php endif; ?>
<script>
window.initialFavoritePostIds = <?php echo json_encode($favoritePostIds, JSON_UNESCAPED_UNICODE); ?>;
</script>
<!-- 租金范围处理逻辑 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const minInput = document.getElementById('minPrice');
    const maxInput = document.getElementById('maxPrice');

    function fixPriceRange() {
        let min = parseInt(minInput.value) || 0;
        let max = parseInt(maxInput.value) || 0;

        if (min < 0) min = 0;
        if (max < 0) max = 0;

        if (min !== 0 && max !== 0 && min > max) {
            [min, max] = [max, min];
        }

        minInput.value = min || '';
        maxInput.value = max || '';
    }

    minInput.addEventListener('change', fixPriceRange);
    maxInput.addEventListener('change', fixPriceRange);
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

