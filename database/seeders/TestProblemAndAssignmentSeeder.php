<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Problem;
use App\Models\ProblemSqlSolution;
use App\Models\TestCase;
use App\Models\TestCaseScript;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestProblemAndAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tạo Giảng viên Mai Xuân Hùng (tài khoản đăng nhập chính)
        $teacher = User::firstOrCreate(
            ['email' => 'hungxm@uit.edu.vn'],
            [
                'name'            => 'Mai Xuân Hùng',
                'initials'        => 'MH',
                'hashed_password' => Hash::make('password123'),
                'role'            => 'instructor_practical',
                'status'          => 'Active',
                'department'      => 'Hệ thống Thông tin',
            ]
        );

        // 2. Tạo 4 sinh viên mẫu
        $studentsData = [
            ['email' => 'student@gm.uit.edu.vn', 'name' => 'Trần Thị B', 'initials' => 'TB'],
            ['email' => '22520001@gm.uit.edu.vn', 'name' => 'Nguyễn Văn C', 'initials' => 'NC'],
            ['email' => '22520002@gm.uit.edu.vn', 'name' => 'Lê Thị D', 'initials' => 'LD'],
            ['email' => '22520003@gm.uit.edu.vn', 'name' => 'Phạm Văn E', 'initials' => 'PE'],
        ];

        $studentIds = [];
        foreach ($studentsData as $st) {
            $user = User::firstOrCreate(
                ['email' => $st['email']],
                [
                    'name'            => $st['name'],
                    'initials'        => $st['initials'],
                    'hashed_password' => Hash::make('password123'),
                    'role'            => 'student',
                    'status'          => 'Active',
                    'department'      => 'Hệ thống Thông tin',
                ]
            );
            $studentIds[] = $user->id;
        }

        // 3. Tạo lớp học IS004.R12 (Cơ sở dữ liệu) và gán sinh viên
        $class = ClassModel::firstOrCreate(
            ['id' => 'IS004.R12'],
            [
                'course'        => 'IS004.R12',
                'term'          => 'HK1 2026-2027',
                'status'        => 'Active',
                'instructor_id' => $teacher->id,
                'start_date'    => '2026-09-01',
                'end_date'      => '2026-12-31',
            ]
        );

        foreach ($studentIds as $sId) {
            ClassEnrollment::firstOrCreate([
                'class_id'   => $class->id,
                'student_id' => $sId,
            ], [
                'role'      => 'student',
                'joined_at' => now(),
            ]);
        }

        // 4. Các chủ đề (Topics)
        $topicWhere = Topic::firstOrCreate(['name' => 'WHERE & Operators']);
        $topicSelect = Topic::firstOrCreate(['name' => 'Basic SELECT']);
        $topicJoin = Topic::firstOrCreate(['name' => 'JOIN Queries']);
        $topicOrder = Topic::firstOrCreate(['name' => 'ORDER BY & GROUP BY']);

        // Schema & Seed scripts dùng chung cho CSDL Quản lý bán hàng
        $createSql = <<<'SQL'
CREATE TABLE KHACHHANG (
    MAKH CHAR(4) NOT NULL PRIMARY KEY,
    HOTEN VARCHAR(40) NOT NULL,
    DCHI VARCHAR(50) NULL,
    SODT VARCHAR(20) NULL,
    NGSINH SMALLDATETIME NULL,
    DOANHSO MONEY NOT NULL,
    NGDK SMALLDATETIME NOT NULL
);

CREATE TABLE NHANVIEN (
    MANV CHAR(4) NOT NULL PRIMARY KEY,
    HOTEN VARCHAR(40) NOT NULL,
    NGVL SMALLDATETIME NOT NULL,
    SODT VARCHAR(20) NULL
);

CREATE TABLE SANPHAM (
    MASP CHAR(4) NOT NULL PRIMARY KEY,
    TENSP VARCHAR(40) NOT NULL,
    DVT VARCHAR(20) NOT NULL,
    NUOCSX VARCHAR(40) NOT NULL,
    GIA MONEY NOT NULL
);

