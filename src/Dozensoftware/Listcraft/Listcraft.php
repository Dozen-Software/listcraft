<?php namespace Dozensoftware\Listcraft;

use DB, Event, Config, App;
use Dozensoftware\Listcraft\Exceptions\ListcraftException;
use Dozensoftware\Listcraft\Exceptions\NullForeignKeyException;
use Dozensoftware\Listcraft\Exceptions\NullScopeException;
use Dozensoftware\Listcraft\Exceptions\InvalidScopeException;
use Dozensoftware\Listcraft\Exceptions\InvalidQueryBuilderException;

/**
 * Gives some nice sorting features to a model.
 * http://dozen-software.github.io/listcraft
 *
 * Extended from from https://github.com/lookitsatravis/listify
 *
 * @package dozen-software/listcraft
 * @version 1.2.2
 * @link
 */

trait Listcraft
{
    /**
     * Array of current config values
     * @var array
     */
    private $listcraftConfig = [
        'top_of_list' => 1,
        'column' => 'position',
        'scope' => '1 = 1',
        'add_new_at' => 'bottom'
    ];

    /**
     * Default scope of the list
     * @var string
     */
    private $defaultScope = '1 = 1';

    /**
     * Contains whether the original attributes are loaded on the model or not
     * @var boolean
     */
    private $originalAttributesLoaded = FALSE;

    /**
     * Container for the changed attributes of the model
     * @var array
     */
    private $swappedAttributes = [];

    /**
     * Contains the current raw scope string. Used to check for changes.
     * @var string
     */
    private $stringScopeValue = NULL;


    // Configuration options are:
    // * +column+ - specifies the column name to use for keeping the position integer (default: +position+)
    // * +scope+ - restricts what is to be considered a list. Given a symbol, it'll attach <tt>_id</tt>
    // (if it hasn't already been added) and use that as the foreign key restriction. It's also possible
    // to give it an entire string that is interpolated if you need a tighter scope than just a foreign key.
    // Example: <tt>acts_as_list scope: 'todo_list_id = #{todo_list_id} AND completed = 0'</tt>
    // * +top_of_list+ - defines the integer used for the top of the list. Defaults to 1. Use 0 to make the collection
    // act more like an array in its indexing.
    // * +add_new_at+ - specifies whether objects get added to the :top or :bottom of the list. (default: +bottom+)
    //                 `nil` will result in new items not being added to the list on create
    //

    /**
     * Required to override options and kick off the Listcraft's automatic list management.
     * @param  array $options [column=>string, scope=>string|BelongsTo|Builder, top_of_list=>int, add_new_at=>string]
     * @return void
     */
    public function initListcraft($options = [])
    {
        //Update config with options
        $this->listcraftConfig = array_replace($this->listcraftConfig, $options);
    }

    /**
     * Returns whether the scope has changed during the course of interaction with the model
     * @return boolean
     */
    public static function bootListcraft()
    {
        //Bind to model events
        static::deleting(function($model)
        {
            $model->reloadPosition();
        });

        static::deleted(function($model)
        {
            $model->decrementPositionsOnLowerItems();
        });

        static::updating(function($model)
        {
            $model->checkScope();
        });

        static::updated(function($model)
        {
            $model->updatePositions();
        });

        static::creating(function($model)
        {
            if($model->addNewAt())
            {
                $method_name = "addToList" . $model->addNewAt();
                $model->$method_name();
            }
        });
    }

    /**
     * Returns whether the scope has changed during the course of interaction with the model
     * @return boolean
     */
    private function hasScopeChanged()
    {
        $theScope = $this->scopeName();

        if(is_string($theScope))
        {
            if(!$this->stringScopeValue)
            {
                $this->stringScopeValue = $theScope;
                return FALSE;
            }

            return $theScope != $this->stringScopeValue;
        }

        $reflector = new \ReflectionClass($theScope);
        if($reflector->getName() == 'Illuminate\Database\Eloquent\Relations\BelongsTo')
        {
            $originalVal = $this->getOriginal()[$theScope->getForeignKey()];
            $currentVal = $this->getAttribute($theScope->getForeignKey());

            if($originalVal != $currentVal) return TRUE;
        }
        else if ($reflector->getName() == 'Illuminate\Database\Query\Builder')
        {
            if(!$this->stringScopeValue)
            {
                $this->stringScopeValue = $this->getConditionStringFromQueryBuilder($theScope);
                return FALSE;
            }

            $theQuery = $this->getConditionStringFromQueryBuilder($theScope);
            if($theQuery != $this->stringScopeValue) return TRUE;
        }

        return FALSE;
    }

