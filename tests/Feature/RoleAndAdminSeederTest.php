<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

function configureLocalAdminSeed(): void
{
    config(['chat.local_admin' => [
        'name' => 'Seeded Admin',
        'email' => 'seeded-admin@example.test',
        'password' => 'SeederPassword123!',
    ]]);
}

test('role seeder maintains the two named roles without duplicating them', function () {
    $adminId = Role::query()->where('slug', 'admin')->sole()->id;
    Role::query()->where('slug', 'admin')->update(['name' => 'Old label']);

    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->assertDatabaseCount('roles', 2);
    $this->assertDatabaseHas('roles', ['id' => $adminId, 'slug' => 'admin', 'name' => 'Yönetici']);
    $this->assertDatabaseHas('roles', ['slug' => 'employee', 'name' => 'Çalışan']);
});

test('admin seeder stores a hashed password and permits login and admin access', function () {
    configureLocalAdminSeed();
    Notification::fake();

    $this->seed(AdminUserSeeder::class);
    $user = User::query()->where('email', 'seeded-admin@example.test')->sole();

    expect($user->password)->not->toBe('SeederPassword123!');
    expect(Hash::check('SeederPassword123!', $user->password))->toBeTrue();
    expect($user->is_active)->toBeTrue();
    expect($user->password_set_at)->not->toBeNull();
    expect($user->assignedRole->name)->toBe('Yönetici');
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'SeederPassword123!'])->assertRedirect(route('chat'));
    $this->assertAuthenticatedAs($user);
    $this->get(route('admin.dashboard'))->assertOk();
    Notification::assertNothingSent();
});

test('users belong to the named role and roles expose their users', function () {
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->create();

    expect($employee->assignedRole->name)->toBe('Çalışan');
    expect($admin->assignedRole->users()->sole()->id)->toBe($admin->id);
});

test('repeating admin seeder preserves existing password activity and role', function () {
    configureLocalAdminSeed();
    $this->seed(AdminUserSeeder::class);
    $user = User::query()->where('email', 'seeded-admin@example.test')->sole();
    $user->forceFill(['password' => 'ChangedPassword987!', 'role' => User::ROLE_EMPLOYEE, 'is_active' => false])->save();
    $existingHash = $user->password;

    $this->seed(AdminUserSeeder::class);

    $this->assertDatabaseCount('users', 1);
    $user->refresh();
    expect($user->password)->toBe($existingHash);
    expect($user->role)->toBe(User::ROLE_EMPLOYEE);
    expect($user->is_active)->toBeFalse();
});

test('invalid admin credentials do not create an account', function (string $key, mixed $value) {
    configureLocalAdminSeed();
    config(['chat.local_admin.'.$key => $value]);

    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('users', 0);
})->with([
    'missing password' => ['password', null],
    'weak password' => ['password', 'short'],
    'invalid email' => ['email', 'invalid'],
    'missing name' => ['name', null],
]);

test('production cannot run the local admin seeder', function () {
    configureLocalAdminSeed();
    app()->instance('env', 'production');

    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(LogicException::class);

    $this->assertDatabaseCount('users', 0);
});

test('default database seeding creates roles and channel without an admin account', function () {
    configureLocalAdminSeed();

    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('roles', 2);
    $this->assertDatabaseHas('channels', ['slug' => 'general']);
    $this->assertDatabaseCount('users', 0);
});

test('the database refuses user roles that are not defined in roles', function () {
    expect(fn () => User::factory()->create(['role' => 'undefined-role']))->toThrow(QueryException::class);

    $this->assertDatabaseCount('users', 0);
});
