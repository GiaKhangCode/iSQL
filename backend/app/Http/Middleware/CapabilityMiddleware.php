<?php

namespace App\Http\Middleware;

use App\Services\Access\AccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn route theo mã quyền: `capability:problem.create` hoặc `capability:results.grade,results.view_all`
 * (đạt khi có ÍT NHẤT MỘT mã). Chỉ kiểm tra "loại việc"; phạm vi do Policy/service quyết định.
 */
class CapabilityMiddleware
{
    public function __construct(private AccessControlService $access)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthorized');
        }

        // `exam.session`: tài khoản thi tạm (token "exam:...") vẫn làm bài trong ca thi, không phụ thuộc quyền tab.
        $examToken = in_array('exam.session', $permissions, true)
            && str_starts_with((string) ($user->currentAccessToken()?->name ?? ''), 'exam:');

        if (!$examToken && !$this->access->canAny($user, $permissions)) {
            abort(403, 'Bạn không có quyền thực hiện thao tác này.');
        }

        return $next($request);
    }
}
