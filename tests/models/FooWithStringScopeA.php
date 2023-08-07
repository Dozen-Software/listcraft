<?php

use Illuminate\Database\Eloquent\Model as Eloquent;

class FooWithStringScopeA extends Eloquent
{
    use \Dozensoftware\Listcraft\Listcraft;

    protected $table ="foo_with_string_scopes";

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
            'scope' => "company = 'companyA'"
        ]);
    }

    public static function boot()
    {
        parent::boot();
        static::bootListcraft();
    }
}