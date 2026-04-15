<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

$allowedSections = ['profile', 'posts', 'favorites', 'applications', 'received'];
$section = isset($_GET['section']) ? trim((string) $_GET['section']) : 'profile';
if (!in_array($section, $allowedSections, true)) {
    $section = 'profile';
}

function profile_section_url(string $section, array $extra = []): string
{
    $params = array_merge(['section' => $section], $extra);
    return project_base_url('profile.php') . '?' . http_build_query($params);
}

function redirect_profile(string $section, string $type, string $message, array $extra = []): void
{
    header('Location: ' . profile_section_url($section, [
        'notice_type' => $type,
        'notice' => $message,
    ] + $extra));
    exit;
}

$currentUserId = (int) (current_user()['id'] ?? 0);
$isAjaxRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        (isset($_GET['ajax']) && $_GET['ajax'] === '1')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $targetSection = trim((string) ($_POST['section'] ?? $section));
    $postsFilterPost = trim((string) ($_POST['posts_filter'] ?? 'all'));
    $favoritesFilterPost = trim((string) ($_POST['favorites_filter'] ?? 'all'));
    $applicationsFilterPost = trim((string) ($_POST['applications_filter'] ?? 'all'));
    $receivedFilterPost = trim((string) ($_POST['received_filter'] ?? 'all'));
    if (!in_array($postsFilterPost, ['all', 'rent', 'roommate', 'sublet'], true)) {
        $postsFilterPost = 'all';
    }
    if (!in_array($favoritesFilterPost, ['all', 'rent', 'roommate', 'sublet'], true)) {
        $favoritesFilterPost = 'all';
    }
    if (!in_array($applicationsFilterPost, ['all', 'rent', 'roommate', 'sublet'], true)) {
        $applicationsFilterPost = 'all';
    }
    if (!in_array($receivedFilterPost, ['all', 'rent', 'roommate', 'sublet'], true)) {
        $receivedFilterPost = 'all';
    }
    if (!in_array($targetSection, $allowedSections, true)) {
        $targetSection = 'profile';
    }

    if ($action === 'update_profile') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if ($username === '' || mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 20) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '昵称需为 2-20 个字符。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '昵称需为 2-20 个字符。');
        }
        if (!is_valid_hk_phone($phone)) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '请输入香港 8 位电话号码（首位为 2-9）。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '请输入香港 8 位电话号码（首位为 2-9）。');
        }

        $stmt = $pdo->prepare('UPDATE users SET username = :username, phone = :phone WHERE id = :id LIMIT 1');
        $stmt->execute([
            ':username' => $username,
            ':phone' => $phone,
            ':id' => $currentUserId,
        ]);

        $_SESSION['user']['username'] = $username;
        $_SESSION['user']['phone'] = $phone;

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => '个人信息已更新。',
                'username' => $username,
                'phone' => $phone,
                'avatar_initial' => mb_substr($username, 0, 1, 'UTF-8'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect_profile($targetSection, 'success', '个人信息已更新。');
    }

    if ($action === 'post_set_status') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        $nextStatus = trim((string) ($_POST['next_status'] ?? ''));
        if ($postId <= 0 || !in_array($nextStatus, ['active', 'hidden'], true)) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '帖子状态操作无效。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '帖子状态操作无效。');
        }

        $stmt = $pdo->prepare(
            "UPDATE posts
             SET status = :status
             WHERE id = :post_id AND user_id = :user_id AND status <> 'deleted'
             LIMIT 1"
        );
        $stmt->execute([
            ':status' => $nextStatus,
            ':post_id' => $postId,
            ':user_id' => $currentUserId,
        ]);

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $nextStatus === 'hidden' ? '帖子已隐藏。' : '帖子已恢复展示。',
                'post_id' => $postId,
                'status' => $nextStatus,
                'next_status' => $nextStatus === 'active' ? 'hidden' : 'active',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect_profile($targetSection, 'success', $nextStatus === 'hidden' ? '帖子已隐藏。' : '帖子已恢复展示。', [
            'posts_filter' => $postsFilterPost,
            'notice_mode' => 'toast',
        ]);
    }

    if ($action === 'post_delete') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '帖子删除参数无效。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '帖子删除参数无效。');
        }

        $stmt = $pdo->prepare(
            "UPDATE posts
             SET status = 'deleted'
             WHERE id = :post_id AND user_id = :user_id AND status <> 'deleted'
             LIMIT 1"
        );
        $stmt->execute([
            ':post_id' => $postId,
            ':user_id' => $currentUserId,
        ]);

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => '帖子已删除。',
                'post_id' => $postId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect_profile($targetSection, 'success', '帖子已删除。', [
            'posts_filter' => $postsFilterPost,
        ]);
    }

    if ($action === 'favorite_remove') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            redirect_profile($targetSection, 'error', '收藏参数无效。');
        }

        $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = :user_id AND post_id = :post_id');
        $stmt->execute([
            ':user_id' => $currentUserId,
            ':post_id' => $postId,
        ]);

        redirect_profile($targetSection, 'success', '已取消收藏。', [
            'favorites_filter' => $favoritesFilterPost,
        ]);
    }

    if ($action === 'application_withdraw') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        if ($applicationId <= 0) {
            redirect_profile($targetSection, 'error', '申请参数无效。');
        }

        $stmt = $pdo->prepare(
            "UPDATE applications
             SET status = 'withdrawn'
             WHERE id = :id AND applicant_user_id = :user_id AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $applicationId,
            ':user_id' => $currentUserId,
        ]);

        if ($stmt->rowCount() <= 0) {
            redirect_profile($targetSection, 'error', '仅待处理申请可撤回。');
        }
        redirect_profile($targetSection, 'success', '申请已撤回。', [
            'applications_filter' => $applicationsFilterPost,
        ]);
    }

    if ($action === 'received_process') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $decision = trim((string) ($_POST['decision'] ?? ''));
        if ($applicationId <= 0 || !in_array($decision, ['accepted', 'rejected'], true)) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '处理参数无效。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '处理参数无效。');
        }

        $stmt = $pdo->prepare(
            "UPDATE applications a
             JOIN posts p ON p.id = a.post_id
             SET a.status = :status,
                 a.applicant_result_unread = 1,
                 a.owner_unread = 0
             WHERE a.id = :id AND p.user_id = :owner_id AND a.status = 'pending'"
        );
        $stmt->execute([
            ':status' => $decision,
            ':id' => $applicationId,
            ':owner_id' => $currentUserId,
        ]);

        if ($stmt->rowCount() <= 0) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '仅帖子拥有者可处理待处理申请。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '仅帖子拥有者可处理待处理申请。');
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $decision === 'accepted' ? '已同意申请。' : '已拒绝申请。',
                'application_id' => $applicationId,
                'status' => $decision,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect_profile($targetSection, 'success', $decision === 'accepted' ? '已同意申请。' : '已拒绝申请。', [
            'received_filter' => $receivedFilterPost,
            'notice_mode' => 'toast',
        ]);
    }
}

$userStmt = $pdo->prepare(
    'SELECT id, username, email, phone, gender, role, school, created_at
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$userStmt->execute([':id' => $currentUserId]);
$profileUser = $userStmt->fetch();

if (!$profileUser) {
    logout_user();
    header('Location: ' . project_base_url('index.php'));
    exit;
}

$isStudent = ($profileUser['role'] ?? '') !== 'landlord';

if ($section === 'applications') {
    $clearApplicantUnread = $pdo->prepare(
        'UPDATE applications SET applicant_result_unread = 0 WHERE applicant_user_id = :uid AND applicant_result_unread = 1'
    );
    $clearApplicantUnread->execute([':uid' => $currentUserId]);
} elseif ($section === 'received') {
    $clearOwnerUnread = $pdo->prepare(
        "UPDATE applications a
         INNER JOIN posts p ON p.id = a.post_id
         SET a.owner_unread = 0
         WHERE p.user_id = :uid AND a.owner_unread = 1"
    );
    $clearOwnerUnread->execute([':uid' => $currentUserId]);
}

$statsStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM posts WHERE user_id = :uid_posts AND status <> 'deleted') AS posts_count,
        (SELECT COUNT(*) FROM favorites WHERE user_id = :uid_favorites) AS favorites_count,
        (SELECT COUNT(*) FROM applications WHERE applicant_user_id = :uid_applications) AS applications_count,
        (
            SELECT COUNT(*)
            FROM applications
            WHERE applicant_user_id = :uid_applicant_unread AND applicant_result_unread = 1
        ) AS applicant_unread_count,
        (
            SELECT COUNT(*)
            FROM applications a
            INNER JOIN posts p ON p.id = a.post_id
            WHERE p.user_id = :uid_owner_unread AND a.owner_unread = 1
        ) AS owner_unread_count"
);
$statsStmt->execute([
    ':uid_posts' => $currentUserId,
    ':uid_favorites' => $currentUserId,
    ':uid_applications' => $currentUserId,
    ':uid_applicant_unread' => $currentUserId,
    ':uid_owner_unread' => $currentUserId,
]);
$stats = $statsStmt->fetch() ?: [
    'posts_count' => 0,
    'favorites_count' => 0,
    'applications_count' => 0,
    'applicant_unread_count' => 0,
    'owner_unread_count' => 0,
];

