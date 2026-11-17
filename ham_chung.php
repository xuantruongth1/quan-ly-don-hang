<?php
declare(strict_types=1);

/**
 * HAM_CHUNG.PHP — kết nối CSDL, phiên đăng nhập, kiểm tra dữ liệu và quy tắc chung.
 * index.php nạp file này đầu tiên. Không có giao diện HTML trong file này.
 * db/query/one: PDO; e: chống XSS; csrf: chống gửi form giả; transaction: tất cả cùng thành công/thất bại.
 */
define('PROJECT_ROOT', __DIR__);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = __DIR__ . '/cau_hinh.php';
    $config = is_file($path) ? require $path : require __DIR__ . '/cau_hinh_mau.php';
    foreach (
        [
            'host' => 'DB_HOST',
            'port' => 'DB_PORT',
            'name' => 'DB_NAME',
            'user' => 'DB_USER',
            'pass' => 'DB_PASS',
        ]
        as $key => $env
    ) {
        if (getenv($env) !== false) {
            $config[$key] = getenv($env);
        }
    }
    $pdo = new PDO(
        'mysql:host=' .
            $config['host'] .
            ';port=' .
            $config['port'] .
            ';dbname=' .
            $config['name'] .
            ';charset=utf8mb4',
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}

/** [DOC_SQL] Chuẩn bị SQL và truyền tham số riêng; không nối dữ liệu người nhập trực tiếp vào câu SQL. */

function query(string $sql, array $params = []): PDOStatement {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function one(string $sql, array $params = []): ?array {
    return query($sql, $params)->fetch() ?: null;
}

/** [GHI_DONG_BO] BEGIN -> gọi hàm nghiệp vụ -> COMMIT; có lỗi thì ROLLBACK và báo lại lỗi. */

function transaction(callable $callback): mixed {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
const STATUS_LABELS = [
    'pending' => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'shipping' => 'Đang giao',
    'delivery_failed' => 'Giao thất bại',
    'returning' => 'Đang chuyển hoàn',
    'returned' => 'Đã kiểm nhận hàng hoàn',
    'lost' => 'Thất lạc — đã chốt',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy',
];
const MAX_STAFF_DISCOUNT_PERCENT = 10;
// Nhân viên giảm tối đa 10%; admin có thể giảm tới tiền hàng.
const DELIVERY_LABELS = ['delivery' => 'Giao tận nơi', 'pickup' => 'Nhận tại cửa hàng'];
const PAYMENT_LABELS = [
    'cash' => 'Tiền mặt',
    'transfer' => 'Chuyển khoản',
    'cod' => 'Thu hộ khi giao hàng',
];
const MAX_MONEY = 9999999999;

function whole_number(mixed $value, int $min, int $max, string $label): int {
    if (!is_scalar($value) || !preg_match('/^\d{1,12}$/D', (string) $value)) {
        throw new DomainException("$label phải là số nguyên hợp lệ.");
    }
    $number = (int) $value;
    if ($number < $min || $number > $max) {
        throw new DomainException("$label ngoài khoảng cho phép ({$min}–{$max}).");
    }
    return $number;
}

function money_input(mixed $value, string $label): int {
    return whole_number($value, 0, MAX_MONEY, $label);
}

function calculate_total(int $items, int $discount, int $shipping): int {
    if ($items < 0 || $discount < 0 || $shipping < 0 || $discount > $items) {
        throw new DomainException('Giảm giá phải từ 0 đến tổng tiền hàng.');
    }
    $total = $items - $discount + $shipping;
    if ($items > MAX_MONEY || $total > MAX_MONEY) {
        throw new DomainException('Giá trị đơn vượt giới hạn cho phép.');
    }
    return $total;
}

function assert_transition(
    string $from,
    string $to,
    bool $paid,
    string $reason,
    string $delivery = 'delivery',
): void {
    $allowed = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' =>
            $delivery === 'pickup' ? ['completed', 'cancelled'] : ['shipping', 'cancelled'],
        'shipping' => ['completed', 'delivery_failed'],
        'delivery_failed' => ['shipping', 'returning', 'lost'],
        'returning' => ['returned', 'lost'],
    ];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        throw new DomainException('Không thể chuyển trạng thái này. Đơn có thể đã được xử lý.');
    }
    if (in_array($to, ['cancelled', 'returning', 'returned', 'lost'], true) && $paid) {
        throw new DomainException(
            'Đơn đã thu tiền cần quy trình hoàn tiền ngoài phạm vi phiên bản này; không ' .
                'thể hủy, chuyển hoàn hoặc chốt thất lạc.',
        );
    }
    if (
        in_array($to, ['cancelled', 'delivery_failed', 'returning', 'returned', 'lost'], true) &&
        trim($reason) === ''
    ) {
        throw new DomainException(
            'Vui lòng nhập lý do hủy, giao thất bại hoặc kết quả xử lý hàng hoàn/thất lạc.',
        );
    }
}

