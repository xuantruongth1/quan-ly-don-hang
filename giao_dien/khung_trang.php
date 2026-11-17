<?php
declare(strict_types=1);

/** Khung trang: đầu trang, menu, tài khoản và thông báo. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Khung chung: tiêu đề, menu và thông tin đăng nhập. */
function header_view(string $title, string $page): void {
    $user = current_user(); ?>
<!doctype html>
<html lang="vi">
    <head>
        <meta charset="utf-8" />
        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        />
        <title><?= e($title) ?> · Đơn hàng</title>
        <link
            rel="stylesheet"
            href="giao_dien.css?v=<?= (int) filemtime(PROJECT_ROOT . '/public/giao_dien.css') ?>"
        />
    </head>
    <body class="<?= la_khach() ? 'customer-site' : 'admin-site' ?>">
        <?php if ($user): ?>
        <aside class="sidebar">
            <a
                class="brand"
                href="<?= e(url(trang_mac_dinh($user))) ?>"
            >
                <span class="brand-icon">S</span>
                <span>
                    Nhà sách
                    <small><?= la_khach() ? 'GÓC CỦA BẠN' : 'QUẢN LÝ CỬA HÀNG' ?></small>
                </span>
            </a>
            <p class="nav-label"><?= la_khach() ? 'KHÁM PHÁ & MUA SÁCH' : 'KHÔNG GIAN LÀM VIỆC' ?></p>
            <nav>
                <?php
                $menu = la_khach()
                    ? [
                        'cua_hang' => 'Chọn sách',
                        'gio_hang' => 'Giỏ hàng (' . array_sum($_SESSION['gio_hang'] ?? []) . ')',
                        'don_cua_toi' => 'Đơn của tôi',
                    ]
                    : [
                        'dashboard' => 'Tổng quan',
                        'orders' => 'Đơn hàng',
                        'customers' => 'Khách hàng',
                        'products' => 'Sản phẩm',
                        'reports' => 'Báo cáo',
                    ];
                foreach ($menu as $key => $label): ?>
                <a
                    class="<?= $page === $key ||
                ($key === 'orders' && in_array($page, ['order', 'order_form', 'print'], true))
                    ? 'active'
                    : '' ?>"
                    href="<?= e(url($key)) ?>"
                >
                    <?= e($label) ?>
                </a>
                <?php endforeach;
                                ?>
                <?php if (is_admin()): ?>
                <a
                    class="<?= $page === 'users' ? 'active' : '' ?>"
                    href="<?= e(url('users')) ?>"
                >
                    Nhân viên
                </a>
                <?php endif; ?>
            </nav>
            <div class="sidebar-bottom">
                <strong><?= e($user['full_name']) ?></strong>
                <small><?= la_khach() ? 'Khách hàng' : (is_admin() ? 'Quản trị viên' : 'Nhân viên') ?></small>
                <a href="<?= e(url('profile')) ?>">Đổi mật khẩu</a>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input
                        type="hidden"
                        name="action"
                        value="logout"
                    />
                    <button class="logout">Đăng xuất ↗</button>
                </form>
            </div>
        </aside>
        <main class="main">
            <header class="topbar">
                <span>Cửa hàng / <?= e($title) ?></span>
                <span><?= date('d/m/Y') ?></span>
            </header>
            <?php else: ?>
            <main class="login-main">
                <?php endif; ?>
                <?php if ($flash = $_SESSION['flash'] ?? null):
                unset($_SESSION['flash']); ?>
                <div
                    class="alert <?= e($flash[1]) ?>"
                    role="status"
                >
                    <?= e($flash[0]) ?>
                </div>
                <?php
                endif; ?>
                <?php
                }

                /** Đóng thẻ HTML của trang. */
                function footer_view(): void {
                    echo '</main></body></html>';
                }

