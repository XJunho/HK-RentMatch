<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user = current_user();
$userRole = (string) ($user['role'] ?? '');
$currentUserId = (int) ($user['id'] ?? 0);
$postId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$errors = [];
$maxImageCount = 3;
$maxImageSizeBytes = 5 * 1024 * 1024;
$validTypes = ['rent', 'roommate-source', 'roommate-nosource', 'sublet'];

function edit_can_publish_type(string $role, string $type): bool
{
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'landlord') {
        return $type === 'rent';
    }
    if ($role === 'student') {
        return in_array($type, ['roommate-source', 'roommate-nosource', 'sublet'], true);
    }
    return false;
}

function edit_role_error(string $role): string
{
    if ($role === 'landlord') {
        return '房源供给方仅可发布租房类型。';
    }
    if ($role === 'student') {
        return '港硕学生仅可发布找室友和转租类型。';
    }
    return '当前账号角色无发布权限。';
}

function edit_type_to_form_type(string $type): string
{
    return in_array($type, ['roommate-source', 'roommate-nosource'], true) ? 'roommate' : $type;
}

function edit_type_to_roommate_mode(string $type): string
{
    if ($type === 'roommate-source') {
        return 'source';
    }
    if ($type === 'roommate-nosource') {
        return 'nosource';
    }
    return '';
}

function edit_effective_type(array &$form, array &$errors, array $validTypes): string
{
    $rawType = (string) ($form['type'] ?? 'rent');
    if ($rawType === 'roommate-source' || $rawType === 'roommate-nosource') {
        $form['type'] = 'roommate';
        $form['roommate_mode'] = $rawType === 'roommate-source' ? 'source' : 'nosource';
        return $rawType;
    }
    if ($rawType === 'roommate') {
        if (($form['roommate_mode'] ?? '') === 'source') {
            return 'roommate-source';
        }
        if (($form['roommate_mode'] ?? '') === 'nosource') {
            return 'roommate-nosource';
        }
        $errors['type'] = '请选择室友类型（有房源 / 无房源）。';
        return 'rent';
    }
    $type = in_array($rawType, $validTypes, true) ? $rawType : 'rent';
    $form['type'] = $type;
    $form['roommate_mode'] = '';
    return $type;
}

function edit_decode_kept_images(string $raw, array $originalImages): array
{
    if (trim($raw) === '') {
        return $originalImages;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $originalImages;
    }
    return array_values(array_filter(array_map('strval', $decoded), static function (string $image) use ($originalImages): bool {
        return in_array($image, $originalImages, true);
    }));
}

if ($postId <= 0) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

$postStmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND status <> :deleted LIMIT 1');
$postStmt->execute([
    ':id' => $postId,
    ':deleted' => 'deleted',
]);
$post = $postStmt->fetch();

if (!$post) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