$myPostsStmt = $pdo->prepare(
    "SELECT id, title, type, price, status, region, metro_stations, school_scope, images, created_at
     FROM posts
     WHERE user_id = :uid AND status <> 'deleted'
     ORDER BY created_at DESC"
);
$myPostsStmt->execute([':uid' => $currentUserId]);
$myPosts = $myPostsStmt->fetchAll();
$postsFilter = trim((string) ($_GET['posts_filter'] ?? 'all'));
if (!in_array($postsFilter, ['all', 'rent', 'roommate', 'sublet'], true)) {
    $postsFilter = 'all';
}
$postsStats = [
    'all' => count($myPosts),
    'rent' => 0,
    'roommate' => 0,
    'sublet' => 0,
];
foreach ($myPosts as $myPostItem) {
    $type = (string) ($myPostItem['type'] ?? '');
    if ($type === 'rent') {
        $postsStats['rent']++;
    } elseif ($type === 'sublet') {
        $postsStats['sublet']++;
    } elseif ($type === 'roommate-source' || $type === 'roommate-nosource') {
        $postsStats['roommate']++;
    }
}
$filteredMyPosts = array_values(array_filter($myPosts, static function (array $item) use ($postsFilter): bool {
    $type = (string) ($item['type'] ?? '');
    if ($postsFilter === 'all') {
        return true;
    }
    if ($postsFilter === 'roommate') {
        return $type === 'roommate-source' || $type === 'roommate-nosource';
    }
    return $type === $postsFilter;
}));

$myFavorites = [];
if ($isStudent) {
    $favoritesStmt = $pdo->prepare(
        "SELECT
            f.post_id,
            f.created_at AS favorite_created_at,
            p.title,
            p.price,
            p.status,
            p.type,
            p.region,
            p.school_scope,
            p.images
         FROM favorites f
         JOIN posts p ON p.id = f.post_id
         WHERE f.user_id = :uid
         ORDER BY f.created_at DESC"
    );
    $favoritesStmt->execute([':uid' => $currentUserId]);
    $myFavorites = $favoritesStmt->fetchAll();
}
$favoritesFilter = trim((string) ($_GET['favorites_filter'] ?? 'all'));
if (!in_array($favoritesFilter, ['all', 'rent', 'roommate', 'sublet'], true)) {
    $favoritesFilter = 'all';
}
$favoritesStats = [
    'all' => count($myFavorites),
    'rent' => 0,
    'roommate' => 0,
    'sublet' => 0,
];
foreach ($myFavorites as $item) {
    $type = (string) ($item['type'] ?? '');
    if ($type === 'rent') {
        $favoritesStats['rent']++;
    } elseif ($type === 'sublet') {
        $favoritesStats['sublet']++;
    } elseif ($type === 'roommate-source' || $type === 'roommate-nosource') {
        $favoritesStats['roommate']++;
    }
}
$filteredFavorites = array_values(array_filter($myFavorites, static function (array $item) use ($favoritesFilter): bool {
    $type = (string) ($item['type'] ?? '');
    if ($favoritesFilter === 'all') {
        return true;
    }
    if ($favoritesFilter === 'roommate') {
        return $type === 'roommate-source' || $type === 'roommate-nosource';
    }
    return $type === $favoritesFilter;
}));

$myApplications = [];
if ($isStudent) {
    $myApplicationsStmt = $pdo->prepare(
        "SELECT
            a.id,
            a.post_id,
            a.message,
            a.status,
            a.created_at,
            p.title,
            p.type AS post_type,
            p.user_id AS owner_user_id,
            u.username AS owner_name,
            u.phone AS owner_phone
         FROM applications a
         JOIN posts p ON p.id = a.post_id
         JOIN users u ON u.id = p.user_id
         WHERE a.applicant_user_id = :uid
         ORDER BY a.created_at DESC"
    );
    $myApplicationsStmt->execute([':uid' => $currentUserId]);
    $myApplications = $myApplicationsStmt->fetchAll();
}
$applicationsFilter = trim((string) ($_GET['applications_filter'] ?? 'all'));
if (!in_array($applicationsFilter, ['all', 'rent', 'roommate', 'sublet'], true)) {
    $applicationsFilter = 'all';
}
$applicationsStats = [
    'all' => count($myApplications),
    'rent' => 0,
    'roommate' => 0,
    'sublet' => 0,
];
foreach ($myApplications as $item) {
    $type = (string) ($item['post_type'] ?? '');
    if ($type === 'rent') {
        $applicationsStats['rent']++;
    } elseif ($type === 'sublet') {
        $applicationsStats['sublet']++;
    } elseif ($type === 'roommate-source' || $type === 'roommate-nosource') {
        $applicationsStats['roommate']++;
    }
}
$filteredApplications = array_values(array_filter($myApplications, static function (array $item) use ($applicationsFilter): bool {
    $type = (string) ($item['post_type'] ?? '');
    if ($applicationsFilter === 'all') {
        return true;
    }
    if ($applicationsFilter === 'roommate') {
        return $type === 'roommate-source' || $type === 'roommate-nosource';
    }
    return $type === $applicationsFilter;
}));

$receivedApplicationsStmt = $pdo->prepare(
    "SELECT
        a.id,
        a.status,
        a.message,
        a.created_at,
        p.id AS post_id,
        p.title AS post_title,
        p.type AS post_type,
        applicant.username AS applicant_name,
        applicant.school AS applicant_school,
        applicant.phone AS applicant_phone
     FROM applications a
     JOIN posts p ON p.id = a.post_id
     JOIN users applicant ON applicant.id = a.applicant_user_id
     WHERE p.user_id = :uid
     ORDER BY a.created_at DESC"
);
$receivedApplicationsStmt->execute([':uid' => $currentUserId]);
$receivedApplications = $receivedApplicationsStmt->fetchAll();
$receivedFilter = trim((string) ($_GET['received_filter'] ?? 'all'));
if (!in_array($receivedFilter, ['all', 'rent', 'roommate', 'sublet'], true)) {
    $receivedFilter = 'all';
}
$receivedStats = [
    'all' => count($receivedApplications),
    'rent' => 0,
    'roommate' => 0,
    'sublet' => 0,
];
foreach ($receivedApplications as $item) {
    $type = (string) ($item['post_type'] ?? '');
    if ($type === 'rent') {
        $receivedStats['rent']++;
    } elseif ($type === 'sublet') {
        $receivedStats['sublet']++;
    } elseif ($type === 'roommate-source' || $type === 'roommate-nosource') {
        $receivedStats['roommate']++;
    }
}
$filteredReceived = array_values(array_filter($receivedApplications, static function (array $item) use ($receivedFilter): bool {
    $type = (string) ($item['post_type'] ?? '');
    if ($receivedFilter === 'all') {
        return true;
    }
    if ($receivedFilter === 'roommate') {
        return $type === 'roommate-source' || $type === 'roommate-nosource';
    }
    return $type === $receivedFilter;
}));

$noticeType = trim((string) ($_GET['notice_type'] ?? ''));
$notice = trim((string) ($_GET['notice'] ?? ''));
$noticeMode = trim((string) ($_GET['notice_mode'] ?? ''));

$genderTextMap = [
    'male' => '男',
    'female' => '女',
    'other' => '其他',
];
$postTypeMap = [
    'rent' => '租房',
    'roommate-source' => '有房找室友',
    'roommate-nosource' => '无房找室友',
    'sublet' => '转租',
];
$applicationStatusMap = [
    'pending' => '待处理',
    'accepted' => '已同意',
    'rejected' => '已拒绝',
    'withdrawn' => '已撤回',
];
$roleMap = ['landlord' => '🏢 房源供给方', 'admin' => '⚙️ 管理员', 'student' => '🎓 港硕学生'];
$roleDisplay = $roleMap[$profileUser['role'] ?? 'student'] ?? '🎓 港硕学生';
$genderDisplay = $genderTextMap[$profileUser['gender'] ?? 'other'] ?? '其他';
$userSchoolDisplay = school_display_name($profileUser['school'] ?? null);

include __DIR__ . '/includes/header.php';
?>

