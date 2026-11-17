<?php
declare(strict_types=1);

/** Giao diện đăng nhập và đổi mật khẩu; xử lý tại xu_ly/tai_khoan.php. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_DANG_NHAP] Nút Đăng nhập gửi action=login. */
function login_view(): void {
    ?>

<section class="login-card">
    <div class="brand-icon">ĐH</div>
    <p class="eyebrow">QUẢN LÝ CỬA HÀNG</p>
    <h1>Chào mừng đến với nhà sách.</h1>
    <p class="muted">Đăng nhập để mua sách hoặc quản lý cửa hàng.</p>
    <form
        method="post"
        class="stack"
    >
        <?php csrf_field(); ?>
        <input
            type="hidden"
            name="action"
            value="login"
        />
        <?php field(
            'username',
            'Tên đăng nhập',
            '',
            'text',
            true,
            50,
        ); ?>
        <label>
            Mật khẩu
            <input
                type="password"
                name="password"
                required
                maxlength="200"
                autocomplete="current-password"
            />
        </label>
        <!-- [NUT_DANG_NHAP] POST login: password_verify rồi tạo phiên. -->
        <button>Đăng nhập →</button>
    </form>
    <p class="hint">Tài khoản quản trị vào trang quản lý; tài khoản khách vào trang mua sách.</p>
</section>
<?php
}

/** [MAN_HINH_MAT_KHAU] Mật khẩu không được điền lại khi có lỗi. */
function profile_view(): void {
    page_heading('Đổi mật khẩu', 'Bảo vệ tài khoản của bạn.'); ?>
<section class="card narrow">
    <form
        method="post"
        class="stack"
    >
        <?php csrf_field(); ?>
        <input
            type="hidden"
            name="action"
            value="password"
        />
        <label>
            Mật khẩu hiện tại
            <input
                type="password"
                name="old_password"
                required
                autocomplete="current-password"
                maxlength="200"
            />
        </label>
        <label>
            Mật khẩu mới
            <input
                type="password"
                name="new_password"
                required
                autocomplete="new-password"
                minlength="8"
                maxlength="72"
            />
        </label>
        <label>
            Nhập lại mật khẩu mới
            <input
                type="password"
                name="confirm_password"
                required
                autocomplete="new-password"
                maxlength="72"
            />
        </label>
        <p class="hint">Mật khẩu từ 8 đến 72 byte; nên dùng chữ, số và ký tự đặc biệt.</p>
        <!-- [NUT_DOI_MAT_KHAU] POST password: kiểm tra mật khẩu cũ và băm mật khẩu mới. -->
        <button>Lưu mật khẩu mới</button>
    </form>
</section>
<?php
}

