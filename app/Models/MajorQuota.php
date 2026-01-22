<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MajorQuota extends Model
{
    use HasFactory;

    protected $table = 'major_quotas';

  protected $fillable = [
    'major_id',
    'academic_year',
    'limit',
    'opens_at',
    'closes_at',
];

protected $casts = [
    'opens_at' => 'datetime',
    'closes_at' => 'datetime',
];


    public function major()
    {
        return $this->belongsTo(Major::class);
    }
}
