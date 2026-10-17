# 📘 iSQL Backend Laravel — Bản Thiết Kế Chi Tiết (Blueprint)

> **Mục tiêu:** Chuyển đổi Backend từ Python/FastAPI sang PHP/Laravel 11, Database nghiệp vụ từ SQL Server sang MySQL 8, trong khi giữ nguyên SQL Server làm Sandbox thực thi truy vấn cho sinh viên.

---

## 1. Tổng Quan Dự Án

### 1.1 Mô tả hệ thống

**iSQL** là hệ thống Luyện tập & Thi thực hành Cơ sở dữ liệu SQL, phục vụ sinh viên và giảng viên tại trường Đại học Công nghệ Thông tin (UIT). Hệ thống cho phép:

- **Sinh viên:** Luyện tập truy vấn SQL trực tuyến, tham gia bài tập do giảng viên giao, tham gia kỳ thi thực hành (DDL + DML + SQL Queries) trên cơ sở dữ liệu cá nhân (Sandbox).
- **Giảng viên:** Soạn ngân hàng câu hỏi, tạo bài tập và kỳ thi toàn diện, chấm bài tự động và thủ công, xem thống kê kết quả.
- **Quản trị viên (Admin):** Quản lý tài khoản người dùng, phê duyệt giảng viên, quản lý danh mục môn học.

### 1.2 Kiến trúc tổng thể (Dual-Database Architecture)

```
                       [React Frontend (Vite)]
                      http://localhost:5173
                              │
                              │  RESTful JSON API
                              ▼
                ┌─────────────────────────────┐
                │   PHP Backend (Laravel 11)  │
                │   http://localhost:8000      │
                │   (API-Only, Stateless JWT) │
                └──────┬──────────────┬───────┘
                       │              │
          ┌────────────▼──┐     ┌─────▼──────────────┐
          │  MySQL 8.0    │     │  SQL Server (Azure) │
          │  (Nghiệp vụ) │     │  (Sandbox Thi cử)   │
          │               │     │                     │
          │ • users       │     │ • Schema cá nhân    │
          │ • problems    │     │   của từng sinh viên│
          │ • assignments │     │ • Chạy DDL / DML    │
          │ • submissions │     │ • Chạy truy vấn SQL │
          │ • classes     │     │ • Chấm điểm đối     │
          │ • contests    │     │   chiếu kết quả     │
          └───────────────┘     └─────────────────────┘
       Connection: mysql          Connection: sqlsrv
       (config/database.php)      (config/database.php)
```

### 1.3 Stack Công nghệ

| Thành phần | Công nghệ |
| :--- | :--- |
| **Backend Framework** | PHP 8.2 + Laravel 11 (API-Only) |
| **Database Nghiệp vụ** | MySQL 8.0 |
| **Database Sandbox** | SQL Server (Azure SQL / Docker `mcr.microsoft.com/mssql/server`) |
| **Xác thực** | Laravel Sanctum hoặc `tymon/jwt-auth` (Stateless JWT Bearer Token) |
| **Queue / Background Jobs** | Redis + Laravel Queue Worker (chấm bài bất đồng bộ) |
| **Frontend** | React 18 + Vite + TypeScript (giữ nguyên, không sửa) |
| **Containerization** | Docker + Docker Compose |
| **Driver SQL Server cho PHP** | `pdo_sqlsrv` + `msodbcsql18` |

---

## 2. Cấu Trúc Thư Mục Dự Án Laravel

