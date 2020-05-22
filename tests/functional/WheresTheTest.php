<?php

namespace Vinelab\NeoEloquent\Tests\Functional;

use Mockery as M;
use Vinelab\NeoEloquent\Tests\TestCase;
use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\Eloquent\Collection;

class User extends Model
{
    protected $label = 'Individual';

    protected $fillable = ['name', 'email', 'alias', 'calls'];
}

class WheresTheTest extends TestCase
{
    public function tearDown()
    {
        M::close();

        $all = User::all();
        $all->each(function ($u) { $u->delete(); });

        parent::tearDown();
    }

    public function setUp()
    {
        parent::setUp();

        $resolver = M::mock('Illuminate\Database\ConnectionResolverInterface');
        $resolver->shouldReceive('connection')->andReturn($this->getConnectionWithConfig('default'));
        User::setConnectionResolver($resolver);

        // Setup the data in the database
        $this->ab = User::create([
            'name' => 'Ey Bee',
            'alias' => 'ab',
            'email' => 'ab@alpha.bet',
            'calls' => 10,
        ]);

        $this->cd = User::create([
            'name' => 'See Dee',
            'alias' => 'cd',
            'email' => 'cd@alpha.bet',
            'calls' => 20,
        ]);

        $this->ef = User::create([
            'name' => 'Eee Eff',
            'alias' => 'ef',
            'email' => 'ef@alpha.bet',
            'calls' => 30,
        ]);

        $this->gh = User::create([
            'name' => 'Gee Aych',
            'alias' => 'gh',
            'email' => 'gh@alpha.bet',
            'calls' => 40,
        ]);

        $this->ij = User::create([
            'name' => 'Eye Jay',
            'alias' => 'ij',
            'email' => 'ij@alpha.bet',
            'calls' => 50,
        ]);
    }

    public function testWhereIdWithNoOperator()
    {
        $u = User::where('id', $this->ab->id)->first();

        $this->assertEquals($this->ab->toArray(), $u->toArray());
    }

    public function testWhereIdSelectingProperties()
    {
        $u = User::where('id', $this->ab->id)->first(['id', 'name', 'email']);

        $this->assertEquals($this->ab->id, $u->id);
        $this->assertEquals($this->ab->name, $u->name);
        $this->assertEquals($this->ab->email, $u->email);
    }

    public function testWhereIdWithEqualsOperator()
    {
        $u = User::where('id', '=', $this->cd->id)->first();

        $this->assertEquals($this->cd->toArray(), $u->toArray());
    }

    public function testWherePropertyWithoutOperator()
    {
        $u = User::where('alias', 'ab')->first();

        $this->assertEquals($this->ab->toArray(), $u->toArray());
    }

    public function testWherePropertyEqualsOperator()
    {
        $u = User::where('alias', '=', 'ab')->first();

        $this->assertEquals($this->ab->toArray(), $u->toArray());
    }

    public function testWhereGreaterThanOperator()
    {
        $others = User::where('calls', '>', 10)->get();
        $this->assertCount(4, $others);

        $brothers = new Collection(array(
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));
        $this->assertTrue($others->diff($brothers)->isEmpty());

        $lastTwo = User::where('calls', '>=', 40)->get();
        $this->assertCount(2, $lastTwo);

        $mothers = new Collection(array($this->gh, $this->ij));
        $this->assertTrue($lastTwo->diff($mothers)->isEmpty());

        $none = User::where('calls', '>', 9000)->get();
        $this->assertCount(0, $none);
    }

    public function testWhereLessThanOperator()
    {
        $u = User::where('calls', '<', 10)->get();
        $this->assertCount(0, $u);

        $three = User::where('calls', '<=', 30)->get();
        $this->assertCount(3, $three);

        $cocoa = new Collection(array($this->ab,
                                                            $this->cd,
                                                            $this->ef, ));
        $this->assertTrue($cocoa->diff($three)->isEmpty());

        $below = User::where('calls', '<', -100)->get();
        $this->assertCount(0, $below);

        $nil = User::where('calls', '<=', 0)->first();
        $this->assertNull($nil);
    }

    public function testWhereDifferentThanOperator()
    {
        $notab = User::where('alias', '<>', 'ab')->get();

        $dudes = new Collection(array(
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));

        $this->assertCount(4, $notab);
        $this->assertTrue($notab->diff($dudes)->isEmpty());
    }

