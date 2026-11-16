<?php

namespace Database\Seeders;

use App\Models\DatabaseLibraryEntry;
use App\Models\Problem;
use App\Models\User;
use App\Services\ProblemService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SampleLibraryProblemSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'phuongtv@uit.edu.vn')->first()
            ?? User::where('email', 'teacher@uit.edu.vn')->first()
            ?? User::where('email', 'gvk@uit.edu.vn')->first()
            ?? User::where('role', User::ROLE_ADMIN)->first();

        if (!$owner) {
            echo "Owner user not found!\n";
            return;
        }

        $schemaSql = <<<'SQL'
CREATE TABLE DOCGIA (
    MADG CHAR(5) NOT NULL PRIMARY KEY,
    HOTEN VARCHAR(40) NOT NULL,
    NGAYSINH SMALLDATETIME NULL,
    LOAIDG VARCHAR(20) NOT NULL,
    NGAYDK SMALLDATETIME NOT NULL,
    TIENCOC MONEY NOT NULL
);

CREATE TABLE TACGIA (
    MATG CHAR(4) NOT NULL PRIMARY KEY,
    TENTG VARCHAR(40) NOT NULL,
    QUOCTICH VARCHAR(30) NULL
);

CREATE TABLE SACH (
    MASACH CHAR(5) NOT NULL PRIMARY KEY,
    TENSACH VARCHAR(60) NOT NULL,
    THELOAI VARCHAR(30) NOT NULL,
    MATG CHAR(4) NOT NULL,
    NAMXB SMALLINT NOT NULL,
    SOLUONG INT NOT NULL,
    GIASACH MONEY NOT NULL,
    FOREIGN KEY (MATG) REFERENCES TACGIA(MATG)
);

CREATE TABLE PHIEUMUON (
    MAPM CHAR(5) NOT NULL PRIMARY KEY,
    MADG CHAR(5) NOT NULL,
    NGAYMUON SMALLDATETIME NOT NULL,
    HANTRA SMALLDATETIME NOT NULL,
    TRANGTHAI VARCHAR(20) NOT NULL,
    FOREIGN KEY (MADG) REFERENCES DOCGIA(MADG)
);

CREATE TABLE CTPM (
    MAPM CHAR(5) NOT NULL,
    MASACH CHAR(5) NOT NULL,
    SOLUONG INT NOT NULL,
    NGAYTRA SMALLDATETIME NULL,
    PRIMARY KEY (MAPM, MASACH),
    FOREIGN KEY (MAPM) REFERENCES PHIEUMUON(MAPM),
    FOREIGN KEY (MASACH) REFERENCES SACH(MASACH)
);
SQL;

        $dataset1 = <<<'SQL'
INSERT INTO DOCGIA (MADG, HOTEN, NGAYSINH, LOAIDG, NGAYDK, TIENCOC) VALUES
    ('DG001', 'Nguyen Minh Anh', '20030512', 'SinhVien', '20250901', 200000),
    ('DG002', 'Tran Quoc Bao', '19881120', 'GiangVien', '20240115', 500000),
    ('DG003', 'Le Thi Cam Tu', '20040218', 'SinhVien', '20250905', 200000),
    ('DG004', 'Pham Hoang Nam', '19900704', 'GiangVien', '20230810', 500000),
    ('DG005', 'Vo Ngoc Han', '20021030', 'SinhVien', '20250910', 200000),
    ('DG006', 'Dang Van Khanh', '20030322', 'SinhVien', '20250912', 150000);

INSERT INTO TACGIA (MATG, TENTG, QUOCTICH) VALUES
    ('TG01', 'Nguyen Nhat Anh', 'Viet Nam'),
    ('TG02', 'Abraham Silberschatz', 'USA'),
    ('TG03', 'Robert C. Martin', 'USA'),
    ('TG04', 'To Hoai', 'Viet Nam');

