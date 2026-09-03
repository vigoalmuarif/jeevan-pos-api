<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Abstracts\BaseService;
use Modules\User\Models\User;

class RegisterService extends BaseService
{
    public function register(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'username' => Str::lower(Str::slug($data['username'], '_')),
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
            'is_owner' => true,
            'is_all_branches' =>  true,
        ]);

        return $user;
    }

}