<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user              = current_user();
$errors            = [];
$maxImageCount     = 3;
$maxImageSizeBytes = 5 * 1024 * 1024;
$validTypes        = ['rent', 'roommate-source', 'roommate-nosource', 'sublet'];
$userRole          = (string) ($user['role'] ?? '');
$regions           = ['中西区','东区','南区','湾仔区','九龙城区','观塘区','深水埗区','黄大仙区','油尖旺区','离岛区','葵青区','北区','西贡区','沙田区','大埔区','荃湾区','屯门区','元朗区'];
$schoolGroups      = school_option_groups();
$validSchoolScopes = array_values(school_scope_options());
$regionMetroMap    = [
    '中西区'   => ['坚尼地城','香港大学','西营盘','上环','中环','香港'],
    '东区'     => ['炮台山','北角','鲗鱼涌','太古','西湾河','筲箕湾','杏花邨','柴湾'],
    '南区'     => ['海洋公园','黄竹坑','利东','海怡半岛'],
    '湾仔区'   => ['金钟','会展','湾仔','铜锣湾','天后'],
    '九龙城区' => ['红磡','黄埔','何文田','九龙塘','土瓜湾','宋皇台','启德'],
    '观塘区'   => ['彩虹','九龙湾','牛头角','观塘','蓝田','油塘','调景岭'],
    '深水埗区' => ['石硖尾','深水埗','长沙湾','荔枝角','美孚','南昌','太子'],
    '黄大仙区' => ['乐富','黄大仙','钻石山'],
    '油尖旺区' => ['旺角东','旺角','油麻地','佐敦','尖沙咀','尖东','柯士甸','奥运','九龙'],
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

function normalize_string_array($raw): array
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

function can_publish_type(string $role, string $type): bool
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

function publish_role_error(string $role): string
{
    if ($role === 'landlord') {
        return '房源供给方仅可发布租房类型。';
    }
    if ($role === 'student') {
        return '港硕学生仅可发布找室友和转租类型。';
    }
    if ($role === 'admin') {
        return '管理员可发布所有类型。';
    }
    return '当前账号角色无发布权限。';
}

$form = [
    'type'               => 'rent',
    'roommate_mode'      => '',
    'title'              => '',
    'price'              => '',
    'floor'              => '',
    'rent_period'        => '',
    'remaining_months'   => '',
    'move_in_date'       => '',
    'renewable'          => '',
    'gender_requirement' => '',
    'need_count'         => '',
    'region'             => '',
    'school_scope'       => '',
    'metro_stations'     => '',
    'content'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $_) {
        $rawValue = $_POST[$key] ?? '';
        $form[$key] = is_array($rawValue) ? '' : trim((string) $rawValue);
    }
    $selectedSchools = normalize_string_array($_POST['school_scope'] ?? []);
    $form['school_scope'] = implode(', ', $selectedSchools);

    $rawType = $form['type'];
    if ($rawType === 'roommate-source' || $rawType === 'roommate-nosource') {
        $postType = $rawType;
        $form['type'] = 'roommate';
        $form['roommate_mode'] = ($rawType === 'roommate-source') ? 'source' : 'nosource';
    } elseif ($rawType === 'roommate') {
        if ($form['roommate_mode'] === 'source') {
            $postType = 'roommate-source';
        } elseif ($form['roommate_mode'] === 'nosource') {
            $postType = 'roommate-nosource';
        } else {
            $postType = 'rent';
            $errors['type'] = '请选择室友类型（有房源 / 无房源）。';
        }
    } else {
        $postType = in_array($rawType, $validTypes, true) ? $rawType : 'rent';
        if (in_array($postType, ['rent', 'sublet'], true)) {
            $form['type'] = $postType;
            $form['roommate_mode'] = '';
        }
    }

    if (!can_publish_type($userRole, $postType)) {
        $errors['type'] = publish_role_error($userRole);
    }

    // 标题
    if ($form['title'] === '' || mb_strlen($form['title'], 'UTF-8') < 5 || mb_strlen($form['title'], 'UTF-8') > 50) {
        $errors['title'] = '请输入5-50字的标题。';
    }

    // 价格
    $maxPrice = ($postType === 'roommate-nosource') ? 50000 : 100000;
    if ($form['price'] === '' || !is_numeric($form['price'])
        || (float)$form['price'] < 1000 || (float)$form['price'] > $maxPrice) {
        $errors['price'] = '请输入1000-' . $maxPrice . '之间的数字。';
    }

    // 楼层（租房和有房找室友必填）
    if (in_array($postType, ['rent', 'roommate-source'], true) && $form['floor'] === '') {
        $errors['floor'] = '请填写楼层信息。';
    }

    // 租期（转租不要求用户填写）
    if ($postType !== 'sublet' && !in_array($form['rent_period'], ['short', 'medium', 'long'], true)) {
        $errors['rent_period'] = '请选择租期。';
    }

    // 性别要求（找室友与转租必填）
    if (in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true)
        && !in_array($form['gender_requirement'], ['male', 'female', 'any'], true)) {
        $errors['gender_requirement'] = '请选择性别要求。';
    }

    // 转租专属字段
    if ($postType === 'sublet') {
        $remainingMonths = (int)$form['remaining_months'];
        if ($remainingMonths < 1 || $remainingMonths > 36) {
            $errors['remaining_months'] = '租期剩余需在 1-36 个月之间。';
        }

        if ($form['move_in_date'] === '') {
            $errors['move_in_date'] = '请选择最早入住日期。';
        } else {
            $moveInTs = strtotime($form['move_in_date']);
            $todayTs  = strtotime(date('Y-m-d'));
            if ($moveInTs === false || $moveInTs < $todayTs) {
                $errors['move_in_date'] = '最早入住日期不能早于今天。';
            }
        }

        if ($form['renewable'] !== '' && !in_array($form['renewable'], ['yes', 'no'], true)) {
            $errors['renewable'] = '可续租字段值无效。';
        }
    }

    // 需求人数（有房找室友必填）
    if ($postType === 'roommate-source') {
        $nc = (int)$form['need_count'];
        if ($nc < 1 || $nc > 10) {
            $errors['need_count'] = '请输入1-10的需求人数。';
        }
    }

    // 区域
    if ($form['region'] === '') {
        $errors['region'] = '请选择所属区域。';
    }

    // 学校
    if ($form['school_scope'] === '') {
        $errors['school_scope'] = '请选择学校范围。';
    } else {
        foreach ($selectedSchools as $schoolName) {
            if (!in_array($schoolName, $validSchoolScopes, true)) {
                $errors['school_scope'] = '学校范围中包含无效选项。';
                break;
            }
        }
    }

    // 地铁站
    if ($form['metro_stations'] === '') {
        $errors['metro_stations'] = '请选择至少一个地铁站。';
    } else {
        $metroArray = explode(',', $form['metro_stations']);
        $metroArray = array_filter(array_map('trim', $metroArray));

        if (count($metroArray) > 5) {
            $errors['metro_stations'] = '最多选择 5 个地铁站！';
        } elseif ($form['region'] !== '') {
            $allowedMetros = $regionMetroMap[$form['region']] ?? [];
            foreach ($metroArray as $metro) {
                if (!in_array($metro, $allowedMetros, true)) {
                    $errors['metro_stations'] = '所选地铁站与所属区域不匹配，请重新选择。';
                    break;
                }
            }
        }
    }

    // 图片处理（租房 / 转租 / 有房找室友支持图片）
    $uploadedImages = [];
    if (in_array($postType, ['rent', 'sublet', 'roommate-source'], true)
        && isset($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
        $names     = $_FILES['images']['name'];
        $types     = $_FILES['images']['type'];
        $tmpNames  = $_FILES['images']['tmp_name'];
        $errorsArr = $_FILES['images']['error'];
        $sizes     = $_FILES['images']['size'];

        foreach ($names as $idx => $name) {
            $fileError = $errorsArr[$idx] ?? UPLOAD_ERR_NO_FILE;
            if ($fileError === UPLOAD_ERR_NO_FILE) continue;
            if ($fileError !== UPLOAD_ERR_OK) { $errors['images'] = '图片上传失败，请重试。'; break; }
            $uploadedImages[] = [
                'name'     => (string)($name ?? ''),
                'type'     => (string)($types[$idx] ?? ''),
                'tmp_name' => (string)($tmpNames[$idx] ?? ''),
                'size'     => (int)($sizes[$idx] ?? 0),
            ];
        }
    }

    if (count($uploadedImages) > $maxImageCount) {
        $errors['images'] = '最多上传 ' . $maxImageCount . ' 张图片。';
    }

    $allowedMimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    if (empty($errors) && !empty($uploadedImages)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            $errors['images'] = '无法检测图片类型，请稍后再试。';
        } else {
            foreach ($uploadedImages as $file) {
                if ($file['size'] <= 0 || $file['size'] > $maxImageSizeBytes) {
                    $errors['images'] = '单张图片大小需在 5MB 以内。'; break;
                }
                $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
                if (!isset($allowedMimeToExt[$mime])) {
                    $errors['images'] = '仅支持 JPG / PNG / WEBP / GIF 图片。'; break;
                }
            }
            finfo_close($finfo);
        }
    }

    // 保存图片
    $savedImagePaths = [];
    if (empty($errors) && !empty($uploadedImages)) {
        $uploadDir = dirname(__DIR__) . '/uploads/posts';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $errors['images'] = '创建上传目录失败，请检查权限。';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                foreach ($uploadedImages as $file) {
                    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
                    $ext  = $allowedMimeToExt[$mime] ?? '';
                    if ($ext === '') { $errors['images'] = '图片类型不受支持。'; break; }
                    try { $random = bin2hex(random_bytes(8)); } catch (Exception $e) { $random = uniqid('', true); }
                    $filename   = date('YmdHis') . '_' . $random . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $errors['images'] = '保存图片失败，请重试。'; break;
                    }
                    $savedImagePaths[] = 'uploads/posts/' . $filename;
                }
                finfo_close($finfo);
            }
        }
        if (!empty($errors)) {
            foreach ($savedImagePaths as $p) { $abs = dirname(__DIR__) . '/' . $p; if (is_file($abs)) @unlink($abs); }
            $savedImagePaths = [];
        }
    }

    // 兼容 posts.rent_period NOT NULL：转租按 remaining_months 自动映射
    $rentPeriodForDb = $form['rent_period'];
    if ($postType === 'sublet') {
        $remainingMonthsForPeriod = (int)$form['remaining_months'];
        if ($remainingMonthsForPeriod <= 6) {
            $rentPeriodForDb = 'short';
        } elseif ($remainingMonthsForPeriod <= 12) {
            $rentPeriodForDb = 'medium';
        } else {
            $rentPeriodForDb = 'long';
        }
    }

    // 写库
    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'INSERT INTO posts (
                user_id, type, title, content, price, floor,
                rent_period, region, school_scope, metro_stations,
                gender_requirement, need_count, remaining_months, move_in_date,
                renewable, images, status
             ) VALUES (
                :user_id, :type, :title, :content, :price, :floor,
                :rent_period, :region, :school_scope, :metro_stations,
                :gender_requirement, :need_count, :remaining_months, :move_in_date,
                :renewable, :images, :status
             )'
        );
        $stmt->execute([
            ':user_id'            => $user['id'],
            ':type'               => $postType,
            ':title'              => $form['title'],
            ':content'            => $form['content'],
            ':price'              => (float)$form['price'],
            ':floor'              => ($form['floor'] !== '') ? $form['floor'] : null,
            ':rent_period'        => $rentPeriodForDb,
            ':region'             => $form['region'],
            ':school_scope'       => ($form['school_scope'] !== '') ? $form['school_scope'] : null,
            ':metro_stations'     => ($form['metro_stations'] !== '') ? $form['metro_stations'] : null,
            ':gender_requirement' => ($form['gender_requirement'] !== '') ? $form['gender_requirement'] : null,
            ':need_count'         => ($form['need_count'] !== '') ? (int)$form['need_count'] : null,
            ':remaining_months'   => ($postType === 'sublet' && $form['remaining_months'] !== '') ? (int)$form['remaining_months'] : null,
            ':move_in_date'       => ($postType === 'sublet' && $form['move_in_date'] !== '') ? $form['move_in_date'] : null,
            ':renewable'          => ($postType === 'sublet' && $form['renewable'] !== '') ? $form['renewable'] : null,
            ':images'             => !empty($savedImagePaths) ? json_encode($savedImagePaths, JSON_UNESCAPED_UNICODE) : null,
            ':status'             => 'active',
        ]);
        $redirectTab = 'rent';
        if ($postType === 'sublet') {
            $redirectTab = 'sublet';
        } elseif (in_array($postType, ['roommate-source', 'roommate-nosource'], true)) {
            $redirectTab = 'roommate';
        }
        header('Location: ../index.php?tab=' . urlencode($redirectTab) . '&toast=published');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php $selectedSchoolScopes = array_filter(array_map('trim', explode(',', $form['school_scope']))); ?>

