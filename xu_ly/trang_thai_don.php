<?php
declare(strict_types=1);

/** TRẠNG THÁI / KHO / THU TIỀN: xác nhận, hủy, giao, kiểm nhận hoàn và lịch sử. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/**
 * [GIAI_THICH_DOI_TRANG_THAI] Các nút Xác nhận/Giao/Hoàn thành/Hủy/Giao lỗi/Chuyển hoàn.
 * $id: đơn; $to: trạng thái muốn đến; $reason: lý do; $actor: người thao tác.
 * 1. Khóa đơn, so version và kiểm tra đường chuyển hợp lệ.
 * 2. Xác nhận mới trừ hàng; hủy confirmed mới trả lại hàng. Giao lại không trừ thêm.
 * 3. Cập nhật orders + ghi order_status_history, cùng transaction với products nếu có.
 * Chốt thất lạc cần quyền quản trị và xác nhận; kiểm nhận hoàn dùng hàm riêng.
 */

function change_status(int $id, string $to, string $reason, int $actor, array $options = []): void {
    transaction(function () use ($id, $to, $reason, $actor, $options) {
        $order = one('SELECT * FROM orders WHERE order_id=? FOR UPDATE', [$id]);
        if (!$order) {
            throw new DomainException('Không tìm thấy đơn hàng.');
        }
        check_order_version($order, $options);
        assert_transition(
            $order['status'],
            $to,
            $order['payment_status'] === 'paid',
            $reason,
            $order['delivery_method'],
        );
        if ($to === 'lost') {
            $admin = one(
                "SELECT user_id FROM users WHERE user_id=? AND role='admin' AND is_active=1",
                [$actor],
            );
            if (!$admin || ($options['loss_confirmation'] ?? '') !== '1') {
                throw new DomainException(
                    'Chỉ quản trị viên được chốt thất lạc và phải xác nhận kết quả xác minh mất toàn bộ hàng.',
                );
            }
            query(
                "UPDATE orders SET status='lost',lost_at=NOW(),lost_by=?,version=version+1 WHERE order_id=?",
                [$actor, $id],
            );
            query(
                'INSERT INTO order_status_history(order_id,from_status,to_status,' .
                    "changed_by,reason) VALUES(?,?,'lost',?,?)",
                [$id, $order['status'], $actor, $reason],
            );
            // Hàng mất không được cộng lại tồn; không tính là đơn hoàn thành.
            return;
        }
        if ($to === 'returned') {
            throw new DomainException(
                'Dùng biểu mẫu kiểm nhận từng sản phẩm để xác nhận hàng hoàn.',
            );
        }
        if ($to === 'shipping' && (!$order['carrier'] || !$order['tracking_code'])) {
            throw new DomainException(
                'Cần lưu đơn vị vận chuyển và mã vận đơn trước khi giao. Nếu tự giao, lưu ' .
                    'thông tin giao nhận để hệ thống cấp mã theo dõi.',
            );
        }
        if ($to === 'completed' && ($options['received_confirmation'] ?? '') !== '1') {
            throw new DomainException('Cần xác nhận đã giao đủ hàng cho khách.');
        }
        if ($to === 'confirmed' || ($to === 'cancelled' && $order['status'] === 'confirmed')) {
            $items = query('SELECT * FROM order_items WHERE order_id=? ORDER BY product_id', [
                $id,
            ])->fetchAll();
            if (!$items) {
                throw new DomainException('Đơn không có sản phẩm.');
            }
            foreach ($items as $item) {
                $product = one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [
                    $item['product_id'],
                ]);
                if (!$product) {
                    throw new DomainException('Không tìm thấy sản phẩm.');
                }
                if ($to === 'confirmed') {
                    if (!$product['is_active'] || $product['stock_quantity'] < $item['quantity']) {
                        throw new DomainException(
                            'Sản phẩm ' .
                                $item['product_name_snapshot'] .
                                ' ngừng bán hoặc không đủ tồn.',
                        );
                    }
                    query(
                        'UPDATE products SET stock_quantity=stock_quantity-? WHERE product_id=?',
                        [$item['quantity'], $item['product_id']],
                    );
                } else {
                    if ((int) $product['stock_quantity'] + (int) $item['quantity'] > 2147483647) {
                        throw new DomainException('Tồn kho vượt giới hạn.');
                    }
                    query(
                        'UPDATE products SET stock_quantity=stock_quantity+? WHERE product_id=?',
                        [$item['quantity'], $item['product_id']],
                    );
                }
            }
        }
        query(
            "UPDATE orders SET status=?,completed_at=CASE WHEN ?='completed' THEN NOW() " .
                "ELSE completed_at END,delivered_at=CASE WHEN ?='completed' THEN NOW() ELSE " .
                "delivered_at END,shipped_at=CASE WHEN ?='shipping' THEN " .
                'COALESCE(shipped_at,NOW()) ELSE shipped_at END,last_failure_reason=CASE WHEN ' .
                "?='delivery_failed' THEN ? ELSE last_failure_reason END,version=version+1 " .
                'WHERE order_id=?',
            [$to, $to, $to, $to, $to, $reason, $id],
        );
        query(
            'INSERT INTO order_status_history(order_id,from_status,to_status,' .
                'changed_by,reason) VALUES(?,?,?,?,?)',
            [$id, $order['status'], $to, $actor, $reason ?: null],
        );
    });
}

