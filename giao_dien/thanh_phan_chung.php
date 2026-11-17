<?php
declare(strict_types=1);

/** Các thành phần giao diện dùng chung: tiêu đề, ô nhập, ngày nhanh và biểu đồ. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Khối tiêu đề và nút/link tạo mới chung.
 * $link là URL GET: link chỉ điều hướng. Các nút Lưu trong form mới gửi POST để ghi.
 */
function page_heading(
    string $title,
    string $subtitle = '',
    ?string $link = null,
    string $label = '+ Tạo mới',
): void {
    echo '<div class="page-heading"><div><h1>' .
        e($title) .
        '</h1><p>' .
        e($subtitle) .
        '</p></div>';
    if ($link) {
        echo '<a class="button" href="' . e($link) . '">' . e($label) . '</a>';
    }
    echo '</div>';
}

/** Tạo ô nhập và hiện lại dữ liệu khi lỗi; không áp dụng cho mật khẩu. */
function field(
    string $name,
    string $label,
    mixed $value = '',
    string $type = 'text',
    bool $required = true,
    int $max = 255,
): void {
    $value = old_value($name, $value);
    echo '<label>' .
        e($label) .
        '<input name="' .
        e($name) .
        '" type="' .
        e($type) .
        '" value="' .
        e($value) .
        '" ' .
        ($required ? 'required ' : '') .
        'maxlength="' .
        $max .
        '"' .
        ($type === 'number' ? ' min="0" step="1"' : '') .
        '></label>';
    if (($GLOBALS['error_field'] ?? '') === $name) {
        echo '<small class="field-error" role="alert">' .
            e($GLOBALS['form_error'] ?? 'Dữ liệu chưa hợp lệ.') .
            '</small>';
    }
}

function empty_row(int $columns, string $text = 'Chưa có dữ liệu.'): void {
    echo '<tr><td class="empty" colspan="' . $columns . '">' . e($text) . '</td></tr>';
}

/** [NUT_NGAY_NHANH] Link GET giữ bộ lọc khác, bỏ số trang; không ghi CSDL. */
function ngay_nhanh_view(string $page, array $params = []): void {
    unset($params['page'], $params['p']);
    $today = new DateTimeImmutable('today');
    $ranges = [
        'Hôm nay' => [$today, $today],
        '7 ngày' => [$today->modify('-6 days'), $today],
        '30 ngày' => [$today->modify('-29 days'), $today],
        'Tháng này' => [$today->modify('first day of this month'), $today],
        'Tháng trước' => [
            $today->modify('first day of last month'),
            $today->modify('last day of last month'),
        ],
    ];
    echo '<nav class="date-shortcuts no-print" aria-label="Khoảng ngày nhanh">';
    foreach ($ranges as $label => [$start, $end]) {
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        $active = ($params['from'] ?? '') === $from && ($params['to'] ?? '') === $to;
        echo '<a class="chip ' .
            ($active ? 'selected' : '') .
            '" ' .
            ($active ? 'aria-current="true" ' : '') .
            'href="' .
            e(url($page, array_replace($params, ['from' => $from, 'to' => $to]))) .
            '">' .
            e($label) .
            '</a>';
    }
    if ($page === 'orders') {
        unset($params['from'], $params['to']);
        echo '<a class="chip" href="' . e(url($page, $params)) . '">Mọi ngày</a>';
    }
    echo '</nav>';
}

/** [BIEU_DO_DON] SVG do PHP sinh; điền cả ngày 0, tối đa 30 ngày, không dùng JS. */
function bieu_do_don_view(
    array $rows,
    string $from,
    string $to,
    string $title,
    string $hint,
): void {
    $end = new DateTimeImmutable($to);
    $start = new DateTimeImmutable($from);
    $start = $start < $end->modify('-29 days') ? $end->modify('-29 days') : $start;
    $byDay = [];
    foreach ($rows as $row) {
        $byDay[$row['day']] = (int) $row['n'];
    }
    $days = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $days[$day->format('Y-m-d')] = $byDay[$day->format('Y-m-d')] ?? 0;
    }
    $peak = max(1, ...array_values($days));
    $count = count($days);
    $step = 820 / max(1, $count);
    $sum = array_sum($days);
    echo '<section class="card chart-card"><div class="section-title"><div><h2>' .
        e($title) .
        '</h2><p class="hint">' .
        e($hint) .
        '</p></div><span class="chart-total">' .
        $sum .
        ' đơn</span></div>';
    echo '<svg class="order-chart" viewBox="0 0 900 220" role="img" aria-label="' .
        e(
            $title .
                ': ' .
                $sum .
                ' đơn từ ' .
                $start->format('d/m/Y') .
                ' đến ' .
                $end->format('d/m/Y'),
        ) .
        '">';
    foreach ([0, 0.5, 1] as $ratio) {
        $y = 170 - 140 * $ratio;
        echo '<line class="chart-grid" x1="45" x2="875" y1="' .
            $y .
            '" y2="' .
            $y .
            '"/><text class="chart-label" x="35" y="' .
            ($y + 4) .
            '" text-anchor="end">' .
            e((string) round($peak * $ratio, 1)) .
            '</text>';
    }
    $i = 0;
    foreach ($days as $date => $n) {
        $x = 50 + $i * $step;
        $height = (140 * $n) / $peak;
        $width = max(4, $step * 0.55);
        echo '<rect class="chart-bar" x="' .
            $x .
            '" y="' .
            (170 - $height) .
            '" width="' .
            $width .
            '" height="' .
            $height .
            '" rx="3"><title>' .
            e(date('d/m/Y', strtotime($date)) . ': ' . $n . ' đơn') .
            '</title></rect>';
        if ($i === 0 || $i === $count - 1 || $i % max(1, (int) ceil($count / 7)) === 0) {
            echo '<text class="chart-label" text-anchor="middle" x="' .
                ($x + $width / 2) .
                '" y="194">' .
                e(date('d/m', strtotime($date))) .
                '</text>';
        }
        $i++;
    }
    echo '</svg><details class="chart-data"><summary>Xem số liệu biểu đồ</summary><div ' .
        'class="table-wrap"><table><thead><tr><th>Ngày</th><th>Số ' .
        'đơn</th></tr></thead><tbody>';
    foreach ($days as $date => $n) {
        echo '<tr><td>' . e(date('d/m/Y', strtotime($date))) . '</td><td>' . $n . '</td></tr>';
    }
    echo '</tbody></table></div></details></section>';
}
