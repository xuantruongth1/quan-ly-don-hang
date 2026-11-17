<?php
declare(strict_types=1);

/** BÁO CÁO: lấy dữ liệu cùng định nghĩa cho màn hình và CSV. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Dùng chung cho màn hình và CSV: cùng khoảng ngày, cùng định nghĩa chỉ tiêu. */

function lay_du_lieu_bao_cao(array $input): array {
    $from = text_value($input, 'from', 10, false) ?: date('Y-m-01');
    $to = text_value($input, 'to', 10, false) ?: date('Y-m-d');
    foreach ([$from, $to] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (
            !$parsed ||
            $parsed->format('Y-m-d') !== $date ||
            $date < '2000-01-01' ||
            $date > '2099-12-31'
        ) {
            throw new DomainException('Ngày báo cáo phải hợp lệ trong khoảng 2000–2099.');
        }
    }
    if ($from > $to) {
        throw new DomainException('Ngày bắt đầu phải trước ngày kết thúc.');
    }
    return transaction(function () use ($from, $to) {
        $dates = [$from . ' 00:00:00', $to];
        $sales = one(
            'SELECT COUNT(*) AS n,COALESCE(SUM(total_amount),0) AS amount FROM orders ' .
                "WHERE status='completed' AND completed_at>=? AND " .
                'completed_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
        $paid = one(
            "SELECT COUNT(*) AS n,COALESCE(SUM(CASE WHEN payment_method='cod' THEN " .
                'COALESCE(cod_received_amount,total_amount) ELSE total_amount END),0) AS ' .
                "amount FROM orders WHERE payment_status='paid' AND status<>'cancelled' AND " .
                'paid_at>=? AND paid_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
        $daily = query(
            'SELECT DATE(completed_at) AS day,COUNT(*) AS n,SUM(total_amount) AS amount ' .
                "FROM orders WHERE status='completed' AND completed_at>=? AND " .
                'completed_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(completed_at) ORDER BY ' .
                'day DESC',
            $dates,
        )->fetchAll();
        $top = query(
            'SELECT p.sku,p.product_name,SUM(i.quantity) AS quantity,SUM(i.subtotal) AS ' .
                'amount FROM order_items i JOIN orders o ON o.order_id=i.order_id JOIN ' .
                "products p ON p.product_id=i.product_id WHERE o.status='completed' AND " .
                'o.completed_at>=? AND o.completed_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY ' .
                'p.product_id,p.sku,p.product_name ORDER BY quantity DESC,p.product_id LIMIT ' .
                '10',
            $dates,
        )->fetchAll();
        $operations = query(
            'SELECT status,COUNT(*) AS n FROM orders WHERE created_at>=? AND ' .
                'created_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY status',
            $dates,
        )->fetchAll();
        $outstanding = one(
            'SELECT COUNT(*) AS n,COALESCE(SUM(total_amount),0) AS amount FROM orders ' .
                "WHERE status='completed' AND payment_status='unpaid' AND completed_at>=? AND " .
                'completed_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
        $events = one(
            'SELECT COUNT(*) AS attempts,COUNT(DISTINCT order_id) AS orders FROM ' .
                "order_status_history WHERE event_type='status' AND " .
                "to_status='delivery_failed' AND created_at>=? AND " .
                'created_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
        $retry = one(
            "SELECT COUNT(*) AS n FROM order_status_history WHERE event_type='status' AND " .
                "from_status='delivery_failed' AND to_status='shipping' AND created_at>=? AND " .
                'created_at<DATE_ADD(?,INTERVAL 1 DAY)',
            $dates,
        );
        return compact(
            'from',
            'to',
            'dates',
            'sales',
            'paid',
            'daily',
            'top',
            'operations',
            'outstanding',
            'events',
            'retry',
        );
    });
}

/** [NUT_XUAT_BAO_CAO] CSV UTF-8 mở bằng Excel; chỉ xuất chỉ tiêu, không xuất nội bộ. */

function xuat_bao_cao_csv(): never {
    require_login();
    $r = lay_du_lieu_bao_cao($_GET);
    // Kiểm tra ngày và đọc xong trước khi gửi header tải file.
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="bao_cao_' .
            $r['from'] .
            '_' .
            $r['to'] .
            '.csv"',
    );
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $write = static function (array $cells) use ($out): void {
        $cells = array_map(
            static fn($cell) => preg_match('/^\s*[=+@-]/u', (string) $cell)
                ? "'" . $cell
                : (string) ($cell ?? ''),
            $cells,
        );
        fputcsv($out, $cells);
    };
    $write(['Nhóm dữ liệu', 'Chỉ tiêu / ngày / sản phẩm', 'Số lượng', 'Giá trị (VND)', 'Ghi chú']);
    $write([
        'Thời gian',
        'Từ ' . $r['from'] . ' đến ' . $r['to'],
        '',
        '',
        'Các ngày cuối được tính đủ ngày',
    ]);
    $write([
        'Tổng hợp',
        'Đơn hoàn thành',
        $r['sales']['n'],
        $r['sales']['amount'],
        'Theo ngày hoàn thành, gồm phí thu khách và đã trừ giảm giá',
    ]);
    $write([
        'Tổng hợp',
        'Tiền thực về',
        $r['paid']['n'],
        $r['paid']['amount'],
        'Theo ngày thu, tiền thu hộ đã trừ phí khấu trừ',
    ]);
    $write([
        'Tổng hợp',
        'Hoàn thành nhưng chưa thu',
        $r['outstanding']['n'],
        $r['outstanding']['amount'],
        'Theo ngày hoàn thành',
    ]);
    $write(['Giao vận', 'Số lần giao thất bại', $r['events']['attempts'], '', 'Theo ngày sự kiện']);
    $write([
        'Giao vận',
        'Số đơn có giao thất bại',
        $r['events']['orders'],
        '',
        'Mỗi đơn chỉ đếm một lần trong kỳ',
    ]);
    $write(['Giao vận', 'Số lần giao lại', $r['retry']['n'], '', 'Theo ngày sự kiện']);
    foreach ($r['operations'] as $row) {
        $write([
            'Trạng thái hiện tại',
            STATUS_LABELS[$row['status']] ?? $row['status'],
            $row['n'],
            '',
            'Các đơn tạo trong kỳ',
        ]);
    }
    foreach ($r['daily'] as $row) {
        $write([
            'Hoàn thành theo ngày',
            $row['day'],
            $row['n'],
            $row['amount'],
            'Theo ngày hoàn thành',
        ]);
    }
    foreach ($r['top'] as $row) {
        $write([
            '10 sản phẩm bán chạy',
            $row['product_name'],
            $row['quantity'],
            $row['amount'],
            'Mã sản phẩm ' . $row['sku'] . '; tiền hàng trước giảm giá cấp đơn và phí giao',
        ]);
    }
    fclose($out);
    exit();
}
