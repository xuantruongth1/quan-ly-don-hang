<?php
declare(strict_types=1);

/** PHÍA KHÁCH: chọn sách, giỏ hàng, đơn của tôi và chi tiết đơn. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

function cua_hang_view(): void {
    ho_so_khach();
    page_heading(
        'Nhà sách của bạn',
        'Chọn sách, đặt hàng và theo dõi đơn trong cùng một nơi.',
        url('gio_hang'),
        'Xem giỏ hàng',
    );
    slider_sach(true);
    $q = text_value($_GET, 'q', 100, false);
    $p = whole_number($_GET['p'] ?? '1', 1, 100000, 'Trang');
    $offset = ($p - 1) * 15;
    $args = ['%' . $q . '%', '%' . $q . '%'];
    $where = ' WHERE is_active=1 AND (product_name LIKE ? OR sku LIKE ?)';
    $total = (int) one('SELECT COUNT(*) AS n FROM products' . $where, $args)['n'];
    $books = query(
        'SELECT * FROM products' . $where . " ORDER BY product_id LIMIT 15 OFFSET $offset",
        $args,
    )->fetchAll();
    ?>
<form
    class="filters card"
    method="get"
>
    <input
        type="hidden"
        name="page"
        value="cua_hang"
    />
    <label>
        Tìm sách theo tên hoặc mã
        <input
            name="q"
            value="<?= e($q) ?>"
            maxlength="100"
            placeholder="Nhập tên sách hoặc SP001..."
        />
    </label>
    <button>Tìm sách</button>
</form>
<p class="muted">Tìm thấy <?= $total ?> cuốn sách đang bán.</p>
<div class="book-grid">
    <?php foreach ($books as $book): ?>
    <article class="card book-card">
        <div class="book-cover tone-<?= (int) $book['product_id'] % 4 ?>">
            <?php if ($book['image']): ?>
            <img
                src="<?= e(url('product_image', ['id' => $book['product_id']])) ?>"
                alt="<?= e($book['product_name']) ?>"
                loading="lazy"
            />
            <?php else: ?>
            <small>NHÀ SÁCH</small>
            <strong><?= e($book['product_name']) ?></strong>
            <span><?= e($book['sku']) ?></span>
            <?php endif; ?>
        </div>
        <small><?= e($book['sku']) ?></small>
        <h2><?= e($book['product_name']) ?></h2>
        <strong class="book-price"><?= money($book['price']) ?></strong>
        <p class="muted"><?= (int) $book['stock_quantity'] > 0
            ? 'Còn ' . (int) $book['stock_quantity'] . ' cuốn'
            : 'Tạm hết hàng' ?></p>
        <form
            method="post"
            action="<?= e(url('cua_hang')) ?>"
        >
            <?php csrf_field(); ?>
            <input
                type="hidden"
                name="action"
                value="them_gio"
            />
            <input
                type="hidden"
                name="product_id"
                value="<?= (int) $book['product_id'] ?>"
            />
            <label>
                Số lượng
                <input
                    type="number"
                    name="quantity"
                    value="1"
                    min="1"
                    max="<?= min(100, (int) $book['stock_quantity']) ?>"
                    required
                    <?= (int) $book['stock_quantity'] === 0 ? 'disabled' : '' ?>
                />
            </label>
            <button <?= (int) $book['stock_quantity'] === 0 ? 'disabled' : '' ?>>Thêm vào giỏ</button>
        </form>
    </article>
    <?php endforeach; ?>
</div>
<?php
if (!$books): ?>
<section class="card empty">Chưa có sách phù hợp với từ khóa.</section>
<?php endif;
pagination('cua_hang', $total, ['q' => $q]);
}

function gio_hang_view(): void {
    $customer = ho_so_khach();
    $cart = $_SESSION['gio_hang'] ?? [];
    $rows = [];
    $sum = 0;
    foreach ($cart as $id => $quantity) {
        $book = one('SELECT * FROM products WHERE product_id=?', [$id]);
        if ($book) {
            $rows[] = [$book, $quantity];
            $sum += (int) $book['price'] * $quantity;
        }
    }
    page_heading(
        'Giỏ hàng',
        'Giá được tính lại theo giá bán hiện tại khi đặt hàng.',
        url('cua_hang'),
        'Tiếp tục chọn sách',
    );
    if (!$rows) {
        echo '<section class="card empty">Giỏ hàng đang trống. Hãy chọn một cuốn sách bạn ' .
            'yêu thích.</section>';
        return;
    }
    ?>
<section class="card">
    <h2>Sách đã chọn</h2>
    <form method="post">
        <?php csrf_field(); ?>
        <input
            type="hidden"
            name="action"
            value="cap_nhat_gio"
        />
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sách</th>
                        <th>Đơn giá</th>
                        <th>Số lượng</th>
                        <th>Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as [$book, $quantity]): ?>
                    <tr>
                        <td>
                            <?= e($book['product_name']) ?>
                            <small>
                                <?= e($book['sku']) ?>
                            · <?= $book['is_active'] ? 'Còn ' . (int) $book['stock_quantity'] . ' cuốn' : 'Đã ngừng bán' ?>
                            </small>
                        </td>
                        <td><?= money($book['price']) ?></td>
                        <td>
                            <input
                                aria-label="Số lượng <?= e($book['product_name']) ?>"
                                type="number"
                                name="quantity[<?= (int) $book['product_id'] ?>]"
                                value="<?= (int) $quantity ?>"
                                min="0"
                                max="100"
                                required
                            />
                        </td>
                        <td><?= money((int) $book['price'] * $quantity) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">
            Nhập 0 để bỏ sách. Sau khi đổi số lượng, bấm Cập nhật giỏ hàng trước khi đặt.
        </p>
        <button>Cập nhật giỏ hàng</button>
    </form>
</section>
<section class="card">
    <h2>Thông tin đặt hàng</h2>
    <p>
        Tiền sách:
        <strong><?= money($sum) ?></strong>
    </p>
    <p>
        Giao tận nơi: phí 30.000 đ, tổng
        <strong><?= money($sum + 30000) ?></strong>
        , thanh toán khi nhận hàng.
        <br />
        Nhận tại cửa hàng: miễn phí giao, tổng
        <strong><?= money($sum) ?></strong>
        , thanh toán tiền mặt tại cửa hàng.
    </p>
    <form
        method="post"
        class="stack"
    >
        <?php csrf_field(); ?>
        <input
            type="hidden"
            name="action"
            value="dat_hang"
        />
        <input
            type="hidden"
            name="form_token"
            value="<?= e(form_token()) ?>"
        />
        <?php
        field('recipient_name', 'Họ tên người nhận', $customer['full_name'], 'text', true, 100);
        field('recipient_phone', 'Số điện thoại', $customer['phone'], 'tel', true, 20);
        field(
            'shipping_address',
            'Địa chỉ nhận hàng',
            $customer['address'] ?? '',
            'text',
            false,
            255,
        );
        ?>
        <label>
            Hình thức nhận
            <select name="delivery_method">
                <option
                    value="delivery"
                    <?= old_value('delivery_method', 'delivery') === 'delivery' ? 'selected' : '' ?>
                >
                    Giao tận nơi — thu tiền khi nhận (+30.000 đ)
                </option>
                <option
                    value="pickup"
                    <?= old_value('delivery_method', 'delivery') === 'pickup' ? 'selected' : '' ?>
                >
                    Nhận và thanh toán tại cửa hàng (miễn phí)
                </option>
            </select>
        </label>
        <label>
            Ghi chú
            <textarea
                name="note"
                maxlength="3000"
            >
<?= e(old_value('note', '')) ?></textarea>
        </label>
        <p class="hint">
            Địa chỉ bắt buộc khi giao tận nơi. Đặt hàng chưa giữ sách; cửa hàng xác nhận khi đủ số
            lượng.
        </p>
        <button>Đặt hàng</button>
    </form>
</section>
<?php
}

function don_cua_toi_view(): void {
    $customer = ho_so_khach();
    $p = whole_number($_GET['p'] ?? '1', 1, 100000, 'Trang');
    $offset = ($p - 1) * 15;
    $total = (int) one('SELECT COUNT(*) AS n FROM orders WHERE customer_id=?', [
        $customer['customer_id'],
    ])['n'];
    $rows = query(
        "SELECT * FROM orders WHERE customer_id=? ORDER BY order_id DESC LIMIT 15 OFFSET $offset",
        [$customer['customer_id']],
    )->fetchAll();
    page_heading(
        'Đơn hàng của tôi',
        'Chỉ hiển thị đơn hàng thuộc hồ sơ của bạn.',
        url('cua_hang'),
        'Chọn thêm sách',
    );
    ?>
<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Ngày đặt</th>
                    <th>Trạng thái</th>
                    <th>Thanh toán</th>
                    <th>Tổng tiền</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!$rows) {
                    empty_row(6, 'Bạn chưa có đơn hàng.');
                }
                foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['order_code']) ?></td>
                    <td><?= stamp($row['created_at']) ?></td>
                    <td><?= status_badge($row['status']) ?></td>
                    <td><?= $row['payment_status'] === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán' ?></td>
                    <td><?= money($row['total_amount']) ?></td>
                    <td><a href="<?= e(url('don_khach', ['id' => $row['order_id']])) ?>">Xem đơn →</a></td>
                </tr>
                <?php endforeach;
                                ?>
            </tbody>
        </table>
    </div>
</section>
<?php pagination('don_cua_toi', $total);
}

function don_khach_view(): void {
    $order = don_cua_khach(whole_number($_GET['id'] ?? '', 1, 2147483647, 'Mã đơn'));
    $items = query('SELECT * FROM order_items WHERE order_id=? ORDER BY order_item_id', [
        $order['order_id'],
    ])->fetchAll();
    page_heading(
        'Đơn ' . $order['order_code'],
        'Đặt lúc ' . stamp($order['created_at']),
        url('don_cua_toi'),
        'Về đơn của tôi',
    );
    ?>
<section class="card">
    <h2>Thông tin nhận hàng</h2>
    <p><?= status_badge($order['status']) ?> · <?= $order['payment_status'] === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán' ?></p>
    <p>
        <?= e($order['recipient_name']) ?> · <?= e($order['recipient_phone']) ?>
        <br />
        <?= $order['delivery_method'] === 'pickup' ? 'Nhận tại cửa hàng' : e($order['shipping_address']) ?>
    </p>
    <?php if ($order['carrier']): ?>
    <p>Đơn vị giao: <?= e($order['carrier']) ?> · Mã vận đơn: <?= e($order['tracking_code'] ?? 'Chưa có') ?></p>
    <?php endif; ?>
    <?php if ($order['expected_delivery_date']): ?>
    <p>Ngày nhận dự kiến: <?= date('d/m/Y', strtotime($order['expected_delivery_date'])) ?></p>
    <?php endif; ?>
    <p>Ghi chú của bạn: <?= e($order['note'] ?: 'Không có') ?></p>
</section>
<section class="card">
    <h2>Sách trong đơn</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sách</th>
                    <th>Số lượng</th>
                    <th>Đơn giá</th>
                    <th>Thành tiền</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['product_name_snapshot']) ?></td>
                    <td><?= (int) $item['quantity'] ?></td>
                    <td><?= money($item['unit_price']) ?></td>
                    <td><?= money($item['subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p>
        Tiền sách: <?= money($order['items_total']) ?>
    · Giảm giá: <?= money($order['discount_amount']) ?>
    · Phí giao: <?= money($order['shipping_fee']) ?>
    </p>
    <h3>Tổng thanh toán: <?= money($order['total_amount']) ?></h3>
    <p>Hình thức thanh toán: <?= e(PAYMENT_LABELS[$order['payment_method']]) ?></p>
</section>
<?php if ($order['status'] === 'pending' && $order['payment_status'] === 'unpaid'): ?>
<section class="card">
    <h2>Hủy đơn hàng</h2>
    <p>Chỉ có thể hủy trước khi cửa hàng xác nhận đơn.</p>
    <form method="post">
        <?php csrf_field(); ?>
        <input
            type="hidden"
            name="action"
            value="huy_don_khach"
        />
        <input
            type="hidden"
            name="id"
            value="<?= (int) $order['order_id'] ?>"
        />
        <input
            type="hidden"
            name="version"
            value="<?= (int) $order['version'] ?>"
        />
        <button class="secondary">Hủy đơn này</button>
    </form>
</section>
<?php endif;
// Không dùng order_view của quản trị: tránh lộ ghi chú nội bộ, doanh số và nút xử lý kho.
}