INSERT INTO SACH (MASACH, TENSACH, THELOAI, MATG, NAMXB, SOLUONG, GIASACH) VALUES
    ('S0001', 'Database System Concepts', 'Cong nghe thong tin', 'TG02', 2020, 10, 350000),
    ('S0002', 'Clean Code', 'Cong nghe thong tin', 'TG03', 2018, 8, 280000),
    ('S0003', 'Clean Architecture', 'Cong nghe thong tin', 'TG03', 2019, 6, 310000),
    ('S0004', 'Cho Toi Xin Mot Ve Di Tuoi Tho', 'Van hoc', 'TG01', 2016, 12, 95000),
    ('S0005', 'Mat Biec', 'Van hoc', 'TG01', 2012, 5, 85000),
    ('S0006', 'De Men Phieu Luu Ky', 'Thieu nhi', 'TG04', 2010, 7, 70000),
    ('S0007', 'Operating System Concepts', 'Cong nghe thong tin', 'TG02', 2021, 9, 390000),
    ('S0008', 'Co Gai Den Tu Hom Qua', 'Van hoc', 'TG01', 2014, 4, 90000);

INSERT INTO PHIEUMUON (MAPM, MADG, NGAYMUON, HANTRA, TRANGTHAI) VALUES
    ('PM001', 'DG001', '20260301', '20260315', 'DaTra'),
    ('PM002', 'DG002', '20260310', '20260324', 'DangMuon'),
    ('PM003', 'DG003', '20260312', '20260326', 'DangMuon'),
    ('PM004', 'DG004', '20260318', '20260405', 'DaTra'),
    ('PM005', 'DG001', '20260320', '20260330', 'DangMuon'),
    ('PM006', 'DG005', '20260402', '20260416', 'DangMuon');

INSERT INTO CTPM (MAPM, MASACH, SOLUONG, NGAYTRA) VALUES
    ('PM001', 'S0001', 1, '20260314'),
    ('PM001', 'S0004', 2, '20260314'),
    ('PM002', 'S0002', 1, NULL),
    ('PM002', 'S0003', 1, '20260320'),
    ('PM003', 'S0007', 2, NULL),
    ('PM004', 'S0001', 1, '20260328'),
    ('PM005', 'S0005', 1, NULL),
    ('PM006', 'S0002', 1, '20260410'),
    ('PM006', 'S0004', 1, NULL);
SQL;

        $dataset2 = $dataset1 . "\n" . <<<'SQL'
INSERT INTO DOCGIA (MADG, HOTEN, NGAYSINH, LOAIDG, NGAYDK, TIENCOC) VALUES
    ('DG007', 'Hoang Thi Mai', '20010815', 'SinhVien', '20250920', 200000);

INSERT INTO PHIEUMUON (MAPM, MADG, NGAYMUON, HANTRA, TRANGTHAI) VALUES
    ('PM007', 'DG007', '20260305', '20260319', 'DangMuon');

INSERT INTO CTPM (MAPM, MASACH, SOLUONG, NGAYTRA) VALUES
    ('PM007', 'S0007', 1, NULL);
