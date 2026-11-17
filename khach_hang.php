<?php
declare(strict_types=1);

/** ĐẦU MỐI PHÍA KHÁCH: giữ đường dẫn nạp cũ; chức năng chia theo giao diện và nghiệp vụ. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/xu_ly/mua_sach.php';
require_once __DIR__ . '/giao_dien/cua_hang.php';
require_once __DIR__ . '/giao_dien/slider.php';