    /**
     * Returns the raw WHERE clause to be used as the Listcraft scope
     * @return string
     */
    private function scopeCondition()
    {
        $theScope = $this->scopeName();

        if($theScope === NULL)
        {
            throw new NullScopeException('You cannot pass in a null scope into Listcraft. It breaks stuff.');
        }

        if($theScope !== $this->defaultScope)
        {
            if(is_string($theScope))
            {
                //Good for you for being brave. Let's hope it'll run in your DB! You sanitized it, right?
                $this->stringScopeValue = $theScope;
            }
            else
            {
                if(is_object($theScope))
                {
                    $reflector = new \ReflectionClass($theScope);
                    if($reflector->getName() == 'Illuminate\Database\Eloquent\Relations\BelongsTo')
                    {
                        $relationshipId = $this->getAttribute($theScope->getForeignKey());

                        if($relationshipId === NULL)
                        {
                            throw new NullForeignKeyException('The Listcraft scope is a "belongsTo" relationship, but the foreign key is null.');
                        }
                        else
                        {
                            $theScope = $theScope->getForeignKey() . ' = ' . $this->getAttribute($theScope->getForeignKey());
                        }
                    }
                    else if ($reflector->getName() == 'Illuminate\Database\Query\Builder')
                    {
                        $theQuery = $this->getConditionStringFromQueryBuilder($theScope);
                        $this->stringScopeValue = $theQuery;
                        $theScope = $theQuery;
                    }
                    else
                    {
                        throw new InvalidScopeException('Listcraft scope parameter must be a String, an Eloquent BelongsTo object, or a Query Builder object.');
                    }
                }
                else
                {
                    throw new InvalidScopeException('Listcraft scope parameter must be a String, an Eloquent BelongsTo object, or a Query Builder object.');
                }
            }
        }

        return $theScope;
    }

    /**
     * Returns a raw WHERE clause based off of a Query Builder object
     * @param  $query A Query Builder instance
     * @return string
     */
    private function getConditionStringFromQueryBuilder($query)
    {
        $initialQueryChunks = explode('where ', $query->toSql());
        if(count($initialQueryChunks) == 1) throw new InvalidQueryBuilderException('The Listcraft scope is a Query Builder object, but it has no "where", so it can\'t be used as a scope.');
        $queryChunks = explode('?', $initialQueryChunks[1]);
        $bindings = $query->getBindings();

        $theQuery = '';

        for($i = 0; $i < count($queryChunks); $i++)
        {
            // "boolean"
            // "integer"
            // "double" (for historical reasons "double" is returned in case of a float, and not simply "float")
            // "string"
            // "array"
            // "object"
            // "resource"
            // "NULL"
            // "unknown type"

            $theQuery .= $queryChunks[$i];
            if(isset($bindings[$i]))
            {
                switch(gettype($bindings[$i]))
                {
                    case "string":
                        $theQuery .= '\'' . $bindings[$i] . '\'';
                        break;
                }
            }
        }

        return $theQuery;
    }

    /**
     * An Eloquent scope based on the processed scope option
     * @param  $query An Eloquent Query Builder instance
     * @return Eloquent Query Builder instance
     */
    public function scopeListcraftScope($query)
    {
        return $query->whereRaw($this->scopeCondition());
    }

    /**
     * An Eloquent scope that returns only items currently in the list
     * @param $query
     * @return Eloquent Query Builder instance
     */
    public function scopeInList($query)
    {
        return $query->listcraftScope()->whereNotNull($this->getTable() . "." . $this->positionColumn());
    }

    /**
     * Get the value of the "top_of_list" option
     * @return string
     */
    public function listcraftTop()
    {
        return $this->listcraftConfig['top_of_list'];
    }

    /**
     * Updates a listcraft config value
     * @param string
     * @param mixed
     * @return void
     */
    public function setListcraftConfig($key, $value)
    {
        $this->listcraftConfig[$key] = $value;
    }

    /**
     * Get the name of the position 'column' option
     * @return string
     */
    public function positionColumn()
    {
        return $this->listcraftConfig['column'];
    }

    /**
     * Get the value of the 'scope' option
     * @return mixed Can be a string, an Eloquent BelongsTo, or an Eloquent Builder
     */
    public function scopeName()
    {
        return $this->listcraftConfig['scope'];
    }

    /**
     * Returns the value of the 'add_new_at' option
     * @return string
     */
    public function addNewAt()
    {
        return $this->listcraftConfig['add_new_at'];
    }

