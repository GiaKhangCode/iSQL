# 📚 Cẩm Nang Kiến Trúc & Chi Tiết Cấu Trúc Thư Mục Dự Án iSQL (Laravel 11)

Tài liệu này giải thích chi tiết vai trò, ý nghĩa, trách nhiệm và lý do tồn tại của từng thư mục trong dự án **iSQL Backend (Laravel 11)**. Cấu trúc này được xây dựng dựa trên nguyên lý kiến trúc hướng dịch vụ (**Service-Oriented Architecture**), tách biệt rõ ràng giữa tầng Giao tiếp (HTTP/Controller), tầng Nghiệp vụ (Service), tầng Dữ liệu (Model/Migration) và tầng Xử lý bất đồng bộ (Jobs/Queue).

> 💡 **Mẹo:** Bạn có thể **click chuột trực tiếp vào tên bất kỳ file/thư mục nào có màu xanh** trong tài liệu này để mở nhanh file đó ngay trong trình soạn thảo.

---

## 🗺️ Bản Đồ Cây Thư Mục Tổng Quan

```
/var/www/html/
├── app/                  # Trọng tâm mã nguồn: Controllers, Models, Services, Jobs...
│   ├── Console/          # Lệnh CLI / Cronjobs tùy biến
│   ├── Exceptions/       # Xử lý ngoại lệ, định dạng lỗi JSON toàn cục
│   ├── Http/             # Tầng tương tác mạng HTTP (Controllers, Middleware, Requests)
│   ├── Jobs/             # Tác vụ xử lý ngầm (Background Queue Jobs)
│   ├── Models/           # Tầng dữ liệu Eloquent ORM (MySQL)
│   ├── Providers/        # Khởi tạo và liên kết các dịch vụ lõi (Service Providers)
│   └── Services/         # Tầng logic nghiệp vụ cốt lõi & CRUD Services
│       ├── UserService.php           # Nghiệp vụ quản lý user, auth, duyệt giảng viên
│       ├── ProblemService.php        # Nghiệp vụ CRUD câu hỏi, đồng bộ topics, test cases
│       ├── AssignmentService.php     # Nghiệp vụ CRUD bài tập & contest, gán lớp, câu hỏi
│       ├── ClassService.php          # Nghiệp vụ quản lý lớp học và ghi danh sinh viên
│       ├── SubmissionQueueService.php# Quản lý hàng đợi chấm bài Redis
│       ├── SqlServer/                # Sandbox, QueryRewriter, SchemaInspector
│       └── Grading/                  # DdlGrader, DmlGrader, SqlQueryGrader, ContestGrading
├── bootstrap/            # Khởi động ứng dụng (Application Bootstrapping & Cache)
├── config/               # Cấu hình tập trung toàn bộ hệ thống
├── database/             # Quản lý cấu trúc CSDL và nạp dữ liệu mẫu
│   ├── factories/        # Tạo dữ liệu giả lập tự động phục vụ test
│   ├── migrations/       # Quản lý phiên bản bảng CSDL (MySQL)
│   └── seeders/          # Tạo tài khoản demo và danh mục ban đầu
├── docker/               # Môi trường container hóa (PHP-FPM, Nginx, MySQL, Redis)
├── public/               # Web Root duy nhất được Web Server (Nginx) trỏ vào
├── resources/            # Tài nguyên giao diện HTML/Blade, ảnh, tài liệu tĩnh
├── routes/               # Bộ định tuyến URL API và Web
├── storage/              # Nơi lưu trữ tạm thời, file upload, sessions, logs
├── tests/                # Bộ kiểm thử tự động (Feature & Unit Tests)
└── vendor/               # Thư viện bên thứ ba cài qua Composer
```

---

## 📂 Chi Tiết Từng Thư Mục

---

### 1. [app/](./app) — Trái Tim Của Ứng Dụng

Thư mục `app/` là nơi chứa hầu hết toàn bộ mã nguồn nghiệp vụ của hệ thống.