if ((int) $post['user_id'] !== $currentUserId && $userRole !== 'admin') {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$form = [
    'type' => edit_type_to_form_type((string) ($post['type'] ?? 'rent')),
    'roommate_mode' => edit_type_to_roommate_mode((string) ($post['type'] ?? 'rent')),
    'title' => (string) ($post['title'] ?? ''),
    'price' => (string) (isset($post['price']) ? (float) $post['price'] : ''),
    'floor' => (string) ($post['floor'] ?? ''),
    'rent_period' => (string) ($post['rent_period'] ?? ''),
    'remaining_months' => (string) ($post['remaining_months'] ?? ''),
    'move_in_date' => (string) ($post['move_in_date'] ?? ''),
    'renewable' => (string) ($post['renewable'] ?? ''),
    'gender_requirement' => (string) ($post['gender_requirement'] ?? ''),
    'need_count' => (string) ($post['need_count'] ?? ''),
    'region' => (string) ($post['region'] ?? ''),
    'school_scope' => (string) ($post['school_scope'] ?? ''),
    'metro_stations' => (string) ($post['metro_stations'] ?? ''),
    'content' => (string) ($post['content'] ?? ''),
];
$originalImages = parse_post_images($post['images'] ?? null);
$keptImages = $originalImages;
$lockedPostType = in_array((string) ($post['type'] ?? 'rent'), $validTypes, true) ? (string) $post['type'] : 'rent';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = trim($_POST[$key] ?? '');
    }

    $postType = $lockedPostType;
    $form['type'] = edit_type_to_form_type($postType);
    $form['roommate_mode'] = edit_type_to_roommate_mode($postType);
    if (!edit_can_publish_type($userRole, $postType)) {
        $errors['type'] = edit_role_error($userRole);
    }

    if ($form['title'] === '' || mb_strlen($form['title'], 'UTF-8') < 5 || mb_strlen($form['title'], 'UTF-8') > 50) {
        $errors['title'] = '请输入 5-50 字的标题。';
    }

    $maxPrice = ($postType === 'roommate-nosource') ? 50000 : 100000;
    if ($form['price'] === '' || !is_numeric($form['price']) || (float) $form['price'] < 1000 || (float) $form['price'] > $maxPrice) {
        $errors['price'] = '请输入 1000-' . $maxPrice . ' 之间的数字。';
    }

    if (in_array($postType, ['rent', 'roommate-source'], true) && $form['floor'] === '') {
        $errors['floor'] = '请填写楼层信息。';
    }

    if ($postType !== 'sublet' && !in_array($form['rent_period'], ['short', 'medium', 'long'], true)) {
        $errors['rent_period'] = '请选择租期。';
    }

    if (in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true)
        && !in_array($form['gender_requirement'], ['male', 'female', 'any'], true)) {
        $errors['gender_requirement'] = '请选择性别要求。';
    }

    if ($postType === 'sublet') {
        $remainingMonths = (int) $form['remaining_months'];
        if ($remainingMonths < 1 || $remainingMonths > 36) {
            $errors['remaining_months'] = '租期剩余需在 1-36 个月之间。';
        }
        if ($form['move_in_date'] === '') {
            $errors['move_in_date'] = '请选择最早入住日期。';
        } else {
            $moveInTs = strtotime($form['move_in_date']);
            $todayTs = strtotime(date('Y-m-d'));
            if ($moveInTs === false || $moveInTs < $todayTs) {
                $errors['move_in_date'] = '最早入住日期不能早于今天。';
            }
        }
        if ($form['renewable'] !== '' && !in_array($form['renewable'], ['yes', 'no'], true)) {
            $errors['renewable'] = '可续租字段值无效。';
        }
    }

    if ($postType === 'roommate-source') {
        $needCount = (int) $form['need_count'];
        if ($needCount < 1 || $needCount > 10) {
            $errors['need_count'] = '请输入 1-10 的需求人数。';
        }
    }

    if ($form['region'] === '') {
        $errors['region'] = '请选择所属区域。';
    }
    if ($form['school_scope'] === '') {
        $errors['school_scope'] = '请选择学校范围。';
    }
    if ($form['metro_stations'] === '') {
        $errors['metro_stations'] = '请输入至少一个地铁站。';
    } else {
        $metroArray = array_filter(array_map('trim', explode(',', $form['metro_stations'])));
        if (count($metroArray) > 5) {
            $errors['metro_stations'] = '最多填写 5 个地铁站。';
        }
    }

    $keptImages = edit_decode_kept_images((string) ($_POST['kept_images'] ?? ''), $originalImages);
    $uploadedImages = [];
    if (in_array($postType, ['rent', 'sublet', 'roommate-source'], true)
        && isset($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
        foreach ($_FILES['images']['name'] as $idx => $name) {
            $fileError = $_FILES['images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
            if ($fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($fileError !== UPLOAD_ERR_OK) {
                $errors['images'] = '图片上传失败，请重试。';
                break;
            }
            $uploadedImages[] = [
                'name' => (string) ($name ?? ''),
                'tmp_name' => (string) ($_FILES['images']['tmp_name'][$idx] ?? ''),
                'size' => (int) ($_FILES['images']['size'][$idx] ?? 0),
            ];
        }
    }

    if (count($keptImages) + count($uploadedImages) > $maxImageCount) {
        $errors['images'] = '最多保留或上传 ' . $maxImageCount . ' 张图片。';
    }

    $savedImagePaths = [];
    $allowedMimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (empty($errors) && !empty($uploadedImages)) {
        $uploadDir = dirname(__DIR__) . '/uploads/posts';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $errors['images'] = '创建上传目录失败，请检查权限。';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                $errors['images'] = '无法检测图片类型，请稍后再试。';
            } else {
                foreach ($uploadedImages as $file) {
                    if ($file['size'] <= 0 || $file['size'] > $maxImageSizeBytes) {
                        $errors['images'] = '单张图片大小需在 5MB 以内。';
                        break;
                    }
                    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
                    $ext = $allowedMimeToExt[$mime] ?? '';
                    if ($ext === '') {
                        $errors['images'] = '仅支持 JPG / PNG / WEBP / GIF 图片。';
                        break;
                    }
                    try {
                        $random = bin2hex(random_bytes(8));
                    } catch (Exception $e) {
                        $random = uniqid('', true);
                    }
                    $filename = date('YmdHis') . '_' . $random . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $errors['images'] = '保存图片失败，请重试。';
                        break;
                    }
                    $savedImagePaths[] = 'uploads/posts/' . $filename;
                }
                finfo_close($finfo);
            }
        }
    }

    if (!empty($errors)) {
        foreach ($savedImagePaths as $path) {
            $abs = dirname(__DIR__) . '/' . $path;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    } else {
        $rentPeriodForDb = $form['rent_period'];
        if ($postType === 'sublet') {
            $remainingMonthsForPeriod = (int) $form['remaining_months'];
            if ($remainingMonthsForPeriod <= 6) {
                $rentPeriodForDb = 'short';
            } elseif ($remainingMonthsForPeriod <= 12) {
                $rentPeriodForDb = 'medium';
            } else {
                $rentPeriodForDb = 'long';
            }
        }

        $imagePathsForDb = in_array($postType, ['rent', 'sublet', 'roommate-source'], true)
            ? array_slice(array_merge($keptImages, $savedImagePaths), 0, $maxImageCount)
            : [];

        $updateStmt = $pdo->prepare(
            'UPDATE posts
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
                 renewable = :renewable,
                 images = :images
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':id' => $postId,
            ':title' => $form['title'],
            ':content' => $form['content'],
            ':price' => (float) $form['price'],
            ':floor' => ($form['floor'] !== '') ? $form['floor'] : null,
            ':rent_period' => $rentPeriodForDb,
            ':region' => $form['region'],
            ':school_scope' => ($form['school_scope'] !== '') ? $form['school_scope'] : null,
            ':metro_stations' => ($form['metro_stations'] !== '') ? $form['metro_stations'] : null,
            ':gender_requirement' => ($form['gender_requirement'] !== '') ? $form['gender_requirement'] : null,
            ':need_count' => ($postType === 'roommate-source' && $form['need_count'] !== '') ? (int) $form['need_count'] : null,
            ':remaining_months' => ($postType === 'sublet' && $form['remaining_months'] !== '') ? (int) $form['remaining_months'] : null,
            ':move_in_date' => ($postType === 'sublet' && $form['move_in_date'] !== '') ? $form['move_in_date'] : null,
            ':renewable' => ($postType === 'sublet' && $form['renewable'] !== '') ? $form['renewable'] : null,
            ':images' => !empty($imagePathsForDb) ? json_encode($imagePathsForDb, JSON_UNESCAPED_UNICODE) : null,
        ]);

        header('Location: ../profile.php?section=posts&notice_type=success&notice=' . urlencode('帖子已更新。'));
        exit;
    }
}

