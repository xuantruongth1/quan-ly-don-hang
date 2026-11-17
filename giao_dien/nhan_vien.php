<?php
declare(strict_types=1);

/** TAB NHÂN VIÊN: quản trị viên thêm, sửa hoặc khóa tài khoản nhân viên. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [MAN_HINH_NHAN_VIEN] Admin tìm kiếm, cấp tài khoản, khóa/mở staff. */
function users_view(): void {
    require_admin();
    $id = (int) ($_GET['edit'] ?? 0);
    $edit = $id ? one("SELECT * FROM users WHERE user_id=? AND role='staff'", [$id]) : null;
    if ($id && !$edit) {
        throw new DomainException('Chỉ được sửa tài khoản nhân viên.');
    }
    page_heading('Nhân viên', 'Cấp tài khoản và kiểm soát quyền truy cập.');
    $p = max(1, (int) ($_GET['p'] ?? 1));
    $offset = ($p - 1) * 15;
    $q = text_value($_GET, 'q', 100, false);
    $state = text_value($_GET, 'state', 1, false);
    $where = " WHERE role IN ('admin','staff') AND (username LIKE ? OR full_name LIKE ?)";
    $args = ['%' . $q . '%', '%' . $q . '%'];
    if (in_array($state, ['0', '1'], true)) {
        $where .= ' AND is_active=?';
        $args[] = $state;
    }
    $total = (int) one('SELECT COUNT(*) AS n FROM users' . $where, $args)['n'];
    $rows = query(
        'SELECT * FROM users' . $where . " ORDER BY user_id LIMIT 15 OFFSET $offset",
        $args,
    )->fetchAll();
    ?>
<div class="split">
    <section class="card">
        <form
            method="get"
            class="filters"
        >
            <input
                type="hidden"
                name="page"
                value="users"
            />
            <input
                name="q"
                value="<?= e($q) ?>"
                aria-label="Tìm nhân viên"
                placeholder="Tên hoặc tài khoản"
            />
            <select
                name="state"
                aria-label="Trạng thái tài khoản"
            >
                <option value="">Tất cả</option>
                <option
                    value="1"
                    <?= $state === '1' ? 'selected' : '' ?>
                >
                    Hoạt động
                </option>
                <option
                    value="0"
                    <?= $state === '0' ? 'selected' : '' ?>
                >
                    Đã khóa
                </option>
            </select>
            <button>Tìm nhân viên</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nhân viên</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['full_name']) ?></strong>
                            <small><?= e($row['username']) ?></small>
                        </td>
                        <td><?= $row['role'] === 'admin' ? 'Quản trị viên' : 'Nhân viên' ?></td>
                        <td><?= $row['is_active'] ? 'Hoạt động' : 'Đã khóa' ?></td>
                        <td>
                            <?php if ($row['role'] === 'staff'): ?>
                            <a href="<?= e(url('users', ['edit' => $row['user_id']])) ?>">Sửa</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php pagination('users', $total, ['q' => $q, 'state' => $state]); ?>
    </section>

    <section class="card">
        <h2><?= $edit ? 'Sửa nhân viên' : 'Thêm nhân viên' ?></h2>
        <form
            method="post"
            class="stack"
        >
            <?php csrf_field(); ?>
            <input
                type="hidden"
                name="action"
                value="save_user"
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
            field('username', 'Tên đăng nhập', $edit['username'] ?? '', 'text', true, 50);
            field('full_name', 'Họ và tên', $edit['full_name'] ?? '', 'text', true, 100);
            field('email', 'Thư điện tử', $edit['email'] ?? '', 'email', false, 100);
            ?>
            <label>
                <?= $edit ? 'Mật khẩu mới (để trống nếu giữ nguyên)' : 'Mật khẩu (tối thiểu 8 ký tự ASCII)' ?>
                <input
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    maxlength="72"
                    <?= $edit ? '' : 'required' ?>
                />
            </label>
            <label class="checkbox">
                <input
                    type="checkbox"
                    name="is_active"
                    <?= old_checked('is_active', !$edit || (bool) $edit['is_active']) ? 'checked' : '' ?>
                />
                Cho phép đăng nhập
            </label>
            <!-- [NUT_LUU_NHAN_VIEN] POST save_user: cấp hoặc khóa tài khoản staff. -->
            <button>Lưu nhân viên</button>
            <?php if ($edit): ?>
            <a href="<?= e(url('users')) ?>">Hủy chỉnh sửa</a>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php
}

