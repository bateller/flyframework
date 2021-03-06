<?php

use Mockery as m;
use Fly\Database\ActiveRecord\Relations\MorphOne;
use Fly\Database\ActiveRecord\Relations\MorphMany;

class DatabaseActiveRecordMorphTest extends PHPUnit_Framework_TestCase {

	public function tearDown()
	{
		m::close();
	}


	public function testMorphOneSetsProperConstraints()
	{
		$relation = $this->getOneRelation();
	}


	public function testMorphOneEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getOneRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('table.morph_id', array(1, 2));
		$relation->getQuery()->shouldReceive('where')->once()->with('table.morph_type', get_class($relation->getParent()));

		$model1 = new ActiveRecordMorphResetModelStub;
		$model1->id = 1;
		$model2 = new ActiveRecordMorphResetModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints(array($model1, $model2));
	}


	/**
	 * Note that the tests are the exact same for morph many because the classes share this code...
	 * Will still test to be safe.
	 */
	public function testMorphManySetsProperConstraints()
	{
		$relation = $this->getManyRelation();
	}


	public function testMorphManyEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getManyRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('table.morph_id', array(1, 2));
		$relation->getQuery()->shouldReceive('where')->once()->with('table.morph_type', get_class($relation->getParent()));

		$model1 = new ActiveRecordMorphResetModelStub;
		$model1->id = 1;
		$model2 = new ActiveRecordMorphResetModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints(array($model1, $model2));
	}


	public function testCreateFunctionOnMorph()
	{
		// Doesn't matter which relation type we use since they share the code...
		$relation = $this->getOneRelation();
		$created = m::mock('stdClass');
		$relation->getRelated()->shouldReceive('newInstance')->once()->with(array('name' => 'allan', 'morph_id' => 1, 'morph_type' => get_class($relation->getParent())))->andReturn($created);
		$created->shouldReceive('save')->once()->andReturn(true);

		$this->assertEquals($created, $relation->create(array('name' => 'allan')));
	}


	protected function getOneRelation()
	{
		$builder = m::mock('Fly\Database\ActiveRecord\Builder');
		$builder->shouldReceive('where')->once()->with('table.morph_id', '=', 1);
		$related = m::mock('Fly\Database\ActiveRecord\Model');
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = m::mock('Fly\Database\ActiveRecord\Model');
		$parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$builder->shouldReceive('where')->once()->with('table.morph_type', get_class($parent));
		return new MorphOne($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
	}


	protected function getManyRelation()
	{
		$builder = m::mock('Fly\Database\ActiveRecord\Builder');
		$builder->shouldReceive('where')->once()->with('table.morph_id', '=', 1);
		$related = m::mock('Fly\Database\ActiveRecord\Model');
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = m::mock('Fly\Database\ActiveRecord\Model');
		$parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$builder->shouldReceive('where')->once()->with('table.morph_type', get_class($parent));
		return new MorphMany($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
	}

}


class ActiveRecordMorphResetModelStub extends Fly\Database\ActiveRecord\Model {}


class ActiveRecordMorphResetBuilderStub extends Fly\Database\ActiveRecord\Builder {
	public function __construct() { $this->query = new ActiveRecordRelationQueryStub; }
	public function getModel() { return new ActiveRecordMorphResetModelStub; }
	public function isSoftDeleting() { return false; }
}


class ActiveRecordMorphQueryStub extends Fly\Database\Query\Builder {
	public function __construct() {}
}