SQL;

        $libTitle = 'Quản lý thư viện (QLTV)';
        $existingLib = DatabaseLibraryEntry::where('title', $libTitle)
            ->where('instructor_id', $owner->id)
            ->first();

        $libData = [
            'instructor_id'          => $owner->id,
            'title'                  => $libTitle,
            'create_table_file_name' => 'qltv_createtable.sql',
            'create_table_sql'       => $schemaSql,
            'insert_into_file_name'  => 'qltv_insertdata.sql',
            'insert_into_sql'        => $dataset1,
            'datasets_json'          => [
                ['fileName' => 'qltv_insertdata.sql', 'sql' => $dataset1, 'isHidden' => false],
                ['fileName' => 'qltv_insertdata_kiemthu.sql', 'sql' => $dataset2, 'isHidden' => true],
            ],
            'revision'               => 1,
        ];

        if ($existingLib) {
            $existingLib->update($libData);
        } else {
            $libData['id'] = (string) Str::uuid();
            DatabaseLibraryEntry::create($libData);
        }

        $dbDescription = [
            [
                'tableName'  => 'DOCGIA',
                'predicate'  => 'Mỗi độc giả có mã độc giả, họ tên, ngày sinh, loại độc giả, ngày đăng ký và tiền đặt cọc.',
                'attributes' => "MADG (char(5)): Mã độc giả (khóa chính)\nHOTEN (varchar(40)): Họ và tên độc giả\nNGAYSINH (smalldatetime): Ngày sinh\nLOAIDG (varchar(20)): Loại độc giả (SinhVien hoặc GiangVien)\nNGAYDK (smalldatetime): Ngày đăng ký thẻ\nTIENCOC (money): Tiền đặt cọc",
            ],
            [
                'tableName'  => 'TACGIA',
                'predicate'  => 'Mỗi tác giả có mã tác giả, tên tác giả và quốc tịch.',
                'attributes' => "MATG (char(4)): Mã tác giả (khóa chính)\nTENTG (varchar(40)): Tên tác giả\nQUOCTICH (varchar(30)): Quốc tịch",
            ],
            [
                'tableName'  => 'SACH',
                'predicate'  => 'Mỗi đầu sách có mã sách, tên sách, thể loại, tác giả, năm xuất bản, số lượng tồn kho và giá sách.',
                'attributes' => "MASACH (char(5)): Mã sách (khóa chính)\nTENSACH (varchar(60)): Tên sách\nTHELOAI (varchar(30)): Thể loại sách\nMATG (char(4)): Mã tác giả\nNAMXB (smallint): Năm xuất bản\nSOLUONG (int): Số lượng bản sách hiện có trong kho\nGIASACH (money): Giá bìa quyển sách",
            ],
            [
                'tableName'  => 'PHIEUMUON',
                'predicate'  => 'Mỗi phiếu mượn ghi nhận mã phiếu, độc giả mượn, ngày mượn, hạn trả và trạng thái phiếu.',
                'attributes' => "MAPM (char(5)): Mã phiếu mượn (khóa chính)\nMADG (char(5)): Mã độc giả\nNGAYMUON (smalldatetime): Ngày lập phiếu mượn\nHANTRA (smalldatetime): Hạn trả dự kiến\nTRANGTHAI (varchar(20)): Trạng thái phiếu (DangMuon hoặc DaTra)",
            ],
            [
                'tableName'  => 'CTPM',
                'predicate'  => 'Chi tiết phiếu mượn lưu từng đầu sách được mượn trong phiếu, số lượng mượn và ngày trả thực tế.',
                'attributes' => "MAPM (char(5)): Mã phiếu mượn (khóa chính)\nMASACH (char(5)): Mã sách (khóa chính)\nSOLUONG (int): Số lượng mượn\nNGAYTRA (smalldatetime): Ngày trả thực tế (NULL nếu chưa trả)",
            ],
        ];

        $ddlQuestions = [
            [
                'id'          => 'qltv-ddl-01',
                'name'        => 'DDL-01 Tạo các bảng QLTV.sql',
                'description' => 'Tạo đầy đủ 5 bảng DOCGIA, TACGIA, SACH, PHIEUMUON, CTPM cùng các khóa chính và khóa ngoại tương ứng.',
                'answer'      => $schemaSql,
                'enabled'     => true,
                'startFrom'   => 'empty',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Dễ',
                'category'    => 'DDL - Tạo bảng (CREATE TABLE)',
            ],
            [
                'id'          => 'qltv-ddl-02',
                'name'        => 'DDL-02 Ràng buộc CHECK cho SACH.sql',
                'description' => 'Thêm ràng buộc kiểm tra cho bảng SACH đảm bảo số lượng sách tồn kho không âm (SOLUONG >= 0) và giá sách dương (GIASACH > 0).',
                'answer'      => "ALTER TABLE SACH ADD CONSTRAINT CK_SACH_SOLUONG CHECK (SOLUONG >= 0);\nALTER TABLE SACH ADD CONSTRAINT CK_SACH_GIASACH CHECK (GIASACH > 0);",
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Dễ',
                'category'    => 'DDL - Ràng buộc (ALTER TABLE / CHECK)',
            ],
            [
                'id'          => 'qltv-ddl-03',
                'name'        => 'DDL-03 Mở rộng bảng PHIEUMUON.sql',
                'description' => 'Thêm cột GHICHU (varchar(100), cho phép NULL) vào bảng PHIEUMUON và thêm ràng buộc hạn trả phải lớn hơn hoặc bằng ngày mượn (HANTRA >= NGAYMUON).',
                'answer'      => "ALTER TABLE PHIEUMUON ADD GHICHU VARCHAR(100) NULL;\nALTER TABLE PHIEUMUON ADD CONSTRAINT CK_PM_HANTRA CHECK (HANTRA >= NGAYMUON);",
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Trung bình',
                'category'    => 'DDL - Sửa bảng & Ràng buộc',
            ],
        ];

        $dmlQuestions = [
            [
                'id'          => 'qltv-dml-01',
                'name'        => 'DML-01 Tăng tiền cọc giảng viên.sql',
                'description' => "Tăng TIENCOC thêm 50000 cho các độc giả có LOAIDG = 'GiangVien'.",
                'answer'      => "UPDATE DOCGIA\nSET TIENCOC = TIENCOC + 50000\nWHERE LOAIDG = 'GiangVien';",
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Dễ',
                'category'    => 'DML - Cập nhật dữ liệu (UPDATE)',
            ],
            [
                'id'          => 'qltv-dml-02',
                'name'        => 'DML-02 Đồng bộ trạng thái phiếu mượn.sql',
                'description' => "Cập nhật TRANGTHAI = 'DaTra' cho những phiếu mượn mà tất cả các sách trong CTPM của phiếu đó đều đã có NGAYTRA khác NULL.",
                'answer'      => "UPDATE PHIEUMUON\nSET TRANGTHAI = 'DaTra'\nWHERE EXISTS (SELECT 1 FROM CTPM ct WHERE ct.MAPM = PHIEUMUON.MAPM)\n  AND NOT EXISTS (SELECT 1 FROM CTPM ct WHERE ct.MAPM = PHIEUMUON.MAPM AND ct.NGAYTRA IS NULL);",
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Trung bình',
                'category'    => 'DML - Cập nhật có truy vấn con',
            ],
            [
                'id'          => 'qltv-dml-03',
                'name'        => 'DML-03 Xóa sách cũ chưa từng mượn.sql',
                'description' => 'Xóa các đầu sách xuất bản trước năm 2015 (NAMXB < 2015) mà chưa từng được mượn lần nào trong CTPM.',
                'answer'      => "DELETE FROM SACH\nWHERE NAMXB < 2015\n  AND MASACH NOT IN (SELECT DISTINCT MASACH FROM CTPM);",
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Khó',
                'category'    => 'DML - Xóa dữ liệu (DELETE)',
            ],
        ];

        $triggerQuestions = [
            [
                'id'          => 'qltv-trg-01',
                'name'        => 'TRG-01 Kiểm tra hạn trả hợp lệ.sql',
                'description' => 'Viết trigger trên bảng PHIEUMUON (AFTER INSERT, UPDATE) đảm bảo HANTRA >= NGAYMUON. Nếu vi phạm, hủy thao tác (ROLLBACK TRANSACTION) và báo lỗi.',
                'answer'      => <<<'SQL'
CREATE TRIGGER trg_PHIEUMUON_HanTra ON PHIEUMUON
AFTER INSERT, UPDATE
AS
BEGIN
    IF EXISTS (SELECT 1 FROM inserted WHERE HANTRA < NGAYMUON)
    BEGIN
        RAISERROR('Han tra phai lon hon hoac bang ngay muon.', 16, 1);
        ROLLBACK TRANSACTION;
    END
END
SQL,
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Trung bình',
                'category'    => 'Trigger - Ràng buộc kiểm tra dữ liệu',
                'probes'      => [
                    "INSERT INTO PHIEUMUON (MAPM, MADG, NGAYMUON, HANTRA, TRANGTHAI) VALUES ('PM901', 'DG001', '20260501', '20260515', 'DangMuon');",
                    "INSERT INTO PHIEUMUON (MAPM, MADG, NGAYMUON, HANTRA, TRANGTHAI) VALUES ('PM902', 'DG001', '20260515', '20260510', 'DangMuon');",
                    "UPDATE PHIEUMUON SET HANTRA = '20260201' WHERE MAPM = 'PM002';",
                ],
            ],
            [
                'id'          => 'qltv-trg-02',
                'name'        => 'TRG-02 Cập nhật tồn kho sách khi mượn hoặc xóa.sql',
                'description' => 'Viết trigger trên bảng CTPM (AFTER INSERT, DELETE) để tự động trừ SACH.SOLUONG khi thêm chi tiết mượn mới và cộng lại SACH.SOLUONG khi xóa chi tiết mượn.',
                'answer'      => <<<'SQL'
CREATE TRIGGER trg_CTPM_CapNhatTonKho ON CTPM
AFTER INSERT, DELETE
AS
BEGIN
    IF EXISTS (SELECT 1 FROM inserted)
    BEGIN
        UPDATE s
        SET s.SOLUONG = s.SOLUONG - i.TONG
        FROM SACH s
        JOIN (SELECT MASACH, SUM(SOLUONG) AS TONG FROM inserted GROUP BY MASACH) i
          ON s.MASACH = i.MASACH;
    END

    IF EXISTS (SELECT 1 FROM deleted)
    BEGIN
        UPDATE s
        SET s.SOLUONG = s.SOLUONG + d.TONG
        FROM SACH s
        JOIN (SELECT MASACH, SUM(SOLUONG) AS TONG FROM deleted GROUP BY MASACH) d
          ON s.MASACH = d.MASACH;
    END
END
SQL,
                'enabled'     => true,
                'startFrom'   => 'provided',
                'setupDdl'    => '',
                'setupDml'    => '',
                'difficulty'  => 'Khó',
                'category'    => 'Trigger - Ràng buộc toàn vẹn liên bảng',
                'probes'      => [
                    "INSERT INTO CTPM (MAPM, MASACH, SOLUONG, NGAYTRA) VALUES ('PM002', 'S0001', 2, NULL), ('PM003', 'S0004', 1, NULL);",
                    "DELETE FROM CTPM WHERE MAPM = 'PM001' AND MASACH = 'S0004';",
                ],
            ],
        ];

        $sqlSolutions = [
            [
                'id'          => 'qltv-dql-01',
                'name'        => 'DQL-01 Sách CNTT sắp xếp theo giá.sql',
                'description' => "Liệt kê mã sách, tên sách, tên tác giả và giá sách của các đầu sách thuộc thể loại 'Cong nghe thong tin', sắp xếp theo giá sách giảm dần.",
                'query'       => "SELECT s.MASACH, s.TENSACH, tg.TENTG, s.GIASACH\nFROM SACH s\nJOIN TACGIA tg ON tg.MATG = s.MATG\nWHERE s.THELOAI = 'Cong nghe thong tin'\nORDER BY s.GIASACH DESC, s.MASACH ASC;",
                'enabled'     => true,
                'timeLimitMs' => 5000,
                'difficulty'  => 'Dễ',
                'category'    => 'DQL - Kết nối bảng (JOIN)',
            ],
            [
                'id'          => 'qltv-dql-02',
                'name'        => 'DQL-02 Tổng số sách đã mượn theo độc giả.sql',
                'description' => 'Thống kê mã độc giả, họ tên và tổng số lượng sách đã mượn (TONGSACHMUON) của từng độc giả (bao gồm cả độc giả chưa mượn quyển nào = 0), sắp xếp theo mã độc giả.',
                'query'       => "SELECT dg.MADG, dg.HOTEN, COALESCE(SUM(ct.SOLUONG), 0) AS TONGSACHMUON\nFROM DOCGIA dg\nLEFT JOIN PHIEUMUON pm ON pm.MADG = dg.MADG\nLEFT JOIN CTPM ct ON ct.MAPM = pm.MAPM\nGROUP BY dg.MADG, dg.HOTEN\nORDER BY dg.MADG;",
                'enabled'     => true,
                'timeLimitMs' => 5000,
                'difficulty'  => 'Trung bình',
                'category'    => 'DQL - Gom nhóm & Thống kê (GROUP BY)',
            ],
            [
                'id'          => 'qltv-dql-03',
                'name'        => 'DQL-03 Độc giả quá hạn chưa trả sách.sql',
                'description' => "Tìm mã độc giả và họ tên các độc giả đang có ít nhất một đầu sách chưa trả (NGAYTRA IS NULL) với hạn trả trước ngày 2026-04-01 (HANTRA < '20260401').",
                'query'       => "SELECT DISTINCT dg.MADG, dg.HOTEN\nFROM DOCGIA dg\nJOIN PHIEUMUON pm ON pm.MADG = dg.MADG\nJOIN CTPM ct ON ct.MAPM = pm.MAPM\nWHERE ct.NGAYTRA IS NULL\n  AND pm.HANTRA < '20260401'\nORDER BY dg.MADG;",
                'enabled'     => true,
                'timeLimitMs' => 5000,
                'difficulty'  => 'Trung bình',
                'category'    => 'DQL - Kết nối nhiều bảng & Lọc điều kiện',
            ],
            [
                'id'          => 'qltv-dql-04',
                'name'        => 'DQL-04 Tác giả có nhiều đầu sách nhất.sql',
                'description' => 'Tìm mã tác giả, tên tác giả và số đầu sách (SODAUSACH) của tác giả có nhiều đầu sách nhất trong thư viện (lấy cả trường hợp đồng hạng).',
                'query'       => "SELECT tg.MATG, tg.TENTG, COUNT(s.MASACH) AS SODAUSACH\nFROM TACGIA tg\nJOIN SACH s ON s.MATG = tg.MATG\nGROUP BY tg.MATG, tg.TENTG\nHAVING COUNT(s.MASACH) >= ALL (\n    SELECT COUNT(s2.MASACH) FROM SACH s2 GROUP BY s2.MATG\n)\nORDER BY tg.MATG;",
                'enabled'     => true,
                'timeLimitMs' => 5000,
                'difficulty'  => 'Khó',
                'category'    => 'DQL - Truy vấn lồng & ALL/HAVING',
            ],
        ];

        $statement = <<<'MD'
