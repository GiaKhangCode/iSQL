<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TestCaseScript extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'test_case_id',
        'table_name',
        'create_script',
        'insert_script',
        'is_expected',
    ];

    protected $casts = [
        'is_expected' => 'boolean',
    ];

    public function testCase()
    {
        return $this->belongsTo(TestCase::class, 'test_case_id');
    }
}