```
iSQL-backend/
│
├── app/
│   ├── Console/
│   │   └── Kernel.php                    # Đăng ký scheduled commands
│   │
│   ├── Exceptions/
│   │   └── Handler.php                   # Xử lý exception toàn cục (JSON errors)
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   └── AuthController.php            # Đăng nhập, Đăng ký, Duyệt GV, /me
│   │   │   │
│   │   │   ├── Admin/
│   │   │   │   ├── UserController.php            # CRUD User, khóa/mở tài khoản
│   │   │   │   ├── ClassController.php           # Quản lý lớp học (Admin)
│   │   │   │   └── OverviewController.php        # Dashboard thống kê hệ thống
│   │   │   │
│   │   │   ├── Teacher/
│   │   │   │   ├── ProblemController.php         # CRUD Ngân hàng câu hỏi
│   │   │   │   ├── AssignmentController.php      # CRUD Bài tập & Contest
│   │   │   │   ├── ClassController.php           # Quản lý lớp học (GV)
│   │   │   │   └── ReviewController.php          # Chấm bài thủ công
│   │   │   │
│   │   │   ├── Student/
│   │   │   │   ├── DashboardController.php       # Dashboard & streak sinh viên
│   │   │   │   ├── AssignmentController.php      # Xem & làm bài tập
│   │   │   │   ├── PracticeController.php        # Luyện tập SQL
│   │   │   │   ├── PreferenceController.php      # Favorites, custom lists, theme
│   │   │   │   └── PersonalDbController.php      # CSDL cá nhân (Practice)
│   │   │   │
│   │   │   ├── Contest/
│   │   │   │   └── ExamController.php            # Sandbox thi: setup, DDL, DML, query, submit
│   │   │   │
│   │   │   ├── SubmissionController.php          # Lịch sử bài nộp (chung)
│   │   │   └── NotificationController.php        # Thông báo
│   │   │
│   │   ├── Middleware/
│   │   │   ├── RoleMiddleware.php                # Kiểm tra role: admin, instructor, student
│   │   │   └── EnsureActiveAccount.php           # Chặn tài khoản Pending / Inactive
│   │   │
│   │   └── Requests/                             # Form Requests (Validation)
│   │       ├── Auth/
│   │       │   ├── LoginRequest.php
│   │       │   ├── RegisterRequest.php
│   │       │   └── RegisterLecturerRequest.php
│   │       ├── ProblemRequest.php
│   │       ├── AssignmentRequest.php
│   │       ├── RunQueryRequest.php
│   │       └── SubmitExamRequest.php
│   │
│   ├── Models/                                   # Eloquent Models (MySQL)
│   │   ├── User.php
│   │   ├── Notification.php
│   │   ├── ActivityLog.php
│   │   ├── Problem.php
│   │   ├── Topic.php
│   │   ├── TestCase.php
│   │   ├── TestCaseScript.php
│   │   ├── Submission.php
│   │   ├── ProblemDraft.php
│   │   ├── Favorite.php
│   │   ├── ProblemList.php
│   │   ├── ProblemListItem.php
│   │   ├── AiChatSession.php
│   │   ├── AiChatMessage.php
│   │   ├── ClassModel.php                        # Tránh xung đột từ khóa "Class" trong PHP
│   │   ├── ClassEnrollment.php
│   │   ├── Assignment.php
│   │   ├── AssignmentClass.php
│   │   └── AssignmentProblem.php
│   │
│   ├── Services/                                 # Business Logic Layer
│   │   ├── SqlServer/
│   │   │   ├── SandboxService.php                # Tạo/xóa schema, chạy DDL/DML trên SQL Server
│   │   │   ├── QueryRewriter.php                 # Gắn tiền tố [schema_name] vào câu SQL
│   │   │   └── SchemaInspector.php               # Truy vấn sys.tables/columns/foreign_keys
│   │   │
│   │   ├── Grading/
│   │   │   ├── ContestGradingService.php         # Chấm toàn diện DDL + DML + SQL
│   │   │   ├── DdlGrader.php                     # So sánh schema SV vs schema chuẩn
│   │   │   ├── DmlGrader.php                     # Kiểm tra tính tương thích dữ liệu
│   │   │   └── SqlQueryGrader.php                # Chạy & so sánh kết quả truy vấn
│   │   │
│   │   └── SubmissionQueueService.php            # Quản lý hàng đợi chấm bài (Redis Queue)
│   │
│   └── Jobs/                                     # Background Jobs (Laravel Queue)
│       ├── GradeSubmissionJob.php                # Chấm bài nền cho Practice & Assignment
│       └── GradeContestExamJob.php               # Chấm bài thi toàn diện (DDL+DML+SQL)
│
├── config/
│   ├── database.php                              # ⭐ Cấu hình Dual-DB: mysql + sqlsrv
│   ├── auth.php                                  # Cấu hình Sanctum / JWT Guard
│   └── queue.php                                 # Cấu hình Redis Queue
│
├── database/
│   ├── migrations/                               # ⭐ Toàn bộ file Migration MySQL
│   │   ├── 0001_create_users_table.php
│   │   ├── 0002_create_notifications_table.php
│   │   ├── 0003_create_activity_logs_table.php
│   │   ├── 0004_create_topics_table.php
│   │   ├── 0005_create_problems_table.php
│   │   ├── 0006_create_problem_topics_table.php
│   │   ├── 0007_create_test_cases_table.php
│   │   ├── 0008_create_test_case_scripts_table.php
│   │   ├── 0009_create_submissions_table.php
│   │   ├── 0010_create_problem_drafts_table.php
│   │   ├── 0011_create_favorites_table.php
│   │   ├── 0012_create_problem_lists_table.php
│   │   ├── 0013_create_problem_list_items_table.php
│   │   ├── 0014_create_ai_chat_sessions_table.php
│   │   ├── 0015_create_ai_chat_messages_table.php
│   │   ├── 0016_create_classes_table.php
│   │   ├── 0017_create_class_enrollments_table.php
│   │   ├── 0018_create_assignments_table.php
│   │   ├── 0019_create_assignment_classes_table.php
│   │   └── 0020_create_assignment_problems_table.php
│   │
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── UserSeeder.php                        # Tạo tài khoản demo: admin, instructor, student
│       └── TopicSeeder.php                       # Tạo danh mục chủ đề SQL: SELECT, JOIN, ...
│
├── routes/
│   ├── api.php                                   # Route loader chính (include các file bên dưới)
│   ├── auth.php                                  # /api/auth/*          (Dev 1)
│   ├── admin.php                                 # /api/admin/*         (Dev 1)
│   ├── teacher.php                               # /api/teacher/*       (Dev 3)
│   ├── student.php                               # /api/student/*       (Dev 4)
│   ├── problems.php                              # /api/problems/*      (Dev 3)
│   ├── submissions.php                           # /api/submissions/*   (Dev 4)
│   ├── notifications.php                         # /api/notifications/* (Dev 4)
│   └── contests.php                              # /api/contests/*      (Dev 2)
│
├── tests/
│   ├── Feature/
│   │   ├── AuthTest.php
│   │   ├── ProblemCrudTest.php
│   │   ├── ContestExamFlowTest.php
│   │   └── SubmissionTest.php
│   └── Unit/
│       ├── QueryRewriterTest.php
│       ├── DdlGraderTest.php
│       └── SqlQueryGraderTest.php
│
├── docker/
│   ├── Dockerfile                                # PHP 8.2-FPM + pdo_sqlsrv + msodbcsql18
│   ├── nginx.conf                                # Cấu hình Nginx reverse proxy
│   └── docker-compose.yml                        # php + nginx + mysql + redis
│
├── .env.example
├── composer.json
└── README.md
```

