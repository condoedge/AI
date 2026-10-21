<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** A plain users-table user for the admin insights access tests. */
class AdminTestUser extends User
{
    protected $table = 'users';
    protected $guarded = [];
}
