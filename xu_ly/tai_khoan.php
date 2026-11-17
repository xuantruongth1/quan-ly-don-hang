<?php
declare(strict_types=1);

/** TÀI KHOẢN: đăng nhập, đăng xuất, mật khẩu và nhân viên. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Đăng nhập: kiểm mật khẩu, tạo phiên và mở đúng giao diện. */
function xu_ly_dang_nhap(): void {
    $username = text_value($_POST, 'username', 50);
    $password = text_value($_POST, 'password', 200);
    $limit = $_SESSION['login_limit'] ?? ['attempts' => 0, 'until' => 0];
    if ($limit['until'] > time()) {
        throw new DomainException('Đã thử quá nhiều lần. Vui lòng chờ 60 giây.');
    }
    $user = one('SELECT * FROM users WHERE username=?', [$username]);
    // Luôn chạy password_verify, kể cả khi tài khoản không tồn tại.
    $hash =
        $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid = password_verify($password, $hash);
    if (!$valid || !$user || !$user['is_active']) {
        $attempts = $limit['attempts'] + 1;
        $_SESSION['login_limit'] = [
            'attempts' => $attempts,
            'until' => $attempts >= 5 ? time() + 60 : 0,
        ];
        throw new DomainException(
            'Tên đăng nhập hoặc mật khẩu không đúng, hoặc tài khoản đã khóa.',
        );
    }
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => $user['user_id'],
        'last_active' => time(),
        'csrf' => bin2hex(random_bytes(32)),
    ];
    redirect(trang_mac_dinh($user));
}

/** Đăng xuất: xóa phiên và về đăng nhập. */
function xu_ly_dang_xuat(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('login');
}

/** Đổi mật khẩu của tài khoản hiện tại. */
function xu_ly_doi_mat_khau(array $user): void {
    $old = text_value($_POST, 'old_password', 200);
    $new = text_value($_POST, 'new_password', 200);
    if (!password_verify($old, $user['password_hash'])) {
        throw new DomainException('Mật khẩu hiện tại không đúng.');
    }
    if (strlen($new) < 8 || strlen($new) > 72) {
        throw new DomainException('Mật khẩu mới cần từ 8 đến 72 byte.');
    }
    if ($new !== ($_POST['confirm_password'] ?? '')) {
        throw new DomainException('Xác nhận mật khẩu không khớp.');
    }
    query('UPDATE users SET password_hash=? WHERE user_id=?', [
        password_hash($new, PASSWORD_DEFAULT),
        $user['user_id'],
    ]);
    session_regenerate_id(true);
    flash('Đã đổi mật khẩu.');
    redirect('profile');
}

/** Nút Lưu nhân viên: chỉ quản trị; tạo/sửa/khóa staff. */
function xu_ly_luu_nhan_vien(): void {
    require_admin();
    $id = whole_number($_POST['id'] ?? '0', 0, 2147483647, 'Mã nhân viên');
    $username = text_value($_POST, 'username', 50);
    $name = text_value($_POST, 'full_name', 100);
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username)) {
        throw new DomainException(
            'Tên đăng nhập cần 3–50 ký tự chữ không dấu, số, dấu chấm, gạch dưới hoặc gạch ngang.',
        );
    }
    $email = email_value($_POST);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $password = text_value($_POST, 'password', 200, false);
    if ((!$id || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) {
        throw new DomainException('Mật khẩu cần từ 8 đến 72 byte.');
    }
    if ($id) {
        $staff = one("SELECT * FROM users WHERE user_id=? AND role='staff'", [$id]);
        if (!$staff) {
            throw new DomainException('Chỉ sửa được tài khoản nhân viên.');
        }
        query('UPDATE users SET username=?,full_name=?,email=?,is_active=? WHERE user_id=?', [
            $username,
            $name,
            $email,
            $active,
            $id,
        ]);
        if ($password !== '') {
            query('UPDATE users SET password_hash=? WHERE user_id=?', [
                password_hash($password, PASSWORD_DEFAULT),
                $id,
            ]);
        }
    } else {
        query(
            'INSERT INTO users(username,password_hash,full_name,email,role,is_active) ' .
                "VALUES(?,?,?,?,'staff',?)",
            [$username, password_hash($password, PASSWORD_DEFAULT), $name, $email, $active],
        );
    }
    flash('Đã lưu tài khoản nhân viên.');
    redirect('users');
}