<!-- Steps Indicator -->
<div class="steps">
    <div class="step active" id="cStep1">
        <div class="step-circle">1</div>
        <span class="step-label">选择类型</span>
    </div>
    <div class="step-line" id="cLine1"></div>
    <div class="step" id="cStep2">
        <div class="step-circle">2</div>
        <span class="step-label">填写信息</span>
    </div>
    <div class="step-line" id="cLine2"></div>
    <div class="step" id="cStep3">
        <div class="step-circle">3</div>
        <span class="step-label">预览发布</span>
    </div>
</div>

<!-- Main Form -->
<div class="create-form-container">

    <?php if (!empty($errors)): ?>
    <div class="form-error form-error-general" style="background:#fff0f0;border:1px solid var(--danger);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:20px;color:var(--danger);">
        提交失败，请检查以下错误：
        <ul style="margin:6px 0 0 16px;">
        <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Step 1: Type Selection -->
    <div id="cSection1" class="create-type-section">
        <h2 class="create-type-section-title">选择发布类型</h2>
        <p class="create-type-section-desc">不同类型对应不同的信息表单，请根据实际需求选择</p>
        <div class="create-type-cards">
            <div class="create-type-card" data-type="rent" onclick="cSelectType('rent')">
                <div class="create-type-check">✓</div>
                <div class="create-type-icon">🏠</div>
                <div class="create-type-name">发布租房</div>
                <div class="create-type-desc">房源供给方发布<br>出租房源信息</div>
                <span class="create-type-role">🏢 房源供给方</span>
            </div>
            <div class="create-type-card" data-type="roommate" onclick="cSelectType('roommate')">
                <div class="create-type-check">✓</div>
                <div class="create-type-icon">🏘️</div>
                <div class="create-type-name">找室友</div>
                <div class="create-type-desc">支持有房源/无房源<br>两种发布方式</div>
                <span class="create-type-role">🎓 港硕学生</span>
            </div>
            <div class="create-type-card" data-type="sublet" onclick="cSelectType('sublet')">
                <div class="create-type-check">✓</div>
                <div class="create-type-icon">🔁</div>
                <div class="create-type-name">发布转租</div>
                <div class="create-type-desc">面向在租房源<br>发布转租信息</div>
                <span class="create-type-role">🎓 港硕学生</span>
            </div>
        </div>
        <div id="cRoommateModeWrap" class="create-subtype-wrap" style="display:none;">
            <div class="create-subtype-title">请选择室友发布方式 <span class="required">*</span></div>
            <div class="create-subtype-options">
                <button type="button" class="create-subtype-option" data-mode="source" onclick="cSelectRoommateMode('source')">
                    🏘️ 有房源
                </button>
                <button type="button" class="create-subtype-option" data-mode="nosource" onclick="cSelectRoommateMode('nosource')">
                    🔍 无房源
                </button>
            </div>
        </div>
        <div class="create-step-nav">
            <div></div>
            <button class="btn btn-primary btn-to-step2" id="cToStep2Btn" onclick="cGoToStep(2)">
                下一步：填写信息 →
            </button>
        </div>
    </div>

    <!-- Step 2: Form -->
    <div id="cSection2" class="create-form-section" style="display:none;">
        <div id="cFormBadge" class="create-section-badge rent">🏠 发布租房</div>
        <h2 class="create-section-title" id="cFormTitle">填写房源信息</h2>
        <p class="create-section-desc" id="cFormDesc">请填写以下信息，带 * 为必填项</p>

        <form id="cForm" method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="type" id="cInputType" value="rent">
            <input type="hidden" name="roommate_mode" id="cInputRoommateMode" value="">

            <!-- 标题 -->
            <div class="form-group">
                <label class="form-label">标题 <span class="required">*</span></label>
                <input type="text" class="form-input" name="title" id="cTitle"
                       placeholder="简明扼要描述亮点，5-50字" maxlength="50"
                       oninput="cCharCount(this,50,'cTitleCount')"
                       value="<?php echo htmlspecialchars($form['title']); ?>">
                <div class="char-count" id="cTitleCount">0/50</div>
            </div>

            <hr class="create-form-divider">

            <div class="create-form-row">
                <!-- 价格 -->
                <div class="form-group" id="cGroupPrice">
                    <label class="form-label" id="cLabelPrice">月租金（HKD） <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="number" class="form-input" name="price" id="cPrice"
                               placeholder="1000 ~ 100000" min="1000" max="100000"
                               step="500"
                               style="padding-right:56px;"
                               value="<?php echo htmlspecialchars($form['price']); ?>">
                        <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-hint);font-size:13px;">HKD/月</span>
                    </div>
                </div>

                <!-- 需求人数（有房找室友） -->
                <div class="form-group" id="cGroupNeedCount" style="display:none;">
                    <label class="form-label">需求室友人数 <span class="required">*</span></label>
                    <input type="number" class="form-input" name="need_count" id="cNeedCount"
                           placeholder="1 ~ 10" min="1" max="10"
                           value="<?php echo htmlspecialchars($form['need_count']); ?>">
                </div>

                <!-- 楼层（租房 / 有房找室友） -->
                <div class="form-group" id="cGroupFloor">
                    <label class="form-label">楼层 <span class="required">*</span></label>
                    <input type="text" class="form-input" name="floor" id="cFloor"
                           placeholder="如 15/F、高层"
                           value="<?php echo htmlspecialchars($form['floor']); ?>">
                </div>
            </div>

            <!-- 租期（转租不展示） -->
            <div class="form-group" id="cGroupRentPeriod">
                <label class="form-label">租期 <span class="required">*</span></label>
                <div class="radio-group" id="cPeriodGroup">
                    <label class="radio-pill <?php echo $form['rent_period']==='short'?'checked':''; ?>" onclick="cCheckRadio(this,'cPeriodGroup')">
                        <input type="radio" name="rent_period" value="short" <?php echo $form['rent_period']==='short'?'checked':''; ?>> 6个月以下
                    </label>
                    <label class="radio-pill <?php echo $form['rent_period']==='medium'?'checked':''; ?>" onclick="cCheckRadio(this,'cPeriodGroup')">
                        <input type="radio" name="rent_period" value="medium" <?php echo $form['rent_period']==='medium'?'checked':''; ?>> 6个月至1年
                    </label>
                    <label class="radio-pill <?php echo $form['rent_period']==='long'?'checked':''; ?>" onclick="cCheckRadio(this,'cPeriodGroup')">
                        <input type="radio" name="rent_period" value="long" <?php echo $form['rent_period']==='long'?'checked':''; ?>> 1年及以上
                    </label>
                </div>
            </div>

            <!-- 性别要求（找室友类型） -->
            <div class="form-group" id="cGroupGender" style="display:none;">
                <label class="form-label">性别要求 <span class="required">*</span></label>
                <div class="radio-group" id="cGenderGroup">
                    <label class="radio-pill <?php echo $form['gender_requirement']==='male'?'checked':''; ?>" onclick="cCheckRadio(this,'cGenderGroup')">
                        <input type="radio" name="gender_requirement" value="male" <?php echo $form['gender_requirement']==='male'?'checked':''; ?>> 👨 男生
                    </label>
                    <label class="radio-pill <?php echo $form['gender_requirement']==='female'?'checked':''; ?>" onclick="cCheckRadio(this,'cGenderGroup')">
                        <input type="radio" name="gender_requirement" value="female" <?php echo $form['gender_requirement']==='female'?'checked':''; ?>> 👩 女生
                    </label>
                    <label class="radio-pill <?php echo $form['gender_requirement']==='any'?'checked':''; ?>" onclick="cCheckRadio(this,'cGenderGroup')">
                        <input type="radio" name="gender_requirement" value="any" <?php echo $form['gender_requirement']==='any'?'checked':''; ?>> 🤝 不限
                    </label>
                </div>
            </div>

            <!-- 转租专属字段 -->
            <div id="cGroupSubletExtras" style="display:none;">
                <div class="create-form-row">
                    <div class="form-group">
                        <label class="form-label">租期剩余（月） <span class="required">*</span></label>
                        <input type="number" class="form-input" name="remaining_months" id="cRemainingMonths"
                               placeholder="1 ~ 36" min="1" max="36"
                               value="<?php echo htmlspecialchars($form['remaining_months']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">最早入住日期 <span class="required">*</span></label>
                        <input type="date" class="form-input" name="move_in_date" id="cMoveInDate"
                               min="<?php echo date('Y-m-d'); ?>"
                               value="<?php echo htmlspecialchars($form['move_in_date']); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">是否可续租 <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（选填）</span></label>
                    <div class="radio-group" id="cRenewableGroup">
                        <label class="radio-pill <?php echo $form['renewable']==='yes'?'checked':''; ?>" onclick="cCheckRadio(this,'cRenewableGroup')">
                            <input type="radio" name="renewable" value="yes" <?php echo $form['renewable']==='yes'?'checked':''; ?>> ✅ 可续租
                        </label>
                        <label class="radio-pill <?php echo $form['renewable']==='no'?'checked':''; ?>" onclick="cCheckRadio(this,'cRenewableGroup')">
                            <input type="radio" name="renewable" value="no" <?php echo $form['renewable']==='no'?'checked':''; ?>> ❌ 不可续租
                        </label>
                    </div>
                </div>
            </div>

            <hr class="create-form-divider">

            <div class="create-form-row">
                <!-- 区域 -->
                <div class="form-group">
                    <label class="form-label">所属区域 <span class="required">*</span></label>
                    <select class="form-select" name="region" id="cRegion" onchange="cHandleRegionChange()">
                        <option value="">请选择区域</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $form['region']===$r?'selected':''; ?>>
                                <?php echo htmlspecialchars($r); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 学校范围 -->
                <div class="form-group">
                    <label class="form-label">学校范围 <span class="required">*</span> <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（可多选）</span></label>
                    <div class="metro-select" id="cSchoolSelect">
                        <div class="metro-trigger" onclick="cToggleSchool()">
                            <div class="metro-tags-wrap" id="cSchoolTags">
                                <span class="metro-placeholder">点击选择学校范围</span>
                            </div>
                            <span style="color:var(--text-hint);font-size:12px;">▼</span>
                        </div>
                        <div class="metro-dropdown" id="cSchoolDropdown">
                            <div style="padding:8px 12px; border-bottom:1px solid #eee;">
                                <input
                                    type="text"
                                    id="schoolSearch"
                                    placeholder="搜索学校..."
                                    style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:6px; outline:none;"
                                    oninput="filterSchools()">
                            </div>
                            <div style="max-height:240px; overflow-y:auto; padding:4px 0;" id="schoolOptionList">
                                <?php foreach ($schoolGroups as $groupLabel => $groupOptions): ?>
                                    <div style="padding:8px 12px 4px;font-size:12px;color:var(--text-hint);font-weight:600;">
                                        <?php echo htmlspecialchars($groupLabel); ?>
                                    </div>
                                    <?php foreach ($groupOptions as $code => $label): ?>
                                        <?php $schoolName = school_short_name($code); ?>
                                        <div class="metro-option <?php echo in_array($schoolName, $selectedSchoolScopes, true) ? 'selected' : ''; ?>" onclick="cToggleSchoolItem(this)" data-name="<?php echo htmlspecialchars($schoolName); ?>">
                                            <div class="metro-option-check"></div>
                                            🎓 <?php echo htmlspecialchars($schoolName); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div id="cSchoolInputs">
                        <?php foreach ($selectedSchoolScopes as $schoolName): ?>
                            <input type="hidden" name="school_scope[]" value="<?php echo htmlspecialchars($schoolName); ?>">
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 地铁站多选 -->
            <div class="form-group">
                <label class="form-label">附近地铁站 <span class="required">*</span> <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（可多选）</span></label>
                <input type="hidden" name="metro_stations" id="cMetroInput" value="<?php echo htmlspecialchars($form['metro_stations']); ?>">
                <div class="metro-select" id="cMetroSelect">
                    <div class="metro-trigger" onclick="cToggleMetro()">
                        <div class="metro-tags-wrap" id="cMetroTags">
                            <span class="metro-placeholder">点击选择地铁站</span>
                        </div>
                        <span style="color:var(--text-hint);font-size:12px;">▼</span>
                    </div>
                    <div class="metro-dropdown" id="cMetroDropdown">
                        <!-- 搜索框 -->
                        <div style="padding:8px 12px; border-bottom:1px solid #eee;">
                            <input
                                type="text"
                                id="metroSearch"
                                placeholder="搜索地铁站..."
                                style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:6px; outline:none;"
                                oninput="filterMetroStations()">
                        </div>
                        <div id="cMetroLineFilters" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;border-bottom:1px solid #eee;"></div>
                        <div style="max-height:240px; overflow-y:auto; padding:4px 0;" id="metroStationList">
                            <div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">请先选择所属区域</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 地铁站多选 -->

            <hr class="create-form-divider">

            <!-- 图片上传（租房 / 有房找室友） -->
            <div class="form-group" id="cGroupImages">
                <label class="form-label">上传图片 <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（选填，最多3张）</span></label>
                <div class="upload-area" id="cUploadArea">
                    <div class="upload-slot" onclick="document.getElementById('cFileInput').click()">
                        <span class="upload-slot-icon">📷</span>
                        <span class="upload-slot-text">添加图片</span>
                    </div>
                </div>
                <input type="file" id="cFileInput" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none;" onchange="cHandleImages(this)">
                <div class="form-hint" style="margin-top:8px;">支持 JPG/PNG，单张不超过 5MB，最多 3 张</div>
            </div>

            <!-- 其他信息 -->
            <div class="form-group">
                <label class="form-label">其他信息 <span style="font-weight:400;color:var(--text-hint);font-size:12px;">（选填）</span></label>
                <textarea class="form-input" name="content" id="cContent" rows="4"
                          placeholder="补充说明，如家电配备、周边环境、合租要求等，100字以内"
                          maxlength="100"
                          oninput="cCharCount(this,100,'cContentCount')"
                          style="resize:vertical;"><?php echo htmlspecialchars($form['content']); ?></textarea>
                <div class="char-count" id="cContentCount">0/100</div>
            </div>

            <div class="create-step-nav">
                <button type="button" class="btn btn-outline" onclick="cGoToStep(1)">← 上一步</button>
                <button type="button" class="btn btn-primary" onclick="cGoToStep(3)">下一步：预览 →</button>
            </div>
        </form>
    </div>

    <!-- Step 3: Preview -->
    <div id="cSection3" class="create-form-section" style="display:none;">
        <h2 class="create-section-title">预览并发布</h2>
        <p class="create-section-desc">确认信息无误后点击发布，发布后将立即对外展示</p>

        <div class="preview-grid" id="cPreviewGrid"></div>

        <div class="create-step-nav" style="margin-top:28px;">
            <button type="button" class="btn btn-outline" onclick="cGoToStep(2)">← 修改信息</button>
            <button type="button" class="btn btn-primary" onclick="cSubmit()">✓ 确认发布</button>
        </div>
    </div>

