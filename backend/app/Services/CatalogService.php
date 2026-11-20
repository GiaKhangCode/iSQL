<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;

/**
 * Danh mục Môn học và Học kỳ.
 *
 * Dùng cho cả màn quản trị (Admin\CourseController, Admin\AcademicTermController)
 * lẫn danh sách chọn của giảng viên (Teacher\CatalogController).
 *
 * Các hàm tìm, sửa, xóa theo id ném ModelNotFoundException khi không có id đó, Laravel
 * tự chuyển thành phản hồi 404.
 */
class CatalogService
{
    // ── Môn học ─────────────────────────────────────────────────────────────

    /**
     * Tất cả môn học kèm số lớp (classes_count), sắp theo tên.
     *
     * @return Collection<int, Course>
     */
    public function listCourses(): Collection
    {
        return Course::withCount('classes')->orderBy('name')->get();
    }

    /**
     * Chỉ các môn đang hoạt động, dùng làm danh sách chọn cho giảng viên.
     *
     * @return Collection<int, Course>
     */
    public function listActiveCourses(): Collection
    {
        return Course::where('status', 'Active')->orderBy('name')->get();
    }

    public function findCourse(string $id): Course
    {
        return Course::withCount('classes')->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data đã được validate
     */
    public function createCourse(array $data): Course
    {
        return Course::create($data);
    }

    /**
     * @param array<string, mixed> $data đã được validate (chỉ các trường được gửi lên)
     */
    public function updateCourse(string $id, array $data): Course
    {
        $course = Course::findOrFail($id);
        $course->update($data);

        return $course->refresh();
    }

    public function deleteCourse(string $id): void
    {
        Course::findOrFail($id)->delete();
    }

    // ── Học kỳ ──────────────────────────────────────────────────────────────

    /**
     * Tất cả học kỳ kèm số lớp (classes_count), mới nhất trước.
     *
     * @return Collection<int, AcademicTerm>
     */
    public function listTerms(): Collection
    {
        return AcademicTerm::withCount('classes')->orderByDesc('start_date')->get();
    }

    /**
     * Chỉ các học kỳ đang hoạt động, dùng làm danh sách chọn cho giảng viên.
     *
     * @return Collection<int, AcademicTerm>
     */
    public function listActiveTerms(): Collection
    {
        return AcademicTerm::where('status', 'Active')->orderByDesc('start_date')->get();
    }

    public function findTerm(string $id): AcademicTerm
    {
        return AcademicTerm::withCount('classes')->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data đã được validate
     */
    public function createTerm(array $data): AcademicTerm
    {
        return AcademicTerm::create($data);
    }

    /**
     * @param array<string, mixed> $data đã được validate (chỉ các trường được gửi lên)
     */
    public function updateTerm(string $id, array $data): AcademicTerm
    {
        $term = AcademicTerm::findOrFail($id);
        $term->update($data);

        return $term->refresh();
    }

    public function deleteTerm(string $id): void
    {
        AcademicTerm::findOrFail($id)->delete();
    }
}
