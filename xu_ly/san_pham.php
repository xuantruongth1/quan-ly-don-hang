<?php
declare(strict_types=1);

/** SẢN PHẨM: lưu ảnh, đọc ảnh và xử lý lưu sách. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** Ảnh lưu ngoài public; chỉ chấp nhận JPEG/PNG/WebP thật, tối đa 2 MB. */

function luu_anh_san_pham(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new DomainException('Ảnh cần tải thành công và không quá 2 MB.');
    }
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Tệp tải lên không hợp lệ.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || !$size || $size[0] * $size[1] > 16000000) {
        throw new DomainException('Chỉ nhận JPEG/PNG/WebP hợp lệ, tối đa 16 triệu điểm ảnh.');
    }
    $dir = PROJECT_ROOT . '/anh_san_pham';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new DomainException('Không tạo được thư mục ảnh.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new DomainException('Không lưu được ảnh.');
    }
    return $name;
}

/** Đọc ảnh theo product_id; không nhận đường dẫn tùy ý từ URL. */

function hien_anh(): never {
    require_login();
    $id = whole_number($_GET['id'] ?? '', 1, 2147483647, 'Mã sản phẩm');
    $product = one('SELECT image FROM products WHERE product_id=?', [$id]);
    $name = $product['image'] ?? '';
    if (
        !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $name) ||
        !is_file(PROJECT_ROOT . '/anh_san_pham/' . $name)
    ) {
        http_response_code(404);
        exit();
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($name, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . filesize(PROJECT_ROOT . '/anh_san_pham/' . $name));
    readfile(PROJECT_ROOT . '/anh_san_pham/' . $name);
    exit();
}

/** Nút Lưu sản phẩm: kiểm ảnh, giá, tồn rồi thêm/sửa products. */
function xu_ly_luu_san_pham(): void {
    require_admin();
    $id = whole_number($_POST['id'] ?? '0', 0, 2147483647, 'Mã sản phẩm');
    // [MA_SACH_TU_DONG] Không tin mã từ form; mã do ID sản phẩm quyết định.
    $sku = $id ? ma_dinh_danh('SP', $id) : 'T-' . bin2hex(random_bytes(10));
    $name = text_value($_POST, 'product_name', 150);
    $price = money_input($_POST['price'] ?? '', 'Giá bán');
    $stock = whole_number($_POST['stock_quantity'] ?? '', 0, 1000000000, 'Tồn kho');
    $description = text_value($_POST, 'description', 3000, false);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $image = luu_anh_san_pham($_FILES['image_file'] ?? []);
    try {
        transaction(function () use (
            $id,
            $sku,
            $name,
            $price,
            $stock,
            $description,
            $active,
            $image,
        ) {
            if ($id) {
                $existing = one('SELECT * FROM products WHERE product_id=? FOR UPDATE', [$id]);
                if (!$existing) {
                    throw new DomainException('Sản phẩm không tồn tại.');
                }
                if ((string) $existing['stock_quantity'] !== ($_POST['original_stock'] ?? '')) {
                    throw new DomainException(
                        'Tồn kho đã thay đổi. Tải lại sản phẩm trước khi lưu.',
                    );
                }
                if ((int) $existing['version'] !== (int) ($_POST['version'] ?? 0)) {
                    throw new DomainException(
                        'Sản phẩm đã được cập nhật ở tab khác. Tải lại trước khi lưu.',
                    );
                }
                query(
                    'UPDATE products SET sku=?,product_name=?,price=?,stock_quantity=?,' .
                        'description=?,is_active=?,image=?,version=version+1 WHERE product_id=?',
                    [
                        $sku,
                        $name,
                        $price,
                        $stock,
                        $description,
                        $active,
                        $image ?? $existing['image'],
                        $id,
                    ],
                );
            } else {
                query(
                    'INSERT INTO products(sku,product_name,price,stock_quantity,description,' .
                        'is_active,image) VALUES(?,?,?,?,?,?,?)',
                    [$sku, $name, $price, $stock, $description, $active, $image],
                );
                $newId = (int) db()->lastInsertId();
                query('UPDATE products SET sku=? WHERE product_id=?', [
                    ma_dinh_danh('SP', $newId),
                    $newId,
                ]);
            }
        });
    } catch (Throwable $error) {
        if ($image) {
            unlink(PROJECT_ROOT . '/anh_san_pham/' . $image);
        }
        throw $error;
    }
    flash('Đã lưu sản phẩm.');
    redirect('products');
}
