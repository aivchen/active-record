<?php

declare(strict_types=1);

namespace Yiisoft\ActiveRecord;

use ArrayAccess;
use IteratorAggregate;
use Throwable;
use Yiisoft\ActiveRecord\Trait\ArrayableTrait;
use Yiisoft\ActiveRecord\Trait\ArrayAccessTrait;
use Yiisoft\ActiveRecord\Trait\ArrayIteratorTrait;
use Yiisoft\ActiveRecord\Trait\MagicPropertiesTrait;
use Yiisoft\ActiveRecord\Trait\MagicRelationsTrait;
use Yiisoft\ActiveRecord\Trait\TransactionalTrait;
use Yiisoft\Arrays\ArrayableInterface;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Schema\TableSchemaInterface;

use function array_diff;
use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function key;
use function preg_replace;

/**
 * ActiveRecord is the base class for classes representing relational data in terms of objects.
 *
 * Active Record implements the [Active Record design pattern](https://en.wikipedia.org/wiki/Active_record).
 *
 * The premise behind Active Record is that an individual {@see ActiveRecord} object is associated with a specific row
 * in a database table. The object's attributes are mapped to the columns of the corresponding table.
 *
 * Referencing an Active Record attribute is equivalent to accessing the corresponding table column for that record.
 *
 * As an example, say that the `Customer` ActiveRecord class is associated with the `customer` table.
 *
 * This would mean that the class's `name` attribute is automatically mapped to the `name` column in `customer` table.
 * Thanks to Active Record, assuming the variable `$customer` is an object of type `Customer`, to get the value of the
 * `name` column for the table row, you can use the expression `$customer->name`.
 *
 * In this example, Active Record is providing an object-oriented interface for accessing data stored in the database.
 * But Active Record provides much more functionality than this.
 *
 * To declare an ActiveRecord class you need to extend {@see ActiveRecord} and implement the `getTableName` method:
 *
 * ```php
 * class Customer extends ActiveRecord
 * {
 *     public static function getTableName(): string
 *     {
 *         return 'customer';
 *     }
 * }
 * ```
 *
 * The `getTableName` method only has to return the name of the database table associated with the class.
 *
 * Class instances are obtained in one of two ways:
 *
 * Using the `new` operator to create a new, empty object.
 * Using a method to fetch an existing record (or records) from the database.
 *
 * Below is an example showing some typical usage of ActiveRecord:
 *
 * ```php
 * $user = new User($db);
 * $user->name = 'Qiang';
 * $user->save();  // a new row is inserted into user table
 *
 * // the following will retrieve the user 'CeBe' from the database
 * $userQuery = new ActiveQuery(User::class, $db);
 * $user = $userQuery->where(['name' => 'CeBe'])->one();
 *
 * // this will get related records from orders table when relation is defined
 * $orders = $user->orders;
 * ```
 *
 * For more details and usage information on ActiveRecord,
 * {@see the [guide article on ActiveRecord](guide:db-active-record)}
 *
 * @method ActiveQuery hasMany($class, array $link) {@see BaseActiveRecord::hasMany()} for more info.
 * @method ActiveQuery hasOne($class, array $link) {@see BaseActiveRecord::hasOne()} for more info.
 *
 * @template-implements ArrayAccess<string, mixed>
 * @template-implements IteratorAggregate<string, mixed>
 */
class ActiveRecord extends AbstractActiveRecord implements ArrayableInterface, ArrayAccess, IteratorAggregate, TransactionalInterface
{
    use ArrayableTrait;
    use ArrayAccessTrait;
    use ArrayIteratorTrait;
    use MagicPropertiesTrait;
    use MagicRelationsTrait;
    use TransactionalTrait;

    public function attributes(): array
    {
        return $this->getTableSchema()->getColumnNames();
    }

    public function filterCondition(array $condition, array $aliases = []): array
    {
        $result = [];

        $columnNames = $this->filterValidColumnNames($aliases);

        foreach ($condition as $key => $value) {
            if (is_string($key) && !in_array($this->db->getQuoter()->quoteSql($key), $columnNames, true)) {
                throw new InvalidArgumentException(
                    'Key "' . $key . '" is not a column name and can not be used as a filter'
                );
            }
            $result[$key] = is_array($value) ? array_values($value) : $value;
        }

        return $result;
    }

