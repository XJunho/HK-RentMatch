-- 注册登录 + 个人中心（收藏 / 申请）数据库核对脚本
-- 使用方法：
-- 1. 先把下方 SET 语句改成你的测试数据
-- 2. 每完成一个页面动作，执行对应查询核对结果
-- 3. 本脚本只包含查询，不做写操作

USE `hk_rentmatch`;

-- =========================
-- 0) 测试数据参数
-- =========================

SET @student_email = 'student_a@example.com';
SET @landlord_email = 'landlord_b@example.com';
SET @post_id = 1;

-- 自动解析用户 ID
SET @student_user_id = (
  SELECT id FROM users WHERE email = @student_email LIMIT 1
);
SET @landlord_user_id = (
  SELECT id FROM users WHERE email = @landlord_email LIMIT 1
);

-- 取学生 A 对测试帖子最新一条申请
SET @latest_application_id = (
  SELECT id
  FROM applications
  WHERE post_id = @post_id AND applicant_user_id = @student_user_id
  ORDER BY id DESC
  LIMIT 1
);

-- =========================
-- 1) 注册后核对
-- =========================

SELECT id, email, role, school, status, created_at
FROM users
WHERE email IN (@student_email, @landlord_email)
ORDER BY id;

SELECT email, COUNT(*) AS email_rows
FROM users
WHERE email IN (@student_email, @landlord_email)
GROUP BY email;

-- 重点核对：
-- 学生账号：role='student'，school 非空，status='active'
-- 房东账号：role='landlord'，school 可为空，status='active'
-- 重复注册负例后，同邮箱 email_rows 仍应为 1

-- =========================
-- 2) 登录准备核对
-- =========================

SELECT id, username, email, role, status, banned_until
FROM users
WHERE id IN (@student_user_id, @landlord_user_id)
ORDER BY id;

-- 若需要做“封禁用户不能登录”负例，可人工改库后再执行本查询确认状态

-- =========================
-- 3) 收藏后 / 取消收藏后核对
-- =========================

SELECT COUNT(*) AS favorite_count
FROM favorites
WHERE user_id = @student_user_id
  AND post_id = @post_id;

SELECT id, user_id, post_id, created_at
FROM favorites
WHERE user_id = @student_user_id
  AND post_id = @post_id
ORDER BY id DESC;

-- 重点核对：
-- 收藏成功后 favorite_count = 1
-- 取消收藏后 favorite_count = 0

-- =========================
-- 4) 收藏列表数据核对
-- =========================

SELECT
  f.id AS favorite_id,
  f.created_at AS favorite_created_at,
  p.id AS post_id,
  p.title,
  p.type,
  p.status,
  p.region
FROM favorites f
JOIN posts p ON p.id = f.post_id
WHERE f.user_id = @student_user_id
ORDER BY f.created_at DESC;

-- 重点核对：
-- 列表内容、帖子标题、帖子类型与“我的收藏”页面一致

-- =========================
-- 5) 发送申请后核对
-- =========================

SELECT id, post_id, applicant_user_id, message, status, owner_unread, applicant_result_unread, created_at, updated_at
FROM applications
WHERE post_id = @post_id
  AND applicant_user_id = @student_user_id
ORDER BY id DESC;

SELECT
  status,
  owner_unread,
  applicant_result_unread
FROM applications
WHERE id = @latest_application_id;

-- 重点核对：
-- status='pending'
-- owner_unread=1
-- applicant_result_unread=0

-- =========================
-- 6) 我的申请列表核对
-- =========================

SELECT
  a.id,
  a.status,
  a.message,
  a.created_at,
  p.id AS post_id,
  p.title,
  p.type AS post_type,
  u.username AS owner_name,
  u.phone AS owner_phone
FROM applications a
JOIN posts p ON p.id = a.post_id
JOIN users u ON u.id = p.user_id
WHERE a.applicant_user_id = @student_user_id
ORDER BY a.created_at DESC;

-- 重点核对：
-- “我的申请”页面中的状态、标题、联系人信息与本查询一致

-- =========================
-- 7) 撤回申请后核对
-- =========================

SELECT id, status, owner_unread, applicant_result_unread, updated_at
FROM applications
WHERE id = @latest_application_id;

-- 重点核对：
-- 撤回后 status='withdrawn'
-- 页面不再出现“撤回”按钮

-- =========================
-- 8) 房东进入 received 前后核对
-- =========================

SELECT
  a.id,
  a.status,
  a.owner_unread,
  a.applicant_result_unread,
  p.id AS post_id,
  p.title AS post_title,
  p.user_id AS owner_user_id
FROM applications a
JOIN posts p ON p.id = a.post_id
WHERE p.user_id = @landlord_user_id
ORDER BY a.created_at DESC;

SELECT COUNT(*) AS owner_unread_count
FROM applications a
JOIN posts p ON p.id = a.post_id
WHERE p.user_id = @landlord_user_id
  AND a.owner_unread = 1;

-- 重点核对：
-- 学生刚发送申请后，owner_unread_count 应增加
-- 房东打开 section=received 后，对应申请 owner_unread 应变为 0

-- =========================
-- 9) 房东同意 / 拒绝后核对
-- =========================

SELECT
  a.id,
  a.status,
  a.owner_unread,
  a.applicant_result_unread,
  a.updated_at,
  applicant.username AS applicant_name,
  applicant.phone AS applicant_phone
FROM applications a
JOIN users applicant ON applicant.id = a.applicant_user_id
WHERE a.id = @latest_application_id;

-- 重点核对：
-- 同意后 status='accepted'
-- 拒绝后 status='rejected'
-- applicant_result_unread=1
-- owner_unread=0

-- =========================
-- 10) 学生查看结果后核对
-- =========================

SELECT
  id,
  status,
  owner_unread,
  applicant_result_unread,
  updated_at
FROM applications
WHERE id = @latest_application_id;

SELECT COUNT(*) AS applicant_result_unread_count
FROM applications
WHERE applicant_user_id = @student_user_id
  AND applicant_result_unread = 1;

-- 重点核对：
-- 在学生打开 section=applications 之前，若房东已处理，应 applicant_result_unread=1
-- 学生打开 section=applications 之后，对应记录 applicant_result_unread 应变为 0

-- =========================
-- 11) 收到申请列表核对
-- =========================

SELECT
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
WHERE p.user_id = @landlord_user_id
ORDER BY a.created_at DESC;

-- 重点核对：
-- “收到申请”页面中的申请人、帖子标题、状态、联系方式与本查询一致

-- =========================
-- 12) 约束与重复提交辅助核对
-- =========================

SELECT
  user_id,
  post_id,
  COUNT(*) AS favorite_rows
FROM favorites
GROUP BY user_id, post_id
HAVING COUNT(*) > 1;

SELECT
  post_id,
  applicant_user_id,
  SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_rows
FROM applications
GROUP BY post_id, applicant_user_id
HAVING SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) > 1;

-- 重点核对：
-- 上面两条查询在正常情况下都应返回 0 行
