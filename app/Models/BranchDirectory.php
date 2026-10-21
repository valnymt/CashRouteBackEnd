<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The bank's master list of branches (Add Branch suggestions). Table: branch_directory */
class BranchDirectory extends Model
{
    protected $table = 'branch_directory';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