$regions = ['中西区','东区','南区','湾仔区','九龙城区','观塘区','深水埗区','黄大仙区','油尖旺区','离岛区','葵青区','北区','西贡区','沙田区','大埔区','荃湾区','屯门区','元朗区'];
$typeOptions = [
    'rent' => '租房',
    'roommate-source' => '🏘️找室友：已有房源',
    'roommate-nosource' => '🏘️找室友：无房源',
    'sublet' => '🔄转租',
];
$periodOptions = ['short' => '6个月以下', 'medium' => '6个月至1年', 'long' => '1年及以上'];
$genderOptions = ['male' => '👨 男生', 'female' => '👩 女生', 'any' => '🤝 不限'];
$renderErrors = [];
$selectedPostTypeForForm = edit_effective_type($form, $renderErrors, $validTypes);
$metroLines = [
    '东铁线' => ['金钟','会展','红磡','旺角东','九龙塘','大围','沙田','火炭','马场','大学','大埔墟','太和','粉岭','上水','落马洲','罗湖'],
    '观塘线' => ['黄埔','何文田','油麻地','旺角','太子','石硖尾','九龙塘','乐富','黄大仙','钻石山','彩虹','九龙湾','牛头角','观塘','蓝田','油塘','调景岭'],
    '港岛线' => ['坚尼地城','香港大学','西营盘','上环','中环','金钟','湾仔','铜锣湾','天后','炮台山','北角','鰂鱼涌','太古','西湾河','筲箕湾','杏花邨','柴湾'],
    '荃湾线' => ['荃湾','大窝口','葵兴','葵芳','荔景','美孚','荔枝角','长沙湾','深水埗','太子','旺角','油麻地','佐敦','尖沙咀','金钟','中环'],
    '屯马线' => ['屯门','兆康','天水围','朗屏','元朗','锦上路','荃湾西','美孚','南昌','柯士甸','尖东','红磡','何文田','土瓜湾','宋皇台','启德','钻石山','显径','大围','车公庙','沙田围','第一城','石门','大水坑','恒安','马鞍山','乌溪沙'],
    '东涌线' => ['香港','九龙','奥运','南昌','荔景','青衣','欣澳','东涌'],
    '将军澳线' => ['北角','鰂鱼涌','油塘','调景岭','将军澳','坑口','宝琳','康城'],
    '南港岛线' => ['金钟','海洋公园','黄竹坑','利东','海怡半岛'],
    '机场快线' => ['香港','九龙','青衣','机场','博览馆'],
    '迪士尼线' => ['欣澳','迪士尼'],
];
$allMetros = array_values(array_unique(array_merge(...array_values($metroLines))));