</div><!-- /.create-form-container -->

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
/* ==================== State ==================== */
let cSelectedType  = '';
let cRoommateMode  = '';
let cSelectedMetros = [];
let cSelectedSchools = <?php echo json_encode(array_values($selectedSchoolScopes), JSON_UNESCAPED_UNICODE); ?>;
let cActiveMetroLine = 'all';
let cUploadedImages = [];   // base64 previews
let cFileObjects    = [];   // actual File objects, synced back to <input> before submit
const cCurrentUserRole = <?php echo json_encode($userRole, JSON_UNESCAPED_UNICODE); ?>;
const C_REGION_METRO_MAP = <?php echo json_encode($regionMetroMap, JSON_UNESCAPED_UNICODE); ?>;
const C_METRO_LINE_STATIONS = {
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
const C_METRO_LINE_COLORS = {
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

function cGetTypeDenyMessage(type) {
    if (cCurrentUserRole === 'admin') return '';
    if (cCurrentUserRole === 'landlord') {
        return type === 'rent' ? '' : '房源供给方仅可发布租房类型。';
    }
    if (cCurrentUserRole === 'student') {
        return type === 'rent' ? '港硕学生仅可发布找室友和转租类型。' : '';
    }
    return '当前账号角色无发布权限。';
}

const C_TYPE_CONFIG = {
    'rent': {
        badge: '🏠 发布租房', badgeClass: 'rent',
        title: '填写房源信息', desc: '请填写以下房源信息，带 * 为必填项',
        priceLabel: '月租金（HKD）', maxPrice: 100000,
        showFloor: true, showRentPeriod: true, showGender: false, showNeedCount: false, showImages: true, showSubletFields: false
    },
    'sublet': {
        badge: '🔁 转租', badgeClass: 'sublet',
        title: '填写转租信息', desc: '请填写以下转租信息，带 * 为必填项',
        priceLabel: '转租价格（HKD）', maxPrice: 100000,
        showFloor: true, showRentPeriod: false, showGender: true, showNeedCount: false, showImages: true, showSubletFields: true
    },
    'roommate-source': {
        badge: '🏘️ 已有房源·找室友', badgeClass: 'roommate-source',
        title: '填写房源与室友需求', desc: '请填写以下信息，带 * 为必填项',
        priceLabel: '月租金（HKD）', maxPrice: 100000,
        showFloor: true, showRentPeriod: true, showGender: true, showNeedCount: true, showImages: true, showSubletFields: false
    },
    'roommate-nosource': {
        badge: '🔍 无房源·找室友', badgeClass: 'roommate-nosource',
        title: '填写室友需求', desc: '请填写以下信息，带 * 为必填项',
        priceLabel: '租房预算（HKD）', maxPrice: 50000,
        showFloor: false, showRentPeriod: true, showGender: true, showNeedCount: false, showImages: false, showSubletFields: false
    }
};

/* ==================== Step 1: Type Select ==================== */
function cSelectType(type) {
    const denyMessage = cGetTypeDenyMessage(type);
    if (denyMessage) {
        showToast(denyMessage, 'error');
        return;
    }

    cSelectedType = type;
    document.querySelectorAll('.create-type-card').forEach(c => c.classList.remove('selected'));
    document.querySelector('.create-type-card[data-type="' + type + '"]').classList.add('selected');
    if (type !== 'roommate') cRoommateMode = '';
    const modeWrap = document.getElementById('cRoommateModeWrap');
    if (modeWrap) modeWrap.style.display = type === 'roommate' ? 'block' : 'none';
    document.querySelectorAll('.create-subtype-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.mode === cRoommateMode);
    });
    cRefreshStep1State();
}

function cSelectRoommateMode(mode, silent = false) {
    cRoommateMode = mode;
    document.querySelectorAll('.create-subtype-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.mode === mode);
    });
    cRefreshStep1State();
    if (!silent) showToast('已选择：' + (mode === 'source' ? '有房源找室友' : '无房源找室友'), 'success');
}

