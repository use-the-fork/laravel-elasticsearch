<?php

  declare(strict_types=1);

  namespace Workbench\App\Models;

  use Illuminate\Database\Eloquent\Relations\BelongsToMany;
  use PDPhilip\Elasticsearch\Eloquent\Model;
  use PDPhilip\Elasticsearch\Schema\IndexBlueprint;
  use PDPhilip\Elasticsearch\Schema\Schema;

  /**
   * @property string $title
   * @property string $author
   * @property array $chapters
   */
  class Skill extends Model
  {

    protected $connection = 'elasticsearch';
    protected $index = 'skills';
    protected static $unguarded = true;

    public function sqlUsers(): BelongsToMany
    {
      return $this->belongsToMany(SqlUser::class);
    }

    /**
     * Check if we need to run the schema.
     */
    public static function executeSchema()
    {
      $schema = Schema::connection('elasticsearch');

      $schema->deleteIfExists('skill_sql_user');
      $schema->create('skill_sql_user', function (IndexBlueprint $table) {
        $table->string('skill_ids');
        $table->string('sql_user_ids');
        $table->date('created_at');
        $table->date('updated_at');
      });

      $schema->deleteIfExists('skills');
      $schema->create('skills', function (IndexBlueprint $table) {
        $table->string('name');
        $table->date('created_at');
        $table->date('updated_at');
      });
    }

  }