<?php

namespace Tests\Feature\Models;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_defaults_and_relationships(): void
    {
        $platform = Platform::create(['code' => 'PlatformA', 'name' => 'Platform A'])->refresh();
        $player = User::factory()->for($platform)->create();
        User::factory()->create();

        $this->assertTrue($platform->is_active);
        $this->assertNotNull($platform->created_at);
        $this->assertNotNull($platform->updated_at);
        $this->assertSame([$player->id], $platform->users->modelKeys());
        $platform->update(['is_active' => false]);
        $this->assertFalse($platform->refresh()->is_active);
    }

    public function test_code_is_case_sensitive_but_exact_duplicates_are_rejected(): void
    {
        Platform::factory()->create(['code' => 'PlatformA']);
        Platform::factory()->create(['code' => 'platforma']);
        $this->assertSame(1, Platform::where('code', 'PlatformA')->count());
        $this->assertSame(1, Platform::where('code', 'platforma')->count());

        $this->expectException(QueryException::class);
        Platform::factory()->create(['code' => 'PlatformA']);
    }

    #[DataProvider('invalidPlatforms')]
    public function test_required_fields_and_column_limits_are_enforced(array $attributes): void
    {
        $this->expectException(QueryException::class);
        Platform::factory()->create($attributes);
    }

    public static function invalidPlatforms(): array
    {
        return [
            'missing code' => [['code' => null]],
            'missing name' => [['name' => null]],
            'code too long' => [['code' => str_repeat('x', 51)]],
            'name too long' => [['name' => str_repeat('x', 101)]],
        ];
    }

    public function test_referenced_platform_cannot_be_deleted(): void
    {
        $platform = Platform::factory()->create();
        $player = User::factory()->for($platform)->create();

        try {
            $platform->delete();
            $this->fail('Expected the platform foreign key to restrict deletion.');
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
        }

        $this->assertModelExists($platform);
        $this->assertModelExists($player);
    }
}
