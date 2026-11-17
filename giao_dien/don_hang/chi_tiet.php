<?php
declare(strict_types=1);

/** XEM ĐƠN: thông tin đơn, lịch sử, phiếu in và chi tiết giao nhận. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_CHI_TIET] Thông tin đơn; print=true ẩn toàn bộ nội bộ và nút xử lý. */
function order_view(bool $print): void {
    $id = (int) ($_GET['id'] ?? 0);
    $order = one(
        'SELECT o.*,c.full_name AS customer_name,u.full_name AS creator,p.full_name ' .
            'AS collector,r.full_name AS return_receiver,a.full_name AS assignee FROM ' .
            'orders o JOIN customers c ON c.customer_id=o.customer_id JOIN users u ON ' .
            'u.user_id=o.created_by LEFT JOIN users p ON p.user_id=o.paid_by LEFT JOIN ' .
            'users r ON r.user_id=o.returned_by LEFT JOIN users a ON ' .
            'a.user_id=o.assigned_to WHERE o.order_id=?',
        [$id],
    );
    if (!$order) {
        throw new DomainException('Không tìm thấy đơn hàng.');
    }
    $items = query(
        'SELECT i.*,COALESCE(i.sku_snapshot,p.sku) AS sku FROM order_items i JOIN ' .
            'products p ON p.product_id=i.product_id WHERE i.order_id=? ORDER BY ' .
            'i.order_item_id',
        [$id],
    )->fetchAll();
    page_heading(
        $print ? 'Phiếu đơn hàng' : $order['order_code'],
        'Lập lúc ' . stamp($order['created_at']) . ' · Người tạo: ' . $order['creator'],
    );
    ?>
<div class="actions no-print">
    <a
        class="button secondary"
        href="<?= e(url('orders')) ?>"
    >
        ← Danh sách
    </a>
    <?php
    if (!$print): ?>
    <a
        class="button secondary"
        href="<?= e(url('print', ['id' => $id])) ?>"
    >
        Bản in
    </a>
    <?php endif;
    if (
        $order['status'] === 'pending' &&
        $order['payment_status'] === 'unpaid'
    ): ?>
    <a
        class="button"
        href="<?= e(url('order_form', ['id' => $id])) ?>"
    >
        Sửa đơn
    </a>
    <?php endif;
    if ($print): ?>
    <span class="hint">Nhấn Ctrl+P để in hoặc lưu PDF bằng trình duyệt.</span>
    <?php endif;
    ?>
</div>

<!-- [BO_CUC_CHI_TIET] CSS xếp phần đơn và lịch sử cạnh nhau; bản in một cột. -->
<div class="<?= $print ? 'print-layout' : 'detail-layout' ?>">
    <section class="card detail-primary">
        <div class="section-title">
            <h2><?= e($order['order_code']) ?></h2>
            <?= status_badge($order['status']) ?>
        </div>
        <div class="info-grid">
            <div>
                <p class="eyebrow">NGƯỜI NHẬN</p>
                <strong><?= e($order['recipient_name']) ?></strong>
                <p><?= e($order['recipient_phone']) ?></p>
                <p><?= e($order['delivery_method'] === 'pickup' ? 'Nhận tại cửa hàng' : $order['shipping_address']) ?></p>
            </div>
            <div>
                <p class="eyebrow">THÔNG TIN ĐƠN</p>
                <p>Khách hàng: <?= e($order['customer_name']) ?></p>
                <?php if (!$print): ?>
                <p>Phụ trách: <?= e($order['assignee'] ?? 'Chưa phân công') ?></p>
                <?php endif; ?>
                <p>Thanh toán: <?= e(PAYMENT_LABELS[$order['payment_method']]) ?></p>
                <p><?= $order['payment_status'] === 'paid' ? 'Đã thu đủ tiền' : 'Chưa thu tiền' ?></p>
                <?php if ($order['paid_at']): ?>
                <p><?= stamp($order['paid_at']) ?> · <?= e($order['collector']) ?></p>
                <?php if (!$print && $order['payment_method'] === 'cod'): ?>
                <p>
                    Tiền thực về: <?= money($order['cod_received_amount'] ?? $order['total_amount']) ?>
                · Phí khấu trừ: <?= money($order['actual_shipping_fee'] ?? 0) ?>
                </p>
                <?php endif;endif; ?>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Số lượng</th>
                        <th>Đơn giá</th>
                        <th class="right">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['product_name_snapshot']) ?></strong>
                            <small><?= e($item['sku']) ?></small>
                        </td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= money($item['unit_price']) ?></td>
                        <td class="right"><?= money($item['subtotal']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="totals">
            <p>
                <span>Tiền hàng</span>
                <strong><?= money($order['items_total']) ?></strong>
            </p>
            <p>
                <span>Giảm giá</span>
                <strong>− <?= money($order['discount_amount']) ?></strong>
            </p>
            <p>
                <span>Phí vận chuyển</span>
                <strong><?= money($order['shipping_fee']) ?></strong>
            </p>
            <p class="grand-total">
                <span>Tổng thanh toán</span>
                <strong><?= money($order['total_amount']) ?></strong>
            </p>
        </div>
        <?php if ($order['note']): ?>
        <p>
            <strong>Ghi chú:</strong>
            <br />
            <?= nl2br(e($order['note'])) ?>
        </p>
        <?php endif; ?>
    </section>
    <?php
    delivery_detail_view($order, $items, $print);
    if (!$print): ?>
    <section class="card">
        <h2>Ghi chú nội bộ</h2>
        <p><?= nl2br(e($order['internal_note'] ?: 'Không có ghi chú nội bộ.')) ?></p>
    </section>
    <?php order_actions_view($order, $items); ?>

    <section class="card detail-history">
        <h2>Lịch sử xử lý</h2>
        <ol class="timeline">
            <?php foreach (
                query(
                    'SELECT h.*,u.full_name FROM order_status_history h JOIN users u ON ' .
                        'u.user_id=h.changed_by WHERE order_id=? ORDER BY history_id DESC',
                    [$id],
                )
                as $history
            ): ?>
            <li>
                <strong><?= e(
                    [
                        'order_edit' => 'Sửa nội dung đơn',
                        'payment' => 'Ghi nhận thanh toán',
                        'shipping_update' => 'Cập nhật giao nhận',
                    ][$history['event_type']] ??
                        (STATUS_LABELS[$history['to_status']] ?? $history['to_status']),
                ) ?></strong>
                <small><?= stamp($history['created_at']) ?> · <?= e($history['full_name']) ?></small>
                <?php if ($history['reason']): ?>
                <p><?= nl2br(e($history['reason'])) ?></p>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif;?>
</div>
<?php
}

