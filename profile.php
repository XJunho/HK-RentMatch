<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

$allowedSections = ['profile', 'posts', 'favorites', 'applications', 'received'];
$section = isset($_GET['section']) ? trim((string) $_GET['section']) : 'profile';
if (!in_array($section, $allowedSections, true)) {
    $section = 'profile';
}
$autoEditPostId = isset($_GET['edit_post_id']) ? max(0, (int) $_GET['edit_post_id']) : 0;

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

$profileRegions = ['中西区','东区','南区','湾仔区','九龙城区','观塘区','深水埗区','黄大仙区','油尖旺区','离岛区','葵青区','北区','西贡区','沙田区','大埔区','荃湾区','屯门区','元朗区'];
$profileRegionMetroMap = [
    '中西区'   => ['坚尼地城','香港大学','西营盘','上环','中环','香港'],
    '东区'     => ['北角','鲗鱼涌','太古','西湾河','筲箕湾','杏花邨','柴湾'],
    '南区'     => ['海洋公园','黄竹坑','利东','海怡半岛'],
    '湾仔区'   => ['金钟','会展','湾仔','铜锣湾','天后'],
    '九龙城区' => ['红磡','黄埔','何文田','九龙塘','土瓜湾','宋皇台','启德'],
    '观塘区'   => ['彩虹','九龙湾','牛头角','观塘','蓝田','油塘','调景岭'],
    '深水埗区' => ['石硖尾','深水埗','长沙湾','荔枝角','美孚','南昌'],
    '黄大仙区' => ['乐富','黄大仙','钻石山','彩虹'],
    '油尖旺区' => ['旺角东','旺角','太子','油麻地','佐敦','尖沙咀','尖东','柯士甸','奥运','九龙'],
    '离岛区'   => ['东涌','欣澳','机场','博览馆','迪士尼'],
    '葵青区'   => ['葵兴','葵芳','荔景','青衣'],
    '北区'     => ['粉岭','上水','落马洲','罗湖'],
    '西贡区'   => ['将军澳','坑口','宝琳','康城'],
    '沙田区'   => ['大围','沙田','火炭','马场','大学','车公庙','沙田围','第一城','石门','大水坑','恒安','马鞍山','乌溪沙','显径'],
    '大埔区'   => ['大埔墟','太和'],
    '荃湾区'   => ['荃湾','大窝口','荃湾西'],
    '屯门区'   => ['屯门','兆康'],
    '元朗区'   => ['天水围','朗屏','元朗','锦上路'],
];
$profileSchoolOptions = array_values(school_scope_options());

