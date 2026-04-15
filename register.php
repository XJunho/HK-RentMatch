<?php
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        (isset($_GET['ajax']) && $_GET['ajax'] === '1')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = $_POST['role'] ?? 'student';
    $school   = normalize_school_code($_POST['school'] ?? '');
    $schoolOptions = school_options_map();
    $username = trim($_POST['username'] ?? '');
    $gender   = $_POST['gender'] ?? '';
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    // 角色
    if (!in_array($role, ['student', 'landlord'], true)) {
        $errors['role'] = '请选择有效身份。';
    }

    // 学校（学生必填）
    if ($role === 'student' && $school === null) {
        $errors['school'] = '请选择所属学校。';
    } elseif ($role === 'student' && !array_key_exists($school, $schoolOptions)) {
        $errors['school'] = '学校选项无效，请重新选择。';
    }

    // 昵称
    if ($username === '' || mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 20) {
        $errors['username'] = '昵称需为 2-20 位。';
    }

    // 性别
    if (!in_array($gender, ['male', 'female'], true)) {
        $errors['gender'] = '请选择性别。';
    }

    // 邮箱
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = '邮箱格式不正确。';
    }

    // 电话（简单校验为 8 位数字）
    if (!is_valid_hk_phone($phone)) {
        $errors['phone'] = '请输入香港 8 位电话号码（首位为 2-9）。';
    }

    // 密码
    if (strlen($password) < 8 || strlen($password) > 20) {
        $errors['password'] = '密码长度需为 8-20 位。';
    } elseif (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $errors['password'] = '密码需包含大小写字母和数字。';
    }

    if ($password !== $confirm) {
        $errors['password_confirm'] = '两次输入的密码不一致。';
    }

    if (empty($errors)) {
        // 检查邮箱是否已存在
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors['email'] = '该邮箱已被注册。';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, email, password, phone, gender, role, school, status)
                 VALUES (:username, :email, :password, :phone, :gender, :role, :school, :status)'
            );

            $stmt->execute([
                ':username' => $username,
                ':email'    => $email,
                ':password' => $hash,
                ':phone'    => $phone,
                ':gender'   => $gender,
                ':role'     => $role,
                ':school'   => $school,
                ':status'   => 'active',
            ]);

            $userId = (int)$pdo->lastInsertId();

            $userRow = [
                'id'       => $userId,
                'username' => $username,
                'email'    => $email,
                'role'     => $role,
                'school'   => $school,
                'gender'   => $gender,
                'status'   => 'active',
            ];

            login_user($userRow);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => '注册成功。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header('Location: index.php');
            exit;
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <section class="auth-card">
        <h1 class="auth-title">创建账号</h1>
        <p class="auth-subtitle">为港硕学生与房源供给方快速建立可信账号</p>

        <form method="post" class="auth-form" novalidate>
            <div class="form-group">
                <label class="form-label">选择身份 <span class="required">*</span></label>
                <div class="role-cards">
                    <label class="role-card <?php echo (($_POST['role'] ?? 'student') === 'student') ? 'selected' : ''; ?>">
                        <input type="radio" name="role" value="student"
                            class="role-radio-input"
                            <?php echo (($_POST['role'] ?? 'student') === 'student') ? 'checked' : ''; ?>>
                        <div class="role-icon">🎓</div>
                        <div class="role-name">港硕学生</div>
                        <div class="role-desc">可发布找室友/转租<br>可收藏/申请</div>
                    </label>
                    <label class="role-card <?php echo (($_POST['role'] ?? '') === 'landlord') ? 'selected' : ''; ?>">
                        <input type="radio" name="role" value="landlord"
                            class="role-radio-input"
                            <?php echo (($_POST['role'] ?? '') === 'landlord') ? 'checked' : ''; ?>>
                        <div class="role-icon">🏢</div>
                        <div class="role-name">房源供给方</div>
                        <div class="role-desc">仅可发布租房<br>不可收藏/申请</div>
                    </label>
                </div>
                <?php if (!empty($errors['role'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['role']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group" id="schoolGroupRegister">
                <label class="form-label">所属学校 <?php echo (($_POST['role'] ?? 'student') === 'student') ? '<span class="required">*</span>' : ''; ?></label>
                <select class="form-select" name="school">
                    <option value="">请选择学校</option>
                    <?php
                    $selectedSchool = normalize_school_code($_POST['school'] ?? '');
                    foreach (school_option_groups() as $groupLabel => $groupOptions): ?>
                        <optgroup label="<?php echo htmlspecialchars($groupLabel); ?>">
                            <?php foreach ($groupOptions as $code => $label): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"
                                    <?php echo $selectedSchool === $code ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['school'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['school']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">昵称 <span class="required">*</span></label>
                <input type="text" class="form-input" name="username"
                       placeholder="2-20位中英文、数字"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                <?php if (!empty($errors['username'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['username']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">性别 <span class="required">*</span></label>
                <div class="form-radio-group">
                    <label class="form-radio">
                        <input type="radio" name="gender" value="male"
                            <?php echo (($_POST['gender'] ?? '') === 'male') ? 'checked' : ''; ?>>
                        男
                    </label>
                    <label class="form-radio">
                        <input type="radio" name="gender" value="female"
                            <?php echo (($_POST['gender'] ?? '') === 'female') ? 'checked' : ''; ?>>
                        女
                    </label>
                </div>
                <?php if (!empty($errors['gender'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['gender']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">邮箱地址 <span class="required">*</span></label>
                <input type="email" class="form-input" name="email"
                       placeholder="请输入邮箱"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <?php if (!empty($errors['email'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['email']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">电话号码 <span class="required">*</span></label>
                <input type="tel" class="form-input" name="phone" maxlength="8" inputmode="numeric" pattern="[2-9][0-9]{7}"
                       placeholder="香港 8 位号码，如 91234567"
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                <?php if (!empty($errors['phone'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['phone']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">密码 <span class="required">*</span></label>
                <input type="password" class="form-input" name="password"
                       placeholder="8-20位，含大小写字母和数字">
                <?php if (!empty($errors['password'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['password']); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">确认密码 <span class="required">*</span></label>
                <input type="password" class="form-input" name="password_confirm"
                       placeholder="请再次输入密码">
                <?php if (!empty($errors['password_confirm'])): ?>
                    <div class="form-error"><?php echo htmlspecialchars($errors['password_confirm']); ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">注册</button>

            <p class="auth-switch">
                已有账号？
                <a href="login.php">立即登录</a>
            </p>
        </form>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