/** Hiện vận đơn và kết quả kiểm nhận; không in thông tin nội bộ. */
function delivery_detail_view(array $order, array $items, bool $print): void {
    ?>

<section class="card">
    <h2>Giao nhận · <?= e(DELIVERY_LABELS[$order['delivery_method']]) ?></h2>

    <div class="info-grid">
        <div>
            <?php if ($order['delivery_method'] === 'delivery'): ?>
            <p>
                Đơn vị vận chuyển:
                <strong><?= e($order['carrier'] ?: 'Chưa cập nhật') ?></strong>
            </p>
            <p>
                Mã vận đơn:
                <strong><?= e($order['tracking_code'] ?: 'Chưa cập nhật') ?></strong>
            </p>
            <?php endif; ?>
            <p>Ngày nhận dự kiến: <?= $order['expected_delivery_date']
                ? e(date('d/m/Y', strtotime($order['expected_delivery_date'])))
                : 'Chưa xác định' ?></p>
            <?php if ($order['shipped_at']): ?>
            <p>Bàn giao lần đầu: <?= stamp($order['shipped_at']) ?></p>
            <?php endif; ?>
            <?php if ($order['delivered_at']): ?>
            <p>Khách đã nhận: <?= stamp($order['delivered_at']) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($order['last_failure_reason']): ?>
            <p>Lý do giao thất bại gần nhất: <?= e($order['last_failure_reason']) ?></p>
            <?php endif; ?>
            <?php if ($order['returned_at']): ?>
            <p>Kiểm nhận hoàn: <?= stamp($order['returned_at']) ?> · <?= e($order['return_receiver']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($order['status'] === 'returned'): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sản phẩm</th>
                    <th>Đã gửi</th>
                    <th>Thực nhận</th>
                    <th>Nhập lại bán</th>
                    <th>Hỏng / không bán</th>
                    <th>Thiếu</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['product_name_snapshot']) ?></td>
                    <td><?= (int) $item['quantity'] ?></td>
                    <td><?= (int) $item['received_quantity'] ?></td>
                    <td><?= (int) $item['restocked_quantity'] ?></td>
                    <td><?= (int) $item['received_quantity'] - (int) $item['restocked_quantity'] ?></td>
                    <td><?= (int) $item['quantity'] - (int) $item['received_quantity'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if (!$print && in_array($order['status'], ['confirmed', 'delivery_failed'], true)): ?>
    <details>
        <summary>Cập nhật thông tin giao nhận</summary>
        <form
            method="post"
            class="stack"
        >
            <?php order_action_fields($order, 'save_delivery'); ?>
            <div class="form-grid"><?php delivery_fields($order, true); ?></div>
            <p class="hint">
                Trước khi bàn giao cần có đơn vị vận chuyển và mã vận đơn. Với Tự giao, mã theo dõi
                được cấp khi lưu.
            </p>
            <!-- [NUT_LUU_GIAO_NHAN] POST save_delivery -> save_delivery(); orders + lịch sử, không đổi tồn. -->
            <button>Lưu thông tin giao nhận</button>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php
}

