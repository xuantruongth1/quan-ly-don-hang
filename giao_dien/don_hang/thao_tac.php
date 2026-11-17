<?php
declare(strict_types=1);

/** NÚT XỬ LÝ ĐƠN: xác nhận, giao hàng, thu tiền, hủy, hoàn hàng và thất lạc. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [NHOM_NUT_XU_LY] Xác nhận/giao/thất bại/hoàn/thu tiền/thất lạc theo trạng thái. */
function order_actions_view(array $order, array $items): void {
    $status = $order['status'];
    $paid = $order['payment_status'] === 'paid';
    $next =
        [
            'pending' => 'confirmed',
            'confirmed' => $order['delivery_method'] === 'pickup' ? 'completed' : 'shipping',
            'shipping' => 'completed',
            'delivery_failed' => 'shipping',
        ][$status] ?? null;
    $labels = [
        'confirmed' => 'Xác nhận và trừ lượng còn bán',
        'shipping' =>
            $status === 'delivery_failed' ? 'Bàn giao để giao lại' : 'Bàn giao và bắt đầu giao',
        'completed' =>
            $order['delivery_method'] === 'pickup'
                ? 'Xác nhận khách đã nhận tại cửa hàng'
                : 'Xác nhận giao thành công',
    ];
    ?>
<section class="card">
    <h2>Xử lý đơn hàng</h2>
    <div class="actions">
        <?php if ($next): ?>
        <form method="post">
            <?php order_action_fields(
                $order,
                'status',
            ); ?>
            <input
                type="hidden"
                name="to_status"
                value="<?= e($next) ?>"
            />
            <?php if ($next === 'completed'): ?>
            <label class="checkbox">
                <input
                    type="checkbox"
                    name="received_confirmation"
                    value="1"
                    required
                />
                Khách đã nhận đủ hàng
            </label>
            <?php endif; ?>
            <?php if ($status === 'delivery_failed'): ?>
            <label>
                Ghi chú giao lại
                <input
                    name="reason"
                    maxlength="500"
                    placeholder="Ví dụ: khách hẹn nhận ngày mai"
                />
            </label>
            <?php endif; ?>

            <!-- [NUT_CHUYEN_TIEP] action=status, id/version/CSRF và to_status=$next.
     Xác nhận -> kiểm đủ và trừ lượng bán; Giao -> kiểm vận đơn; Hoàn thành -> checkbox nhận đủ.
     handle_post gọi change_status(), xong về chi tiết. -->
            <button><?= e($labels[$next]) ?></button>
        </form>
        <?php endif; ?>
        <?php if (
            !$paid &&
            !in_array($status, ['cancelled', 'returning', 'returned', 'lost'], true) &&
            ($order['payment_method'] !== 'cod' || $status === 'completed')
        ): ?>
        <!-- [NUT_THU_TIEN] POST paid → mark_paid(); COD thực nhận = tổng đơn - phí khấu trừ. -->

        <form method="post">
            <?php order_action_fields($order, 'paid'); ?>
            <?php if ($order['payment_method'] === 'cod'): ?>
            <?php
            field('actual_shipping_fee', 'Phí bị hãng vận chuyển khấu trừ (VND)', 0, 'number');
            field(
                'cod_received_amount',
                'Tiền thực về cửa hàng (VND)',
                (int) $order['total_amount'],
                'number',
            );
            ?>
            <?php endif; ?>

            <label class="checkbox">
                <input
                    type="checkbox"
                    name="money_received"
                    value="1"
                    required
                />
                Khách đã thanh toán đủ; cửa hàng đã thực nhận số tiền xác nhận
            </label>
            <button class="secondary">Ghi nhận thanh toán</button>
        </form>
        <?php elseif (
            !$paid &&
            $order['payment_method'] === 'cod' &&
            !in_array($status, ['cancelled', 'returning', 'returned', 'lost'], true)
        ): ?>
        <p class="hint">
            Thu hộ khi giao hàng: chỉ ghi nhận sau giao thành công và khi tiền đã thực về cửa hàng.
        </p>
        <?php endif; ?>
    </div>
    <?php if ($status === 'shipping'): ?>
    <details class="cancel-box">
        <summary>Ghi nhận giao thất bại</summary>
        <form
            method="post"
            class="stack"
        >
            <?php order_action_fields(
                $order,
                'status',
            ); ?>
            <input
                type="hidden"
                name="to_status"
                value="delivery_failed"
            />
            <label>
                Lý do giao thất bại
                <textarea
                    name="reason"
                    required
                    maxlength="500"
                    placeholder="Không liên lạc được, khách từ chối nhận…"
                ></textarea>
            </label>
            <p class="hint">Giao thất bại chưa làm thay đổi tồn kho.</p>
            <!-- [NUT_GIAO_LOI] POST status + delivery_failed + lý do -> change_status(); chưa cộng tồn. -->
            <button class="danger">Ghi nhận giao thất bại</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($status === 'delivery_failed' && !$paid): ?>
    <details class="cancel-box">
        <summary>Chuyển hoàn về cửa hàng</summary>
        <form
            method="post"
            class="stack"
        >
            <?php order_action_fields(
                $order,
                'status',
            ); ?>
            <input
                type="hidden"
                name="to_status"
                value="returning"
            />
            <label>
                Lý do chuyển hoàn
                <textarea
                    name="reason"
                    required
                    maxlength="500"
                ></textarea>
            </label>
            <p class="hint">Chưa cộng tồn. Chờ thực nhận và kiểm tra hàng hoàn.</p>
            <!-- [NUT_CHUYEN_HOAN] POST status + returning; chỉ chưa thu tiền, hàng chưa về nên chưa cộng tồn. -->
            <button class="danger">Xác nhận đang chuyển hoàn</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($status === 'returning'): ?>
    <h3>Kiểm nhận hàng hoàn</h3>
    <p class="muted">
        Nhập số thực nhận và số đủ điều kiện bán lại. Hàng hỏng/thiếu không được cộng vào tồn. Chỉ
        chốt sau khi đã kiểm nhận xong; phiên bản này không nhận hoàn nhiều đợt.
    </p>
    <form
        method="post"
        class="stack"
    >
        <?php order_action_fields($order, 'receive_return'); ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Đã gửi</th>
                        <th>Thực nhận</th>
                        <th>Đủ điều kiện bán lại</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                    $key = (int) $item['order_item_id']; ?>
                    <tr>
                        <td><?= e($item['product_name_snapshot']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td>
                            <input
                                type="number"
                                min="0"
                                max="<?= (int) $item['quantity'] ?>"
                                name="received_quantity[<?= $key ?>]"
                                required
                                aria-label="Thực nhận <?= e($item['product_name_snapshot']) ?>"
                            />
                        </td>
                        <td>
                            <input
                                type="number"
                                min="0"
                                max="<?= (int) $item['quantity'] ?>"
                                name="restocked_quantity[<?= $key ?>]"
                                required
                                aria-label="Nhập lại bán <?= e($item['product_name_snapshot']) ?>"
                            />
                        </td>
                    </tr>
                    <?php
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <label>
            Ghi chú kiểm nhận (nêu rõ hàng hỏng/thiếu nếu có)
            <textarea
                name="reason"
                required
                maxlength="500"
            ></textarea>
        </label>
        <label class="checkbox">
            <input
                type="checkbox"
                name="received_confirmation"
                value="1"
                required
            />
            Đã thực nhận và kiểm tra xong hàng hoàn
        </label>
        <!-- [NUT_NHAN_HOAN] POST receive_return với số nhận/bán lại từng dòng -> receive_return(); chỉ cộng phần bán lại. -->
        <button>Xác nhận kiểm nhận và cộng phần bán lại</button>
    </form>
    <?php endif; ?>
    <?php if (in_array($status, ['pending', 'confirmed'], true) && !$paid): ?>
    <details class="cancel-box">
        <summary>Hủy đơn hàng</summary>
        <form
            method="post"
            class="stack"
        >
            <?php order_action_fields(
                $order,
                'status',
            ); ?>
            <input
                type="hidden"
                name="to_status"
                value="cancelled"
            />
            <label>
                Lý do hủy
                <textarea
                    name="reason"
                    required
                    maxlength="500"
                ></textarea>
            </label>
            <!-- [NUT_HUY_DON] POST status + cancelled + lý do; pending không hoàn hàng, confirmed hoàn lượng đã trừ. -->
            <button class="danger">Xác nhận hủy<?= $status === 'confirmed' ? ' và hoàn lượng còn bán' : '' ?></button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($paid && in_array($status, ['pending', 'confirmed', 'delivery_failed'], true)): ?>
    <p class="hint">
        Đơn đã thu tiền không hủy/chuyển hoàn trong phiên bản này vì chưa có nghiệp vụ hoàn tiền.
    </p>
    <?php endif; ?>
    <?php if (is_admin() && !$paid && in_array($status, ['delivery_failed', 'returning'], true)): ?>

    <details class="cancel-box">
        <summary>Chốt thất lạc toàn bộ hàng (chỉ quản trị viên)</summary>
        <!-- [NUT_THAT_LAC] Không cộng tồn, không tính doanh số, bắt buộc lý do + xác nhận. -->

        <form
            method="post"
            class="stack"
        >
            <?php order_action_fields(
                $order,
                'status',
            ); ?>
            <input
                type="hidden"
                name="to_status"
                value="lost"
            />

            <label>
                Kết quả xác minh / lý do chốt
                <textarea
                    name="reason"
                    required
                    maxlength="500"
                ></textarea>
            </label>

            <label class="checkbox">
                <input
                    name="loss_confirmation"
                    type="checkbox"
                    value="1"
                    required
                />
                Đã xác minh mất toàn bộ, không còn hàng để kiểm nhận
            </label>

            <button class="danger">Chốt thất lạc, không hoàn tồn</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if (in_array($status, ['cancelled', 'returned', 'completed', 'lost'], true)): ?>
    <p class="muted">Đơn đã kết thúc, không chuyển thêm trạng thái.</p>
    <?php endif; ?>
</section>
<?php
}

/** Gắn action, id, version, csrf vào mỗi form xử lý đơn. */
/** Các form xử lý đơn dùng chung: action + id + version + CSRF.
 * Nút đổi trạng thái gửi thêm to_status. PHP kiểm lại, không dựa vào việc nút có hiện hay không.
 */
function order_action_fields(array $order, string $action): void {
    csrf_field();
    echo '<input type="hidden" name="action" value="' .
        e($action) .
        '"><input type="hidden" name="id" value="' .
        (int) $order['order_id'] .
        '"><input type="hidden" name="version" value="' .
        (int) $order['version'] .
        '">';
}

