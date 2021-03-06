<?php

use Mockery as m;
use Fly\Database\ActiveRecord\Collection;
use Fly\Database\ActiveRecord\Relations\BelongsTo;

class DatabaseActiveRecordBelongsToTest extends PHPUnit_Framework_TestCase {

	public function tearDown()
	{
		m::close();
	}


	public function testUpdateMethodRetrievesModelAndUpdates()
	{
		$relation = $this->getRelation();
		$mock = m::mock('Fly\Database\ActiveRecord\Model');
		$mock->shouldReceive('fill')->once()->with(array('attributes'))->andReturn($mock);
		$mock->shouldReceive('save')->once()->andReturn(true);
		$relation->getQuery()->shouldReceive('first')->once()->andReturn($mock);

		$this->assertTrue($relation->update(array('attributes')));
	}


	public function testEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('relation.id', array('foreign.value', 'foreign.value.two'));
		$models = array(new ActiveRecordBelongsToModelStub, new ActiveRecordBelongsToModelStub, new AnotherActiveRecordBelongsToModelStub);
		$relation->addEagerConstraints($models);
	}


	public function testRelationIsProperlyInitialized()
	{
		$relation = $this->getRelation();
		$model = m::mock('Fly\Database\ActiveRecord\Model');
		$model->shouldReceive('setRelation')->once()->with('foo', null);
		$models = $relation->initRelation(array($model), 'foo');

		$this->assertEquals(array($model), $models);
	}


	public function testModelsAreProperlyMatchedToParents()
	{
		$relation = $this->getRelation();
		$result1 = m::mock('stdClass');
		$result1->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$result2 = m::mock('stdClass');
		$result2->shouldReceive('getAttribute')->with('id')->andReturn(2);
		$model1 = new ActiveRecordBelongsToModelStub;
		$model1->foreign_key = 1;
		$model2 = new ActiveRecordBelongsToModelStub;
		$model2->foreign_key = 2;
		$models = $relation->match(array($model1, $model2), new Collection(array($result1, $result2)), 'foo');

		$this->assertEquals(1, $models[0]->foo->getAttribute('id'));
		$this->assertEquals(2, $models[1]->foo->getAttribute('id'));
	}


	public function testAssociateMethodSetsForeignKeyOnModel()
	{
		$parent = m::mock('Fly\Database\ActiveRecord\Model');
		$parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
		$relation = $this->getRelation($parent);
		$associate = m::mock('Fly\Database\ActiveRecord\Model');
		$associate->shouldReceive('getAttribute')->once()->with('id')->andReturn(1);
		$parent->shouldReceive('setAttribute')->once()->with('foreign_key', 1);
		$parent->shouldReceive('setRelation')->once()->with('relation', $associate);

		$relation->associate($associate);
	}


	protected function getRelation($parent = null)
	{
		$builder = m::mock('Fly\Database\ActiveRecord\Builder');
		$builder->shouldReceive('where')->with('relation.id', '=', 'foreign.value');
		$related = m::mock('Fly\Database\ActiveRecord\Model');
		$related->shouldReceive('getKeyName')->andReturn('id');
		$related->shouldReceive('getTable')->andReturn('relation');
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = $parent ?: new ActiveRecordBelongsToModelStub;
		return new BelongsTo($builder, $parent, 'foreign_key', 'id', 'relation');
	}

}

class ActiveRecordBelongsToModelStub extends Fly\Database\ActiveRecord\Model {

	public $foreign_key = 'foreign.value';

}

class AnotherActiveRecordBelongsToModelStub extends Fly\Database\ActiveRecord\Model {

	public $foreign_key = 'foreign.value.two';

}