    public function filterValidAliases(ActiveQuery $query): array
    {
        $tables = $query->getTablesUsedInFrom();

        $aliases = array_diff(array_keys($tables), $tables);

        return array_map(static fn ($alias) => preg_replace('/{{([\w]+)}}/', '$1', $alias), array_values($aliases));
    }

    /**
     * Returns the schema information of the DB table associated with this AR class.
     *
     * @throws Exception
     * @throws InvalidConfigException If the table for the AR class does not exist.
     *
     * @return TableSchemaInterface The schema information of the DB table associated with this AR class.
     */
    public function getTableSchema(): TableSchemaInterface
    {
        $tableSchema = $this->db->getSchema()->getTableSchema($this->getTableName());

        if ($tableSchema === null) {
            throw new InvalidConfigException('The table does not exist: ' . $this->getTableName());
        }

        return $tableSchema;
    }

    /**
     * Loads default values from database table schema.
     *
     * You may call this method to load default values after creating a new instance:
     *
     * ```php
     * // class Customer extends ActiveRecord
     * $customer = new Customer($db);
     * $customer->loadDefaultValues();
     * ```
     *
     * @param bool $skipIfSet Whether existing value should be preserved. This will only set defaults for attributes
     * that are `null`.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return self The active record instance itself.
     */
    public function loadDefaultValues(bool $skipIfSet = true): self
    {
        foreach ($this->getTableSchema()->getColumns() as $name => $column) {
            if ($column->getDefaultValue() !== null && (!$skipIfSet || $this->getAttribute($name) === null)) {
                $this->setAttribute($name, $column->getDefaultValue());
            }
        }

        return $this;
    }

    public function populateRecord(array|object $row): void
    {
        $columns = $this->getTableSchema()->getColumns();

        /** @psalm-var array[][] $row */
        foreach ($row as $name => $value) {
            if (isset($columns[$name])) {
                $row[$name] = $columns[$name]->phpTypecast($value);
            }
        }

        parent::populateRecord($row);
    }

    public function primaryKey(): array
    {
        return $this->getTableSchema()->getPrimaryKey();
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function refresh(): bool
    {
        $query = $this->instantiateQuery(static::class);

        $tableName = key($query->getTablesUsedInFrom());
        $pk = [];

        /** disambiguate column names in case ActiveQuery adds a JOIN */
        foreach ($this->getPrimaryKey(true) as $key => $value) {
            $pk[$tableName . '.' . $key] = $value;
        }

        $query->where($pk);

        return $this->refreshInternal($query->onePopulate());
    }

    /**
     * Valid column names are table column names or column names prefixed with table name or table alias.
     *
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function filterValidColumnNames(array $aliases): array
    {
        $columnNames = [];
        $tableName = $this->getTableName();
        $quotedTableName = $this->db->getQuoter()->quoteTableName($tableName);

        foreach ($this->getTableSchema()->getColumnNames() as $columnName) {
            $columnNames[] = $columnName;
            $columnNames[] = $this->db->getQuoter()->quoteColumnName($columnName);
            $columnNames[] = "$tableName.$columnName";
            $columnNames[] = $this->db->getQuoter()->quoteSql("$quotedTableName.[[$columnName]]");

            foreach ($aliases as $tableAlias) {
                $columnNames[] = "$tableAlias.$columnName";
                $quotedTableAlias = $this->db->getQuoter()->quoteTableName($tableAlias);
                $columnNames[] = $this->db->getQuoter()->quoteSql("$quotedTableAlias.[[$columnName]]");
            }
        }

        return $columnNames;
    }

    /**
     * Inserts an ActiveRecord into DB without considering transaction.
     *
     * @param array|null $attributes List of attributes that need to be saved. Defaults to `null`, meaning all
     * attributes that are loaded from DB will be saved.
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws Throwable
     *
     * @return bool Whether the record is inserted successfully.
     */
    protected function insertInternal(array $attributes = null): bool
    {
        $values = $this->getDirtyAttributes($attributes);
        $primaryKeys = $this->db->createCommand()->insertWithReturningPks($this->getTableName(), $values);

        if ($primaryKeys === false) {
            return false;
        }

        $columns = $this->getTableSchema()->getColumns();

        foreach ($primaryKeys as $name => $value) {
            $id = $columns[$name]->phpTypecast($value);
            $this->setAttribute($name, $id);
            $values[$name] = $id;
        }

        $this->setOldAttributes($values);

        return true;
    }
}