function cGetEffectiveType() {
    if (cSelectedType !== 'roommate') return cSelectedType;
    if (cRoommateMode === 'source') return 'roommate-source';
    if (cRoommateMode === 'nosource') return 'roommate-nosource';
    return '';
}

function cRefreshStep1State() {
    const btn = document.getElementById('cToStep2Btn');
    if (!btn) return;
    const canNext = cSelectedType && (cSelectedType !== 'roommate' || !!cRoommateMode);
    btn.style.opacity = canNext ? '1' : '0.45';
    btn.style.pointerEvents = canNext ? 'auto' : 'none';
}

/* ==================== Step Navigation ==================== */
function cGoToStep(step) {
    if (step === 2) {
        if (!cSelectedType) { showToast('请先选择发布类型', 'error'); return; }
        if (cSelectedType === 'roommate' && !cRoommateMode) { showToast('请先选择有房源或无房源', 'error'); return; }
    }
    if (step === 3 && !cValidateForm()) return;

    for (let i = 1; i <= 3; i++) {
        const el = document.getElementById('cStep' + i);
        el.classList.remove('active', 'done');
        if (i < step) el.classList.add('done');
        else if (i === step) el.classList.add('active');
    }
    document.getElementById('cLine1').classList.toggle('done', step > 1);
    document.getElementById('cLine2').classList.toggle('done', step > 2);

    document.getElementById('cSection1').style.display = step === 1 ? 'block' : 'none';
    document.getElementById('cSection2').style.display = step === 2 ? 'block' : 'none';
    document.getElementById('cSection3').style.display = step === 3 ? 'block' : 'none';

    if (step === 2) cConfigureForm();
    if (step === 3) cBuildPreview();

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ==================== Configure Form ==================== */
function cConfigureForm() {
    const effectiveType = cGetEffectiveType();
    const cfg = C_TYPE_CONFIG[effectiveType];
    if (!cfg) return;
    document.getElementById('cInputType').value = effectiveType;
    document.getElementById('cInputRoommateMode').value = cSelectedType === 'roommate' ? cRoommateMode : '';
    document.getElementById('cFormBadge').className   = 'create-section-badge ' + cfg.badgeClass;
    document.getElementById('cFormBadge').textContent = cfg.badge;
    document.getElementById('cFormTitle').textContent = cfg.title;
    document.getElementById('cFormDesc').textContent  = cfg.desc;
    document.getElementById('cLabelPrice').innerHTML  = cfg.priceLabel + ' <span class="required">*</span>';
    document.getElementById('cPrice').placeholder = '1000 ~ ' + cfg.maxPrice;
    document.getElementById('cPrice').max         = cfg.maxPrice;
    document.getElementById('cGroupFloor').style.display     = cfg.showFloor     ? 'block' : 'none';
    document.getElementById('cGroupRentPeriod').style.display= cfg.showRentPeriod ? 'block' : 'none';
    document.getElementById('cGroupGender').style.display    = cfg.showGender    ? 'block' : 'none';
    document.getElementById('cGroupNeedCount').style.display = cfg.showNeedCount ? 'block' : 'none';
    document.getElementById('cGroupImages').style.display    = cfg.showImages    ? 'block' : 'none';
    document.getElementById('cGroupSubletExtras').style.display = cfg.showSubletFields ? 'block' : 'none';
    const genderLabel = document.querySelector('#cGroupGender .form-label');
    if (genderLabel) {
        genderLabel.innerHTML = (effectiveType === 'sublet' ? '入住性别要求' : '性别要求') + ' <span class="required">*</span>';
    }
    if (cfg.showSubletFields) {
        const moveInEl = document.getElementById('cMoveInDate');
        const todayStr = new Date().toISOString().split('T')[0];
        if (moveInEl) moveInEl.min = todayStr;
    }
}

/* ==================== Radio Pills ==================== */
function cCheckRadio(el, groupId) {
    document.querySelectorAll('#' + groupId + ' .radio-pill').forEach(p => p.classList.remove('checked'));
    el.classList.add('checked');
    el.querySelector('input').checked = true;
}

/* ==================== School ==================== */
function cSyncSchoolInputs() {
    const wrap = document.getElementById('cSchoolInputs');
    wrap.innerHTML = cSelectedSchools.map(name =>
        '<input type="hidden" name="school_scope[]" value="' + esc(name) + '">'
    ).join('');
}

function cRenderSchoolTags() {
    const container = document.getElementById('cSchoolTags');
    if (cSelectedSchools.length === 0) {
        container.innerHTML = '<span class="metro-placeholder">点击选择学校范围</span>';
    } else {
        container.innerHTML = cSelectedSchools.map(name =>
            '<span class="metro-tag">🎓 ' + esc(name) + ' <span class="metro-tag-x" onclick="event.stopPropagation();cRemoveSchool(\'' + esc(name) + '\')">×</span></span>'
        ).join('');
    }
}

function cToggleSchool() {
    document.getElementById('cSchoolDropdown').classList.toggle('open');
}

function cToggleSchoolItem(el) {
    const name = el.dataset.name.trim();
    el.classList.toggle('selected');

    if (el.classList.contains('selected')) {
        if (!cSelectedSchools.includes(name)) cSelectedSchools.push(name);
    } else {
        cSelectedSchools = cSelectedSchools.filter(item => item !== name);
    }

    cRenderSchoolTags();
    cSyncSchoolInputs();
}

function cRemoveSchool(name) {
    cSelectedSchools = cSelectedSchools.filter(item => item !== name);
    document.querySelectorAll('#schoolOptionList .metro-option').forEach(el => {
        if (el.dataset.name.trim() === name) el.classList.remove('selected');
    });
    cRenderSchoolTags();
    cSyncSchoolInputs();
}

function filterSchools() {
    const kw = document.getElementById('schoolSearch').value.toLowerCase().trim();
    const items = document.querySelectorAll('#schoolOptionList .metro-option');

    items.forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        item.style.display = kw === '' || name.includes(kw) ? 'flex' : 'none';
    });
}