    /**
     * Returns the value of the model's current position
     * @return int
     */
    public function getListcraftPosition()
    {
        return $this->getAttribute($this->positionColumn());
    }

    /**
     * Sets the value of the model's position
     * @param int $position
     * @return void
     */
    public function setListcraftPosition($position)
    {
        $this->setAttribute($this->positionColumn(), $position);
    }

    /**
     * Insert the item at the given position (defaults to the top position of 1).
     * @param  int $position
     * @return void
     */
    public function insertAt($position = NULL)
    {
        if($position === NULL) $position = $this->listcraftTop();
        $this->insertAtPosition($position);
    }

    /**
     * Swap positions with the next lower item, if one exists.
     * @return void
     */
    public function moveLower()
    {
        if(!$this->lowerItem()) return;

        $this->getConnection()->transaction(function()
        {
            $this->lowerItem()->decrement($this->positionColumn());
            $this->increment($this->positionColumn());
        });
    }

    /**
     * Swap positions with the next higher item, if one exists.
     * @return void
     */
    public function moveHigher()
    {
        if(!$this->higherItem()) return;

        $this->getConnection()->transaction(function()
        {
            $this->higherItem()->increment($this->positionColumn());
            $this->decrement($this->positionColumn());
        });
    }

    /**
     * Move to the bottom of the list. If the item is already in the list, the items below it have their positions adjusted accordingly.
     * @return void
     */
    public function moveToBottom()
    {
        if($this->isNotInList()) return NULL;

        $this->getConnection()->transaction(function()
        {
            $this->decrementPositionsOnLowerItems();
            $this->assumeBottomPosition();
        });
    }

    /**
     * Move to the top of the list. If the item is already in the list, the items above it have their positions adjusted accordingly.
     * @return void
     */
    public function moveToTop()
    {
        if($this->isNotInList()) return NULL;

        $this->getConnection()->transaction(function()
        {
            $this->incrementPositionsOnHigherItems();
            $this->assumeTopPosition();
        });
    }

    /**
     * Removes the item from the list.
     * @return void
     */
    public function removeFromList()
    {
        if($this->isInList())
        {
            $this->decrementPositionsOnLowerItems();
            $this->setListPosition(NULL);
        }
    }

    /**
     * Increase the position of this item without adjusting the rest of the list.
     * @return void
     */
    public function incrementPosition()
    {
        if($this->isNotInList()) return NULL;
        $this->setListcraftPosition($this->getListcraftPosition() + 1);
    }

    /**
     * Decrease the position of this item without adjusting the rest of the list.
     * @return void
     */
    public function decrementPosition()
    {
        if($this->isNotInList()) return NULL;
        $this->setListcraftPosition($this->getListcraftPosition() - 1);
    }

    /**
     * Returns if this object is the first in the list.
     * @return boolean
     */
    public function isFirst()
    {
        if($this->isNotInList()) return FALSE;
        if($this->getListcraftPosition() == $this->listcraftTop()) return TRUE;
        return FALSE;
    }

    /**
     * Returns if this object is the last in the list.
     * @return boolean
     */
    public function isLast()
    {
        if($this->isNotInList()) return FALSE;
        if($this->getListcraftPosition() == $this->bottomPositionInList()) return TRUE;
        return FALSE;
    }

    /**
     * Return the next higher item in the list.
     * @return mixed Returned item will be of the same type as the current class instance
     */
    public function higherItem()
    {
        if($this->isNotInList()) return NULL;

        return $this->listcraftList()
            ->where($this->positionColumn(), "<", $this->getListcraftPosition())
            ->orderBy($this->getTable() . "." . $this->positionColumn(), "DESC")
            ->first();
    }

    /**
     * Return the next n higher items in the list. Selects all higher items by default
     * @param  int $limit The number of items to return
     * @return mixed Returned items will be of the same type as the current class instance
     */
    public function higherItems($limit = NULL)
    {
        if($limit === NULL) $limit = $this->listcraftList()->count();
        $position_value = $this->getListcraftPosition();

        return $this->listcraftList()
            ->where($this->positionColumn(), "<", $position_value)
            ->where($this->positionColumn(), ">=", $position_value - $limit)
            ->take($limit)
            ->orderBy($this->getTable() . "." . $this->positionColumn(), "ASC")
            ->get();
    }

    /**
     * Return the next lower item in the list.
     * @return mixed Returned item will be of the same type as the current class instance
     */
    public function lowerItem()
    {
        if($this->isNotInList()) return NULL;

        return $this->listcraftList()
            ->where($this->positionColumn(), ">", $this->getListcraftPosition())
            ->orderBy($this->getTable() . "." . $this->positionColumn(), "ASC")
            ->first();
    }