function delivery_input(array $data): array {
    $method = text_value($data, 'delivery_method', 20);
    if (!isset(DELIVERY_LABELS[$method])) {
        throw new DomainException('Hình thức nhận hàng không hợp lệ.');
    }
    $carrier = text_value($data, 'carrier', 100, false);
    $tracking = text_value($data, 'tracking_code', 100, false);
    $date = text_value($data, 'expected_delivery_date', 10, false);
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (
            !$parsed ||
            $parsed->format('Y-m-d') !== $date ||
            $date < '2000-01-01' ||
            $date > '2099-12-31'
        ) {
            throw new DomainException('Ngày nhận dự kiến không hợp lệ (2000–2099).');
        }
    }
    if ($method === 'pickup' && ($carrier !== '' || $tracking !== '')) {
        throw new DomainException(
            'Nhận tại cửa hàng không sử dụng đơn vị vận chuyển hoặc mã vận đơn.',
        );
    }
    return [$method, $carrier ?: null, $tracking ?: null, $date ?: null];
}
date_default_timezone_set('Asia/Ho_Chi_Minh');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'self'; style-src 'self'; form-action " .
        "'self'; frame-ancestors 'none'; base-uri 'none'",
);
header('Cache-Control: no-store');

/** [HIEN_THI_AN_TOAN] Đổi ký tự đặc biệt thành HTML an toàn trước khi in nội dung người dùng nhập. */

function e(mixed $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** [TAO_LINK] Tạo URL GET có page/tham số; chỉ tạo chuỗi URL, chưa đọc/ghi CSDL. */

function url(string $page, array $params = []): string {
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

/** [SAU_KHI_LUU] Trả HTTP 303 rồi dừng PHP; trình duyệt mở trang GET mới, F5 không gửi lại POST vừa lưu. */

function redirect(string $page, array $params = []): never {
    header('Location: ' . url($page, $params), true, 303);
    exit();
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [$message, $type];
}

function csrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): void {
    echo '<input type="hidden" name="csrf" value="' . e(csrf()) . '">';
}

function check_csrf(): void {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(), $_POST['csrf'])) {
        throw new DomainException('Phiên biểu mẫu hết hạn. Vui lòng tải lại trang.');
    }
}
class LoiNhapLieu extends DomainException {
    public function __construct(public string $field, string $message) {
        parent::__construct($message);
    }
}

function text_value(array $data, string $key, int $max, bool $required = true): string {
    if (!is_scalar($data[$key] ?? '')) {
        throw new DomainException('Dữ liệu không hợp lệ.');
    }
    $value = trim((string) ($data[$key] ?? ''));
    if (($required && $value === '') || mb_strlen($value) > $max) {
        throw new LoiNhapLieu($key, "Cần nhập đầy đủ và không vượt $max ký tự.");
    }
    return $value;
}