function profile_normalize_string_array($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $values = [];
    foreach ($raw as $item) {
        $value = trim((string) $item);
        if ($value !== '') {
            $values[$value] = true;
        }
    }

    return array_keys($values);
}

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

    if ($action === 'post_get_edit_data') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '帖子参数无效。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '帖子参数无效。');
        }

        $stmt = $pdo->prepare(
            "SELECT
                id, user_id, type, title, content, price, floor, rent_period, region, school_scope, metro_stations,
                gender_requirement, need_count, remaining_months, move_in_date, renewable, status
             FROM posts
             WHERE id = :post_id AND user_id = :user_id AND status <> 'deleted'
             LIMIT 1"
        );
        $stmt->execute([
            ':post_id' => $postId,
            ':user_id' => $currentUserId,
        ]);
        $postRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$postRow) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '未找到可编辑的帖子。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '未找到可编辑的帖子。');
        }

        $schools = array_values(array_filter(array_map('trim', explode(',', (string) ($postRow['school_scope'] ?? '')))));
        $metros = array_values(array_filter(array_map('trim', explode(',', (string) ($postRow['metro_stations'] ?? '')))));

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => (int) $postRow['id'],
                    'type' => (string) ($postRow['type'] ?? ''),
                    'title' => (string) ($postRow['title'] ?? ''),
                    'content' => (string) ($postRow['content'] ?? ''),
                    'price' => (string) ($postRow['price'] ?? ''),
                    'floor' => (string) ($postRow['floor'] ?? ''),
                    'rent_period' => (string) ($postRow['rent_period'] ?? ''),
                    'region' => (string) ($postRow['region'] ?? ''),
                    'schools' => $schools,
                    'metros' => $metros,
                    'gender_requirement' => (string) ($postRow['gender_requirement'] ?? ''),
                    'need_count' => (string) ($postRow['need_count'] ?? ''),
                    'remaining_months' => (string) ($postRow['remaining_months'] ?? ''),
                    'move_in_date' => (string) ($postRow['move_in_date'] ?? ''),
                    'renewable' => (string) ($postRow['renewable'] ?? ''),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'post_update') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '帖子参数无效。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '帖子参数无效。');
        }

        $postStmt = $pdo->prepare(
            "SELECT id, type
             FROM posts
             WHERE id = :post_id AND user_id = :user_id AND status <> 'deleted'
             LIMIT 1"
        );
        $postStmt->execute([
            ':post_id' => $postId,
            ':user_id' => $currentUserId,
        ]);
        $existingPost = $postStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingPost) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => '未找到可编辑的帖子。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', '未找到可编辑的帖子。');
        }

        $postType = (string) ($existingPost['type'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $price = trim((string) ($_POST['price'] ?? ''));
        $floor = trim((string) ($_POST['floor'] ?? ''));
        $rentPeriod = trim((string) ($_POST['rent_period'] ?? ''));
        $region = trim((string) ($_POST['region'] ?? ''));
        $metroStations = trim((string) ($_POST['metro_stations'] ?? ''));
        $genderRequirement = trim((string) ($_POST['gender_requirement'] ?? ''));
        $needCount = trim((string) ($_POST['need_count'] ?? ''));
        $remainingMonths = trim((string) ($_POST['remaining_months'] ?? ''));
        $moveInDate = trim((string) ($_POST['move_in_date'] ?? ''));
        $renewable = trim((string) ($_POST['renewable'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $selectedSchools = profile_normalize_string_array($_POST['school_scope'] ?? []);
        $schoolScope = implode(', ', $selectedSchools);
        $errors = [];

        if ($title === '' || mb_strlen($title, 'UTF-8') < 5 || mb_strlen($title, 'UTF-8') > 50) {
            $errors[] = '标题需为 5-50 个字符。';
        }

        $maxPrice = ($postType === 'roommate-nosource') ? 50000 : 100000;
        if ($price === '' || !is_numeric($price) || (float) $price < 1000 || (float) $price > $maxPrice) {
            $errors[] = '价格需在 1000-' . $maxPrice . ' 之间。';
        }

        if (in_array($postType, ['rent', 'roommate-source', 'sublet'], true) && $floor === '') {
            $errors[] = '请填写楼层信息。';
        }

        if ($postType !== 'sublet' && !in_array($rentPeriod, ['short', 'medium', 'long'], true)) {
            $errors[] = '请选择租期。';
        }

        if (in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true)
            && !in_array($genderRequirement, ['male', 'female', 'any'], true)) {
            $errors[] = '请选择性别要求。';
        }

        if ($postType === 'roommate-source') {
            $needCountInt = (int) $needCount;
            if ($needCount === '' || $needCountInt < 1 || $needCountInt > 10) {
                $errors[] = '需求人数需在 1-10 之间。';
            }
        }

        if ($postType === 'sublet') {
            $remainingMonthsInt = (int) $remainingMonths;
            if ($remainingMonths === '' || $remainingMonthsInt < 1 || $remainingMonthsInt > 36) {
                $errors[] = '租期剩余需在 1-36 个月之间。';
            }
            if ($moveInDate === '') {
                $errors[] = '请选择最早入住日期。';
            } else {
                $moveInTs = strtotime($moveInDate);
                $todayTs = strtotime(date('Y-m-d'));
                if ($moveInTs === false || $moveInTs < $todayTs) {
                    $errors[] = '最早入住日期不能早于今天。';
                }
            }
            if ($renewable !== '' && !in_array($renewable, ['yes', 'no'], true)) {
                $errors[] = '可续租字段值无效。';
            }
        }

        if ($region === '' || !in_array($region, $profileRegions, true)) {
            $errors[] = '请选择所属区域。';
        }

        if ($selectedSchools === []) {
            $errors[] = '请选择学校范围。';
        } else {
            foreach ($selectedSchools as $schoolItem) {
                if (!in_array($schoolItem, $profileSchoolOptions, true)) {
                    $errors[] = '学校范围中包含无效选项。';
                    break;
                }
            }
        }

        $metroArray = array_values(array_filter(array_map('trim', explode(',', $metroStations))));
        if ($metroArray === []) {
            $errors[] = '请选择至少一个地铁站。';
        } elseif (count($metroArray) > 5) {
            $errors[] = '最多可选择 5 个地铁站。';
        } else {
            $allowedMetros = $profileRegionMetroMap[$region] ?? [];
            foreach ($metroArray as $metroName) {
                if (!in_array($metroName, $allowedMetros, true)) {
                    $errors[] = '所选地铁站与所属区域不匹配。';
                    break;
                }
            }
        }

        if (!empty($errors)) {
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => $errors[0],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_profile($targetSection, 'error', $errors[0]);
        }

        $rentPeriodForDb = $rentPeriod;
        if ($postType === 'sublet') {
            $remainingMonthsForPeriod = (int) $remainingMonths;
            if ($remainingMonthsForPeriod <= 6) {
                $rentPeriodForDb = 'short';
            } elseif ($remainingMonthsForPeriod <= 12) {
                $rentPeriodForDb = 'medium';
            } else {
                $rentPeriodForDb = 'long';
            }
        }

        $updateStmt = $pdo->prepare(
            "UPDATE posts
             SET title = :title,
                 content = :content,
                 price = :price,
                 floor = :floor,
                 rent_period = :rent_period,
                 region = :region,
                 school_scope = :school_scope,
                 metro_stations = :metro_stations,
                 gender_requirement = :gender_requirement,
                 need_count = :need_count,
                 remaining_months = :remaining_months,
                 move_in_date = :move_in_date,
                 renewable = :renewable
             WHERE id = :post_id AND user_id = :user_id AND status <> 'deleted'
             LIMIT 1"
        );
        $updateStmt->execute([
            ':title' => $title,
            ':content' => $content,
            ':price' => (float) $price,
            ':floor' => ($postType === 'roommate-nosource') ? null : $floor,
            ':rent_period' => $rentPeriodForDb,
            ':region' => $region,
            ':school_scope' => $schoolScope,
            ':metro_stations' => implode(', ', $metroArray),
            ':gender_requirement' => in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true) ? $genderRequirement : null,
            ':need_count' => $postType === 'roommate-source' ? (int) $needCount : null,
            ':remaining_months' => $postType === 'sublet' ? (int) $remainingMonths : null,
            ':move_in_date' => $postType === 'sublet' ? $moveInDate : null,
            ':renewable' => $postType === 'sublet' && $renewable !== '' ? $renewable : null,
            ':post_id' => $postId,
            ':user_id' => $currentUserId,
        ]);

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => '帖子已更新。',
                'data' => [
                    'post_id' => $postId,
                    'title' => $title,
                    'price' => number_format((float) $price, 0),
                    'region' => $region,
                    'metro_stations' => implode(', ', $metroArray),
                    'school_scope' => $schoolScope,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect_profile($targetSection, 'success', '帖子已更新。', [
            'posts_filter' => $postsFilterPost,
            'notice_mode' => 'toast',
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
            'notice_mode' => 'toast',
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
    "SELECT id, title, type, content, price, floor, rent_period, status, region, metro_stations, school_scope,
            gender_requirement, need_count, remaining_months, move_in_date, renewable, images, created_at
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
        applicant.gender AS applicant_gender,
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
                                        <span class="my-post-status <?php echo $postStatus === 'active' ? 'status-active' : 'status-hidden'; ?>">
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
                                        <button class="btn btn-outline btn-small icon-only-btn js-post-edit-btn" type="button" data-post-id="<?php echo (int) $post['id']; ?>" title="编辑" aria-label="编辑">
                                            <span class="icon-feather" style="--icon-url:url('<?php echo htmlspecialchars(project_base_url('feather/edit.svg')); ?>');"></span>
                                        </button>
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
                                        <form method="post" class="js-post-delete-form">
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
                                                <button class="btn btn-outline btn-small btn-danger-outline" type="button" onclick="confirmUnfavorite(this);">取消收藏</button>
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
                                                <form method="post">
                                                    <input type="hidden" name="action" value="application_withdraw">
                                                    <input type="hidden" name="section" value="applications">
                                                    <input type="hidden" name="applications_filter" value="<?php echo htmlspecialchars($applicationsFilter); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo (int) $app['id']; ?>">
                                                    <button class="btn btn-outline btn-small" type="button" onclick="confirmWithdraw(this);">↩️ 撤回</button>
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
                                $applicantGenderText = $genderTextMap[$received['applicant_gender'] ?? 'other'] ?? '其他';
                                ?>
                                <div class="received-card" data-applicant-phone="<?php echo htmlspecialchars((string) ($received['applicant_phone'] ?? '')); ?>">
                                    <div class="received-header">
                                        <div class="received-applicant">
                                            <div class="received-applicant-avatar"><?php echo htmlspecialchars(mb_substr((string) $received['applicant_name'], 0, 1, 'UTF-8')); ?></div>
                                            <div>
                                                <div class="received-applicant-name"><?php echo htmlspecialchars((string) $received['applicant_name']); ?></div>
                                                <div class="received-applicant-meta">
                                                    <span class="received-applicant-school">👤 <?php echo htmlspecialchars($applicantGenderText); ?></span>
                                                    <?php if ($applicantSchoolText !== ''): ?>
                                                        <span class="received-applicant-school">🎓 <?php echo htmlspecialchars($applicantSchoolText); ?></span>
                                                    <?php endif; ?>
                                                </div>
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

<div class="modal-overlay" id="sharedConfirmModal">
    <div class="modal" style="max-width:380px;">
        <div class="modal-header">
            <h2 class="modal-title" id="sharedConfirmTitle" style="font-size:16px;"></h2>
            <button class="modal-close" type="button" onclick="closeSharedConfirm()">×</button>
        </div>
        <div style="padding:24px;">
            <p id="sharedConfirmMsg" style="margin:0 0 24px;color:var(--text-main);"></p>
            <div style="display:flex;justify-content:flex-end;gap:12px;">
                <button class="btn btn-outline btn-small" type="button" onclick="closeSharedConfirm()">取消</button>
                <button class="btn btn-small" type="button" id="sharedConfirmBtn" onclick="execSharedConfirm()">确定</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="postEditModal">
    <div class="modal" style="max-width:860px;width:92%;max-height:90vh;overflow:auto;">
        <div class="modal-header">
            <h2 class="modal-title">编辑帖子</h2>
            <button class="modal-close" type="button" onclick="closeModal('postEditModal')">×</button>
        </div>
        <form id="postEditForm" method="post" class="post-edit-form">
            <input type="hidden" name="action" value="post_update">
            <input type="hidden" name="section" value="posts">
            <input type="hidden" name="posts_filter" value="<?php echo htmlspecialchars($postsFilter); ?>">
            <input type="hidden" name="post_id" id="editPostId" value="">
            <input type="hidden" name="metro_stations" id="editMetroStations" value="">

            <div class="post-edit-grid">
                <div class="form-group">
                    <label class="profile-form-label">帖子类型</label>
                    <input type="text" class="profile-input" id="editTypeLabel" value="" disabled>
                </div>
                <div class="form-group">
                    <label class="profile-form-label">标题 <span class="required">*</span></label>
                    <input type="text" class="profile-input" name="title" id="editTitle" maxlength="50" required>
                </div>
                <div class="form-group">
                    <label class="profile-form-label">价格（HKD） <span class="required">*</span></label>
                    <input type="number" class="profile-input" name="price" id="editPrice" min="1000" step="100" required>
                </div>
                <div class="form-group" id="editFloorWrap">
                    <label class="profile-form-label">楼层 <span class="required">*</span></label>
                    <input type="text" class="profile-input" name="floor" id="editFloor" placeholder="如 15/F、高层">
                </div>
                <div class="form-group" id="editRentPeriodWrap">
                    <label class="profile-form-label">租期 <span class="required">*</span></label>
                    <select class="profile-input profile-select" name="rent_period" id="editRentPeriod">
                        <option value="">请选择</option>
                        <option value="short">6个月以下</option>
                        <option value="medium">6个月至1年</option>
                        <option value="long">1年及以上</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="profile-form-label">所属区域 <span class="required">*</span></label>
                    <select class="profile-input profile-select" name="region" id="editRegion">
                        <option value="">请选择区域</option>
                        <?php foreach ($profileRegions as $regionOpt): ?>
                            <option value="<?php echo htmlspecialchars($regionOpt); ?>"><?php echo htmlspecialchars($regionOpt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="profile-form-label">学校范围 <span class="required">*</span></label>
                    <div class="metro-select" id="editSchoolSelect">
                        <div class="metro-trigger" onclick="profileToggleEditSchoolDropdown()">
                            <div class="metro-tags-wrap" id="editSchoolTags">
                                <span class="metro-placeholder">点击选择学校范围</span>
                            </div>
                            <span style="color:var(--text-hint);font-size:12px;">▼</span>
                        </div>
                        <div class="metro-dropdown" id="editSchoolDropdown">
                            <div style="padding:8px 12px;border-bottom:1px solid #eee;">
                                <input type="text" id="editSchoolSearch" placeholder="搜索学校..." style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;outline:none;" oninput="profileFilterEditSchoolOptions()">
                            </div>
                            <div style="max-height:220px;overflow-y:auto;padding:4px 0;" id="editSchoolOptionList">
                                <?php foreach ($profileSchoolOptions as $schoolOpt): ?>
                                    <div class="metro-option" onclick="profileToggleEditSchoolOption(this)" data-name="<?php echo htmlspecialchars($schoolOpt); ?>">
                                        <div class="metro-option-check"></div>
                                        🎓 <?php echo htmlspecialchars($schoolOpt); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div id="editSchoolInputs"></div>
                </div>
                <div class="form-group">
                    <label class="profile-form-label">附近地铁站 <span class="required">*</span></label>
                    <div class="metro-select" id="editMetroSelectWrap">
                        <div class="metro-trigger" onclick="profileToggleEditMetroDropdown()">
                            <div class="metro-tags-wrap" id="editMetroTags">
                                <span class="metro-placeholder">点击选择地铁站</span>
                            </div>
                            <span style="color:var(--text-hint);font-size:12px;">▼</span>
                        </div>
                        <div class="metro-dropdown" id="editMetroDropdown">
                            <div style="padding:8px 12px;border-bottom:1px solid #eee;">
                                <input type="text" id="editMetroSearch" placeholder="搜索地铁站..." style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;outline:none;" oninput="profileFilterEditMetroOptions()">
                            </div>
                            <div id="editMetroLineFilters" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;border-bottom:1px solid #eee;"></div>
                            <div style="max-height:220px;overflow-y:auto;padding:4px 0;" id="editMetroOptionList">
                                <div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">请先选择所属区域</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" id="editGenderWrap">
                    <label class="profile-form-label">性别要求 <span class="required">*</span></label>
                    <select class="profile-input profile-select" name="gender_requirement" id="editGenderRequirement">
                        <option value="">请选择</option>
                        <option value="male">男</option>
                        <option value="female">女</option>
                        <option value="any">不限</option>
                    </select>
                </div>
                <div class="form-group" id="editNeedCountWrap">
                    <label class="profile-form-label">需求人数 <span class="required">*</span></label>
                    <input type="number" class="profile-input" name="need_count" id="editNeedCount" min="1" max="10">
                </div>
                <div class="form-group" id="editRemainingMonthsWrap">
                    <label class="profile-form-label">剩余租期（月） <span class="required">*</span></label>
                    <input type="number" class="profile-input" name="remaining_months" id="editRemainingMonths" min="1" max="36">
                </div>
                <div class="form-group" id="editMoveInDateWrap">
                    <label class="profile-form-label">最早入住日期 <span class="required">*</span></label>
                    <input type="date" class="profile-input" name="move_in_date" id="editMoveInDate">
                </div>
                <div class="form-group" id="editRenewableWrap">
                    <label class="profile-form-label">是否可续租</label>
                    <select class="profile-input profile-select" name="renewable" id="editRenewable">
                        <option value="">请选择</option>
                        <option value="yes">可续租</option>
                        <option value="no">不可续租</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-top:8px;">
                <label class="profile-form-label">其他信息</label>
                <textarea class="profile-input" name="content" id="editContent" rows="4" maxlength="100" style="height:auto;resize:vertical;"></textarea>
            </div>
            <div class="profile-actions active" style="margin-top:12px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('postEditModal')">取消</button>
                <button type="submit" class="btn btn-primary" id="postEditSaveBtn">保存修改</button>
            </div>
        </form>
    </div>
</div>

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

let _sharedConfirmCallback = null;
function openConfirmModal(options) {
    const titleEl = document.getElementById('sharedConfirmTitle');
    const msgEl = document.getElementById('sharedConfirmMsg');
    const btn = document.getElementById('sharedConfirmBtn');
    if (titleEl) titleEl.textContent = options.title || '确认';
    if (msgEl) msgEl.textContent = options.message || '';
    if (btn) {
        const isDanger = options.danger !== false;
        btn.style.background = isDanger ? 'var(--danger)' : 'var(--primary)';
        btn.style.borderColor = isDanger ? 'var(--danger)' : 'var(--primary)';
        btn.style.color = '#fff';
    }
    _sharedConfirmCallback = options.onConfirm || null;
    const el = document.getElementById('sharedConfirmModal');
    if (el) {
        el.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}
function closeSharedConfirm() {
    closeModal('sharedConfirmModal');
    _sharedConfirmCallback = null;
}
function execSharedConfirm() {
    closeModal('sharedConfirmModal');
    if (_sharedConfirmCallback) {
        _sharedConfirmCallback();
        _sharedConfirmCallback = null;
    }
}

function confirmUnfavorite(btn) {
    const form = btn && btn.closest ? btn.closest('form') : null;
    if (!form) return;
    openConfirmModal({
        title: '取消收藏',
        message: '确定要取消收藏该帖子吗？',
        onConfirm: function() { form.submit(); }
    });
}

function confirmWithdraw(btn) {
    const form = btn && btn.closest ? btn.closest('form') : null;
    if (!form) return;
    openConfirmModal({
        title: '撤回申请',
        message: '确定要撤回该申请吗？',
        onConfirm: function() { form.submit(); }
    });
}

const PROFILE_EDIT_TYPE_LABEL_MAP = {
    'rent': '🏠 租房',
    'roommate-source': '🏘️ 有房找室友',
    'roommate-nosource': '🔍 无房找室友',
    'sublet': '🔄 转租'
};
const PROFILE_REGION_METRO_MAP = <?php echo json_encode($profileRegionMetroMap, JSON_UNESCAPED_UNICODE); ?>;
const PROFILE_METRO_LINE_STATIONS = {
    '东铁线': ['金钟','会展','红磡','旺角东','九龙塘','大围','沙田','火炭','马场','大学','大埔墟','太和','粉岭','上水','落马洲','罗湖'],
    '观塘线': ['黄埔','何文田','油麻地','旺角','太子','石硖尾','九龙塘','乐富','黄大仙','钻石山','彩虹','九龙湾','牛头角','观塘','蓝田','油塘','调景岭'],
    '港岛线': ['坚尼地城','香港大学','西营盘','上环','中环','金钟','湾仔','铜锣湾','天后','炮台山','北角','鲗鱼涌','太古','西湾河','筲箕湾','杏花邨','柴湾'],
    '荃湾线': ['荃湾','大窝口','葵兴','葵芳','荔景','美孚','荔枝角','长沙湾','深水埗','太子','旺角','油麻地','佐敦','尖沙咀','金钟','中环'],
    '屯马线': ['屯门','兆康','天水围','朗屏','元朗','锦上路','荃湾西','美孚','南昌','柯士甸','尖东','红磡','何文田','土瓜湾','宋皇台','启德','钻石山','显径','大围','车公庙','沙田围','第一城','石门','大水坑','恒安','马鞍山','乌溪沙'],
    '东涌线': ['香港','九龙','奥运','南昌','荔景','青衣','欣澳','东涌'],
    '将军澳线': ['北角','鲗鱼涌','油塘','调景岭','将军澳','坑口','宝琳','康城'],
    '南港岛线': ['金钟','海洋公园','黄竹坑','利东','海怡半岛'],
    '机场快线': ['博览馆','机场','青衣','九龙','香港'],
    '迪士尼线': ['迪士尼'],
};
const PROFILE_METRO_LINE_COLORS = {
    '东铁线': '#5EB6E4',
    '观塘线': '#00A040',
    '港岛线': '#0072CE',
    '荃湾线': '#E2231A',
    '屯马线': '#8B5A2B',
    '东涌线': '#F28C28',
    '将军澳线': '#7E3F98',
    '南港岛线': '#B5BD00',
    '机场快线': '#00888A',
    '迪士尼线': '#E894C6',
};
let currentEditingPostType = 'rent';
let profileEditSelectedSchools = [];
let profileEditSelectedMetros = [];
let profileEditActiveMetroLine = 'all';

function profileEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text || '');
    return div.innerHTML;
}

function profileSyncEditSchoolHiddenInputs() {
    const wrap = document.getElementById('editSchoolInputs');
    if (!wrap) return;
    wrap.innerHTML = profileEditSelectedSchools.map(function(name) {
        return '<input type="hidden" name="school_scope[]" value="' + profileEscapeHtml(name) + '">';
    }).join('');
}

function profileRenderEditSchoolTags() {
    const container = document.getElementById('editSchoolTags');
    if (!container) return;
    if (profileEditSelectedSchools.length === 0) {
        container.innerHTML = '<span class="metro-placeholder">点击选择学校范围</span>';
        return;
    }
    container.innerHTML = profileEditSelectedSchools.map(function(name, index) {
        return '<span class="metro-tag">🎓 ' + profileEscapeHtml(name)
            + ' <span class="metro-tag-x" onclick="event.stopPropagation();profileRemoveEditSchool(' + String(index) + ')">×</span></span>';
    }).join('');
}

function profileSyncEditSchoolOptionStates() {
    document.querySelectorAll('#editSchoolOptionList .metro-option').forEach(function(option) {
        const name = String(option.dataset.name || '').trim();
        option.classList.toggle('selected', profileEditSelectedSchools.includes(name));
    });
}

function profileToggleEditSchoolDropdown() {
    const dropdown = document.getElementById('editSchoolDropdown');
    if (!dropdown) return;
    dropdown.classList.toggle('open');
}

function profileToggleEditSchoolOption(el) {
    const name = String(el.dataset.name || '').trim();
    if (name === '') return;
    if (profileEditSelectedSchools.includes(name)) {
        profileEditSelectedSchools = profileEditSelectedSchools.filter(function(item) { return item !== name; });
    } else {
        profileEditSelectedSchools.push(name);
    }
    profileSyncEditSchoolOptionStates();
    profileRenderEditSchoolTags();
    profileSyncEditSchoolHiddenInputs();
}

function profileRemoveEditSchool(index) {
    if (index < 0 || index >= profileEditSelectedSchools.length) return;
    profileEditSelectedSchools.splice(index, 1);
    profileSyncEditSchoolOptionStates();
    profileRenderEditSchoolTags();
    profileSyncEditSchoolHiddenInputs();
}

function profileFilterEditSchoolOptions() {
    const kwEl = document.getElementById('editSchoolSearch');
    const keyword = kwEl ? String(kwEl.value || '').toLowerCase().trim() : '';
    document.querySelectorAll('#editSchoolOptionList .metro-option').forEach(function(option) {
        const name = String(option.dataset.name || '').toLowerCase();
        option.style.display = keyword === '' || name.includes(keyword) ? 'flex' : 'none';
    });
}

function profileSetMetroHiddenValue() {
    const hiddenInput = document.getElementById('editMetroStations');
    if (!hiddenInput) return;
    hiddenInput.value = profileEditSelectedMetros.join(', ');
}

function profileGetEditAvailableMetroLines(region) {
    const stations = PROFILE_REGION_METRO_MAP[region] || [];
    return Object.keys(PROFILE_METRO_LINE_STATIONS).filter(function(line) {
        return PROFILE_METRO_LINE_STATIONS[line].some(function(station) { return stations.includes(station); });
    });
}

function profileGetVisibleMetroStations(region) {
    const stations = PROFILE_REGION_METRO_MAP[region] || [];
    if (profileEditActiveMetroLine === 'all') return stations;
    const lineStations = PROFILE_METRO_LINE_STATIONS[profileEditActiveMetroLine] || [];
    return stations.filter(function(station) { return lineStations.includes(station); });
}

function profileRenderEditMetroOptions(region, selectedMetros) {
    const optionList = document.getElementById('editMetroOptionList');
    const lineFilters = document.getElementById('editMetroLineFilters');
    if (!optionList) return;
    const allowed = PROFILE_REGION_METRO_MAP[region] || [];
    const incoming = Array.isArray(selectedMetros) ? selectedMetros : profileEditSelectedMetros;
    profileEditSelectedMetros = incoming.filter(function(item) { return allowed.includes(item); });
    const lines = profileGetEditAvailableMetroLines(region);
    if (profileEditActiveMetroLine !== 'all' && !lines.includes(profileEditActiveMetroLine)) {
        profileEditActiveMetroLine = 'all';
    }

    if (!region) {
        if (lineFilters) lineFilters.innerHTML = '';
        optionList.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">请先选择所属区域</div>';
        profileSetMetroHiddenValue();
        profileRenderEditMetroTags();
        return;
    }

    if (lineFilters) {
        let html = '<button type="button" onclick="event.stopPropagation();profileSwitchEditMetroLine(\'all\')" style="border:1px solid #2d6cdf;border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;background:#2d6cdf;color:#fff;">全部</button>';
        lines.forEach(function(line) {
            const color = PROFILE_METRO_LINE_COLORS[line] || '#6b7280';
            html += '<button type="button" onclick="event.stopPropagation();profileSwitchEditMetroLine(\'' + profileEscapeHtml(line) + '\')" style="border:1px solid ' + color + ';border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;background:' + color + ';color:#fff;">' + profileEscapeHtml(line) + '</button>';
        });
        lineFilters.innerHTML = html;
    }

    const visibleStations = profileGetVisibleMetroStations(region);
    const kwEl = document.getElementById('editMetroSearch');
    const keyword = kwEl ? String(kwEl.value || '').toLowerCase().trim() : '';
    const finalStations = visibleStations.filter(function(name) { return keyword === '' || name.toLowerCase().includes(keyword); });

    if (allowed.length === 0) {
        optionList.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">当前区域暂无可选地铁站</div>';
        profileSetMetroHiddenValue();
        profileRenderEditMetroTags();
        return;
    }
    if (finalStations.length === 0) {
        optionList.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">该线路下无匹配站点</div>';
        profileSetMetroHiddenValue();
        profileRenderEditMetroTags();
        return;
    }

    optionList.innerHTML = finalStations.map(function(name) {
        const selectedClass = profileEditSelectedMetros.includes(name) ? ' selected' : '';
        return '<div class="metro-option' + selectedClass + '" onclick="profileToggleEditMetroOption(this)" data-name="'
            + profileEscapeHtml(name) + '"><div class="metro-option-check"></div>🚇 ' + profileEscapeHtml(name) + '</div>';
    }).join('');
    profileSetMetroHiddenValue();
    profileRenderEditMetroTags();
}

function profileSwitchEditMetroLine(line) {
    profileEditActiveMetroLine = line;
    const region = document.getElementById('editRegion');
    profileRenderEditMetroOptions(region ? region.value : '', profileEditSelectedMetros);
}

function profileRenderEditMetroTags() {
    const container = document.getElementById('editMetroTags');
    if (!container) return;
    if (profileEditSelectedMetros.length === 0) {
        container.innerHTML = '<span class="metro-placeholder">点击选择地铁站</span>';
        return;
    }
    container.innerHTML = profileEditSelectedMetros.map(function(name, index) {
        return '<span class="metro-tag">🚇 ' + profileEscapeHtml(name)
            + ' <span class="metro-tag-x" onclick="event.stopPropagation();profileRemoveEditMetro(' + String(index) + ')">×</span></span>';
    }).join('');
}

function profileToggleEditMetroDropdown() {
    const region = document.getElementById('editRegion');
    if (!region || !region.value) {
        if (typeof showToast === 'function') showToast('请先选择所属区域', 'error');
        return;
    }
    const dropdown = document.getElementById('editMetroDropdown');
    if (!dropdown) return;
    dropdown.classList.toggle('open');
}

function profileToggleEditMetroOption(el) {
    const name = String(el.dataset.name || '').trim();
    if (name === '') return;
    if (profileEditSelectedMetros.includes(name)) {
        profileEditSelectedMetros = profileEditSelectedMetros.filter(function(item) { return item !== name; });
    } else {
        if (profileEditSelectedMetros.length >= 5) {
            if (typeof showToast === 'function') showToast('最多只能选择 5 个地铁站', 'error');
            return;
        }
        profileEditSelectedMetros.push(name);
    }
    const region = document.getElementById('editRegion');
    profileRenderEditMetroOptions(region ? region.value : '', profileEditSelectedMetros);
}

function profileRemoveEditMetro(index) {
    if (index < 0 || index >= profileEditSelectedMetros.length) return;
    profileEditSelectedMetros.splice(index, 1);
    const region = document.getElementById('editRegion');
    profileRenderEditMetroOptions(region ? region.value : '', profileEditSelectedMetros);
}

function profileFilterEditMetroOptions() {
    const region = document.getElementById('editRegion');
    profileRenderEditMetroOptions(region ? region.value : '', profileEditSelectedMetros);
}

function profileToggleEditFieldsByType(postType) {
    currentEditingPostType = postType || 'rent';
    const isSublet = currentEditingPostType === 'sublet';
    const isRoommateSource = currentEditingPostType === 'roommate-source';
    const isRoommateNoSource = currentEditingPostType === 'roommate-nosource';
    const showGender = isRoommateSource || isRoommateNoSource || isSublet;

    const floorWrap = document.getElementById('editFloorWrap');
    const rentPeriodWrap = document.getElementById('editRentPeriodWrap');
    const genderWrap = document.getElementById('editGenderWrap');
    const needCountWrap = document.getElementById('editNeedCountWrap');
    const remainingWrap = document.getElementById('editRemainingMonthsWrap');
    const moveInWrap = document.getElementById('editMoveInDateWrap');
    const renewableWrap = document.getElementById('editRenewableWrap');

    if (floorWrap) floorWrap.style.display = isRoommateNoSource ? 'none' : '';
    if (rentPeriodWrap) rentPeriodWrap.style.display = isSublet ? 'none' : '';
    if (genderWrap) genderWrap.style.display = showGender ? '' : 'none';
    if (needCountWrap) needCountWrap.style.display = isRoommateSource ? '' : 'none';
    if (remainingWrap) remainingWrap.style.display = isSublet ? '' : 'none';
    if (moveInWrap) moveInWrap.style.display = isSublet ? '' : 'none';
    if (renewableWrap) renewableWrap.style.display = isSublet ? '' : 'none';
}

function openPostEditModal(postId) {
    if (!postId) return;
    const formData = new FormData();
    formData.append('action', 'post_get_edit_data');
    formData.append('section', 'posts');
    formData.append('posts_filter', <?php echo json_encode($postsFilter, JSON_UNESCAPED_UNICODE); ?>);
    formData.append('post_id', String(postId));

    fetch(<?php echo json_encode(profile_section_url('posts', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
    })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (!result || result.success !== true || !result.data) {
                throw new Error(result && result.message ? result.message : '加载编辑数据失败。');
            }
            const data = result.data;
            openModal('postEditModal');

            const editForm = document.getElementById('postEditForm');
            if (!editForm) return;
            editForm.reset();

            const selectedSchools = Array.isArray(data.schools) ? data.schools : [];
            const selectedMetros = Array.isArray(data.metros) ? data.metros : [];

            document.getElementById('editPostId').value = String(data.id || '');
            document.getElementById('editTypeLabel').value = PROFILE_EDIT_TYPE_LABEL_MAP[data.type] || '租房';
            document.getElementById('editTitle').value = data.title || '';
            document.getElementById('editPrice').value = data.price || '';
            document.getElementById('editFloor').value = data.floor || '';
            document.getElementById('editRentPeriod').value = data.rent_period || '';
            document.getElementById('editRegion').value = data.region || '';
            document.getElementById('editGenderRequirement').value = data.gender_requirement || '';
            document.getElementById('editNeedCount').value = data.need_count || '';
            document.getElementById('editRemainingMonths').value = data.remaining_months || '';
            document.getElementById('editMoveInDate').value = data.move_in_date || '';
            document.getElementById('editRenewable').value = data.renewable || '';
            document.getElementById('editContent').value = data.content || '';
            profileEditSelectedSchools = selectedSchools.slice();
            profileSyncEditSchoolOptionStates();
            profileRenderEditSchoolTags();
            profileSyncEditSchoolHiddenInputs();
            profileRenderEditMetroOptions(data.region || '', selectedMetros);
            profileToggleEditFieldsByType(data.type || 'rent');
        })
        .catch(function(error) {
            if (typeof showToast === 'function') {
                showToast(error.message || '加载编辑数据失败，请稍后重试。', 'error');
            }
        });
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

    document.querySelectorAll('.js-post-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const postId = Number(btn.getAttribute('data-post-id') || '0');
            if (!Number.isInteger(postId) || postId <= 0) return;
            openPostEditModal(postId);
        });
    });

    const autoEditPostId = <?php echo (int) $autoEditPostId; ?>;
    if (autoEditPostId > 0) {
        openPostEditModal(autoEditPostId);
    }

    const editRegion = document.getElementById('editRegion');
    if (editRegion) {
        editRegion.addEventListener('change', function() {
            profileEditActiveMetroLine = 'all';
            profileRenderEditMetroOptions(editRegion.value || '', profileEditSelectedMetros);
        });
    }

    document.addEventListener('click', function(e) {
        const schoolWrap = document.getElementById('editSchoolSelect');
        const metroWrap = document.getElementById('editMetroSelectWrap');
        if (schoolWrap && !schoolWrap.contains(e.target)) {
            const schoolDropdown = document.getElementById('editSchoolDropdown');
            if (schoolDropdown) schoolDropdown.classList.remove('open');
        }
        if (metroWrap && !metroWrap.contains(e.target)) {
            const metroDropdown = document.getElementById('editMetroDropdown');
            if (metroDropdown) metroDropdown.classList.remove('open');
        }
    });

    profileRenderEditSchoolTags();
    profileSyncEditSchoolHiddenInputs();
    profileRenderEditMetroTags();

    const postEditForm = document.getElementById('postEditForm');
    if (postEditForm) {
        postEditForm.addEventListener('submit', function(e) {
            e.preventDefault();
            profileSetMetroHiddenValue();

            const saveBtn = document.getElementById('postEditSaveBtn');
            if (saveBtn) saveBtn.disabled = true;

            const formData = new FormData(postEditForm);
            fetch(<?php echo json_encode(profile_section_url('posts', ['ajax' => '1']), JSON_UNESCAPED_UNICODE); ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.success !== true) {
                        throw new Error(data && data.message ? data.message : '更新失败，请稍后重试。');
                    }

                    const payload = data.data || {};
                    const postId = Number(payload.post_id || 0);
                    if (postId > 0) {
                        const card = document.querySelector('.my-post-card .js-post-edit-btn[data-post-id="' + String(postId) + '"]');
                        const root = card ? card.closest('.my-post-card') : null;
                        if (root) {
                            const titleEl = root.querySelector('.my-post-title');
                            const priceEl = root.querySelector('.my-post-price');
                            const tags = root.querySelectorAll('.my-post-tags .tag');
                            if (titleEl) titleEl.textContent = payload.title || '-';
                            if (priceEl) priceEl.textContent = payload.price || '0';
                            if (tags[0]) tags[0].textContent = payload.region || '-';
                            if (tags[1]) tags[1].textContent = '🚇 ' + (payload.metro_stations || '-');
                            if (tags[2]) tags[2].textContent = payload.school_scope || '-';
                        }
                    }

                    closeModal('postEditModal');
                    if (typeof showToast === 'function') {
                        showToast(data.message || '帖子已更新。', 'success');
                    }
                })
                .catch(function(error) {
                    if (typeof showToast === 'function') {
                        showToast(error.message || '更新失败，请稍后重试。', 'error');
                    }
                })
                .finally(function() {
                    if (saveBtn) saveBtn.disabled = false;
                });
        });
    }

    document.querySelectorAll('.js-post-status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('.js-post-status-btn');
            const nextStatusInput = form.querySelector('.js-next-status');
            if (!submitBtn || !nextStatusInput) return;

            if (!form.dataset.confirmed) {
                const isHiding = nextStatusInput.value === 'hidden';
                openConfirmModal({
                    title: isHiding ? '隐藏帖子' : '显示帖子',
                    message: isHiding
                        ? '确定要隐藏该帖子吗？隐藏后其他用户将无法看到。'
                        : '确定要重新显示该帖子吗？',
                    danger: isHiding,
                    onConfirm: function() {
                        form.dataset.confirmed = '1';
                        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    }
                });
                return;
            }
            delete form.dataset.confirmed;

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
                        statusBadge.classList.remove('status-active', 'status-hidden');
                        statusBadge.classList.add(currentStatus === 'active' ? 'status-active' : 'status-hidden');
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

            if (!form.dataset.confirmed) {
                openConfirmModal({
                    title: '删除帖子',
                    message: '确定要删除该帖子吗？删除后无法恢复。',
                    onConfirm: function() {
                        form.dataset.confirmed = '1';
                        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    }
                });
                return;
            }
            delete form.dataset.confirmed;

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

            if (!form.dataset.confirmed) {
                const decisionInput = form.querySelector('[name="decision"]');
                const decision = decisionInput ? decisionInput.value : '';
                const isAccept = decision === 'accepted';
                openConfirmModal({
                    title: isAccept ? '同意申请' : '拒绝申请',
                    message: isAccept ? '确定要同意该申请吗？' : '确定要拒绝该申请吗？',
                    danger: !isAccept,
                    onConfirm: function() {
                        form.dataset.confirmed = '1';
                        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    }
                });
                return;
            }
            delete form.dataset.confirmed;

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
.post-edit-form { padding:4px 2px; }
.post-edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 16px; }
.post-edit-grid .form-group { margin-bottom:0; }

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
.my-post-status.status-active { background:#E8F5E9; color:#2E7D32; }
.my-post-status.status-hidden { background:#FFF3E0; color:#E65100; }
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
.received-applicant-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
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
    .post-edit-grid { grid-template-columns:1fr; }
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
