<?php
declare(strict_types=1);

/** TAB ĐƠN HÀNG: danh sách và hai nút Xem đơn, Sửa đơn. Form: don_hang/soan_don.php. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

function order_table(array $rows): void {
    ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Mã đơn / Ngày tạo</th>
                <th>Khách hàng</th>
                <th>Tổng tiền</th>
                <th>Trạng thái</th>
                <th>Thanh toán</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (!$rows) {
                empty_row(6, 'Chưa có đơn phù hợp.');
            }
            foreach ($rows as $row): ?>
            <tr>
                <td>
                    <a
                        class="order-code"
                        href="<?= e(url('order', ['id' => $row['order_id']])) ?>"
                    >
                        <?= e($row['order_code']) ?>
                    </a>
                    <small><?= stamp($row['created_at']) ?></small>
                </td>
                <td>
                    <?= e($row['full_name']) ?>
                    <small><?= e($row['recipient_phone']) ?></small>
                </td>
                <td class="nowrap"><?= money($row['total_amount']) ?></td>
                <td><?= status_badge($row['status']) ?></td>
                <td>
                    <span class="payment-dot <?= $row['payment_status'] === 'paid' ? 'paid' : '' ?>"></span>
                    <?= $row['payment_status'] === 'paid' ? 'Đã thu' : 'Chưa thu' ?>
                </td>
                <td class="nowrap">
                    <!-- [NUT_XEM_DON] Mở chi tiết đơn, không thay đổi dữ liệu. -->
                    <a
                        class="button secondary"
                        href="<?= e(url('order', ['id' => $row['order_id']])) ?>"
                    >
                        Xem đơn
                    </a>
                    <?php if (
                        $row['status'] === 'pending' &&
                        $row['payment_status'] === 'unpaid'
                    ): ?>
                    <!-- [NUT_SUA_DON_TRUC_TIEP] Mở form sửa ngay từ danh sách. Chỉ đơn chờ xác nhận, chưa thu tiền. -->
                    <a
                        class="button secondary"
                        href="<?= e(url('order_form', ['id' => $row['order_id']])) ?>"
                    >
                        Sửa đơn
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach;?>
        </tbody>
    </table>
</div>
<?php
}

/** [MAN_HINH_DON] Lọc, sắp xếp, phân trang; dùng chung bộ lọc với xuất CSV. */
function orders_view(): void {
    // [NUT_MO_TAO_DON] + Tạo đơn hàng -> GET order_form -> order_form_view(), chưa INSERT.
    page_heading(
        'Đơn hàng',
        'Tra cứu, lọc việc cần xử lý và xuất danh sách.',
        url('order_form'),
        '+ Tạo đơn hàng',
    );
    [$where, $args, $sort, $filter] = bo_loc_don($_GET);
    $join = ' FROM orders o JOIN customers c ON c.customer_id=o.customer_id';
    // [THONG_KE_BO_LOC] Tổng đúng toàn bộ kết quả lọc, không chỉ 15 hàng trên trang.
    $summary = one(
        'SELECT COUNT(*) AS n,COALESCE(SUM(o.total_amount),0) AS ' .
            "amount,COALESCE(SUM(o.status='completed' AND o.payment_status='unpaid'),0) " .
            "AS outstanding,COALESCE(SUM(o.status='delivery_failed'),0) AS failed" .
            $join .
            $where,
        $args,
    );
    $total = (int) $summary['n'];
    $params = array_intersect_key(
        $_GET,
        array_flip([
            'q',
            'status',
            'delivery_method',
            'payment_status',
            'task',
            'sort',
            'customer_id',
            'assigned_to',
            'from',
            'to',
        ]),
    );
    $p = whole_number($_GET['p'] ?? '1', 1, 1000000, 'Trang');
    $offset = ($p - 1) * 15;
    $rows = query(
        'SELECT o.*,c.full_name' .
            $join .
            $where .
            ' ORDER BY ' .
            $sort .
            " LIMIT 15 OFFSET $offset",
        $args,
    )->fetchAll();
    $staff = query(
        'SELECT user_id,full_name FROM users WHERE role IN ("admin","staff") ORDER BY full_name',
    )->fetchAll();
    ?>

<?php ngay_nhanh_view('orders', $params); ?>
<div class="stats">
    <article class="stat">
        <span>Đơn theo bộ lọc</span>
        <strong><?= $total ?></strong>
        <small>Toàn bộ trang kết quả</small>
    </article>
    <article class="stat accent">
        <span>Tổng giá trị đơn đang lọc</span>
        <strong><?= money($summary['amount']) ?></strong>
        <small>Gồm mọi trạng thái trong bộ lọc, chưa phải thực thu</small>
    </article>
    <article class="stat">
        <span>Hoàn thành chưa thu</span>
        <strong><?= (int) $summary['outstanding'] ?></strong>
        <small>Trong bộ lọc hiện tại</small>
    </article>
    <article class="stat">
        <span>Đang giao thất bại</span>
        <strong><?= (int) $summary['failed'] ?></strong>
        <small>Cần liên hệ hoặc giao lại</small>
    </article>
</div>
<section class="card">
    <!-- [NUT_LOC_DON] method=GET để giữ tiêu chí trong URL và dùng lại khi xuất CSV. -->

    <form
        method="get"
        class="filters"
    >
        <input
            type="hidden"
            name="page"
            value="orders"
        />
        <?php if ($filter['customer']): ?>
        <input
            type="hidden"
            name="customer_id"
            value="<?= $filter['customer'] ?>"
        />
        <?php endif; ?>

        <label class="grow">
            Tìm kiếm
            <input
                name="q"
                value="<?= e($filter['q']) ?>"
                maxlength="100"
                placeholder="Mã đơn/vận đơn, tên, điện thoại…"
            />
        </label>

        <label>
            Trạng thái
            <select name="status">
                <option value="">Tất cả</option>
                <?php foreach (STATUS_LABELS as $key => $label): ?>
                <option
                    value="<?= e($key) ?>"
                    <?= $filter['status'] === $key ? 'selected' : '' ?>
                >
                    <?= e($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>

        <!-- [BO_LOC_MO_RONG] details HTML đóng/mở không cần JavaScript; bộ lọc đang dùng tự mở. -->
        <details
            class="advanced-filters"
            <?= $filter['delivery'] ||
            $filter['payment'] ||
            $filter['task'] ||
            $filter['assigned'] ||
            $filter['from'] ||
            $filter['to'] ||
            ($filter['sort'] && $filter['sort'] !== 'newest')
                ? 'open'
                : '' ?>
        >
            <summary>Bộ lọc mở rộng · ngày, thanh toán, phụ trách</summary>
            <div class="advanced-grid">
                <label>
                    Hình thức nhận
                    <select name="delivery_method">
                        <option value="">Tất cả</option>
                        <?php foreach (DELIVERY_LABELS as $key => $label): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= $filter['delivery'] === $key ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Thanh toán
                    <select name="payment_status">
                        <option value="">Tất cả</option>
                        <option
                            value="paid"
                            <?= $filter['payment'] === 'paid' ? 'selected' : '' ?>
                        >
                            Đã thanh toán
                        </option>
                        <option
                            value="unpaid"
                            <?= $filter['payment'] === 'unpaid' ? 'selected' : '' ?>
                        >
                            Chưa thanh toán
                        </option>
                    </select>
                </label>

                <label>
                    Việc cần xử lý
                    <select name="task">
                        <option value="">Tất cả</option>
                        <?php foreach (
                            [
                                'overdue' => 'Quá ngày dự kiến',
                                'waiting' => 'Chờ xác nhận trên 24 giờ',
                                'unpaid' => 'Hoàn thành chưa thu',
                                'failed' => 'Giao thất bại',
                                'returning' => 'Chờ kiểm nhận hoàn',
                            ]
                            as $key => $label
                        ): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= $filter['task'] === $key ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Phụ trách
                    <select name="assigned_to">
                        <option value="0">Tất cả</option>
                        <?php foreach ($staff as $person): ?>
                        <option
                            value="<?= (int) $person['user_id'] ?>"
                            <?= $filter['assigned'] === (int) $person['user_id'] ? 'selected' : '' ?>
                        >
                            <?= e($person['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Sắp xếp
                    <select name="sort">
                        <?php foreach (
                            [
                                'newest' => 'Ngày tạo mới nhất',
                                'oldest' => 'Ngày tạo cũ nhất',
                                'amount_desc' => 'Tổng tiền giảm dần',
                                'amount_asc' => 'Tổng tiền tăng dần',
                            ]
                            as $key => $label
                        ): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= ($filter['sort'] ?: 'newest') === $key ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Từ ngày
                    <input
                        type="date"
                        name="from"
                        value="<?= e($filter['from']) ?>"
                    />
                </label>

                <label>
                    Đến ngày
                    <input
                        type="date"
                        name="to"
                        value="<?= e($filter['to']) ?>"
                    />
                </label>
            </div>
        </details>
        <button>Lọc đơn</button>
        <a
            class="button secondary"
            href="<?= e(url('orders')) ?>"
        >
            Đặt lại
        </a>
    </form>
    <?php $params = array_intersect_key(
        $_GET,
        array_flip([
            'q',
            'status',
            'delivery_method',
            'payment_status',
            'task',
            'sort',
            'customer_id',
            'assigned_to',
            'from',
            'to',
        ]),
    ); ?>
    <?php
    order_table($rows);
    pagination('orders', $total, $params);?>
</section>
<?php
}