CREATE TABLE HOADON (
    SOHD INT NOT NULL PRIMARY KEY,
    NGHD SMALLDATETIME NOT NULL,
    MAKH CHAR(4) NULL,
    MANV CHAR(4) NOT NULL,
    TRIGIA MONEY NOT NULL,
    FOREIGN KEY (MAKH) REFERENCES KHACHHANG(MAKH),
    FOREIGN KEY (MANV) REFERENCES NHANVIEN(MANV)
);

CREATE TABLE CTHD (
    SOHD INT NOT NULL,
    MASP CHAR(4) NOT NULL,
    SL INT NOT NULL,
    PRIMARY KEY (SOHD, MASP),
    FOREIGN KEY (SOHD) REFERENCES HOADON(SOHD),
    FOREIGN KEY (MASP) REFERENCES SANPHAM(MASP)
);
SQL;

        $insertSql = <<<'SQL'
INSERT INTO KHACHHANG (MAKH, HOTEN, DCHI, SODT, NGSINH, DOANHSO, NGDK) VALUES
    ('KH01', 'Nguyen Van A', '731 Tran Hung Dao, Q5, TpHCM', '08823451', '19601022', 13060000, '20060722'),
    ('KH02', 'Tran Ngoc Han', '23/5 Nguyen Trai, Q5, TpHCM', '0908256478', '19740403', 280000, '20060730'),
    ('KH03', 'Tran Ngoc Linh', '45 Nguyen Canh Chan, Q1, TpHCM', '0938776266', '19800612', 3860000, '20060805'),
    ('KH04', 'Tran Minh Long', '50/34 Le Dai Hanh, Q10, TpHCM', '0917325476', '19650309', 250000, '20061002'),
    ('KH05', 'Le Nhat Minh', '34 Truong Dinh, Q3, TpHCM', '08246108', '19500310', 21000, '20061028');

INSERT INTO NHANVIEN (MANV, HOTEN, SODT, NGVL) VALUES
    ('NV01', 'Nguyen Nhu Nhut', '0927345678', '20060413'),
    ('NV02', 'Le Thi Phi Yen', '0987567390', '20060421'),
    ('NV03', 'Nguyen Van B', '0997047382', '20060427');

INSERT INTO SANPHAM (MASP, TENSP, DVT, NUOCSX, GIA) VALUES
    ('BC01', 'But chi', 'cay', 'Singapore', 3000),
    ('BC02', 'But chi', 'cay', 'Singapore', 5000),
    ('BC03', 'But chi', 'cay', 'Viet Nam', 3500),
    ('BC04', 'But chi', 'hop', 'Viet Nam', 30000),
    ('BB01', 'But bi', 'cay', 'Viet Nam', 5000),
    ('BB02', 'But bi', 'cay', 'Trung Quoc', 7000),
    ('BB03', 'But bi', 'hop', 'Thai Lan', 100000),
    ('TV01', 'Tap 100 giay mong', 'quyen', 'Trung Quoc', 2500),
    ('TV02', 'Tap 200 giay mong', 'quyen', 'Trung Quoc', 4500),
    ('TV03', 'Tap 100 giay tot', 'quyen', 'Viet Nam', 3000),
    ('TV04', 'Tap 200 giay tot', 'quyen', 'Viet Nam', 5500),
    ('ST01', 'So tay 500 trang', 'quyen', 'Trung Quoc', 40000),
    ('ST02', 'So tay loai 1', 'quyen', 'Viet Nam', 55000),
    ('ST03', 'So tay loai 2', 'quyen', 'Viet Nam', 51000),
    ('ST04', 'So tay', 'quyen', 'Thai Lan', 55000);

INSERT INTO HOADON (SOHD, NGHD, MAKH, MANV, TRIGIA) VALUES
    (1001, '20060723', 'KH01', 'NV01', 320000),
    (1002, '20060812', 'KH01', 'NV02', 840000),
    (1003, '20060823', 'KH02', 'NV01', 100000),
    (1004, '20060901', 'KH02', 'NV01', 180000),
    (1005, '20061020', 'KH01', 'NV02', 3800000);