    /**
     * Return the next n lower items in the list. Selects all lower items by default.
     * @param int $limit The number of items to return
     * @return mixed Returned items will be of the same type as the current class instance
     */
    public function lowerItems($limit = NULL)
    {
        if($limit === NULL) $limit = $this->listcraftList()->count();
        $position_value = $this->getListcraftPosition();

        return $this->listcraftList()
            ->where($this->positionColumn(), '>', $position_value)
            ->where($this->positionColumn(), '<=', $position_value + $limit)
            ->take($limit)
            ->orderBy($this->getTable() . "." . $this->positionColumn(), "ASC")
            ->get();
    }

    /**
     * Returns whether the item is in the list
     * @return boolean
     */
    public function isInList()
    {
        return !$this->isNotInList();
    }

    /**
     * Returns whether the item is not in the list
     * @return boolean
     */
    public function isNotInList()
    {
        return $this->getListcraftPosition() === NULL;
    }

    /**
     * Get the default item position
     * @return mixed
     */
    public function defaultPosition()
    {
        return NULL;
    }

    /**
     * Returns whether the item's current position matches the default position
     * @return boolean
     */
    public function isDefaultPosition()
    {
        return $this->defaultPosition() == $this->getListcraftPosition();
    }

    /**
     * Sets the new position and saves it
     * @param int $new_position
     * @return void
     */
    public function setListPosition($new_position)
    {
        $this->setListcraftPosition($new_position);
        $this->save();
    }

    /* Private Methods */

    /**
     * Creates an instance of the current class scope as a list
     * @return mixed
     */
    private function listcraftList()
    {
        $model = new self();
        $model->setListcraftConfig('scope', $this->scopeCondition());

        return $model->listcraftScope();
    }

    /**
     * Adds item to the top of the list
     * @return void
     */
    private function addToListTop()
    {
        $this->incrementPositionsOnAllItems();
        $this->setListcraftPosition($this->listcraftTop());
    }

    /**
     * Adds item to the bottom of the list
     * @return void
     */
    private function addToListBottom()
    {
        if($this->isNotInList())
        {
            $this->setListcraftPosition($this->bottomPositionInList() + 1);
        }
    }

    /**
     * Returns the bottom position number in the list
     * @param  mixed $except An Eloquent model instance
     * @return int
     */
    private function bottomPositionInList($except = NULL)
    {
        $item = $this->bottomItem($except);

        if($item)
            return $item->getListcraftPosition();
        else
            return $this->listcraftTop() - 1;
    }

    /**
     * Returns the bottom item
     * @param  mixed $except An Eloquent model instance
     * @return mixed Returns an item of the same type as the current class instance
     */
    private function bottomItem($except = NULL)
    {
        $conditions = $this->scopeCondition();

        if($except !== NULL)
        {
            $conditions = $conditions . " AND " . $this->primaryKey() . " != " . $except->id;
        }

        $list = $this->listcraftList()
            ->whereNotNull($this->getTable() . "." . $this->positionColumn())
            ->whereRaw($conditions)
            ->orderBy($this->getTable() . "." . $this->positionColumn(), "DESC")
            ->take(1)->first();

        return $list;
    }

    /**
     * Returns the primary key of the current Eloquent instance
     * @return string
     */
    private function primaryKey()
    {
        return $this->getConnection()->getTablePrefix() . $this->getQualifiedKeyName();
    }

    /**
     * Forces item to assume the bottom position in the list.
     * @return void
     */
    private function assumeBottomPosition()
    {
        $this->setListPosition($this->bottomPositionInList($this) + 1);
    }

    /**
     * Forces item to assume the top position in the list.
     * @return void
     */
    private function assumeTopPosition()
    {
        $this->setListPosition($this->listcraftTop());
    }

    /**
     * This has the effect of moving all the lower items up one.
     * @param  int $position All items below the passed in position will be modified
     * @return void
     */
    private function decrementPositionsOnLowerItems($position = NULL)
    {
        if($this->isNotInList()) return NULL;
        if($position === NULL) $position = $this->getListcraftPosition();

        $this->listcraftList()
           ->where($this->positionColumn(), '>', $position)
           ->decrement($this->positionColumn());
    }

    /**
     * This has the effect of moving all the higher items down one.
     * @return void
     */
    private function incrementPositionsOnHigherItems()
    {
        if($this->isNotInList()) return NULL;

        $this->listcraftList()
           ->where($this->positionColumn(), '<', $this->getListcraftPosition())
           ->increment($this->positionColumn());
    }

