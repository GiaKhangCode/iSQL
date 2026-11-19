<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khu vực giao diện không còn là thuộc tính của vai trò: nó được suy ra từ quyền hiệu lực
 * (PermissionCatalog::areasFor), nên cột access_roles.area không còn ai ghi hay đọc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('access_roles', 'area')) {
            Schema::table('access_roles', function (Blueprint $table) {
                $table->dropColumn('area');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('access_roles', 'area')) {
            Schema::table('access_roles', function (Blueprint $table) {
                $table->string('area', 20)->default('student')->after('name');
            });
        }
    }
};
