# Laptop678 Tra Cứu Bảo Hành

Plugin WordPress tra cứu bảo hành theo số điện thoại cho laptop678.vn (tối ưu cho theme Flatsome).
Thay thế plugin tra cứu miễn phí cũ (bị lỗi import Excel).

## Tính năng

- **Thông tin mỗi đơn**: Tên khách hàng, SĐT, Địa chỉ, Tên máy, Cấu hình, Ngày mua (+ không bắt buộc: số tháng bảo hành, ghi chú/tình trạng).
- **2 cách nhập dữ liệu**
  1. **Import file** `.xlsx` hoặc `.csv`: tự nhận cột theo tiêu đề, **dùng được luôn file Excel mẫu cũ** (Tên Sản phẩm → Tên máy, SKU → Cấu hình). Đọc được ngày `dd/mm/yyyy` lẫn ô ngày của Excel, tự thêm số 0 bị Excel làm mất ở đầu SĐT, tách được ô có 2 số điện thoại. Có chế độ bỏ qua đơn đã có, hoặc xoá hết rồi import lại.
  2. **Thêm thủ công**: form 1 trang, nút "Lưu & thêm đơn khác" để nhân viên nhập liên tục.
- **Trang tra cứu**: tra bằng AJAX, có nhãn Còn/Hết bảo hành, hiển thị đẹp trên mobile, lấy màu chính của Flatsome. Có element **"Tra cứu bảo hành"** trong UX Builder.
- Shortcode `[tra_cuu_bao_hanh]`. Shortcode cũ `[shortcode_check_warranty]` vẫn chạy, nên trang `/tra-ma-bao-hanh/` hiện tại không cần sửa.
- Quản lý: tìm kiếm, lọc theo ngày mua, sửa, xoá hàng loạt, export CSV (sao lưu, import lại được).
- Lưu dữ liệu ở bảng riêng `wp_l678_warranty`: 4.400 đơn import chưa tới 1 giây, tra cứu nhanh.
- Bảo mật: chỉ trả kết quả khi khớp đúng SĐT, giới hạn 30 lượt tra/10 phút mỗi IP, kết quả không bị plugin cache lưu lại, có tuỳ chọn ẩn bớt địa chỉ.

## Cài đặt

1. Nén thư mục `laptop678-warranty` thành `.zip` (hoặc dùng file zip đã gửi kèm).
2. WordPress → Plugin → Cài mới → Tải plugin lên → chọn file zip → Kích hoạt.
3. **Tắt (Deactivate) plugin tra cứu bảo hành cũ** để shortcode cũ chuyển sang plugin mới.
4. Menu **Bảo hành → Import Excel** → chọn file Excel → Import.
5. Menu **Bảo hành → Cài đặt**: chỉnh số tháng bảo hành mặc định, cách hiện địa chỉ, câu thông báo.

Nhân viên cần quyền **Biên tập viên (Editor)** hoặc **Quản lý cửa hàng** trở lên để nhập dữ liệu. Chỉ Admin mới vào được trang Cài đặt.
