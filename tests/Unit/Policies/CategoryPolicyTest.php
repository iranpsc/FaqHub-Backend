<?php

namespace Tests\Unit\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\CategoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CategoryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CategoryPolicy;
    }

    public function test_view_any_allows_guests_and_users(): void
    {
        $this->assertTrue($this->policy->viewAny(null));
        $this->assertTrue($this->policy->viewAny(User::factory()->create()));
        $this->assertTrue($this->policy->viewAny(User::factory()->admin()->create()));
    }

    public function test_view_allows_guests_and_users(): void
    {
        $category = Category::factory()->create();

        $this->assertTrue($this->policy->view(null, $category));
        $this->assertTrue($this->policy->view(User::factory()->create(), $category));
        $this->assertTrue($this->policy->view(User::factory()->admin()->create(), $category));
    }

    #[DataProvider('adminOnlyAbilityProvider')]
    public function test_write_abilities_require_admin(string $ability): void
    {
        $category = Category::factory()->create();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['role' => 'user', 'level' => 13]);

        if ($ability === 'create') {
            $this->assertTrue($this->policy->create($admin));
            $this->assertFalse($this->policy->create($user));

            return;
        }

        $this->assertTrue($this->policy->{$ability}($admin, $category));
        $this->assertFalse($this->policy->{$ability}($user, $category));
    }

    public static function adminOnlyAbilityProvider(): array
    {
        return [
            'create' => ['create'],
            'update' => ['update'],
            'delete' => ['delete'],
        ];
    }

    public function test_is_admin_is_role_based_not_level_based(): void
    {
        $category = Category::factory()->create();
        $highLevelUser = User::factory()->create(['role' => 'user', 'level' => 13]);
        $lowLevelAdmin = User::factory()->admin()->create(['level' => 1]);

        $this->assertFalse($this->policy->create($highLevelUser));
        $this->assertFalse($this->policy->update($highLevelUser, $category));
        $this->assertFalse($this->policy->delete($highLevelUser, $category));

        $this->assertTrue($this->policy->create($lowLevelAdmin));
        $this->assertTrue($this->policy->update($lowLevelAdmin, $category));
        $this->assertTrue($this->policy->delete($lowLevelAdmin, $category));
    }
}