---

## 3. MySQL Migration — Chi Tiết Từng Bảng

> Tất cả các migration nằm trong `database/migrations/`. Dưới đây là schema tương ứng 1:1 với Python `models.py` hiện tại, chuyển đổi sang cú pháp Laravel Blueprint.

### 3.1 `users` — Bảng Người dùng

```php
// 0001_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->string('id', 50)->primary();                // UUID
    $table->string('email', 255)->unique();
    $table->string('hashed_password', 255);
    $table->string('name', 255);
    $table->string('initials', 10);
    $table->string('role', 50)->default('student');      // student | instructor | admin
    $table->string('status', 50)->default('Active');     // Active | Inactive | Pending
    $table->string('department', 255)->nullable();
    $table->integer('current_streak')->default(0);
    $table->integer('longest_streak')->default(0);
    $table->timestamp('last_streak_date')->nullable();
    $table->timestamp('last_active')->nullable();
    $table->timestamps();                                // created_at, updated_at
});
```

### 3.2 `notifications` — Bảng Thông báo

```php
// 0002_create_notifications_table.php
Schema::create('notifications', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50);
    $table->string('title', 255);
    $table->text('message');
    $table->string('type', 50);                          // info | success | warning | error
    $table->boolean('is_read')->default(false);
    $table->string('link', 255)->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->index('user_id');
});
```