#### 1.1 [app/Http/](./app/Http)
* **Vai trò:** Quản lý toàn bộ giao tiếp vào/ra thông qua giao thức HTTP (RESTful API).
* **Tại sao cần có:** Tách biệt hoàn toàn việc nhận request và trả response ra khỏi logic tính toán nghiệp vụ (giúp code sạch, dễ viết Unit Test).
* **Các thư mục con:**
  * **[Controllers/](./app/Http/Controllers)**: Bộ điều khiển nhận yêu cầu từ client, gọi các Service để xử lý và trả về JSON:
    * [Auth/AuthController.php](./app/Http/Controllers/Auth/AuthController.php): Đăng nhập, đăng ký sinh viên, đăng ký giảng viên, cấp JWT/Sanctum token.
    * [Admin/UserController.php](./app/Http/Controllers/Admin/UserController.php): Quản trị viên quản lý danh sách user, kích hoạt/khóa tài khoản, đổi vai trò.
    * [Admin/ClassController.php](./app/Http/Controllers/Admin/ClassController.php): Quản lý danh sách lớp học toàn trường.
    * [Admin/OverviewController.php](./app/Http/Controllers/Admin/OverviewController.php): Thống kê tổng quan số lượng sinh viên, giảng viên, bài nộp.
    * [Teacher/ProblemController.php](./app/Http/Controllers/Teacher/ProblemController.php): Giảng viên CRUD ngân hàng câu hỏi, test truy vấn.
    * [Teacher/AssignmentController.php](./app/Http/Controllers/Teacher/AssignmentController.php): Giảng viên tạo bài tập & kỳ thi contest.
    * [Teacher/ClassController.php](./app/Http/Controllers/Teacher/ClassController.php): Giảng viên quản lý lớp học mình phụ trách.
    * [Teacher/ReviewController.php](./app/Http/Controllers/Teacher/ReviewController.php): Chấm điểm thủ công và nhận xét bài nộp.
    * [Student/DashboardController.php](./app/Http/Controllers/Student/DashboardController.php): Dashboard sinh viên, streak ngày làm bài, lịch sử gần đây.
    * [Student/AssignmentController.php](./app/Http/Controllers/Student/AssignmentController.php): Danh sách bài tập được giao cho sinh viên.
    * [Student/PracticeController.php](./app/Http/Controllers/Student/PracticeController.php): Danh sách bài tập luyện tập công khai.
    * [Student/PreferenceController.php](./app/Http/Controllers/Student/PreferenceController.php): Quản lý bài tập yêu thích và danh sách tự tạo.
    * [Student/PersonalDbController.php](./app/Http/Controllers/Student/PersonalDbController.php): Thao tác với database cá nhân để luyện tập.
    * [Contest/ExamController.php](./app/Http/Controllers/Contest/ExamController.php): Sandbox thi SQL Server (setup database, chạy DDL, seed DML, chạy query, nộp bài).
    * [SubmissionController.php](./app/Http/Controllers/SubmissionController.php): Tra cứu lịch sử nộp bài của sinh viên và chi tiết bài làm.
    * [NotificationController.php](./app/Http/Controllers/NotificationController.php): Xem thông báo và đánh dấu tất cả là đã đọc.
  * **[Middleware/](./app/Http/Middleware)**: "Lớp lọc" trung gian giữa client và controller:
    * [RoleMiddleware.php](./app/Http/Middleware/RoleMiddleware.php): Kiểm tra phân quyền truy cập (`student`, `instructor`, `admin`).
    * [EnsureActiveAccount.php](./app/Http/Middleware/EnsureActiveAccount.php): Chặn những tài khoản đang ở trạng thái `Pending` (chờ duyệt) hoặc `Inactive`.
    * *Tại sao cần:* Ngăn chặn request không hợp lệ ngay từ cửa sổ, bảo mật API mà không cần lặp lại code kiểm tra trong từng hàm Controller.
  * **[Requests/](./app/Http/Requests)**: Tầng xác thực dữ liệu đầu vào (Form Request Validation):
    * [LoginRequest.php](./app/Http/Requests/Auth/LoginRequest.php), [RegisterRequest.php](./app/Http/Requests/Auth/RegisterRequest.php), [RegisterLecturerRequest.php](./app/Http/Requests/Auth/RegisterLecturerRequest.php): Kiểm tra định dạng email, password khi xác thực.
    * [ProblemRequest.php](./app/Http/Requests/ProblemRequest.php): Kiểm tra tiêu đề, độ khó, mô tả câu hỏi.
    * [AssignmentRequest.php](./app/Http/Requests/AssignmentRequest.php): Kiểm tra cấu hình bài thi (thời gian mở/đóng, điểm DDL/DML/SQL).
    * [RunQueryRequest.php](./app/Http/Requests/RunQueryRequest.php), [SubmitExamRequest.php](./app/Http/Requests/SubmitExamRequest.php): Kiểm tra câu query gửi lên.
    * *Tại sao cần:* Tự động kiểm tra dữ liệu gửi lên trước khi Controller chạy. Nếu sai, tự động trả về lỗi `422 Unprocessable Entity` kèm thông báo lỗi rõ ràng.

