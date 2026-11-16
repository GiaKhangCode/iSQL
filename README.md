# 📘 iSQL — Hệ Thống Luyện Tập & Thi Thực Hành SQL (UIT)

[![React](https://img.shields.io/badge/React-18.x-61DAFB?style=for-the-badge&logo=react&logoColor=black)](https://react.dev/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://www.typescriptlang.org/)
[![Vite](https://img.shields.io/badge/Vite-6.x-646CFF?style=for-the-badge&logo=vite&logoColor=white)](https://vitejs.dev/)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![SQL Server](https://img.shields.io/badge/SQL_Server-2022_Sandbox-CC292B?style=for-the-badge&logo=microsoftsqlserver&logoColor=white)](https://www.microsoft.com/sql-server)
[![Redis](https://img.shields.io/badge/Redis-Queue-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

Hệ thống Fullstack (**React + TypeScript** Frontend & **Laravel 11** Backend API) phục vụ việc **Luyện tập, Kiểm tra, Thi thực hành, Chấm điểm & Phúc khảo Cơ sở dữ liệu SQL** dành cho sinh viên, giảng viên, giáo vụ khoa và quản trị viên trường **Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.

---

## 🏗️ Kiến Trúc Hệ Thống (Dual-Database Architecture)

Hệ thống áp dụng mô hình kiến trúc cơ sở dữ liệu kép:

- **MySQL 8.0 (Nghiệp vụ cốt lõi):** Quản lý người dùng, lớp học, ngân hàng câu hỏi, bài tập, kỳ thi, ca thi, phân công phúc khảo và ma trận phân quyền linh hoạt (RBAC).
- **SQL Server 2022 Sandbox:** Môi trường thực thi độc lập cho sinh viên chạy lệnh DDL (`CREATE TABLE`), DML (`INSERT`/`UPDATE`/`DELETE`), Trigger và câu lệnh truy vấn SQL theo từng schema riêng biệt, đảm bảo an toàn và cách ly dữ liệu.

```
                  [React Frontend (Vite)]
                  http://localhost:5173
                           │
                           │ RESTful JSON API (/api/*)
                           ▼
             ┌───────────────────────────┐
             │  PHP Backend (Laravel 11) │
             │  http://localhost:8000    │
             └──────┬─────────────┬──────┘
                    │             │
       ┌────────────▼──┐    ┌─────▼─────────────┐
       │  MySQL 8.0    │    │  SQL Server 2022  │
       │  (Nghiệp vụ)  │    │  (Sandbox Thi cử) │
       └───────────────┘    └───────────────────┘
```

---

## 📁 Cấu Trúc Thư Mục Dự Án

```text
iSQL/
├── app/                # Laravel Controllers, Models, Services, Middleware
├── config/             # Cấu hình Laravel, Database, CORS, JWT
├── database/           # Migrations, Seeders, Factories
├── docker/             # Dockerfile, docker-compose.yml, nginx.conf
├── routes/             # Định tuyến API (api.php, admin.php, teacher.php, student.php...)
├── tests/              # Bộ kiểm thử tự động PHPUnit (Backend)
├── frontend/           # Ứng dụng Web React + TypeScript + Vite (copy từ SQL-UIT/frontend)
│   ├── src/            # Mã nguồn giao diện (Admin, Office, Teacher, Student)
│   ├── tests/          # Bộ kiểm thử Frontend
│   ├── package.json    # Thư viện & scripts npm
│   └── vite.config.ts  # Cấu hình Vite & proxy /api -> http://127.0.0.1:8000
├── .env.example        # Mẫu biến môi trường Backend
└── README.md
```

---

## 📋 Yêu Cầu Hệ Thống

Trước khi cài đặt, máy của bạn cần có:
- **Docker & Docker Compose** (khuyên dùng Docker Desktop bản mới nhất)
- **Node.js** `>= 18` (khuyên dùng Node.js 20 LTS) và `npm`
- **Git**

---

## 🚀 Hướng Dẫn Cài Đặt & Khởi Chạy Hệ Thống

### Phần 1: Khởi Chạy Backend & Database (Docker)

Toàn bộ dịch vụ Backend, MySQL, SQL Server, Redis và phpMyAdmin đã được đóng gói sẵn trong Docker.

#### Bước 1: Clone dự án và tạo file `.env`

```bash
git clone https://github.com/GiaKhangCode/iSQL.git
cd iSQL
```

Sao chép file `.env.example` thành `.env`:

```bash
# Trên Windows (PowerShell / CMD):
copy .env.example .env

# Hoặc trên Linux / macOS:
cp .env.example .env
```

> 💡 **Lưu ý về `.env`:**
> - Các thông số kết nối Docker (`DB_HOST=mysql`, `DB_PASSWORD=root`, `SQLSRV_HOST=mssql`, `REDIS_HOST=redis`,...) đã được cấu hình sẵn trong `docker/docker-compose.yml`.
> - `CORS_ALLOWED_ORIGINS=http://localhost:5173` cần khớp với cổng chạy Frontend.
> - Nếu chưa cấu hình Gmail App Password để gửi OTP qua email, có thể đặt tạm `MAIL_MAILER=log` để xem mã OTP trong `storage/logs/laravel.log`.

#### Bước 2: Khởi động các containers Docker

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Lệnh này sẽ khởi tạo 6 containers:
1. `isql_php`: PHP 8.3-FPM tích hợp `pdo_mysql`, `pdo_sqlsrv`, `sqlsrv`, `redis`.
2. `isql_nginx`: Web Server Nginx lắng nghe tại cổng `8000`.
3. `isql_mysql`: CSDL MySQL 8.0 lắng nghe tại cổng `3306`.
4. `isql_redis`: Cache & hàng đợi xử lý chấm điểm lắng nghe tại cổng `6379`.
5. `isql_phpmyadmin`: Giao diện quản trị MySQL lắng nghe tại cổng `8080`.
6. `isql_mssql`: Microsoft SQL Server 2022 (Sandbox thực thi & chấm bài SQL) lắng nghe tại cổng `1433`. Database `sql_practice` được tự động khởi tạo khi chạy lần đầu.

#### Bước 3: Cài đặt thư viện PHP (Composer)

```bash
docker exec isql_php composer install
```

#### Bước 4: Khởi tạo App Key, chạy Migration & Seed dữ liệu mẫu

```bash
# 1. Tạo khóa mã hóa ứng dụng Laravel
docker exec isql_php php artisan key:generate

# 2. Chạy migration tạo toàn bộ bảng CSDL
docker exec isql_php php artisan migrate

# 3. Nạp dữ liệu mẫu (Tài khoản, Ma trận phân quyền, Lớp học, Bài tập...)
docker exec isql_php php artisan db:seed
```

---

### Phần 2: Khởi Chạy Frontend (React + Vite)

Mở một terminal mới tại thư mục gốc `iSQL`:

```bash
cd frontend
npm install
npm run dev
```

Mặc định Frontend chạy tại **[http://localhost:5173](http://localhost:5173)** và tự động proxy toàn bộ request `/api/*` sang Backend tại `http://127.0.0.1:8000` (cấu hình trong `frontend/vite.config.ts`).

> 💡 Nếu Backend chạy ở địa chỉ/cổng khác, bạn có thể tạo file `frontend/.env.local` với nội dung:
> ```env
> SQLUIT_API_TARGET=http://127.0.0.1:8000
> ```

Để build bản production cho Frontend:

```bash
cd frontend
npm run build
```

---

### Phần 3: Chạy Kiểm Thử Tự Động (Automated Testing)

* **Kiểm thử Backend (PHPUnit):**
  ```bash
  docker exec isql_php php artisan test
  ```

* **Kiểm thử Frontend (TypeScript check & unit tests):**
  ```bash
  cd frontend
  npm test
  ```

---

## 🌐 Các Cổng Dịch Vụ & Địa Chỉ Truy Cập

| Dịch vụ               | URL / Địa chỉ                                  | Thông tin đăng nhập / Ghi chú                                                        |
| :-------------------- | :--------------------------------------------- | :----------------------------------------------------------------------------------- |
| **Frontend Web App**  | [http://localhost:5173](http://localhost:5173) | Giao diện người dùng chính (React + Vite)                                            |
| **Backend REST API**  | [http://localhost:8000](http://localhost:8000) | Gốc API của Nginx (VD: `http://localhost:8000/api/auth/me`)                          |
| **phpMyAdmin**        | [http://localhost:8080](http://localhost:8080) | Server: `mysql` \| User: `root` \| Password: `root`                                  |
| **MySQL 8.0**         | `localhost:3306`                               | User: `root` \| Password: `root` \| Database: `sql_uit`                              |
| **SQL Server 2022**   | `localhost:1433`                               | User: `sa` \| Password: `YourSecurePassword123!` \| Database: `sql_practice` (Sandbox) |
| **Redis**             | `localhost:6379`                               | Cache & Job Queue                                                                    |

---

## 🔑 Tài Khoản Demo Mặc Định

Sau khi chạy `db:seed`, bạn có thể sử dụng ngay các tài khoản sau để đăng nhập tại [http://localhost:5173](http://localhost:5173):

| Vai trò                     | Email                   | Mật khẩu mặc định | Ghi chú quyền hạn                                                  |
| :-------------------------- | :---------------------- | :---------------- | :----------------------------------------------------------------- |
| **Quản trị viên (Admin)**   | `admin@uit.edu.vn`      | `password123`     | **Superadmin Bypass**: Toàn quyền trên mọi module                  |
| **Giảng viên (Instructor)** | `teacher@uit.edu.vn`    | `password123`     | Quản lý lớp học, tạo đề thi, quản lý bài tập, chấm bài             |
| **Sinh viên (Student)**     | `student@gm.uit.edu.vn` | `password123`     | Luyện tập SQL, thi thực hành, xem kết quả & gửi yêu cầu phúc khảo  |

---

## 🛡️ Hệ Thống Ma Trận Phân Quyền (RBAC Permission Matrix)

Hệ thống kết hợp ma trận phân quyền động dựa trên các Chức năng (**Functions**) và Hành động (**Actions**):

### 1. Bảng Chức Năng (Functions)
- `USER_MGMT`: Quản lý tài khoản người dùng, duyệt giảng viên, import CSV.
- `PERMISSION_MGMT`: Cấu hình vai trò chi tiết, nhóm quyền và gán quyền người dùng.
- `CLASS_MGMT`: Quản lý lớp học, phân công giảng viên lý thuyết/thực hành, quản lý danh sách sinh viên.
- `PROBLEM_MGMT`: Soạn thảo và quản lý kho bài tập thực hành SQL.
- `ASSIGNMENT_MGMT`: Quản lý kỳ thi, bài kiểm tra, cấu hình sandbox và chấm điểm.
- `PRACTICE_MGMT`: Luyện tập truy vấn SQL và nộp bài cá nhân.

### 2. Các hành động (Actions)
Mỗi quyền được phân tách thành 5 hành vi độc lập: `view` (Xem), `add` (Thêm), `edit` (Sửa), `delete` (Xóa), `download` (Tải về/Export).

### 3. Quy tắc đăng ký tài khoản
- **Sinh viên (`POST /api/auth/register`):** Tự động kích hoạt (`status = 'Active'`) và gán vào Nhóm quyền **Sinh viên**.
- **Giảng viên (`POST /api/auth/register-lecturer`):** Đăng ký ở trạng thái chờ duyệt (`status = 'Pending'`). Sau khi Quản trị viên duyệt (`POST /api/admin/users/{id}/approve`), tài khoản được chuyển sang `Active` và tự động gán vào Nhóm quyền **Giảng viên**.

---

## 📡 Danh Mục API Endpoints Chính

- `/api/auth/*`: Đăng nhập, Đăng ký sinh viên/giảng viên, Quên mật khẩu qua OTP email, Thông tin cá nhân (`/me`), Ma trận quyền cá nhân (`/my-permissions`).
- `/api/admin/users/*`: Quản lý người dùng, nhập danh sách qua CSV, duyệt giảng viên, reset mật khẩu sinh viên.
- `/api/admin/courses` & `/api/admin/terms`: Quản lý danh mục Môn học và Học kỳ.
- `/api/admin/roles` & `/api/admin/role-groups`: Quản trị ma trận phân quyền chi tiết.
- `/api/teacher/classes/*`: Quản lý lớp học, phân công giảng viên, quản lý danh sách sinh viên lớp.
- `/api/student/*`: Dashboard tiến độ học tập cá nhân, danh sách lớp theo học, database cá nhân.
- `/api/problems/*`: Ngân hàng bài tập SQL (CRUD, chạy thử câu lệnh, nộp bài chấm điểm).
- `/api/assignments/*` & `/api/examinations/*`: Quản lý kỳ thi, ca thi, giám thị, liên kết lớp học, cấu hình bài thi DDL/DML/Query.
- `/api/contests/*`: **Sandbox phòng thi SQL Server** (khởi tạo schema, chạy DDL/DML/Query, nộp bài).
- `/api/submissions/*` & `/api/regrade-assignments/*`: Lịch sử nộp bài, chấm bài, công bố/ẩn điểm và phân công phúc khảo.

---

## 👥 Đội Ngũ Phát Triển

Dự án được xây dựng phục vụ công tác giảng dạy & thực hành môn Cơ sở dữ liệu tại **Trường Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.