/**
 * [GIAI_THICH_THU_TIEN] Nút Ghi nhận thanh toán.
 * Khóa và kiểm tra đơn chưa thu. Thu hộ chỉ chốt sau completed và khi tiền đã về.
 * Kiểm tiền ròng = tổng đơn - phí khấu trừ; không sửa total_amount.
 * Ghi orders (paid/paid_at/paid_by) và sự kiện payment trong order_status_history.
 */

function mark_paid(int $id, int $actor, array $options = []): void {
    transaction(function () use ($id, $actor, $options) {
        $order = one('SELECT * FROM orders WHERE order_id=? FOR UPDATE', [$id]);
        if (
            !$order ||
            in_array($order['status'], ['cancelled', 'returning', 'returned', 'lost'], true) ||
            $order['payment_status'] === 'paid'
        ) {
            throw new DomainException(
                'Không thể thu tiền đơn đã hủy, chuyển hoàn, thất lạc hoặc đã thu tiền.',
            );
        }
        check_order_version($order, $options);
        if ($order['payment_method'] === 'cod' && $order['status'] !== 'completed') {
            throw new DomainException(
                'Chỉ ghi nhận COD sau khi giao hoàn thành và tiền đã thực về cửa hàng.',
            );
        }
        $fee = null;
        $received = (int) $order['total_amount'];
        if ($order['payment_method'] === 'cod') {
            $fee = money_input($options['actual_shipping_fee'] ?? '0', 'Phí bị khấu trừ');
            $received = money_input(
                $options['cod_received_amount'] ?? (string) $received,
                'Số tiền thực nhận',
            );
            if (
                $fee > (int) $order['total_amount'] ||
                $received !== (int) $order['total_amount'] - $fee
            ) {
                throw new DomainException(
                    'COD thực nhận phải bằng tổng tiền đơn trừ phí khấu trừ (phiên bản đối soát một lần).',
                );
            }
        }
        query(
            "UPDATE orders SET payment_status='paid',paid_at=NOW(),paid_by=?," .
                'actual_shipping_fee=?,cod_received_amount=?,version=version+1 WHERE ' .
                'order_id=?',
            [$actor, $fee, $order['payment_method'] === 'cod' ? $received : null, $id],
        );
        ghi_su_kien(
            $id,
            $order['status'],
            $actor,
            'payment',
            'Xác nhận khách đã thanh toán đủ ' .
                money($order['total_amount']) .
                '; cửa hàng thực nhận ' .
                money($received) .
                ($fee !== null ? '; phí khấu trừ ' . money($fee) : '') .
                '.',
        );
    });
}

function check_order_version(array $order, array $options): void {
    if (isset($options['version']) && (int) $order['version'] !== (int) $options['version']) {
        throw new DomainException('Đơn đã được cập nhật. Tải lại trang trước khi thao tác.');
    }
}

/**
 * [MA_TU_GIAO] Dùng số thứ tự của đơn để cấp mã TG. Giữ mã tự giao đã lưu
 * để lịch sử và các phiếu cũ vẫn tra được. Khi đổi sang hãng, bỏ mã nội bộ
 * nếu ô mã vẫn giữ nguyên giá trị cũ.
 */