function phone_value(array $data, string $key): string {
    $value = text_value($data, $key, 20);
    $value = preg_replace('/[ .()-]/', '', $value);
    if (str_starts_with($value, '+84')) {
        $value = '0' . substr($value, 3);
    }
    if (!preg_match('/^\+?[0-9][0-9 .()-]{6,18}$/D', $value)) {
        throw new LoiNhapLieu($key, 'Số điện thoại không hợp lệ.');
    }
    return $value;
}

function email_value(array $data): ?string {
    $value = text_value($data, 'email', 100, false);
    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new LoiNhapLieu('email', 'Thư điện tử không hợp lệ.');
    }
    return $value === '' ? null : $value;
}

function money(mixed $value): string {
    return number_format((float) $value, 0, ',', '.') . ' ₫';
}

function stamp(?string $value): string {
    return $value ? date('d/m/Y H:i', strtotime($value)) : '—';
}

function current_user(): ?array {
    static $loaded = false,
        $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (!empty($_SESSION['user_id'])) {
        if (time() - (int) ($_SESSION['last_active'] ?? 0) <= 1800) {
            $user = one('SELECT * FROM users WHERE user_id=? AND is_active=1', [
                $_SESSION['user_id'],
            ]);
        }
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        $_SESSION['last_active'] = time();
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function require_admin(): void {
    if (require_login()['role'] !== 'admin') {
        throw new DomainException('Chỉ quản trị viên được thực hiện thao tác này.');
    }
}

function is_admin(): bool {
    return (current_user()['role'] ?? '') === 'admin';
}
// Dữ liệu cũ chỉ tồn tại trong yêu cầu đang lỗi; không lưu mật khẩu vào session hoặc HTML.

function old_value(string $name, mixed $default = ''): mixed {
    $old = $GLOBALS['old_input'] ?? [];
    return array_key_exists($name, $old) && is_scalar($old[$name]) ? $old[$name] : $default;
}

function old_checked(string $name, bool $default): bool {
    return isset($GLOBALS['old_input']) ? isset($GLOBALS['old_input'][$name]) : $default;
}

function form_token(): string {
    $token = bin2hex(random_bytes(16));
    $_SESSION['form_tokens'][$token] = time();
    foreach ($_SESSION['form_tokens'] as $key => $time) {
        if (time() - $time > 3600) {
            unset($_SESSION['form_tokens'][$key]);
        }
    }
    if (count($_SESSION['form_tokens']) > 30) {
        array_shift($_SESSION['form_tokens']);
    }
    return $token;
}

function check_form_token(string $token): void {
    if (!isset($_SESSION['form_tokens'][$token])) {
        throw new DomainException('Biểu mẫu đã gửi hoặc hết hạn. Vui lòng mở lại đơn.');
    }
}

function status_badge(string $status): string {
    return '<span class="badge ' .
        e($status) .
        '">' .
        e(STATUS_LABELS[$status] ?? $status) .
        '</span>';
}

function pagination(string $page, int $total, array $params = []): void {
    $current = max(1, (int) ($_GET['p'] ?? 1));
    $pages = (int) ceil($total / 15);
    echo '<div class="pagination"><span>' . e($total) . ' kết quả</span>';
    if ($current > 1) {
        echo '<a class="button secondary" href="' .
            e(url($page, $params + ['p' => $current - 1])) .
            '">← Trước</a>';
    }
    if ($current < $pages) {
        echo '<a class="button secondary" href="' .
            e(url($page, $params + ['p' => $current + 1])) .
            '">Sau →</a>';
    }
    echo '</div>';
}

/** [MA_TU_DONG] Mã theo khóa chính tự tăng: SP001/DH001/TG001; tối thiểu 3 số, không MAX+1 nên không tranh mã. */

function ma_dinh_danh(string $prefix, int $id): string {
    if (!in_array($prefix, ['SP', 'DH', 'TG'], true) || $id < 1) {
        throw new DomainException('Không tạo được mã hợp lệ.');
    }
    return $prefix . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
}
