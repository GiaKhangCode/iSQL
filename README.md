# 📘 iSQL — Hệ Thống Luyện Tập & Thi Thực Hành SQL (UIT)

[![React](https://img.shields.io/badge/React-18.x-61DAFB?style=for-the-badge&logo=react&logoColor=black)](https://react.dev/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://www.typescriptlang.org/)
[![Vite](https://img.shields.io/badge/Vite-6.x-646CFF?style=for-the-badge&logo=vite&logoColor=white)](https://vitejs.dev/)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![SQL Server](https://img.shields.io/badge/SQL_Server-2022_Sandbox-CC292B?style=for-the-badge&logo=microsoftsqlserver&logoColor=white)](https://www.microsoft.com/sql-server)
[![Redis](https://img.shields.io/badge/Redis-Cache-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)

Hệ thống Fullstack (**React + TypeScript** Frontend & **Laravel 11** Backend API) phục vụ việc **Luyện tập, Làm bài tập, Thi thực hành, Chấm điểm & Phúc khảo Cơ sở dữ liệu SQL** dành cho sinh viên, giảng viên, giáo vụ khoa và quản trị viên trường **Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.

---

## 🏗️ Kiến Trúc Hệ Thống (Dual-Database Architecture)

Hệ thống áp dụng mô hình kiến trúc cơ sở dữ liệu kép:

- **MySQL 8.0 (Nghiệp vụ cốt lõi):** Quản lý người dùng, lớp học, ngân hàng câu hỏi, bài tập, kỳ thi, ca thi, bài nộp, điểm, phân công phúc khảo và phân quyền (RBAC).
- **SQL Server 2022 Sandbox:** Môi trường thực thi riêng cho mã SQL của sinh viên (DDL, DML, Trigger và câu truy vấn), tách theo từng schema để cách ly dữ liệu.

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
       ┌────────────▼──┐    ┌─────▼─────────────────┐
       │  MySQL 8.0    │    │  SQL Server 2022      │
       │  (Nghiệp vụ)  │    │  (Sandbox luyện & thi)│
       └───────────────┘    └───────────────────────┘
```

---

## 📁 Cấu Trúc Thư Mục Dự Án

```text
iSQL/
├── backend/            # Ứng dụng Backend Laravel 11 API
│   ├── app/            # Controllers, Models, Services, Middleware
│   ├── config/         # Cấu hình Laravel (database, cache, mail, Sanctum...)
│   ├── database/       # Migrations, Seeders, Factories
│   ├── routes/         # Định tuyến API (api.php, admin.php, teacher.php, student.php...)
│   ├── tests/          # Bộ kiểm thử tự động PHPUnit (Backend)
│   └── .env.example    # Mẫu biến môi trường Backend
├── frontend/           # Ứng dụng Web React + TypeScript + Vite
│   ├── src/            # Mã nguồn giao diện (Admin, Office, Teacher, Student)
│   ├── tests/          # Bộ kiểm thử Frontend
│   ├── package.json    # Thư viện & scripts npm
│   └── vite.config.ts  # Cấu hình Vite & proxy /api -> http://127.0.0.1:8000
├── docker/             # Dockerfile, docker-compose.yml, nginx.conf
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

#### Bước 1: Clone dự án và tạo file `backend/.env`

```bash
git clone https://github.com/GiaKhangCode/iSQL.git
cd iSQL
```

Sao chép file `backend/.env.example` thành `backend/.env`:

```bash
# Trên Windows (PowerShell / CMD):
copy backend\.env.example backend\.env

# Hoặc trên Linux / macOS:
cp backend/.env.example backend/.env
```

> 💡 **Lưu ý về `.env`:**
> - Khi chạy bằng Docker, các thông số kết nối MySQL, SQL Server và Redis (`DB_HOST=mysql`, `DB_PASSWORD=root`, `SQLSRV_HOST=mssql`, `REDIS_HOST=redis`,...) lấy từ `docker/docker-compose.yml` và ghi đè giá trị trong `.env`.
> - Giữ `CACHE_STORE=redis` như trong file mẫu.
> - Nếu chưa cấu hình Gmail App Password để gửi mã OTP qua email, có thể đặt tạm `MAIL_MAILER=log` để xem mã trong `backend/storage/logs/laravel.log`.

#### Bước 2: Khởi động các containers Docker

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Lệnh này sẽ khởi tạo 6 containers:
1. `isql_php`: PHP 8.3-FPM tích hợp `pdo_mysql`, `pdo_sqlsrv`, `sqlsrv`, `redis`.
2. `isql_nginx`: Web Server Nginx lắng nghe tại cổng `8000`.
3. `isql_mysql`: CSDL MySQL 8.0 lắng nghe tại cổng `3306`.
4. `isql_redis`: Bộ nhớ đệm (cache) của ứng dụng, cổng `6379`.
5. `isql_phpmyadmin`: Giao diện quản trị MySQL lắng nghe tại cổng `8080`.
6. `isql_mssql`: Microsoft SQL Server 2022 (Sandbox thực thi & chấm bài SQL), cổng `1433`. Database `sql_practice` được backend tự tạo ở lần đầu chạy mã SQL.

Bài làm được chấm ngay trong request, **không cần chạy queue worker**.

#### Bước 3: Cài đặt thư viện PHP (Composer)

```bash
docker exec isql_php composer install
```

#### Bước 4: Khởi tạo App Key, chạy Migration & Seed dữ liệu nền

```bash
# 1. Tạo khóa mã hóa ứng dụng Laravel
docker exec isql_php php artisan key:generate

# 2. Chạy migration tạo toàn bộ bảng CSDL
docker exec -u www-data isql_php php artisan migrate

# 3. Nạp dữ liệu nền: vai trò & quyền, tài khoản demo, 10 lớp IT004, chủ đề, 2 CSDL mẫu trong thư viện
docker exec -u www-data isql_php php artisan db:seed
```

> 💡 Chạy `artisan` với `-u www-data` để file `storage/logs/laravel.log` không bị tạo bằng quyền `root` (khi đó PHP-FPM không ghi được log).

#### Bước 5 (tùy chọn): Nạp dữ liệu thực hành & thi mẫu

Bước 4 chưa tạo bài toán hay bài tập nào. Để có dữ liệu dùng thử, chạy **đúng thứ tự** sau:

```bash
# 2 lớp thực hành IT004.O21.1 / .2, mỗi lớp 12 sinh viên (<mssv>@gm.uit.edu.vn, mật khẩu password123)
docker exec -u www-data isql_php php artisan db:seed --class=UitPracticeClassSeeder

# 4 CSDL trong thư viện: QLBH, QLGV, QLPT, QLKB
docker exec -u www-data isql_php php artisan db:seed --class=UitDatabaseLibrarySeeder

# 4 bài toán tương ứng (DDL, DML, Trigger, DQL)
docker exec -u www-data isql_php php artisan db:seed --class=UitProblemSeeder

# 1 kỳ thi cuối kỳ trên QLPT: 4 mã đề, 2 ca thi trong ngày chạy lệnh
docker exec -u www-data isql_php php artisan db:seed --class=UitExaminationSeeder
```

> ⚠️ Sau bước này **không chạy lại `db:seed`**: `ClassSeeder` xóa mọi lớp ngoài 10 lớp của nó, gồm cả 2 lớp `IT004.O21.*` cùng danh sách sinh viên và liên kết bài giao.

> ⚠️ **Đã có database tạo từ bộ migration cũ (trước 26/11/2026)?** Bộ migration đã được gộp lại thành mỗi bảng một file (`database/migrations/2026_01_01_*`), nên `php artisan migrate` trên database cũ sẽ báo "table already exists". Hãy tạo lại database (**mất toàn bộ dữ liệu dev đang có**), rồi chạy lại Bước 5 nếu cần:
>
> ```bash
> docker exec -u www-data isql_php php artisan migrate:fresh --seed
> ```
>
> Thay đổi cấu trúc về sau: thêm file migration mới có ngày hiện tại, không sửa các file `2026_01_01_*` đã dùng chung.

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
  docker exec -u www-data isql_php php artisan test
  ```
  Test chạy trên **database MySQL đang cấu hình trong môi trường dev** (dữ liệu được rollback sau mỗi test); một số test cần SQL Server đang hoạt động.

* **Kiểm thử Frontend:**
  ```bash
  cd frontend
  npm test                       # kiểm tra kiểu TypeScript và một nhóm test chính
  node tests/<tên>.test.mjs      # chạy riêng từng file test khác
  ```

---

## 🌐 Các Cổng Dịch Vụ & Địa Chỉ Truy Cập

| Dịch vụ               | URL / Địa chỉ                                  | Thông tin đăng nhập / Ghi chú                                                          |
| :-------------------- | :--------------------------------------------- | :------------------------------------------------------------------------------------- |
| **Frontend Web App**  | [http://localhost:5173](http://localhost:5173) | Giao diện người dùng chính (React + Vite)                                              |
| **Backend REST API**  | [http://localhost:8000](http://localhost:8000) | Gốc API của Nginx (VD: `http://localhost:8000/api/auth/me`)                            |
| **phpMyAdmin**        | [http://localhost:8080](http://localhost:8080) | Server: `mysql` \| User: `root` \| Password: `root`                                    |
| **MySQL 8.0**         | `localhost:3306`                               | User: `root` \| Password: `root` \| Database: `sql_uit`                                |
| **SQL Server 2022**   | `localhost:1433`                               | User: `sa` \| Password: `YourSecurePassword123!` \| Database: `sql_practice` (Sandbox) |
| **Redis**             | `localhost:6379`                               | Cache                                                                                  |

---

## 🔑 Tài Khoản Demo Mặc Định

Sau khi chạy `db:seed`, đăng nhập tại [http://localhost:5173](http://localhost:5173) bằng các tài khoản sau (quyền theo vai trò mặc định):

| Vai trò                              | Email                   | Mật khẩu      | Chức năng                                                                                                           |
| :----------------------------------- | :---------------------- | :------------ | :------------------------------------------------------------------------------------------------------------------ |
| **Admin**                            | `admin@uit.edu.vn`      | `password123` | Tổng quan hệ thống, người dùng, môn học & lớp, vai trò & phân quyền                                                 |
| **Giảng viên**                       | `teacher@uit.edu.vn`    | `password123` | Bài toán, thư viện CSDL, bài tập, lớp phụ trách, coi thi, chấm bài, thống kê lớp, chấm phúc khảo; luyện tập SQL    |
| **Giáo vụ khoa**                     | `gvk@uit.edu.vn`        | `12345678`    | Kỳ thi & ca thi, cấp tài khoản thi, kết quả thi, phân công phúc khảo, thống kê khoa, giao bài tập                   |
| **Sinh viên** (lớp `IT004.R11.1`)    | `student@gm.uit.edu.vn` | `password123` | Luyện tập SQL, làm bài tập và bài thi, xem bài đã nộp và kết quả                                                    |

Vai trò **Trưởng bộ môn** được tạo sẵn nhưng chưa gán cho tài khoản demo nào. Yêu cầu phúc khảo được sinh viên nộp trên [cổng sinh viên UIT](https://portal.uit.edu.vn/sinh-vien/phuc-khao); trong iSQL, giáo vụ phân công người chấm phúc khảo.

---

## 🛡️ Hệ Thống Phân Quyền (RBAC theo capability)

Một **vai trò** là tập các mã quyền (`practice.solve`, `problem.create`, `assignment.create`, `results.grade`, `class.manage`, `user.manage`, `access.manage`...). Một người có thể giữ nhiều vai trò; quyền hiệu lực = hợp các vai trò + quyền gán trực tiếp. Không có vai trò nào tự có mọi quyền: vai trò **Admin** mặc định chỉ có 4 quyền (`system.overview`, `user.manage`, `class.manage`, `access.manage`) và sửa được như mọi vai trò khác. Quyền `sandbox.cleanup` (dọn schema bài làm trên SQL Server ở trang Tổng quan, khi kết thúc học phần) không thuộc vai trò mặc định nào: tick cho vai trò cần dùng trên trang Vai trò. Mỗi vai trò là một **khu vực giao diện**: ô chuyển khu vực liệt kê các vai trò người dùng giữ, thanh điều hướng của khu vực gồm các tab mà quyền của vai trò đó mở ra, xem `GET /api/auth/permissions` (`roles`). Quản trị vai trò qua `/api/admin/access*` (trang **Vai trò** của quản trị viên).

### Quy tắc tạo tài khoản
- **Không có tự đăng ký.** Quản trị viên tạo tài khoản ở trang Người dùng (tạo từng người hoặc nhập CSV). Tài khoản sinh viên tự nhận vai trò mặc định **Sinh viên**.
- Trạng thái tài khoản chỉ có `Active` và `Inactive`.
- **Tài khoản thi** là tài khoản tạm do giáo vụ cấp cho từng ca thi, đăng nhập ở cùng trang đăng nhập.

---

## 📡 Danh Mục API Endpoints Chính

- `/api/auth/*`: Đăng nhập, quên mật khẩu qua OTP email, thông tin cá nhân (`/me`), quyền hiệu lực, vai trò (khu vực) và quyền gán riêng (`/permissions`).
- `/api/admin/users/*`: Quản lý người dùng, nhập danh sách qua CSV, đặt lại mật khẩu sinh viên.
- `/api/admin/classes/*`, `/api/admin/courses`, `/api/admin/terms`: Lớp học, phân công giảng viên, danh sách sinh viên, danh mục môn học và học kỳ.
- `/api/admin/overview/*` & `/api/admin/sandbox`: Tổng quan hệ thống và dọn schema bài làm.
- `/api/admin/access*`: Quản trị vai trò, quyền và gán vai trò cho tài khoản.
- `/api/exercise-tags` & `/api/admin/exercise-tags`: Thẻ phân loại câu hỏi.
- `/api/teacher/classes/*`: Giảng viên xem lớp, danh sách sinh viên và đặt lại mật khẩu sinh viên trong lớp.
- `/api/teacher/database-library/*`: Thư viện cơ sở dữ liệu mẫu.
- `/api/teacher/statistics` & `/api/office/statistics`: Thống kê lớp và thống kê khoa.
- `/api/student/*`: Dashboard tiến độ, hồ sơ, tùy chọn, bài tập, lượt làm bài, lớp đang học, database cá nhân.
- `/api/problems/*`: Ngân hàng bài toán SQL (CRUD, chạy thử, nộp bài và chấm).
- `/api/assignments/*`: Quản lý bài tập và đối tượng được giao.
- `/api/examinations/*`: Kỳ thi, ca thi, cấp và xuất tài khoản thi.
- `/api/exam-sessions/*`: Lập kế hoạch ca thi và **sandbox phòng thi SQL Server** (khởi tạo schema, chạy DDL/DML/truy vấn, nộp bài).
- `/api/exam-auth/*`, `/api/exam-admission/*`, `/api/proctor/*`: Đăng nhập tài khoản thi, mở khóa bài thi, coi thi.
- `/api/submissions/*` & `/api/regrade-assignments/*`: Lịch sử nộp bài, chấm bài và phân công phúc khảo.
- `/api/notifications/*`: Thông báo (chuông).

---

## 👥 Đội Ngũ Phát Triển

**Nhóm Kỵ Sĩ Mùa Xuân — lớp IS207.R11**

Dự án được xây dựng phục vụ công tác giảng dạy & thực hành môn Cơ sở dữ liệu tại **Trường Đại học Công nghệ Thông tin — ĐHQG-HCM (UIT)**.