### 3.3 `activity_logs` — Nhật ký hoạt động

```php
// 0003_create_activity_logs_table.php
Schema::create('activity_logs', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50)->nullable();
    $table->string('action', 255);
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
});
```

### 3.4 `topics` — Chủ đề SQL

```php
// 0004_create_topics_table.php
Schema::create('topics', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('name', 100)->unique();
});
```

### 3.5 `problems` — Ngân hàng câu hỏi

```php
// 0005_create_problems_table.php
Schema::create('problems', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('number', 50);
    $table->string('title', 255);
    $table->string('topic', 100)->nullable();
    $table->json('topics')->nullable();                  // Danh sách topic dạng JSON array
    $table->string('difficulty', 50);                    // Easy | Medium | Hard
    $table->text('description');
    $table->text('requirements');
    $table->text('hint')->nullable();
    $table->boolean('practice_listed')->default(true);
    $table->string('database_type', 50)->default('SQL Server');
    $table->string('creator_id', 50)->nullable();
    $table->json('tables')->nullable();                  // Định nghĩa DataTable dạng JSON
    $table->json('expected')->nullable();                // Kết quả mẫu dạng JSON
    $table->json('test_cases')->nullable();              // Test case Legacy dạng JSON

    $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
});
```

### 3.6 `problem_topics` — Quan hệ nhiều-nhiều Problem ↔ Topic

```php
// 0006_create_problem_topics_table.php
Schema::create('problem_topics', function (Blueprint $table) {
    $table->string('problem_id', 50);
    $table->string('topic_id', 50);
    $table->primary(['problem_id', 'topic_id']);

    $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
    $table->foreign('topic_id')->references('id')->on('topics')->onDelete('cascade');
});
```

### 3.7 `test_cases_rel` — Test case cho từng bài

```php
// 0007_create_test_cases_table.php
Schema::create('test_cases_rel', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('problem_id', 50);
    $table->boolean('is_hidden')->default(false);
    $table->integer('order_index')->default(0);
    $table->text('expected_query')->nullable();           // Câu truy vấn đáp án

    $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
    $table->index('problem_id');
});
```

### 3.8 `test_case_scripts` — Script tạo dữ liệu mẫu cho test case

```php
// 0008_create_test_case_scripts_table.php
Schema::create('test_case_scripts', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('test_case_id', 50);
    $table->string('table_name', 100);
    $table->text('create_script');
    $table->text('insert_script');
    $table->boolean('is_expected')->default(false);

    $table->foreign('test_case_id')->references('id')->on('test_cases_rel')->onDelete('cascade');
});
```

### 3.9 `submissions` — Lịch sử bài nộp

```php
// 0009_create_submissions_table.php
Schema::create('submissions', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50);
    $table->string('problem_id', 50);
    $table->string('source', 100);                       // Practice | Assignments | Contests
    $table->string('context', 255);                      // ID của Assignment/Contest
    $table->string('result', 100);                       // Accepted | Wrong Answer | Partial | Error
    $table->integer('score')->default(0);
    $table->timestamp('submitted_at')->useCurrent();
    $table->text('query_text')->nullable();
    $table->string('database_type', 50)->nullable();
    $table->integer('evaluated_score')->nullable();
    $table->text('feedback')->nullable();                 // Scorecard JSON khi thi

    $table->foreign('user_id')->references('id')->on('users');
    $table->foreign('problem_id')->references('id')->on('problems');
    $table->index(['user_id', 'problem_id']);
    $table->index('context');
});
```

### 3.10 `problem_drafts` — Bản nháp bài làm

```php
// 0010_create_problem_drafts_table.php
Schema::create('problem_drafts', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50);
    $table->string('problem_id', 50);
    $table->text('draft_query')->nullable();

    $table->foreign('user_id')->references('id')->on('users');
    $table->foreign('problem_id')->references('id')->on('problems');
    $table->unique(['user_id', 'problem_id']);
});
```

### 3.11 `favorites` — Bài tập yêu thích

