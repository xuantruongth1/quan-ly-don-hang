<?php
declare(strict_types=1);

/**
 * [DIEM_VAO] Mọi yêu cầu trình duyệt đều đi qua file này.
 * GET ?page=orders → chọn màn hình trong giao_dien.php.
 * POST action=save_order → handle_post() trong xu_ly.php → save_order().
 * Sau khi lưu thành công dùng redirect 303 để F5 không gửi lại biểu mẫu.
 */
require __DIR__ . '/../ham_chung.php';
require __DIR__ . '/../xu_ly.php';
require __DIR__ . '/../giao_dien.php';
require __DIR__ . '/../khach_hang.php';

// 1. GET page chọn màn hình. Ví dụ nhấn + Tạo đơn: page=order_form, chưa lưu gì.
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
$titles = [
    'cua_hang' => 'Nhà sách',
    'gio_hang' => 'Giỏ hàng',
    'don_cua_toi' => 'Đơn của tôi',
    'don_khach' => 'Chi tiết đơn của tôi',
    'login' => 'Đăng nhập',
    'dashboard' => 'Tổng quan',
    'orders' => 'Đơn hàng',
    'order_form' => 'Soạn đơn hàng',
    'order' => 'Chi tiết đơn hàng',
    'print' => 'Phiếu đơn hàng',
    'customers' => 'Khách hàng',
    'products' => 'Sản phẩm',
    'users' => 'Nhân viên',
    'reports' => 'Báo cáo',
    'report_csv' => 'Xuất báo cáo',
    'profile' => 'Đổi mật khẩu',
    'export' => 'Xuất CSV',
    'product_image' => 'Ảnh sản phẩm',
];

try {
    if (!isset($titles[$page])) {
        http_response_code(404);
        throw new DomainException('Trang không tồn tại.');
    }
    if ($page !== 'login') {
        require_login();
    } elseif ($signedIn = current_user()) {
        redirect(trang_mac_dinh($signedIn));
    }
    // [PHAN_QUYEN_TRANG] Chặn trên máy chủ cả khi tự gõ URL quản trị/CSV.
    $customerPages = ['cua_hang', 'gio_hang', 'don_cua_toi', 'don_khach'];
    if ($page !== 'login') {
        if (la_khach() && $page === 'dashboard' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('cua_hang');
        }
        if (la_khach() && !in_array($page, [...$customerPages, 'profile', 'product_image'], true)) {
            http_response_code(403);
            throw new DomainException('Khách hàng không có quyền truy cập trang quản trị.');
        }
        if (!la_khach() && in_array($page, $customerPages, true)) {
            http_response_code(403);
            throw new DomainException('Hãy đăng nhập tài khoản khách để mở cửa hàng.');
        }
    }
    if ($page === 'users' && !is_admin()) {
        http_response_code(403);
        $page = 'dashboard';
        $GLOBALS['form_error'] = 'Bạn không có quyền quản lý nhân viên.';
    }

    // Tệp tải về phải gửi header trước khi xuất HTML; không dùng giao diện cho CSV/ảnh.
    if ($page === 'export') {
        xuat_csv();
    }
    if ($page === 'report_csv') {
        xuat_bao_cao_csv();
    }
    if ($page === 'product_image') {
        hien_anh();
    }

    // 2. Nút submit gửi POST: handle_post đọc action để gọi đúng nghiệp vụ.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // [PHAN_LUONG_NUT] action là giá trị của nút submit, không phải tên file PHP tùy ý.
            handle_post();
        } catch (DomainException | PDOException $error) {
            if ($error instanceof PDOException && (string) $error->getCode() !== '23000') {
                throw $error;
            }
            http_response_code(422);
            $GLOBALS['form_error'] =
                $error instanceof PDOException
                    ? 'Mã sản phẩm, tài khoản hoặc email nhân viên đã tồn tại. Kiểm tra lại dữ liệu.'
                    : $error->getMessage();
            $GLOBALS['error_field'] = $error instanceof LoiNhapLieu ? $error->field : '';
            $GLOBALS['old_input'] = $_POST;
            // Không giữ hoặc in lại mật khẩu khi nhập sai. Tệp ảnh cần chọn lại theo quy tắc trình duyệt.
            foreach (
                ['password', 'old_password', 'new_password', 'confirm_password', 'csrf']
                as $secret
            ) {
                unset($GLOBALS['old_input'][$secret]);
            }
            if (!$GLOBALS['error_field']) {
                foreach (
                    [
                        'Giảm giá' => 'discount_amount',
                        'Phí giao' => 'shipping_fee',
                        'Số lượng' => 'quantity',
                        'thực nhận' => 'cod_received_amount',
                        'SKU' => 'sku',
                    ]
                    as $phrase => $field
                ) {
                    if (str_contains($GLOBALS['form_error'], $phrase)) {
                        $GLOBALS['error_field'] = $field;
                    }
                }
            }
        }
    }

    // Dựng nội dung vào bộ đệm: lỗi truy vấn không làm xuất dở HTML.
    ob_start();
    // 3. GET hoặc POST trợ giúp: chọn hàm giao diện. Nội dung ghi thành công thường đã redirect trước đó.
    render_page($page);
    $content = ob_get_clean();
    header_view($titles[$page], $page);
    if ($message = $GLOBALS['form_error'] ?? null) {
        echo '<div class="alert error" role="alert">' . e($message) . '</div>';
        if (!empty($_POST)) {
            echo '<p class="hint">Dữ liệu vừa nhập được giữ lại. Sửa trường báo lỗi rồi lưu; ' .
                'nếu thông báo phiên bản cũ, mở lại trang để lấy dữ liệu mới.</p>';
        }
    }
    echo $content;
    footer_view();
} catch (Throwable $error) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    if ($error instanceof DomainException && http_response_code() < 400) {
        http_response_code(422);
    }
    if (!($error instanceof DomainException)) {
        error_log((string) $error);
        http_response_code(500);
    }
    $message =
        $error instanceof DomainException
            ? $error->getMessage()
            : 'Không xử lý được yêu cầu. Kiểm tra cấu hình và kết nối cơ sở dữ liệu.';
    ?>
<!doctype html>
<html lang="vi">
    <head>
        <meta charset="utf-8" />
        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        />
        <title>Thông báo</title>
        <link
            rel="stylesheet"
            href="giao_dien.css"
        />
    </head>
    <body>
        <main class="error-main">
            <section class="card">
                <h1>Chưa thể hoàn tất</h1>
                <p role="alert"><?= e($message) ?></p>
                <a
                    class="button"
                    href="index.php"
                >
                    Về tổng quan
                </a>
            </section>
        </main>
    </body>
</html>
<?php
}

