<?php

namespace Illuminate\Tests\Integration\Database\EloquentCrossDatabaseTest;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;

class EloquentCrossDatabaseTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        define('__TEST_SECONDARY_SCHEMA', 'schema_two');

        parent::getEnvironmentSetUp($app);
    }

    protected function setUpDatabaseRequirements(Closure $callback): void
    {
        try {
            $this->app['db']->connection()->statement('CREATE DATABASE '.__TEST_SECONDARY_SCHEMA);
        } catch(QueryException $e) {
            // ...
        }

        parent::setUpDatabaseRequirements($callback);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->foreignId('user_id')->nullable();
            $table->foreignId('root_tag_id')->nullable();
        })
        ;
        Schema::create(__TEST_SECONDARY_SCHEMA.'.users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.sub_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->foreignId('post_id');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.views', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('hits')->default(1);
            $table->morphs('viewable');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.viewables', function (Blueprint $table) {
            $table->foreignId('view_id');
            $table->morphs('viewable');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('content');
            $table->foreignId('sub_post_id');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tag');
        });

        Schema::create(__TEST_SECONDARY_SCHEMA.'.post_tag', function (Blueprint $table) {
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
    }

    protected function destroyDatabaseMigrations()
    {
        Schema::dropIfExists('posts');

        foreach (['users', 'sub_posts', 'comments', 'views', 'viewables', 'tags', 'post_tag'] as $table) {
            Schema::dropIfExists(__TEST_SECONDARY_SCHEMA.'.'.$table);
        }
    }

    public function testRelationships()
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
    /**
     * @return string|null
     */
    public function getTable(): ?string
    {
        return __TEST_SECONDARY_SCHEMA.'.'.parent::getTable();
    }
}

class Post extends BaseModel
{
    public function comments()
    {
        return $this->hasManyThrough(Comment::class, SubPost::class, 'post_id', 'sub_post_id');
    }

    public function rootTag()
    {
        return $this->hasOne(Tag::class, 'id', 'root_tag_id');
    }


    public function subPosts()
    {
        return $this->hasMany(SubPost::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class)->using(PostTag::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function view()
    {
        return $this->morphOne(View::class, 'viewable');
    }

    public function viewables()
    {
        return $this->morphToMany(View::class, 'viewable', 'viewables')->using(Viewable::class);
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
    public function posts()
    {
        return $this->morphedByMany(Post::class, 'viewable');
    }

    public function viewable()
    {
        return $this->morphTo();
    }
}

class PostTag extends Pivot
{
    protected $table = __TEST_SECONDARY_SCHEMA . '.post_tag';
}

class Viewable extends Pivot
{
    protected $table = __TEST_SECONDARY_SCHEMA . '.viewables';
}
