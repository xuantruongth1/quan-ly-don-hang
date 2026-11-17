<?php
declare(strict_types=1);

/** TẠO / SỬA ĐƠN: chọn khách, sách, giao nhận và Lưu và xem đơn. Xử lý: xu_ly/luu_don.php. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_SOAN_DON] Mọi nút trong form đều POST về public/index.php. */
/** [GIAI_THICH_FORM_DON]
 * GET không id: tạo mảng form mới. Có id: đọc đơn pending/unpaid để sửa.
 * POST trợ giúp: ưu tiên order_draft; nhập lỗi: dùng old_input để không mất dữ liệu.
 * HTML form ở đây chỉ hiển thị. save_order() trong xu_ly.php mới ghi CSDL.
 */
function order_form_view(): void {
    $id = whole_number($_GET['id'] ?? '0', 0, 2147483647, 'Mã đơn');
    $order = $id ? one('SELECT * FROM orders WHERE order_id=?', [$id]) : null;
    if ($id && (!$order || $order['status'] !== 'pending' || $order['payment_status'] === 'paid')) {
        throw new DomainException('Chỉ soạn được đơn chờ xác nhận chưa thanh toán.');
    }
    $stored = $id
        ? query('SELECT * FROM order_items WHERE order_id=? ORDER BY order_item_id', [
            $id,
        ])->fetchAll()
        : [];
    $data = $GLOBALS['order_draft'] ?? ($GLOBALS['old_input'] ?? null);
    if ($data === null) {
        $data = $order ?? [
            'delivery_method' => 'delivery',
            'payment_method' => 'cod',
            'discount_amount' => '0',
            'shipping_fee' => '0',
            'assigned_to' => require_login()['user_id'],
        ];
        $data['product_id'] = array_column($stored, 'product_id') ?: ['', ''];
        $data['quantity'] = array_column($stored, 'quantity') ?: ['1', '1'];
        $data['form_token'] = form_token();
        $data['version'] = $order['version'] ?? 0;
    }
    $ids = is_array($data['product_id'] ?? null) ? array_slice($data['product_id'], 0, 50) : [''];
    $qty = is_array($data['quantity'] ?? null) ? $data['quantity'] : [];
    // [TIM_KHACH_PHAN_TRANG] Chỉ đọc khách đã chọn và tối đa 11 mã khớp, không preload cả bảng.
    $selectedId = whole_number($data['customer_id'] ?? '0', 0, 2147483647, 'Khách đã chọn');
    $selectedCustomer = $selectedId
        ? one(
            'SELECT customer_id,full_name,phone,email,address FROM customers WHERE customer_id=?',
            [$selectedId],
        )
        : null;
    $customerResults = tim_khach_cho_don($data);

    $products = query('SELECT * FROM products WHERE is_active=1 ORDER BY product_name')->fetchAll();
    $staff = query(
        'SELECT user_id,full_name FROM users WHERE is_active=1 AND role IN ' .
            '("admin","staff") ORDER BY full_name',
    )->fetchAll();
    $GLOBALS['old_input'] = $data;
    page_heading(
        $id ? 'Sửa đơn ' . $order['order_code'] : 'Tạo đơn hàng',
        'Các nút trợ giúp chưa lưu đơn. Chỉ nút Lưu đơn mới ghi vào CSDL.',
    );
    if (!$products) {
        echo '<section class="card">Cần có sản phẩm đang bán trước khi soạn đơn. Bạn có ' .
            'thể thêm khách mới trong mục Khách hàng. <a href="' .
            e(url('customers')) .
            '">Khách hàng</a> · <a href="' .
            e(url('products')) .
            '">Sản phẩm</a></section>';
        return;
    }
    ?>

<form
    method="post"
    class="stack"
>
    <?php csrf_field(); ?>
    <input
        type="hidden"
        name="order_id"
        value="<?= $id ?>"
    />
    <input
        type="hidden"
        name="version"
        value="<?= e($data['version'] ?? 0) ?>"
    />
    <input
        type="hidden"
        name="form_token"
        value="<?= e($data['form_token'] ?? form_token()) ?>"
    />

    <section class="card">
        <h2>01. Khách hàng và giao nhận</h2>

        <div class="form-grid">
            <!-- [NUT_TIM_KHACH] POST tìm tiền tố, tối thiểu 2 ký tự. Giữ nguyên toàn bộ dòng hàng và ghi chú. -->
            <div class="customer-picker">
                <input
                    type="hidden"
                    name="customer_id"
                    value="<?= (int) ($selectedCustomer['customer_id'] ?? 0) ?>"
                />
                <input
                    type="hidden"
                    name="customer_after"
                    value="<?= e($data['customer_after'] ?? 0) ?>"
                />
                <input
                    type="hidden"
                    name="customer_before"
                    value="<?= e($data['customer_before'] ?? 0) ?>"
                />
                <input
                    type="hidden"
                    name="customer_search_previous"
                    value="<?= e($customerResults['term']) ?>"
                />
                <label>
                    Tìm khách theo tên, số điện thoại, thư điện tử hoặc #mã khách
                    <input
                        name="customer_search"
                        value="<?= e($customerResults['term']) ?>"
                        maxlength="100"
                        placeholder="Ví dụ: Nguyễn, 0909, demo.customer hoặc #12"
                    />
                </label>
                <div class="actions">
                    <button
                        name="action"
                        value="search_customer"
                        formnovalidate
                        class="secondary"
                    >
                        Tìm khách hàng
                    </button>
                    <a
                        class="button secondary"
                        href="<?= e(url('customers')) ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        + Thêm khách mới
                    </a>
                </div>
                <p class="hint">
                    Gõ từ đầu họ tên, số điện thoại hoặc thư điện tử (ít nhất 2 ký tự). Mỗi lượt tối
                    đa 10 kết quả. Thêm khách mới ở tab khác rồi quay lại tìm; đơn đang soạn vẫn
                    được giữ.
                </p>
                <?php if ($selectedCustomer): ?>
                <p class="alert success">
                    <strong>Khách đã chọn: #<?= (int) $selectedCustomer['customer_id'] ?>
                    · <?= e($selectedCustomer['full_name']) ?></strong>
                    <br />
                    <?= e($selectedCustomer['phone']) ?> · <?= e($selectedCustomer['email']) ?>
                </p>
                <?php else: ?>
                <p class="hint">Chưa chọn khách. Tìm rồi bấm “Chọn dùng” ở kết quả.</p>
                <?php endif; ?>
                <?php if ($customerResults['started']): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Khách hàng</th>
                                <th>Liên hệ</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$customerResults['rows']) {
                                empty_row(
                                    3,
                                    'Không có khách khớp. Thử tiền tố khác hoặc thêm khách mới.',
                                );
                            } ?>
                            <?php foreach ($customerResults['rows'] as $customer): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int) $customer['customer_id'] ?> · <?= e($customer['full_name']) ?></strong>
                                    <small><?= e($customer['address']) ?></small>
                                </td>
                                <td>
                                    <?= e($customer['phone']) ?>
                                    <small><?= e($customer['email']) ?></small>
                                </td>
                                <td>
                                    <!-- [NUT_CHON_KHACH] POST mã khách → SELECT đúng 1 hồ sơ và điền người nhận. -->
                                    <button
                                        name="choose_customer"
                                        value="<?= (int) $customer['customer_id'] ?>"
                                        formnovalidate
                                        class="secondary"
                                    >
                                        Chọn dùng
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($customerResults['rows']):

                $first = $customerResults['rows'][0];
                $last = $customerResults['rows'][count($customerResults['rows']) - 1];
                ?>
                <input
                    type="hidden"
                    name="customer_first_id"
                    value="<?= (int) $first['customer_id'] ?>"
                />
                <input
                    type="hidden"
                    name="customer_last_id"
                    value="<?= (int) $last['customer_id'] ?>"
                />
                <div class="actions">
                    <?php if ($customerResults['previous']): ?>
                    <button
                        name="action"
                        value="customer_previous"
                        formnovalidate
                        class="secondary"
                    >
                        ← Khách trước
                    </button>
                    <?php endif; ?>
                    <?php if ($customerResults['next']): ?>
                    <button
                        name="action"
                        value="customer_next"
                        formnovalidate
                        class="secondary"
                    >
                        Khách tiếp →
                    </button>
                    <?php endif; ?>
                </div>
                <?php
                endif; ?>
                <?php elseif ($customerResults['term'] !== ''): ?>
                <p class="hint">Nhập ít nhất 2 ký tự để tìm, hoặc #mã khách chính xác.</p>
                <?php endif; ?>
            </div>
            <?php field(
                'recipient_name',
                'Tên người nhận',
                $data['recipient_name'] ?? '',
                'text',
                true,
                100,
            ); ?>
            <?php field(
                'recipient_phone',
                'Điện thoại người nhận',
                $data['recipient_phone'] ?? '',
                'tel',
                true,
                20,
            ); ?>
            <?php field(
                'shipping_address',
                'Địa chỉ giao (bắt buộc khi giao tận nơi)',
                $data['shipping_address'] ?? '',
                'text',
                false,
                255,
            ); ?>
            <?php delivery_fields($data); ?>

            <label>
                Nhân viên phụ trách

                <select name="assigned_to">
                    <option value="0">Chưa phân công</option>
                    <?php foreach ($staff as $person): ?>
                    <option
                        value="<?= (int) $person['user_id'] ?>"
                        <?= (int) ($data['assigned_to'] ?? 0) === (int) $person['user_id'] ? 'selected' : '' ?>
                    >
                        <?= e($person['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <!-- [NUT_AP_DUNG_NHAN] Chọn pickup rồi bấm: PHP bỏ vận đơn, phí = 0, COD đổi thành tiền mặt. -->

        <button
            type="submit"
            name="action"
            value="apply_delivery"
            formnovalidate
            class="secondary"
        >
            Áp dụng hình thức nhận
        </button>
        <p class="hint">
            Nhận tại cửa hàng: không vận đơn, phí giao 0, thanh toán tiền mặt/chuyển khoản.
        </p>
    </section>

    <section
        class="card"
        id="san-pham-trong-don"
        style="scroll-margin-top:16px"
    >
        <h2>02. Sản phẩm trong đơn</h2>
        <p class="hint">
            Tối đa 50 dòng. Sản phẩm trong đơn sửa giữ tên, mã sản phẩm và giá lúc lập.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Sản phẩm · giá hiện tại · còn bán</th>
                        <th>Số lượng</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($ids as $i => $selected): ?>

                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <select
                                name="product_id[]"
                                aria-label="Sản phẩm dòng <?= $i + 1 ?>"
                            >
                                <option value="">— Không chọn —</option>
                                <?php
                                $found = false;
                                foreach ($products as $product):

                                    $chosen = (int) $selected === (int) $product['product_id'];
                                    $found = $found || $chosen;
                                    ?>
                                <option
                                    value="<?= (int) $product['product_id'] ?>"
                                    <?= $chosen ? 'selected' : '' ?>
                                >
                                    <?= e(
                                        $product['sku'] .
                                            ' · ' .
                                            $product['product_name'] .
                                            ' · ' .
                                            money($product['price']) .
                                            ' · còn ' .
                                            $product['stock_quantity'],
                                    ) ?>
                                </option>
                                <?php
                                endforeach;
                                ?>
                                <?php if ($selected && !$found): ?>
                                <option
                                    value="<?= (int) $selected ?>"
                                    selected
                                >
                                    Sản phẩm đã ngừng bán — cần bỏ dòng
                                </option>
                                <?php endif; ?>
                            </select>
                            <?php foreach ($stored as $old) {
                            if (
                                (int) $old['product_id'] === (int) $selected
                            ): ?>
                            <small>Giá trên đơn: <?= money($old['unit_price']) ?></small>
                            <?php endif;
                            } ?>
                        </td>
                        <td>
                            <input
                                class="quantity"
                                type="number"
                                name="quantity[]"
                                min="1"
                                max="100000"
                                step="1"
                                value="<?= e(is_scalar($qty[$i] ?? null) ? $qty[$i] : '1') ?>"
                                aria-label="Số lượng dòng <?= $i + 1 ?>"
                            />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <!-- [NUT_THEM_DONG] POST add_line → thêm dòng trong mảng, không INSERT.
                     formaction giữ page/id và thêm #san-pham-trong-don: trình duyệt tải lại xong tự cuộn về phần sản phẩm, không cần JavaScript. -->

            <button
                name="action"
                value="add_line"
                formaction="<?= e(url('order_form', $id ? ['id' => $id] : []) . '#san-pham-trong-don') ?>"
                formnovalidate
                class="secondary"
            >
                + Thêm dòng
            </button>

            <label>
                Dòng cần bỏ
                <select name="line_index">
                    <?php foreach ($ids as $i => $_): ?>
                    <option value="<?= $i ?>"><?= $i + 1 ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <!-- [NUT_BO_DONG] POST remove_line → bỏ dòng chọn và đánh lại số thứ tự.
                     Cùng neo #san-pham-trong-don để không nhảy lên đầu trang sau POST. -->

            <button
                name="action"
                value="remove_line"
                formaction="<?= e(url('order_form', $id ? ['id' => $id] : []) . '#san-pham-trong-don') ?>"
                formnovalidate
                class="secondary"
            >
                Bỏ dòng đã chọn
            </button>
        </div>
    </section>

    <section class="card">
        <h2>03. Thanh toán và ghi chú</h2>

        <div class="form-grid">
            <?php field(
                'discount_amount',
                'Giảm giá (VND)',
                $data['discount_amount'] ?? 0,
                'number',
            ); ?>
            <?php field(
                'shipping_fee',
                'Phí giao thu khách (VND)',
                $data['shipping_fee'] ?? 0,
                'number',
            ); ?>

            <label>
                Phương thức thanh toán
                <select name="payment_method">
                    <?php foreach (PAYMENT_LABELS as $key => $label): ?>
                    <option
                        value="<?= e($key) ?>"
                        <?= ($data['payment_method'] ?? 'cod') === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="hint">
            Nhân viên giảm tối đa <?= MAX_STAFF_DISCOUNT_PERCENT ?>%; quản trị viên có thể giảm tới toàn bộ tiền hàng.
        </p>

        <label>
            Ghi chú giao hàng (có in trên phiếu)
            <textarea
                name="note"
                maxlength="3000"
            >
<?= e($data['note'] ?? '') ?></textarea>
        </label>

        <label>
            Ghi chú nội bộ (không in, không xuất CSV)
            <textarea
                name="internal_note"
                maxlength="3000"
            >
<?= e($data['internal_note'] ?? '') ?></textarea>
        </label>
    </section>

    <?php if ($preview = $GLOBALS['order_preview'] ?? null): ?>

    <section class="card preview">
        <h2>Xem trước — chưa lưu đơn</h2>
        <?php foreach ($preview['lines'] as $line): ?>
        <p>
            <?= e($line['name']) ?>: <?= $line['quantity'] ?> × <?= money($line['price']) ?> =
            <strong><?= money($line['subtotal']) ?></strong>
            <?= $line['quantity'] > $line['stock'] ? ' · Không đủ lượng còn bán' : '' ?>
        </p>
        <?php endforeach; ?>
        <p>Tiền hàng <?= money($preview['sum']) ?> − giảm <?= money($preview['discount']) ?> + phí <?= money($preview['fee']) ?></p>

        <h3>Tổng thanh toán: <?= money($preview['total']) ?></h3>
        <p class="hint">Khi lưu, PHP vẫn lấy lại giá và tính lại; khi xác nhận vẫn kiểm tra tồn.</p>
    </section>
    <?php endif; ?>

    <div class="actions">
        <!-- [NUT_XEM_TRUOC] POST preview_order → tinh_truoc_don(); không ghi CSDL. -->

        <button
            name="action"
            value="preview_order"
            formnovalidate
            class="secondary"
        >
            Tính tiền / Xem trước
        </button>
        <!-- [NUT_LUU_DON] POST save_order → save_order() → transaction orders + order_items + history. -->

        <button
            name="action"
            value="save_order"
        >
            Lưu và xem đơn →
        </button>
        <a
            class="button secondary"
            href="<?= e(url($id ? 'order' : 'orders', $id ? ['id' => $id] : [])) ?>"
        >
            Quay lại
        </a>
    </div>
</form>
<?php
}

function delivery_fields(array $order = [], bool $locked = false): void {
    ?>
<?php if ($locked): ?>
<input
    type="hidden"
    name="delivery_method"
    value="<?= e($order['delivery_method']) ?>"
/>
<?php else: ?>
<label>
    Hình thức nhận
    <select name="delivery_method">
        <?php foreach (DELIVERY_LABELS as $key => $label): ?>
        <option
            value="<?= e($key) ?>"
            <?= old_value('delivery_method', $order['delivery_method'] ?? 'delivery') === $key ? 'selected' : '' ?>
        >
            <?= e($label) ?>
        </option>
        <?php endforeach; ?>
    </select>
</label>
<?php endif; ?>
<?php
if (!$locked || $order['delivery_method'] === 'delivery'): ?>
<?php // [CHON_DON_VI_VAN_CHUYEN] Dropdown gửi carrier như ô nhập cũ.
    // Giữ giá trị đã lưu hoặc đã nhập để sửa đơn cũ và hiển thị lại khi form lỗi.
    // [CHON_DON_VI_VAN_CHUYEN] Dropdown gửi carrier như ô nhập cũ.
    // Giữ giá trị đã lưu hoặc đã nhập để sửa đơn cũ và hiển thị lại khi form lỗi.
    // [CHON_DON_VI_VAN_CHUYEN] Dropdown gửi carrier như ô nhập cũ.
    // Giữ giá trị đã lưu hoặc đã nhập để sửa đơn cũ và hiển thị lại khi form lỗi.
    // [CHON_DON_VI_VAN_CHUYEN] Dropdown gửi carrier như ô nhập cũ.
    // Giữ giá trị đã lưu hoặc đã nhập để sửa đơn cũ và hiển thị lại khi form lỗi.

$carrier = (string) old_value('carrier', $order['carrier'] ?? '');
$carriers = [
    'Giao Hàng Nhanh',
    'Giao Hàng Tiết Kiệm',
    'Viettel Post',
    'J&T Express',
    'Bưu điện Việt Nam',
    'Tự giao',
];
?>
<label>
    Đơn vị vận chuyển
    <select name="carrier">
        <option value="">— Chưa chọn —</option>
        <?php if ($carrier !== '' && !in_array($carrier, $carriers, true)): ?>
        <option
            value="<?= e($carrier) ?>"
            selected
        >
            <?= e($carrier) ?> (đã nhập)
        </option>
        <?php endif; ?>
        <?php foreach ($carriers as $name): ?>
        <option
            value="<?= e($name) ?>"
            <?= $carrier === $name ? ' selected' : '' ?>
        >
            <?= e($name) ?>
        </option>
        <?php endforeach; ?>
    </select>
</label>
<p class="hint">
    Tự giao: hệ thống cấp mã TG theo số của đơn khi lưu. Giao qua hãng: nhập mã do hãng cấp.
</p>
<?php field(
    'tracking_code',
    'Mã vận đơn / mã theo dõi',
    $order['tracking_code'] ?? '',
    'text',
    false,
    100,
); ?>
<?php endif;
field(
    'expected_delivery_date',
    'Ngày nhận dự kiến',
    old_value('expected_delivery_date', $order['expected_delivery_date'] ?? ''),
    'date',
    false,
    10,
);?>
<?php
}

