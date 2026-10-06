<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'chat:create-admin';

    protected $description = 'Create an administrator securely using interactive prompts';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively; credentials are never accepted as command-line arguments.');

            return self::FAILURE;
        }
        $name = $this->ask('Admin adı');
        $email = mb_strtolower(trim((string) $this->ask('E-posta')));
        $password = $this->secret('Şifre (en az 12 karakter, büyük/küçük harf, sayı ve sembol)');
        $validation = Validator::make(compact('name', 'email', 'password'), ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => User::ROLE_ADMIN, 'is_active' => true, 'password_set_at' => now(), 'email_verified_at' => now()])->save();
        $this->info('Admin oluşturuldu.');

        return self::SUCCESS;
    }
}
