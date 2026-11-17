<?php
declare(strict_types=1);

/** SOẠN FORM: tìm/chọn khách, thêm/bỏ dòng, áp dụng hình thức nhận và xem trước. Chưa ghi đơn. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

// ===== NGHIỆP VỤ ĐƠN HÀNG VÀ XỬ LÝ BIỂU MẪU =====

/** Tìm theo tiền tố tên/SĐT/email, hoặc #mã khách. Tối đa 10 kết quả + 1 dòng kiểm tra trang sau. */

function tim_khach_cho_don(array $data): array {
    $term = text_value($data, 'customer_search', 100, false);
    $after = whole_number($data['customer_after'] ?? '0', 0, 2147483647, 'Mốc tìm tiếp');
    $before = whole_number($data['customer_before'] ?? '0', 0, 2147483647, 'Mốc tìm trước');
    if ($after && $before) {
        throw new DomainException('Chỉ dùng một mốc phân trang khách.');
    }
    if (mb_strlen($term) < 2) {
        return [
            'rows' => [],
            'term' => $term,
            'previous' => false,
            'next' => false,
            'started' => false,
        ];
    }
    $cursor = $before ?: $after;
    $operator = $before ? '<' : '>';
    $direction = $before ? 'DESC' : 'ASC';
    if (preg_match('/^#([1-9][0-9]{0,9})$/D', $term, $match)) {
        $row = one(
            'SELECT customer_id,full_name,phone,email,address FROM customers WHERE customer_id=?',
            [(int) $match[1]],
        );
        return [
            'rows' => $row ? [$row] : [],
            'term' => $term,
            'previous' => false,
            'next' => false,
            'started' => true,
        ];
    }
    // ESCAPE '=' để %, _ do người dùng nhập được hiểu là ký tự, không phải ký tự đại diện SQL.
    $prefix = strtr($term, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    $phone = preg_replace('/[ .()-]/', '', $term);
    if (str_starts_with($phone, '+84')) {
        $phone = '0' . substr($phone, 3);
    }
    $phonePrefix = preg_match('/^[0-9]{2,}$/D', $phone) ? $phone . '%' : $prefix;
    // Mỗi nhánh dùng chỉ mục riêng và LIMIT 11; UNION khử khách trùng do nhiều trường cùng khớp.
    $branches = [];
    $args = [];
    foreach (
        ['full_name' => $prefix, 'phone' => $phonePrefix, 'email' => $prefix]
        as $column => $pattern
    ) {
        $branches[] =
            "(SELECT customer_id FROM customers WHERE $column LIKE ? ESCAPE '=' " .
            "AND customer_id $operator ? ORDER BY customer_id $direction LIMIT 11)";
        $args[] = $pattern;
        $args[] = $cursor;
    }
    $sql =
        'SELECT c.customer_id,c.full_name,c.phone,c.email,c.address FROM customers c JOIN (' .
        implode(' UNION ', $branches) .
        ') found ON found.customer_id=c.customer_id ORDER BY c.customer_id ' .
        $direction .
        ' LIMIT 11';
    $rows = query($sql, $args)->fetchAll();
    $more = count($rows) > 10;
    $rows = array_slice($rows, 0, 10);
    if ($before) {
        $rows = array_reverse($rows);
    }
    return [
        'rows' => $rows,
        'term' => $term,
        'previous' => $before ? $more : $after > 0,
        'next' => $before ? true : $more,
        'started' => true,
    ];
}

/** [NUT_XEM_TRUOC] Chỉ SELECT và tính tiền; không tạo đơn, không trừ kho. */

function tinh_truoc_don(array $data): array {
    $ids = $data['product_id'] ?? [];
    $quantities = $data['quantity'] ?? [];
    if (!is_array($ids) || !is_array($quantities) || count($ids) > 50) {
        throw new DomainException('Tối đa 50 dòng sản phẩm.');
    }
    $old = [];
    $id = (int) ($data['order_id'] ?? 0);
    if ($id) {
        foreach (query('SELECT * FROM order_items WHERE order_id=?', [$id]) as $item) {
            $old[$item['product_id']] = $item;
        }
    }
    $sum = 0;
    $seen = [];
    $lines = [];
    foreach ($ids as $key => $raw) {
        if ($raw === '') {
            continue;
        }
        $productId = whole_number($raw, 1, 2147483647, 'Sản phẩm');
        if (isset($seen[$productId])) {
            throw new DomainException('Mỗi sản phẩm chỉ chọn một dòng.');
        }
        $seen[$productId] = true;
        $product = one('SELECT * FROM products WHERE product_id=? AND is_active=1', [$productId]);
        if (!$product) {
            throw new DomainException('Sản phẩm không tồn tại hoặc đã ngừng bán.');
        }
        $qty = whole_number($quantities[$key] ?? '', 1, 100000, 'Số lượng');
        $price = (int) ($old[$productId]['unit_price'] ?? $product['price']);
        $name = $old[$productId]['product_name_snapshot'] ?? $product['product_name'];
        $sum += $price * $qty;
        $lines[] = [
            'name' => $name,
            'quantity' => $qty,
            'price' => $price,
            'subtotal' => $price * $qty,
            'stock' => $product['stock_quantity'],
        ];
    }
    if (!$lines) {
        throw new DomainException('Chọn ít nhất một sản phẩm để tính tiền.');
    }
    $discount = money_input($data['discount_amount'] ?? '0', 'Giảm giá');
    $fee = money_input($data['shipping_fee'] ?? '0', 'Phí giao hàng');
    $total = calculate_total($sum, $discount, $fee);
    kiem_tra_quyen_giam_gia((int) require_login()['user_id'], $discount, $sum);
    return [
        'lines' => $lines,
        'sum' => $sum,
        'discount' => $discount,
        'fee' => $fee,
        'total' => $total,
    ];
}

/** Các nút trợ giúp gửi POST và hiển thị lại form trong cùng yêu cầu, chưa ghi CSDL. */

function xu_ly_form_soan_don(string $action): void {
    $data = $_POST;
    if (
        in_array($action, ['customer_next', 'customer_previous'], true) &&
        text_value($data, 'customer_search', 100, false) !==
            text_value($data, 'customer_search_previous', 100, false)
    ) {
        $action = 'search_customer';
    }
    // [NUT_TIM_KHACH] Phân trang bằng mã khách (keyset), không tải cả danh bạ hoặc dùng OFFSET lớn.
    if ($action === 'search_customer') {
        $data['customer_after'] = '0';
        $data['customer_before'] = '0';
    }
    if ($action === 'customer_next') {
        $data['customer_after'] = (string) whole_number(
            $data['customer_last_id'] ?? '',
            1,
            2147483647,
            'Mốc khách tiếp',
        );
        $data['customer_before'] = '0';
    }
    if ($action === 'customer_previous') {
        $data['customer_before'] = (string) whole_number(
            $data['customer_first_id'] ?? '',
            1,
            2147483647,
            'Mốc khách trước',
        );
        $data['customer_after'] = '0';
    }
    if (!is_array($data['product_id'] ?? []) || !is_array($data['quantity'] ?? [])) {
        throw new DomainException('Dòng hàng không hợp lệ.');
    }
    $ids = $data['product_id'] ?? [''];
    $qty = $data['quantity'] ?? ['1'];
    if (count($ids) > 50) {
        throw new DomainException('Tối đa 50 dòng.');
    }
    // [NUT_THEM_DONG] Thêm phần tử mảng; chưa INSERT vào order_items.
    if ($action === 'add_line') {
        if (count($ids) >= 50) {
            throw new DomainException('Đã đủ 50 dòng.');
        }
        $ids[] = '';
        $qty[] = '1';
    }
    // [NUT_BO_DONG] Bỏ phần tử và đánh lại chỉ số; không DELETE dòng CSDL lúc đang soạn.
    if ($action === 'remove_line') {
        $index = whole_number($data['line_index'] ?? '', 0, 49, 'Dòng cần bỏ');
        if (count($ids) <= 1) {
            $ids = [''];
            $qty = ['1'];
        } else {
            unset($ids[$index], $qty[$index]);
            $ids = array_values($ids);
            $qty = array_values($qty);
        }
    }
    $data['product_id'] = $ids;
    $data['quantity'] = $qty;
    // [NUT_CHON_KHACH] SELECT customers rồi điền người nhận vào mảng form; chưa lưu đơn.
    if ($action === 'use_customer') {
        $id = whole_number($data['customer_id'] ?? '', 1, 2147483647, 'Khách hàng');
        $customer = one('SELECT * FROM customers WHERE customer_id=?', [$id]);
        if (!$customer) {
            throw new DomainException('Không tìm thấy khách hàng.');
        }
        $data['recipient_name'] = $customer['full_name'];
        $data['recipient_phone'] = $customer['phone'];
        $data['shipping_address'] = $customer['address'] ?? '';
    }
    if ($action === 'apply_delivery' && ($data['delivery_method'] ?? '') === 'pickup') {
        $data['carrier'] = '';
        $data['tracking_code'] = '';
        $data['shipping_fee'] = '0';
        if (($data['payment_method'] ?? 'cod') === 'cod') {
            $data['payment_method'] = 'cash';
        }
    }
    // Đưa mảng đã xử lý cho order_form_view; các ô không chỉnh vẫn giữ giá trị đã nhập.
    $GLOBALS['order_draft'] = $data;
    if ($action === 'preview_order') {
        $GLOBALS['order_preview'] = tinh_truoc_don($data);
    }
}