**Bài tập 3 – Cơ sở dữ liệu quản lý thư viện** (Cơ sở dữ liệu quan hệ – Khoa Hệ thống thông tin, UIT).

CSDL gồm 5 quan hệ DOCGIA, TACGIA, SACH, PHIEUMUON, CTPM (xem *Mô tả cơ sở dữ liệu*). Bài gồm 4 phần: **DDL** (tạo bảng, khóa ngoại, ràng buộc CHECK), **DML** (cập nhật và xóa dữ liệu), **Trigger** (kiểm tra hạn trả, cập nhật tồn kho sách) và **DQL** (4 câu truy vấn).

Mỗi câu hỏi được gắn *dạng bài* và *mức độ* (Dễ / Trung bình / Khó). Dữ liệu chuỗi không dấu, ví dụ `SinhVien`, `GiangVien`, `Cong nghe thong tin`, `DangMuon`, `DaTra`.
MD;

        $problemPayload = [
            'number'                     => 'QLTV01',
            'title'                      => 'Quản lý thư viện (QLTV) – DDL, DML, trigger và 4 câu truy vấn',
            'difficulty'                 => 'Medium',
            'topics'                     => ['Quản lý thư viện', 'DDL', 'DML', 'DQL', 'Trigger', 'Cơ sở dữ liệu quan hệ'],
            'statement'                  => $statement,
            'requirements'               => 'Làm các câu theo từng phần DDL / DML / Trigger / SQL. Câu trigger được chấm theo kết quả: hệ thống chạy các thao tác thử và so dữ liệu với đáp án.',
            'hints'                      => [],
            'database'                   => 'SQL Server',
            'schemaFileName'             => 'qltv_createtable.sql',
            'databaseDescription'        => $dbDescription,
            'databaseDescriptionVisible' => true,
            'workspaceParts'             => ['DDL', 'DML', 'SQL', 'TRIGGER'],
            'schema'                     => $schemaSql,
            'seedData'                   => $dataset1,
            'testCases'                  => [
                ['schema' => $schemaSql, 'seedData' => $dataset1, 'isHidden' => false],
                ['schema' => $schemaSql, 'seedData' => $dataset2, 'isHidden' => true],
            ],
            'referenceSolution'          => $sqlSolutions[0]['query'],
            'ddlExercise'                => ['questions' => $ddlQuestions],
            'dmlExercise'                => ['questions' => $dmlQuestions],
            'triggerExercise'            => ['questions' => $triggerQuestions],
            'sqlSolutions'               => $sqlSolutions,
            'practiceListed'             => true,
        ];

        $problemService = app(ProblemService::class);
        $existingProblem = Problem::where('number', 'QLTV01')->first();

        if ($existingProblem) {
            $problemService->updateProblem($existingProblem->id, $problemPayload, $owner);
            $problem = Problem::find($existingProblem->id);
            echo "Updated Problem QLTV01 (ID: {$problem->id})\n";
        } else {
            $res = $problemService->createProblem($problemPayload, $owner);
            $problem = Problem::find($res['id']);
            echo "Created Problem QLTV01 (ID: {$problem->id})\n";
        }
    }
}