```php
// 0011_create_favorites_table.php
Schema::create('favorites', function (Blueprint $table) {
    $table->string('user_id', 50);
    $table->string('problem_id', 50);
    $table->timestamp('created_at')->useCurrent();
    $table->primary(['user_id', 'problem_id']);

    $table->foreign('user_id')->references('id')->on('users');
    $table->foreign('problem_id')->references('id')->on('problems');
});
```

### 3.12 `problem_lists` & `problem_list_items` — Danh sách bài tập tùy chỉnh

```php
// 0012_create_problem_lists_table.php
Schema::create('problem_lists', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50);
    $table->string('name', 255);
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('user_id')->references('id')->on('users');
});

// 0013_create_problem_list_items_table.php
Schema::create('problem_list_items', function (Blueprint $table) {
    $table->string('list_id', 50);
    $table->string('problem_id', 50);
    $table->primary(['list_id', 'problem_id']);

    $table->foreign('list_id')->references('id')->on('problem_lists')->onDelete('cascade');
    $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
});
```

### 3.13 `ai_chat_sessions` & `ai_chat_messages` — Trợ lý AI

```php
// 0014_create_ai_chat_sessions_table.php
Schema::create('ai_chat_sessions', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('user_id', 50);
    $table->string('problem_id', 50);
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users');
    $table->foreign('problem_id')->references('id')->on('problems');
});

// 0015_create_ai_chat_messages_table.php
Schema::create('ai_chat_messages', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('session_id', 50);
    $table->string('role', 10);                          // 'user' | 'ai'
    $table->text('content');
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('session_id')->references('id')->on('ai_chat_sessions')->onDelete('cascade');
});
```

### 3.14 `classes` & `class_enrollments` — Lớp học

```php
// 0016_create_classes_table.php
Schema::create('classes', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('course', 255);                       // Tên môn: "Cơ sở dữ liệu"
    $table->string('term', 100);                         // Học kỳ: "HK1 2026-2027"
    $table->string('instructor_id', 50)->nullable();
    $table->string('start_date', 50)->nullable();
    $table->string('end_date', 50)->nullable();
    $table->string('status', 50)->default('Active');
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('instructor_id')->references('id')->on('users')->onDelete('set null');
});

// 0017_create_class_enrollments_table.php
Schema::create('class_enrollments', function (Blueprint $table) {
    $table->string('class_id', 50);
    $table->string('student_id', 50);
    $table->string('role', 50)->default('Member');
    $table->timestamp('joined_at')->useCurrent();
    $table->primary(['class_id', 'student_id']);

    $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
    $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
});
```

### 3.15 `assignments` — Bài tập & Kỳ thi (Contest)

```php
// 0018_create_assignments_table.php
Schema::create('assignments', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('title', 255);
    $table->boolean('is_contest')->default(false);
    $table->text('instructions')->nullable();
    $table->string('audience_type', 20)->default('classes');
    $table->string('short_description', 180)->nullable();
    $table->text('description')->nullable();
    $table->text('rules')->nullable();
    $table->string('banner_url', 255)->nullable();
    $table->string('banner_source_url', 255)->nullable();
    $table->string('banner_crop', 160)->nullable();
    $table->timestamp('opens')->nullable();
    $table->timestamp('closes')->nullable();
    $table->boolean('published')->default(false);
    $table->string('instructor_id', 50)->nullable();

    // Student Options
    $table->boolean('hints_enabled')->default(true);
    $table->boolean('comments_enabled')->default(true);
    $table->boolean('leaderboard_enabled')->default(false);
    $table->boolean('ai_allowed')->default(true);

    // Comprehensive Contest Exam Fields
    $table->string('exam_type', 50)->default('classic'); // classic | comprehensive
    $table->text('ddl_reference_script')->nullable();    // Script DDL chuẩn của GV
    $table->text('dml_seed_script')->nullable();         // Script DML dữ liệu mẫu
    $table->integer('ddl_points')->default(30);
    $table->integer('dml_points')->default(20);
    $table->integer('sql_points')->default(50);

    $table->timestamps();

    $table->foreign('instructor_id')->references('id')->on('users')->onDelete('set null');
});
```

