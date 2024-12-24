<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Projects extends Model
{
    use HasFactory,SoftDeletes;
    
    protected $primaryKey = 'project_id';
    protected $table = 'projects';
    public $incrementing = true;
    public $timestamps = true;
    protected $fillable = [
        'project_id',
        'project_name',
        'description',
        'deadline',
        'pm_id',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    public function projectManager()
    {
        return $this->hasOne(User::class,'user_id', 'pm_id');
    }

    public function teamMembers()
    {
    return $this->belongsToMany(User::class, 'users_has_teams', 'project_id', 'users_id')->withTimestamps();
    }

    public function task()
    {
        return $this->hasMany(Tasks::class, 'project_id', 'project_id');
    }

    protected $appends = ['status_deadline'];

    public function getStatusDeadlineAttribute()
    {
        return $this->deadline > now() ? 'Tepat Waktu' : 'Terlambat';
    }
}
