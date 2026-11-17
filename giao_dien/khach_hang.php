<?php
declare(strict_types=1);

/** TAB KHÁCH HÀNG: tìm và thêm/sửa hồ sơ. Đây là trang quản trị. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_KHACH] Tìm khách, thống kê mua hàng, nút Sửa/Đơn đã mua/Lưu khách. */
function customers_view(): void {
    $id = (int) ($_GET['edit'] ?? 0);
    $edit = $id ? one('SELECT * FROM customers WHERE customer_id=?', [$id]) : null;
    if ($id && !$edit) {
        throw new DomainException('Không tìm thấy khách hàng.');
    }
    page_heading('Khách hàng', 'Hồ sơ liên hệ và lịch sử mua hàng.');
    $search = text_value($_GET, 'q', 100, false);
    $p = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($p - 1) * 15;
    $where = ' WHERE full_name LIKE ? OR phone LIKE ?';
    $args = ['%' . $search . '%', '%' . $search . '%'];
    $total = (int) one('SELECT COUNT(*) AS n FROM customers' . $where, $args)['n'];
    $rows = query(
        'SELECT customers.*, (SELECT COUNT(*) FROM orders WHERE ' .
            'customer_id=customers.customer_id) AS order_count, (SELECT COUNT(*) FROM ' .
            "orders WHERE customer_id=customers.customer_id AND status='completed') AS " .
            'completed_count, (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE ' .
            "customer_id=customers.customer_id AND status='completed') AS purchased, " .
            '(SELECT MAX(created_at) FROM orders WHERE customer_id=customers.customer_id) ' .
            'AS last_order FROM customers' .
            $where .
            " ORDER BY customer_id DESC LIMIT 15 OFFSET $offset",
        $args,
    )->fetchAll();
    ?>
<div class="split">
    <section class="card">
        <form
            class="filters"
            method="get"
        >
            <input
                type="hidden"
                name="page"
                value="customers"
            />
            <input
                name="q"
                placeholder="Tìm tên hoặc số điện thoại"
                aria-label="Tìm khách hàng"
                value="<?= e($search) ?>"
                maxlength="100"
            />
            <button>Tìm kiếm</button>
        </form>
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
                    <?php
                    if (!$rows) {
                        empty_row(3);
                    }
                    foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['full_name']) ?></strong>
                            <small><?= e($row['address']) ?></small>
                            <small>
                                <?= (int) $row['order_count'] ?> đơn
                                · <?= (int) $row['completed_count'] ?> hoàn thành
                                · <?= money($row['purchased']) ?>
                            </small>
                            <small>Lần đặt gần nhất: <?= stamp($row['last_order']) ?></small>
                        </td>
                        <td>
                            <?= e($row['phone']) ?>
                            <small><?= e($row['email']) ?></small>
                        </td>
                        <td>
                            <a href="<?= e(url('customers', ['edit' => $row['customer_id']])) ?>">Sửa</a>
                            ·
                            <a href="<?= e(url('orders', ['customer_id' => $row['customer_id']])) ?>">Đơn đã mua</a>
                        </td>
                    </tr>
                    <?php endforeach;
                                        ?>
                </tbody>
            </table>
        </div>
        <?php pagination('customers', $total, ['q' => $search]); ?>
    </section>

    <section class="card">
        <h2><?= $edit ? 'Sửa khách hàng' : 'Thêm khách hàng' ?></h2>
        <form
            method="post"
            class="stack"
        >
            <?php csrf_field(); ?>
            <input
                type="hidden"
                name="action"
                value="save_customer"
            />
            <input
                type="hidden"
                name="id"
                value="<?= $id ?>"
            />
            <input
                type="hidden"
                name="version"
                value="<?= e(old_value('version', $edit['version'] ?? 1)) ?>"
            />
            <?php
            field('full_name', 'Họ và tên', $edit['full_name'] ?? '', 'text', true, 100);
            field('phone', 'Số điện thoại', $edit['phone'] ?? '', 'tel', true, 20);
            field('email', 'Thư điện tử', $edit['email'] ?? '', 'email', false, 100);
            field('address', 'Địa chỉ', $edit['address'] ?? '', 'text', false, 255);
            ?>
            <!-- [NUT_LUU_KHACH] POST save_customer: INSERT/UPDATE customers; kiểm tra version khi sửa. -->
            <button>Lưu khách hàng</button>
            <?php if ($edit): ?>
            <a href="<?= e(url('customers')) ?>">Hủy chỉnh sửa</a>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php
}

