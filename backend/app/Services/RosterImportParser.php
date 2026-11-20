<?php

namespace App\Services;

/**
 * Đọc nội dung tệp CSV/TXT danh sách sinh viên và trả về danh sách "định danh"
 * (email, id hoặc MSSV). Lớp thuần: không truy cập DB, không biết sinh viên có tồn tại hay không.
 *
 * Quy tắc trích xuất cho mỗi dòng:
 *  - Dòng đầu có tiêu đề `email`/`mssv`... → dùng đúng cột đó (ưu tiên email, thiếu thì MSSV).
 *  - Dòng nhiều ô, không có cột chỉ định → lấy TẤT CẢ ô là email hợp lệ (cho phép dòng "a@x, b@x");
 *    nếu không có email: cả dòng là danh sách mã (mọi ô khác rỗng đều là mã 6-15 ký tự có chữ số)
 *    thì lấy hết, ngược lại chỉ lấy một ô giống mã, ưu tiên ô có chữ số
 *    (ví dụ dòng "1,Nguyễn A,22520001" hay "1,Student,22520001").
 *  - Dòng một ô → email nhúng trong dòng, hoặc tách theo dấu , ;
 */
class RosterImportParser
{
    private const EMAIL_HEADERS = ['email', 'e-mail', 'mail', 'hộp thư', 'hop thu', 'email address'];

    private const CODE_HEADERS = [
        'mssv', 'student_id', 'student id', 'mã số sinh viên', 'ma so sinh vien',
        'mã sinh viên', 'ma sinh vien', 'student_code',
    ];

    private const IGNORED_TOKENS = ['email', 'name', 'mssv', 'stt'];

    /** Tệp .xlsx (zip) bị đổi đuôi thành .csv. */
    public function looksLikeXlsx(string $content): bool
    {
        return str_starts_with($content, "PK\x03\x04");
    }

    /** Bỏ UTF-8 BOM và đảm bảo nội dung là UTF-8 hợp lệ. */
    public function normalizeEncoding(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16,WINDOWS-1258,CP1252,ISO-8859-1');
            $raw = ($converted && mb_check_encoding($converted, 'UTF-8'))
                ? $converted
                : mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        return $raw;
    }

    /**
     * @return string[]
     */
    public function extractIdentifiers(string $content, string $extension): array
    {
        $lines = preg_split('/[\r\n]+/', trim($content));
        if (empty($lines)) {
            return [];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $firstRowCells = str_getcsv($lines[0], $delimiter);

        [$emailCol, $codeCol] = $this->detectHeaderColumns($firstRowCells, $extension);
        $startIndex = ($emailCol !== null || $codeCol !== null) ? 1 : 0;

        $identifiers = [];
        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $cells = str_getcsv($line, $delimiter);
            $found = count($cells) > 1
                ? $this->fromMultiCellRow($cells, $emailCol, $codeCol)
                : $this->fromSingleCellLine($line);

            array_push($identifiers, ...$found);
        }

        return $identifiers;
    }

    /**
     * @return array{0:?int,1:?int} [cột email, cột MSSV] nếu dòng đầu là tiêu đề
     */
    private function detectHeaderColumns(array $firstRowCells, string $extension): array
    {
        $emailCol = null;
        $codeCol = null;

        if (count($firstRowCells) > 1 || $extension === 'csv') {
            foreach ($firstRowCells as $idx => $cell) {
                $clean = mb_strtolower(trim($cell));
                if (in_array($clean, self::EMAIL_HEADERS, true)) {
                    $emailCol = $idx;
                } elseif (in_array($clean, self::CODE_HEADERS, true)) {
                    $codeCol = $idx;
                }
            }
        }

        return [$emailCol, $codeCol];
    }

    /**
     * @return string[]
     */
    private function fromMultiCellRow(array $cells, ?int $emailCol, ?int $codeCol): array
    {
        if ($emailCol !== null && isset($cells[$emailCol]) && trim($cells[$emailCol]) !== '') {
            return [trim($cells[$emailCol])];
        }
        if ($codeCol !== null && isset($cells[$codeCol]) && trim($cells[$codeCol]) !== '') {
            return [trim($cells[$codeCol])];
        }

        $cells = array_map('trim', $cells);

        $emails = array_values(array_filter($cells, fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL)));
        if ($emails !== []) {
            return $emails;
        }

        $codes = array_values(array_filter($cells, fn ($c) => preg_match('/^[a-zA-Z0-9]{6,15}$/', $c) === 1));
        if ($codes === []) {
            return [];
        }

        $withDigit = array_values(array_filter($codes, fn ($c) => preg_match('/\d/', $c) === 1));
        $nonEmpty = array_filter($cells, fn ($c) => $c !== '');
        $pureCodeList = count($codes) === count($nonEmpty) && count($withDigit) === count($codes);

        // Hàng có tên cạnh mã (vd "1,Student,22520001"): ưu tiên ô có chữ số làm mã.
        return $pureCodeList ? $codes : [$withDigit[0] ?? $codes[0]];
    }

    /**
     * @return string[]
     */
    private function fromSingleCellLine(string $line): array
    {
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $matches) && ! empty($matches[0])) {
            return array_map('trim', $matches[0]);
        }

        $found = [];
        foreach (preg_split('/[,;]+/', $line) as $token) {
            $token = trim($token, " \t\n\r\0\x0B\"'");
            if ($token !== '' && ! in_array(mb_strtolower($token), self::IGNORED_TOKENS, true)) {
                $found[] = $token;
            }
        }

        return $found;
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [];
        foreach ([',', ';', "\t"] as $d) {
            $counts[$d] = substr_count($line, $d);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