<main class="main-container profile-page">
    <div class="profile-layout">
        <aside class="profile-sidebar">
            <div class="sidebar-profile-card">
                <div class="sidebar-profile-banner">
                    <div class="sidebar-profile-avatar" id="sidebarProfileAvatar">
                        <?php echo htmlspecialchars(mb_substr($profileUser['username'], 0, 1, 'UTF-8')); ?>
                    </div>
                </div>
                <div class="sidebar-profile-info">
                    <div class="sidebar-profile-name" id="sidebarProfileName"><?php echo htmlspecialchars($profileUser['username']); ?></div>
                    <div class="sidebar-profile-role"><?php echo htmlspecialchars($roleDisplay); ?></div>
                </div>

                <div class="sidebar-profile-stats">
                    <div class="sidebar-stat">
                        <div class="sidebar-stat-num" id="profilePostsCount"><?php echo (int) $stats['posts_count']; ?></div>
                        <div class="sidebar-stat-label">发布</div>
                    </div>
                    <div class="sidebar-stat">
                        <div class="sidebar-stat-num"><?php echo (int) $stats['favorites_count']; ?></div>
                        <div class="sidebar-stat-label">收藏</div>
                    </div>
                    <div class="sidebar-stat">
                        <div class="sidebar-stat-num"><?php echo (int) $stats['applications_count']; ?></div>
                        <div class="sidebar-stat-label">申请</div>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <a class="sidebar-nav-item <?php echo $section === 'profile' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('profile')); ?>">
                        <span class="nav-icon">👤</span> 个人中心
                    </a>
                    <a class="sidebar-nav-item <?php echo $section === 'posts' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('posts')); ?>">
                        <span class="nav-icon">📝</span> 我的发布
                    </a>
                    <a class="sidebar-nav-item <?php echo $section === 'favorites' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('favorites')); ?>">
                        <span class="nav-icon">❤️</span> 我的收藏
                    </a>
                    <a class="sidebar-nav-item <?php echo $section === 'applications' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('applications')); ?>">
                        <span class="nav-icon">📋</span> 我的申请
                        <?php if ((int) $stats['applicant_unread_count'] > 0): ?>
                            <span class="nav-badge"><?php echo (int) $stats['applicant_unread_count'] > 99 ? '99+' : (int) $stats['applicant_unread_count']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="sidebar-nav-item <?php echo $section === 'received' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('received')); ?>">
                        <span class="nav-icon">📨</span> 收到申请
                        <?php if ((int) $stats['owner_unread_count'] > 0): ?>
                            <span class="nav-badge"><?php echo (int) $stats['owner_unread_count'] > 99 ? '99+' : (int) $stats['owner_unread_count']; ?></span>
                        <?php endif; ?>
                    </a>
                </nav>
            </div>
        </aside>

        <section class="profile-content">
            <?php if ($notice !== '' && in_array($noticeType, ['success', 'error'], true) && $noticeMode !== 'toast'): ?>
                <div class="profile-alert <?php echo $noticeType === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($notice); ?>
                </div>
            <?php endif; ?>

            <?php if ($section === 'profile'): ?>
                <div class="section-header">
                    <div class="section-title">👤 个人中心</div>
                    <div class="section-desc">管理您的个人信息</div>
                </div>
                <div class="profile-section-card">
                    <div class="profile-form-header">
                        <h2 class="profile-form-title">基本信息</h2>
                        <button type="button" class="profile-edit-btn" id="profileEditBtn" onclick="toggleProfileEdit()">
                            ✏️ 编辑
                        </button>
                    </div>

                    <div class="profile-avatar-edit">
                        <div class="profile-avatar-large" id="profileAvatarLarge">
                            <?php echo htmlspecialchars(mb_substr($profileUser['username'], 0, 1, 'UTF-8')); ?>
                            <div class="profile-avatar-edit-btn">📷</div>
                        </div>
                    </div>

                    <form method="post" class="profile-form profile-form-readonly" id="profileEditForm">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="section" value="profile">

                        <div class="form-group">
                            <label class="profile-form-label">昵称 <span class="required">*</span></label>
                            <input class="profile-input profile-input-editable" id="profileUsernameInput" type="text" name="username" maxlength="20" value="<?php echo htmlspecialchars((string) $profileUser['username']); ?>" required disabled>
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">性别</label>
                            <input class="profile-input" type="text" value="<?php echo htmlspecialchars((string) $profileUser['gender']); ?>" disabled>
                            <!-- <select class="profile-input profile-select profile-input-editable" name="gender" disabled>
                                <?php foreach ($genderTextMap as $genderKey => $genderText): ?>
                                    <option value="<?php echo htmlspecialchars($genderKey); ?>" <?php echo ($profileUser['gender'] ?? '') === $genderKey ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($genderText); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select> -->
                            <div class="profile-field-hint">性別不可修改</div>
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">邮箱 <span class="required">*</span></label>
                            <input class="profile-input" type="text" value="<?php echo htmlspecialchars((string) $profileUser['email']); ?>" disabled>
                            <div class="profile-field-hint">邮箱不可修改</div>
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">电话号码 <span class="required">*</span></label>
                            <input class="profile-input profile-input-editable" id="profilePhoneInput" type="tel" name="phone" maxlength="8" inputmode="numeric" pattern="[2-9][0-9]{7}" value="<?php echo htmlspecialchars((string) ($profileUser['phone'] ?? '')); ?>" placeholder="香港 8 位号码，如 91234567" required disabled>
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">角色</label>
                            <input class="profile-input" type="text" value="<?php echo htmlspecialchars($roleDisplay); ?>" disabled>
                            <div class="profile-field-hint">角色不可修改</div>
                        </div>

                        <div class="form-group">
                            <label class="profile-form-label">所属学校</label>
                            <input class="profile-input" type="text" value="<?php echo htmlspecialchars($userSchoolDisplay !== '' ? $userSchoolDisplay : '-'); ?>" disabled>
                            <div class="profile-field-hint">学校不可修改</div>
                        </div>

                        <div class="profile-actions" id="profileActions">
                            <button type="button" class="btn btn-outline" onclick="cancelProfileEdit()">取消</button>
                            <button class="btn btn-primary" id="profileSaveBtn" type="submit">💾 保存修改</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($section === 'posts'): ?>
                <div class="section-header">
                    <div class="section-title">📝 我的发布</div>
                    <div class="section-desc">管理您发布的所有帖子</div>
                </div>
                <div class="profile-section-card">
                    <div class="posts-tabs">
                        <a class="posts-tab <?php echo $postsFilter === 'all' ? 'active' : ''; ?>" id="postsAllTab" href="<?php echo htmlspecialchars(profile_section_url('posts', ['posts_filter' => 'all'])); ?>">全部 (<?php echo (int) $postsStats['all']; ?>)</a>
                        <a class="posts-tab <?php echo $postsFilter === 'rent' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('posts', ['posts_filter' => 'rent'])); ?>">🏠 租房</a>
                        <a class="posts-tab <?php echo $postsFilter === 'roommate' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('posts', ['posts_filter' => 'roommate'])); ?>">🤝 找室友</a>
                        <a class="posts-tab <?php echo $postsFilter === 'sublet' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('posts', ['posts_filter' => 'sublet'])); ?>">🔄 转租</a>
                    </div>
                    <?php if (empty($filteredMyPosts)): ?>
                        <div class="profile-empty">暂无发布内容。</div>
                    <?php else: ?>
                        <div class="my-posts-list">
                            <?php foreach ($filteredMyPosts as $post): ?>
                                <?php
                                $postStatus = (string) ($post['status'] ?? 'active');
                                $postType = (string) ($post['type'] ?? 'rent');
                                $typeBadgeMap = [
                                    'rent' => ['class' => 'rent', 'text' => '🏠 租房'],
                                    'roommate-source' => ['class' => 'roommate', 'text' => '🏘️ 有房找室友'],
                                    'roommate-nosource' => ['class' => 'roommate', 'text' => '🔍 无房找室友'],
                                    'sublet' => ['class' => 'sublet', 'text' => '🔄 转租'],
                                ];
                                $badge = $typeBadgeMap[$postType] ?? ['class' => 'rent', 'text' => '🏠 租房'];
                                $images = parse_post_images($post['images'] ?? null);
                                $thumb = resolve_post_image_url($images[0] ?? '') ?: 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=300&h=200&fit=crop';
                                $dateText = !empty($post['created_at']) ? date('Y-m-d', strtotime((string) $post['created_at'])) : '-';
                                ?>
                                <div class="my-post-card" data-post-type="<?php echo htmlspecialchars($postType); ?>">
                                    <div class="my-post-header">
                                        <span class="my-post-type-badge <?php echo htmlspecialchars($badge['class']); ?>"><?php echo htmlspecialchars($badge['text']); ?></span>
                                        <span class="my-post-status <?php echo $postStatus === 'active' ? 'active' : 'hidden'; ?>">
                                            <?php echo $postStatus === 'active' ? '🟢 正常显示' : '🟡 已隐藏'; ?>
                                        </span>
                                    </div>

                                    <div class="my-post-body">
                                        <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo htmlspecialchars((string) $post['title']); ?>" class="my-post-image">
                                        <div class="my-post-info">
                                            <a class="my-post-title" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $post['id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $post['id']; ?>);">
                                                <?php echo htmlspecialchars((string) $post['title']); ?>
                                            </a>
                                            <div class="my-post-price"><?php echo number_format((float) $post['price'], 0); ?></div>
                                            <div class="my-post-tags">
                                                <span class="tag tag-region"><?php echo htmlspecialchars((string) ($post['region'] ?: '-')); ?></span>
                                                <span class="tag tag-metro">🚇 <?php echo htmlspecialchars((string) ($post['metro_stations'] ?: '-')); ?></span>
                                                <span class="tag tag-school"><?php echo htmlspecialchars((string) ($post['school_scope'] ?: '-')); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="my-post-actions">
                                        <a class="btn btn-outline btn-small icon-only-btn" href="<?php echo htmlspecialchars(project_base_url('post/edit.php?id=' . (int) $post['id'])); ?>" title="编辑" aria-label="编辑">
                                            <span class="icon-feather" style="--icon-url:url('<?php echo htmlspecialchars(project_base_url('feather/edit.svg')); ?>');"></span>
                                        </a>
                                        <form method="post" class="js-post-status-form">
                                            <input type="hidden" name="action" value="post_set_status">
                                            <input type="hidden" name="section" value="posts">
                                            <input type="hidden" name="posts_filter" value="<?php echo htmlspecialchars($postsFilter); ?>">
                                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                            <input class="js-next-status" type="hidden" name="next_status" value="<?php echo $postStatus === 'active' ? 'hidden' : 'active'; ?>">
                                            <button class="btn btn-outline btn-small icon-only-btn js-post-status-btn" type="submit" title="<?php echo $postStatus === 'active' ? '隐藏' : '显示'; ?>" aria-label="<?php echo $postStatus === 'active' ? '隐藏' : '显示'; ?>">
                                                <span class="icon-feather js-post-status-icon" style="--icon-url:url('<?php echo htmlspecialchars(project_base_url($postStatus === 'active' ? 'feather/eye-off.svg' : 'feather/eye.svg')); ?>');"></span>
                                            </button>
                                        </form>
                                        <form method="post" class="js-post-delete-form" onsubmit="return confirm('确认删除该帖子？');">
                                            <input type="hidden" name="action" value="post_delete">
                                            <input type="hidden" name="section" value="posts">
                                            <input type="hidden" name="posts_filter" value="<?php echo htmlspecialchars($postsFilter); ?>">
                                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                            <button class="btn btn-outline btn-small btn-danger-outline icon-only-btn js-post-delete-btn" type="submit" title="删除" aria-label="删除">
                                                <span class="icon-feather" style="--icon-url:url('<?php echo htmlspecialchars(project_base_url('feather/trash.svg')); ?>');"></span>
                                            </button>
                                        </form>
                                        <span class="my-post-time">发布于 <?php echo htmlspecialchars($dateText); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($section === 'favorites'): ?>
                <div class="section-header">
                    <div class="section-title">❤️ 我的收藏</div>
                    <div class="section-desc">您收藏的帖子</div>
                </div>

                <div class="profile-section-card">
                    <div class="posts-tabs">
                        <a class="posts-tab <?php echo $favoritesFilter === 'all' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('favorites', ['favorites_filter' => 'all'])); ?>">全部 (<?php echo (int) $favoritesStats['all']; ?>)</a>
                        <a class="posts-tab <?php echo $favoritesFilter === 'rent' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('favorites', ['favorites_filter' => 'rent'])); ?>">🏠 租房</a>
                        <a class="posts-tab <?php echo $favoritesFilter === 'roommate' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('favorites', ['favorites_filter' => 'roommate'])); ?>">🤝 找室友</a>
                        <a class="posts-tab <?php echo $favoritesFilter === 'sublet' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('favorites', ['favorites_filter' => 'sublet'])); ?>">🔄 转租</a>
                    </div>
                    <?php if (!$isStudent): ?>
                        <div class="profile-empty">房源供给方不可收藏帖子。</div>
                    <?php elseif (empty($filteredFavorites)): ?>
                        <div class="profile-empty">暂无收藏内容。</div>
                    <?php else: ?>
                        <div class="favorites-list">
                            <?php foreach ($filteredFavorites as $fav): ?>
                                <?php
                                $images = parse_post_images($fav['images'] ?? null);
                                $thumb = resolve_post_image_url($images[0] ?? '') ?: 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=300&h=200&fit=crop';
                                $favDate = !empty($fav['favorite_created_at']) ? date('Y-m-d', strtotime((string) $fav['favorite_created_at'])) : '-';
                                ?>
                                <div class="fav-card">
                                    <div class="fav-card-body">
                                        <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo htmlspecialchars((string) $fav['title']); ?>" class="fav-card-image">
                                        <div class="fav-card-info">
                                            <a class="fav-card-title" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $fav['post_id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $fav['post_id']; ?>);">
                                                <?php echo htmlspecialchars((string) $fav['title']); ?>
                                            </a>
                                            <div class="fav-card-price"><?php echo number_format((float) $fav['price'], 0); ?></div>
                                            <div class="my-post-tags">
                                                <span class="tag tag-region"><?php echo htmlspecialchars((string) ($fav['region'] ?: '-')); ?></span>
                                                <span class="tag tag-school"><?php echo htmlspecialchars((string) ($fav['school_scope'] ?: '-')); ?></span>
                                            </div>
                                            <div class="fav-card-meta">收藏于 <?php echo htmlspecialchars($favDate); ?></div>
                                        </div>
                                        <div class="fav-card-actions">
                                            <a class="btn btn-primary btn-small" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $fav['post_id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $fav['post_id']; ?>);">查看详情</a>
                                            <form method="post">
                                                <input type="hidden" name="action" value="favorite_remove">
                                                <input type="hidden" name="section" value="favorites">
                                                <input type="hidden" name="favorites_filter" value="<?php echo htmlspecialchars($favoritesFilter); ?>">
                                                <input type="hidden" name="post_id" value="<?php echo (int) $fav['post_id']; ?>">
                                                <button class="btn btn-outline btn-small btn-danger-outline" type="submit">取消收藏</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($section === 'applications'): ?>
                <div class="section-header">
                    <div class="section-title">📋 我的申请</div>
                    <div class="section-desc">您发送的申请记录</div>
                </div>

                <div class="profile-section-card">
                    <div class="posts-tabs">
                        <a class="posts-tab <?php echo $applicationsFilter === 'all' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('applications', ['applications_filter' => 'all'])); ?>">全部 (<?php echo (int) $applicationsStats['all']; ?>)</a>
                        <a class="posts-tab <?php echo $applicationsFilter === 'rent' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('applications', ['applications_filter' => 'rent'])); ?>">🏠 租房</a>
                        <a class="posts-tab <?php echo $applicationsFilter === 'roommate' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('applications', ['applications_filter' => 'roommate'])); ?>">🤝 找室友</a>
                        <a class="posts-tab <?php echo $applicationsFilter === 'sublet' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('applications', ['applications_filter' => 'sublet'])); ?>">🔄 转租</a>
                    </div>
                    <?php if (!$isStudent): ?>
                        <div class="profile-empty">房源供给方不可发送申请。</div>
                    <?php elseif (empty($filteredApplications)): ?>
                        <div class="profile-empty">暂无申请记录。</div>
                    <?php else: ?>
                        <div class="applications-list">
                            <?php foreach ($filteredApplications as $app): ?>
                                <?php $appStatus = (string) ($app['status'] ?? 'pending'); ?>
                                <?php
                                $appBadgeMap = [
                                    'rent' => ['bg' => 'var(--rent-color)', 'text' => '🏠 租房'],
                                    'roommate-source' => ['bg' => 'var(--roommate-source)', 'text' => '🏘️ 找室友'],
                                    'roommate-nosource' => ['bg' => '#2ECC71', 'text' => '🔍 找室友'],
                                    'sublet' => ['bg' => 'var(--sublet-color)', 'text' => '🔄 转租'],
                                ];
                                $badge = $appBadgeMap[$app['post_type'] ?? 'rent'] ?? $appBadgeMap['rent'];
                                $statusCls = in_array($appStatus, ['pending', 'accepted', 'rejected', 'withdrawn'], true) ? $appStatus : 'pending';
                                ?>
                                <div class="app-card">
                                    <div class="app-card-header">
                                        <div class="app-card-post">
                                            <span class="app-card-post-badge" style="background:<?php echo htmlspecialchars($badge['bg']); ?>;"><?php echo htmlspecialchars($badge['text']); ?></span>
                                            <a class="app-card-post-title" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $app['post_id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $app['post_id']; ?>);">
                                                <?php echo htmlspecialchars((string) $app['title']); ?>
                                            </a>
                                        </div>
                                        <span class="app-status <?php echo htmlspecialchars($statusCls); ?>"><?php echo htmlspecialchars($applicationStatusMap[$appStatus] ?? $appStatus); ?></span>
                                    </div>

                                    <div class="app-card-body">
                                        <div class="app-card-label">申请理由</div>
                                        <div class="app-card-message"><?php echo htmlspecialchars((string) ($app['message'] ?: '无')); ?></div>
                                        <?php if ($appStatus === 'accepted' && !empty($app['owner_phone'])): ?>
                                            <div class="profile-contact">📞 联系方式：<?php echo htmlspecialchars((string) $app['owner_phone']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="app-card-footer">
                                        <span class="app-card-time">🕐 <?php echo htmlspecialchars(!empty($app['created_at']) ? date('Y-m-d H:i', strtotime((string) $app['created_at'])) : '-'); ?></span>
                                        <div class="app-card-actions">
                                            <?php if ($appStatus === 'pending'): ?>
                                                <form method="post" onsubmit="return confirm('确认撤回该申请？');">
                                                    <input type="hidden" name="action" value="application_withdraw">
                                                    <input type="hidden" name="section" value="applications">
                                                    <input type="hidden" name="applications_filter" value="<?php echo htmlspecialchars($applicationsFilter); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $app['id']; ?>">
                                                    <button class="btn btn-outline btn-small" type="submit">↩️ 撤回</button>
                                                </form>
                                            <?php endif; ?>
                                            <a class="btn btn-outline btn-small" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $app['post_id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $app['post_id']; ?>);">查看帖子</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($section === 'received'): ?>
                <div class="section-header">
                    <div class="section-title">📨 收到申请</div>
                    <div class="section-desc">他人对您发布帖子的申请</div>
                </div>

                <div class="profile-section-card">
                    <div class="posts-tabs">
                        <a class="posts-tab <?php echo $receivedFilter === 'all' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('received', ['received_filter' => 'all'])); ?>">全部 (<?php echo (int) $receivedStats['all']; ?>)</a>
                        <a class="posts-tab <?php echo $receivedFilter === 'rent' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('received', ['received_filter' => 'rent'])); ?>">🏠 租房</a>
                        <a class="posts-tab <?php echo $receivedFilter === 'roommate' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('received', ['received_filter' => 'roommate'])); ?>">🤝 找室友</a>
                        <a class="posts-tab <?php echo $receivedFilter === 'sublet' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(profile_section_url('received', ['received_filter' => 'sublet'])); ?>">🔄 转租</a>
                    </div>
                    <?php if (empty($filteredReceived)): ?>
                        <div class="profile-empty">暂无收到的申请。</div>
                    <?php else: ?>
                        <div class="received-list">
                            <?php foreach ($filteredReceived as $received): ?>
                                <?php
                                $receivedStatus = (string) ($received['status'] ?? 'pending');
                                $receivedStatusCls = in_array($receivedStatus, ['pending', 'accepted', 'rejected'], true) ? $receivedStatus : 'pending';
                                $receivedBadgeMap = [
                                    'rent' => ['bg' => 'var(--rent-color)', 'text' => '🏠 租房'],
                                    'roommate-source' => ['bg' => 'var(--roommate-source)', 'text' => '🏘️ 找室友'],
                                    'roommate-nosource' => ['bg' => '#2ECC71', 'text' => '🔍 找室友'],
                                    'sublet' => ['bg' => 'var(--sublet-color)', 'text' => '🔄 转租'],
                                ];
                                $rBadge = $receivedBadgeMap[$received['post_type'] ?? 'rent'] ?? $receivedBadgeMap['rent'];
                                $applicantSchoolText = school_display_name($received['applicant_school'] ?? null);
                                ?>
                                <div class="received-card" data-applicant-phone="<?php echo htmlspecialchars((string) ($received['applicant_phone'] ?? '')); ?>">
                                    <div class="received-header">
                                        <div class="received-applicant">
                                            <div class="received-applicant-avatar"><?php echo htmlspecialchars(mb_substr((string) $received['applicant_name'], 0, 1, 'UTF-8')); ?></div>
                                            <div>
                                                <div class="received-applicant-name"><?php echo htmlspecialchars((string) $received['applicant_name']); ?></div>
                                                <?php if ($applicantSchoolText !== ''): ?>
                                                    <div class="received-applicant-school">🎓 <?php echo htmlspecialchars($applicantSchoolText); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="app-status <?php echo htmlspecialchars($receivedStatusCls); ?>"><?php echo htmlspecialchars($applicationStatusMap[$receivedStatus] ?? $receivedStatus); ?></span>
                                    </div>

                                    <a class="received-post-ref" href="<?php echo htmlspecialchars(project_base_url('post/detail.php?id=' . (int) $received['post_id'])); ?>" onclick="event.preventDefault(); openProfilePostDetail(<?php echo (int) $received['post_id']; ?>);">
                                        <span class="received-post-ref-badge" style="background:<?php echo htmlspecialchars($rBadge['bg']); ?>;"><?php echo htmlspecialchars($rBadge['text']); ?></span>
                                        <span class="received-post-ref-title"><?php echo htmlspecialchars((string) $received['post_title']); ?></span>
                                        <span class="received-post-ref-arrow">›</span>
                                    </a>

                                    <div class="app-card-body">
                                        <div class="app-card-label">申请理由</div>
                                        <div class="app-card-message"><?php echo htmlspecialchars((string) ($received['message'] ?: '无')); ?></div>
                                    </div>

                                    <div class="app-card-footer">
                                        <span class="app-card-time">🕐 <?php echo htmlspecialchars(!empty($received['created_at']) ? date('Y-m-d H:i', strtotime((string) $received['created_at'])) : '-'); ?></span>
                                        <div class="app-card-actions">
                                            <?php if ($receivedStatus === 'pending'): ?>
                                                <form class="js-received-process-form" method="post">
                                                    <input type="hidden" name="action" value="received_process">
                                                    <input type="hidden" name="section" value="received">
                                                    <input type="hidden" name="received_filter" value="<?php echo htmlspecialchars($receivedFilter); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $received['id']; ?>">
                                                    <input type="hidden" name="decision" value="accepted">
                                                    <button class="btn btn-primary btn-small js-received-process-btn" type="submit">✅ 同意</button>
                                                </form>
                                                <form class="js-received-process-form" method="post">
                                                    <input type="hidden" name="action" value="received_process">
                                                    <input type="hidden" name="section" value="received">
                                                    <input type="hidden" name="received_filter" value="<?php echo htmlspecialchars($receivedFilter); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $received['id']; ?>">
                                                    <input type="hidden" name="decision" value="rejected">
                                                    <button class="btn btn-outline btn-small btn-danger-outline js-received-process-btn" type="submit">❌ 拒绝</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($receivedStatus === 'accepted' && !empty($received['applicant_phone'])): ?>
                                        <div class="received-contact visible">
                                            <div style="font-weight:600;margin-bottom:4px;">📞 申请人联系方式</div>
                                            <div class="received-contact-item">📱 <?php echo htmlspecialchars((string) $received['applicant_phone']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="modal-overlay" id="profileDetailModal">
    <div class="modal detail-modal">
        <div class="modal-header">
            <div class="modal-title-group">
                <span class="card-badge badge-rent" id="profileDetailBadge">🏠 租房</span>
                <h2 class="modal-title">房源详情</h2>
            </div>
            <button class="modal-close" onclick="closeModal('profileDetailModal')">×</button>
        </div>
        <div class="detail-slider" id="profileDetailSlider">
            <button class="slider-arrow slider-arrow-prev" id="profileSliderPrevBtn" type="button" onclick="profileSliderPrev()">&#8249;</button>
            <img id="profileDetailSliderImage" src="" alt="房源图片">
            <button class="slider-arrow slider-arrow-next" id="profileSliderNextBtn" type="button" onclick="profileSliderNext()">&#8250;</button>
            <div class="slider-dots" id="profileSliderDots"></div>
        </div>
        <div class="detail-info">
            <div class="detail-badge-row">
                <div class="card-tags">
                    <span class="tag tag-region" id="profileDetailRegion">-</span>
                    <span class="tag tag-metro" id="profileDetailMetro">🚇 -</span>
                    <span class="tag tag-school" id="profileDetailSchool">-</span>
                </div>
            </div>
            <h3 class="detail-title" id="profileDetailTitle">-</h3>
            <div class="detail-price"><span id="profileDetailPrice">0</span> <span id="profileDetailPriceLabel" style="font-size:0.875rem;font-weight:400;color:var(--text-secondary);">HKD/月</span></div>
            <div class="detail-meta">
                <div class="meta-item" id="profileDetailFloorItem"><div class="meta-icon">📍</div><div><div class="meta-label">楼层</div><div class="meta-value" id="profileDetailFloor">-</div></div></div>
                <div class="meta-item" id="profileDetailPeriodItem"><div class="meta-icon">📅</div><div><div class="meta-label">租期</div><div class="meta-value" id="profileDetailPeriod">-</div></div></div>
                <div class="meta-item"><div class="meta-icon">🏫</div><div><div class="meta-label">学校范围</div><div class="meta-value" id="profileDetailSchoolMeta">-</div></div></div>
                <div class="meta-item"><div class="meta-icon">🚇</div><div><div class="meta-label">地铁站</div><div class="meta-value" id="profileDetailMetroMeta">-</div></div></div>
                <div class="meta-item" id="profileDetailGenderReqItem" style="display:none"><div class="meta-icon">👤</div><div><div class="meta-label">性别要求</div><div class="meta-value" id="profileDetailGenderReq">-</div></div></div>
                <div class="meta-item" id="profileDetailNeedCountItem" style="display:none"><div class="meta-icon">👥</div><div><div class="meta-label">需求人数</div><div class="meta-value" id="profileDetailNeedCount">-</div></div></div>
                <div class="meta-item" id="profileDetailRemainingMonthsItem" style="display:none"><div class="meta-icon">⏳</div><div><div class="meta-label">剩余租期</div><div class="meta-value" id="profileDetailRemainingMonths">-</div></div></div>
                <div class="meta-item" id="profileDetailMoveInDateItem" style="display:none"><div class="meta-icon">🗓️</div><div><div class="meta-label">最早入住日期</div><div class="meta-value" id="profileDetailMoveInDate">-</div></div></div>
                <div class="meta-item" id="profileDetailRenewableItem" style="display:none"><div class="meta-icon">♻️</div><div><div class="meta-label">是否可续租</div><div class="meta-value" id="profileDetailRenewable">-</div></div></div>
            </div>
            <div class="detail-desc" id="profileDetailDesc">暂无描述。</div>
            <div class="detail-author">
                <div class="author-info">
                    <div class="author-detail-avatar" id="profileDetailAuthorAvatar">?</div>
                    <div>
                        <div class="author-detail-name" id="profileDetailAuthorName">匿名用户</div>
                        <div class="author-detail-role" id="profileDetailAuthorRole">-</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-sm-muted" id="profileDetailCreatedDate"></div>
                    <div class="text-primary-strong" id="profileDetailContact">-</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleProfileEdit() {
    const form = document.getElementById('profileEditForm');
    const btn = document.getElementById('profileEditBtn');
    const actions = document.getElementById('profileActions');
    if (!form || !btn || !actions) return;

    const isReadonly = form.classList.contains('profile-form-readonly');
    const fields = form.querySelectorAll('.profile-input-editable');

    if (isReadonly) {
        form.classList.remove('profile-form-readonly');
        btn.textContent = '取消编辑';
        actions.classList.add('active');
        fields.forEach(function(field) { field.disabled = false; });
    } else {
        cancelProfileEdit();
    }
}

function cancelProfileEdit() {
    const form = document.getElementById('profileEditForm');
    const btn = document.getElementById('profileEditBtn');
    const actions = document.getElementById('profileActions');
    if (!form || !btn || !actions) return;

    form.reset();
    form.classList.add('profile-form-readonly');
    actions.classList.remove('active');
    btn.textContent = '✏️ 编辑';
    form.querySelectorAll('.profile-input-editable').forEach(function(field) {
        field.disabled = true;
    });
}

let profileDetailImages = [];
let profileDetailIndex = 0;

function profileSetText(id, text) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
}

function profileRenderSlider() {
    const img = document.getElementById('profileDetailSliderImage');
    const dots = document.getElementById('profileSliderDots');
    const prevBtn = document.getElementById('profileSliderPrevBtn');
    const nextBtn = document.getElementById('profileSliderNextBtn');
    if (!img) return;

    img.src = profileDetailImages[profileDetailIndex] || '';
    if (dots) {
        dots.innerHTML = '';
        if (profileDetailImages.length > 1) {
            profileDetailImages.forEach(function(_, i) {
                const dot = document.createElement('span');
                dot.className = 'slider-dot' + (i === profileDetailIndex ? ' active' : '');
                dot.onclick = function() { profileDetailIndex = i; profileRenderSlider(); };
                dots.appendChild(dot);
            });
        }
    }
    if (prevBtn) prevBtn.classList.toggle('hidden', profileDetailImages.length <= 1 || profileDetailIndex === 0);
    if (nextBtn) nextBtn.classList.toggle('hidden', profileDetailImages.length <= 1 || profileDetailIndex === profileDetailImages.length - 1);
}

function profileSliderPrev() {
    if (profileDetailIndex > 0) {
        profileDetailIndex--;
        profileRenderSlider();
    }
}

function profileSliderNext() {
    if (profileDetailIndex < profileDetailImages.length - 1) {
        profileDetailIndex++;
        profileRenderSlider();
    }
}

function openProfilePostDetail(postId) {
    if (!postId) return;
    fetch(<?php echo json_encode(project_base_url('post/detail_api.php'), JSON_UNESCAPED_UNICODE); ?> + '?id=' + encodeURIComponent(postId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (!result || result.success !== true || !result.data) {
                throw new Error(result && result.message ? result.message : '加载详情失败。');
            }
            const data = result.data;
            const type = data.type || 'rent';
            const isSublet = type === 'sublet';
            profileSetText('profileDetailTitle', data.title || '-');
            profileSetText('profileDetailPrice', data.price || '0');
            profileSetText('profileDetailPriceLabel', data.price_label || 'HKD/月');
            profileSetText('profileDetailRegion', data.region || '-');
            profileSetText('profileDetailMetro', '🚇 ' + (data.metro || '-'));
            profileSetText('profileDetailSchool', data.school || '-');
            profileSetText('profileDetailFloor', data.floor || '-');
            profileSetText('profileDetailPeriod', data.period || '-');
            profileSetText('profileDetailGenderReq', data.gender_req || '-');
            profileSetText('profileDetailNeedCount', data.need_count || '-');
            profileSetText('profileDetailRemainingMonths', data.remaining_months || '-');
            profileSetText('profileDetailMoveInDate', data.move_in_date || '-');
            profileSetText('profileDetailRenewable', data.renewable || '-');
            profileSetText('profileDetailSchoolMeta', data.school || '-');
            profileSetText('profileDetailMetroMeta', data.metro || '-');
            profileSetText('profileDetailDesc', data.content || '暂无描述。');
            profileSetText('profileDetailAuthorAvatar', data.author_initial || '?');
            profileSetText('profileDetailAuthorName', data.author || '匿名用户');
            profileSetText('profileDetailAuthorRole', data.author_role || '-');
            profileSetText('profileDetailCreatedDate', data.created_date ? ('发布于 ' + data.created_date) : '');
            profileSetText('profileDetailContact', data.contact || '-');

            const badge = document.getElementById('profileDetailBadge');
            if (badge) {
                badge.className = 'card-badge';
                if (type === 'roommate-source') {
                    badge.classList.add('badge-roommate-source');
                    badge.textContent = '🏘️ 有房找室友';
                } else if (type === 'roommate-nosource') {
                    badge.classList.add('badge-roommate-nosource');
                    badge.textContent = '🔍 无房找室友';
                } else if (type === 'sublet') {
                    badge.classList.add('badge-sublet');
                    badge.textContent = '🔄 转租';
                } else {
                    badge.classList.add('badge-rent');
                    badge.textContent = '🏠 租房';
                }
            }

            const slider = document.getElementById('profileDetailSlider');
            if (slider) {
                slider.style.display = type === 'roommate-nosource' ? 'none' : '';
            }
            const floorItem = document.getElementById('profileDetailFloorItem');
            if (floorItem) {
                floorItem.style.display = type === 'roommate-nosource' ? 'none' : '';
            }
            const periodItem = document.getElementById('profileDetailPeriodItem');
            if (periodItem) {
                periodItem.style.display = isSublet ? 'none' : '';
            }
            const genderItem = document.getElementById('profileDetailGenderReqItem');
            if (genderItem) {
                genderItem.style.display = (type === 'roommate-source' || type === 'roommate-nosource' || isSublet) ? '' : 'none';
            }
            const needCountItem = document.getElementById('profileDetailNeedCountItem');
            if (needCountItem) {
                needCountItem.style.display = type === 'roommate-source' ? '' : 'none';
            }
            const remainingMonthsItem = document.getElementById('profileDetailRemainingMonthsItem');
            if (remainingMonthsItem) {
                remainingMonthsItem.style.display = isSublet ? '' : 'none';
            }
            const moveInDateItem = document.getElementById('profileDetailMoveInDateItem');
            if (moveInDateItem) {
                moveInDateItem.style.display = isSublet ? '' : 'none';
            }
            const renewableItem = document.getElementById('profileDetailRenewableItem');
            if (renewableItem) {
                renewableItem.style.display = isSublet ? '' : 'none';
            }

            profileDetailImages = [];
            if (type !== 'roommate-nosource') {
                if (data.image_main) profileDetailImages.push(data.image_main);
                if (data.image_thumb1 && data.image_thumb1 !== data.image_main) profileDetailImages.push(data.image_thumb1);
                if (data.image_thumb2 && data.image_thumb2 !== data.image_thumb1 && data.image_thumb2 !== data.image_main) profileDetailImages.push(data.image_thumb2);
            }
            if (profileDetailImages.length === 0) profileDetailImages = [''];
            profileDetailIndex = 0;
            profileRenderSlider();

            openModal('profileDetailModal');
        })
        .catch(function(error) {
            if (typeof showToast === 'function') {
                showToast(error.message || '加载详情失败，请稍后重试。', 'error');
            }
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const profileEditForm = document.getElementById('profileEditForm');
    if (profileEditForm) {
        profileEditForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const saveBtn = document.getElementById('profileSaveBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
            }

            const formData = new FormData(profileEditForm);
            fetch(<?php echo json_encode(profile_section_url('profile', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : '保存失败，请稍后重试。');
                    }

                    const nextUsername = String(data.username || '').trim();
                    const nextPhone = String(data.phone || '').trim();
                    const nextAvatarInitial = String(data.avatar_initial || '').trim();

                    const usernameInput = document.getElementById('profileUsernameInput');
                    const phoneInput = document.getElementById('profilePhoneInput');
                    const sidebarName = document.getElementById('sidebarProfileName');
                    const sidebarAvatar = document.getElementById('sidebarProfileAvatar');
                    const profileAvatarLarge = document.getElementById('profileAvatarLarge');
                    const headerUserNavName = document.getElementById('headerUserNavName');
                    const headerDropdownName = document.getElementById('headerDropdownName');
                    const headerUserAvatar = document.getElementById('headerUserAvatar');

                    if (usernameInput) {
                        usernameInput.value = nextUsername;
                        usernameInput.defaultValue = nextUsername;
                    }
                    if (phoneInput) {
                        phoneInput.value = nextPhone;
                        phoneInput.defaultValue = nextPhone;
                    }
                    if (sidebarName) sidebarName.textContent = nextUsername;
                    if (sidebarAvatar && nextAvatarInitial !== '') {
                        sidebarAvatar.textContent = nextAvatarInitial;
                    }
                    if (profileAvatarLarge && nextAvatarInitial !== '') {
                        profileAvatarLarge.innerHTML = nextAvatarInitial + '<div class="profile-avatar-edit-btn">📷</div>';
                    }
                    if (headerUserNavName) {
                        headerUserNavName.textContent = nextUsername;
                    }
                    if (headerDropdownName) {
                        headerDropdownName.textContent = nextUsername;
                    }
                    if (headerUserAvatar && nextAvatarInitial !== '') {
                        headerUserAvatar.textContent = nextAvatarInitial;
                    }

                    if (typeof showToast === 'function') {
                        showToast(data.message || '个人信息已更新。', 'success');
                    }
                    cancelProfileEdit();
                })
                .catch(function(error) {
                    if (typeof showToast === 'function') {
                        showToast(error.message || '保存失败，请稍后重试。', 'error');
                    }
                })
                .finally(function() {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                    }
                });
        });
    }

    document.querySelectorAll('.js-post-status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('.js-post-status-btn');
            const nextStatusInput = form.querySelector('.js-next-status');
            if (!submitBtn || !nextStatusInput) return;

            submitBtn.disabled = true;
            const formData = new FormData(form);

            fetch(<?php echo json_encode(profile_section_url('posts', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : '操作失败，请稍后重试。');
                    }

                    const card = form.closest('.my-post-card');
                    const statusBadge = card ? card.querySelector('.my-post-status') : null;
                    const icon = form.querySelector('.js-post-status-icon');
                    const nextStatus = data.next_status === 'active' ? 'active' : 'hidden';
                    const currentStatus = data.status === 'active' ? 'active' : 'hidden';

                    nextStatusInput.value = nextStatus;

                    if (statusBadge) {
                        statusBadge.classList.remove('active', 'hidden');
                        statusBadge.classList.add(currentStatus);
                        statusBadge.textContent = currentStatus === 'active' ? '🟢 正常显示' : '🟡 已隐藏';
                    }

                    if (icon) {
                        const iconUrl = currentStatus === 'active'
                            ? "<?php echo htmlspecialchars(project_base_url('feather/eye-off.svg')); ?>"
                            : "<?php echo htmlspecialchars(project_base_url('feather/eye.svg')); ?>";
                        icon.style.setProperty('--icon-url', "url('" + iconUrl + "')");
                    }

                    const label = currentStatus === 'active' ? '隐藏' : '显示';
                    submitBtn.title = label;
                    submitBtn.setAttribute('aria-label', label);

                    if (typeof showToast === 'function') {
                        showToast(data.message || (currentStatus === 'active' ? '帖子已恢复展示。' : '帖子已隐藏。'), 'success');
                    }
                })
                .catch(function(error) {
                    if (typeof showToast === 'function') {
                        showToast(error.message || '操作失败，请稍后重试。', 'error');
                    }
                })
                .finally(function() {
                    submitBtn.disabled = false;
                });
        });
    });

    document.querySelectorAll('.js-post-delete-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('.js-post-delete-btn');
            if (!submitBtn) return;
            submitBtn.disabled = true;

            const formData = new FormData(form);
            fetch(<?php echo json_encode(profile_section_url('posts', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : '删除失败，请稍后重试。');
                    }

                    const card = form.closest('.my-post-card');
                    if (card) {
                        card.remove();
                    }

                    const allTab = document.getElementById('postsAllTab');
                    if (allTab) {
                        const match = (allTab.textContent || '').match(/\((\d+)\)/);
                        if (match) {
                            const next = Math.max(0, Number(match[1]) - 1);
                            allTab.textContent = '全部 (' + String(next) + ')';
                        }
                    }

                    const postsCountEl = document.getElementById('profilePostsCount');
                    if (postsCountEl) {
                        const current = Number(postsCountEl.textContent || '0');
                        postsCountEl.textContent = String(Math.max(0, current - 1));
                    }

                    const list = document.querySelector('.my-posts-list');
                    if (list && list.querySelectorAll('.my-post-card').length === 0) {
                        list.innerHTML = '<div class="profile-empty">暂无发布内容。</div>';
                    }

                    if (typeof showToast === 'function') {
                        showToast(data.message || '帖子已删除。', 'success');
                    }
                })
                .catch(function(error) {
                    if (typeof showToast === 'function') {
                        showToast(error.message || '删除失败，请稍后重试。', 'error');
                    }
                })
                .finally(function() {
                    submitBtn.disabled = false;
                });
        });
    });

    document.querySelectorAll('.js-received-process-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const card = form.closest('.received-card');
            const actionsWrap = card ? card.querySelector('.app-card-actions') : null;
            const statusEl = card ? card.querySelector('.app-status') : null;
            const submitButtons = actionsWrap ? actionsWrap.querySelectorAll('.js-received-process-btn') : [];
            submitButtons.forEach(function(btn) { btn.disabled = true; });

            const formData = new FormData(form);
            fetch(<?php echo json_encode(profile_section_url('received', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : '操作失败，请稍后重试。');
                    }

                    const nextStatus = data.status === 'accepted' ? 'accepted' : 'rejected';
                    if (statusEl) {
                        statusEl.classList.remove('pending', 'accepted', 'rejected');
                        statusEl.classList.add(nextStatus);
                        statusEl.textContent = nextStatus === 'accepted' ? '已同意' : '已拒绝';
                    }

                    if (actionsWrap) {
                        actionsWrap.innerHTML = '';
                    }

                    if (nextStatus === 'accepted' && card) {
                        const phone = (card.getAttribute('data-applicant-phone') || '').trim();
                        if (phone !== '' && !card.querySelector('.received-contact')) {
                            const contactBlock = document.createElement('div');
                            contactBlock.className = 'received-contact visible';
                            contactBlock.innerHTML = '<div style="font-weight:600;margin-bottom:4px;">📞 申请人联系方式</div><div class="received-contact-item">📱 ' + phone + '</div>';
                            card.appendChild(contactBlock);
                        }
                    }

                    if (typeof showToast === 'function') {
                        showToast(data.message || (nextStatus === 'accepted' ? '已同意申请。' : '已拒绝申请。'), 'success');
                    }
                })
                .catch(function(error) {
                    if (typeof showToast === 'function') {
                        showToast(error.message || '操作失败，请稍后重试。', 'error');
                    }
                })
                .finally(function() {
                    submitButtons.forEach(function(btn) { btn.disabled = false; });
                });
        });
    });
});

