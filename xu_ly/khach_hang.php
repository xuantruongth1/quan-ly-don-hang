<?php
declare(strict_types=1);

/** KHÁCH HÀNG: xử lý thêm/sửa hồ sơ quản trị. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Nút Lưu khách: kiểm hồ sơ rồi thêm/sửa customers. */
function xu_ly_luu_khach(): void {
    $id = whole_number($_POST['id'] ?? '0', 0, 2147483647, 'Mã khách');
    $name = text_value($_POST, 'full_name', 100);
    $phone = phone_value($_POST, 'phone');
    $email = email_value($_POST);
    $address = text_value($_POST, 'address', 255, false);
    $duplicate = one(
        'SELECT customer_id FROM customers WHERE ' .
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' " .
            "',''),'.',''),'-',''),'(',''),')','') IN (?,?) AND customer_id<>?",
        [$phone, str_starts_with($phone, '0') ? '+84' . substr($phone, 1) : $phone, $id],
    );
    if ($id) {
        if (!one('SELECT customer_id FROM customers WHERE customer_id=?', [$id])) {
            throw new DomainException('Khách không tồn tại.');
        }
        $version = whole_number($_POST['version'] ?? '', 1, 2147483647, 'Phiên bản khách');
        $changed = query(
            'UPDATE customers SET full_name=?,phone=?,email=?,address=?,version=version+1 ' .
                'WHERE customer_id=? AND version=?',
            [$name, $phone, $email, $address ?: null, $id, $version],
        );
        if (!$changed->rowCount()) {
            throw new DomainException('Hồ sơ khách đã được sửa ở tab khác. Tải lại trước khi lưu.');
        }
    } else {
        query('INSERT INTO customers(full_name,phone,email,address) VALUES(?,?,?,?)', [
            $name,
            $phone,
            $email,
            $address ?: null,
        ]);
    }
    flash(
        $duplicate
            ? 'Đã lưu. Lưu ý: số điện thoại này cũng nằm trong một hồ sơ khách khác.'
            : 'Đã lưu khách hàng.',
        $duplicate ? 'warning' : 'success',
    );
    redirect('customers');
}
