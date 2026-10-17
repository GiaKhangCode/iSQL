# 📘 iSQL Backend — Hệ Thống Luyện Tập & Thi Thực Hành SQL (UIT)

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![SQL Server](https://img.shields.io/badge/SQL_Server-Azure_/_MSSQL-CC292B?style=for-the-badge&logo=microsoftsqlserver&logoColor=white)](https://www.microsoft.com/sql-server)
[![Redis](https://img.shields.io/badge/Redis-Queue-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

Hệ thống Backend API-Only phục vụ Luyện tập & Thi thực hành Cơ sở dữ liệu SQL dành cho sinh viên và giảng viên trường **Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.

---

## 🏗️ Kiến Trúc Hệ Thống (Dual-Database Architecture)

Hệ thống sử dụng mô hình kiến trúc cơ sở dữ liệu kép độc đáo:
* **MySQL 8.0 (Nghiệp vụ):** Lưu trữ toàn bộ dữ liệu người dùng (`users`), bài tập (`problems`), lớp học (`classes`), kỳ thi (`assignments`), và lịch sử nộp bài (`submissions`).
* **SQL Server (Azure SQL / MSSQL Sandbox):** Cơ sở dữ liệu cách ly phục vụ sinh viên thực thi lệnh DDL (`CREATE TABLE`), DML (`INSERT`), và các câu truy vấn SQL trong phòng thi theo từng schema riêng biệt.

```
                  [React Frontend (Vite)]
                 http://localhost:5173
                          │
                          │ RESTful JSON API
                          ▼
            ┌───────────────────────────┐
            │  PHP Backend (Laravel 11) │
            │  (API-Only, Service-Arch) │
            └──────┬─────────────┬──────┘
                   │             │
      ┌────────────▼──┐    ┌─────▼─────────────┐
      │  MySQL 8.0    │    │  SQL Server Azure │
      │  (Nghiệp vụ) │    │  (Sandbox Thi cử) │
      └───────────────┘    └───────────────────┘
```

Xem chi tiết kiến trúc và phân công module tại:
* 📖 [Bản thiết kế kỹ thuật (Blueprint)](./backend_laravel_blueprint.md)
* 🗺️ [Cẩm nang cấu trúc thư mục chi tiết (Project Structure)](./PROJECT_STRUCTURE.md)

---

## 🚀 Hướng Dẫn Cài Đặt & Khởi Chạy

### Cách 1: Chạy Bằng Docker (Khuyến nghị)

Toàn bộ môi trường đã được cấu hình sẵn trong thư mục [docker/](./docker):

```bash
# 1. Di chuyển vào thư mục docker
cd docker

# 2. Khởi động toàn bộ container (PHP-FPM, Nginx, MySQL, Redis)
docker compose up -d --build

# 3. Chạy migration và seed dữ liệu mẫu
docker compose exec php php artisan migrate
docker compose exec php php artisan db:seed
```
API Backend sẽ sẵn sàng tại: `http://localhost:8000`

---

### Cách 2: Chạy Trên Máy Cục Bộ (Local Environment)

#### Yêu cầu hệ thống:
* **PHP:** >= 8.2 (kèm extension: `pdo_mysql`, `pdo_sqlsrv`, `redis`, `mbstring`)
* **Composer:** >= 2.x
* **MySQL:** >= 8.0

```bash
# 1. Cài đặt các thư viện PHP
composer install

# 2. Cấu hình biến môi trường
cp .env.example .env
php artisan key:generate

# 3. Cấu hình thông số DB trong file .env, sau đó chạy migration & seeder:
php artisan migrate
php artisan db:seed

# 4. Khởi động server API
php artisan serve --port=8000

# 5. (Tùy chọn) Khởi động Queue Worker xử lý chấm bài nền:
php artisan queue:work redis --queue=grading
```

---

## 🔑 Tài Khoản Demo Mặc Định

Sau khi chạy `php artisan db:seed`, hệ thống có sẵn 3 tài khoản demo:

| Vai trò | Email | Mật khẩu mặc định |
| :--- | :--- | :--- |
| **Quản trị viên (Admin)** | `admin@uit.edu.vn` | `password123` |
| **Giảng viên (Instructor)** | `teacher@uit.edu.vn` | `password123` |
| **Sinh viên (Student)** | `student@gm.uit.edu.vn` | `password123` |

---

## 📡 Danh Mục API Endpoints

Hệ thống cung cấp **46 API endpoints** được phân chia theo module sạch sẽ trong [routes/](./routes):

* `/api/auth/*`: Đăng nhập, Đăng ký sinh viên, Đăng ký giảng viên, Demo login, `/me`.
* `/api/admin/*`: Quản lý danh sách người dùng, kích hoạt tài khoản giảng viên, đổi quyền, thống kê hệ thống.
* `/api/teacher/*`: Giảng viên quản lý lớp học.
* `/api/problems/*`: Ngân hàng câu hỏi SQL (CRUD, chạy thử câu lệnh, nộp bài luyện tập).
* `/api/assignments/*`: Quản lý bài tập & kỳ thi contest (liên kết lớp học, câu hỏi, điểm DDL/DML/SQL).
* `/api/student/*`: Dashboard sinh viên, streak ngày làm bài, danh sách bài tập được giao, database cá nhân.
* `/api/contests/*`: **Sandbox phòng thi SQL Server** (`setup-db`, `run-ddl`, `seed-dml`, `inspect-schema`, `run-query`, `submit-exam`).
* `/api/submissions/*`: Lịch sử nộp bài, xem chi tiết bài làm, chấm điểm thủ công.
* `/api/notifications/*`: Danh sách thông báo, đánh dấu đã đọc.

---

## 🧪 Kiểm Thử Tự Động (Automated Testing)

Dự án được viết sẵn bộ kiểm thử tự động (Unit & Feature tests):

```bash
php artisan test
```

---

## 👥 Đội Ngũ Phát Triển

Dự án phục vụ giảng dạy và thực hành môn Cơ sở dữ liệu tại **Trường Đại học Công nghệ Thông tin — ĐHQG-HCM**.
