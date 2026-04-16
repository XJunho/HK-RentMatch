let isLoggedIn = typeof window !== 'undefined' && typeof window.isLoggedIn !== 'undefined'
    ? window.isLoggedIn
    : false;
let currentUserId = typeof window !== 'undefined' && Number.isInteger(Number(window.currentUserId))
    ? Number(window.currentUserId)
    : 0;
let currentUserRole = typeof window !== 'undefined' && typeof window.currentUserRole === 'string'
    ? window.currentUserRole
    : '';
let favorites = getInitialFavorites();
let currentDetailPostId = null;
let currentDetailOwnerId = 0;

// ==================== Detail Slider State ====================
let sliderImages = [];
let sliderIndex = 0;

// ==================== Initialize ====================
document.addEventListener('DOMContentLoaded', function() {
    initTabNavigation();
    initSortSelect();
    restoreScrollPosition();
});

// ==================== Tab Navigation ====================
function initTabNavigation() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabInput = document.getElementById('currentTab');
            if (tabInput) tabInput.value = this.dataset.tab;
            const form = document.getElementById('filterForm');
            if (form) {
                saveScrollPosition();
                form.submit();
            }
        });
    });
}

// ==================== Modal Functions ====================
function openModal(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return;

    // 每次打开弹窗时重置表单，避免残留旧数据
    const form = el.querySelector('form');
    if (form) {
        form.reset();
        // 恢复注册弹窗身份卡片为默认选中状态
        if (modalId === 'registerModal') {
            const cards = form.querySelectorAll('.role-card');
            cards.forEach(c => c.classList.remove('selected'));
            if (cards.length > 0) cards[0].classList.add('selected');
            const schoolGroup = document.getElementById('schoolGroup');
            if (schoolGroup) schoolGroup.style.display = 'block';
            const roleInput = document.getElementById('registerRole');
            if (roleInput) roleInput.value = 'student';
        }
        // 清除错误提示
        el.querySelectorAll('.form-error').forEach(err => err.remove());
    }

    el.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return;
    el.classList.remove('active');
    document.body.style.overflow = '';
}

function switchModal(fromId, toId) {
    closeModal(fromId);
    setTimeout(() => openModal(toId), 200);
}

// ==================== Auth Functions ====================
function handleLogin(e) {
    e.preventDefault();

    const form = e.target;
    if (!form) return;

    const base = typeof window !== 'undefined' && typeof window.projectBaseUrl === 'string'
        ? window.projectBaseUrl
        : '';

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch(base + '/login.php?ajax=1', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new FormData(form),
    })
        .then(response => response.json())
        .then(data => {
            if (!data || data.success !== true) {
                const firstError = data && data.errors
                    ? Object.values(data.errors)[0]
                    : null;
                showToast(firstError || '邮箱或密码错误。', 'error');
                return;
            }

            closeModal('loginModal');
            form.reset();
            showToast('登录成功！', 'success');
            setTimeout(() => { window.location.reload(); }, 800);
        })
        .catch(() => {
            showToast('登录失败，请稍后重试。', 'error');
        })
        .finally(() => {
            if (submitBtn) submitBtn.disabled = false;
        });
}

function handleRegister(e) {
    e.preventDefault();

    const form = e.target;
    if (!form) return;

    const base = typeof window !== 'undefined' && typeof window.projectBaseUrl === 'string'
        ? window.projectBaseUrl
        : '';
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch(base + '/register.php?ajax=1', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new FormData(form),
    })
        .then(response => response.json())
        .then(data => {
            if (!data || data.success !== true) {
                const firstError = data && data.errors
                    ? Object.values(data.errors)[0]
                    : null;
                showToast(firstError || '注册失败，请检查输入信息。', 'error');
                return;
            }

            closeModal('registerModal');
            form.reset();
            showToast('注册成功！', 'success');
            setTimeout(() => { window.location.reload(); }, 800);
        })
        .catch(() => {
            showToast('注册失败，请稍后重试。', 'error');
        })
        .finally(() => {
            if (submitBtn) submitBtn.disabled = false;
        });
}

function logout() {
    isLoggedIn = false;
    const loggedOut = document.getElementById('loggedOutActions');
    const loggedIn = document.getElementById('loggedInActions');
    if (loggedOut) loggedOut.style.display = 'flex';
    if (loggedIn) loggedIn.style.display = 'none';
    showToast('已退出登录', 'success');
}