<?php if ($notice !== '' && in_array($noticeType, ['success', 'error'], true) && $noticeMode === 'toast'): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof showToast === 'function') {
        showToast(<?php echo json_encode($notice, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode($noticeType, JSON_UNESCAPED_UNICODE); ?>);
    }

    if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('notice');
        url.searchParams.delete('notice_type');
        url.searchParams.delete('notice_mode');
        window.history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }
});
<?php endif; ?>
</script>

<style>
.profile-page { max-width:none; padding:0; }
.profile-layout { max-width:1280px; margin:0 auto; padding:24px 20px; display:flex; gap:24px; min-height:calc(100vh - 64px); }
.profile-sidebar { width:280px; flex-shrink:0; position:sticky; top:88px; height:fit-content; }

.sidebar-profile-card { background:var(--white); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); overflow:hidden; margin-bottom:0; }
.sidebar-profile-banner { height:80px; background:linear-gradient(135deg,var(--primary),var(--secondary)); position:relative; }
.sidebar-profile-avatar { width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:700; border:4px solid var(--white); position:absolute; bottom:-36px; left:50%; transform:translateX(-50%); box-shadow:var(--shadow-md); }
.sidebar-profile-info { padding:44px 20px 20px; text-align:center; }
.sidebar-profile-name { font-size:18px; font-weight:700; margin-bottom:4px; }
.sidebar-profile-role { font-size:13px; color:var(--text-secondary); margin-bottom:12px; }
.sidebar-profile-stats { display:flex; border-top:1px solid var(--border); padding:16px 0; }
.sidebar-stat { flex:1; text-align:center; border-right:1px solid var(--border); }
.sidebar-stat:last-child { border-right:none; }
.sidebar-stat-num { font-size:20px; font-weight:700; color:var(--primary); }
.sidebar-stat-label { font-size:12px; color:var(--text-hint); margin-top:2px; }

