<?php

namespace Illuminate\Tests\Integration\Database;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class QueryBuilderTest extends DatabaseTestCase
{
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('content');
            $table->timestamp('created_at');
        });

        DB::table('posts')->insert([
            ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.', 'created_at' => new Carbon('2017-11-12 13:14:15')],
            ['title' => 'Bar Post', 'content' => 'Lorem Ipsum.', 'created_at' => new Carbon('2018-01-02 03:04:05')],
        ]);

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('post_id');
            $table->text('content');
            $table->string('tag')->nullable();
            $table->integer('votes')->nullable();
            $table->timestamp('created_at');
        });

        DB::table('comments')->insert([
            ['post_id' => 1, 'content' => 'Lorem Ipsum a.', 'tag' => 'science', 'votes' => 1, 'created_at' => new Carbon('2023-01-01 13:14:15')],
            ['post_id' => 2, 'content' => 'Lorem Ipsum b.', 'tag' => 'science', 'votes' => 0, 'created_at' => new Carbon('2023-05-14 23:59:59')],
            ['post_id' => 2, 'content' => 'Lorem Ipsum c.', 'tag' => 'entertainment', 'votes' => null, 'created_at' => new Carbon('2023-02-03 17:49:14')],
            ['post_id' => 1, 'content' => 'Lorem Ipsum d.', 'tag' => null, 'votes' => null, 'created_at' => new Carbon('2023-04-27 20:00:05')],
            ['post_id' => 1, 'content' => 'Lorem Ipsum e.', 'tag' => '', 'votes' => null, 'created_at' => new Carbon('2022-09-22 14:30:05')],
        ]);
    }

    public function testIncrement()
    {
        Schema::create('accounting', function (Blueprint $table) {
            $table->increments('id');
            $table->float('wallet_1');
            $table->float('wallet_2');
            $table->integer('user_id');
            $table->string('name', 20);
        });

        DB::table('accounting')->insert([
            [
                'wallet_1' => 100,
                'wallet_2' => 200,
                'user_id' => 1,
                'name' => 'Taylor',
            ],
            [
                'wallet_1' => 15,
                'wallet_2' => 300,
                'user_id' => 2,
                'name' => 'Otwell',
            ],
        ]);
        $connection = DB::table('accounting')->getConnection();
        $connection->enableQueryLog();

        DB::table('accounting')->where('user_id', 2)->incrementEach([
            'wallet_1' => 10,
            'wallet_2' => -20,
        ], ['name' => 'foo']);

        $queryLogs = $connection->getQueryLog();
        $this->assertCount(1, $queryLogs);

        $rows = DB::table('accounting')->get();

        $this->assertCount(2, $rows);
        // other rows are not affected.
        $this->assertEquals([
            'id' => 1,
            'wallet_1' => 100,
            'wallet_2' => 200,
            'user_id' => 1,
            'name' => 'Taylor',
        ], (array) $rows[0]);

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + 10,
            'wallet_2' => 300 - 20,
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // without the second argument.
        $affectedRowsCount = DB::table('accounting')->where('user_id', 2)->incrementEach([
            'wallet_1' => 20,
            'wallet_2' => 20,
        ]);

        $this->assertEquals(1, $affectedRowsCount);

        $rows = DB::table('accounting')->get();

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + (10 + 20),
            'wallet_2' => 300 + (-20 + 20),
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // Test Can affect multiple rows at once.
        $affectedRowsCount = DB::table('accounting')->incrementEach([
            'wallet_1' => 31.5,
            'wallet_2' => '-32.5',
        ]);

        $this->assertEquals(2, $affectedRowsCount);

        $rows = DB::table('accounting')->get();
        $this->assertEquals([
            'id' => 1,
            'wallet_1' => 100 + 31.5,
            'wallet_2' => 200 - 32.5,
            'user_id' => 1,
            'name' => 'Taylor',
        ], (array) $rows[0]);

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + (10 + 20 + 31.5),
            'wallet_2' => 300 + (-20 + 20 - 32.5),
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // In case of a conflict, the second argument wins and sets a fixed value:
        $affectedRowsCount = DB::table('accounting')->incrementEach([
            'wallet_1' => 3000,
        ], ['wallet_1' => 1.5]);

        $this->assertEquals(2, $affectedRowsCount);

        $rows = DB::table('accounting')->get();

        $this->assertEquals(1.5, $rows[0]->wallet_1);
        $this->assertEquals(1.5, $rows[1]->wallet_1);

        Schema::drop('accounting');
    }

    public function testSole()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post'];

        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->select('id', 'title')->sole());
    }

    public function testSoleWithParameters()
    {
        $expected = ['id' => '1'];

        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole('id'));
        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole(['id']));

        $expected = ['id' => '1', 'title' => 'Foo Post'];
        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole(['id', 'title']));
    }

    public function testSoleFailsForMultipleRecords()
    {
        DB::table('posts')->insert([
            ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.', 'created_at' => new Carbon('2017-11-12 13:14:15')],
        ]);

        $this->expectExceptionObject(new MultipleRecordsFoundException(2));

        DB::table('posts')->where('title', 'Foo Post')->sole();
    }

    public function testSoleFailsIfNoRecords()
    {
        $this->expectException(RecordsNotFoundException::class);

        DB::table('posts')->where('title', 'Baz Post')->sole();
    }

    public function testSelect()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post'];

        $this->assertEquals($expected, (array) DB::table('posts')->select('id', 'title')->first());
        $this->assertEquals($expected, (array) DB::table('posts')->select(['id', 'title'])->first());

        $this->assertCount(4, (array) DB::table('posts')->select()->first());
    }

    public function testSelectReplacesExistingSelects()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post'],
            (array) DB::table('posts')->select('content')->select(['id', 'title'])->first()
        );
    }

    public function testSelectWithSubQuery()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post', 'foo' => 'Lorem Ipsum.'],
            (array) DB::table('posts')->select(['id', 'title', 'foo' => function ($query) {
                $query->select('content');
            }])->first()
        );
    }

    public function testAddSelect()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post', 'content' => 'Lorem Ipsum.'];

        $this->assertEquals($expected, (array) DB::table('posts')->select('id')->addSelect('title', 'content')->first());
        $this->assertEquals($expected, (array) DB::table('posts')->select('id')->addSelect(['title', 'content'])->first());
        $this->assertEquals($expected, (array) DB::table('posts')->addSelect(['id', 'title', 'content'])->first());

        $this->assertCount(4, (array) DB::table('posts')->addSelect([])->first());
        $this->assertEquals(['id' => '1'], (array) DB::table('posts')->select('id')->addSelect([])->first());
    }

    public function testAddSelectWithSubQuery()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post', 'foo' => 'Lorem Ipsum.'],
            (array) DB::table('posts')->addSelect(['id', 'title', 'foo' => function ($query) {
                $query->select('content');
            }])->first()
        );
    }

    public function testFromWithAlias()
    {
        $this->assertCount(2, DB::table('posts', 'alias')->select('alias.*')->get());
    }

    public function testFromWithSubQuery()
    {
        $this->assertSame(
            'Fake Post',
            DB::table(function ($query) {
                $query->selectRaw("'Fake Post' as title");
            }, 'posts')->first()->title
        );
    }

    public function testWhereValueSubQuery()
    {
        $subQuery = function ($query) {
            $query->selectRaw("'Sub query value'");
        };

        $this->assertTrue(DB::table('posts')->where($subQuery, 'Sub query value')->exists());
        $this->assertFalse(DB::table('posts')->where($subQuery, 'Does not match')->exists());
        $this->assertTrue(DB::table('posts')->where($subQuery, '!=', 'Does not match')->exists());
    }

    public function testWhereValueSubQueryBuilder()
    {
        $subQuery = DB::table('posts')->selectRaw("'Sub query value'")->limit(1);

        $this->assertTrue(DB::table('posts')->where($subQuery, 'Sub query value')->exists());
        $this->assertFalse(DB::table('posts')->where($subQuery, 'Does not match')->exists());
        $this->assertTrue(DB::table('posts')->where($subQuery, '!=', 'Does not match')->exists());

        $this->assertTrue(DB::table('posts')->where(DB::raw('\'Sub query value\''), $subQuery)->exists());
        $this->assertFalse(DB::table('posts')->where(DB::raw('\'Does not match\''), $subQuery)->exists());
        $this->assertTrue(DB::table('posts')->where(DB::raw('\'Does not match\''), '!=', $subQuery)->exists());
    }

    public function testWhereNot()
    {
        $results = DB::table('posts')->whereNot(function ($query) {
            $query->where('title', 'Foo Post');
        })->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bar Post', $results[0]->title);
    }

    public function testWhereNotInputStringParameter()
    {
        $results = DB::table('posts')->whereNot('title', 'Foo Post')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bar Post', $results[0]->title);

        DB::table('posts')->insert([
            ['title' => 'Baz Post', 'content' => 'Lorem Ipsum.', 'created_at' => new Carbon('2017-11-12 13:14:15')],
        ]);

        $results = DB::table('posts')->whereNot('title', 'Foo Post')->whereNot('title', 'Bar Post')->get();
        $this->assertSame('Baz Post', $results[0]->title);
    }

    public function testOrWhereNot()
    {
        $results = DB::table('posts')->where('id', 1)->orWhereNot(function ($query) {
            $query->where('title', 'Foo Post');
        })->get();

        $this->assertCount(2, $results);
    }

    public function testWhereDate()
    {
        $this->assertSame(1, DB::table('posts')->whereDate('created_at', '2018-01-02')->count());
        $this->assertSame(1, DB::table('posts')->whereDate('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testOrWhereDate()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDate('created_at', '2018-01-02')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDate('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testWhereDay()
    {
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', '02')->count());
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', 2)->count());
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testOrWhereDay()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', '02')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', 2)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testWhereMonth()
    {
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', '01')->count());
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', 1)->count());
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testOrWhereMonth()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', '01')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', 1)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testWhereYear()
    {
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', '2018')->count());
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', 2018)->count());
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testOrWhereYear()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', '2018')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', 2018)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', new Carbon('2018-01-02'))->count());
    }

    public function testWhereTime()
    {
        $this->assertSame(1, DB::table('posts')->whereTime('created_at', '03:04:05')->count());
        $this->assertSame(1, DB::table('posts')->whereTime('created_at', new Carbon('2018-01-02 03:04:05'))->count());
    }

    public function testOrWhereTime()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereTime('created_at', '03:04:05')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereTime('created_at', new Carbon('2018-01-02 03:04:05'))->count());
    }

    public function testWhereNested()
    {
        $results = DB::table('posts')->where('content', 'Lorem Ipsum.')->whereNested(function ($query) {
            $query->where('title', 'Foo Post')
                ->orWhere('title', 'Bar Post');
        })->count();
        $this->assertSame(2, $results);
    }

    public function testPaginateWithSpecificColumns()
    {
        $result = DB::table('posts')->paginate(5, ['title', 'content']);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertEquals($result->items(), [
            (object) ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.'],
            (object) ['title' => 'Bar Post', 'content' => 'Lorem Ipsum.'],
        ]);
    }

    public function testChunkMap()
    {
        DB::enableQueryLog();

        $results = DB::table('posts')->orderBy('id')->chunkMap(function ($post) {
            return $post->title;
        }, 1);

        $this->assertCount(2, $results);
        $this->assertSame('Foo Post', $results[0]);
        $this->assertSame('Bar Post', $results[1]);
        $this->assertCount(3, DB::getQueryLog());
    }


    #[DataProvider('pluckProvider')]
    public function testPluck(string $pluckFn): void
    {
        // Test SELECT override, since pluck will take the first column.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->select(['content', 'id', 'title'])->$pluckFn('title')->toArray());

        // Test without SELECT override.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->$pluckFn('title')->toArray());

        // Test specific key.
        $this->assertSame([
            1 => 'Foo Post',
            2 => 'Bar Post',
        ], DB::table('posts')->$pluckFn('title', 'id')->toArray());

        $results = DB::table('posts')->$pluckFn('title', 'created_at');

        // Test timestamps (truncates RDBMS differences).
        $this->assertSame([
            '2017-11-12 13:14:15',
            '2018-01-02 03:04:05',
        ], $results->keys()->map(fn ($v) => substr($v, 0, 19))->toArray());
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], $results->values()->toArray());

        // Test duplicate keys (a match will override a previous match).
        $this->assertSame([
            'Lorem Ipsum.' => 'Bar Post',
        ], DB::table('posts')->$pluckFn('title', 'content')->toArray());

        // Test custom query calculations.
        $this->assertSame([
            2 => 'FOO POST',
            4 => 'BAR POST',
        ], DB::table('posts')->$pluckFn(
            DB::raw('UPPER(title)'),
            DB::raw('2 * id')
        )->toArray());

        // Test null and empty string as key.
        $this->assertSame([
            'science' => 'Lorem Ipsum b.',
            'entertainment' => 'Lorem Ipsum c.',
            null => 'Lorem Ipsum d.',
            '' => 'Lorem Ipsum e.',
        ],  DB::table('comments')->$pluckFn('content', 'tag')->toArray());

        // Test null and numeric as key.
        $this->assertSame([
            1 => 'Lorem Ipsum a.',
            0 => 'Lorem Ipsum b.',
            null => 'Lorem Ipsum e.',
        ],  DB::table('comments')->$pluckFn('content', 'votes')->toArray());

        // Test null and numeric values with string keys.
        $this->assertSame([
            'Lorem Ipsum a.' => 1,
            'Lorem Ipsum b.' => 0,
            'Lorem Ipsum c.' => null,
            'Lorem Ipsum d.' => null,
            'Lorem Ipsum e.' => null,
        ],  DB::table('comments')->$pluckFn('votes', 'content')->toArray());
    }

    public static function pluckProvider(): array
    {
        return [
            ['pluck'],
            ['pluckPDO'],
        ];
    }
}