// ==================== Role Selection ====================
function selectRole(element, role) {
    document.querySelectorAll('.role-card').forEach(card => card.classList.remove('selected'));
    element.classList.add('selected');

    const schoolGroup = document.getElementById('schoolGroup');
    const roleInput = document.getElementById('registerRole');

    if (roleInput) {
        roleInput.value = role === 'landlord' ? 'landlord' : 'student';
    }

    if (!schoolGroup) return;
    if (role === 'student') {
        schoolGroup.style.display = 'block';
    } else {
        schoolGroup.style.display = 'none';
    }
}

function openPostModal() {
    document.querySelectorAll('#postModal .post-type-card').forEach(c => c.classList.remove('selected'));
    const btn = document.getElementById('goPublishBtn');
    if (btn) btn.classList.remove('enabled');
    openModal('postModal');
}

function getPublishTypeDenyMessage(type) {
    if (!isLoggedIn) return '';
    if (currentUserRole === 'admin') return '';
    if (currentUserRole === 'landlord') {
        return type === 'rent' ? '' : '房源供给方仅可发布租房类型。';
    }
    if (currentUserRole === 'student') {
        return type === 'rent' ? '港硕学生仅可发布找室友和转租类型。' : '';
    }
    return '当前账号角色无发布权限。';
}

function selectPostType(element, type) {
    const denyMessage = getPublishTypeDenyMessage(type);
    if (denyMessage) {
        showToast(denyMessage, 'error');
        return;
    }

    document.querySelectorAll('#postModal .post-type-card').forEach(card => card.classList.remove('selected'));
    element.classList.add('selected');
    element.dataset.postType = type;
    const btn = document.getElementById('goPublishBtn');
    if (btn) btn.classList.add('enabled');
}

function goToPublish() {
    const selected = document.querySelector('#postModal .post-type-card.selected');
    if (!selected) {
        showToast('请先选择发布类型。', 'error');
        return;
    }
    const selectedType = selected.dataset.postType || '';
    const denyMessage = getPublishTypeDenyMessage(selectedType);
    if (denyMessage) {
        showToast(denyMessage, 'error');
        return;
    }
    const base = typeof window.projectBaseUrl === 'string' ? window.projectBaseUrl : '';
    window.location.href = base + '/post/create.php';
}

// ==================== Favorite Functions ====================
function getInitialFavorites() {
    const initial = typeof window !== 'undefined' ? window.initialFavoritePostIds : [];
    if (!Array.isArray(initial)) return new Set();

    const ids = initial
        .map(v => Number(v))
        .filter(v => Number.isInteger(v) && v > 0);
    return new Set(ids);
}

function buildProjectUrl(path) {
    const base = typeof window !== 'undefined' && typeof window.projectBaseUrl === 'string'
        ? window.projectBaseUrl
        : '';
    return base + path;
}

function requestInteraction(action, payload) {
    const formData = new FormData();
    formData.append('action', action);

    Object.keys(payload).forEach(function(key) {
        formData.append(key, payload[key]);
    });

    return fetch(buildProjectUrl('/post/interact.php'), {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
    }).then(async function(response) {
        let data = null;
        try {
            data = await response.json();
        } catch (_) {
            data = null;
        }

        if (!data || data.success !== true) {
            const err = new Error(data && data.message ? data.message : '操作失败，请稍后重试。');
            err.payload = data;
            throw err;
        }

        return data;
    });
}

function applyFavoriteState(postId, favorited) {
    const id = Number(postId);
    if (!Number.isInteger(id) || id <= 0) return;

    if (favorited) {
        favorites.add(id);
    } else {
        favorites.delete(id);
    }

    syncCardFavoriteState(id, favorited);
    if (currentDetailPostId === id) {
        updateDetailFavoriteButton();
    }
}

function toggleFavorite(postId, button) {
    if (!isLoggedIn) {
        showToast('请先登录', 'error');
        openModal('loginModal');
        return;
    }

    const id = Number(postId);
    if (!Number.isInteger(id) || id <= 0 || !button) return;

    button.disabled = true;
    requestInteraction('toggle_favorite', { post_id: String(id) })
        .then(function(data) {
            const favorited = !!(data.data && data.data.favorited);
            applyFavoriteState(id, favorited);
            showToast(data.message || (favorited ? '收藏成功！' : '已取消收藏'), 'success');
        })
        .catch(function(error) {
            if (error.payload && error.payload.require_login) {
                isLoggedIn = false;
                showToast('请先登录', 'error');
                openModal('loginModal');
                return;
            }
            showToast(error.message || '收藏操作失败，请稍后重试。', 'error');
        })
        .finally(function() {
            button.disabled = false;
        });
}