include __DIR__ . '/../includes/header.php';
?>
<div class="create-form-container">
    <div class="create-form-section" style="display:block;">
        <h2 class="create-section-title">编辑帖子</h2>
        <p class="create-section-desc">编辑已发布帖子时，类型不支持修改，若要更改帖子类型，请删除此帖重新发布！</p>

        <div class="form-group">
            <div class="create-section-badge <?php echo $selectedPostTypeForForm === 'sublet' ? 'sublet' : ($selectedPostTypeForForm === 'rent' ? 'rent' : 'roommate-source'); ?>">
                <?php echo htmlspecialchars($typeOptions[$selectedPostTypeForForm] ?? '租房'); ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="form-error form-error-general" style="background:#fff0f0;border:1px solid var(--danger);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:20px;color:var(--danger);">
                提交失败，请检查以下错误：
                <ul style="margin:6px 0 0 16px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="editPostForm" novalidate>
            <input type="hidden" name="kept_images" id="keptImagesInput" value="<?php echo htmlspecialchars(json_encode($keptImages, JSON_UNESCAPED_UNICODE)); ?>">

            <input type="hidden" name="type" id="editType" value="<?php echo htmlspecialchars($selectedPostTypeForForm); ?>">
            <input type="hidden" name="roommate_mode" id="roommateModeInput" value="<?php echo htmlspecialchars($form['roommate_mode']); ?>">
            <div class="form-group">
                <label class="form-label">标题 <span class="required">*</span></label>
                <input type="text" class="form-input" name="title" maxlength="50" value="<?php echo htmlspecialchars($form['title']); ?>" placeholder="简明扼要描述亮点，5-50字" required>
            </div>

            <div class="create-form-row">
                <div class="form-group">
                    <label class="form-label" id="editPriceLabel">月租金（HKD） <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="number" class="form-input" name="price" id="editPrice" min="1000" max="100000" step="500" value="<?php echo htmlspecialchars($form['price']); ?>" placeholder="1000 ~ 100000" style="padding-right:56px;" required>
                        <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-hint);font-size:13px;">HKD/月</span>
                    </div>
                </div>
                <div class="form-group type-field field-floor">
                    <label class="form-label">楼层 <span class="required">*</span></label>
                    <input type="text" class="form-input" name="floor" id="editFloor" value="<?php echo htmlspecialchars($form['floor']); ?>" placeholder="如 15/F、高层">
                </div>
                <div class="form-group type-field field-need-count" style="display:none;">
                    <label class="form-label">需求室友人数 <span class="required">*</span></label>
                    <input type="number" class="form-input" name="need_count" id="editNeedCount" min="1" max="10" value="<?php echo htmlspecialchars($form['need_count']); ?>" placeholder="1 ~ 10">
                </div>
            </div>

            <div class="form-group type-field field-rent-period">
                <label class="form-label">租期 <span class="required">*</span></label>
                <div class="radio-group">
                    <?php foreach ($periodOptions as $value => $label): ?>
                        <label class="radio-pill <?php echo $form['rent_period'] === $value ? 'checked' : ''; ?>">
                            <input type="radio" name="rent_period" value="<?php echo htmlspecialchars($value); ?>" <?php echo $form['rent_period'] === $value ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group type-field field-gender" style="display:none;">
                <label class="form-label">性别要求 <span class="required">*</span></label>
                <div class="radio-group">
                    <?php foreach ($genderOptions as $value => $label): ?>
                        <label class="radio-pill <?php echo $form['gender_requirement'] === $value ? 'checked' : ''; ?>">
                            <input type="radio" name="gender_requirement" value="<?php echo htmlspecialchars($value); ?>" <?php echo $form['gender_requirement'] === $value ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="type-field field-sublet" style="display:none;">
                <div class="create-form-row">
                    <div class="form-group">
                        <label class="form-label">租期剩余（月）<span class="required">*</span></label>
                        <input type="number" class="form-input" name="remaining_months" min="1" max="36" value="<?php echo htmlspecialchars($form['remaining_months']); ?>" placeholder="1 ~ 36">
                    </div>
                    <div class="form-group">
                        <label class="form-label">最早入住日期 <span class="required">*</span></label>
                        <input type="date" class="form-input" name="move_in_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($form['move_in_date']); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">是否可续租</label>
                    <div class="radio-group">
                        <label class="radio-pill <?php echo $form['renewable'] === 'yes' ? 'checked' : ''; ?>">
                            <input type="radio" name="renewable" value="yes" <?php echo $form['renewable'] === 'yes' ? 'checked' : ''; ?>> 可续租
                        </label>
                        <label class="radio-pill <?php echo $form['renewable'] === 'no' ? 'checked' : ''; ?>">
                            <input type="radio" name="renewable" value="no" <?php echo $form['renewable'] === 'no' ? 'checked' : ''; ?>> 不可续租
                        </label>
                    </div>
                </div>
            </div>

            <hr class="create-form-divider">

            <div class="create-form-row">
                <div class="form-group">
                    <label class="form-label">所属区域 <span class="required">*</span></label>
                    <select class="form-select" name="region" required>
                        <option value="">请选择区域</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region); ?>" <?php echo $form['region'] === $region ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">学校范围 <span class="required">*</span></label>
                    <select class="form-select" name="school_scope" required>
                        <option value="">请选择学校</option>
                        <?php foreach (school_option_groups() as $groupLabel => $groupOptions): ?>
                            <optgroup label="<?php echo htmlspecialchars($groupLabel); ?>">
                                <?php foreach ($groupOptions as $code => $label): ?>
                                    <?php $schoolName = school_short_name($code); ?>
                                    <option value="<?php echo htmlspecialchars($schoolName); ?>" <?php echo $form['school_scope'] === $schoolName ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($schoolName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">附近地铁站 <span class="required">*</span></label>
                <input type="hidden" name="metro_stations" id="cMetroInput" value="<?php echo htmlspecialchars($form['metro_stations']); ?>">
                <div class="metro-select" id="cMetroSelect">
                    <div class="metro-trigger" onclick="cToggleMetro()">
                        <div class="metro-tags-wrap" id="cMetroTags">
                            <span class="metro-placeholder">点击选择地铁站</span>
                        </div>
                        <span style="color:var(--text-hint);font-size:12px;">▼</span>
                    </div>
                    <div class="metro-dropdown" id="cMetroDropdown">
                        <div style="padding:8px 12px; border-bottom:1px solid #eee;">
                            <input
                                type="text"
                                id="metroSearch"
                                placeholder="搜索地铁站..."
                                style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:6px; outline:none;"
                                oninput="filterMetroStations()">
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:6px; padding:8px 12px; border-bottom:1px solid #eee;">
                            <button type="button" class="metro-line-btn all" data-line="all" onclick="switchMetroLine('all')">全部</button>
                            <?php $lineClassIndex = 1; ?>
                            <?php foreach ($metroLines as $lineName => $_stations): ?>
                                <button type="button" class="metro-line-btn line<?php echo $lineClassIndex++; ?>" data-line="<?php echo htmlspecialchars($lineName); ?>" onclick="switchMetroLine('<?php echo htmlspecialchars($lineName); ?>')"><?php echo htmlspecialchars($lineName); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div style="max-height:240px; overflow-y:auto; padding:4px 0;" id="metroStationList">
                            <div class="metro-line-group" data-line="all">
                                <?php foreach ($allMetros as $station): ?>
                                    <div class="metro-option" onclick="cToggleMetroItem(this)" data-name="<?php echo htmlspecialchars($station); ?>">
                                        <div class="metro-option-check"></div>
                                        🚇 <?php echo htmlspecialchars($station); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php foreach ($metroLines as $lineName => $stations): ?>
                                <div class="metro-line-group" data-line="<?php echo htmlspecialchars($lineName); ?>" style="display:none;">
                                    <?php foreach ($stations as $station): ?>
                                        <div class="metro-option" onclick="cToggleMetroItem(this)" data-name="<?php echo htmlspecialchars($station); ?>">
                                            <div class="metro-option-check"></div>
                                            🚇 <?php echo htmlspecialchars($station); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group type-field field-images">
                <label class="form-label">上传图片 <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（选填，最多3张）</span></label>
                <div class="upload-area" id="editUploadArea"></div>
                <input type="file" id="editFileInput" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;" onchange="cHandleImages(this)">
                <div class="form-hint" style="margin-top:8px;">支持 JPG/PNG，单张不超过 5MB，最多 3 张；点击已有图片右上角可移除。</div>
            </div>

            <div class="form-group">
                <label class="form-label">其他信息</label>
                <textarea class="form-input" name="content" rows="5" maxlength="100" placeholder="补充说明，如家电配备、周边环境、合租要求等，100字以内" style="resize:vertical;"><?php echo htmlspecialchars($form['content']); ?></textarea>
            </div>

            <div class="create-step-nav">
                <a class="btn btn-outline" href="<?php echo htmlspecialchars(project_base_url('profile.php?section=posts')); ?>">取消</a>
                <button type="submit" class="btn btn-primary">保存修改</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    const typeSelect = document.getElementById('editType');
    const roommateModeInput = document.getElementById('roommateModeInput');
    const keptImagesInput = document.getElementById('keptImagesInput');
    const cExistingImageUrls = <?php echo json_encode(array_map('resolve_post_image_url', $keptImages), JSON_UNESCAPED_UNICODE); ?>;
    let cExistingImagePaths = <?php echo json_encode(array_values($keptImages), JSON_UNESCAPED_UNICODE); ?>;
    let cUploadedImages = [];
    let cFileObjects = [];
    let cSelectedMetros = [];
    let currentMetroLine = 'all';

    function effectiveType() {
        return typeSelect ? typeSelect.value : 'rent';
    }

    function toggleFields() {
        const type = effectiveType();
        const cfg = {
            'rent': { priceLabel: '月租金（HKD）', maxPrice: 100000, showFloor: true, showRentPeriod: true, showGender: false, showNeedCount: false, showSublet: false, showImages: true },
            'sublet': { priceLabel: '月租金（HKD）', maxPrice: 100000, showFloor: true, showRentPeriod: false, showGender: true, showNeedCount: false, showSublet: true, showImages: true },
            'roommate-source': { priceLabel: '月租金（HKD）', maxPrice: 100000, showFloor: true, showRentPeriod: true, showGender: true, showNeedCount: true, showSublet: false, showImages: true },
            'roommate-nosource': { priceLabel: '租房预算（HKD）', maxPrice: 50000, showFloor: false, showRentPeriod: true, showGender: true, showNeedCount: false, showSublet: false, showImages: false }
        }[type] || { priceLabel: '月租金（HKD）', maxPrice: 100000, showFloor: true, showRentPeriod: true, showGender: false, showNeedCount: false, showSublet: false, showImages: true };

        const priceLabel = document.getElementById('editPriceLabel');
        const priceInput = document.getElementById('editPrice');
        if (priceLabel) priceLabel.innerHTML = cfg.priceLabel + ' <span class="required">*</span>';
        if (priceInput) {
            priceInput.max = cfg.maxPrice;
            priceInput.placeholder = '1000 ~ ' + cfg.maxPrice;
        }

        setFieldGroup('.field-floor', cfg.showFloor);
        setFieldGroup('.field-rent-period', cfg.showRentPeriod);
        setFieldGroup('.field-gender', cfg.showGender);
        setFieldGroup('.field-need-count', cfg.showNeedCount);
        setFieldGroup('.field-sublet', cfg.showSublet);
        setFieldGroup('.field-images', cfg.showImages);
        if (roommateModeInput) {
            roommateModeInput.value = type === 'roommate-source' ? 'source' : (type === 'roommate-nosource' ? 'nosource' : '');
        }
        cRenderUploadArea();
    }

    function setFieldGroup(selector, visible) {
        document.querySelectorAll(selector).forEach(el => {
            el.style.display = visible ? 'block' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(input => {
                input.disabled = !visible;
            });
        });
    }

    window.cHandleImages = function(input) {
        const files = Array.from(input.files || []);
        const remain = 3 - cExistingImagePaths.length - cFileObjects.length;
        if (remain <= 0) {
            if (typeof showToast === 'function') showToast('最多上传 3 张图片', 'error');
            input.value = '';
            return;
        }
        files.slice(0, remain).forEach(file => {
            if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                if (typeof showToast === 'function') showToast('仅支持 JPG/PNG/WEBP/GIF', 'error');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                if (typeof showToast === 'function') showToast(file.name + ' 超过5MB', 'error');
                return;
            }
            cFileObjects.push(file);
            const reader = new FileReader();
            reader.onload = function(event) {
                cUploadedImages.push(event.target.result);
                cRenderUploadArea();
            };
            reader.readAsDataURL(file);
        });
        input.value = '';
        cSyncFilesToInput();
    };

    window.cRemoveExistingImage = function(idx) {
        cExistingImagePaths.splice(idx, 1);
        cExistingImageUrls.splice(idx, 1);
        updateKeptImages();
        cRenderUploadArea();
    };

    window.cRemoveImage = function(idx) {
        cUploadedImages.splice(idx, 1);
        cFileObjects.splice(idx, 1);
        cSyncFilesToInput();
        cRenderUploadArea();
    };

    function cSyncFilesToInput() {
        const input = document.getElementById('editFileInput');
        if (!input) return;
        try {
            const dt = new DataTransfer();
            cFileObjects.forEach(file => dt.items.add(file));
            input.files = dt.files;
        } catch (e) {
            // Older browsers may not allow programmatic file assignment.
        }
    }

    function cRenderUploadArea() {
        const area = document.getElementById('editUploadArea');
        if (!area) return;
        let html = '';
        cExistingImageUrls.forEach((src, idx) => {
            html += '<div class="upload-slot"><img src="' + escapeAttr(src) + '" alt="图片"><div class="remove-img" onclick="event.stopPropagation();cRemoveExistingImage(' + idx + ')">×</div></div>';
        });
        cUploadedImages.forEach((src, idx) => {
            html += '<div class="upload-slot"><img src="' + escapeAttr(src) + '" alt="预览"><div class="remove-img" onclick="event.stopPropagation();cRemoveImage(' + idx + ')">×</div></div>';
        });
        if (cExistingImagePaths.length + cUploadedImages.length < 3) {
            html += '<div class="upload-slot" onclick="document.getElementById(\'editFileInput\').click()"><span class="upload-slot-icon">📷</span><span class="upload-slot-text">添加图片</span></div>';
        }
        area.innerHTML = html;
        updateKeptImages();
    }

    window.cToggleMetro = function() {
        const dropdown = document.getElementById('cMetroDropdown');
        if (dropdown) dropdown.classList.toggle('open');
    };

    window.cToggleMetroItem = function(el) {
        const name = '🚇 ' + (el.dataset.name || '').trim();
        if (!el.classList.contains('selected') && cSelectedMetros.length >= 5) {
            if (typeof showToast === 'function') showToast('最多只能选择 5 个地铁站', 'error');
            return;
        }
        el.classList.toggle('selected');
        if (el.classList.contains('selected')) {
            if (!cSelectedMetros.includes(name)) cSelectedMetros.push(name);
        } else {
            cSelectedMetros = cSelectedMetros.filter(item => item !== name);
        }
        syncMetroSelectionToDuplicateOptions();
        cRenderMetroTags();
    };

    window.cRemoveMetro = function(name) {
        cSelectedMetros = cSelectedMetros.filter(item => item !== name);
        syncMetroSelectionToDuplicateOptions();
        cRenderMetroTags();
    };

    function syncMetroSelectionToDuplicateOptions() {
        document.querySelectorAll('#cMetroDropdown .metro-option').forEach(el => {
            const name = '🚇 ' + (el.dataset.name || '').trim();
            el.classList.toggle('selected', cSelectedMetros.includes(name));
        });
    }

    window.cRenderMetroTags = function() {
        const container = document.getElementById('cMetroTags');
        const input = document.getElementById('cMetroInput');
        if (!container || !input) return;
        if (cSelectedMetros.length === 0) {
            container.innerHTML = '<span class="metro-placeholder">点击选择地铁站</span>';
        } else {
            container.innerHTML = cSelectedMetros.map(name =>
                '<span class="metro-tag">' + escapeHtml(name) + ' <span class="metro-tag-x" onclick="event.stopPropagation();cRemoveMetro(\'' + escapeJs(name) + '\')">×</span></span>'
            ).join('');
        }
        input.value = cSelectedMetros.map(name => name.replace('🚇 ', '')).join(', ');
    };

    window.filterMetroStations = function() {
        const search = document.getElementById('metroSearch');
        const kw = search ? search.value.toLowerCase().trim() : '';
        const activeGroup = document.querySelector('.metro-line-group[data-line="' + cssEscape(currentMetroLine) + '"]');
        if (!activeGroup) return;
        activeGroup.querySelectorAll('.metro-option').forEach(item => {
            const name = (item.dataset.name || '').toLowerCase();
            item.style.display = kw === '' || name.includes(kw) ? 'flex' : 'none';
        });
    };

    window.switchMetroLine = function(line) {
        currentMetroLine = line;
        document.querySelectorAll('.metro-line-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.line === line);
        });
        document.querySelectorAll('.metro-line-group').forEach(group => {
            group.style.display = group.dataset.line === line ? 'block' : 'none';
        });
        window.filterMetroStations();
    };

    function initMetroSelection() {
        const input = document.getElementById('cMetroInput');
        const raw = input ? input.value : '';
        cSelectedMetros = raw.split(',')
            .map(item => item.trim())
            .filter(Boolean)
            .slice(0, 5)
            .map(item => item.startsWith('🚇 ') ? item : '🚇 ' + item);
        syncMetroSelectionToDuplicateOptions();
        cRenderMetroTags();
        switchMetroLine('all');
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function escapeJs(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/"/g, '\\"');
    }

    function updateKeptImages() {
        if (!keptImagesInput) return;
        keptImagesInput.value = JSON.stringify(cExistingImagePaths);
    }

    if (typeSelect) {
        toggleFields();
    }

    document.querySelectorAll('.radio-pill').forEach(label => {
        label.addEventListener('click', function() {
            const input = this.querySelector('input[type="radio"]');
            if (!input) return;
            document.querySelectorAll('input[name="' + input.name + '"]').forEach(item => {
                const pill = item.closest('.radio-pill');
                if (pill) pill.classList.toggle('checked', item === input);
            });
            input.checked = true;
        });
    });

    cRenderUploadArea();

    initMetroSelection();

    document.addEventListener('click', function(event) {
        const select = document.getElementById('cMetroSelect');
        const dropdown = document.getElementById('cMetroDropdown');
        if (select && dropdown && !select.contains(event.target)) {
            dropdown.classList.remove('open');
        }
    });
})();
</script>
