<?php
declare(strict_types=1);

/** TẠO / SỬA ĐƠN: kiểm dữ liệu, giá, transaction, orders, order_items và lịch sử. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/**
 * [GIAI_THICH_LUU_DON] Nút Lưu đơn, không phải nút mở form Tạo đơn.
 * Nhận: $data là dữ liệu POST; $actor là ID người đang đăng nhập.
 * Làm: kiểm tra -> tính lại tiền -> lưu đầu đơn + dòng + lịch sử trong transaction.
 * Bảng: orders, order_items, order_status_history; đọc customers/products/users.
 * Trả: ID đơn để handle_post chuyển đến trang chi tiết. CHƯA trừ lượng còn bán.
 */

function save_order(array $data, int $actor): int {
    // BƯỚC 1: đọc và kiểm tra dữ liệu. Dữ liệu từ trình duyệt luôn phải kiểm lại.
    $token = text_value($data, 'form_token', 64);
    check_form_token($token);
    $id = whole_number($data['order_id'] ?? '0', 0, 2147483647, 'Mã đơn');
    $customer = whole_number($data['customer_id'] ?? '', 1, 2147483647, 'Khách hàng');
    $recipient = text_value($data, 'recipient_name', 100);
    $phone = phone_value($data, 'recipient_phone');
    [$delivery, $carrier, $tracking, $expected] = delivery_input($data);
    $address = text_value($data, 'shipping_address', 255, $delivery === 'delivery');
    $note = text_value($data, 'note', 3000, false);
    // Ghi chú giao hàng: được phép in.
    $internal = text_value($data, 'internal_note', 3000, false);
    // Ghi chú nội bộ: tuyệt đối không in ra phiếu khách.
    $assigned = whole_number($data['assigned_to'] ?? '0', 0, 2147483647, 'Người phụ trách');
    $discount = money_input($data['discount_amount'] ?? '0', 'Giảm giá');
    $shipping = money_input($data['shipping_fee'] ?? '0', 'Phí giao hàng');
    if ($delivery === 'pickup' && $shipping !== 0) {
        throw new DomainException('Nhận tại cửa hàng phải có phí giao hàng bằng 0.');
    }
    $method = text_value($data, 'payment_method', 20);
    if (!isset(PAYMENT_LABELS[$method])) {
        throw new DomainException('Phương thức thanh toán không hợp lệ.');
    }
    if ($delivery === 'pickup' && $method === 'cod') {
        throw new DomainException(
            'Nhận tại cửa hàng dùng tiền mặt hoặc chuyển khoản, không dùng thu hộ khi giao hàng.',
        );
    }
    $ids = $data['product_id'] ?? [];
    $quantities = $data['quantity'] ?? [];
    if (!is_array($ids) || !is_array($quantities) || count($ids) > 50) {
        throw new DomainException('Đơn có tối đa 50 dòng sản phẩm.');
    }
    // BƯỚC 2: gom các dòng đã chọn, bỏ dòng trống và từ chối sản phẩm trùng.
    $requested = [];
    foreach ($ids as $key => $value) {
        if ($value === '') {
            continue;
        }
        $product = whole_number($value, 1, 2147483647, 'Sản phẩm');
        if (isset($requested[$product])) {
            throw new DomainException('Một sản phẩm chỉ được chọn một dòng.');
        }
        $requested[$product] = whole_number($quantities[$key] ?? '', 1, 100000, 'Số lượng');
    }
    if (!$requested) {
        throw new DomainException('Đơn hàng phải có ít nhất một sản phẩm.');
    }
    ksort($requested);
    // BƯỚC 3: tất cả bước ghi cùng thành công hoặc cùng rollback khi lỗi.
    $result = transaction(function () use (
        $data,
        $id,
        $customer,
        $recipient,
        $phone,
        $address,
        $note,
        $internal,
        $assigned,
        $discount,
        $shipping,
        $method,
        $requested,
        $actor,
        $delivery,
        $carrier,
        $tracking,
        $expected,
    ) {
        // Nếu sửa: khóa đơn pending/unpaid, so version và giữ giá/tên/SKU dòng cũ.
        $old = null;
        $oldItems = [];
        if ($id) {
            $old = one('SELECT * FROM orders WHERE order_id=? FOR UPDATE', [$id]);
            if (!$old || $old['status'] !== 'pending' || $old['payment_status'] === 'paid') {
                throw new DomainException('Chỉ sửa được đơn chờ xác nhận và chưa thu tiền.');
            }
            if ((int) $old['version'] !== (int) ($data['version'] ?? 0)) {
                throw new DomainException('Đơn đã được người khác cập nhật. Vui lòng tải lại.');
            }
            foreach (query('SELECT * FROM order_items WHERE order_id=?', [$id]) as $item) {
                $oldItems[$item['product_id']] = $item;
            }
        }
        if (!one('SELECT customer_id FROM customers WHERE customer_id=?', [$customer])) {
            throw new DomainException('Khách hàng không tồn tại.');
        }
        $items = [];
        $sum = 0;
        foreach ($requested as $productId => $quantity) {
            $product = one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [$productId]);
            if (!$product || !$product['is_active']) {
                throw new DomainException('Có sản phẩm đã ngừng bán hoặc không tồn tại.');
            }
            // Khách đặt: kiểm tra tồn trong cùng transaction và khóa sản phẩm.
            if (
                (one('SELECT role FROM users WHERE user_id=?', [$actor])['role'] ?? '') ===
                    'customer' &&
                $quantity > (int) $product['stock_quantity']
            ) {
                throw new DomainException('Sách không đủ số lượng. Vui lòng cập nhật giỏ hàng.');
            }
            $price = (int) ($oldItems[$productId]['unit_price'] ?? $product['price']);
            $name = $oldItems[$productId]['product_name_snapshot'] ?? $product['product_name'];
            $sum += $price * $quantity;
            $sku = $oldItems[$productId]['sku_snapshot'] ?? $product['sku'];
            $items[] = [$productId, $name, $quantity, $price, $sku];
        }
        // BƯỚC 4: tự tính tổng và kiểm quyền giảm giá; không tin tổng tiền client gửi.
        $total = calculate_total($sum, $discount, $shipping);
        kiem_tra_quyen_giam_gia($actor, $discount, $sum);
        if (
            $assigned &&
            !one(
                'SELECT user_id FROM users WHERE user_id=? AND is_active=1 AND role IN ("admin","staff")',
                [$assigned],
            )
        ) {
            throw new DomainException('Người phụ trách phải là tài khoản đang hoạt động.');
        }
        $values = [
            $customer,
            $recipient,
            $phone,
            $address,
            $sum,
            $discount,
            $shipping,
            $total,
            $method,
            $note,
        ];
        if ($id) {
            query(
                'UPDATE orders SET customer_id=?,recipient_name=?,recipient_phone=?,' .
                    'shipping_address=?,items_total=?,discount_amount=?,shipping_fee=?,' .
                    'total_amount=?,payment_method=?,note=?,version=version+1 WHERE ' .
                    'order_id=?',
                [...$values, $id],
            );
            query('DELETE FROM order_items WHERE order_id=?', [$id]);
            $orderId = $id;
        } else {
            // [MA_DON_TU_DONG] Mã tạm chỉ tồn tại trong transaction; có ID thì đổi DH001 trước commit.
            $code = 'T-' . bin2hex(random_bytes(10));
            query(
                'INSERT INTO orders(customer_id,recipient_name,recipient_phone,' .
                    'shipping_address,items_total,discount_amount,shipping_fee,total_amount,' .
                    'payment_method,note,order_code,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,' .
                    '?)',
                [...$values, $code, $actor],
            );
            $orderId = (int) db()->lastInsertId();
            query('UPDATE orders SET order_code=? WHERE order_id=?', [
                ma_dinh_danh('DH', $orderId),
                $orderId,
            ]);
            query(
                'INSERT INTO order_status_history(order_id,to_status,changed_by,reason) VALUES(?,?,?,?)',
                [$orderId, 'pending', $actor, 'Tạo đơn hàng'],
            );
        }
        $tracking = ma_theo_doi_giao_hang($orderId, $carrier, $tracking, $old);
        // BƯỚC 5: lưu giao nhận và từng dòng. Sửa đơn thì lưu thêm nội dung trước/sau.
        query(
            'UPDATE orders SET delivery_method=?,carrier=?,tracking_code=?,' .
                'expected_delivery_date=?,internal_note=?,assigned_to=? WHERE order_id=?',
            [$delivery, $carrier, $tracking, $expected, $internal, $assigned ?: null, $orderId],
        );
        foreach ($items as $item) {
            query(
                'INSERT INTO order_items(order_id,product_id,product_name_snapshot,' .
                    'quantity,unit_price,sku_snapshot) VALUES(?,?,?,?,?,?)',
                [$orderId, ...$item],
            );
        }
        if ($id) {
            $changes = [];
            foreach (
                [
                    'customer_id' => 'Khách',
                    'recipient_name' => 'Người nhận',
                    'recipient_phone' => 'Điện thoại',
                    'shipping_address' => 'Địa chỉ',
                    'discount_amount' => 'Giảm giá',
                    'shipping_fee' => 'Phí giao',
                    'total_amount' => 'Tổng',
                    'payment_method' => 'Cách thu',
                    'note' => 'Ghi chú giao',
                    'internal_note' => 'Ghi chú nội bộ',
                    'assigned_to' => 'Phụ trách',
                    'carrier' => 'Đơn vị giao',
                    'tracking_code' => 'Vận đơn',
                    'expected_delivery_date' => 'Ngày dự kiến',
                    'delivery_method' => 'Hình thức nhận',
                ]
                as $key => $label
            ) {
                $newValue = [
                    'customer_id' => $customer,
                    'recipient_name' => $recipient,
                    'recipient_phone' => $phone,
                    'shipping_address' => $address,
                    'discount_amount' => $discount,
                    'shipping_fee' => $shipping,
                    'total_amount' => $total,
                    'payment_method' => $method,
                    'note' => $note,
                    'internal_note' => $internal,
                    'assigned_to' => $assigned ?: null,
                    'carrier' => $carrier,
                    'tracking_code' => $tracking,
                    'expected_delivery_date' => $expected,
                    'delivery_method' => $delivery,
                ][$key];
                if ((string) $old[$key] !== (string) $newValue) {
                    $changes[] =
                        $label . ': ' . ($old[$key] ?? 'trống') . ' → ' . ($newValue ?? 'trống');
                }
            }
            $beforeLines = array_map(
                static fn($row) => $row['product_id'] .
                    ' × ' .
                    $row['quantity'] .
                    ' @ ' .
                    (int) $row['unit_price'],
                array_values($oldItems),
            );
            $afterLines = array_map(
                static fn($row) => $row[0] . ' × ' . $row[2] . ' @ ' . $row[3],
                $items,
            );
            if ($beforeLines !== $afterLines) {
                $changes[] =
                    'Dòng hàng (mã SP × SL @ giá): ' .
                    implode('; ', $beforeLines) .
                    ' → ' .
                    implode('; ', $afterLines);
            }
            ghi_su_kien(
                $orderId,
                'pending',
                $actor,
                'order_edit',
                $changes ? implode("\n", $changes) : 'Lưu lại đơn; nội dung không thay đổi.',
            );
        }
        return $orderId;
    });
    // BƯỚC 6: chỉ dùng xong token khi transaction đã thành công; trả ID cho trang chi tiết.
    unset($_SESSION['form_tokens'][$token]);
    return $result;
}

/** Giới hạn giảm giá chỉ áp dụng cho nhân viên, không giới hạn admin ngoài tổng tiền hàng. */

function kiem_tra_quyen_giam_gia(int $actor, int $discount, int $sum): void {
    $user = one('SELECT role FROM users WHERE user_id=? AND is_active=1', [$actor]);
    if (!$user) {
        throw new DomainException('Tài khoản không còn hoạt động.');
    }
    if ($user['role'] !== 'admin' && $discount * 100 > $sum * MAX_STAFF_DISCOUNT_PERCENT) {
        throw new DomainException(
            'Nhân viên chỉ được giảm tối đa ' .
                MAX_STAFF_DISCOUNT_PERCENT .
                '%. Đơn giảm cao hơn cần quản trị viên lưu.',
        );
    }
}

/** Nút Lưu và xem đơn: gọi save_order rồi chuyển tới chi tiết. */
function xu_ly_luu_don(array $user): void {
    $id = save_order($_POST, (int) $user['user_id']);
    flash('Đã lưu đơn hàng.');
    redirect('order', ['id' => $id]);
}