#### 1.2 [app/Models/](./app/Models)
* **Vai trò:** Đại diện cho các bảng dữ liệu trong MySQL thông qua Eloquent ORM.
* **Tại sao cần có:** Giúp thao tác với database dưới dạng hướng đối tượng (OOP) thay vì phải viết các câu lệnh SQL dài dòng thủ công.
* **Các file tiêu biểu:**
  * [User.php](./app/Models/User.php): Tài khoản người dùng (Admin, Instructor, Student), lưu streak, hash password.
  * [Problem.php](./app/Models/Problem.php): Ngân hàng câu hỏi SQL.
  * [Topic.php](./app/Models/Topic.php): Chủ đề SQL (SELECT, JOIN, Subqueries...).
  * [TestCase.php](./app/Models/TestCase.php) & [TestCaseScript.php](./app/Models/TestCaseScript.php): Kịch bản test case và script tạo bảng mẫu.
  * [Assignment.php](./app/Models/Assignment.php): Bài tập và kỳ thi contest toàn diện.
  * [ClassModel.php](./app/Models/ClassModel.php) & [ClassEnrollment.php](./app/Models/ClassEnrollment.php): Quản lý lớp học và danh sách sinh viên đăng ký.
  * [Submission.php](./app/Models/Submission.php): Lịch sử bài làm, câu query đã nộp, điểm số và phản hồi.
  * [ProblemDraft.php](./app/Models/ProblemDraft.php): Bản nháp câu truy vấn sinh viên đang viết dở.
  * [Favorite.php](./app/Models/Favorite.php): Danh sách bài tập yêu thích của từng sinh viên.
  * [ProblemList.php](./app/Models/ProblemList.php) & [ProblemListItem.php](./app/Models/ProblemListItem.php): Danh sách câu hỏi tự tạo.
  * [AiChatSession.php](./app/Models/AiChatSession.php) & [AiChatMessage.php](./app/Models/AiChatMessage.php): Phiên chat trợ lý ảo AI hỗ trợ học tập.
  * [Traits/HasUuid.php](./app/Models/Traits/HasUuid.php): Trait tự động sinh chuỗi UUID (`VARCHAR(50)`) làm khóa chính, đảm bảo tương thích 100% với hệ thống cũ.

#### 1.3 [app/Services/](./app/Services)
* **Vai trò:** Tầng xử lý logic nghiệp vụ cốt lõi (Business Logic Layer) theo chuẩn **Service Pattern**.
* **Tại sao cần có:** Tách toàn bộ logic nghiệp vụ và giao dịch database ra khỏi Controller ("Thin Controller"). Controller chỉ làm nhiệm vụ nhận request và trả JSON, còn Service chịu trách nhiệm xử lý logic, transaction và tái sử dụng ở nhiều nơi.
* **Các Service nghiệp vụ CRUD (Domain Services):**
  * [UserService.php](./app/Services/UserService.php): Xử lý toàn bộ logic tài khoản, xác thực mật khẩu hash, đăng ký sinh viên/giảng viên, duyệt trạng thái tài khoản (`Active`/`Pending`) và phân quyền.
  * [ProblemService.php](./app/Services/ProblemService.php): Xử lý CRUD câu hỏi, đồng bộ quan hệ nhiều-nhiều với Topics, bọc transaction khi tạo/xóa câu hỏi kèm test cases.
  * [AssignmentService.php](./app/Services/AssignmentService.php): Xử lý CRUD bài tập & kỳ thi contest, đồng bộ lớp học được gán và danh sách câu hỏi trong `DB::transaction`, lọc danh sách bài tập theo lớp sinh viên đã ghi danh.
  * [ClassService.php](./app/Services/ClassService.php): Quản lý tạo lớp học, phân công giảng viên và ghi danh sinh viên vào lớp (`ClassEnrollment`).