/* ==================== Metro ==================== */
function cGetAvailableMetroStations(region) {
    return C_REGION_METRO_MAP[region] || [];
}

function cGetAvailableMetroLines(region) {
    const stations = cGetAvailableMetroStations(region);
    return Object.keys(C_METRO_LINE_STATIONS).filter(function(line) {
        return C_METRO_LINE_STATIONS[line].some(function(station) { return stations.includes(station); });
    });
}

function cGetVisibleMetroStations(region) {
    const stations = cGetAvailableMetroStations(region);
    if (cActiveMetroLine === 'all') return stations;
    const lineStations = C_METRO_LINE_STATIONS[cActiveMetroLine] || [];
    return stations.filter(function(station) { return lineStations.includes(station); });
}

function cSyncMetroInput() {
    document.getElementById('cMetroInput').value = cSelectedMetros.map(m => m.replace('🚇 ', '')).join(', ');
}

function cToggleMetro() {
    if (!document.getElementById('cRegion').value) {
        showToast('请先选择所属区域', 'error');
        return;
    }
    document.getElementById('cMetroDropdown').classList.toggle('open');
}

function cToggleMetroItem(el) {
    if (!el.classList.contains('selected') && cSelectedMetros.length >= 5) {
        showToast('最多只能选择 5 个地铁站', 'error');
        return;
    }

    el.classList.toggle('selected');
    const name = '🚇 ' + el.dataset.name.trim();

    if (el.classList.contains('selected')) {
        if (!cSelectedMetros.includes(name)) cSelectedMetros.push(name);
    } else {
        cSelectedMetros = cSelectedMetros.filter(m => m !== name);
    }

    cRenderMetroTags();
    cSyncMetroInput();
}
function filterMetroStations() {
    cRenderMetroOptions();
}

