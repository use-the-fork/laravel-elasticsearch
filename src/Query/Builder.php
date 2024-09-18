<?php

declare(strict_types=1);

namespace PDPhilip\Elasticsearch\Query;

use AllowDynamicProperties;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use LogicException;
use PDPhilip\Elasticsearch\Collection\ElasticCollection;
use PDPhilip\Elasticsearch\Collection\ElasticResult;
use PDPhilip\Elasticsearch\Collection\LazyElasticCollection;
use PDPhilip\Elasticsearch\Connection;
use PDPhilip\Elasticsearch\DSL\Results;
use PDPhilip\Elasticsearch\Helpers\Utilities;
use PDPhilip\Elasticsearch\Meta\QueryMetaData;
use PDPhilip\Elasticsearch\Schema\Schema;
use RuntimeException;

/**
 * @property Connection $connection
 * @property Processor $processor
 * @property Grammar $grammar
 */
#[AllowDynamicProperties]
class Builder extends BaseBuilder
{
    use Utilities;

    public array $options = [];

    public bool $paginating = false;

    public mixed $searchAfter = null;

    public array $cursor = [];

    public mixed $previousSearchAfter = null;

    public string $searchQuery = '';

    public int $distinctType = 0;

    public array $searchOptions = [];

    public mixed $minScore = null;

    public array $fields = [];

    public array $filters = [];

    /**
     * Clause ops.
     *
     * @var string[]
     */
    public $operators = [
        // @inherited
        '=',
        '<',
        '>',
        '<=',
        '>=',
        '<>',
        '!=',
        '<=>',
        'like',
        'like binary',
        'not like',
        'ilike',
        '&',
        '|',
        '^',
        '<<',
        '>>',
        '&~',
        'rlike',
        'not rlike',
        'regexp',
        'not regexp',
        '~',
        '~*',
        '!~',
        '!~*',
        'similar to',
        'not similar to',
        'not ilike',
        '~~*',
        '!~~*',
        // @Elastic Search
        'exist',
        'regex',
    ];

    protected string $index = '';

    protected string|bool $refresh = 'wait_for';

    /**
     * Operator conversion.
     */
    protected array $conversion = [
        '=' => '=',
        '!=' => 'ne',
        '<>' => 'ne',
        '<' => 'lt',
        '<=' => 'lte',
        '>' => 'gt',
        '>=' => 'gte',
    ];

    /**
     * {@inheritdoc}
     */
    public function __construct(Connection $connection, Processor $processor)
    {
        $this->grammar = new Grammar;
        $this->connection = $connection;
        $this->processor = $processor;
    }

    public function getProcessor(): Processor
    {
        return $this->processor;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function setRefresh($value): void
    {
        $this->refresh = $value;
    }

    public function initCursor($cursor): array
    {

        $this->cursor = [
            'page' => 1,
            'pages' => 0,
            'records' => 0,
            'sort_history' => [],
            'next_sort' => null,
            'ts' => 0,
        ];

        if (! empty($cursor)) {
            $this->cursor = [
                'page' => $cursor->parameter('page'),
                'pages' => $cursor->parameter('pages'),
                'records' => $cursor->parameter('records'),
                'sort_history' => $cursor->parameter('sort_history'),
                'next_sort' => $cursor->parameter('next_sort'),
                'ts' => $cursor->parameter('ts'),
            ];
        }

        return $this->cursor;
    }

    //----------------------------------------------------------------------
    // Querying Executors
    //----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return Arr::get($result, $column);
    }

    /**
     * {@inheritdoc}
     */
    public function get($columns = []): ElasticCollection|LazyCollection
    {
        return $this->_processGet($columns);
    }