function toggleFavoriteDetail(button) {
    if (!isLoggedIn) {
        showToast('请先登录', 'error');
        closeModal('detailModal');
        openModal('loginModal');
        return;
    }

    if (!currentDetailPostId) return;

    button.disabled = true;
    requestInteraction('toggle_favorite', { post_id: String(currentDetailPostId) })
        .then(function(data) {
            const favorited = !!(data.data && data.data.favorited);
            applyFavoriteState(currentDetailPostId, favorited);
            showToast(data.message || (favorited ? '收藏成功！' : '已取消收藏'), 'success');
        })
        .catch(function(error) {
            if (error.payload && error.payload.require_login) {
                isLoggedIn = false;
                closeModal('detailModal');
                showToast('请先登录', 'error');
                openModal('loginModal');
                return;
            }
            showToast(error.message || '收藏操作失败，请稍后重试。', 'error');
        })
        .finally(function() {
            button.disabled = false;
        });
}

// ==================== Detail Slider ====================
function initDetailSlider(images) {
    sliderImages = images.length > 0 ? images : [''];
    sliderIndex = 0;
    renderSlider();
}

function renderSlider() {
    const img = document.getElementById('detailSliderImage');
    const dots = document.getElementById('sliderDots');
    const prevBtn = document.getElementById('sliderPrevBtn');
    const nextBtn = document.getElementById('sliderNextBtn');
    if (!img) return;

    img.style.opacity = '0';
    setTimeout(function() {
        img.src = sliderImages[sliderIndex] || '';
        img.style.opacity = '1';
    }, 150);

    if (dots) {
        dots.innerHTML = '';
        if (sliderImages.length > 1) {
            sliderImages.forEach(function(_, i) {
                const dot = document.createElement('span');
                dot.className = 'slider-dot' + (i === sliderIndex ? ' active' : '');
                dot.onclick = function() { sliderIndex = i; renderSlider(); };
                dots.appendChild(dot);
            });
        }
    }

    if (prevBtn) prevBtn.classList.toggle('hidden', sliderImages.length <= 1 || sliderIndex === 0);
    if (nextBtn) nextBtn.classList.toggle('hidden', sliderImages.length <= 1 || sliderIndex === sliderImages.length - 1);
}

function detailSliderPrev() {
    if (sliderIndex > 0) { sliderIndex--; renderSlider(); }
}

function detailSliderNext() {
    if (sliderIndex < sliderImages.length - 1) { sliderIndex++; renderSlider(); }
}

// ==================== Post Detail ====================
function openPostDetail(postId) {
    if (!postId) return;
    const card = document.querySelector('.post-card[data-post-id="' + String(postId) + '"]');
    if (!card) {
        window.location.href = 'post/detail.php?id=' + encodeURIComponent(postId);
        return;
    }

    currentDetailPostId = Number(postId);
    fillDetailModalFromCard(card);
    updateDetailFavoriteButton();
    openModal('detailModal');
}