    public function testWhereIn()
    {
        $alpha = User::whereIn('alias', ['ab', 'cd', 'ef', 'gh', 'ij'])->get();

        $crocodile = new Collection(array($this->ab,
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));

        $this->assertTrue($alpha->diff($crocodile)->isEmpty());
    }

    public function testWhereNotNull()
    {
        $alpha = User::whereNotNull('alias')->get();

        $crocodile = new Collection(array($this->ab,
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));
        $this->assertTrue($alpha->diff($crocodile)->isEmpty());
    }

    public function testWhereNull()
    {
        $u = User::whereNull('calls')->get();
        $this->assertCount(0, $u);
    }

    public function testWhereNotIn()
    {
        /*
         * There is no WHERE NOT IN [ids] in Neo4j, it should be something like this:
         *
         * MATCH (actor:Actor {name:"Tom Hanks"} )-[:ACTED_IN]->(movies)<-[:ACTED_IN]-(coactor)
         * WITH collect(distinct coactor) as coactors
         * MATCH (actor:Actor)
         * WHERE actor NOT IN coactors
         * RETURN actor
         */
        $u = User::whereNotIn('alias', ['ab', 'cd', 'ef'])->get();
        $still = new Collection(array($this->gh, $this->ij));
        $rest = [$this->gh->toArray(), $this->ij->toArray()];

        $this->assertCount(2, $u);
        $this->assertEquals($rest, $still->toArray());
    }

    public function testWhereBetween()
    {
        /*
         * There is no WHERE BETWEEN
         */
        $this->markTestIncomplete();

        $u = User::whereBetween('id', [$this->ab->id, $this->ij->id])->get()->pluck("id");

        $mwahaha = new Collection(array($this->ab,
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));
        $mwahaha = $mwahaha->pluck('id');
        $this->assertCount(5, $u);
        $this->assertTrue($buddies->diff($mwahaha)->isEmpty());
    }

    public function testOrWhere()
    {
        $buddies = User::where('name', 'Ey Bee')
            ->orWhere('alias', 'cd')
            ->orWhere('email', 'ef@alpha.bet')
            ->orWhere('id', $this->gh->id)
            ->orWhere('calls', '>', 40)
            ->get();

        $this->assertCount(5, $buddies);
        $bigBrothers = new Collection(array($this->ab,
                                                            $this->cd,
                                                            $this->ef,
                                                            $this->gh,
                                                            $this->ij, ));
        $bigBrothers = $bigBrothers;
        $this->assertTrue($bigBrothers->diff($buddies)->isEmpty());
    }

    public function testOrWhereIn()
    {
        $all = User::whereIn('id', [$this->ab->id, $this->cd->id])
            ->orWhereIn('alias', ['ef', 'gh', 'ij'])->get();

        $padrougas = new Collection(array(
            $this->ab,
            $this->cd,
            $this->ef,
            $this->gh,
            $this->ij
        ));
        $padrougas = $padrougas;
        $this->assertTrue($padrougas->diff($all)->isEmpty());
    }

    public function testWhereNotFound()
    {
        $u = User::where('id', '<', 1)->get();
        $this->assertCount(0, $u);

        $u2 = User::where('glasses', 'always on')->first();
        $this->assertNull($u2);
    }

    /**
     * Regression test for issue #19.
     *
     * @see  https://github.com/Vinelab/NeoEloquent/issues/19
     */
    public function testWhereMultipleValuesForSameColumn()
    {
        $u = User::where('alias', '=', 'ab')->orWhere('alias', '=', 'cd')->get()->pluck('alias');
        $this->assertCount(2, $u);
        $expectedAliases = collect(['ab', 'cd']);
        $this->assertTrue($expectedAliases->diff($u)->isEmpty());
    }

    /**
     * Regression test for issue #41.
     *
     * @see https://github.com/Vinelab/NeoEloquent/issues/41
     */
    public function testWhereWithIn()
    {
        $ab = User::where('alias', 'IN', ['ab'])->first();

        $this->assertEquals($this->ab->toArray(), $ab->toArray());

        $users = User::where('alias', 'IN', ['cd', 'ef'])->get();

        $l = (new User())->getConnection()->getQueryLog();
        $expectedUsers = new Collection(
            array($this->cd, $this->ef)
        );
        $this->assertTrue($expectedUsers->diff($users)->isEmpty());
    }
}
