<?php

namespace Illuminate\Tests\Integration\Database\EloquentCrossDatabaseTest;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;

class EloquentCrossDatabaseTest extends DatabaseTestCase
{
    public const string SECONDARY_SCHEMA = 'schema_two';

    protected function setUpDatabaseRequirements(Closure $callback): void
    {
        try {
            $this->app['db']->connection()->statement('CREATE DATABASE '.static::SECONDARY_SCHEMA);
        } catch (QueryException $e) {
            // Ignore error if database already exists.
        }

        parent::setUpDatabaseRequirements($callback);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        try {
            Schema::create('posts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title');
                $table->foreignId('user_id')->nullable();
                $table->foreignId('root_tag_id')->nullable();
            });

            Schema::create(static::SECONDARY_SCHEMA.'.users', function (Blueprint $table) {
                $table->increments('id');
                $table->string('username');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.sub_posts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title');
                $table->foreignId('post_id');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.views', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('hits')->default(1);
                $table->morphs('viewable');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.viewables', function (Blueprint $table) {
                $table->foreignId('view_id');
                $table->morphs('viewable');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.comments', function (Blueprint $table) {
                $table->increments('id');
                $table->string('content');
                $table->foreignId('sub_post_id');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.tags', function (Blueprint $table) {
                $table->increments('id');
                $table->string('tag');
            });

            Schema::create(static::SECONDARY_SCHEMA.'.post_tag', function (Blueprint $table) {
                $table->foreignId('post_id');
                $table->foreignId('tag_id');
            });

            Post::query()->insert([
                ['title' => 'Foobar', 'user_id' => 1],
                ['title' => 'The title', 'user_id' => 1],
            ]);

            User::query()->insert([
                ['username' => 'Lortay Wellot'],
            ]);

            SubPost::query()->insert([
                ['title' => 'The subpost title', 'post_id' => 1],
            ]);

            Comment::query()->insert([
                ['content' => 'The comment content', 'sub_post_id' => 1],
            ]);

            View::query()->insert([
                ['hits' => 123, 'viewable_id' => 1, 'viewable_type' => Post::class],
            ]);
        } catch (QueryException $e) {
            // Ignore error if table already exists.
        }
    }

    protected function destroyDatabaseMigrations()
    {
        Schema::dropIfExists('posts');

        foreach (['users', 'sub_posts', 'comments', 'views', 'viewables', 'tags', 'post_tag'] as $table) {
            Schema::dropIfExists(static::SECONDARY_SCHEMA.'.'.$table);
        }

        $this->app['db']->connection()->statement('DROP DATABASE '.static::SECONDARY_SCHEMA);
    }

    public function test_relationships(): void
    {
        // We only test general compilation without errors here, indicating that cross-database queries have been
        // executed correctly.

        foreach (['comments', 'rootTag', 'subPosts', 'tags', 'user', 'view', 'viewables'] as $relation) {
            $this->assertInstanceOf(Collection::class, Post::query()->with($relation)->get());
            $this->assertInstanceOf(Collection::class, Post::query()->withCount($relation)->get());
            $this->assertInstanceOf(Collection::class, Post::query()->whereHas($relation)->get());
        }

        $this->assertInstanceOf(Collection::class, View::query()->with('posts')->get());
        $this->assertInstanceOf(Collection::class, View::query()->withCount('posts')->get());
        $this->assertInstanceOf(Collection::class, View::query()->whereHas('posts')->get());
        $this->assertInstanceOf(Collection::class, View::query()->with('viewable')->get());
        $this->assertInstanceOf(Collection::class, View::query()->whereHas('viewable')->get());
    }
}

abstract class BaseModel extends Model
{
    public $timestamps = false;
    protected $guarded = [];
}

abstract class SecondaryBaseModel extends BaseModel
{
    public function getTable(): ?string
    {
        return EloquentCrossDatabaseTest::SECONDARY_SCHEMA.'.'.parent::getTable();
    }
}

class Post extends BaseModel
{
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(Comment::class, SubPost::class, 'post_id', 'sub_post_id');
    }

    public function rootTag(): HasOne
    {
        return $this->hasOne(Tag::class, 'id', 'root_tag_id');
    }

    public function subPosts(): HasMany
    {
        return $this->hasMany(SubPost::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, PostTag::class)->using(PostTag::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function view(): MorphOne
    {
        return $this->morphOne(View::class, 'viewable');
    }

    public function viewables(): MorphToMany
    {
        return $this->morphToMany(View::class, 'viewable', Viewable::class)->using(Viewable::class);
    }
}

class User extends SecondaryBaseModel
{
    //
}

class SubPost extends SecondaryBaseModel
{
    //
}

class Comment extends SecondaryBaseModel
{
    //
}

class Tag extends SecondaryBaseModel
{
    //
}

class View extends SecondaryBaseModel
{
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'viewable', Viewable::class)->using(Viewable::class);
    }

    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }
}

class PostTag extends Pivot
{
    protected $table = EloquentCrossDatabaseTest::SECONDARY_SCHEMA.'.post_tag';
}

class Viewable extends Pivot
{
    protected $table = EloquentCrossDatabaseTest::SECONDARY_SCHEMA.'.viewables';
}