    /**
     * @return ElasticCollection|LazyElasticCollection|void
     */
    protected function _processGet(array|string $columns = [], bool $returnLazy = false)
    {

        $wheres = $this->compileWheres();
        $options = $this->compileOptions();
        $columns = $this->prepareColumns($columns);

        if ($this->groups) {
            throw new RuntimeException('Groups are not used');
        }

        if ($this->aggregate) {
            $function = $this->aggregate['function'];
            $aggColumns = $this->aggregate['columns'];
            if (in_array('*', $aggColumns)) {
                $aggColumns = null;
            }
            if ($aggColumns) {
                $columns = $aggColumns;
            }

            if ($this->distinctType) {
                $totalResults = $this->connection->distinctAggregate($function, $wheres, $options, $columns);
            } else {
                $totalResults = $this->connection->aggregate($function, $wheres, $options, $columns);
            }

            if (! $totalResults->isSuccessful()) {
                throw new RuntimeException($totalResults->errorMessage);
            }
            $results = [
                [
                    '_id' => null,
                    'aggregate' => $totalResults->data,
                ],
            ];
            $result = new ElasticCollection($results);
            $result->setQueryMeta($totalResults->getMetaData());

            // Return results
            return $result;
        }

        if ($this->distinctType) {
            if (empty($columns[0]) || $columns[0] == '*') {
                throw new RuntimeException('Columns are required for term aggregation when using distinct()');
            } else {

                if ($this->distinctType == 2) {
                    $find = $this->connection->distinct($wheres, $options, $columns, true);
                } else {
                    $find = $this->connection->distinct($wheres, $options, $columns);
                }
            }
        } else {
            $find = $this->connection->find($wheres, $options, $columns);
        }

        //Else Normal find query
        if ($find->isSuccessful()) {
            $data = $find->data;
            if ($returnLazy) {
                if ($data) {
                    $lazy = LazyElasticCollection::make(function () use ($data) {
                        foreach ($data as $item) {
                            yield $item;
                        }
                    });
                    $lazy->setQueryMeta($find->getMetaData());

                    return $lazy;
                }
            }
            $collection = new ElasticCollection($data);
            $collection->setQueryMeta($find->getMetaData());

            return $collection;
        } else {
            throw new RuntimeException('Error: '.$find->errorMessage);
        }
    }

    protected function compileWheres(): array
    {
        $wheres = $this->wheres ?: [];
        $compiledWheres = [];
        if ($wheres) {
            if ($wheres[0]['boolean'] == 'or') {
                throw new RuntimeException('Cannot start a query with an OR statement');
            }
            if (count($wheres) == 1) {
                return $this->{'_parseWhere'.$wheres[0]['type']}($wheres[0]);
            }
            $and = [];
            $or = [];
            foreach ($wheres as $where) {
                if ($where['boolean'] == 'or') {
                    $or[] = $and;
                    //clear AND for the next bucket
                    $and = [];
                }

                $result = $this->{'_parseWhere'.$where['type']}($where);
                $and[] = $result;
            }
            if ($or) {
                //Add the last AND bucket
                $or[] = $and;
                foreach ($or as $and) {
                    $compiledWheres['or'][] = $this->_prepAndBucket($and);
                }
            } else {

                $compiledWheres = $this->_prepAndBucket($and);
            }
        }

        return $compiledWheres;
    }

    private function _prepAndBucket($andData): array
    {
        $data = [];
        foreach ($andData as $key => $ops) {
            $data['and'][$key] = $ops;
        }

        return $data;
    }

    protected function compileOptions(): array
    {
        $options = [];
        if ($this->orders) {
            $options['sort'] = $this->orders;
        }
        if ($this->offset) {
            $options['skip'] = $this->offset;
        }
        if ($this->limit) {
            $options['limit'] = $this->limit;
            //Check if it's first() with no ordering,
            //Set order to created_at -> asc for consistency
            //TODO
        }
        if ($this->cursor) {
            $options['_meta']['cursor'] = $this->cursor;
            if (! empty($this->cursor['next_sort'])) {
                $options['search_after'] = $this->cursor['next_sort'];
            }
        }

        if ($this->previousSearchAfter) {
            $options['prev_search_after'] = $this->previousSearchAfter;
        }
        if ($this->minScore) {
            $options['minScore'] = $this->minScore;
        }
        if ($this->searchOptions) {
            $options['searchOptions'] = $this->searchOptions;
        }
        if ($this->filters) {
            $options['filters'] = $this->filters;
        }

        return $options;
    }

    protected function prepareColumns($columns): array
    {
        $final = [];
        if ($this->columns) {
            foreach ($this->columns as $col) {
                $final[] = $col;
            }
        }

        if ($columns) {
            if (! is_array($columns)) {
                $columns = [$columns];
            }

            foreach ($columns as $col) {
                $final[] = $col;
            }
        }
        if (! $final) {
            return ['*'];
        }

        $final = array_values(array_unique($final));
        if (($key = array_search('*', $final)) !== false) {
            unset($final[$key]);
        }

        return $final;
    }

