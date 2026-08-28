<?php

namespace Tests\Unit\Models;

use App\Models\Question;
use App\Models\Tag;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_without_slug_generates_slug_from_name(): void
    {
        $tag = Tag::create(['name' => 'Hello World']);

        $this->assertSame('hello-world', $tag->slug);
        $this->assertDatabaseHas('tags', [
            'name' => 'Hello World',
            'slug' => 'hello-world',
        ]);
    }

    public function test_creating_with_explicit_slug_preserves_it(): void
    {
        $tag = Tag::create([
            'name' => 'Hello World',
            'slug' => 'custom-slug',
        ]);

        $this->assertSame('custom-slug', $tag->slug);
    }

    public function test_updating_name_does_not_change_existing_slug(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Before',
            'slug' => 'before',
        ]);

        $tag->update(['name' => 'After']);

        $this->assertSame('After', $tag->fresh()->name);
        $this->assertSame('before', $tag->fresh()->slug);
    }

    public function test_updating_name_with_empty_slug_regenerates_slug(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Before',
            'slug' => 'before',
        ]);

        $tag->update([
            'name' => 'After Name',
            'slug' => '',
        ]);

        $this->assertSame('after-name', $tag->fresh()->slug);
    }

    public function test_fillable_only_allows_name_and_slug(): void
    {
        $tag = Tag::create([
            'name' => 'Fillable',
            'slug' => 'fillable',
            'id' => 12345,
        ]);

        $this->assertNotEquals(12345, $tag->id);
        $this->assertSame(['name', 'slug'], $tag->getFillable());
    }

    public function test_questions_relationship_is_belongs_to_many(): void
    {
        $tag = Tag::factory()->create();
        $questions = Question::factory()->count(2)->create();
        $tag->questions()->attach($questions);

        $this->assertCount(2, $tag->questions);
        $this->assertTrue($tag->questions->contains($questions[0]));
        $this->assertTrue($tag->questions->contains($questions[1]));
    }

    public function test_slug_generation_matches_str_slug(): void
    {
        $name = 'C++ / Node.js & PHP!';
        $tag = Tag::create(['name' => $name]);

        $this->assertSame(Str::slug($name), $tag->slug);
    }

    public function test_model_does_not_use_soft_deletes(): void
    {
        $tag = Tag::factory()->create();
        $id = $tag->id;

        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(Tag::class));

        $tag->delete();

        $this->assertDatabaseMissing('tags', ['id' => $id]);
        $this->assertNull(Tag::find($id));
    }
}