* **Các Service hạ tầng & chấm thi (Infrastructure & Grading Services):**
  * **[SqlServer/](./app/Services/SqlServer)**:
    * [SandboxService.php](./app/Services/SqlServer/SandboxService.php): Tạo và xóa schema riêng biệt trên Azure SQL Server cho từng sinh viên làm bài thi.
    * [QueryRewriter.php](./app/Services/SqlServer/QueryRewriter.php): Gắn tiền tố schema `[student_schema].[table_name]` vào câu lệnh SQL của sinh viên để cô lập dữ liệu.
    * [SchemaInspector.php](./app/Services/SqlServer/SchemaInspector.php): Đọc danh mục bảng/cột từ `sys.tables` để vẽ sơ đồ ERD cho sinh viên kiểm tra.
  * **[Grading/](./app/Services/Grading)**:
    * [DdlGrader.php](./app/Services/Grading/DdlGrader.php): Chấm điểm cấu trúc bảng sinh viên tạo so với schema chuẩn của giảng viên (30%).
    * [DmlGrader.php](./app/Services/Grading/DmlGrader.php): Kiểm tra xem dữ liệu mẫu nạp vào có bị lỗi ràng buộc khóa ngoại không (20%).
    * [SqlQueryGrader.php](./app/Services/Grading/SqlQueryGrader.php): Thực thi câu truy vấn của sinh viên và so sánh bảng kết quả với đáp án của giáo viên (50%).
    * [ContestGradingService.php](./app/Services/Grading/ContestGradingService.php): Nhạc trưởng tổng hợp 3 phần thi để tính điểm tổng.
  * [SubmissionQueueService.php](./app/Services/SubmissionQueueService.php): Điều phối việc đẩy bài nộp vào hàng đợi Redis để chấm điểm ngầm.

#### 1.4 [app/Jobs/](./app/Jobs)
* **Vai trò:** Chứa các tác vụ chạy ngầm bất đồng bộ (Background Queue Jobs).
* **Tại sao cần có:** Khi hàng trăm sinh viên cùng nhấn "Nộp bài thi" cùng một lúc, việc kết nối sang SQL Server để chấm bài sẽ mất từ vài giây đến nửa phút. Nếu chạy trực tiếp trên Controller, server sẽ nghẽn và sinh viên bị timeout. Bằng cách đẩy vào `Jobs`, server phản hồi ngay lập tức cho sinh viên ("Đã nhận bài"), sau đó Queue Worker (`Redis`) sẽ lấy từng Job ra xử lý ngầm.
* **Các file tiêu biểu:**
  * [GradeSubmissionJob.php](./app/Jobs/GradeSubmissionJob.php): Chấm điểm bài làm luyện tập.
  * [GradeContestExamJob.php](./app/Jobs/GradeContestExamJob.php): Chấm điểm bài thi kỳ thi toàn diện (DDL + DML + SQL).

#### 1.5 [app/Console/](./app/Console)
* **Vai trò:** Nơi chứa các lệnh dòng lệnh tùy biến (`php artisan custom:command`) hoặc cronjob định kỳ.
* **Tại sao cần có:** Phục vụ các tác vụ bảo trì hệ thống, ví dụ: tự động dọn dẹp các schema sandbox cũ sau khi kỳ thi kết thúc, gửi email nhắc nhở bài tập lúc 0h.

