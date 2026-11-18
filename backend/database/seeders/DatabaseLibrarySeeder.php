<?php

namespace Database\Seeders;

use App\Models\DatabaseLibraryEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $salesDdl = <<<'SQL'
CREATE TABLE KHACHHANG (
    MAKH CHAR(4) NOT NULL PRIMARY KEY,
    HOTEN VARCHAR(40) NOT NULL,
    DCHI VARCHAR(50) NULL,
    SODT VARCHAR(20) NULL,
    NGSINH DATE NULL,
    DOANHSO DECIMAL(18, 2) NOT NULL DEFAULT 0,
    NGDK DATE NOT NULL
);

CREATE TABLE NHANVIEN (
    MANV CHAR(4) NOT NULL PRIMARY KEY,
    HOTEN VARCHAR(40) NOT NULL,
    SODT VARCHAR(20) NULL,
    NGVL DATE NOT NULL
);

CREATE TABLE SANPHAM (
    MASP CHAR(4) NOT NULL PRIMARY KEY,
    TENSP VARCHAR(40) NOT NULL,
    DVT VARCHAR(20) NULL,
    NUOCSX VARCHAR(40) NULL,
    GIA DECIMAL(18, 2) NOT NULL DEFAULT 0
);

CREATE TABLE HOADON (
    SOHD INT NOT NULL PRIMARY KEY,
    NGHD DATE NOT NULL,
    MAKH CHAR(4) NULL,
    MANV CHAR(4) NOT NULL,
    TRIGIA DECIMAL(18, 2) NOT NULL DEFAULT 0,
    CONSTRAINT FK_HOADON_KHACHHANG FOREIGN KEY (MAKH) REFERENCES KHACHHANG(MAKH),
    CONSTRAINT FK_HOADON_NHANVIEN FOREIGN KEY (MANV) REFERENCES NHANVIEN(MANV)
);

CREATE TABLE CTHD (
    SOHD INT NOT NULL,
    MASP CHAR(4) NOT NULL,
    SL INT NOT NULL DEFAULT 1,
    PRIMARY KEY (SOHD, MASP),
    CONSTRAINT FK_CTHD_HOADON FOREIGN KEY (SOHD) REFERENCES HOADON(SOHD),
    CONSTRAINT FK_CTHD_SANPHAM FOREIGN KEY (MASP) REFERENCES SANPHAM(MASP)
);
SQL;

        $salesDml1 = <<<'SQL'
INSERT INTO KHACHHANG (MAKH, HOTEN, DCHI, SODT, NGSINH, DOANHSO, NGDK) VALUES
    ('KH01', 'Nguyen Van A', '731 Tran Hung Dao, Q5, TpHCM', '08823451', '1960-10-22', 13060000, '2006-07-22'),
    ('KH02', 'Tran Ngoc Han', '23/5 Nguyen Trai, Q5, TpHCM', '0908256478', '1974-04-03', 280000, '2006-07-30'),
    ('KH03', 'Tran Ngoc Linh', '45 Nguyen Canh Chan, Q1, TpHCM', '0938776266', '1980-06-12', 3860000, '2006-08-05');

INSERT INTO NHANVIEN (MANV, HOTEN, SODT, NGVL) VALUES
    ('NV01', 'Nguyen Nhu Nhut', '0927345678', '2006-04-13'),
    ('NV02', 'Le Thi Phi Yen', '0987567390', '2006-04-21');

INSERT INTO SANPHAM (MASP, TENSP, DVT, NUOCSX, GIA) VALUES
    ('BC01', 'But chi', 'cay', 'Singapore', 3000),
    ('BC02', 'But chi', 'cay', 'Singapore', 5000),
    ('BC03', 'But chi', 'cay', 'Viet Nam', 3500),
    ('BB01', 'But bi', 'cay', 'Viet Nam', 5000),
    ('BB02', 'But bi', 'cay', 'Trung Quoc', 7000);

INSERT INTO HOADON (SOHD, NGHD, MAKH, MANV, TRIGIA) VALUES
    (1001, '2006-07-23', 'KH01', 'NV01', 320000),
    (1002, '2006-08-12', 'KH01', 'NV02', 840000);

INSERT INTO CTHD (SOHD, MASP, SL) VALUES
    (1001, 'BC01', 5),
    (1002, 'BB01', 20);
SQL;

        $academicDdl = <<<'SQL'
CREATE TABLE KHOA (
    MAKHOA VARCHAR(10) PRIMARY KEY,
    TENKHOA VARCHAR(100) NOT NULL
);

CREATE TABLE SINHVIEN (
    MASV VARCHAR(10) PRIMARY KEY,
    HOTEN VARCHAR(100) NOT NULL,
    PHAI VARCHAR(5) CHECK (PHAI IN ('Nam', 'Nữ')),
    NGAYSINH DATE NULL,
    DIEMTB DECIMAL(4, 2) NOT NULL,
    MAKHOA VARCHAR(10) NOT NULL,
    CONSTRAINT FK_SINHVIEN_KHOA FOREIGN KEY (MAKHOA) REFERENCES KHOA(MAKHOA)
);
SQL;

        $academicDml1 = <<<'SQL'
INSERT INTO KHOA (MAKHOA, TENKHOA) VALUES
('CNTT', 'Khoa Công nghệ Thông tin'),
('HTTT', 'Khoa Hệ thống Thông tin'),
('KHMT', 'Khoa Khoa học Máy tính');

INSERT INTO SINHVIEN (MASV, HOTEN, PHAI, NGAYSINH, DIEMTB, MAKHOA) VALUES
('22520001', 'Nguyễn Văn An', 'Nam', '2004-01-15', 8.50, 'CNTT'),
('22520002', 'Trần Thị Bình', 'Nữ', '2004-03-22', 7.20, 'HTTT'),
('22520003', 'Lê Hoàng Cường', 'Nam', '2004-07-10', 9.10, 'CNTT'),
('22520004', 'Phạm Thu Dung', 'Nữ', '2004-11-05', 8.00, 'KHMT'),
('22520005', 'Đỗ Minh Đức', 'Nam', '2004-09-18', 6.80, 'HTTT');
SQL;

        DatabaseLibraryEntry::firstOrCreate(
            ['title' => 'Quản lý Bán hàng'],
            [
                'id'                     => (string) Str::uuid(),
                'instructor_id'          => null,
                'database_file_name'     => 'quanlybanhang.sql',
                'create_table_file_name' => 'quanlybanhang_createtable.sql',
                'insert_into_file_name'  => 'quanlybanhang_insertdata.sql',
                'create_table_sql'       => $salesDdl,
                'insert_into_sql'        => $salesDml1,
                'datasets_json'          => [
                    ['fileName' => 'quanlybanhang_insertdata.sql', 'sql' => $salesDml1],
                ],
            ]
        );

        DatabaseLibraryEntry::firstOrCreate(
            ['title' => 'Quản lý Đào tạo'],
            [
                'id'                     => (string) Str::uuid(),
                'instructor_id'          => null,
                'database_file_name'     => 'quanlydaotao.sql',
                'create_table_file_name' => 'quanlydaotao_createtable.sql',
                'insert_into_file_name'  => 'quanlydaotao_insertdata.sql',
                'create_table_sql'       => $academicDdl,
                'insert_into_sql'        => $academicDml1,
                'datasets_json'          => [
                    ['fileName' => 'quanlydaotao_insertdata.sql', 'sql' => $academicDml1],
                ],
            ]
        );
    }
}