### 3.16 `assignment_classes` & `assignment_problems` — Liên kết

```php
// 0019_create_assignment_classes_table.php
Schema::create('assignment_classes', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('assignment_id', 50);
    $table->string('class_id', 50);

    $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
    $table->foreign('class_id')->references('id')->on('classes')->onDelete('cascade');
    $table->unique(['assignment_id', 'class_id']);
});

// 0020_create_assignment_problems_table.php
Schema::create('assignment_problems', function (Blueprint $table) {
    $table->string('id', 50)->primary();
    $table->string('assignment_id', 50);
    $table->string('problem_id', 50);
    $table->integer('points')->default(10);
    $table->integer('order_index')->default(0);

    $table->foreign('assignment_id')->references('id')->on('assignments')->onDelete('cascade');
    $table->foreign('problem_id')->references('id')->on('problems')->onDelete('cascade');
});
```

---

## 4. API Endpoint Mapping (Python → PHP)

> Bảng ánh xạ 1:1 từ Backend Python hiện tại sang Backend Laravel mới. Frontend React gọi đúng các URL này nên **KHÔNG ĐƯỢC ĐỔI TÊN**.

### 4.1 Auth (`/api/auth`)

| Method | Endpoint | Controller | Ghi chú |
| :--- | :--- | :--- | :--- |
| POST | `/api/auth/login` | `AuthController@login` | Trả về JWT + user object |
| POST | `/api/auth/register` | `AuthController@register` | Tạo sinh viên mới |
| POST | `/api/auth/register-lecturer` | `AuthController@registerLecturer` | Tạo GV (status=Pending) |
| POST | `/api/auth/demo` | `AuthController@demoLogin` | Đăng nhập demo |
| POST | `/api/auth/token` | `AuthController@tokenLogin` | Cho Swagger UI |
| GET | `/api/auth/me` | `AuthController@me` | Trả info user từ JWT |

### 4.2 Admin (`/api/admin`)

| Method | Endpoint | Controller | Ghi chú |
| :--- | :--- | :--- | :--- |
| GET | `/api/admin/users` | `Admin\UserController@index` | Danh sách tất cả user |
| PUT | `/api/admin/users/{id}/status` | `Admin\UserController@updateStatus` | Kích hoạt / Khóa |
| PUT | `/api/admin/users/{id}/role` | `Admin\UserController@updateRole` | Đổi quyền |
| DELETE | `/api/admin/users/{id}` | `Admin\UserController@destroy` | Xóa tài khoản |
| GET | `/api/admin/overview` | `Admin\OverviewController@index` | Thống kê hệ thống |
| GET | `/api/admin/classes` | `Admin\ClassController@index` | Danh sách lớp học |

### 4.3 Teacher (`/api/teacher`, `/api/problems`, `/api/assignments`)

| Method | Endpoint | Controller |
| :--- | :--- | :--- |
| GET | `/api/problems` | `Teacher\ProblemController@index` |
| GET | `/api/problems/{id}` | `Teacher\ProblemController@show` |
| POST | `/api/problems` | `Teacher\ProblemController@store` |
| PUT | `/api/problems/{id}` | `Teacher\ProblemController@update` |
| DELETE | `/api/problems/{id}` | `Teacher\ProblemController@destroy` |
| POST | `/api/problems/{id}/run` | `Teacher\ProblemController@runQuery` |
| POST | `/api/problems/{id}/submit` | `Teacher\ProblemController@submit` |
| GET | `/api/teacher/classes` | `Teacher\ClassController@index` |
| POST | `/api/teacher/classes` | `Teacher\ClassController@store` |
| GET | `/api/assignments` | `Teacher\AssignmentController@index` |
| POST | `/api/assignments` | `Teacher\AssignmentController@store` |
| PUT | `/api/assignments/{id}` | `Teacher\AssignmentController@update` |
| DELETE | `/api/assignments/{id}` | `Teacher\AssignmentController@destroy` |

### 4.4 Student

