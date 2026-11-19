<?php

namespace App\Models\Account\Admin;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'administrator_roles';
    public $timestamps = false;
    protected $guarded = ['id'];
}
