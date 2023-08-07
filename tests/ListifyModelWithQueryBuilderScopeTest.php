<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Way\Tests\Assert;

class ListcraftModelWithQueryBuilderScopeTest extends ListcraftBaseTest {

    protected $model = 'FooWithQueryBuilderScopeA';
    protected $modelScopeValue = "company = 'ACME'";

    private $modelB = 'FooWithQueryBuilderScopeB';
    private $modelBScopeValue = "company = 'NOT_ACME'";

    public function setUp()
    {
        parent::setUp();

        //Allows model events to work during testing
        $modelB = $this->modelB;
        $modelB::boot();

        for($i = 1; $i <= 10; $i++)
        {
            $foo = new $this->modelB;
            $foo->name = $this->modelB . $i;
            $foo->company = 'NOT_ACME';
            $foo->save();
        }

        $this->reloadBFoos();
    }

    public function tearDown()
    {
        parent::tearDown();

        $modelB = $this->modelB;
        $modelB::flushEventListeners();
    }

    /**
     * @expectedException \Dozensoftware\Listcraft\Exceptions\InvalidQueryBuilderException
     */
    public function test_passScopeInWithMissingWhere()
    {
        $foo = new $this->model;
        $foo->name = $this->model . "New";
        $foo->setListcraftConfig('scope', Capsule::table('foos')->orderBy('id ASC'));
        $foo->save();
    }

    public function test_changeScopeBeforeUpdate()
    {
        $foo1 = new $this->model;
        $foo1->name = $this->model . "Test1";
        $foo1->company = 'TestCompany1';
        $foo1->setListcraftConfig('scope', Capsule::table('foo_with_query_builder_scopes')->where('company', '=', 'TestCompany1'));
        $foo1->save();

        $foo2 = new $this->model;
        $foo2->name = $this->model . "Test2";
        $foo2->company = 'TestCompany1';
        $foo2->setListcraftConfig('scope', Capsule::table('foo_with_query_builder_scopes')->where('company', '=', 'TestCompany1'));
        $foo2->save();

        Assert::eq(1, $foo1->getListcraftPosition());
        Assert::eq(2, $foo2->getListcraftPosition());

        $foo1->setListcraftConfig('scope', Capsule::table('foo_with_query_builder_scopes')->where('company', '=', 'TestCompany2'));
        $foo1->save();

        Assert::eq(1, $foo1->getListcraftPosition());
        Assert::eq(2, $foo2->getListcraftPosition());
    }

    //The whole point of this is to validate that the secondary model (that shares the table) is not modified when manipulating the primary model. The scope should prevent that, so we validate that the secondary model has not changed after each test.
    protected function childAssertion()
    {
        $this->reloadBFoos();

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
        $this->bfoos = (new $this->modelB)->whereRaw($this->modelBScopeValue)->orderBy('id', "ASC")->get()->all();
    }
}