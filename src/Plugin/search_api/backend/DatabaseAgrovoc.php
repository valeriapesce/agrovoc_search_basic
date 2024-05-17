<?php
namespace Drupal\agrovoc_search_basic\Plugin\search_api\backend;

// Same "use" directivs a in overridden class?
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database as CoreDatabase;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\Contrib\AutocompleteBackendInterface;
use Drupal\search_api\DataType\DataTypePluginManager;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\Field;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Plugin\search_api\data_type\value\TextToken;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\DataTypeHelper;
use Drupal\search_api_autocomplete\SearchInterface;
use Drupal\search_api_autocomplete\Suggestion\SuggestionFactory;
use Drupal\search_api_db\DatabaseCompatibility\DatabaseCompatibilityHandlerInterface;
use Drupal\search_api_db\DatabaseCompatibility\GenericDatabase;
use Drupal\search_api_db\DatabaseCompatibility\LocationAwareDatabaseInterface;
use Drupal\search_api_db\Event\QueryPreExecuteEvent;
use Drupal\search_api_db\Event\SearchApiDbEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

// Use also the oiginal class?
use Drupal\search_api_db\Plugin\search_api\backend\Database;


/**
 *  @SearchApiBackend(
 *   id = "search_api_db_agrovoc",
 *   label = @Translation("Agrovoc Database"),
 *   description = @Translation("Extends search api db to modify autocomplete suggestions.")
 * )
 */
class DatabaseAgrovoc extends Database {

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteSuggestions(QueryInterface $query, SearchInterface $search, string $incomplete_key, string $user_input): array {
    $settings = $this->configuration['autocomplete'];

    // Check if at least it enters: it doesn't!
    \Drupal::logger('agrovoc_search')->notice('Entered AGROVOC autocomplete override. If settings set: @i',
      array(
      '@i' => strval(array_filter($settings))
    ));	
    

    // If none of the options is checked, the user apparently chose a very
    // roundabout way of telling us they don't want autocompletion.
    if (!array_filter($settings)) {
      return [];
    }

    $index = $query->getIndex();
    $db_info = $this->getIndexDbInfo($index);
    if (empty($db_info['field_tables'])) {
      return [];
    }
    $fields = $this->getFieldInfo($index);

    $suggestions = [];
    $factory = new SuggestionFactory($user_input);
    $passes = [];
    $incomplete_like = NULL;

    // Make the input lowercase as the indexed data is (usually) also all
    // lowercase.
    $incomplete_key = mb_strtolower($incomplete_key);
    $user_input = mb_strtolower($user_input);

    // Decide which methods we want to use.
    if ($incomplete_key && $settings['suggest_suffix']) {
      $passes[] = 1;
      $incomplete_like = $this->database->escapeLike($incomplete_key) . '%';
    }
    if ($settings['suggest_words'] && (!$incomplete_key || strlen($incomplete_key) >= $this->configuration['min_chars'])) {
      $passes[] = 2;
    }

    if (!$passes) {
      return [];
    }

    // We want about half of the suggestions from each enabled method.
    $limit = $query->getOption('limit', 10);
    $limit /= count($passes);
    $limit = ceil($limit);

    // Also collect all keywords already contained in the query so we don't
    // suggest them.
    if ($query->getIndex()->isValidProcessor('tokenizer')) {
      $keys = array_filter(explode(' ', $user_input), 'strlen');
    }
    else {
      $keys = static::splitIntoWords($user_input);
    }
    $keys = array_combine($keys, $keys);

    foreach ($passes as $pass) {
      if ($pass == 2 && $incomplete_key) {
        $query->keys($user_input);
      }
      // To avoid suggesting incomplete words, we have to temporarily disable
      // partial matching. There should be no way we'll save the server during
      // the createDbQuery() call, so this should be safe.
      $configuration = $this->configuration;
      $db_query = NULL;
      try {
        $this->configuration['matching'] = 'words';
        $db_query = $this->createDbQuery($query, $fields);
        $this->configuration = $configuration;

        // We need a list of all current results to match the suggestions
        // against. However, since MySQL doesn't allow using a temporary table
        // multiple times in one query, we regrettably have to do it this way.
        $fulltext_fields = $this->getQueryFulltextFields($query);
        if (count($fulltext_fields) > 1) {
          $all_results = $db_query->execute()->fetchCol();
          // Compute the total number of results so we can later sort out
          // matches that occur too often.
          $total = count($all_results);
        }
        else {
          $table = $this->getTemporaryResultsTable($db_query);
          if (!$table) {
            return [];
          }
          $all_results = $this->database->select($table, 't')
            ->fields('t', ['item_id']);
          $sql = "SELECT COUNT(item_id) FROM {{$table}}";
          $total = $this->database->query($sql)->fetchField();
        }
      }
      catch (SearchApiException $e) {
        // If the exception was in createDbQuery(), we need to reset the
        // configuration here.
        $this->configuration = $configuration;
        $this->logException($e, '%type while trying to create autocomplete suggestions: @message in %function (line %line of %file).');
        continue;
      }
      $max_occurrences = $this->getConfigFactory()
        ->get('search_api_db.settings')
        ->get('autocomplete_max_occurrences');
      $max_occurrences = max(1, floor($total * $max_occurrences));

      if (!$total) {
        if ($pass == 1) {
          return [];
        }
        continue;
      }

      /** @var \Drupal\Core\Database\Query\SelectInterface|null $word_query */
      $word_query = NULL;
      foreach ($fulltext_fields as $field) {
        if (!isset($fields[$field]) || !$this->getDataTypeHelper()->isTextType($fields[$field]['type'])) {
          continue;
        }
        $field_query = $this->database->select($fields[$field]['table'], 't');
        $field_query->fields('t', ['word', 'item_id'])
          ->condition('t.field_name', $field)
          ->condition('t.item_id', $all_results, 'IN');
        if ($pass == 1) {
          $field_query
            //->condition('t.word', $keys, 'NOT IN') // Override of searchapi_db for AGROVOC module just to remove condition that excludes the word typed 
            ->condition('t.word', $incomplete_like, 'LIKE');
        }
        if (!isset($word_query)) {
          $word_query = $field_query;
        }
        else {
          $word_query->union($field_query);
        }
      }
      if (!$word_query) {
        return [];
      }
      $db_query = $this->database->select($word_query, 't');
      $db_query->addExpression('COUNT(DISTINCT [t].[item_id])', 'results');
      $db_query->fields('t', ['word'])
        // Exclude bigrams.
        ->condition('t.word', '% %', 'NOT LIKE')
        ->groupBy('t.word')
        ->having('COUNT(DISTINCT [t].[item_id]) <= :max', [':max' => $max_occurrences])
        ->orderBy('results', 'DESC')
        ->range(0, $limit);
      $incomplete_key_len = strlen($incomplete_key);
      foreach ($db_query->execute() as $row) {
        $suffix = ($pass == 1) ? substr($row->word, $incomplete_key_len) : ' ' . $row->word;
        $suggestions[] = $factory->createFromSuggestionSuffix($suffix, $row->results);
      }
    }

    return $suggestions;
  }
}
