<?php
declare(strict_types=1);

/** TAB BÁO CÁO: lọc theo ngày và Xuất báo cáo CSV. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_BAO_CAO] Doanh số theo ngày hoàn thành, tiền thực về theo ngày thu. */
function reports_view(): void {
    // [MAN_HINH_BAO_CAO] Dữ liệu dùng chung với nút xuất CSV, tránh lệch cách tính.
    $report = lay_du_lieu_bao_cao($_GET);
    extract($report, EXTR_SKIP);
    page_heading('Báo cáo bán hàng');
    ?>

<section class="card">
    <form
        class="filters no-print"
        method="get"
    >
        <label>
            Từ ngày
            <input
                type="date"
                name="from"
                value="<?= e($from) ?>"
                required
            />
        </label>
        <label>
            Đến ngày
            <input
                type="date"
                name="to"
                value="<?= e($to) ?>"
                required
            />
        </label>
        <!-- [NUT_XUAT_BAO_CAO] GET report_csv dùng chính ngày đang nhập; xuất CSV mở bằng Excel. -->
        <!-- [NUT_XEM_BAO_CAO] GET from/to -> lay_du_lieu_bao_cao(); chỉ đọc để thống kê. -->
        <button
            name="page"
            value="reports"
        >
            Xem báo cáo
        </button>
        <button
            name="page"
            value="report_csv"
            class="secondary"
        >
            Xuất báo cáo CSV
        </button>
        <p class="hint">CSV gồm tổng hợp, theo ngày, sản phẩm bán chạy, trạng thái và giao vận.</p>
    </form>
</section>
<div class="stats three">
    <article class="stat">
        <span>Đơn hoàn thành</span>
        <strong><?= (int) $sales['n'] ?></strong>
        <small>Theo ngày hoàn thành</small>
    </article>
    <article class="stat accent">
        <span>Giá trị đơn hoàn thành</span>
        <strong><?= money($sales['amount']) ?></strong>
        <small>Đã trừ giảm giá, gồm phí giao hàng</small>
    </article>
    <article class="stat">
        <span>Tiền thực về trong kỳ</span>
        <strong><?= money($paid['amount']) ?></strong>
        <small><?= (int) $paid['n'] ?> đơn · theo ngày thu tiền</small>
    </article>
</div>

<?php bieu_do_don_view(
    $daily,
    $from,
    $to,
    'Đơn hoàn thành theo ngày',
    'Tối đa 30 ngày cuối của kỳ lọc; theo ngày hoàn thành. Bảng phía dưới vẫn gồm cả kỳ.',
); ?>
<section class="card">
    <h2>Tình hình xử lý đơn tạo trong kỳ</h2>
    <p class="hint">
        Trạng thái hiện tại của các đơn có ngày tạo trong khoảng lọc; không phải số sự kiện giao
        thất bại phát sinh trong kỳ.
    </p>
    <div class="status-summary">
        <?php
        foreach ($operations as $row): ?>
        <span>
            <?= status_badge($row['status']) ?>
            <strong><?= (int) $row['n'] ?></strong>
        </span>
        <?php endforeach;
        if (!$operations): ?>
        <p class="muted">Chưa có đơn trong kỳ.</p>
        <?php endif;
                ?>
    </div>
    <p>
        Đơn hoàn thành trong kỳ nhưng chưa thu:
        <strong><?= (int) $outstanding['n'] ?> đơn · <?= money($outstanding['amount']) ?></strong>
    </p>
</section>
<?php hien_hieu_qua_giao($dates, $events, $retry); ?>
<div class="split equal">
    <section class="card">
        <h2>Giá trị hoàn thành theo ngày</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Số đơn</th>
                        <th>Giá trị</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!$daily) {
                        empty_row(3);
                    }
                    foreach ($daily as $row): ?>
                    <tr>
                        <td><?= e(date('d/m/Y', strtotime($row['day']))) ?></td>
                        <td><?= (int) $row['n'] ?></td>
                        <td><?= money($row['amount']) ?></td>
                    </tr>
                    <?php endforeach;
                                        ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="card">
        <h2>10 sản phẩm bán chạy</h2>
        <p class="hint">Tiền hàng trước giảm giá cấp đơn và phí vận chuyển.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Đã bán</th>
                        <th>Tiền hàng</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!$top) {
                        empty_row(3);
                    }
                    foreach ($top as $row): ?>
                    <tr>
                        <td>
                            <?= e($row['product_name']) ?>
                            <small><?= e($row['sku']) ?></small>
                        </td>
                        <td><?= (int) $row['quantity'] ?></td>
                        <td><?= money($row['amount']) ?></td>
                    </tr>
                    <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
}

/** Tổng hợp theo bảng lịch sử: đếm riêng số lần thất bại và số đơn có thất bại. */
function hien_hieu_qua_giao(array $dates, ?array $stats = null, ?array $retry = null): void {
    $stats =
        $stats ??
        one(
            'SELECT COUNT(*) AS attempts,COUNT(DISTINCT order_id) AS orders FROM ' .
                "order_status_history WHERE event_type='status' AND " .
                "to_status='delivery_failed' AND created_at>=? AND " .
                'created_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
    $retry =
        $retry ??
        one(
            "SELECT COUNT(*) AS n FROM order_status_history WHERE event_type='status' AND " .
                "from_status='delivery_failed' AND to_status='shipping' AND created_at>=? AND " .
                'created_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
    echo '<section class="card"><h2>Sự kiện giao vận phát sinh trong kỳ</h2><p><strong>' .
        (int) $stats['attempts'] .
        ' lần</strong> giao thất bại trên <strong>' .
        (int) $stats['orders'] .
        ' đơn</strong>; <strong>' .
        (int) $retry['n'] .
        (' lần</strong> bàn giao để giao lại.</p><p class="hint">Đếm theo thời điểm sự ' .
            'kiện trong lịch sử, khác với trạng thái hiện tại của đơn tạo trong ' .
            'kỳ.</p></section>');
}