function ma_theo_doi_giao_hang(int $id, ?string $carrier, ?string $tracking, ?array $old): ?string {
    $wasSelfDelivery = ($old['carrier'] ?? null) === 'Tự giao';
    $oldTracking = $old['tracking_code'] ?? null;
    if ($carrier === 'Tự giao') {
        return $wasSelfDelivery && $oldTracking ? $oldTracking : ma_dinh_danh('TG', $id);
    }
    if ($wasSelfDelivery && $tracking === $oldTracking) {
        return null;
    }
    return $tracking;
}

/**
 * [GIAI_THICH_LUU_GIAO_NHAN] Nút Lưu thông tin giao nhận.
 * Chỉ confirmed/delivery_failed, so version, không đổi hình thức nhận sau xác nhận.
 * Ghi carrier/tracking/date vào orders và sự kiện shipping_update; không đổi tồn.
 */

function save_delivery(int $id, array $data, int $actor): void {
    [$method, $carrier, $tracking, $date] = delivery_input($data);
    transaction(function () use ($id, $data, $method, $carrier, $tracking, $date, $actor) {
        $order = one('SELECT * FROM orders WHERE order_id=? FOR UPDATE', [$id]);
        if (!$order || !in_array($order['status'], ['confirmed', 'delivery_failed'], true)) {
            throw new DomainException(
                'Chỉ cập nhật vận chuyển khi đã xác nhận hoặc giao thất bại.',
            );
        }
        check_order_version($order, $data);
        if ($method !== $order['delivery_method']) {
            throw new DomainException('Không đổi hình thức nhận sau khi xác nhận đơn.');
        }
        $tracking = ma_theo_doi_giao_hang($id, $carrier, $tracking, $order);
        query(
            'UPDATE orders SET carrier=?,tracking_code=?,expected_delivery_date=?,' .
                'version=version+1 WHERE order_id=?',
            [$carrier, $tracking, $date, $id],
        );
        $summary =
            'Cập nhật giao nhận: ' .
            ($carrier ?? 'nhận tại cửa hàng') .
            ' / ' .
            ($tracking ?? 'không vận đơn') .
            ' / dự kiến ' .
            ($date ?? 'chưa xác định');
        ghi_su_kien($id, $order['status'], $actor, 'shipping_update', $summary);
    });
}

/**
 * [GIAI_THICH_NHAN_HOAN] Nút Xác nhận kiểm nhận và cộng phần bán lại.
 * Nhận số thực nhận/số bán lại từng dòng. Số bán lại <= thực nhận <= đã gửi.
 * Chỉ cộng số bán lại vào products; ghi kết quả order_items, trạng thái orders và lịch sử.
 * Cùng transaction, chốt một lần. Chưa nhận món nào thì không chuyển returned.
 */

function receive_return(int $id, array $data, int $actor): void {
    $reason = text_value($data, 'reason', 500);
    if (($data['received_confirmation'] ?? '') !== '1') {
        throw new DomainException('Cần xác nhận đã thực nhận và kiểm tra hàng hoàn.');
    }
    $received = $data['received_quantity'] ?? null;
    $restocked = $data['restocked_quantity'] ?? null;
    if (!is_array($received) || !is_array($restocked)) {
        throw new DomainException('Thiếu số lượng kiểm nhận từng dòng.');
    }
    transaction(function () use ($id, $data, $actor, $reason, $received, $restocked) {
        $order = one('SELECT * FROM orders WHERE order_id=? FOR UPDATE', [$id]);
        if (!$order) {
            throw new DomainException('Không tìm thấy đơn hàng.');
        }
        check_order_version($order, $data);
        assert_transition(
            $order['status'],
            'returned',
            $order['payment_status'] === 'paid',
            $reason,
            $order['delivery_method'],
        );
        $items = query('SELECT * FROM order_items WHERE order_id=? ORDER BY product_id', [
            $id,
        ])->fetchAll();
        if (!$items || count($received) !== count($items) || count($restocked) !== count($items)) {
            throw new DomainException('Phải kiểm nhận đủ các dòng sản phẩm của đơn.');
        }
        $totalReceived = 0;
        foreach ($items as $item) {
            $key = $item['order_item_id'];
            $qty = whole_number($received[$key] ?? '', 0, (int) $item['quantity'], 'Số thực nhận');
            $good = whole_number($restocked[$key] ?? '', 0, $qty, 'Số đủ điều kiện bán lại');
            $totalReceived += $qty;
            $product = one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [
                $item['product_id'],
            ]);
            if (!$product || (int) $product['stock_quantity'] + $good > 2147483647) {
                throw new DomainException('Không thể cập nhật tồn kho sản phẩm.');
            }
            query('UPDATE products SET stock_quantity=stock_quantity+? WHERE product_id=?', [
                $good,
                $item['product_id'],
            ]);
            query(
                'UPDATE order_items SET received_quantity=?,restocked_quantity=? WHERE order_item_id=?',
                [$qty, $good, $key],
            );
        }
        if ($totalReceived === 0) {
            throw new DomainException(
                'Chưa nhận lại sản phẩm nào: giữ trạng thái đang chuyển hoàn để tiếp tục xử lý.',
            );
        }
        query(
            "UPDATE orders SET status='returned',returned_at=NOW(),returned_by=?," .
                'version=version+1 WHERE order_id=?',
            [$actor, $id],
        );
        query(
            'INSERT INTO order_status_history(order_id,from_status,to_status,' .
                "changed_by,reason) VALUES(?,'returning','returned',?,?)",
            [$id, $actor, $reason],
        );
    });
}