| Method | Endpoint | Controller |
| :--- | :--- | :--- |
| GET | `/api/student/dashboard` | `Student\DashboardController@index` |
| GET | `/api/student/assignments` | `Student\AssignmentController@index` |
| GET | `/api/student/preferences` | `Student\PreferenceController@index` |
| POST | `/api/student/preferences/favorites/{id}` | `Student\PreferenceController@toggleFavorite` |
| GET | `/api/student/personal-db/status` | `Student\PersonalDbController@status` |
| POST | `/api/student/personal-db/run` | `Student\PersonalDbController@runQuery` |

### 4.5 Contest Exam (`/api/contests`) — ⭐ Sử dụng connection `sqlsrv`

| Method | Endpoint | Controller | SQL Server Action |
| :--- | :--- | :--- | :--- |
| POST | `/api/contests/{id}/setup-db` | `Contest\ExamController@setupDb` | Tạo schema cá nhân |
| POST | `/api/contests/{id}/run-ddl` | `Contest\ExamController@runDdl` | Thực thi CREATE TABLE |
| POST | `/api/contests/{id}/seed-dml` | `Contest\ExamController@seedDml` | Nạp dữ liệu mẫu |
| GET | `/api/contests/{id}/inspect-schema` | `Contest\ExamController@inspectSchema` | Đọc cấu trúc ERD |
| POST | `/api/contests/{id}/run-query` | `Contest\ExamController@runQuery` | Chạy thử truy vấn |
| POST | `/api/contests/{id}/submit-exam` | `Contest\ExamController@submitExam` | Chấm toàn diện |

### 4.6 Shared

| Method | Endpoint | Controller |
| :--- | :--- | :--- |
| GET | `/api/submissions` | `SubmissionController@index` |
| GET | `/api/submissions/{id}` | `SubmissionController@show` |
| PUT | `/api/submissions/{id}/review` | `SubmissionController@review` |
| GET | `/api/notifications` | `NotificationController@index` |
| POST | `/api/notifications/mark-read` | `NotificationController@markAllRead` |

---

## 5. Cấu Hình Dual-Database (`config/database.php`)

```php
'connections' => [

    // ── Connection 1: MySQL (Dữ liệu nghiệp vụ - MẶC ĐỊNH) ──
    'mysql' => [
        'driver'   => 'mysql',
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'sql_uit'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        'collation'=> 'utf8mb4_unicode_ci',
    ],

    // ── Connection 2: SQL Server (Sandbox thi cử) ──
    'sqlsrv' => [
        'driver'   => 'sqlsrv',
        'host'     => env('SQLSRV_HOST', 'your-azure-server.database.windows.net'),
        'port'     => env('SQLSRV_PORT', '1433'),
        'database' => env('SQLSRV_DATABASE', 'sql_practice'),
        'username' => env('SQLSRV_USERNAME', 'sa'),
        'password' => env('SQLSRV_PASSWORD', ''),
        'options'  => [
            PDO::ATTR_TIMEOUT => 5,                       // ⭐ Timeout 5 giây chống query treo
        ],
    ],

],

'default' => env('DB_CONNECTION', 'mysql'),
```

---

## 6. File `.env.example`

