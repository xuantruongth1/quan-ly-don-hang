<?php
declare(strict_types=1);

/** ĐẦU MỐI NÚT POST: kiểm CSRF/quyền, đọc action rồi gọi hàm xử lý theo nhóm. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/xu_ly/form_don.php';
require_once __DIR__ . '/xu_ly/luu_don.php';
require_once __DIR__ . '/xu_ly/trang_thai_don.php';
require_once __DIR__ . '/xu_ly/bao_cao.php';
require_once __DIR__ . '/xu_ly/bo_loc_don.php';
require_once __DIR__ . '/xu_ly/san_pham.php';
require_once __DIR__ . '/xu_ly/khach_hang.php';
require_once __DIR__ . '/xu_ly/tai_khoan.php';
require_once __DIR__ . '/xu_ly/mua_sach.php';

/**
 * [TRAM_XU_LY_NUT] index.php gọi hàm này khi trình duyệt gửi POST.
 * $_POST['action'] quyết định nhánh. Ví dụ save_order -> save_order(), status -> change_status().
 * Trước mọi nhánh kiểm CSRF; sau đăng nhập kiểm người dùng và quyền ở nghiệp vụ tương ứng.
 * Thành công lưu thường redirect 303; nút trợ giúp chỉ dựng lại form, chưa ghi đơn.
 */

function handle_post(): void {
    check_csrf();
    // [NUT_CHON_KHACH] Nút ở từng kết quả gửi mã khách; điền hồ sơ vào form, chưa lưu đơn.
    if (isset($_POST['choose_customer'])) {
        $_POST['customer_id'] = (string) whole_number(
            $_POST['choose_customer'],
            1,
            2147483647,
            'Khách cần chọn',
        );
        $_POST['action'] = 'use_customer';
    }
    $action = text_value($_POST, 'action', 40);
    // [NUT_DANG_NHAP] SELECT users, password_verify, đổi session ID, chuyển tới tổng quan.
    if ($action === 'login') {
        xu_ly_dang_nhap();
        return;
    }
    $user = require_login();
    // [PHAN_QUYEN_NUT] Khách không thể gửi POST giả vào các nút quản trị.
    if ($user['role'] === 'customer' && !in_array($action, ['logout', 'password'], true)) {
        xu_ly_nut_khach($action);
    }
    // [NUT_TRO_GIUP_FORM] Các nút này chỉ dựng lại form; chưa INSERT/UPDATE đơn.
    if (
        in_array(
            $action,
            [
                'add_line',
                'remove_line',
                'use_customer',
                'preview_order',
                'apply_delivery',
                'search_customer',
                'customer_next',
                'customer_previous',
            ],
            true,
        )
    ) {
        xu_ly_form_soan_don($action);
        return;
    }
    // [NUT_DANG_XUAT] Xóa dữ liệu session và chuyển về đăng nhập.
    if ($action === 'logout') {
        xu_ly_dang_xuat();
        return;
    }
    // [NUT_DOI_MAT_KHAU] Kiểm tra mật khẩu cũ, băm mật khẩu mới rồi UPDATE users.
    if ($action === 'password') {
        xu_ly_doi_mat_khau($user);
        return;
    }
    // [NUT_LUU_KHACH] Kiểm tra điện thoại/email/version, INSERT hoặc UPDATE customers.
    if ($action === 'save_customer') {
        xu_ly_luu_khach();
        return;
    }
    // [NUT_LUU_SAN_PHAM] Chỉ admin; kiểm tra tồn/version/ảnh trước UPDATE products.
    if ($action === 'save_product') {
        xu_ly_luu_san_pham();
        return;
    }
    // [NUT_LUU_NHAN_VIEN] Chỉ admin; tạo/sửa/khóa staff trong users, không sửa admin.
    if ($action === 'save_user') {
        xu_ly_luu_nhan_vien();
        return;
    }
    // [NUT_LUU_DON] Gọi save_order(); thành công chuyển qua chi tiết bằng mã id.
    if ($action === 'save_order') {
        xu_ly_luu_don($user);
        return;
    }
    // [NUT_TRANG_THAI] Gọi change_status(); chỉ chuyển theo luồng và giữ phiên bản đúng.
    if ($action === 'status') {
        xu_ly_doi_trang_thai($user);
        return;
    }
    // [NUT_THU_TIEN] Kiểm tra xác nhận thực nhận, gọi mark_paid(), lưu lịch sử.
    if ($action === 'paid') {
        xu_ly_thu_tien($user);
        return;
    }
    if (in_array($action, ['save_delivery', 'receive_return'], true)) {
        xu_ly_giao_nhan($action, $user);
        return;
    }
    throw new DomainException('Thao tác không hợp lệ.');
}