function cSwitchMetroLine(line) {
    cActiveMetroLine = line;
    cRenderMetroOptions();
}

function cRenderMetroOptions() {
    const region = document.getElementById('cRegion').value;
    const list = document.getElementById('metroStationList');
    const filters = document.getElementById('cMetroLineFilters');
    const lines = cGetAvailableMetroLines(region);
    if (cActiveMetroLine !== 'all' && !lines.includes(cActiveMetroLine)) {
        cActiveMetroLine = 'all';
    }

    if (!region) {
        if (filters) filters.innerHTML = '';
        list.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">请先选择所属区域</div>';
        return;
    }

    if (filters) {
        let html = '<button type="button" onclick="event.stopPropagation();cSwitchMetroLine(\'all\')" style="border:1px solid #2d6cdf;border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;background:#2d6cdf;color:#fff;">全部</button>';
        lines.forEach(function(line) {
            const color = C_METRO_LINE_COLORS[line] || '#6b7280';
            html += '<button type="button" onclick="event.stopPropagation();cSwitchMetroLine(\'' + esc(line) + '\')" style="border:1px solid ' + color + ';border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;background:' + color + ';color:#fff;">' + esc(line) + '</button>';
        });
        filters.innerHTML = html;
    }

    const stations = cGetVisibleMetroStations(region);
    const kw = document.getElementById('metroSearch').value.toLowerCase().trim();
    const finalStations = stations.filter(function(name) { return kw === '' || name.toLowerCase().includes(kw); });
    if (stations.length === 0) {
        list.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">当前区域暂无可选地铁站</div>';
        return;
    }
    if (finalStations.length === 0) {
        list.innerHTML = '<div class="metro-empty-state" style="padding:12px 16px;color:var(--text-hint);">该线路下无匹配站点</div>';
        return;
    }

    list.innerHTML = finalStations.map(name =>
        '<div class="metro-option' + (cSelectedMetros.includes('🚇 ' + name) ? ' selected' : '') + '" onclick="cToggleMetroItem(this)" data-name="' + esc(name) + '"><div class="metro-option-check"></div>🚇 ' + esc(name) + '</div>'
    ).join('');
}

