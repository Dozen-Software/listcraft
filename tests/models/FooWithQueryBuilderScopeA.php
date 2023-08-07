<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as Eloquent;

class FooWithQueryBuilderScopeA extends Eloquent
{
    use \Dozensoftware\Listcraft\Listcraft;

    protected $table ="foo_with_query_builder_scopes";

    /**
     * The fillable array lets laravel know which fields are fillable
     *
     * @var array
     */
    protected $fillable = ['name', 'company'];

    /**
     * The rules array lets us know how to to validate this model
     *
     * @var array
     */
    public $rules = [
        'name' => 'required',
        'company' => 'required'
    ];

    /**
     * __construct method
     *
     * @param array   $attributes - An array of attributes to initialize the model with
     * @param boolean $exists     - Boolean flag to indicate if the model exists or not
     */
    public function __construct($attributes = array(), $exists = false)
    {
        parent::__construct($attributes, $exists);
        $this->initListcraft([
            'scope' => Capsule::table($this->getTable())->where('company', '=', 'ACME')
        ]);
    }

    public static function boot()
    {
        parent::boot();
        static::bootListcraft();
    }
}