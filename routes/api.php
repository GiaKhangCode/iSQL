<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — iSQL Backend
|--------------------------------------------------------------------------
| Tất cả các route được tổ chức theo module theo đúng Blueprint.
| Đường dẫn URL và format JSON phải tương thích 100% với Frontend React.
*/

Route::prefix('auth')->group(base_path('routes/auth.php'));
Route::prefix('admin')->group(base_path('routes/admin.php'));
Route::prefix('teacher')->group(base_path('routes/teacher.php'));
Route::prefix('student')->group(base_path('routes/student.php'));
Route::prefix('problems')->group(base_path('routes/problems.php'));
Route::prefix('assignments')->group(base_path('routes/assignments.php'));
Route::prefix('contests')->group(base_path('routes/contests.php'));
Route::prefix('submissions')->group(base_path('routes/submissions.php'));
Route::prefix('notifications')->group(base_path('routes/notifications.php'));