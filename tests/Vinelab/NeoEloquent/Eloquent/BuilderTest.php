<?php

namespace Vinelab\NeoEloquent\Tests\Eloquent;

use Illuminate\Support\Collection;
use Mockery as M;
use Vinelab\NeoEloquent\Eloquent\Builder;
use Vinelab\NeoEloquent\Tests\TestCase;

class EloquentBuilderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->query = M::mock('Vinelab\NeoEloquent\Query\Builder');
        $this->query->shouldReceive('modelAsNode')->andReturn('n');
        $this->model = M::mock('Vinelab\NeoEloquent\Eloquent\Model');

        $this->builder = new Builder($this->query);
    }

    public function tearDown(): void
    {
        M::close();

        parent::tearDown();
    }

    public function testFindMethod()
    {
        $builder = M::mock('Vinelab\NeoEloquent\Eloquent\Builder[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($this->getMockModel());
        $builder->getQuery()->shouldReceive('where')->once()->with('foo', '=', 'bar');
        $builder->shouldReceive('first')->with(['column'])->andReturn('baz');

        $result = $builder->find('bar', ['column']);
        $this->assertEquals('baz', $result);
    }

    public function testFindOrFailMethodThrowsModelNotFoundException()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($this->getMockModel());
        $builder->getQuery()->shouldReceive('where')->once()->with('foo', '=', 'bar');
        $builder->shouldReceive('first')->with(['column'])->andReturn(null);
        $result = $builder->findOrFail('bar', ['column']);
    }

    public function testFindOrFailMethodWithManyThrowsModelNotFoundException()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[get]', [$this->getMockQueryBuilder()]);
        $builder->setModel($this->getMockModel());
        $builder->getQuery()->shouldReceive('whereIn')->once()->with('foo', [1, 2]);
        $builder->shouldReceive('get')->with(['column'])->andReturn(new Collection([1]));
        $result = $builder->findOrFail([1, 2], ['column']);
    }

    public function testFirstOrFailMethodThrowsModelNotFoundException()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($this->getMockModel());
        $builder->shouldReceive('first')->with(['column'])->andReturn(null);
        $result = $builder->firstOrFail(['column']);
    }

    public function testFindWithMany()
    {
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->shouldReceive('whereIn')->once()->with('foo', [1, 2]);
        $builder->setModel($this->getMockModel());
        $builder->shouldReceive('get')->with(['column'])->andReturn('baz');

        $result = $builder->find([1, 2], ['column']);
        $this->assertEquals('baz', $result);
    }

    public function testFirstMethod()
    {
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[get,take]', [$this->getMockQueryBuilder()]);
        $builder->shouldReceive('take')->with(1)->andReturn($builder);
        $builder->shouldReceive('get')->with(['*'])->andReturn(new Collection(['bar']));

        $result = $builder->first();
        $this->assertEquals('bar', $result);
    }

    public function testGetMethodLoadsModelsAndHydratesEagerRelations()
    {
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[getModels,eagerLoadRelations]', [$this->getMockQueryBuilder()]);
        $builder->shouldReceive('getModels')->with(['foo'])->andReturn(['bar']);
        $builder->shouldReceive('eagerLoadRelations')->with(['bar'])->andReturn(['bar', 'baz']);
        $builder->setModel($this->getMockModel());
        $builder->getModel()->shouldReceive('newCollection')->with(['bar', 'baz'])->andReturn(new Collection(['bar', 'baz']));

        $results = $builder->get(['foo']);
        $this->assertEquals(['bar', 'baz'], $results->all());
    }

    public function testGetMethodDoesntHydrateEagerRelationsWhenNoResultsAreReturned()
    {
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[getModels,eagerLoadRelations]', [$this->getMockQueryBuilder()]);
        $builder->shouldReceive('getModels')->with(['foo'])->andReturn([]);
        $builder->shouldReceive('eagerLoadRelations')->never();
        $builder->setModel($this->getMockModel());
        $builder->getModel()->shouldReceive('newCollection')->with([])->andReturn(new Collection([]));

        $results = $builder->get(['foo']);
        $this->assertEquals([], $results->all());
    }

    public function testPluckMethodWithModelFound()
    {
        $queryBuilder = $this->getMockQueryBuilder();
        $queryBuilder->shouldReceive('from');
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[first]', [$queryBuilder]);
        $mockModel = m::mock('Illuminate\Database\Eloquent\Model')->makePartial();
        $mockModel->name = 'foo';
        $builder->shouldReceive('first')->with(['name'])->andReturn($mockModel);
        $builder->getQuery()->shouldReceive('pluck')->with('name', '')->andReturn(new Collection(['bar', 'baz']));
        $builder->setModel($mockModel);
        $builder->getModel()->shouldReceive('hasGetMutator')->with('name')->andReturn(true);
        $builder->getModel()->shouldReceive('newFromBuilder')->with(['name' => 'bar'])->andReturn(new EloquentBuilderTestPluckStub(['name' => 'bar']));
        $builder->getModel()->shouldReceive('newFromBuilder')->with(['name' => 'baz'])->andReturn(new EloquentBuilderTestPluckStub(['name' => 'baz']));

        $this->assertEquals(['foo_bar', 'foo_baz'], $builder->pluck('name')->all());
    }

    public function testPluckMethodWithModelNotFound()
    {
        $queryBuilder = $this->getMockQueryBuilder();
        $queryBuilder->shouldReceive('from');

        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[first]', [$queryBuilder]);
        $builder->shouldReceive('first')->with(['name'])->andReturn(null);
        $builder->getQuery()->shouldReceive('pluck')->with('name', '')->andReturn(null);
        $mockModel = m::mock('Illuminate\Database\Eloquent\Model')->makePartial();
        $builder->setModel($mockModel);
        $builder->getModel()->shouldReceive('hasGetMutator')->with('name')->andReturn(false);

        $this->assertNull($builder->pluck('name'));
    }

    public function testChunkExecuteCallbackOverPaginatedRequest()
    {
        $this->markTestIncomplete('Getting error: BadMethodCallException: Method Mockery_1_Vinelab_NeoEloquent_Query_Builder::orderBy() does not exist on this mock object');
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[forPage,get]', [$this->getMockQueryBuilder()]);
        $builder->shouldReceive('forPage')->once()->with(1, 2)->andReturn($builder);
        $builder->shouldReceive('forPage')->once()->with(2, 2)->andReturn($builder);
        $builder->shouldReceive('forPage')->once()->with(3, 2)->andReturn($builder);
        $builder->shouldReceive('get')->times(3)->andReturn(['foo1', 'foo2'], ['foo3'], []);
        $callbackExecutionAssertor = m::mock('StdClass');
        $callbackExecutionAssertor->shouldReceive('doSomething')->with('foo1')->once();
        $callbackExecutionAssertor->shouldReceive('doSomething')->with('foo2')->once();
        $callbackExecutionAssertor->shouldReceive('doSomething')->with('foo3')->once();
        $builder->setModel($this->getMockModel());
        $builder->chunk(2, function ($results) use ($callbackExecutionAssertor) {
            foreach ($results as $result) {
                $callbackExecutionAssertor->doSomething($result);
            }
        });
    }

    public function testGetModelsProperlyHydratesModels()
    {
        $query = $this->getMockQueryBuilder();
        $query->columns = ['n.name', 'n.age'];

        $builder = M::mock('Vinelab\NeoEloquent\Eloquent\Builder[get]', [$query]);

        $records[] = ['id' => 1902, 'name' => 'taylor', 'age' => 26];
        $records[] = ['id' => 6252, 'name' => 'dayle', 'age' => 28];

        $resultSet = $this->createNodeResultSet($records, ['n.name', 'n.age']);

        $builder->getQuery()->shouldReceive('get')->once()->with(['foo'])->andReturn($resultSet);
        $grammar = M::mock('Vinelab\NeoEloquent\Query\Grammars\CypherGrammar')->makePartial();
        $builder->getQuery()->shouldReceive('getGrammar')->andReturn($grammar);

        $model = M::mock('Vinelab\NeoEloquent\Eloquent\Model[getTable,getConnectionName,newInstance]');
        $model->shouldReceive('getTable')->once()->andReturn('foo_table');

        $builder->setModel($model);

        $model->shouldReceive('getConnectionName')->times(3)->andReturn('foo_connection');
        $model->shouldReceive('newInstance')->andReturnUsing(function () {
            return new EloquentBuilderTestModelStub();
        });
        $models = $builder->getModels(['foo']);

        $this->assertEquals('taylor', $models[0]->name);
        $this->assertEquals($models[0]->getAttributes(), $models[0]->getOriginal());
        $this->assertEquals('dayle', $models[1]->name);
        $this->assertEquals($models[1]->getAttributes(), $models[1]->getOriginal());
        $this->assertEquals('foo_connection', $models[0]->getConnectionName());
        $this->assertEquals('foo_connection', $models[1]->getConnectionName());
    }

    public function testEagerLoadRelationsLoadTopLevelRelationships()
    {
        $builder = m::mock('Vinelab\NeoEloquent\Eloquent\Builder[eagerLoadRelation]', [$this->getMockQueryBuilder()]);
        $nop1 = function () {
        };
        $nop2 = function () {
        };
        $builder->setEagerLoads(['foo' => $nop1, 'foo.bar' => $nop2]);
        $builder->shouldAllowMockingProtectedMethods()->shouldReceive('eagerLoadRelation')->with(['models'], 'foo', $nop1)->andReturn(['foo']);

        $results = $builder->eagerLoadRelations(['models']);
        $this->assertEquals(['foo'], $results);
    }

    public function testGetRelationProperlySetsNestedRelationships()
    {
        $builder = $this->getBuilder();
        $builder->setModel($this->getMockModel());
        $builder->getModel()->shouldReceive('newInstance->orders')->once()->andReturn($relation = m::mock('stdClass'));
        $relationQuery = m::mock('stdClass');
        $relation->shouldReceive('getQuery')->andReturn($relationQuery);
        $relationQuery->shouldReceive('with')->once()->with(['lines' => null, 'lines.details' => null]);
        $builder->setEagerLoads(['orders' => null, 'orders.lines' => null, 'orders.lines.details' => null]);

        $relation = $builder->getRelation('orders');
    }

    public function testGetRelationProperlySetsNestedRelationshipsWithSimilarNames()
    {
        $builder = $this->getBuilder();
        $builder->setModel($this->getMockModel());
        $builder->getModel()->shouldReceive('newInstance->orders')->once()->andReturn($relation = m::mock('stdClass'));
        $builder->getModel()->shouldReceive('newInstance->ordersGroups')->once()->andReturn($groupsRelation = m::mock('stdClass'));

        $relationQuery = m::mock('stdClass');
        $relation->shouldReceive('getQuery')->andReturn($relationQuery);

        $groupRelationQuery = m::mock('stdClass');
        $groupsRelation->shouldReceive('getQuery')->andReturn($groupRelationQuery);
        $groupRelationQuery->shouldReceive('with')->once()->with(['lines' => null, 'lines.details' => null]);

        $builder->setEagerLoads(['orders' => null, 'ordersGroups' => null, 'ordersGroups.lines' => null, 'ordersGroups.lines.details' => null]);

        $relation = $builder->getRelation('orders');
        $relation = $builder->getRelation('ordersGroups');
    }

    public function testEagerLoadParsingSetsProperRelationships()
    {
        $builder = $this->getBuilder();
        $builder->with(['orders', 'orders.lines']);
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf('Closure', $eagers['orders']);
        $this->assertInstanceOf('Closure', $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with('orders', 'orders.lines');
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf('Closure', $eagers['orders']);
        $this->assertInstanceOf('Closure', $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with(['orders.lines']);
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf('Closure', $eagers['orders']);
        $this->assertInstanceOf('Closure', $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with(['orders' => function () {
            return 'foo';
        }]);
        $eagers = $builder->getEagerLoads();

        $this->assertEquals('foo', $eagers['orders']());

        $builder = $this->getBuilder();
        $builder->with(['orders.lines' => function () {
            return 'foo';
        }]);
        $eagers = $builder->getEagerLoads();

        $this->assertInstanceOf('Closure', $eagers['orders']);
        $this->assertNull($eagers['orders']());
        $this->assertEquals('foo', $eagers['orders.lines']());
    }

    public function testQueryPassThru()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('foobar')->once()->andReturn('foo');

        $this->assertInstanceOf('Vinelab\NeoEloquent\Eloquent\Builder', $builder->foobar());

        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('insert')->once()->with(['bar'])->andReturn('foo');

        $this->assertEquals('foo', $builder->insert(['bar']));
    }

    public function testQueryScopes()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->getQuery()->shouldReceive('where')->once()->with('foo', 'bar');
        $builder->setModel($model = new EloquentBuilderTestScopeStub());
        $result = $builder->approved();

        $this->assertEquals($builder, $result);
    }

    public function testSimpleWhere()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('where')->once()->with('foo', '=', 'bar');
        $result = $builder->where('foo', '=', 'bar');
        $this->assertEquals($result, $builder);
    }

    public function testNestedWhere()
    {
        $this->markTestIncomplete('Getting error: Static method Mockery_1_Vinelab_NeoEloquent_Eloquent_Model::resolveConnection() does not exist on this mock object');

        $nestedQuery = m::mock('Vinelab\NeoEloquent\Eloquent\Builder');
        $nestedRawQuery = $this->getMockQueryBuilder();
        $nestedQuery->shouldReceive('getQuery')->once()->andReturn($nestedRawQuery);
        $model = $this->getMockModel()->makePartial();
        $model->shouldReceive('newQueryWithoutScopes')->once()->andReturn($nestedQuery);
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->setModel($model);
        $builder->getQuery()->shouldReceive('addNestedWhereQuery')->once()->with($nestedRawQuery, 'and');
        $nestedQuery->shouldReceive('foo')->once();

        $result = $builder->where(function ($query) {
            $query->foo();
        });
        $this->assertEquals($builder, $result);
    }

    public function testDeleteOverride()
    {
        $this->markTestIncomplete('Getting the error BadMethodCallException: Method Mockery_2_Vinelab_NeoEloquent_Query_Builder::onDelete() does not exist on this mock object');
        $builder = $this->getBuilder();
        $builder->onDelete(function ($builder) {
            return ['foo' => $builder];
        });
        $this->assertEquals(['foo' => $builder], $builder->delete());
    }

    public function testFindingById()
    {
        $resultSet = M::mock('Everyman\Neo4j\Query\ResultSet');
        $resultSet->shouldReceive('getColumns')->withNoArgs()->andReturn(['id', 'name', 'age']);

        $this->query->shouldReceive('where')->once()->with('id(n)', '=', 1);
        $this->query->shouldReceive('from')->once()->with('Model')->andReturn(['Model']);
        $this->query->shouldReceive('take')->once()->with(1)->andReturn($this->query);
        $this->query->shouldReceive('get')->once()->with(['*'])->andReturn($resultSet);

        $resultSet->shouldReceive('valid')->once()->andReturn(false);

        $this->model->shouldReceive('getKeyName')->twice()->andReturn('id');
        $this->model->shouldReceive('getTable')->once()->andReturn('Model');
        $this->model->shouldReceive('getConnectionName')->once()->andReturn('default');
        $this->model->shouldReceive('hasNamedScope')->once()->andReturn(false);

        $collection = new \Illuminate\Support\Collection([M::mock('Everyman\Neo4j\Query\ResultSet')]);
        $this->model->shouldReceive('newCollection')->once()->andReturn($collection);

        $this->builder->setModel($this->model);

        $result = $this->builder->find(1);

        $this->assertInstanceOf('Everyman\Neo4j\Query\ResultSet', $result);
    }

    public function testFindingByIdWithProperties()
    {
        // the intended Node id
        $id = 6;

        // the expected result set
        $result = [

            'id'    => $id,
            'name'  => 'Some Name',
            'email' => 'some@mail.net',
        ];

        // the properties that we need returned of our model
        $properties = ['id(n)', 'n.name', 'n.email', 'n.somthing'];

        $resultSet = $this->createNodeResultSet($result, $properties);

        // usual query expectations
        $this->query->shouldReceive('where')->once()->with('id(n)', '=', $id)
                    ->shouldReceive('take')->once()->with(1)->andReturn($this->query)
                    ->shouldReceive('get')->once()->with($properties)->andReturn($resultSet)
                    ->shouldReceive('from')->once()->with('Model')
                        ->andReturn(['Model']);

        // our User object that we expect to have returned
        $user = M::mock('User');
        $user->shouldReceive('setConnection')->once()->with('default');

        // model method calls expectations
        $attributes = array_merge($result, ['id' => $id]);

        // the Collection that represents the returned result by Eloquent holding the User as an item
        $collection = new \Illuminate\Support\Collection([$user]);

        $this->model->shouldReceive('newCollection')->once()->andReturn($collection)
                    ->shouldReceive('getKeyName')->twice()->andReturn('id')
                    ->shouldReceive('getTable')->once()->andReturn('Model')
                    ->shouldReceive('hasNamedScope')->once()->andReturn(false)
                    ->shouldReceive('getConnectionName')->once()->andReturn('default')
                    ->shouldReceive('newFromBuilder')->once()->with($attributes)->andReturn($user);

        // assign the builder's $model to our mock
        $this->builder->setModel($this->model);
        $grammar = M::mock('Vinelab\NeoEloquent\Query\Grammars\CypherGrammar')->makePartial();
        $this->query->shouldReceive('getGrammar')->andReturn($grammar);
        // put things to the test
        $found = $this->builder->find($id, $properties);

        $this->assertInstanceOf('User', $found);
    }

    public function testGettingModels()
    {
        // the expected result set
        $results = [

            [

                'id'    => 10,
                'name'  => 'Some Name',
                'email' => 'some@mail.net',
            ],

            [

                'id'    => 11,
                'name'  => 'Another Person',
                'email' => 'person@diff.io',
            ],

        ];

        $resultSet = $this->createNodeResultSet($results);

        $grammar = M::mock('Vinelab\NeoEloquent\Query\Grammars\CypherGrammar')->makePartial();
        $this->query->shouldReceive('get')->once()->with(['*'])->andReturn($resultSet)
                    ->shouldReceive('from')->once()->andReturn('User')
                    ->shouldReceive('getGrammar')->andReturn($grammar);

        // our User object that we expect to have returned
        $user = M::mock('User');
        $user->shouldReceive('setConnection')->twice()->with('default');

        $this->model->shouldReceive('getTable')->once()->andReturn('User')
                    ->shouldReceive('getConnectionName')->once()->andReturn('default')
                    ->shouldReceive('newFromBuilder')->once()
                        ->with($results[0])->andReturn($user)
                    ->shouldReceive('newFromBuilder')->once()
                        ->with($results[1])->andReturn($user);

        $this->builder->setModel($this->model);

        $models = $this->builder->getModels();

        $this->assertIsArray($models);
        $this->assertInstanceOf('User', $models[0]);
        $this->assertInstanceOf('User', $models[1]);
    }

    public function testGettingModelsWithProperties()
    {
        // the expected result set
        $results = [
            'id'    => 138,
            'name'  => 'Nicolas Jaar',
            'email' => 'noise@space.see',
        ];

        $properties = ['id', 'name'];

        $resultSet = $this->createNodeResultSet($results);

        $grammar = M::mock('Vinelab\NeoEloquent\Query\Grammars\CypherGrammar')->makePartial();
        $this->query->shouldReceive('get')->once()->with($properties)->andReturn($resultSet)
                    ->shouldReceive('from')->once()->andReturn('User')
                    ->shouldReceive('getGrammar')->andReturn($grammar);

        // our User object that we expect to have returned
        $user = M::mock('User');
        $user->shouldReceive('setConnection')->once()->with('default');

        $this->model->shouldReceive('getTable')->once()->andReturn('User')
                    ->shouldReceive('getConnectionName')->once()->andReturn('default')
                    ->shouldReceive('newFromBuilder')->once()
                        ->with($results)->andReturn($user);

        $this->builder->setModel($this->model);

        $models = $this->builder->getModels($properties);

        $this->assertIsArray($models);
        $this->assertInstanceOf('User', $models[0]);
    }

    public function testExtractingPropertiesFromNode()
    {
        $properties = [
            'id'         => 911,
            'skin'       => 'white',
            'username'   => 'eminem',
            'occupation' => 'white nigga',
        ];

        $row = $this->createRowWithNodeAtIndex(0, $properties);
        $row->shouldReceive('current')->once()->andReturn($row->offsetGet(0));

        $this->model->shouldReceive('getTable')->once()->andReturn('Artist');

        $this->query->shouldReceive('from')->once()->andReturn('Artist');

        $this->builder->setModel($this->model);

        $columns = array_map(function ($property) {
            return 'n.'.$property;
        }, array_keys($properties));

        $attributes = $this->builder->getProperties($columns, $row);

        $this->assertEquals($properties, $attributes);
    }

    public function testExtractingPropertiesOfChosenColumns()
    {
        $properties = [
            'id'    => 'mothafucka',
            'arms'  => 2,
            'legs'  => 2,
            'heads' => 1,
            'eyes'  => 2,
            'sex'   => 'male',
        ];

        $row = $this->createRowWithPropertiesAtIndex(0, $properties);
        $row->shouldReceive('current')->once()->andReturn($row->offsetGet(0));

        $this->model->shouldReceive('getTable')->once()->andReturn('Human:Male');

        $this->query->columns = ['arms', 'legs'];
        $this->query->shouldReceive('from')->once()->andReturn('Human:Male');

        $this->builder->setModel($this->model);

        $attributes = $this->builder->getProperties(['arms', 'legs'], $row, ['arms', 'legs']);

        $expected = ['arms' => $properties['arms'], 'legs' => $properties['legs']];

        $this->assertEquals($expected, $attributes);
    }

    public function testCheckingIsRelationship()
    {
        $this->assertTrue($this->builder->isRelationship(['user', 'account']));
        $this->assertFalse($this->builder->isRelationship(['user.name', 'account.id']));
        $this->assertFalse($this->builder->isRelationship(['user', 'user.name', 'account.id']));
    }

    /**
     *     Utility methods down below this area.
     */

    /**
     * Create a new ResultSet out of an array of properties and values.
     *
     * @param array $data       The values you want returned could be of the form
     *                          [ [name => something, username => here] ]
     *                          or specify the attributes straight in the array
     * @param array $properties The expected properties (columns)
     *
     * @return \Everyman\Neo4j\Query\ResultSet
     */
    public function createNodeResultSet($data = [], $properties = [])
    {
        $c = $this->getConnectionWithConfig('default');

        $rows = [];

        if (is_array(reset($data))) {
            foreach ($data as $index => $node) {
                $rows[] = $this->createRowWithNodeAtIndex($index, $node);
            }
        } else {
            $rows[] = $this->createRowWithNodeAtIndex(0, $data);
        }

        // the ResultSet $result part
        $result = [
            'data'    => $rows,
            'columns' => $properties,
        ];

        // create the result set
        return new \Everyman\Neo4j\Query\ResultSet($c->getClient(), $result);
    }

    /**
     * Get a row with a Node inside of it having $data as properties.
     *
     * @param int   $index The index of the node in the row
     * @param array $data
     *
     * @return \Everyman\Neo4j\Query\Row
     */
    public function createRowWithNodeAtIndex($index, array $data)
    {
        // create the result Node containing the properties and their values
        $node = M::mock('Everyman\Neo4j\Node');

        // the Node id is never returned with the properties so in case
        // that is one of the data properties we need to remove it
        // and add it to when requested through getId()
        if (isset($data['id'])) {
            $node->shouldReceive('getId')->once()->andReturn($data['id']);

            unset($data['id']);
        }

        $node->shouldReceive('getProperties')->once()->andReturn($data);

        // create the result row that should contain the Node
        $row = M::mock('Everyman\Neo4j\Query\Row');
        $row->shouldReceive('offsetGet')->andReturn($node);

        return $row;
    }

    public function createRowWithPropertiesAtIndex($index, array $properties)
    {
        $row = M::mock('Everyman\Neo4j\Query\Row');
        // $row->shouldReceive('offsetGet')->with($index)->andReturn($properties);

        foreach ($properties as $key => $value) {
            // prepare the row's offsetGet to rerturn the desired value when asked
            // by prepending the key with an n. representing the node in the Cypher query.
            $row->shouldReceive('offsetGet')
                ->with("n.{$key}")
                ->andReturn($properties[$key]);

            $row->shouldReceive('offsetGet')
                ->with("{$key}")
                ->andReturn($properties[$key]);
        }

        return $row;
    }

    protected function getMockModel()
    {
        $model = m::mock('Vinelab\NeoEloquent\Eloquent\Model');
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo');

        return $model;
    }

    protected function getMockQueryBuilder()
    {
        $query = m::mock('Vinelab\NeoEloquent\Query\Builder');
        $query->shouldReceive('from')->with('foo_table');
        $query->shouldReceive('modelAsNode')->andReturn('n');

        return $query;
    }

    protected function getBuilder()
    {
        $query = M::mock('Vinelab\NeoEloquent\Query\Builder');
        $query->shouldReceive('from')->andReturn('foo_table');
        $query->shouldReceive('modelAsNode')->andReturn('n');

        return new Builder($query);
    }
}

// Don't ask what this is, brought in from
// laravel/framework/tests/Databases/DatabaseEloquentBuilderTest.php
// and it makes the tests pass, so leave it :P
class EloquentBuilderTestModelStub extends \Vinelab\NeoEloquent\Eloquent\Model
{
}

class EloquentBuilderTestScopeStub extends \Vinelab\NeoEloquent\Eloquent\Model
{
    public function scopeApproved($query)
    {
        $query->where('foo', 'bar');
    }
}

class EloquentBuilderTestListsStub
{
    protected $attributes;

    public function __construct($attributes)
    {
        $this->attributes = $attributes;
    }

    public function __get($key)
    {
        return 'foo_'.$this->attributes[$key];
    }
}

class EloquentBuilderTestPluckStub
{
    protected $attributes;

    public function __construct($attributes)
    {
        $this->attributes = $attributes;
    }

    public function __get($key)
    {
        return 'foo_'.$this->attributes[$key];
    }
}