#### 1.6 [app/Exceptions/](./app/Exceptions)
* **Vai trò:** Bắt và định dạng toàn bộ lỗi hệ thống (Global Exception Handling).
* **Tại sao cần có:** Vì iSQL là Backend API-Only cho React frontend, mọi lỗi (kể cả lỗi SQL Server chết kết nối, 404, 500) đều phải được chuyển đổi thành JSON chuẩn (`{ "detail": "..." }`) thay vì trả về trang HTML màu vàng/cam mặc định của Laravel.

#### 1.7 [app/Providers/](./app/Providers)
* **Vai trò:** Khởi động và cấu hình các dịch vụ nền tảng (Bootstrapping Service Container).
* **File tiêu biểu:** [AppServiceProvider.php](./app/Providers/AppServiceProvider.php) — dùng để khai báo các cấu hình toàn cục như format phân trang, đăng ký schema mặc định cho MySQL.

---

### 2. [bootstrap/](./bootstrap) — Khởi Động Ứng Dụng

* **Vai trò:** Nơi chứa mã nguồn khởi tạo nền tảng của Laravel.
* **File quan trọng:**
  * [bootstrap/app.php](./bootstrap/app.php): File trung tâm của Laravel 11, cấu hình routing (`web`, `api`, `commands`), đăng ký Middleware alias (`role`, `active`), và bộ xử lý ngoại lệ.
  * [bootstrap/providers.php](./bootstrap/providers.php): Đăng ký danh sách các Service Provider khởi chạy cùng ứng dụng.
* **Tại sao cần có:** Đảm bảo toàn bộ framework được nạp theo đúng trình tự trước khi xử lý request đầu tiên.

---

### 3. [config/](./config) — Cấu Hình Tập Trung

* **Vai trò:** Lưu trữ tất cả các thiết lập của framework và các module.
* **Tại sao cần có:** Thay vì viết "cứng" (hardcode) các thông số trong code, mọi thông số đều được đưa vào đây và lấy giá trị động từ file môi trường [.env](./.env).
* **File quan trọng nhất đối với iSQL:**
  * [config/database.php](./config/database.php): **Cấu hình Dual-Database**. Khai báo kết nối `mysql` (chứa dữ liệu nghiệp vụ sinh viên, bài tập) và kết nối `sqlsrv` (kết nối sang SQL Server Sandbox với timeout 5 giây chống treo truy vấn).
  * [config/auth.php](./config/auth.php): Khai báo cơ chế bảo mật (Sanctum / JWT).
  * [config/queue.php](./config/queue.php): Cấu hình driver hàng đợi Redis.

---

### 4. [database/](./database) — Cơ Sở Dữ Liệu & Dữ Liệu Khởi Tạo

* **Vai trò:** Quản trị cấu trúc database bằng code (Database as Code).
* **Các thư mục con:**
  * **[migrations/](./database/migrations)**: Chứa 20 file migration định nghĩa cấu trúc của 20 bảng MySQL theo đúng Blueprint (`0001_create_users_table.php` đến `0020_create_assignment_problems_table.php`).
    * *Tại sao cần:* Giúp kiểm soát phiên bản CSDL (tương tự Git). Bất kỳ thành viên nào trong nhóm chỉ cần gõ `php artisan migrate` là có ngay cơ sở dữ liệu hoàn chỉnh giống hệt nhau mà không cần xuất/nhập file `.sql` thủ công.
  * **[seeders/](./database/seeders)**:
    * [DatabaseSeeder.php](./database/seeders/DatabaseSeeder.php): Bộ gọi seeder chính.
    * [UserSeeder.php](./database/seeders/UserSeeder.php): Tạo sẵn các tài khoản demo (Admin UIT, Giảng viên, Sinh viên).
    * [TopicSeeder.php](./database/seeders/TopicSeeder.php): Tạo danh mục chủ đề SQL ban đầu (SELECT, JOIN, Subqueries...).
    * *Tại sao cần:* Giúp hệ thống có sẵn dữ liệu để phát triển và test ngay lập tức sau khi cài đặt.
  * **[factories/](./database/factories)**: Nơi định nghĩa các bản mẫu để sinh hàng nghìn dòng dữ liệu ảo (dummy data) phục vụ kiểm thử hiệu năng.