function fillDetailModalFromCard(card) {
    const data = card.dataset;
    const type = data.type || 'rent';
    const isSublet = type === 'sublet';
    currentDetailOwnerId = Number(data.ownerId || 0);

    setText('detailTitle', data.title || '-');
    setText('detailPrice', data.price || '0');
    setText('detailRegion', data.region || '-');
    setText('detailMetro', '🚇 ' + (data.metro || '-'));
    setText('detailSchool', data.school || '-');
    setText('detailFloor', data.floor || '-');
    setText('detailPeriod', data.period || '-');
    setText('detailRemainingMonths', data.remainingMonths || '-');
    setText('detailMoveInDate', data.moveInDate || '-');
    setText('detailRenewable', data.renewable || '-');
    setText('detailSchoolMeta', data.school || '-');
    setText('detailMetroMeta', data.metro || '-');
    setText('detailAuthorAvatar', data.authorInitial || '?');
    setText('detailAuthorName', data.author || '匿名用户');
    setText('detailAuthorRole', data.authorRole || '-');
    setText('detailContact', data.contact || '📞 登录后查看联系方式');

    const createdDate = data.createdDate ? ('发布于 ' + data.createdDate) : '';
    setText('detailCreatedDate', createdDate);

    const desc = document.getElementById('detailDesc');
    if (desc) desc.textContent = data.content || '暂无描述。';

    // 价格标签（租房 HKD/月，找室友类型显示月预算）
    setText('detailPriceLabel', data.priceLabel || 'HKD/月');

    // 徽章：根据 type 更换 class 和文案
    const badge = document.querySelector('#detailModal .modal-title-group .card-badge');
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

    // 图片轮播：无房找室友无图片，隐藏 slider 区域
    const slider = document.getElementById('detailSlider');
    if (slider) {
        slider.style.display = type === 'roommate-nosource' ? 'none' : '';
    }

    // 楼层：无房找室友隐藏
    const floorItem = document.getElementById('detailFloorItem');
    if (floorItem) {
        floorItem.style.display = type === 'roommate-nosource' ? 'none' : '';
    }

    // 租期：转租不显示
    const periodItem = document.getElementById('detailPeriodItem');
    if (periodItem) {
        periodItem.style.display = isSublet ? 'none' : '';
    }

    // 性别要求：找室友 + 转租显示
    const genderItem = document.getElementById('detailGenderReqItem');
    if (genderItem) {
        genderItem.style.display = (type === 'roommate-source' || type === 'roommate-nosource' || isSublet) ? '' : 'none';
    }
    setText('detailGenderReq', data.genderReq || '-');

    // 需求人数：仅有房找室友显示
    const needCountItem = document.getElementById('detailNeedCountItem');
    if (needCountItem) {
        needCountItem.style.display = type === 'roommate-source' ? '' : 'none';
    }
    setText('detailNeedCount', data.needCount || '-');

    // 转租专属：剩余租期 / 最早入住 / 是否可续租
    const remainingMonthsItem = document.getElementById('detailRemainingMonthsItem');
    if (remainingMonthsItem) {
        remainingMonthsItem.style.display = isSublet ? '' : 'none';
    }
    const moveInDateItem = document.getElementById('detailMoveInDateItem');
    if (moveInDateItem) {
        moveInDateItem.style.display = isSublet ? '' : 'none';
    }
    const renewableItem = document.getElementById('detailRenewableItem');
    if (renewableItem) {
        renewableItem.style.display = isSublet ? '' : 'none';
    }

    // 图片滑块：无房找室友不加载图片
    const imgs = [];
    if (type !== 'roommate-nosource') {
        if (data.imageMain) imgs.push(data.imageMain);
        if (data.imageThumb1 && data.imageThumb1 !== data.imageMain) imgs.push(data.imageThumb1);
        if (data.imageThumb2 && data.imageThumb2 !== data.imageThumb1 && data.imageThumb2 !== data.imageMain) imgs.push(data.imageThumb2);
    }
    initDetailSlider(imgs);
    updateDetailPrimaryButton();
}

function isDetailOwnerPost() {
    return !!isLoggedIn && currentUserId > 0 && currentDetailOwnerId > 0 && currentUserId === currentDetailOwnerId;
}

function updateDetailPrimaryButton() {
    const btn = document.getElementById('detailPrimaryBtn');
    if (!btn) return;
    btn.textContent = isDetailOwnerPost() ? '编辑' : '发送申请';
}

function openPostEditFromDetail() {
    if (!currentDetailPostId) return;
    window.location.href = buildProjectUrl('/profile.php') + '?section=posts&edit_post_id=' + encodeURIComponent(String(currentDetailPostId));
}

function openDetailPrimaryAction() {
    if (isDetailOwnerPost()) {
        closeModal('detailModal');
        openPostEditFromDetail();
        return;
    }
    openModal('applyModal');
}

function updateDetailFavoriteButton() {
    const button = document.getElementById('detailFavoriteBtn');
    if (!button || !currentDetailPostId) return;
    button.innerHTML = favorites.has(currentDetailPostId) ? '❤️ 已收藏' : '🤍 收藏';
}

function syncCardFavoriteState(postId, favorited) {
    const btn = document.querySelector(
        '.post-card[data-post-id="' + String(postId) + '"] .card-favorite'
    );
    if (!btn) return;
    btn.classList.toggle('active', !!favorited);
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
}

function setImage(id, src) {
    const el = document.getElementById(id);
    if (!el || !src) return;
    el.src = src;
}

