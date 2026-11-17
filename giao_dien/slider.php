<?php
declare(strict_types=1);

/** Slider tự chuyển banner bằng CSS, dùng chung cho tổng quan và cửa hàng. */
if (!defined('PROJECT_ROOT')) {
    http_response_code(404);
    exit();
}

/** [SLIDER_SACH] CSS tự trượt 3 banner mỗi 6 giây; không có nút điều khiển, không ghi CSDL. */
function slider_sach(bool $customer = false): void {
    $slides = $customer
        ? [
            [
                'MỖI NGÀY MỘT TRANG SÁCH',
                'Chọn một cuốn sách, mở thêm một thế giới.',
                'Tìm sách yêu thích, thêm vào giỏ và đặt hàng ngay tại đây.',
            ],
            [
                'MUA SÁCH THẬT DỄ',
                'Một giỏ sách dành riêng cho bạn.',
                'Kiểm tra số lượng và tổng tiền trước khi gửi đơn cho cửa hàng.',
            ],
            [
                'ĐỒNG HÀNH CÙNG BẠN',
                'Theo dõi từng bước của đơn hàng.',
                'Xem đơn chờ xác nhận, đang giao và đã hoàn thành trong một nơi.',
            ],
        ]
        : [
            [
                'GÓC ĐIỀU HÀNH NHÀ SÁCH',
                'Một ngày mới, những đơn hàng mới.',
                'Kiểm tra đơn chờ xác nhận và chuẩn bị sách để giao đúng hẹn.',
            ],
            [
                'TỦ SÁCH CỬA HÀNG',
                'Đừng để cuốn sách được yêu thích hết hàng.',
                'Theo dõi lượng còn bán và cập nhật danh mục sách của cửa hàng.',
            ],
            [
                'NHỊP BÁN HÀNG',
                'Nhìn lại kết quả để chuẩn bị tốt hơn.',
                'Xem doanh số, sách bán chạy và xuất báo cáo theo thời gian.',
            ],
        ]; ?>
<section
    class="book-slider"
    aria-label="Góc nhà sách — ba nội dung nổi bật"
    aria-roledescription="trình chiếu"
>
    <div class="slider-window">
        <div class="slider-track">
            <?php foreach ($slides as $i => $slide): ?>
            <article
                class="hero-slide hero-<?= $i ?>"
                aria-label="Nội dung <?= $i + 1 ?> trên 3"
            >
                <div class="hero-copy">
                    <p class="hero-eyebrow">
                        <span></span>
                        <?= e($slide[0]) ?>
                    </p>
                    <h2><?= e($slide[1]) ?></h2>
                    <p class="hero-description"><?= e($slide[2]) ?></p>
                    <div class="hero-caption">
                        <span>NHÀ SÁCH</span>
                        <span>Mỗi trang sách, một niềm vui.</span>
                    </div>
                </div>
                <div
                    class="hero-illustration"
                    aria-hidden="true"
                >
                    <div class="hero-orbit"></div>
                    <span class="hero-spark spark-one">✦</span>
                    <span class="hero-spark spark-two">✧</span>
                    <div class="display-book book-back">
                        <small>TỦ SÁCH VIỆT</small>
                        <strong>
                            Dế Mèn
                            <br />
                            Phiêu Lưu Ký
                        </strong>
                        <span>TÔ HOÀI</span>
                    </div>
                    <div class="display-book book-front">
                        <small>MỞ MỘT TRANG SÁCH</small>
                        <strong><?= ['Nhà<br>Giả Kim', 'Mắt<br>Biếc', 'Tuổi Trẻ<br>Đáng Giá<br>Bao Nhiêu?'][$i] ?></strong>
                        <span><?= ['PAULO COELHO', 'NGUYỄN NHẬT ÁNH', 'ROSIE NGUYỄN'][$i] ?></span>
                    </div>
                    <div class="hero-stamp">
                        GÓC NHỎ
                        <br />
                        <b>
                            cho người
                            <br />
                            yêu sách
                        </b>
                    </div>
                </div>
                <span
                    class="slide-number"
                    aria-hidden="true"
                >
                    0<?= $i + 1 ?> / 03
                </span>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php
}