    /**
     * This has the effect of moving all the lower items down one.
     * @param  int $position All items below the passed in position will be modified
     * @return void
     */
    private function incrementPositionsOnLowerItems($position)
    {
        $this->listcraftList()
            ->where($this->positionColumn(), '>=', $position)
            ->increment($this->positionColumn());
    }

    /**
     * Increments position of all items in the list.
     * @return void
     */
    private function incrementPositionsOnAllItems()
    {
        $this->listcraftList()
            ->increment($this->positionColumn());
    }

    /**
     * Reorders intermediate items to support moving an item from old_position to new_position.
     * @param  int $old_position
     * @param  int $new_position
     * @param  string $avoid_id     You can pass in an ID of a record matching the current class and it will be ignored
     * @return void
     */
    private function shufflePositionsOnIntermediateItems($old_position, $new_position, $avoid_id = NULL)
    {
        if($old_position == $new_position) return;
        $avoid_id_condition = $avoid_id ? $this->primaryKey() . " != " . $avoid_id : '1 = 1';

        if($old_position < $new_position)
        {
            // Decrement position of intermediate items

            // e.g., if moving an item from 2 to 5,
            // move [3, 4, 5] to [2, 3, 4]

            $this->listcraftList()
                ->where($this->positionColumn(), '>', $old_position)
                ->where($this->positionColumn(), '<=', $new_position)
                ->whereRaw($avoid_id_condition)
                ->decrement($this->positionColumn());
        }
        else
        {
            // Increment position of intermediate items

            // e.g., if moving an item from 5 to 2,
            // move [2, 3, 4] to [3, 4, 5]

            $this->listcraftList()
                ->where($this->positionColumn(), '>=', $new_position)
                ->where($this->positionColumn(), '<', $old_position)
                ->whereRaw($avoid_id_condition)
                ->increment($this->positionColumn());
        }
    }

    /**
     * Inserts the item at a particular location in the list. All items around it will be modified
     * @param  int $position
     * @return void
     */
    private function insertAtPosition($position)
    {
        if($this->isInList())
        {
            $old_position = $this->getListcraftPosition();
            if($position == $old_position) return;

            $this->shufflePositionsOnIntermediateItems($old_position, $position);
        }
        else
        {
            $this->incrementPositionsOnLowerItems($position);
        }

        $this->setListPosition($position);
    }

    /**
     * Updates all items based on the original position of the item and the new position of the item
     * @return void
     */
    private function updatePositions()
    {
        $old_position = $this->getOriginal()[$this->positionColumn()];
        $new_position = $this->getListcraftPosition();

        if($new_position === NULL)
            $matching_position_records = 0;
        else
            $matching_position_records = $this->listcraftList()->where($this->positionColumn(), '=', $new_position)->count();

        if($matching_position_records <= 1)
        {
            return;
        }

        $this->shufflePositionsOnIntermediateItems($old_position, $new_position, $this->id);
    }

    /**
     * Temporarily swap changes attributes with current attributes
     * @return void
     */
    public function swapChangedAttributes()
    {
        if($this->originalAttributesLoaded === FALSE)
        {
            $this->swappedAttributes = $this->getAttributes();
            $this->fill($this->getOriginal());
            $this->originalAttributesLoaded = TRUE;
        }
        else
        {
            if(count($this->swappedAttributes) == 0) $this->swappedAttributes = $this->getAttributes();
            $this->fill($this->swappedAttributes);
            $this->originalAttributesLoaded = FALSE;
        }
    }

    /**
     * Determines whether scope has changed. If so, it will move the current item to the top/bottom of the list and update all other items
     * @return void
     */
    private function checkScope()
    {
        if($this->hasScopeChanged())
        {
            $this->swapChangedAttributes();
            if($this->lowerItem()) $this->decrementPositionsOnLowerItems();
            $this->swapChangedAttributes();
            $this->setListcraftPosition(NULL); //make this item "not in the list" so subsequent call to addToListBottom() works (b/c it only operates on items that have no position)
            $method_name = "addToList" . $this->addNewAt();
            $this->$method_name();
        }
    }

    /**
     * Reloads the position value of the current item. This is only called when an item is deleted and is here to prevent unsetting the position column which would prevent other items from being moved properly
     * @return void
     */
    private function reloadPosition()
    {
        $this->setListcraftPosition($this->getOriginal()[$this->positionColumn()]);
    }
}
