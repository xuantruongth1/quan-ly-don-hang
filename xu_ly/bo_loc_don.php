<?php
declare(strict_types=1);

/** TÌM ĐƠN: điều kiện lọc dùng chung và luồng tải danh sách CSV hiện có. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Bộ lọc dùng CHUNG cho bảng đơn và CSV để kết quả xuất không lệch với màn hình. */

function bo_loc_don(array $input): array {
    $q = text_value($input, 'q', 100, false);
    $status = text_value($input, 'status', 30, false);
    $delivery = text_value($input, 'delivery_method', 20, false);
    $payment = text_value($input, 'payment_status', 20, false);
    $task = text_value($input, 'task', 20, false);
    $sort = text_value($input, 'sort', 20, false);
    $customer = whole_number($input['customer_id'] ?? '0', 0, 2147483647, 'Khách hàng');
    $assigned = whole_number($input['assigned_to'] ?? '0', 0, 2147483647, 'Người phụ trách');
    $from = text_value($input, 'from', 10, false);
    $to = text_value($input, 'to', 10, false);
    foreach ([$from, $to] as $date) {
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new DomainException('Ngày lọc không hợp lệ.');
            }
        }
    }
    if ($from && $to && $from > $to) {
        throw new DomainException('Ngày bắt đầu phải trước ngày kết thúc.');
    }
    $where = ' WHERE 1=1';
    $args = [];
    if ($q !== '') {
        $where .=
            ' AND (o.order_code LIKE ? OR c.full_name LIKE ? OR o.recipient_name LIKE ? ' .
            'OR c.phone LIKE ? OR o.recipient_phone LIKE ? OR o.tracking_code LIKE ?)';
        $args = array_fill(0, 6, '%' . $q . '%');
    }
    if (isset(STATUS_LABELS[$status])) {
        $where .= ' AND o.status=?';
        $args[] = $status;
    }
    if (isset(DELIVERY_LABELS[$delivery])) {
        $where .= ' AND o.delivery_method=?';
        $args[] = $delivery;
    }
    if (in_array($payment, ['unpaid', 'paid'], true)) {
        $where .= ' AND o.payment_status=?';
        $args[] = $payment;
    }
    if ($customer) {
        $where .= ' AND o.customer_id=?';
        $args[] = $customer;
    }
    if ($assigned) {
        $where .= ' AND o.assigned_to=?';
        $args[] = $assigned;
    }
    if ($from) {
        $where .= ' AND o.created_at>=?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to) {
        $where .= ' AND o.created_at<DATE_ADD(?,INTERVAL 1 DAY)';
        $args[] = $to;
    }
    $tasks = [
        'overdue' =>
            "o.expected_delivery_date<CURDATE() AND o.status IN ('confirmed','shipping','delivery_failed')",
        'waiting' => "o.status='pending' AND o.created_at<DATE_SUB(NOW(),INTERVAL 24 HOUR)",
        'unpaid' => "o.status='completed' AND o.payment_status='unpaid'",
        'failed' => "o.status='delivery_failed'",
        'returning' => "o.status='returning'",
    ];
    if (isset($tasks[$task])) {
        $where .= ' AND (' . $tasks[$task] . ')';
    }
    $sorts = [
        'newest' => 'o.created_at DESC,o.order_id DESC',
        'oldest' => 'o.created_at ASC,o.order_id ASC',
        'amount_desc' => 'o.total_amount DESC,o.order_id DESC',
        'amount_asc' => 'o.total_amount ASC,o.order_id DESC',
    ];
    return [
        $where,
        $args,
        $sorts[$sort] ?? $sorts['newest'],
        compact(
            'q',
            'status',
            'delivery',
            'payment',
            'task',
            'sort',
            'customer',
            'assigned',
            'from',
            'to',
        ),
    ];
}

/** [NUT_XUAT_CSV] Xuất tối đa 10.000 đơn theo bộ lọc, không xuất ghi chú nội bộ. */

function xuat_csv(): never {
    require_login();
    [$where, $args, $sort] = bo_loc_don($_GET);
    $rows = query(
        'SELECT o.*,c.full_name FROM orders o JOIN customers c ON c.customer_id=o.customer_id' .
            $where .
            ' ORDER BY ' .
            $sort .
            ' LIMIT 10000',
        $args,
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="don_hang_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Mã đơn',
        'Ngày tạo',
        'Khách hàng',
        'Điện thoại nhận',
        'Tổng thanh toán',
        'Trạng thái',
        'Thanh toán',
        'Đơn vị giao',
        'Vận đơn',
    ]);
    foreach ($rows as $row) {
        $cells = [
            $row['order_code'],
            $row['created_at'],
            $row['full_name'],
            $row['recipient_phone'],
            $row['total_amount'],
            STATUS_LABELS[$row['status']],
            $row['payment_status'] === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán',
            $row['carrier'],
            $row['tracking_code'],
        ];
        // Ngăn nội dung bắt đầu bằng =,+,-,@ bị Excel coi là công thức.
        $cells = array_map(
            static fn($value) => preg_match('/^\s*[=+@-]/u', (string) $value)
                ? "'" . $value
                : (string) ($value ?? ''),
            $cells,
        );
        fputcsv($out, $cells);
    }
    fclose($out);
    exit();
}
