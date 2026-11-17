<?php
declare(strict_types=1);

/** TAB TỔNG QUAN. Tìm nút: Tạo đơn hàng, Xem tất cả. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_TONG_QUAN] Các số thống kê, biểu đồ và đơn mới nhất. */
function dashboard_view(): void {
    // [NUT_MO_TAO_DON] Link GET page=order_form chỉ mở form, chưa tạo bản ghi.
    page_heading(
        'Tổng quan cửa hàng',
        'Theo dõi đơn hàng và những việc cần xử lý hôm nay.',
        url('order_form'),
        '+ Tạo đơn hàng',
    );
    slider_sach();
    $stats = one(
        "SELECT COUNT(*) AS total, SUM(status='pending') AS pending, " .
            "SUM(status='shipping') AS shipping, COALESCE(SUM(CASE WHEN " .
            "status='completed' AND DATE(completed_at)=CURDATE() THEN total_amount ELSE 0 " .
            'END),0) AS revenue FROM orders',
    );
    ?>
<div class="stats">
    <article class="stat">
        <span>Tổng đơn hàng</span>
        <strong><?= (int) $stats['total'] ?></strong>
        <small>Toàn bộ cửa hàng</small>
    </article>
    <article class="stat">
        <span>Chờ xác nhận</span>
        <strong><?= (int) $stats['pending'] ?></strong>
        <small>Cần kiểm tra và chốt đơn</small>
    </article>
    <article class="stat">
        <span>Đang giao</span>
        <strong><?= (int) $stats['shipping'] ?></strong>
        <small>Đang trên đường đến khách</small>
    </article>
    <article class="stat accent">
        <span>Giá trị hoàn thành hôm nay</span>
        <strong><?= money($stats['revenue']) ?></strong>
        <small>Theo thời điểm hoàn thành</small>
    </article>
</div>
<?php
$chartTo = date('Y-m-d');
$chartFrom = date('Y-m-d', strtotime('-13 days'));
$newOrders = query(
    'SELECT DATE(created_at) AS day,COUNT(*) AS n FROM orders WHERE created_at>=? ' .
        'AND created_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(created_at)',
    [$chartFrom . ' 00:00:00', $chartTo],
)->fetchAll();
bieu_do_don_view(
    $newOrders,
    $chartFrom,
    $chartTo,
    'Đơn hàng mới trong 14 ngày',
    'Theo ngày tạo, gồm mọi trạng thái; rê chuột vào cột để xem số đơn.',
);
?>
<section class="card">
    <div class="section-title">
        <h2>Đơn hàng mới nhất</h2>
        <a href="<?= e(url('orders')) ?>">Xem tất cả →</a>
    </div>
    <?php order_table(
        query(
            'SELECT o.*,c.full_name FROM orders o JOIN customers c ON ' .
                'c.customer_id=o.customer_id ORDER BY o.created_at DESC,o.order_id DESC LIMIT ' .
                '8',
        )->fetchAll(),
    ); ?>
</section>

<section class="card">
    <h2>Sản phẩm sắp hết hàng</h2>
    <p class="muted">Sản phẩm đang bán có tồn kho từ 5 trở xuống.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Mã sản phẩm</th>
                    <th>Sản phẩm</th>
                    <th>Còn bán</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rows = query(
                    'SELECT * FROM products WHERE is_active=1 AND stock_quantity<=5 ORDER BY ' .
                        'stock_quantity,product_id LIMIT 10',
                )->fetchAll();
                if (!$rows) {
                    empty_row(3, 'Chưa có sản phẩm cần bổ sung tồn.');
                }
                foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['sku']) ?></td>
                    <td><?= e($row['product_name']) ?></td>
                    <td><strong><?= (int) $row['stock_quantity'] ?></strong></td>
                </tr>
                <?php endforeach;?>
            </tbody>
        </table>
    </div>
</section>
<?php
}