function cHandleRegionChange() {
    const region = document.getElementById('cRegion').value;
    const allowedNames = cGetAvailableMetroStations(region);
    cActiveMetroLine = 'all';
    const beforeCount = cSelectedMetros.length;
    cSelectedMetros = cSelectedMetros.filter(m => allowedNames.includes(m.replace('🚇 ', '')));
    if (beforeCount !== cSelectedMetros.length) {
        showToast('已清除不属于该区域的地铁站', 'success');
    }
    cRenderMetroTags();
    cSyncMetroInput();
    cRenderMetroOptions();
}

function cRemoveMetro(name) {
    cSelectedMetros = cSelectedMetros.filter(m => m !== name);
    cRenderMetroTags();
    cSyncMetroInput();
    cRenderMetroOptions();
}
function cRenderMetroTags() {
    const container = document.getElementById('cMetroTags');
    if (cSelectedMetros.length === 0) {
        container.innerHTML = '<span class="metro-placeholder">点击选择地铁站</span>';
    } else {
        container.innerHTML = cSelectedMetros.map(m =>
            '<span class="metro-tag">' + m + ' <span class="metro-tag-x" onclick="event.stopPropagation();cRemoveMetro(\'' + m + '\')">×</span></span>'
        ).join('');
    }
}
document.addEventListener('click', function(e) {
    const sel = document.getElementById('cMetroSelect');
    if (sel && !sel.contains(e.target)) {
        document.getElementById('cMetroDropdown').classList.remove('open');
    }
    const schoolSel = document.getElementById('cSchoolSelect');
    if (schoolSel && !schoolSel.contains(e.target)) {
        document.getElementById('cSchoolDropdown').classList.remove('open');
    }
});

