<?php
declare(strict_types=1);

/** PHÍA KHÁCH: quyền sở hữu đơn, giỏ session, đặt hàng và hủy đơn của mình. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

function la_khach(): bool {
    return (current_user()['role'] ?? '') === 'customer';
}

function trang_mac_dinh(array $user): string {
    return $user['role'] === 'customer' ? 'cua_hang' : 'dashboard';
}

/** Lấy hồ sơ bằng tài khoản đăng nhập, không nhận mã khách do trình duyệt gửi. */
function ho_so_khach(): array {
    $user = require_login();
    if ($user['role'] !== 'customer') {
        throw new DomainException('Trang này dành cho khách hàng.');
    }
    $customer = one('SELECT * FROM customers WHERE customer_id=?', [$user['customer_id'] ?? 0]);
    if (!$customer) {
        throw new DomainException('Tài khoản chưa được liên kết hồ sơ khách hàng.');
    }
    return $customer;
}

function don_cua_khach(int $id): array {
    $customer = ho_so_khach();
    $order = one('SELECT * FROM orders WHERE order_id=? AND customer_id=?', [
        $id,
        $customer['customer_id'],
    ]);
    if (!$order) {
        http_response_code(404);
        throw new DomainException('Không tìm thấy đơn hàng của bạn.');
    }
    return $order;
}

/** [NUT_THEM_GIO] POST → kiểm tra sách/số lượng → lưu giỏ vào session → chuyển trang. */
function xu_ly_nut_khach(string $action): never {
    $customer = ho_so_khach();
    if ($action === 'them_gio') {
        $id = whole_number($_POST['product_id'] ?? '', 1, 2147483647, 'Mã sách');
        $quantity = whole_number($_POST['quantity'] ?? '1', 1, 100, 'Số lượng');
        $book = one('SELECT * FROM products WHERE product_id=? AND is_active=1', [$id]);
        $newQuantity = (int) ($_SESSION['gio_hang'][$id] ?? 0) + $quantity;
        if (!$book || $newQuantity > min(100, (int) $book['stock_quantity'])) {
            throw new DomainException('Sách không còn bán hoặc số lượng vượt lượng còn bán.');
        }
        if (!isset($_SESSION['gio_hang'][$id]) && count($_SESSION['gio_hang'] ?? []) >= 50) {
            throw new DomainException('Giỏ hàng tối đa 50 loại sách.');
        }
        $_SESSION['gio_hang'][$id] = $newQuantity;
        flash('Đã thêm sách vào giỏ.');
        redirect('cua_hang');
    }
    if ($action === 'cap_nhat_gio') {
        $quantities = $_POST['quantity'] ?? [];
        if (!is_array($quantities)) {
            throw new DomainException('Giỏ hàng không hợp lệ.');
        }
        $cart = $_SESSION['gio_hang'] ?? [];
        foreach ($cart as $id => $old) {
            $quantity = whole_number($quantities[$id] ?? $old, 0, 100, 'Số lượng');
            if ($quantity === 0) {
                unset($cart[$id]);
                continue;
            }
            $book = one('SELECT * FROM products WHERE product_id=? AND is_active=1', [$id]);
            if (!$book || $quantity > (int) $book['stock_quantity']) {
                throw new DomainException(
                    'Có sách đã ngừng bán hoặc không đủ số lượng. Nhập 0 để bỏ sách đó.',
                );
            }
            $cart[$id] = $quantity;
        }
        $_SESSION['gio_hang'] = $cart;
        flash('Đã cập nhật giỏ hàng.');
        redirect('gio_hang');
    }
    // [NUT_DAT_HANG] Chỉ lấy mã khách từ session; giá, phí và mã đơn do máy chủ tính.
    if ($action === 'dat_hang') {
        $cart = $_SESSION['gio_hang'] ?? [];
        if (!$cart) {
            throw new DomainException('Giỏ hàng đang trống.');
        }
        $delivery = text_value($_POST, 'delivery_method', 20);
        if (!in_array($delivery, ['delivery', 'pickup'], true)) {
            throw new DomainException('Hình thức nhận không hợp lệ.');
        }
        // Dùng lại save_order để đơn từ khách và đơn nhân viên lập có cùng quy tắc.
        // Kiểm tra tồn trong cùng transaction/khóa sản phẩm của save_order ở phía dưới.
        $id = save_order(
            [
                'form_token' => text_value($_POST, 'form_token', 64),
                'customer_id' => $customer['customer_id'],
                'recipient_name' => text_value($_POST, 'recipient_name', 100),
                'recipient_phone' => phone_value($_POST, 'recipient_phone'),
                'shipping_address' => text_value(
                    $_POST,
                    'shipping_address',
                    255,
                    $delivery === 'delivery',
                ),
                'delivery_method' => $delivery,
                'payment_method' => $delivery === 'pickup' ? 'cash' : 'cod',
                'shipping_fee' => $delivery === 'pickup' ? '0' : '30000',
                'discount_amount' => '0',
                'note' => text_value($_POST, 'note', 3000, false),
                'product_id' => array_keys($cart),
                'quantity' => array_values($cart),
            ],
            (int) require_login()['user_id'],
        );
        unset($_SESSION['gio_hang']);
        flash('Đặt hàng thành công. Cửa hàng sẽ kiểm tra và xác nhận đơn.');
        redirect('don_khach', ['id' => $id]);
    }
    // [NUT_HUY_DON_KHACH] Chỉ hủy đơn của mình, còn chờ xác nhận và chưa thanh toán.
    if ($action === 'huy_don_khach') {
        $id = whole_number($_POST['id'] ?? '', 1, 2147483647, 'Mã đơn');
        transaction(function () use ($id, $customer): void {
            $order = one('SELECT * FROM orders WHERE order_id=? AND customer_id=? FOR UPDATE', [
                $id,
                $customer['customer_id'],
            ]);
            if (
                !$order ||
                $order['status'] !== 'pending' ||
                $order['payment_status'] !== 'unpaid'
            ) {
                throw new DomainException(
                    'Chỉ hủy được đơn của bạn đang chờ xác nhận và chưa thanh toán.',
                );
            }
            if (
                (int) $order['version'] !==
                whole_number($_POST['version'] ?? '', 1, 2147483647, 'Phiên bản')
            ) {
                throw new DomainException('Đơn đã thay đổi. Hãy tải lại trang.');
            }
            query("UPDATE orders SET status='cancelled',version=version+1 WHERE order_id=?", [$id]);
            query(
                'INSERT INTO order_status_history(order_id,from_status,to_status,' .
                    'changed_by,reason) VALUES(?,?,?,?,?)',
                [
                    $id,
                    'pending',
                    'cancelled',
                    (int) require_login()['user_id'],
                    'Khách hàng yêu cầu hủy đơn',
                ],
            );
        });
        flash('Đã hủy đơn hàng.');
        redirect('don_khach', ['id' => $id]);
    }
    throw new DomainException('Khách hàng không có quyền thực hiện thao tác này.');
}