---

### 5. [docker/](./docker) — Môi Trường Ảo Hóa Docker

* **Vai trò:** Đóng gói toàn bộ môi trường chạy ứng dụng để triển khai dễ dàng trên mọi máy tính.
* **Tại sao cần có:** Giải quyết vấn đề muôn thuở *"Code chạy trên máy tôi nhưng không chạy trên máy bạn"*. Đặc biệt, việc cài đặt driver `pdo_sqlsrv` và `msodbcsql18` trên Linux rất phức tạp; Dockerfile sẽ tự động hóa 100% công đoạn này.
* **Các file bên trong:**
  * [Dockerfile](./docker/Dockerfile): Định nghĩa container PHP 8.2-FPM cùng các extension bắt buộc (`pdo_mysql`, `pdo_sqlsrv`, `redis`).
  * [nginx.conf](./docker/nginx.conf): Cấu hình máy chủ web Nginx làm Reverse Proxy, chuyển tiếp các request `.php` sang PHP-FPM.
  * [docker-compose.yml](./docker/docker-compose.yml): Kéo 4 container (`php`, `nginx`, `mysql:8.0`, `redis:alpine`) cùng mạng `isql_network`.

---

### 6. [public/](./public) — Thư Mục Công Khai (Web Root)

* **Vai trò:** Thư mục duy nhất mà Web Server (Nginx/Apache) được phép truy cập trực tiếp từ Internet.
* **Tại sao cần có (Rất quan trọng về mặt bảo mật):**
  * Toàn bộ mã nguồn nhạy cảm (`app/`, `.env`, `config/`) nằm bên ngoài `public/`. Nếu kẻ tấn công cố tình duyệt file, chúng không thể đọc trộm file [.env](./.env) chứa mật khẩu database.
* **Các file bên trong:**
  * [public/index.php](./public/index.php): **Entry Point duy nhất**. Mọi request gửi đến hệ thống đều đi qua file này để nạp autoload và khởi động Laravel.
  * [public/.htaccess](./public/.htaccess): Cấu hình Rewrite URL cho Apache.
  * [public/robots.txt](./public/robots.txt): Chỉ thị cho máy tìm kiếm.

---

### 7. [resources/](./resources) — Tài Nguyên Ứng Dụng

* **Vai trò:** Chứa các tài nguyên chưa qua biên dịch (Raw assets) hoặc giao diện hiển thị.
* **Tại sao cần có:**
  * Dù iSQL Backend là API-Only, thư mục này vẫn chứa [welcome.blade.php](./resources/views/welcome.blade.php) (trang chủ mặc định hiển thị thông tin khi truy cập trực tiếp đường dẫn gốc `http://localhost:8000`).

---

### 8. [routes/](./routes) — Bộ Định Tuyến Hệ Thống

* **Vai trò:** Bản đồ chỉ dẫn: "Khi người dùng gọi URL này với phương thức này, hàm nào trong Controller sẽ chịu trách nhiệm trả lời?".
* **Tại sao cần chia nhỏ file trong iSQL:**
  * Thay vì dồn hàng trăm route vào một file `api.php` duy nhất gây khó quản lý khi làm việc nhóm (dễ bị xung đột Git Conflict), các route được phân chia theo đúng phân hệ nghiệp vụ:
  * [routes/api.php](./routes/api.php): Loader trung tâm gom các file bên dưới.
  * [routes/auth.php](./routes/auth.php): `/api/auth/*`.
  * [routes/admin.php](./routes/admin.php): `/api/admin/*`.
  * [routes/teacher.php](./routes/teacher.php): `/api/teacher/*`.
  * [routes/student.php](./routes/student.php): `/api/student/*`.
  * [routes/problems.php](./routes/problems.php): `/api/problems/*`.
  * [routes/assignments.php](./routes/assignments.php): `/api/assignments/*`.
  * [routes/contests.php](./routes/contests.php): `/api/contests/*`.
  * [routes/submissions.php](./routes/submissions.php): `/api/submissions/*`.
  * [routes/notifications.php](./routes/notifications.php): `/api/notifications/*`.
  * [routes/web.php](./routes/web.php): Route giao diện web mặc định.
  * [routes/console.php](./routes/console.php): Định nghĩa các lệnh console closure.

