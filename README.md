# 📘 iSQL Backend — Hệ Thống Luyện Tập & Thi Thực Hành SQL (UIT)

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![SQL Server](https://img.shields.io/badge/SQL_Server-Sandbox-CC292B?style=for-the-badge&logo=microsoftsqlserver&logoColor=white)](https://www.microsoft.com/sql-server)
[![Redis](https://img.shields.io/badge/Redis-Queue-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![phpMyAdmin](https://img.shields.io/badge/phpMyAdmin-Ready-6C78AF?style=for-the-badge&logo=phpmyadmin&logoColor=white)](https://www.phpmyadmin.net/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

Hệ thống Backend API phục vụ việc **Luyện tập, Kiểm tra & Thi thực hành Cơ sở dữ liệu SQL** dành cho sinh viên và giảng viên trường **Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.

---

## 🏗️ Kiến Trúc Hệ Thống (Dual-Database Architecture)

Hệ thống áp dụng mô hình kiến trúc cơ sở dữ liệu kép:

- **MySQL 8.0 (Nghiệp vụ cốt lõi):** Quản lý người dùng, lớp học, ngân hàng câu hỏi, bài thi và ma trận phân quyền linh hoạt (RBAC).
- **SQL Server Sandbox (Azure / Local):** Môi trường thực thi độc lập cho sinh viên chạy lệnh DDL (`CREATE TABLE`), DML (`INSERT`), và câu lệnh truy vấn SQL theo từng schema riêng biệt, đảm bảo an toàn tuyệt đối.

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
       │  (Nghiệp vụ)  │    │  (Sandbox Thi cử) │
       └───────────────┘    └───────────────────┘
```

---

## 🚀 Hướng Dẫn Cài Đặt & Chạy Hệ Thống Nhanh (Docker)

Toàn bộ hệ thống Backend, Database, Cache và Công cụ quản trị CSDL đã được đóng gói sẵn trong Docker. Bạn chỉ cần làm theo các bước dưới đây để chạy hệ thống từ con số 0.

### Bước 1: Chuẩn bị file môi trường `.env`

Đứng tại thư mục gốc dự án (`d:\PROJECTS\iSQL`), sao chép file `.env.example` thành `.env`:

```bash
# Trên Windows (PowerShell / CMD):
copy .env.example .env

# Hoặc trên Linux / macOS:
cp .env.example .env
```

> 💡 **Cấu hình CSDL mặc định trong `.env` đã được thiết lập sẵn sàng kết nối Docker:**
>
> ```env
> DB_CONNECTION=mysql
> DB_HOST=mysql
> DB_PORT=3306
> DB_DATABASE=sql_uit
> DB_USERNAME=root
> DB_PASSWORD=root
> REDIS_HOST=redis
> REDIS_PORT=6379
> ```

---

### Bước 2: Khởi động các dịch vụ Docker

Tại thư mục gốc dự án, chạy lệnh:

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Lệnh này sẽ tự động khởi động các containers:

1. `isql_php`: PHP 8.3-FPM tích hợp sẵn extension `pdo_mysql`, `pdo_sqlsrv`, `sqlsrv`, `redis`.
2. `isql_nginx`: Web Server Nginx lắng nghe tại cổng `8000`.
3. `isql_mysql`: CSDL MySQL 8.0 lắng nghe tại cổng `3306`.
4. `isql_redis`: In-memory cache & hàng đợi xử lý chấm điểm lắng nghe tại cổng `6379`.
5. `isql_phpmyadmin`: Giao diện quản trị CSDL MySQL lắng nghe tại cổng `8080`.
6. `isql_mssql`: CSDL Microsoft SQL Server 2022 (Sandbox chạy thử truy vấn & chấm bài) lắng nghe tại cổng `1433`. Database `sql_practice` sẽ được hệ thống tự động khởi tạo khi chạy lần đầu.

---

### Bước 3: Cài đặt thư viện Dependencies (Composer)

Do thư mục mã nguồn được mount trực tiếp từ máy vào container, chạy lệnh sau để cài đặt gói `vendor`:

```bash
docker exec isql_php composer install
```

---

### Bước 4: Khởi tạo App Key, Chạy Migrations & Seed Dữ Liệu

Chạy chuỗi lệnh sau để tạo cấu trúc CSDL và nạp dữ liệu mẫu ban đầu:

```bash
# 1. Tạo khóa mã hóa ứng dụng Laravel
docker exec isql_php php artisan key:generate

# 2. Chạy migration tạo 27 bảng CSDL
docker exec isql_php php artisan migrate

# 3. Nạp dữ liệu mẫu (Tài khoản, Ma trận phân quyền, Danh mục bài tập)
docker exec isql_php php artisan db:seed
```

---

### Bước 5: Chạy Kiểm Thử Hệ Thống (Automated Testing)

Chạy bộ test suite (31 tests) để xác thực toàn bộ hệ thống hoạt động chính xác 100%:

```bash
docker exec isql_php php artisan test
```

---

## 🌐 Các Cổng Dịch Vụ & Địa Chỉ Truy Cập

| Dịch vụ              | URL / Địa chỉ                                  | Thông tin đăng nhập / Ghi chú                               |
| :------------------- | :--------------------------------------------- | :---------------------------------------------------------- |
| **Backend REST API** | [http://localhost:8000](http://localhost:8000) | Gốc API của Nginx (VD: `http://localhost:8000/api/auth/me`) |
| **phpMyAdmin**       | [http://localhost:8080](http://localhost:8080) | Server: `mysql` \| User: `root` \| Password: `root`         |
| **MySQL 8.0**        | `localhost:3306`                               | User: `root` \| Password: `root` \| Database: `sql_uit`     |
| **SQL Server 2022**  | `localhost:1433`                               | User: `sa` \| Password: `YourSecurePassword123!` \| Database: `sql_practice` (Sandbox) |
| **Redis**            | `localhost:6379`                               | Cache & Job Queue                                           |

---

## 🔑 Tài Khoản Demo Mặc Định

Sau khi chạy `db:seed`, bạn có thể sử dụng ngay các tài khoản sau:

| Vai trò                     | Email                   | Mật khẩu mặc định | Ghi chú quyền hạn                                                  |
| :-------------------------- | :---------------------- | :---------------- | :----------------------------------------------------------------- |
| **Quản trị viên (Admin)**   | `admin@uit.edu.vn`      | `password123`     | **Superadmin Bypass**: Toàn quyền trên mọi module                  |
| **Giảng viên (Instructor)** | `teacher@uit.edu.vn`    | `password123`     | Quản lý lớp học, phân công giảng viên, tạo đề thi, quản lý bài tập |
| **Sinh viên (Student)**     | `student@gm.uit.edu.vn` | `password123`     | Luyện tập SQL, nộp bài kiểm tra, xem danh sách lớp học             |

---

## 🛡️ Hệ Thống Ma Trận Phân Quyền (RBAC Permission Matrix)

Hệ thống kết hợp ma trận phân quyền động dựa trên các Chức năng (**Functions**) và Hành động (**Actions**):

### 1. Bảng Chức Năng (Functions):

- `USER_MGMT`: Quản lý tài khoản người dùng, duyệt giảng viên, import CSV.
- `PERMISSION_MGMT`: Cấu hình vai trò chi tiết, nhóm quyền và gán quyền người dùng.
- `CLASS_MGMT`: Quản lý lớp học, phân công giảng viên lý thuyết/thực hành, quản lý danh sách sinh viên.
- `PROBLEM_MGMT`: Soạn thảo và quản lý kho bài tập thực hành SQL.
- `ASSIGNMENT_MGMT`: Quản lý kỳ thi, bài kiểm tra, cấu hình sandbox và chấm điểm.
- `PRACTICE_MGMT`: Luyện tập truy vấn SQL và nộp bài cá nhân.

### 2. Các hành động (Actions):

Mỗi quyền được phân tách thành 5 hành vi độc lập: `view` (Xem), `add` (Thêm), `edit` (Sửa), `delete` (Xóa), `download` (Tải về/Export).

### 3. Quy tắc đăng ký tài khoản:

- **Sinh viên (`POST /api/auth/register`):** Tự động kích hoạt (`status = 'Active'`) và gán vào Nhóm quyền **Sinh viên**.
- **Giảng viên (`POST /api/auth/register-lecturer`):** Đăng ký ở trạng thái chờ duyệt (`status = 'Pending'`). Sau khi Quản trị viên duyệt (`POST /api/admin/users/{id}/approve`), tài khoản được chuyển sang `Active` và tự động gán vào Nhóm quyền **Giảng viên**.

---

## 📡 Danh Mục API Endpoints Chính

- `/api/auth/*`: Đăng nhập, Đăng ký sinh viên/giảng viên, Quên mật khẩu qua OTP email, Thông tin cá nhân (`/me`), Ma trận quyền cá nhân (`/my-permissions`).
- `/api/admin/users/*`: Quản lý người dùng, nhập danh sách qua CSV, duyệt giảng viên, reset mật khẩu sinh viên.
- `/api/admin/courses` & `/api/admin/terms`: Quản lý danh mục Môn học và Học kỳ.
- `/api/admin/roles` & `/api/admin/role-groups`: Quản trị ma trận phân quyền chi tiết.
- `/api/teacher/classes/*`: Quản lý lớp học, phân công giảng viên lý thuyết/thực hành, quản lý danh sách sinh viên lớp.
- `/api/student/*`: Dashboard tiến độ học tập cá nhân, danh sách lớp theo học, database cá nhân.
- `/api/problems/*`: Ngân hàng bài tập SQL (CRUD, chạy thử câu lệnh, nộp bài chấm điểm).
- `/api/assignments/*`: Quản lý kỳ thi, liên kết lớp học, cấu hình bài thi DDL/DML/Query.
- `/api/contests/*`: **Sandbox phòng thi SQL Server** (khởi tạo schema, chạy DDL/DML/Query, nộp bài).
- `/api/submissions/*`: Lịch sử nộp bài và kết quả chấm điểm.

---

## 👥 Đội Ngũ Phát Triển

Dự án được xây dựng phục vụ công tác giảng dạy & thực hành môn Cơ sở dữ liệu tại **Trường Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.
