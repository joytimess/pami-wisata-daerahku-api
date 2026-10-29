<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BootstrapSuperadmin extends Command
{
    protected $signature = 'app:bootstrap-superadmin';

    protected $description = 'Create the first superadmin interactively';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal is required.');

            return self::FAILURE;
        }
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 8 characters)');
        $data = ['name' => $name, 'email' => $email, 'password' => $password];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:8']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            if (User::where('role', 2)->lockForUpdate()->exists()) {
                throw new \RuntimeException('A superadmin already exists.');
            }
            $user = User::create($data);
            $user->role = 2;
            $user->save();
        }, 3);
        $this->info('Superadmin created.');

        return self::SUCCESS;
    }
}
