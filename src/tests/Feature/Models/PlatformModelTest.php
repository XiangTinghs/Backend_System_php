<?php

namespace Tests\Feature\Models;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LogicException;
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

    public function test_creation_accepts_code_and_other_fields_remain_updatable(): void
    {
        $platform = Platform::create(['code' => 'PlatformA', 'name' => 'Original'])->refresh();
        $this->assertSame('PlatformA', $platform->code);

        $this->assertTrue($platform->update(['name' => 'Renamed', 'is_active' => false]));
        $platform->refresh();
        $this->assertSame('PlatformA', $platform->code);
        $this->assertSame('Renamed', $platform->name);
        $this->assertFalse($platform->is_active);

        // Reassigning the identical value is not a code change at the Model layer.
        $this->assertTrue($platform->update(['code' => 'PlatformA', 'name' => 'Again', 'is_active' => true]));
        $platform->refresh();
        $this->assertSame('PlatformA', $platform->code);
        $this->assertSame('Again', $platform->name);
        $this->assertTrue($platform->is_active);
    }

    #[DataProvider('codeChanges')]
    public function test_existing_code_changes_are_rejected_without_persisting_any_fields(string $method, string $code): void
    {
        $platform = Platform::factory()->create(['code' => 'PlatformA'])->refresh();
        $before = $platform->getAttributes();
        $attributes = ['code' => $code, 'name' => 'Must not persist', 'is_active' => false];

        try {
            match ($method) {
                'update' => $platform->update($attributes),
                'fill_save' => $platform->fill($attributes)->save(),
                'assignment_save' => $this->assignAndSaveCode($platform, $code),
                'update_quietly' => $platform->updateQuietly($attributes),
                'save_quietly' => $platform->fill($attributes)->saveQuietly(),
            };
            $this->fail('Expected immutable platform code to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Platform code cannot be changed after creation.', $exception->getMessage());
        }

        $this->assertSame($before, $platform->refresh()->getAttributes());
        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'code' => 'PlatformA']);
        $this->assertDatabaseMissing('platforms', ['id' => $platform->id, 'code' => $code]);
    }

    private function assignAndSaveCode(Platform $platform, string $code): bool
    {
        $platform->code = $code;
        $platform->name = 'Must not persist';
        $platform->is_active = false;

        return $platform->save();
    }

    public static function codeChanges(): array
    {
        $cases = [];
        foreach (['update', 'fill_save', 'assignment_save', 'update_quietly', 'save_quietly'] as $method) {
            foreach (['PlatformB', 'platforma'] as $code) {
                $cases[$method . '_' . $code] = [$method, $code];
            }
        }

        return $cases;
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