.sidebar-nav { background:transparent; border-radius:0; box-shadow:none; overflow:hidden; border-top:1px solid var(--border); }
.sidebar-nav-title { padding:16px 20px 8px; font-size:12px; font-weight:600; color:var(--text-hint); letter-spacing:1px; text-transform:uppercase; }
.sidebar-nav-item { display:flex; align-items:center; gap:12px; padding:14px 20px; border-left:3px solid transparent; color:var(--text-secondary); font-size:14px; font-weight:500; transition:all .2s; }
.sidebar-nav-item:hover { background:var(--bg-gray); color:var(--text-primary); }
.sidebar-nav-item.active { background:var(--primary-bg); color:var(--primary); border-left-color:var(--primary); font-weight:600; }
.sidebar-nav-item .nav-icon { width:32px; height:32px; border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:center; font-size:16px; background:var(--bg-gray); }
.sidebar-nav-item.active .nav-icon { background:var(--primary-bg); }
.sidebar-nav-divider { height:1px; background:var(--border); margin:4px 16px; }
.sidebar-nav-item .nav-badge { margin-left:auto; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; background:var(--danger); color:#fff; min-width:20px; text-align:center; }

.profile-content { flex:1; min-width:0; }
.section-header { background:var(--white); border-radius:var(--radius-lg); padding:20px 24px; margin-bottom:20px; box-shadow:var(--shadow-sm); }
.section-title { font-size:20px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:10px; }
.section-desc { font-size:13px; color:var(--text-hint); margin-top:4px; }

.profile-section-card { background:var(--white); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); padding:24px; min-height:360px; }
.profile-alert { padding:12px 14px; border-radius:10px; margin-bottom:12px; font-size:14px; }
.profile-alert.success { background:#e7f8ef; color:#207a47; }
.profile-alert.error { background:#fff2f2; color:#b02626; }
.profile-form-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid var(--border); }
.profile-form-title { font-size:16px; font-weight:600; color:var(--text-primary); }
.profile-edit-btn { border:2px solid var(--border); background:#fff; color:var(--text-primary); border-radius:var(--radius-xl); padding:6px 14px; font-size:12px; font-weight:600; transition:all .2s; }
.profile-edit-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-bg); }
.profile-avatar-edit { text-align:center; margin-bottom:24px; }
.profile-avatar-large { width:96px; height:96px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--secondary)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:40px; font-weight:700; margin:0 auto; position:relative; box-shadow:var(--shadow-md); }
.profile-avatar-edit-btn { position:absolute; bottom:0; right:0; width:28px; height:28px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; border:2px solid var(--white); }
.profile-form { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { margin-bottom:0; }
.profile-form-label { font-size:13px; color:var(--text-secondary); font-weight:600; }
.profile-form-label .required { color: var(--danger); margin-left: 2px; }
.profile-input { width:100%; height:48px; border:2px solid #DFE6E9; border-radius:var(--radius-md); padding:12px 14px; font-size:14px; color:var(--text-primary); transition: border-color 0.2s; }
.profile-select { padding:12px 36px 12px 14px; background:var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23636E72' d='M2.5 4.5L6 8l3.5-3.5'/%3E%3C/svg%3E") no-repeat right 12px center; appearance:none; cursor:pointer; }
.profile-input:focus { border-color: var(--primary); }
.profile-input:disabled { background: var(--bg-gray); color: var(--text-hint); cursor: not-allowed; }
.profile-field-hint { font-size:12px; color:var(--text-hint); margin-top:2px; }
.profile-actions { grid-column:1 / -1; margin-top:20px; padding-top:16px; border-top:1px solid var(--border); display:none; justify-content:flex-end; gap:12px; }
.profile-actions.active { display:flex; }
.profile-actions .btn { min-width:108px; height:40px; }

.posts-tabs { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
.posts-tab { padding:8px 18px; border-radius:20px; font-size:13px; font-weight:600; cursor:pointer; background:var(--white); color:var(--text-secondary); border:2px solid var(--border); transition:all .2s; }
.posts-tab:hover { border-color:var(--primary); color:var(--primary); }
.posts-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

.my-posts-list { display:flex; flex-direction:column; gap:16px; }
.my-post-card { background:var(--white); border-radius:var(--radius-lg); border:1px solid var(--border); padding:20px; transition:box-shadow .2s; }
.my-post-card:hover { box-shadow:var(--shadow-md); }
.my-post-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.my-post-type-badge { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; color:#fff; }
.my-post-type-badge.rent { background:var(--rent-color); }
.my-post-type-badge.roommate { background:var(--roommate-source); }
.my-post-type-badge.sublet { background:var(--sublet-color); }
.my-post-status { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:500; }
.my-post-status.active { background:#E8F5E9; color:#2E7D32; }
.my-post-status.hidden { background:#FFF3E0; color:#E65100; }
.my-post-body { display:flex; gap:16px; }
.my-post-image { width:140px; height:100px; border-radius:var(--radius-md); object-fit:cover; flex-shrink:0; }
.my-post-info { flex:1; min-width:0; }
.my-post-title { display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; font-size:15px; font-weight:600; color:var(--text-primary); margin-bottom:6px; }
.my-post-price { font-size:18px; font-weight:700; color:var(--primary); margin-bottom:8px; }
.my-post-price::after { content:'/月'; font-size:12px; font-weight:400; color:var(--text-secondary); margin-left:2px; }
.my-post-tags { display:flex; flex-wrap:wrap; gap:6px; }
.my-post-actions { display:flex; gap:8px; margin-top:16px; border-top:1px solid var(--border); padding-top:5px; align-items:center; }
.my-post-actions .btn { font-size:12px; padding:6px 14px; }
.icon-only-btn {
    width:36px;
    height:36px;
    min-width:36px;
    padding:0 !important;
    border:none !important;
    background:transparent !important;
    box-shadow:none !important;
    border-radius:10px;
    line-height:1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.icon-only-btn:hover { background:rgba(178,190,195,0.12) !important; }
.icon-feather {
    width:17px;
    height:17px;
    display:block;
    background-color:var(--text-hint);
    -webkit-mask-image:var(--icon-url);
    mask-image:var(--icon-url);
    -webkit-mask-repeat:no-repeat;
    mask-repeat:no-repeat;
    -webkit-mask-position:center;
    mask-position:center;
    -webkit-mask-size:contain;
    mask-size:contain;
}
.my-post-time { margin-left:auto; font-size:12px; color:var(--text-hint); }
.btn-danger-outline { color:var(--danger); border-color:var(--danger); }

.favorites-list,
.applications-list,
.received-list { display:flex; flex-direction:column; gap:16px; }

.fav-card {
    background:var(--white);
    border-radius:var(--radius-lg);
    border:1px solid var(--border);
    overflow:hidden;
    transition:transform .2s, box-shadow .2s;
}
.fav-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.fav-card-body { display:flex; gap:16px; padding:16px; }
.fav-card-image { width:120px; height:90px; border-radius:var(--radius-md); object-fit:cover; flex-shrink:0; }
.fav-card-info { flex:1; min-width:0; }
.fav-card-title { font-size:15px; font-weight:600; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:4px; color:var(--text-primary); }
.fav-card-price { font-size:16px; font-weight:700; color:var(--primary); margin-bottom:6px; }
.fav-card-price::after { content:'/月'; font-size:11px; font-weight:400; color:var(--text-secondary); }
.fav-card-meta { font-size:12px; color:var(--text-hint); margin-top:6px; }
.fav-card-actions { display:flex; flex-direction:column; justify-content:center; gap:8px; }

.app-card {
    background:var(--white);
    border-radius:var(--radius-lg);
    border:1px solid var(--border);
    padding:20px;
}
.app-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:10px; }
.app-card-post { display:flex; align-items:center; gap:10px; min-width:0; }
.app-card-post-badge { padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; color:white; white-space:nowrap; }
.app-card-post-title { font-size:14px; font-weight:600; color:var(--text-primary); display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
.app-status { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; white-space:nowrap; }
.app-status.pending { background:#FFF3E0; color:#E65100; }
.app-status.accepted { background:#E8F5E9; color:#2E7D32; }
.app-status.rejected { background:#FFEBEE; color:#C62828; }
.app-status.withdrawn { background:var(--bg-gray); color:var(--text-hint); }
.app-card-body { background:var(--bg-gray); border-radius:var(--radius-md); padding:14px; margin-bottom:12px; }
.app-card-label { font-size:12px; color:var(--text-hint); margin-bottom:4px; }
.app-card-message { font-size:14px; color:var(--text-primary); line-height:1.5; }
.app-card-footer { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.app-card-time { font-size:12px; color:var(--text-hint); }
.app-card-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.app-card-actions form { display:inline-flex; }

.received-card {
    background:var(--white);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);
    padding:20px;
}
.received-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:10px; }
.received-applicant { display:flex; align-items:center; gap:12px; min-width:0; }
.received-applicant-avatar { width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,var(--primary-light),var(--secondary)); display:flex; align-items:center; justify-content:center; color:white; font-size:18px; font-weight:600; flex-shrink:0; }
.received-applicant-name { font-size:15px; font-weight:600; color:var(--text-primary); }
.received-applicant-school { font-size:12px; color:var(--text-secondary); }
.received-post-ref { background:var(--bg-gray); border-radius:var(--radius-md); padding:12px; margin-bottom:12px; display:flex; align-items:center; gap:10px; transition:background .2s; }
.received-post-ref:hover { background:var(--border); }
.received-post-ref-badge { padding:3px 8px; border-radius:12px; font-size:10px; font-weight:600; color:white; white-space:nowrap; }
.received-post-ref-title { font-size:13px; font-weight:500; flex:1; color:var(--text-primary); display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden; }
.received-post-ref-arrow { color:var(--text-hint); font-size:16px; }
.received-contact { background:#E8F5E9; border-radius:var(--radius-md); padding:12px; margin-top:12px; font-size:13px; color:#2E7D32; display:none; }
.received-contact.visible { display:block; }
.received-contact-item { display:flex; align-items:center; gap:8px; margin-top:4px; }

.profile-list { display:flex; flex-direction:column; gap:12px; }
.profile-list-item { border:1px solid var(--border); border-radius:12px; padding:12px; display:flex; gap:12px; justify-content:space-between; }
.profile-list-main { min-width:0; }
.profile-list-title { display:block; font-size:16px; font-weight:600; margin-bottom:8px; color:var(--text-primary); }
.profile-list-meta { display:flex; flex-wrap:wrap; gap:12px; color:var(--text-secondary); font-size:13px; }
.profile-list-actions { display:flex; align-items:flex-start; gap:8px; flex-wrap:wrap; }
.profile-empty { color:var(--text-secondary); font-size:14px; padding:12px; background:var(--bg-gray); border-radius:10px; }
.profile-contact { margin-top:8px; font-size:13px; color:var(--primary); font-weight:600; }
@media (max-width: 900px) {
    .profile-layout { flex-direction:column; }
    .profile-sidebar { width:100%; position:static; }
    .sidebar-nav-item { padding:12px 16px; }
    .profile-form { grid-template-columns: 1fr; }
    .my-post-body { flex-direction:column; }
    .my-post-image { width:100%; height:160px; }
    .my-post-actions { flex-wrap:wrap; }
    .my-post-time { width:100%; margin-left:0; }
    .fav-card-body { flex-direction:column; }
    .fav-card-image { width:100%; height:160px; }
    .fav-card-actions { flex-direction:row; }
    .app-card-header,
    .app-card-footer,
    .received-header { flex-direction:column; align-items:flex-start; }
    .app-card-actions { justify-content:flex-start; }
    .profile-section-card { padding:20px 16px; }
    .profile-list-item { flex-direction:column; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
