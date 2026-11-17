<?php
declare(strict_types=1);

/** TAB SẢN PHẨM: tìm, thêm/sửa sách và tải ảnh. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_SAN_PHAM] Tìm/lọc sản phẩm, tải ảnh, lưu và ngừng bán. */
function products_view(): void {
    $id = (int) ($_GET['edit'] ?? 0);
    $edit = $id ? one('SELECT * FROM products WHERE product_id=?', [$id]) : null;
    if ($id && !$edit) {
        throw new DomainException('Không tìm thấy sản phẩm.');
    }
    page_heading('Danh mục sách', 'Các đầu sách, giá bán minh họa và số lượng còn bán.');
    $search = text_value($_GET, 'q', 100, false);
    $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    $p = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($p - 1) * 15;
    $where = ' WHERE (product_name LIKE ? OR sku LIKE ?)';
    $args = ['%' . $search . '%', '%' . $search . '%'];
    if (in_array($state, ['0', '1'], true)) {
        $where .= ' AND is_active=?';
        $args[] = $state;
    }
    $total = (int) one('SELECT COUNT(*) AS n FROM products' . $where, $args)['n'];
    $rows = query(
        'SELECT * FROM products' . $where . " ORDER BY product_id ASC LIMIT 15 OFFSET $offset",
        $args,
    )->fetchAll();
    ?>
<div class="<?= is_admin() ? 'split' : 'single' ?>">
    <section class="card">
        <form
            class="filters"
            method="get"
        >
            <input
                type="hidden"
                name="page"
                value="products"
            />
            <input
                name="q"
                placeholder="Tìm tên sách hoặc mã SP001"
                aria-label="Tìm sản phẩm"
                value="<?= e($search) ?>"
                maxlength="100"
            />
            <select
                name="state"
                aria-label="Trạng thái sản phẩm"
            >
                <option value="">Tất cả trạng thái</option>
                <option
                    value="1"
                    <?= $state === '1' ? 'selected' : '' ?>
                >
                    Đang bán
                </option>
                <option
                    value="0"
                    <?= $state === '0' ? 'selected' : '' ?>
                >
                    Ngừng bán
                </option>
            </select>
            <button>Lọc</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sách</th>
                        <th>Giá bán</th>
                        <th>Tồn</th>
                        <th>Trạng thái</th>
                        <?php if (is_admin()): ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!$rows) {
                        empty_row(is_admin() ? 5 : 4);
                    }
                    foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <?php if ($row['image']): ?>
                            <img
                                class="product-thumb"
                                src="<?= e(url('product_image', ['id' => $row['product_id']])) ?>"
                                alt="Ảnh <?= e($row['product_name']) ?>"
                                loading="lazy"
                            />
                            <?php endif; ?>
                            <strong><?= e($row['product_name']) ?></strong>
                            <small><?= e($row['sku']) ?></small>
                        </td>
                        <td><?= money($row['price']) ?></td>
                        <td><?= (int) $row['stock_quantity'] ?></td>
                        <td>
                            <span class="badge <?= $row['is_active'] ? 'completed' : 'cancelled' ?>">
                                <?= $row['is_active'] ? 'Đang bán' : 'Ngừng bán' ?>
                            </span>
                        </td>
                        <?php if (is_admin()): ?>
                        <td><a href="<?= e(url('products', ['edit' => $row['product_id']])) ?>">Sửa</a></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach;
                                        ?>
                </tbody>
            </table>
        </div>
        <?php pagination('products', $total, ['q' => $search, 'state' => $state]); ?>
    </section>
    <?php if (is_admin()): ?>
    <section class="card">
        <h2><?= $edit ? 'Sửa sách' : 'Thêm sách' ?></h2>
        <form
            method="post"
            enctype="multipart/form-data"
            class="stack"
        >
            <?php csrf_field(); ?>
            <input
                type="hidden"
                name="action"
                value="save_product"
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
            <input
                type="hidden"
                name="original_stock"
                value="<?= e(old_value('original_stock', $edit['stock_quantity'] ?? '')) ?>"
            />
            <?php
            // [MA_SACH_HIEN_THI] Readonly để xem; backend tự sinh SP001, không dùng giá trị trình duyệt gửi.
            ?>
            <label>
                Mã sản phẩm (tự động)
                <input
                    value="<?= e($edit['sku'] ?? 'Tự tạo sau khi lưu, ví dụ SP041') ?>"
                    readonly
                />
            </label>
            <?php
            field('product_name', 'Tên sách', $edit['product_name'] ?? '', 'text', true, 150);
            field(
                'price',
                'Giá bán (VND)',
                isset($edit['price']) ? (int) $edit['price'] : 0,
                'number',
            );
            field('stock_quantity', 'Số lượng còn bán', $edit['stock_quantity'] ?? 0, 'number');
            ?>
            <label>
                Mô tả
                <textarea
                    name="description"
                    maxlength="3000"
                >
<?= e(old_value('description', $edit['description'] ?? '')) ?></textarea>
            </label>
            <label class="checkbox">
                <input
                    type="checkbox"
                    name="is_active"
                    <?= old_checked('is_active', !$edit || (bool) $edit['is_active']) ? 'checked' : '' ?>
                />
                Đang bán
            </label>
            <p class="hint">
                Đây là lượng còn bán sau khi trừ các đơn đã xác nhận, không phải toàn bộ hàng vật lý
                trong kho. Không tự cộng lại phần đang dành cho đơn hoặc đang chuyển hoàn.
            </p>
            <label>
                Ảnh sản phẩm (JPEG/PNG/WebP, tối đa 2 MB)
                <input
                    type="file"
                    name="image_file"
                    accept="image/jpeg,image/png,image/webp"
                />
            </label>
            <p class="hint">
                Nếu lưu lỗi cần chọn lại tệp ảnh; ảnh cũ vẫn được giữ nếu không tải ảnh mới.
            </p>
            <!-- [NUT_LUU_SAN_PHAM] POST save_product: chỉ admin, kiểm tra tồn/version và ảnh. -->
            <button>Lưu sản phẩm</button>
            <?php if ($edit): ?>
            <a href="<?= e(url('products')) ?>">Hủy chỉnh sửa</a>
            <?php endif; ?>
        </form>
    </section>
    <?php endif; ?>
</div>
<?php
}