    /**
     * {@inheritdoc}
     */
    public function aggregate($function, $columns = []): mixed
    {

        $this->aggregate = compact('function', 'columns');

        $previousColumns = $this->columns;

        // Store previous bindings before aggregate
        $previousSelectBindings = $this->bindings['select'];

        $this->bindings['select'] = [];
        $results = $this->get($columns);
        // Restore bindings after aggregate search
        $this->aggregate = [];
        $this->columns = $previousColumns;
        $this->bindings['select'] = $previousSelectBindings;

        if (isset($results[0])) {
            $result = (array) $results[0];
            $esResult = new ElasticResult;
            $esResult->setQueryMeta($results->getQueryMeta());
            $esResult->setValue($result['aggregate']);

            // For now we'll return the result as is,
            // Later we'll return ElasticResult to get access to the meta
            return $esResult->getValue();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function distinct($includeCount = false): static
    {
        $this->distinctType = 1;
        if ($includeCount) {
            $this->distinctType = 2;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id, $columns = [])
    {
        return $this->where('_id', $id)->first($columns);
    }

    /**
     * {@inheritdoc}
     */
    public function cursor($columns = []): LazyCollection
    {
        $result = $this->_processGet($columns, true);
        if ($result instanceof LazyCollection) {
            return $result;
        }
        throw new RuntimeException('Query not compatible with cursor');
    }

    /**
     * {@inheritdoc}
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function insert(array $values, $returnData = false): ElasticCollection
    {
        return $this->_processInsert($values, $returnData, false);
    }

    /*
     * @see insert(array $values, $returnData = false)
     */

    public function insertWithoutRefresh(array $values, $returnData = false): ElasticCollection
    {
        return $this->_processInsert($values, $returnData, true);
    }

    protected function _processInsert(array $values, bool $returnData, bool $saveWithoutRefresh): ElasticCollection
    {
        $response = [
            'hasErrors' => false,
            'took' => 0,
            'total' => 0,
            'success' => 0,
            'created' => 0,
            'modified' => 0,
            'failed' => 0,
            'data' => [],
            'error_bag' => [],
        ];
        if (empty($values)) {
            return $this->_parseBulkInsertResult($response, $returnData);
        }

        if ($saveWithoutRefresh) {
            $this->refresh = false;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }
        $this->applyBeforeQueryCallbacks();

        collect($values)->chunk(1000)->each(callback: function ($chunk) use (&$response, $returnData) {
            $result = $this->connection->insertBulk($chunk->toArray(), $returnData, $this->refresh);
            if ((bool) $result['hasErrors']) {
                $response['hasErrors'] = true;
            }
            $response['total'] += $result['total'];
            $response['took'] += $result['took'];
            $response['success'] += $result['success'];
            $response['failed'] += $result['failed'];
            $response['created'] += $result['created'];
            $response['modified'] += $result['modified'];
            $response['data'] = array_merge($response['data'], $result['data']);

            $response['error_bag'] = array_merge($response['error_bag'], $result['error_bag']);
        });

        return $this->_parseBulkInsertResult($response, $returnData);

    }

    protected function _parseBulkInsertResult($response, $returnData): ElasticCollection
    {

        $result = new ElasticCollection($response['data']);
        $result->setQueryMeta(new QueryMetaData([]));
        $result->getQueryMeta()->setSuccess();
        $result->getQueryMeta()->setCreated($response['created']);
        $result->getQueryMeta()->setModified($response['modified']);
        $result->getQueryMeta()->setFailed($response['failed']);
        $result->getQueryMeta()->setQuery('InsertBulk');
        $result->getQueryMeta()->setTook($response['took']);
        $result->getQueryMeta()->setTotal($response['total']);
        if ($response['hasErrors']) {
            $errorMessage = 'Bulk insert failed for all values';
            if ($response['success'] > 0) {
                $errorMessage = 'Bulk insert failed for some values';
            }
            $result->getQueryMeta()->setError($response['error_bag'], $errorMessage);
        }
        if (! $returnData) {
            $data = $result->getQueryMetaAsArray();
            unset($data['query']);
            $response['data'] = $data;

            return $this->_parseBulkInsertResult($response, true);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function insertGetId(array $values, $sequence = null): int|array|string|null
    {
        $result = $this->connection->save($values, $this->refresh);

        if ($result->isSuccessful()) {
            // Return id
            return $sequence ? $result->getInsertedId() : $result->data;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $values, array $options = [])
    {
        $this->_checkValues($values);

        return $this->_processUpdate($values, $options);
    }

    private function _checkValues($values): true
    {
        unset($values['updated_at']);
        unset($values['created_at']);
        if (! $this->_isAssociative($values)) {
            throw new RuntimeException('Invalid value format. Expected associative array, got sequential array');
        }

        return true;
    }

    private function _isAssociative(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    protected function _processUpdate($values, array $options = [], $method = 'updateMany'): int
    {
        // Update multiple items by default.
        if (! array_key_exists('multiple', $options)) {
            $options['multiple'] = true;
        }
        $wheres = $this->compileWheres();
        $result = $this->connection->{$method}($wheres, $values, $options, $this->refresh);
        if ($result->isSuccessful()) {
            return $result->getModifiedCount();
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($column, $amount = 1, $extra = [], $options = [])
    {
        return $this->increment($column, -1 * $amount, $extra, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function increment($column, $amount = 1, $extra = [], $options = [])
    {
        $values = ['inc' => [$column => $amount]];

        if (! empty($extra)) {
            $values['set'] = $extra;
        }

        $this->where(function ($query) use ($column) {
            $query->where($column, 'exists', false);

            $query->orWhereNotNull($column);
        });

        return $this->_processUpdate($values, $options, 'incrementMany');
    }

    public function agg(array $functions, $column)
    {
        if (is_array($column)) {
            throw new RuntimeException('Column must be a string');
        }
        $aggregateTypes = ['sum', 'avg', 'min', 'max', 'matrix', 'count'];
        foreach ($functions as $function) {
            if (! in_array($function, $aggregateTypes)) {
                throw new RuntimeException('Invalid aggregate type: '.$function);
            }
        }
        $wheres = $this->compileWheres();
        $options = $this->compileOptions();

        $results = $this->connection->multipleAggregate($functions, $wheres, $options, $column);

        return $results->data ?? [];
    }

    //----------------------------------------------------------------------
    //  Query Processing (Connection API)
    //----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function forPageAfterId($perPage = 15, $lastId = 0, $column = '_id')
    {
        return parent::forPageAfterId($perPage, $lastId, $column);
    }

    /**
     * @return $this
     */
    public function whereNestedObject($column, $callBack, $scoreMode = 'avg'): static
    {
        $boolean = 'and';
        $query = $this->newQuery();
        $callBack($query);
        $wheres = $query->compileWheres();
        $this->wheres[] = [
            'column' => $column,
            'type' => 'NestedObject',
            'wheres' => $wheres,
            'score_mode' => $scoreMode,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function newQuery(): Builder
    {
        return new self($this->connection, $this->processor);
    }

    /**
     * @return $this
     */
    public function whereNotNestedObject($column, $callBack, $scoreMode = 'avg'): static
    {
        $boolean = 'and';
        $query = $this->newQuery();
        $callBack($query);
        $wheres = $query->compileWheres();
        $this->wheres[] = [
            'column' => $column,
            'type' => 'NotNestedObject',
            'wheres' => $wheres,
            'score_mode' => $scoreMode,
            'boolean' => $boolean,
        ];

        return $this;
    }

    //----------------------------------------------------------------------
    // Clause Operators
    //----------------------------------------------------------------------

    public function wherePhrase($column, $value): static
    {
        $boolean = 'and';
        $this->wheres[] = [
            'column' => $column,
            'type' => 'Basic',
            'value' => $value,
            'operator' => 'phrase',
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function wherePhrasePrefix($column, $value): static
    {
        $boolean = 'and';
        $this->wheres[] = [
            'column' => $column,
            'type' => 'Basic',
            'value' => $value,
            'operator' => 'phrase_prefix',
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function whereExact($column, $value): static
    {
        $boolean = 'and';
        $this->wheres[] = [
            'column' => $column,
            'type' => 'Basic',
            'value' => $value,
            'operator' => 'exact',
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function queryNested($column, $callBack): static
    {
        $boolean = 'and';
        $query = $this->newQuery();
        $callBack($query);
        $wheres = $query->compileWheres();
        $options = $query->compileOptions();
        $this->wheres[] = [
            'column' => $column,
            'type' => 'QueryNested',
            'wheres' => $wheres,
            'options' => $options,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereTimestamp($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }
        $this->wheres[] = [
            'column' => $column,
            'type' => 'Timestamp',
            'value' => $value,
            'operator' => $operator,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function orderByDesc($column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * {@inheritdoc}
     */
    public function orderBy($column, $direction = 'asc'): static
    {
        if (is_string($direction)) {
            $direction = (strtolower($direction) == 'asc' ? 'asc' : 'desc');
        }

        $this->orders[$column] = [
            'order' => $direction,
        ];

        return $this;
    }

    /**
     * Including outlier sort functions
     *
     * @return $this
     */
    public function withSort(string $column, string $key, mixed $value): static
    {
        $currentColOrder = $this->orders[$column] ?? [];
        $currentColOrder[$key] = $value;
        $this->orders[$column] = $currentColOrder;

        return $this;
    }

    /**
     * @param  $unit  @values: 'km', 'mi', 'm', 'ft'
     * @param  $mode  @values: 'min', 'max', 'avg', 'sum'
     * @param  $type  @values: 'arc', 'plane'
     * @return $this
     */
    public function orderByGeoDesc($column, $pin, $unit = 'km', $mode = null, $type = null): static
    {
        return $this->orderByGeo($column, $pin, 'desc', $unit, $mode, $type);
    }

    /**
     * @param  string  $direction  @values: 'asc', 'desc'
     * @param  string  $unit  @values: 'km', 'mi', 'm', 'ft'
     * @param  $mode  @values: 'min', 'max', 'avg', 'sum'
     * @param  $type  @values: 'arc', 'plane'
     * @return $this
     */
    public function orderByGeo(
        $column,
        $pin,
        string $direction = 'asc',
        string $unit = 'km',
        ?string $mode = null,
        ?string $type = null
    ): static {
        $this->orders[$column] = [
            'is_geo' => true,
            'order' => $direction,
            'pin' => $pin,
            'unit' => $unit,
            'mode' => $mode,
            'type' => $type,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function orderByNested($column, $direction = 'asc', $mode = null): static
    {
        $this->orders[$column] = [
            'is_nested' => true,
            'order' => $direction,
            'mode' => $mode,

        ];

        return $this;
    }

    //Filters

    /**
     * {@inheritdoc}
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false): static
    {
        $type = 'between';

        $this->wheres[] = compact('column', 'type', 'boolean', 'values', 'not');

        return $this;
    }

    public function groupBy(...$groups): Builder
    {
        if (is_array($groups[0])) {
            $groups = $groups[0];
        }

        $this->addSelect($groups);
        $this->distinctType = 1;

        return $this;
    }

    //Regexs

    public function addSelect($column): static
    {
        if (! is_array($column)) {
            $column = [$column];
        }

        $currentColumns = $this->columns;
        if ($currentColumns) {
            return $this->select(array_merge($currentColumns, $column));
        }

        return $this->select($column);
    }

    /**
     * {@inheritdoc}
     */
    public function select($columns = ['*']): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->columns = $columns;

        return $this;
    }

    public function filterGeoBox($field, $topLeft, $bottomRight): void
    {
        $this->filters['filterGeoBox'] = [
            'field' => $field,
            'topLeft' => $topLeft,
            'bottomRight' => $bottomRight,
        ];
    }

    public function filterGeoPoint($field, $distance, $geoPoint): void
    {
        $this->filters['filterGeoPoint'] = [
            'field' => $field,
            'distance' => $distance,
            'geoPoint' => $geoPoint,
        ];
    }

    public function whereRegex($column, $expression): static
    {
        $type = 'regex';
        $boolean = 'and';
        $this->wheres[] = compact('column', 'type', 'expression', 'boolean');

        return $this;
    }

    public function orWhereRegex($column, $expression): static
    {
        $type = 'regex';
        $boolean = 'or';
        $this->wheres[] = compact('column', 'type', 'expression', 'boolean');

        return $this;
    }

    public function _parseWhereExists(array $where)
    {
        throw new LogicException('SQL type "where exists" query is not valid for Elasticsearch. Use whereNotNull() or whereNull() to query the existence of a field');
    }

    public function _parseWhereNotExists(array $where)
    {
        throw new LogicException('SQL type "where exists" query is not valid for Elasticsearch. Use whereNotNull() or whereNull() to query the existence of a field');
    }

    /**
     * Set custom options for the query.
     *
     *
     * @return $this
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function pluck($column, $key = null): Collection
    {
        $results = $this->get($key === null ? [$column] : [$column, $key]);

        // Convert ObjectID's to strings
        if ($key == '_id') {
            $results = $results->map(function ($item) {
                $item['_id'] = (string) $item['_id'];

                return $item;
            });
        }

        $p = Arr::pluck($results, $column, $key);

        return new Collection($p);
    }

    /**
     * {@inheritdoc}
     */
    public function from($index, $as = null): static
    {

        if ($index) {
            $this->connection->setIndex($index);
            $this->index = $this->connection->getIndex();
        }

        return parent::from($index);
    }

    /**
     * {@inheritdoc}
     */
    public function truncate(): int
    {
        $result = $this->connection->deleteAll([]);

        if ($result->isSuccessful()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    public function deleteIndex(): bool
    {
        return Schema::connection($this->connection->getName())->delete($this->index);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id = null): int
    {

        if ($id !== null) {
            $this->where('_id', '=', $id);
        }

        return $this->_processDelete();
    }

    protected function _processDelete(): int
    {
        $wheres = $this->compileWheres();
        $options = $this->compileOptions();
        $result = $this->connection->deleteAll($wheres, $options);
        if ($result->isSuccessful()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    public function deleteIndexIfExists(): bool
    {
        return Schema::connection($this->connection->getName())->deleteIfExists($this->index);
    }

    public function getIndexMappings(): array
    {
        return Schema::connection($this->connection->getName())->getMappings($this->index);
    }

    public function getIndexSettings(): array
    {
        return Schema::connection($this->connection->getName())->getSettings($this->index);
    }

    public function createIndex(array $settings = []): bool
    {
        if (! $this->indexExists()) {
            $this->connection->indexCreate($settings);

            return true;
        }

        return false;
    }

    public function indexExists(): bool
    {
        return Schema::connection($this->connection->getName())->hasIndex($this->index);
    }

    public function rawSearch(array $bodyParams, $returnRaw = false): Results
    {
        return $this->connection->searchRaw($bodyParams, $returnRaw);
    }

    public function rawAggregation(array $bodyParams): Collection
    {
        $find = $this->connection->aggregationRaw($bodyParams);
        $data = $find->data;

        return new Collection($data);
    }

    public function toSql(): array
    {
        return $this->toDsl();
    }

    public function toDsl(): array
    {
        $wheres = $this->compileWheres();
        $options = $this->compileOptions();
        $columns = $this->prepareColumns([]);
        if ($this->searchQuery) {
            $searchParams = $this->searchQuery;
            $searchOptions = $this->searchOptions;
            $fields = $this->fields;

            return $this->connection->toDslForSearch($searchParams, $searchOptions, $wheres, $options, $fields, $columns);
        }

        return $this->connection->toDsl($wheres, $options, $columns);
    }

    /**
     * {@inheritdoc}
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException('The upsert feature for Elasticsearch is currently not supported. Please use updateAll()');
    }

    /**
     * {@inheritdoc}
     */
    public function groupByRaw($sql, array $bindings = [])
    {
        throw new LogicException('groupByRaw() is currently not supported');
    }

    public function matrix($column)
    {
        if (! is_array($column)) {
            $column = [$column];
        }
        $result = $this->aggregate(__FUNCTION__, $column);

        return $result ?: 0;
    }

    //----------------------------------------------------------------------
    // Collection bindings
    //----------------------------------------------------------------------

    public function searchQuery($term, $boostFactor = null, $clause = null, $type = 'term'): void
    {
        if (! $clause && ! empty($this->searchQuery)) {
            switch ($type) {
                case 'fuzzy':
                    throw new RuntimeException('Incorrect query sequencing, fuzzyTerm() should only start the ORM chain');
                case 'regex':
                    throw new RuntimeException('Incorrect query sequencing, regEx() should only start the ORM chain');
                case 'phrase':
                    throw new RuntimeException('Incorrect query sequencing, phrase() should only start the ORM chain');
                default:
                    throw new RuntimeException('Incorrect query sequencing, term() should only start the ORM chain');
            }
        }
        if ($clause && empty($this->searchQuery)) {
            switch ($type) {
                case 'fuzzy':
                    throw new RuntimeException('Incorrect query sequencing, andFuzzyTerm()/orFuzzyTerm() cannot start the ORM chain');
                case 'regex':
                    throw new RuntimeException('Incorrect query sequencing, andRegEx()/orRegEx() cannot start the ORM chain');
                case 'phrase':
                    throw new RuntimeException('Incorrect query sequencing, andPhrase()/orPhrase() cannot start the ORM chain');
                default:
                    throw new RuntimeException('Incorrect query sequencing, andTerm()/orTerm() cannot start the ORM chain');
            }
        }
        switch ($type) {
            case 'fuzzy':
                $nextTerm = '('.self::_escape($term).'~)';
                break;
            case 'regex':
                $nextTerm = '(/'.$term.'/)';
                break;
            case 'phrase':
                $nextTerm = '("'.self::_escape($term).'")';
                break;
            default:
                $nextTerm = '('.self::_escape($term).')';
                break;
        }

        if ($boostFactor) {
            $nextTerm .= '^'.$boostFactor;
        }
        if ($clause) {
            $this->searchQuery = $this->searchQuery.' '.strtoupper($clause).' '.$nextTerm;
        } else {
            $this->searchQuery = $nextTerm;
        }
    }

    //----------------------------------------------------------------------
    // Index/Schema
    //----------------------------------------------------------------------

    public function minShouldMatch($value): void
    {
        $this->searchOptions['minimum_should_match'] = $value;
    }

    public function minScore($value): void
    {
        $this->minScore = $value;
    }

    public function boostField($field, $factor): void
    {
        $this->fields[$field] = $factor ?? 1;
    }

    public function searchFields(array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($this->fields[$field])) {
                $this->fields[$field] = 1;
            }
        }
    }

    public function searchField($field, $boostFactor = null): void
    {
        $this->fields[$field] = $boostFactor ?? 1;
    }

    public function highlight(
        array $fields = [],
        string|array $preTag = '<em>',
        string|array $postTag = '</em>',
        array $globalOptions = []
    ): void {
        $highlightFields = [
            '*' => (object) [],
        ];
        if (! empty($fields)) {
            $highlightFields = [];
            foreach ($fields as $field => $payload) {
                if (is_int($field)) {
                    $highlightFields[$payload] = (object) [];
                } else {
                    $highlightFields[$field] = $payload;
                }
            }
        }
        if (! is_array($preTag)) {
            $preTag = [$preTag];
        }
        if (! is_array($postTag)) {
            $postTag = [$postTag];
        }

        $highlight = [];
        if ($globalOptions) {
            $highlight = $globalOptions;
        }
        $highlight['pre_tags'] = $preTag;
        $highlight['post_tags'] = $postTag;
        $highlight['fields'] = $highlightFields;

        $this->searchOptions['highlight'] = $highlight;
    }

    public function search($columns = '*'): Collection
    {

        $searchParams = $this->searchQuery;
        if (! $searchParams) {
            throw new RuntimeException('No search parameters. Add terms to search for.');
        }
        $searchOptions = $this->searchOptions;
        $wheres = $this->compileWheres();
        $options = $this->compileOptions();
        $fields = $this->fields;

        $search = $this->connection->search($searchParams, $searchOptions, $wheres, $options, $fields, $columns);
        if ($search->isSuccessful()) {
            $data = $search->data;

            return new Collection($data);
        } else {
            throw new RuntimeException('Error: '.$search->errorMessage);
        }
    }

    public function openPit($keepAlive = '5m'): string
    {
        return $this->connection->openPit($keepAlive);
    }

    public function pitFind(int $count, string $pitId, ?array $after = null, string $keepAlive = '5m'): Results
    {
        $wheres = $this->compileWheres();
        $options = $this->compileOptions();
        $fields = $this->fields;
        $options['limit'] = $count;

        return $this->connection->pitFind($wheres, $options, $fields, $pitId, $after, $keepAlive);
    }

    public function closePit($id): bool
    {
        return $this->connection->closePit($id);
    }
    //----------------------------------------------------------------------
    // Pagination overrides
    //----------------------------------------------------------------------

    protected function _parseWhereNested(array $where): array
    {

        $boolean = $where['boolean'];
        //        if ($boolean !== 'and') {
        //            throw new RuntimeException('Nested where clause with boolean other than "and" is not supported');
        //        }
        if ($boolean === 'and not') {
            $boolean = 'not';
        }
        $must = match ($boolean) {
            'and' => 'must',
            'not', 'or not' => 'must_not',
            'or' => 'should',
            default => throw new RuntimeException($boolean.' is not supported for parameter grouping'),
        };

        $query = $where['query'];
        $wheres = $query->compileWheres();

        return [
            $must => ['group' => ['wheres' => $wheres]],
        ];
    }

    protected function _parseWhereQueryNested(array $where): array
    {
        return [
            $where['column'] => [
                'innerNested' => [
                    'wheres' => $where['wheres'],
                    'options' => $where['options'],
                ],
            ],
        ];
    }

    protected function _parseWhereIn(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'];

        return [$column => ['in' => array_values($values)]];
    }

    protected function _parseWhereNotIn(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'];

        return [$column => ['nin' => array_values($values)]];
    }

    protected function _parseWhereNull(array $where): array
    {
        $where['operator'] = 'not_exists';
        $where['value'] = null;

        return $this->_parseWhereBasic($where);
    }

    //----------------------------------------------------------------------
    // Helpers
    //----------------------------------------------------------------------

    protected function _parseWhereBasic(array $where): array
    {
        $operator = $where['operator'];
        $column = $where['column'];
        $value = $where['value'];
        $boolean = $where['boolean'] ?? null;
        if ($boolean === 'and not') {
            $operator = '!=';
        }
        if ($boolean === 'or not') {
            $operator = '!=';
        }
        if ($operator === 'not like') {
            $operator = 'not_like';
        }

        if (! isset($operator) || $operator == '=') {
            $query = [$column => $value];
        } elseif (array_key_exists($operator, $this->conversion)) {
            $query = [$column => [$this->conversion[$operator] => $value]];
        } else {
            if (is_callable($column)) {
                throw new RuntimeException('Invalid closure for where clause');
            }
            $query = [$column => [$operator => $value]];
        }

        return $query;
    }

    /**
     * @return array
     */
    protected function _parseWhereNotNull(array $where)
    {
        $where['operator'] = 'exists';
        $where['value'] = null;

        return $this->_parseWhereBasic($where);
    }

    //----------------------------------------------------------------------
    // ES query executors
    //----------------------------------------------------------------------

    protected function _parseWhereBetween(array $where): array
    {
        $not = $where['not'] ?? false;
        $values = $where['values'];
        $column = $where['column'];

        if ($not) {
            return [
                $column => [
                    'not_between' => [$values[0], $values[1]],
                ],
            ];
        }

        return [
            $column => [
                'between' => [$values[0], $values[1]],
            ],
        ];
    }

    protected function _parseWhereDate(array $where): array
    {
        //return a normal where clause
        return $this->_parseWhereBasic($where);
    }

    //----------------------------------------------------------------------
    // ES Search query methods
    //----------------------------------------------------------------------

    protected function _parseWhereTimestamp(array $where): array
    {
        $where['value'] = $this->_formatTimestamp($where['value']);

        return $this->_parseWhereBasic($where);
    }

    private function _formatTimestamp($value): string|int
    {
        if (is_numeric($value)) {
            // Convert to integer in case it's a string
            $value = (int) $value;
            // Check for milliseconds
            if ($value > 10000000000) {
                return $value;
            }

            // ES expects seconds as a string
            return (string) Carbon::createFromTimestamp($value)->timestamp;
        }

        // If it's not numeric, assume it's a date string and try to return TS as a string
        try {
            return (string) Carbon::parse($value)->timestamp;
        } catch (\Exception $e) {
            throw new LogicException('Invalid date or timestamp');
        }
    }

    protected function _parseWhereMonth(array $where): array
    {
        throw new LogicException('whereMonth clause is not available yet');
    }

    protected function _parseWhereDay(array $where): array
    {
        throw new LogicException('whereDay clause is not available yet');
    }

    protected function _parseWhereYear(array $where): array
    {
        throw new LogicException('whereYear clause is not available yet');
    }

    protected function _parseWhereTime(array $where): array
    {
        throw new LogicException('whereTime clause is not available yet');
    }

    protected function _parseWhereRaw(array $where): array
    {
        throw new LogicException('whereRaw clause is not available yet');
    }

    protected function _parseWhereRegex(array $where): array
    {
        $value = $where['expression'];
        $column = $where['column'];

        return [$column => ['regex' => $value]];
    }

    //----------------------------------------------------------------------
    // PIT API
    //----------------------------------------------------------------------

    protected function _parseWhereNestedObject(array $where): array
    {
        $wheres = $where['wheres'];
        $column = $where['column'];
        $scoreMode = $where['score_mode'];

        return [
            $column => ['nested' => ['wheres' => $wheres, 'score_mode' => $scoreMode]],
        ];
    }

    protected function _parseWhereNotNestedObject(array $where): array
    {
        $wheres = $where['wheres'];
        $column = $where['column'];
        $scoreMode = $where['score_mode'];

        return [
            $column => ['not_nested' => ['wheres' => $wheres, 'score_mode' => $scoreMode]],
        ];
    }

    protected function runPaginationCountQuery($columns = ['*']): Closure|array
    {
        if ($this->distinctType) {
            $clone = $this->cloneForPaginationCount();
            $currentCloneCols = $clone->columns;
            if ($columns && $columns !== ['*']) {
                $currentCloneCols = array_merge($currentCloneCols, $columns);
            }

            return $clone->setAggregate('count', $currentCloneCols)->get()->all();
        }

        $without = $this->unions ? ['orders', 'limit', 'offset'] : ['columns', 'orders', 'limit', 'offset'];

        return $this->cloneWithout($without)->cloneWithoutBindings($this->unions ? ['order'] : [
            'select',
            'order',
        ])->setAggregate('count', $this->withoutSelectAliases($columns))->get()->all();
    }

    //----------------------------------------------------------------------
    // Helpers
    //----------------------------------------------------------------------

    public function all($columns = []): Collection
    {
        return $this->_processGet($columns);
    }
}