---

### 9. [storage/](./storage) — Lưu Trữ Tự Động Của Hệ Thống

* **Vai trò:** Nơi chứa tất cả các dữ liệu được tạo ra trong quá trình ứng dụng vận hành.
* **Tại sao cần có:** Thư mục này cần cấp quyền ghi (`chmod -R 775 storage`).
  * `storage/app/`: Chứa file upload của sinh viên / giảng viên (ảnh banner bài thi, tài liệu đính kèm).
  * `storage/framework/`: Lưu session, views biên dịch tạm thời.
  * `storage/logs/laravel.log`: Ghi nhận toàn bộ lỗi hệ thống phát sinh trong quá trình chạy thực tế để lập trình viên tra cứu và sửa lỗi (debugging).

---

### 10. [tests/](./tests) — Bộ Kiểm Thử Tự Động (Automated Testing)

* **Vai trò:** Chứa các kịch bản kiểm tra chất lượng code tự động bằng PHPUnit / Pest.
* **Tại sao cần có:** Đảm bảo khi sửa đổi code ở một module thì các module khác không bị hỏng (Regression Testing).
* **Các thư mục con:**
  * **[Feature/](./tests/Feature)**: Kiểm thử tích hợp từ đầu đến cuối (Integration/API Test) bằng cách giả lập gửi request HTTP:
    * [AuthTest.php](./tests/Feature/AuthTest.php): Kiểm thử luồng đăng nhập, bắt lỗi thiếu thông tin.
    * [ContestExamFlowTest.php](./tests/Feature/ContestExamFlowTest.php): Kiểm thử quy trình thi SQL của sinh viên.
    * [ProblemCrudTest.php](./tests/Feature/ProblemCrudTest.php): Kiểm thử CRUD ngân hàng câu hỏi.
    * [SubmissionTest.php](./tests/Feature/SubmissionTest.php): Kiểm thử nộp và tra cứu bài nộp.
  * **[Unit/](./tests/Unit)**: Kiểm thử độc lập từng phương thức thuật toán nhỏ:
    * [QueryRewriterTest.php](./tests/Unit/QueryRewriterTest.php): Kiểm tra thuật toán regex gắn schema vào câu truy vấn có hoạt động chuẩn xác không.
    * [DdlGraderTest.php](./tests/Unit/DdlGraderTest.php): Kiểm tra cấu trúc phản hồi chấm DDL.
    * [SqlQueryGraderTest.php](./tests/Unit/SqlQueryGraderTest.php): Kiểm tra khởi tạo dịch vụ chấm truy vấn SQL.

---

### 11. [vendor/](./vendor) & Các File Cấu Hình Gốc

* **`vendor/`**: Chứa toàn bộ các thư viện PHP bên thứ ba được cài đặt tự động qua Composer (Laravel framework, Sanctum, Predis...). Thư mục này không bao giờ được commit lên Git (đã nằm trong `.gitignore`).
* **[composer.json](./composer.json)** & **`composer.lock`**: Khai báo danh sách các thư viện PHP và phiên bản chính xác của chúng.
* **[.env](./.env)** & **[.env.example](./.env.example)**: Lưu trữ các bí mật môi trường (thông tin đăng nhập database MySQL, tài khoản Azure SQL Server, Secret Key). File `.env` chứa thông tin thật trên máy bạn, còn `.env.example` là file mẫu an toàn để đồng đội tham khảo.
* **[artisan](./artisan)**: File thực thi CLI của Laravel. Cung cấp hàng trăm lệnh tiện ích: chạy server, tạo controller, chạy migration (`php artisan ...`).
* **[phpunit.xml](./phpunit.xml)**: Cấu hình môi trường chạy kiểm thử tự động.
* **[backend_laravel_blueprint.md](./backend_laravel_blueprint.md)**: Bản thiết kế kiến trúc chi tiết ban đầu của hệ thống iSQL.
