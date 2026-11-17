<?php
declare(strict_types=1);

/** ĐẦU MỐI GIAO DIỆN: nạp các tab và chọn hàm hiển thị theo page. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/giao_dien/khung_trang.php';
require_once __DIR__ . '/giao_dien/thanh_phan_chung.php';
require_once __DIR__ . '/giao_dien/tai_khoan.php';
require_once __DIR__ . '/giao_dien/tong_quan.php';
require_once __DIR__ . '/giao_dien/don_hang.php';
require_once __DIR__ . '/giao_dien/don_hang/soan_don.php';
require_once __DIR__ . '/giao_dien/don_hang/chi_tiet.php';
require_once __DIR__ . '/giao_dien/don_hang/thao_tac.php';
require_once __DIR__ . '/giao_dien/khach_hang.php';
require_once __DIR__ . '/giao_dien/san_pham.php';
require_once __DIR__ . '/giao_dien/bao_cao.php';
require_once __DIR__ . '/giao_dien/nhan_vien.php';

/** Chọn hàm dựng màn hình theo page trên URL. */
function render_page(string $page): void {
    match ($page) {
        'cua_hang' => cua_hang_view(),
        'gio_hang' => gio_hang_view(),
        'don_cua_toi' => don_cua_toi_view(),
        'don_khach' => don_khach_view(),
        'login' => login_view(),
        'dashboard' => dashboard_view(),
        'customers' => customers_view(),
        'products' => products_view(),
        'users' => users_view(),
        'orders' => orders_view(),
        'order_form' => order_form_view(),
        'order', 'print' => order_view($page === 'print'),
        'reports' => reports_view(),
        'profile' => profile_view(),
        default => dashboard_view(),
    };
}