INSERT INTO CTHD (SOHD, MASP, SL) VALUES
    (1001, 'TV02', 10),
    (1001, 'ST01', 5),
    (1001, 'BC01', 5),
    (1002, 'BC04', 20),
    (1002, 'BB01', 20),
    (1003, 'BB03', 10),
    (1004, 'TV01', 20),
    (1005, 'TV03', 50);
SQL;

        // 5. Danh sách 10 bài toán mẫu chuẩn từ bộ đề Quản lý bán hàng
        $problemsData = [
            [
                'number'       => '01',
                'title'        => 'Sản phẩm do Trung Quốc sản xuất',
                'difficulty'   => 'Easy',
                'topics'       => ['Basic SELECT', 'WHERE & Operators'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) do "Trung Quoc" sản xuất.',
                'solution'     => "SELECT MASP, TENSP\nFROM SANPHAM\nWHERE NUOCSX = 'Trung Quoc'\nORDER BY MASP;",
            ],
            [
                'number'       => '02',
                'title'        => 'Sản phẩm có đơn vị tính là cây hoặc quyển',
                'difficulty'   => 'Easy',
                'topics'       => ['Basic SELECT', 'WHERE & Operators'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) có đơn vị tính là "cay", "quyen".',
                'solution'     => "SELECT MASP, TENSP\nFROM SANPHAM\nWHERE DVT IN ('cay', 'quyen')\nORDER BY MASP;",
            ],
            [
                'number'       => '03',
                'title'        => 'Sản phẩm có mã bắt đầu là B và kết thúc là 01',
                'difficulty'   => 'Easy',
                'topics'       => ['WHERE & Operators'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) có mã sản phẩm bắt đầu là "B" và kết thúc là "01".',
                'solution'     => "SELECT MASP, TENSP\nFROM SANPHAM\nWHERE MASP LIKE 'B%01'\nORDER BY MASP;",
            ],
            [
                'number'       => '04',
                'title'        => 'Sản phẩm Trung Quốc có giá từ 30.000 đến 40.000',
                'difficulty'   => 'Easy',
                'topics'       => ['WHERE & Operators'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) do "Trung Quoc" sản xuất có giá từ 30.000 đến 40.000.',
                'solution'     => "SELECT MASP, TENSP\nFROM SANPHAM\nWHERE NUOCSX = 'Trung Quoc' AND GIA BETWEEN 30000 AND 40000\nORDER BY MASP;",
            ],
            [
                'number'       => '05',
                'title'        => 'Sản phẩm Trung Quốc hoặc Việt Nam có giá 30k-40k',
                'difficulty'   => 'Medium',
                'topics'       => ['WHERE & Operators'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) do "Trung Quoc" hoặc "Viet Nam" sản xuất có giá từ 30.000 đến 40.000.',
                'solution'     => "SELECT MASP, TENSP\nFROM SANPHAM\nWHERE NUOCSX IN ('Trung Quoc', 'Viet Nam') AND GIA BETWEEN 30000 AND 40000\nORDER BY MASP;",
            ],
            [
                'number'       => '06',
                'title'        => 'Hóa đơn bán ra trong ngày 1/1/2007 và ngày 2/1/2007',
                'difficulty'   => 'Easy',
                'topics'       => ['Basic SELECT', 'WHERE & Operators'],
                'description'  => 'In ra các số hóa đơn, trị giá hóa đơn bán ra trong ngày 1/1/2007 và ngày 2/1/2007.',
                'solution'     => "SELECT SOHD, TRIGIA\nFROM HOADON\nWHERE NGHD IN ('20070101', '20070102')\nORDER BY SOHD;",
            ],
            [
                'number'       => '07',
                'title'        => 'Hóa đơn trong tháng 1/2007 sắp xếp giảm dần trị giá',
                'difficulty'   => 'Easy',
                'topics'       => ['ORDER BY & GROUP BY'],
                'description'  => 'In ra các số hóa đơn, trị giá hóa đơn trong tháng 1/2007, sắp xếp theo ngày (tăng dần) và trị giá của hóa đơn (giảm dần).',
                'solution'     => "SELECT SOHD, TRIGIA\nFROM HOADON\nWHERE NGHD >= '20070101' AND NGHD < '20070201'\nORDER BY NGHD ASC, TRIGIA DESC;",
            ],
            [
                'number'       => '08',
                'title'        => 'Khách hàng mua hàng trong ngày 1/1/2007',
                'difficulty'   => 'Medium',
                'topics'       => ['JOIN Queries'],
                'description'  => 'In ra danh sách các khách hàng (MAKH, HOTEN) đã mua hàng trong ngày 1/1/2007.',
                'solution'     => "SELECT DISTINCT KH.MAKH, KH.HOTEN\nFROM KHACHHANG KH\nINNER JOIN HOADON HD ON KH.MAKH = HD.MAKH\nWHERE HD.NGHD = '20070101'\nORDER BY KH.MAKH;",
            ],
            [
                'number'       => '09',
                'title'        => 'Hóa đơn do nhân viên Nguyễn Văn B lập trong ngày 28/10/2006',
                'difficulty'   => 'Medium',
                'topics'       => ['JOIN Queries'],
                'description'  => 'In ra số hóa đơn, trị giá các hóa đơn do nhân viên có tên "Nguyen Van B" lập trong ngày 28/10/2006.',
                'solution'     => "SELECT HD.SOHD, HD.TRIGIA\nFROM HOADON HD\nINNER JOIN NHANVIEN NV ON HD.MANV = NV.MANV\nWHERE NV.HOTEN = 'Nguyen Van B' AND HD.NGHD = '20061028'\nORDER BY HD.SOHD;",
            ],
            [
                'number'       => '10',
                'title'        => 'Sản phẩm khách hàng Nguyễn Văn A mua trong tháng 10/2006',
                'difficulty'   => 'Hard',
                'topics'       => ['JOIN Queries'],
                'description'  => 'In ra danh sách các sản phẩm (MASP, TENSP) được khách hàng có tên "Nguyen Van A" mua trong tháng 10/2006.',
                'solution'     => "SELECT DISTINCT SP.MASP, SP.TENSP\nFROM SANPHAM SP\nINNER JOIN CTHD ON SP.MASP = CTHD.MASP\nINNER JOIN HOADON HD ON CTHD.SOHD = HD.SOHD\nINNER JOIN KHACHHANG KH ON HD.MAKH = KH.MAKH\nWHERE KH.HOTEN = 'Nguyen Van A' AND HD.NGHD >= '20061001' AND HD.NGHD < '20061101'\nORDER BY SP.MASP;",
            ],
        ];

        $createdProblems = [];

        foreach ($problemsData as $pData) {
            $problem = Problem::firstOrCreate(
                ['number' => $pData['number']],
                [
                    'title'                        => $pData['title'],
                    'difficulty'                   => $pData['difficulty'],
                    'topics'                       => $pData['topics'],
                    'topic'                        => implode(', ', $pData['topics']),
                    'description'                  => $pData['description'],
                    'requirements'                 => $pData['solution'],
                    'hint'                         => 'Xem lại cú pháp SELECT, WHERE và kiểm tra kỹ điều kiện lọc.',
                    'practice_listed'              => true,
                    'database_type'                => 'SQL Server',
                    'creator_id'                   => $teacher->id,
                    'workspace_parts'              => ['DDL', 'DML', 'SQL'],
                    'schema_file_name'             => 'QuanLyBanHang.sql',
                    'database_description_visible' => true,
                ]
            );

            // Solution
            ProblemSqlSolution::firstOrCreate(
                [
                    'problem_id' => $problem->id,
                    'name'       => 'Đáp án chuẩn',
                ],
                [
                    'query'         => $pData['solution'],
                    'description'   => 'Truy vấn chuẩn cho ' . $pData['title'],
                    'enabled'       => true,
                    'time_limit_ms' => 5000,
                    'order_index'   => 0,
                ]
            );

            // Test Case
            $testCase = TestCase::firstOrCreate(
                [
                    'problem_id'  => $problem->id,
                    'order_index' => 0,
                ],
                [
                    'is_hidden'      => false,
                    'expected_query' => $pData['solution'],
                ]
            );

            // Test Case Script
            TestCaseScript::firstOrCreate(
                [
                    'test_case_id' => $testCase->id,
                    'table_name'   => 'SANPHAM',
                ],
                [
                    'create_script' => $createSql,
                    'insert_script' => $insertSql,
                    'is_expected'   => true,
                ]
            );

            $createdProblems[] = $problem;
        }

        // 6. Tạo Bài tập 01 (Published / Open) - giống hệt ảnh của bạn
        $assignment1 = Assignment::firstOrCreate(
            ['title' => 'Bài thực hành 01: Truy vấn dữ liệu cơ bản trên CSDL Quản lý bán hàng'],
            [
                'instructor_id'            => $teacher->id,
                'is_contest'               => false,
                'audience_type'            => 'classes',
                'short_description'        => 'Bài thực hành truy vấn SQL cơ bản',
                'instructions'             => "<p>- Sinh viên hoàn thành các câu truy vấn SQL trên cơ sở dữ liệu Quản lý bán hàng.</p><p>- Sử dụng đúng tên bảng và tên cột theo quy định.</p><p>- Hệ thống sẽ chấm điểm tự động thông qua các bộ test case công khai và ẩn.</p>",
                'opens'                    => '2026-10-21 12:00:00',
                'closes'                   => '2026-10-31 12:00:00',
                'published'                => true,
                'hints_enabled'            => true,
                'comments_enabled'         => false,
                'leaderboard_enabled'      => false,
                'ai_allowed'               => true,
                'exam_mode'                => false,
                'show_scores_after_submit' => true,
            ]
        );

        $assignment1->classes()->sync([$class->id => ['id' => (string) Str::uuid()]]);

        // Gán 3 bài đầu tiên vào Assignment 1
        $assignment1->problems()->detach();
        foreach (array_slice($createdProblems, 0, 3) as $idx => $p) {
            $assignment1->problems()->attach($p->id, [
                'id'                           => (string) Str::uuid(),
                'points'                       => 10,
                'order_index'                  => $idx + 1,
                'workspace_parts'              => json_encode(['problem-detail', 'schema-browser', 'query-editor']),
                'dataset_visibility'           => json_encode([]),
                'database_structure_visible'   => true,
                'database_description_visible' => true,
            ]);
        }

        // 7. Tạo Bài tập 02 (Draft - Để thử tính năng lưu nháp & chỉnh sửa)
        $assignment2 = Assignment::firstOrCreate(
            ['title' => 'Bài thực hành 02: Các phép toán Gom nhóm (GROUP BY) và Kết nối (JOIN)'],
            [
                'instructor_id'            => $teacher->id,
                'is_contest'               => false,
                'audience_type'            => 'classes',
                'short_description'        => 'Bài thực hành nâng cao với JOIN và GROUP BY',
                'instructions'             => '<p>Sinh viên thực hiện các câu truy vấn nâng cao kết nối nhiều bảng.</p>',
                'opens'                    => '2026-11-01 08:00:00',
                'closes'                   => '2026-11-15 23:59:00',
                'published'                => false,
                'hints_enabled'            => true,
                'comments_enabled'         => true,
                'leaderboard_enabled'      => false,
                'ai_allowed'               => false,
                'exam_mode'                => false,
                'show_scores_after_submit' => true,
            ]
        );

        $assignment2->classes()->sync([$class->id => ['id' => (string) Str::uuid()]]);

        $assignment2->problems()->detach();
        foreach (array_slice($createdProblems, 6, 3) as $idx => $p) {
            $assignment2->problems()->attach($p->id, [
                'id'                           => (string) Str::uuid(),
                'points'                       => 10,
                'order_index'                  => $idx + 1,
                'workspace_parts'              => json_encode(['problem-detail', 'query-editor']),
                'dataset_visibility'           => json_encode([]),
                'database_structure_visible'   => true,
                'database_description_visible' => true,
            ]);
        }
    }
}