```env
APP_NAME="iSQL API"
APP_ENV=local
APP_KEY=
APP_URL=http://localhost:8000

# ── MySQL (Nghiệp vụ) ──
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sql_uit
DB_USERNAME=root
DB_PASSWORD=

# ── SQL Server (Sandbox) ──
SQLSRV_HOST=your-server.database.windows.net
SQLSRV_PORT=1433
SQLSRV_DATABASE=sql_practice
SQLSRV_USERNAME=sa
SQLSRV_PASSWORD=YourSecurePassword123

# ── JWT / Sanctum ──
JWT_SECRET=your-super-secret-jwt-key
JWT_ALGO=HS256
JWT_TTL=1440

# ── Redis (Queue chấm bài) ──
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# ── CORS ──
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

---

## 7. Sơ Đồ Quan Hệ MySQL (ERD)

```
┌──────────┐     ┌──────────────────┐     ┌────────────────────┐
│  users   │◄────│ class_enrollments │────►│     classes         │
│──────────│     │──────────────────│     │────────────────────│
│ id (PK)  │     │ class_id (FK)    │     │ id (PK)            │
│ email    │     │ student_id (FK)  │     │ course             │
│ name     │     │ role             │     │ term               │
│ role     │     └──────────────────┘     │ instructor_id (FK) │
│ status   │                              └────────────────────┘
└─────┬────┘                                      │
      │                                           │
      │     ┌──────────────────┐     ┌────────────▼───────────┐
      ├────►│  submissions     │     │   assignment_classes   │
      │     │──────────────────│     │────────────────────────│
      │     │ user_id (FK)     │     │ assignment_id (FK)     │
      │     │ problem_id (FK)  │     │ class_id (FK)          │
      │     │ source / context │     └────────────────────────┘
      │     │ result / score   │                ▲
      │     └──────────────────┘                │
      │                                ┌────────┴───────────────┐
      │     ┌──────────────────┐       │     assignments        │
      ├────►│  notifications   │       │────────────────────────│
      │     │──────────────────│       │ id (PK)                │
      │     │ user_id (FK)     │       │ title                  │
      │     │ title / message  │       │ is_contest             │
      │     └──────────────────┘       │ exam_type              │
      │                                │ ddl_reference_script   │
      │     ┌──────────────────┐       │ dml_seed_script        │
      ├────►│  favorites       │       │ instructor_id (FK)     │
      │     │──────────────────│       └────────┬───────────────┘
      │     │ user_id (FK)     │                │
      │     │ problem_id (FK)  │       ┌────────▼───────────────┐
      │     └──────────────────┘       │  assignment_problems   │
      │                                │────────────────────────│
      │     ┌──────────────────┐       │ assignment_id (FK)     │
      └────►│  problem_drafts  │       │ problem_id (FK)        │
            │──────────────────│       │ points / order_index   │
            │ user_id (FK)     │       └────────────────────────┘
            │ problem_id (FK)  │                ▲
            └──────────────────┘                │
                                       ┌────────┴───────────────┐
┌──────────────────┐                   │      problems          │
│     topics       │                   │────────────────────────│
│──────────────────│                   │ id (PK)                │
│ id (PK)          │◄─── problem_ ───►│ title / difficulty     │
│ name             │     topics        │ description            │
└──────────────────┘                   │ creator_id (FK)        │
                                       └────────┬───────────────┘
                                                │
                                       ┌────────▼───────────────┐
                                       │   test_cases_rel       │
                                       │────────────────────────│
                                       │ id (PK)                │
                                       │ problem_id (FK)        │
                                       │ expected_query         │
                                       └────────┬───────────────┘
                                                │
                                       ┌────────▼───────────────┐
                                       │  test_case_scripts     │
                                       │────────────────────────│
                                       │ test_case_id (FK)      │
                                       │ table_name             │
                                       │ create_script          │
                                       │ insert_script          │
                                       └────────────────────────┘
```

---

## 8. Checklist Khởi Tạo Dự Án

```bash
# 1. Tạo dự án Laravel 11 (API-Only)
composer create-project laravel/laravel iSQL-backend
cd iSQL-backend

# 2. Cài đặt các package cần thiết
composer require tymon/jwt-auth          # Hoặc dùng Laravel Sanctum
composer require predis/predis           # Redis client cho Queue

# 3. Cấu hình .env (sao chép từ .env.example)
cp .env.example .env
php artisan key:generate

# 4. Chạy Migration tạo toàn bộ bảng MySQL
php artisan migrate

# 5. Seed dữ liệu mẫu (tài khoản demo)
php artisan db:seed

# 6. Khởi động server phát triển
php artisan serve --port=8000

# 7. Khởi động Queue Worker (chấm bài nền)
php artisan queue:work redis --queue=grading
```

---

> **Ghi chú quan trọng:**
> - Toàn bộ API endpoint URL và cấu trúc JSON response phải **KHỚP 100%** với Backend Python hiện tại để Frontend React **không cần sửa bất kỳ dòng nào**.
> - SQL Server chỉ được gọi thông qua `DB::connection('sqlsrv')` trong các Service nằm ở `app/Services/SqlServer/`.
> - Tất cả các cột `id` dùng UUID string (`VARCHAR(50)`) thay vì auto-increment integer để tương thích với dữ liệu cũ.