// ==================== Apply Function ====================
function handleApply(e) {
    e.preventDefault();
    if (!isLoggedIn) {
        showToast('请先登录', 'error');
        closeModal('applyModal');
        closeModal('detailModal');
        openModal('loginModal');
        return;
    }

    if (!currentDetailPostId) {
        showToast('无法识别帖子，请刷新后重试。', 'error');
        return;
    }

    const form = e.target;
    if (!form) return;

    const messageInput = form.querySelector('textarea[name="message"]');
    const message = messageInput ? messageInput.value.trim() : '';
    if (message === '') {
        showToast('请填写申请理由。', 'error');
        return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    requestInteraction('send_application', {
        post_id: String(currentDetailPostId),
        message: message,
    })
        .then(function(data) {
            closeModal('applyModal');
            form.reset();
            showToast(data.message || '申请已发送！', 'success');
        })
        .catch(function(error) {
            if (error.payload && error.payload.require_login) {
                isLoggedIn = false;
                closeModal('applyModal');
                closeModal('detailModal');
                showToast('请先登录', 'error');
                openModal('loginModal');
                return;
            }
            showToast(error.message || '申请发送失败，请稍后重试。', 'error');
        })
        .finally(function() {
            if (submitBtn) submitBtn.disabled = false;
        });
}

// ==================== Filter Functions ====================
function applyFilters() {
    syncSortInput();
    const form = document.getElementById('filterForm');
    if (form) {
        saveScrollPosition();
        form.submit();
    }
}

function resetFilters() {
    const region = document.getElementById('filterRegion');
    const school = document.getElementById('filterSchool');
    const minPrice = document.getElementById('minPrice');
    const maxPrice = document.getElementById('maxPrice');
    const period = document.getElementById('filterPeriod');
    const source = document.getElementById('filterSource');
    const gender = document.getElementById('filterGender');
    const keyword = document.getElementById('searchKeyword');
    const sortSelect = document.getElementById('sortSelect');

    if (region) region.value = '';
    if (school) school.value = '';
    if (minPrice) minPrice.value = '';
    if (maxPrice) maxPrice.value = '';
    if (period) period.value = '';
    if (source) source.value = '';
    if (gender) gender.value = '';
    if (keyword) keyword.value = '';
    if (sortSelect) sortSelect.value = 'newest';

    syncSortInput();

    const form = document.getElementById('filterForm');
    if (form) {
        saveScrollPosition();
        form.submit();
    }
}

function syncSortInput() {
    const sortSelect = document.getElementById('sortSelect');
    const sortInput = document.getElementById('sortInput');
    if (!sortInput) return;
    sortInput.value = sortSelect ? sortSelect.value : 'newest';
}

function initSortSelect() {
    const sortSelect = document.getElementById('sortSelect');
    if (!sortSelect) return;

    syncSortInput();
    sortSelect.addEventListener('change', function() {
        syncSortInput();
        const form = document.getElementById('filterForm');
        if (form) {
            saveScrollPosition();
            form.submit();
        }
    });
}

function saveScrollPosition() {
    sessionStorage.setItem('filterScrollY', String(window.scrollY));
}

function restoreScrollPosition() {
    const saved = sessionStorage.getItem('filterScrollY');
    if (saved !== null) {
        sessionStorage.removeItem('filterScrollY');
        window.scrollTo({ top: parseInt(saved, 10), behavior: 'instant' });
    }
}

// ==================== Toast Function ====================
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'toast ' + type;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, 2500);
}

// ==================== Pagination ====================
document.querySelectorAll('.page-btn:not(:disabled)').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!this.classList.contains('active')) {
            document.querySelectorAll('.page-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            showToast('加载中...', 'success');
        }
    });
});

// ==================== Password Toggle (hold to show) ====================
document.querySelectorAll('.password-toggle').forEach(btn => {
    const input = btn.closest('.password-wrapper').querySelector('input');
    const show = () => { input.type = 'text'; };
    const hide = () => { input.type = 'password'; };
    btn.addEventListener('mousedown', (e) => { e.preventDefault(); show(); });
    btn.addEventListener('mouseup', hide);
    btn.addEventListener('mouseleave', hide);
    btn.addEventListener('touchstart', (e) => { e.preventDefault(); show(); }, { passive: false });
    btn.addEventListener('touchend', hide);
});