/* ==================== Image Upload ==================== */
function cHandleImages(input) {
    const files  = Array.from(input.files);
    const remain = 3 - cFileObjects.length;
    if (remain <= 0) { showToast('最多上传3张图片', 'error'); input.value = ''; return; }
    files.slice(0, remain).forEach(file => {
        if (!['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)) { showToast('仅支持 JPG/PNG/WEBP/GIF', 'error'); return; }
        if (file.size > 5 * 1024 * 1024) { showToast(file.name + ' 超过5MB', 'error'); return; }
        cFileObjects.push(file);
        const reader = new FileReader();
        reader.onload = function(e) { cUploadedImages.push(e.target.result); cRenderUploadArea(); };
        reader.readAsDataURL(file);
    });
    input.value = '';
    cSyncFilesToInput();
}
function cRemoveImage(idx) {
    cUploadedImages.splice(idx, 1);
    cFileObjects.splice(idx, 1);
    cRenderUploadArea();
    cSyncFilesToInput();
}
function cSyncFilesToInput() {
    const input = document.getElementById('cFileInput');
    if (!input) return;
    try {
        const dt = new DataTransfer();
        cFileObjects.forEach(function(f) { dt.items.add(f); });
        input.files = dt.files;
    } catch (e) {
        // DataTransfer not supported (older browsers) — files may not submit
    }
}
function cRenderUploadArea() {
    const area = document.getElementById('cUploadArea');
    area.innerHTML = cUploadedImages.map((src, i) =>
        '<div class="upload-slot"><img src="' + src + '" alt="预览"><div class="remove-img" onclick="event.stopPropagation();cRemoveImage(' + i + ')">×</div></div>'
    ).join('');
    if (cUploadedImages.length < 3) {
        area.innerHTML += '<div class="upload-slot" onclick="document.getElementById(\'cFileInput\').click()"><span class="upload-slot-icon">📷</span><span class="upload-slot-text">添加图片</span></div>';
    }
}

/* ==================== Char Counter ==================== */
function cCharCount(el, max, countId) {
    const len = el.value.length;
    const counter = document.getElementById(countId);
    if (!counter) return;
    counter.textContent = len + '/' + max;
    counter.className   = 'char-count' + (len > max ? ' over' : len > max * 0.85 ? ' warn' : '');
}

/* ==================== Validate ==================== */
function cValidateForm() {
    const cfg = C_TYPE_CONFIG[cGetEffectiveType()];
    if (!cfg) { showToast('发布类型无效，请重新选择', 'error'); return false; }
    let ok = true;
    const err = (msg) => { showToast(msg, 'error'); ok = false; };

    const title = document.getElementById('cTitle').value.trim();
    if (title.length < 5 || title.length > 50) { err('标题需为5-50个字符'); return false; }

    const price = parseFloat(document.getElementById('cPrice').value);
    if (isNaN(price) || price < 1000 || price > cfg.maxPrice) { err('价格需在1000-' + cfg.maxPrice + '之间'); return false; }

    if (cfg.showFloor) {
        const floorValue = document.getElementById('cFloor').value.trim();
        if (floorValue === '') { err('请填写楼层信息'); return false; }
    }
    if (cfg.showNeedCount) {
        const nc = parseInt(document.getElementById('cNeedCount').value);
        if (isNaN(nc) || nc < 1 || nc > 10) { err('请输入1-10的需求人数'); return false; }
    }
    if (cfg.showRentPeriod && !document.querySelector('#cPeriodGroup input:checked')) { err('请选择租期'); return false; }
    if (cfg.showGender && !document.querySelector('#cGenderGroup input:checked')) { err('请选择性别要求'); return false; }
    if (cfg.showSubletFields) {
        const remainingMonths = parseInt(document.getElementById('cRemainingMonths').value, 10);
        if (isNaN(remainingMonths) || remainingMonths < 1 || remainingMonths > 36) {
            err('租期剩余需为 1-36 个月'); return false;
        }
        const moveInDate = document.getElementById('cMoveInDate').value;
        if (!moveInDate) { err('请选择最早入住日期'); return false; }
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const moveIn = new Date(moveInDate + 'T00:00:00');
        if (Number.isNaN(moveIn.getTime()) || moveIn < today) {
            err('最早入住日期不能早于今天'); return false;
        }
    }
    if (!document.getElementById('cRegion').value) { err('请选择所属区域'); return false; }
    if (cSelectedSchools.length === 0) { err('请选择学校范围'); return false; }
    if (cSelectedMetros.length === 0) { err('请选择至少一个地铁站'); return false; }

    return ok;
}

/* ==================== Preview ==================== */
function cBuildPreview() {
    const cfg = C_TYPE_CONFIG[cGetEffectiveType()];
    if (!cfg) return;
    const periodMap = { short: '6个月以下', medium: '6个月至1年', long: '1年及以上' };
    const genderMap = { male: '👨 男生', female: '👩 女生', any: '🤝 不限' };

    const title    = document.getElementById('cTitle').value;
    const price    = parseFloat(document.getElementById('cPrice').value) || 0;
    const region   = document.getElementById('cRegion').value;
    const floor    = document.getElementById('cFloor').value;
    const content  = document.getElementById('cContent').value;
    const periodEl = document.querySelector('#cPeriodGroup input:checked');
    const genderEl = document.querySelector('#cGenderGroup input:checked');
    const needCount= document.getElementById('cNeedCount').value;
    const remainingMonths = document.getElementById('cRemainingMonths').value;
    const moveInDate = document.getElementById('cMoveInDate').value;
    const renewableEl = document.querySelector('#cRenewableGroup input:checked');
    const renewableMap = { yes: '可续租', no: '不可续租' };
    const renewableStr = renewableEl ? renewableMap[renewableEl.value] : '-';
    const periodStr = periodEl ? periodMap[periodEl.value] : '-';
    const genderStr = genderEl ? genderMap[genderEl.value] : '-';
    const imgSrc   = cUploadedImages.length > 0 ? cUploadedImages[0] : '';

    // Card
    let cardHtml = '<div class="preview-card">';
    cardHtml += '<div class="preview-card-image">' + (imgSrc ? '<img src="' + imgSrc + '" style="width:100%;height:100%;object-fit:cover;">' : '📷') + '</div>';
    cardHtml += '<div class="preview-card-body">';
    cardHtml += '<span class="create-section-badge ' + cfg.badgeClass + '" style="margin-bottom:10px;">' + cfg.badge + '</span>';
    cardHtml += '<div class="preview-card-title">' + esc(title) + '</div>';
    cardHtml += '<div class="preview-card-price">' + price.toLocaleString() + '</div>';
    cardHtml += '<div class="preview-tags">';
    cardHtml += '<span class="tag tag-region">' + esc(region) + '</span>';
    cSelectedMetros.forEach(m => { cardHtml += '<span class="tag tag-metro">' + esc(m) + '</span>'; });
    cSelectedSchools.forEach(school => { cardHtml += '<span class="tag tag-school">' + esc(school) + '</span>'; });
    cardHtml += '</div>';
    let metaHtml = '<div class="preview-meta">';
    if (cfg.showRentPeriod) {
        metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">租期</div><div class="preview-meta-value">' + periodStr + '</div></div>';
    }
    if (cfg.showFloor) metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">楼层</div><div class="preview-meta-value">' + esc(floor||'-') + '</div></div>';
    if (cfg.showGender) metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">性别要求</div><div class="preview-meta-value">' + genderStr + '</div></div>';
    if (cfg.showNeedCount) metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">需求人数</div><div class="preview-meta-value">' + esc(needCount||'-') + '人</div></div>';
    if (cfg.showSubletFields) {
        metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">剩余租期</div><div class="preview-meta-value">' + esc(remainingMonths || '-') + '个月</div></div>';
        metaHtml += '<div class="preview-meta-item"><div class="preview-meta-label">最早入住</div><div class="preview-meta-value">' + esc(moveInDate || '-') + '</div></div>';
    }
    metaHtml += '</div>';
    cardHtml += metaHtml + '</div></div>';

    // Detail box
    const rows = [
        ['📌 类型', cfg.badge],
        ['💰 ' + cfg.priceLabel, price.toLocaleString() + ' HKD/月'],
        ['📍 区域', region],
        ['🏫 学校范围', cSelectedSchools.join('、') || '-'],
        ['🚇 地铁站', cSelectedMetros.map(m => m.replace('🚇 ', '')).join('、') || '-'],
    ];
    if (cfg.showRentPeriod) rows.push(['📅 租期', periodStr]);
    if (cfg.showFloor)     rows.push(['🏢 楼层', floor || '-']);
    if (cfg.showGender)    rows.push(['👥 性别要求', genderStr]);
    if (cfg.showNeedCount) rows.push(['🔢 需求人数', (needCount || '-') + ' 人']);
    if (cfg.showSubletFields) {
        rows.push(['⏳ 剩余租期', (remainingMonths || '-') + ' 个月']);
        rows.push(['🗓️ 最早入住日期', moveInDate || '-']);
        rows.push(['♻️ 可续租', renewableStr]);
    }
    if (content)           rows.push(['📝 其他信息', content]);

    let detailHtml = '<div class="preview-detail-box"><h3 style="font-size:16px;font-weight:600;margin-bottom:16px;">📋 详细信息</h3>';
    rows.forEach(([label, val]) => {
        detailHtml += '<div class="preview-detail-row"><span class="preview-detail-label">' + esc(label) + '</span><span class="preview-detail-value">' + esc(val) + '</span></div>';
    });
    detailHtml += '</div>';

    document.getElementById('cPreviewGrid').innerHTML = cardHtml + detailHtml;
}

function esc(str) {
    const d = document.createElement('div'); d.textContent = String(str); return d.innerHTML;
}

/* ==================== Submit ==================== */
function cSubmit() {
    cSyncFilesToInput();
    document.getElementById('cForm').submit();
}

/* ==================== Init ==================== */
(function init() {
    // 如果 PHP 回传了错误，直接显示第 2 步
    <?php if (!empty($errors)): ?>
    cSelectedType = <?php echo json_encode($form['type']); ?>;
    cRoommateMode = <?php echo json_encode($form['roommate_mode']); ?>;
    if (cSelectedType === 'roommate') {
        cSelectType('roommate');
        if (cRoommateMode) cSelectRoommateMode(cRoommateMode, true);
    } else if (document.querySelector('.create-type-card[data-type="' + cSelectedType + '"]')) {
        cSelectType(cSelectedType);
    }
    if (C_TYPE_CONFIG[cGetEffectiveType()]) {
        document.getElementById('cSection1').style.display = 'none';
        document.getElementById('cSection2').style.display = 'block';
        document.getElementById('cStep1').classList.remove('active'); document.getElementById('cStep1').classList.add('done');
        document.getElementById('cLine1').classList.add('done');
        document.getElementById('cStep2').classList.add('active');
        cConfigureForm();
    }
    // 恢复地铁站
    <?php if ($form['metro_stations'] !== ''): ?>
    (function() {
        const metros = <?php echo json_encode(array_map('trim', explode(',', $form['metro_stations']))); ?>;
        metros.forEach(m => {
            cSelectedMetros.push('🚇 ' + m);
        });
        cRenderMetroTags();
        cSyncMetroInput();
    })();
    <?php endif; ?>
    <?php endif; ?>
    cRenderSchoolTags();
    cSyncSchoolInputs();
    cRenderMetroOptions();
})();
</script>
