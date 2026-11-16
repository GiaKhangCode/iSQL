-- Bo du lieu kiem thu thu hai (an) cua QLGV: noi vao cuoi qlgv_insert.sql.
-- K1108 (lop truong K11) chi thi mot mon va dat; K1205 (lop truong K12) khong dat ca 4 mon o moi lan thi.
-- Phat hien bai lam bo sot truong hop "chua thi du mon" (cau 33) va "lop truong thi khong dat qua 3 mon" (cau 25).
INSERT INTO KETQUATHI (MAHV, MAMH, LANTHI, NGTHI, DIEM, KQUA) VALUES
    ('K1108', 'CSDL', 1, '20060720', 9.00, 'Dat'),
    ('K1205', 'CSDL', 1, '20060720', 3.00, 'Khong Dat'),
    ('K1205', 'CTDLGT', 1, '20061228', 3.00, 'Khong Dat'),
    ('K1205', 'THDC', 1, '20060520', 3.00, 'Khong Dat'),
    ('K1205', 'CTRR', 1, '20060513', 3.00, 'Khong Dat');