/** Ghi sự kiện sửa đơn/thu tiền vào bảng lịch sử có sẵn, không tạo bảng thứ 7. */

function ghi_su_kien(int $id, string $status, int $actor, string $type, string $content): void {
    query(
        'INSERT INTO order_status_history(order_id,from_status,to_status,' .
            'changed_by,event_type,reason) VALUES(?,?,?,?,?,?)',
        [$id, $status, $status, $actor, $type, $content],
    );
}

/** Nút đổi trạng thái/hủy: gọi change_status trong transaction. */
function xu_ly_doi_trang_thai(array $user): void {
    $id = whole_number($_POST['id'] ?? '', 1, 2147483647, 'Mã đơn');
    $version = whole_number($_POST['version'] ?? '', 1, 2147483647, 'Phiên bản đơn');
    change_status(
        $id,
        text_value($_POST, 'to_status', 20),
        text_value($_POST, 'reason', 500, false),
        (int) $user['user_id'],
        [
            'version' => $version,
            'received_confirmation' => $_POST['received_confirmation'] ?? '',
            'loss_confirmation' => $_POST['loss_confirmation'] ?? '',
        ],
    );
    flash('Đã cập nhật trạng thái đơn hàng.');
    redirect('order', ['id' => $id]);
}

/** Nút thu tiền: xác nhận thực nhận rồi gọi mark_paid. */
function xu_ly_thu_tien(array $user): void {
    $id = whole_number($_POST['id'] ?? '', 1, 2147483647, 'Mã đơn');
    if (($_POST['money_received'] ?? '') !== '1') {
        throw new DomainException('Cần xác nhận cửa hàng đã thực nhận đủ tiền.');
    }
    $version = whole_number($_POST['version'] ?? '', 1, 2147483647, 'Phiên bản đơn');
    mark_paid($id, (int) $user['user_id'], [
        'version' => $version,
        'actual_shipping_fee' => $_POST['actual_shipping_fee'] ?? '0',
        'cod_received_amount' => $_POST['cod_received_amount'] ?? null,
    ]);
    flash('Đã ghi nhận thanh toán.');
    redirect('order', ['id' => $id]);
}

/** Nút Lưu giao nhận / Kiểm nhận hoàn: gọi nghiệp vụ tương ứng. */
function xu_ly_giao_nhan(string $action, array $user): void {
    $id = whole_number($_POST['id'] ?? '', 1, 2147483647, 'Mã đơn');
    whole_number($_POST['version'] ?? '', 1, 2147483647, 'Phiên bản đơn');
    if ($action === 'save_delivery') {
        save_delivery($id, $_POST, (int) $user['user_id']);
    } else {
        receive_return($id, $_POST, (int) $user['user_id']);
    }
    flash(
        $action === 'save_delivery'
            ? 'Đã lưu thông tin giao nhận.'
            : 'Đã kiểm nhận; chỉ hàng đủ điều kiện bán được cộng vào tồn.',
    );
    redirect('order', ['id' => $id]);
}
