<?php

use Way\Tests\Assert;

class ListcraftModelWithBelongstoScopeTest extends ListcraftBaseTest {

    protected $model = 'FooWithBelongstoScopeA';
    protected $modelScopeValue = "foo_with_belongsto_scope_b_id = 1";

    private $modelB = 'FooWithBelongstoScopeB';
    private $modelBScopeValue = "foo_with_belongsto_scope_b_id = 99";

    private $foreignKeyId;

    public function setUp()
    {
        //This is the record that model A will belong to in order to test the scope
        $foo = new $this->modelB;
        $foo->name = 'BelongsToExample';
        $foo->save();

        $this->foreignKeyId = $foo->id;

        $this->belongsToFunction = 'foo_with_belongsto_scope_b';
        $this->belongsToObject = $foo;

        parent::setUp();

        //Now we setup the secondary records which will be out of scope and should remain unchanged throughout modification

        for($i = 1; $i <= 10; $i++)
        {
            $foo = new $this->model;
            $foo->name = $this->model . '-test-' . $i;
            $foo->foo_with_belongsto_scope_b_id = 99;
            $foo->save();
        }
    }

    /**
     * @expectedException \Dozensoftware\Listcraft\Exceptions\NullScopeException
     */
    public function test_passInNullScopeThrowsError()
    {
        $model = $this->model;
        $foo = new $model();
        $foo->name = "FooHasNullScope";
        $foo->setListcraftConfig('scope', NULL);
        $foo->save();
    }

    /**
     * @expectedException \Dozensoftware\Listcraft\Exceptions\NullForeignKeyException
     */
    public function test_passInNullScopeWithForeignKeyThrowsError()
    {
        $model = $this->model;
        $foo = new $model();
        $foo->name = "FooHasNoForeignKey";
        $foo->save();
    }

    public function test_changeScopeBeforeUpdate()
    {
        $foo1 = new $this->model;
        $foo1->name = $this->model . "Test1";
        $foo1->foo_with_belongsto_scope_b_id = 19;
        $foo1->save();

        $foo2 = new $this->model;
        $foo2->name = $this->model . "Test2";
        $foo2->foo_with_belongsto_scope_b_id = 19;
        $foo2->save();

        Assert::eq(1, $foo1->getListcraftPosition());
        Assert::eq(2, $foo2->getListcraftPosition());

        $foo1->foo_with_belongsto_scope_b_id = 20;
        $foo1->save();

        Assert::eq(1, $foo1->getListcraftPosition());
        Assert::eq(2, $foo2->getListcraftPosition());
    }

    //The whole point of this is to validate that the secondary model (that shares the table) is not modified when manipulating the primary model. The scope should prevent that, so we validate that the secondary model has not changed after each test.
    protected function childAssertion()
    {
        $this->reloadFoos();
        $this->reloadBFoos();

        $position = 1;
        foreach($this->foos as $foo)
        {
            Assert::eq($this->foreignKeyId, $foo->foo_with_belongsto_scope_b_id);
            $position++;
        }

        $position = 1;
        foreach($this->bfoos as $bfoo)
        {
            Assert::eq($position, $bfoo->getListcraftPosition());
            $position++;
        }
    }

    protected function reloadFoos()
    {
        $this->foos = (new $this->model)->whereRaw($this->modelScopeValue)->orderBy('id', "ASC")->get()->all();
    }

    private function reloadBFoos()
    {
        $this->bfoos = (new $this->model)->whereRaw($this->modelBScopeValue)->orderBy('id', "ASC")->get()->all();
    }
}