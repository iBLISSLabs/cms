<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Connection;
use craft\db\pgsql\Schema;
use craft\db\Query;
use craft\enums\ColumnType;
use craft\events\SearchEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\Search as SearchHelper;
use craft\search\SearchQuery;
use craft\search\SearchQueryTerm;
use craft\search\SearchQueryTermGroup;
use Exception;
use yii\base\Component;

/**
 * Handles search operations.
 *
 * An instance of the Search service is globally accessible in Craft via [[Application::search `Craft::$app->getSearch()`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Search extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SearchEvent The event that is triggered before a search is performed.
     */
    const EVENT_BEFORE_SEARCH = 'beforeSearch';

    /**
     * @event SearchEvent The event that is triggered after a search is performed.
     */
    const EVENT_AFTER_SEARCH = 'afterSearch';

    // Properties
    // =========================================================================

    /**
     * @var
     */
    private $_tokens;

    /**
     * @var
     */
    private $_terms;

    /**
     * @var
     */
    private $_groups;

    // Public Methods
    // =========================================================================

    /**
     * Indexes the attributes of a given element defined by its element type.
     *
     * @param ElementInterface $element
     *
     * @return boolean Whether the indexing was a success.
     */
    public function indexElementAttributes(ElementInterface $element)
    {
        /** @var Element $element */
        // Does it have any searchable attributes?
        $searchableAttributes = $element::searchableAttributes();

        $searchableAttributes[] = 'slug';

        if ($element::hasTitles()) {
            $searchableAttributes[] = 'title';
        }

        foreach ($searchableAttributes as $attribute) {
            $value = $element->$attribute;
            $value = StringHelper::toString($value);
            $this->_indexElementKeywords($element->id, $attribute, '0', $element->siteId, $value);
        }

        return true;
    }

    /**
     * Indexes the field values for a given element and site.
     *
     * @param integer $elementId The ID of the element getting indexed.
     * @param integer $siteId    The site ID of the content getting indexed.
     * @param array   $fields    The field values, indexed by field ID.
     *
     * @return boolean  Whether the indexing was a success.
     */
    public function indexElementFields($elementId, $siteId, $fields)
    {
        foreach ($fields as $fieldId => $value) {
            $this->_indexElementKeywords($elementId, 'field', (string)$fieldId, $siteId, $value);
        }

        return true;
    }

    /**
     * Filters a list of element IDs by a given search query.
     *
     * @param integer[]          $elementIds   The list of element IDs to filter by the search query.
     * @param string|SearchQuery $query        The search query (either a string or a SearchQuery instance)
     * @param boolean            $scoreResults Whether to order the results based on how closely they match the query.
     * @param integer            $siteId       The site ID to filter by.
     * @param boolean            $returnScores Whether the search scores should be included in the results. If true, results will be returned as `element ID => score`.
     *
     * @return array The filtered list of element IDs.
     */
    public function filterElementIdsByQuery($elementIds, $query, $scoreResults = true, $siteId = null, $returnScores = false)
    {
        if (is_string($query)) {
            $query = new SearchQuery($query, Craft::$app->getConfig()->get('defaultSearchTermOptions'));
        } else if (is_array($query)) {
            $options = $query;
            $query = $options['query'];
            unset($options['query']);
            $options = array_merge(Craft::$app->getConfig()->get('defaultSearchTermOptions'), $options);
            $query = new SearchQuery($query, $options);
        }

        // Fire a 'beforeSearch' event
        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
            'elementIds' => $elementIds,
            'query' => $query,
            'siteId' => $siteId,
        ]));

        // Get tokens for query
        $this->_tokens = $query->getTokens();
        $this->_terms = [];
        $this->_groups = [];

        // Set Terms and Groups based on tokens
        foreach ($this->_tokens as $obj) {
            if ($obj instanceof SearchQueryTermGroup) {
                $this->_groups[] = $obj->terms;
            } else {
                $this->_terms[] = $obj;
            }
        }

        // Get where clause from tokens, bail out if no valid query is there
        $where = $this->_getWhereClause($siteId);

        if (!$where) {
            return [];
        }

        if ($siteId) {
            $where .= sprintf(' AND %s = %s', Craft::$app->getDb()->quoteColumnName('siteId'), Craft::$app->getDb()->quoteValue($siteId));
        }

        // Begin creating SQL
        $sql = sprintf('SELECT * FROM %s WHERE %s', Craft::$app->getDb()->quoteTableName('{{%searchindex}}'), $where);

        // Append elementIds to QSL
        if ($elementIds) {
            $sql .= sprintf(' AND %s IN (%s)',
                Craft::$app->getDb()->quoteColumnName('elementId'),
                implode(',', $elementIds)
            );
        }

        // Execute the sql
        $results = Craft::$app->getDb()->createCommand($sql)->queryAll();

        // Are we scoring the results?
        if ($scoreResults) {
            $scoresByElementId = [];

            // Loop through results and calculate score per element
            foreach ($results as $row) {
                $elementId = $row['elementId'];
                $score = $this->_scoreRow($row);

                if (!isset($scoresByElementId[$elementId])) {
                    $scoresByElementId[$elementId] = $score;
                } else {
                    $scoresByElementId[$elementId] += $score;
                }
            }

            // Sort found elementIds by score
            arsort($scoresByElementId);

            if ($returnScores) {
                return $scoresByElementId;
            }

            // Just return the ordered element IDs
            return array_keys($scoresByElementId);
        }

        // Don't apply score, just return the IDs
        $elementIds = [];

        foreach ($results as $row) {
            $elementIds[] = $row['elementId'];
        }

        $elementIds = array_unique($elementIds);

        // Fire a 'beforeSearch' event
        $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
            'elementIds' => $elementIds,
            'query' => $query,
            'siteId' => $siteId,
        ]));

        return $elementIds;
    }

    // Private Methods
    // =========================================================================

    /**
     * Indexes keywords for a specific element attribute/field.
     *
     * @param integer      $elementId
     * @param string       $attribute
     * @param string       $fieldId
     * @param integer|null $siteId
     * @param string       $dirtyKeywords
     *
     * @return void
     */
    private function _indexElementKeywords($elementId, $attribute, $fieldId, $siteId, $dirtyKeywords)
    {
        $attribute = StringHelper::toLowerCase($attribute);

        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        // Clean 'em up
        $cleanKeywords = SearchHelper::normalizeKeywords($dirtyKeywords);

        // Save 'em
        $keyColumns = [
            'elementId' => $elementId,
            'attribute' => $attribute,
            'fieldId' => $fieldId,
            'siteId' => $siteId
        ];

        if ($cleanKeywords !== null && $cleanKeywords !== false && $cleanKeywords !== '') {
            // Add padding around keywords
            $cleanKeywords = ' '.$cleanKeywords.' ';
        }

        $cleanKeywordsLength = strlen($cleanKeywords);

        $maxDbColumnSize = Db::getTextualColumnStorageCapacity(ColumnType::Text);

        if ($maxDbColumnSize) {
            // Give ourselves 10% wiggle room.
            $maxDbColumnSize = ceil($maxDbColumnSize * 0.9);

            if ($cleanKeywordsLength > $maxDbColumnSize) {
                // Time to truncate.
                $cleanKeywords = mb_strcut($cleanKeywords, 0, $maxDbColumnSize);

                // Make sure we don't cut off a word in the middle.
                if ($cleanKeywords[mb_strlen($cleanKeywords) - 1] !== ' ') {
                    $position = mb_strrpos($cleanKeywords, ' ');

                    if ($position) {
                        $cleanKeywords = mb_substr($cleanKeywords, 0, $position + 1);
                    }
                }
            }
        }

        $keywordColumns = ['keywords' => $cleanKeywords];

        // PostgreSQL?
        if (Craft::$app->getDb()->getDriverName() == Connection::DRIVER_PGSQL) {
            $keywordColumns['keywords_vector'] = $cleanKeywords;
        }

        // Insert/update the row in searchindex
        Craft::$app->getDb()->createCommand()
            ->upsert(
                '{{%searchindex}}',
                $keyColumns,
                $keywordColumns,
                false)
            ->execute();
    }

    /**
     * Calculate score for a result.
     *
     * @param array $row A single result from the search query.
     *
     * @return float The total score for this row.
     */
    private function _scoreRow($row)
    {
        // Starting point
        $score = 0;

        // Loop through AND-terms and score each one against this row
        foreach ($this->_terms as $term) {
            $score += $this->_scoreTerm($term, $row);
        }

        // Loop through each group of OR-terms
        foreach ($this->_groups as $terms) {
            // OR-terms are weighted less depending on the amount of OR terms in the group
            $weight = 1 / count($terms);

            // Get the score for each term and add it to the total
            foreach ($terms as $term) {
                $score += $this->_scoreTerm($term, $row, $weight);
            }
        }

        return $score;
    }

    /**
     * Calculate score for a row/term combination.
     *
     * @param  object    $term   The SearchQueryTerm to score.
     * @param  array     $row    The result row to score against.
     * @param  float|int $weight Optional weight for this term.
     *
     * @return float The total score for this term/row combination.
     */
    private function _scoreTerm($term, $row, $weight = 1)
    {
        // Skip these terms: siteId and exact filtering is just that, no weighted search applies since all elements will
        // already apply for these filters.
        if (
            $term->attribute == 'site' ||
            $term->exact ||
            !($keywords = $this->_normalizeTerm($term->term))
        ) {
            return 0;
        }

        // Account for substrings
        if (!$term->subLeft) {
            $keywords = ' '.$keywords;
        }

        if (!$term->subRight) {
            $keywords = $keywords.' ';
        }

        // Get haystack and safe word count
        $haystack = $row['keywords'];
        $wordCount = count(array_filter(explode(' ', $haystack)));

        // Get number of matches
        $score = StringHelper::countSubstrings($haystack, $keywords);

        if ($score) {
            // Exact match
            if (trim($keywords) == trim($haystack)) {
                $mod = 100;
            } // Don't scale up for substring matches
            else if ($term->subLeft || $term->subRight) {
                $mod = 10;
            } else {
                $mod = 50;
            }

            // If this is a title, 5X it
            if ($row['attribute'] == 'title') {
                $mod *= 5;
            }

            $score = ($score / $wordCount) * $mod * $weight;
        }

        return $score;
    }

    /**
     * Get the complete where clause for current tokens
     *
     * @param integer|null $siteId The site ID to search within
     *
     * @return string|false
     */
    private function _getWhereClause($siteId = null)
    {
        $where = [];

        // Add the regular terms to the WHERE clause
        if ($this->_terms) {
            $condition = $this->_processTokens($this->_terms, true, $siteId);

            if ($condition === false) {
                return false;
            }

            $where[] = $condition;
        }

        // Add each group to the where clause
        foreach ($this->_groups as $group) {
            $condition = $this->_processTokens($group, false, $siteId);

            if ($condition === false) {
                return false;
            }

            $where[] = $condition;
        }

        // And combine everything with AND
        return implode(' AND ', $where);
    }

    /**
     * Generates partial WHERE clause for search from given tokens
     *
     * @param array        $tokens
     * @param boolean      $inclusive
     * @param integer|null $siteId
     *
     * @return string|false
     */
    private function _processTokens($tokens = [], $inclusive = true, $siteId = null)
    {
        $andOr = $inclusive ? ' AND ' : ' OR ';
        $where = [];
        $words = [];

        foreach ($tokens as $obj) {
            // Get SQL and/or keywords
            list($sql, $keywords) = $this->_getSqlFromTerm($obj, $siteId);

            if ($sql === false && $inclusive) {
                return false;
            }

            // If we have SQL, just add that
            if ($sql) {
                $where[] = $sql;
            } // No SQL but keywords, save them for later
            else if ($keywords) {
                if ($inclusive) {
                    if (Craft::$app->getDb()->getDriverName() == Connection::DRIVER_MYSQL) {
                        $keywords = '+'.$keywords;
                    }
                }

                $words[] = $keywords;
            }
        }

        // If we collected full-text words, combine them into one
        if ($words) {
            $where[] = $this->_sqlFullText($words, true, $andOr);
        }

        // If we have valid where clauses now, stringify them
        if (!empty($where)) {
            // Implode WHERE clause to a string
            $where = implode($andOr, $where);

            // And group together for non-inclusive queries
            if (!$inclusive) {
                $where = "({$where})";
            }
        } else {
            // If the tokens didn't produce a valid where clause,
            // make sure we return false
            $where = false;
        }

        return $where;
    }

    /**
     * Generates a piece of WHERE clause for fallback (LIKE) search from search term
     * or returns keywords to use in a full text search clause
     *
     * @param SearchQueryTerm $term
     * @param integer|null    $siteId
     *
     * @return array
     * @throws Exception
     */
    private function _getSqlFromTerm(SearchQueryTerm $term, $siteId = null)
    {
        // Initiate return value
        $sql = null;
        $keywords = null;
        $driver = Craft::$app->getDb()->getDriverName();

        // Check for site first
        if ($term->attribute == 'site') {
            if (is_numeric($term->attribute)) {
                $siteId = $term->attribute;
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($term->attribute);
                if ($site) {
                    $siteId = $site->id;
                } else {
                    $siteId = 0;
                }
            }
            $oper = $term->exclude ? '!=' : '=';

            return [
                $this->_sqlWhere($siteId, $oper, $term->term), $keywords
            ];
        }

        // Check for other attributes
        if (!is_null($term->attribute)) {
            // Is attribute a valid fieldId?
            $fieldId = $this->_getFieldIdFromAttribute($term->attribute);

            if ($fieldId) {
                $attr = 'fieldId';
                $val = $fieldId;
            } else {
                $attr = 'attribute';
                $val = $term->attribute;
            }

            // Use subselect for attributes
            $subSelect = $this->_sqlWhere($attr, '=', $val);
        } else {
            $subSelect = null;
        }

        // Sanitize term
        if ($term->term !== null) {
            $keywords = $this->_normalizeTerm($term->term);

            // Make sure that it didn't result in an empty string (e.g. if they entered '&')
            // unless it's meant to search for *anything* (e.g. if they entered 'attribute:*').
            if ($keywords !== '' || $term->subLeft) {
                // If we're on PostgreSQL and this is a phrase or exact match, we have to special case it.
                if ($driver == Connection::DRIVER_PGSQL && $term->phrase) {
                    $sql = $this->_sqlPhraseExactMatch($keywords);
                } else {

                    // Create fulltext clause from term
                    if ($this->_doFullTextSearch($keywords, $term)) {
                        if ($term->subRight) {
                            switch ($driver) {
                                case Connection::DRIVER_MYSQL:
                                    $keywords .= '*';
                                    break;
                                case Connection::DRIVER_PGSQL:
                                    $keywords .= ':*';
                                    break;
                                default:
                                    throw new Exception('Unsupported connection type: '.$driver);
                            }
                        }

                        // Add quotes for exact match
                        if ($driver == Connection::DRIVER_MYSQL && StringHelper::contains($keywords, ' ')) {
                            $keywords = '"'.$keywords.'"';
                        }

                        // Determine prefix for the full-text keyword
                        if ($term->exclude) {
                            $keywords = '-'.$keywords;
                        }

                        // Only create an SQL clause if there's a subselect. Otherwise, return the keywords.
                        if ($subSelect) {
                            // If there is a subselect, create the full text SQL bit
                            $sql = $this->_sqlFullText($keywords);
                        }
                    } // Create LIKE clause from term
                    else {
                        if ($term->exact) {
                            // Create exact clause from term
                            $operator = $term->exclude ? 'NOT LIKE' : 'LIKE';

                            switch ($driver) {
                                case Connection::DRIVER_MYSQL:
                                    $keywords = ($term->subLeft ? '%' : ' ').$keywords.($term->subRight ? '%' : ' ');
                                    break;
                                case Connection::DRIVER_PGSQL:
                                    if ($term->subLeft) {
                                        $keywords = '%'.$keywords;
                                    }

                                    if ($term->subRight) {
                                        $keywords = $keywords.'%';
                                    }
                                    break;
                                    break;
                                default:
                                    throw new Exception('Unsupported connection type: '.$driver);
                            }
                        } else {
                            // Create LIKE clause from term
                            $operator = $term->exclude ? 'NOT LIKE' : 'LIKE';
                            $keywords = ($term->subLeft ? '%' : '% ').$keywords.($term->subRight ? '%' : ' %');
                        }

                        // Generate the SQL
                        $sql = $this->_sqlWhere('keywords', $operator, $keywords);
                    }
                }
            }
        } else {
            // Support for attribute:* syntax to just check if something has *any* keyword value.
            if ($term->subLeft) {
                $sql = $this->_sqlWhere('keywords', '!=', '');
            }
        }

        // If we have a where clause in the subselect, add the keyword bit to it.
        if ($subSelect && $sql) {
            $sql = $this->_sqlSubSelect($subSelect.' AND '.$sql, $siteId);

            // We need to reset keywords even if the subselect ended up in no results.
            $keywords = null;
        }

        return [$sql, $keywords];
    }

    /**
     * Normalize term from tokens, keep a record for cache.
     *
     * @param string $term
     *
     * @return string
     */
    private function _normalizeTerm($term)
    {
        static $terms = [];

        if (!array_key_exists($term, $terms)) {
            $terms[$term] = SearchHelper::normalizeKeywords($term);
        }

        return $terms[$term];
    }

    /**
     * Get the fieldId for given attribute or 0 for unmatched.
     *
     * @param string $attribute
     *
     * @return integer
     */
    private function _getFieldIdFromAttribute($attribute)
    {
        // Get field id from service
        /** @var Field $field */
        $field = Craft::$app->getFields()->getFieldByHandle($attribute);

        // Fallback to 0
        return ($field) ? $field->id : 0;
    }

    /**
     * Get SQL bit for simple WHERE clause
     *
     * @param string $key  The attribute.
     * @param string $oper The operator.
     * @param string $val  The value.
     *
     * @return string
     */
    private function _sqlWhere($key, $oper, $val)
    {
        $key = Craft::$app->getDb()->quoteColumnName($key);

        return sprintf("(%s %s '%s')", $key, $oper, $val);
    }

    /**
     * Get SQL necessary for a full text search.
     *
     * @param mixed   $val   String or Array of keywords
     * @param boolean $bool  Use In Boolean Mode or not
     * @param string  $andOr If multiple values are passed in as an array, whether to AND or OR then.
     *
     * @return string
     * @throws Exception
     */
    private function _sqlFullText($val, $bool = true, $andOr = ' AND ')
    {
        $driver = Craft::$app->getDb()->getDriverName();
        switch ($driver)
        {
            case Connection::DRIVER_MYSQL:
                return sprintf("MATCH(%s) AGAINST('%s'%s)", Craft::$app->getDb()->quoteColumnName('keywords'), (is_array($val) ? implode(' ', $val) : $val), ($bool ? ' IN BOOLEAN MODE' : ''));

            case Connection::DRIVER_PGSQL:
                if ($andOr == ' AND ') {
                    $andOr = ' & ';
                } else {
                    $andOr = ' | ';
                }

                if (is_array($val)) {
                    foreach ($val as $key => $value) {
                        if (StringHelper::contains($value, ' ')) {
                            $temp = explode(' ', $val[$key]);
                            $temp = implode(' & ', $temp);
                            $val[$key] = $temp;
                        }
                    }
                }

                return sprintf("%s @@ '%s'::tsquery", Craft::$app->getDb()->quoteColumnName('keywords_vector'), (is_array($val) ? implode($andOr, $val) : $val));

            default:
                throw new Exception('Unsupported connection type: '.$driver);
        }

    }

    /**
     * Get SQL bit for sub-selects.
     *
     * @param string       $where
     * @param integer|null $siteId
     *
     * @return string|false
     */
    private function _sqlSubSelect($where, $siteId = null)
    {
        $query = (new Query())
            ->select(['elementId'])
            ->from(['{{%searchindex}}'])
            ->where($where);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $elementIds = $query->column();

        if ($elementIds) {
            return Craft::$app->getDb()->quoteColumnName('elementId').' IN ('.implode(', ', $elementIds).')';
        }

        return false;
    }

    /**
     * Whether or not to do a full text search or not.
     *
     * @param string          $keywords
     * @param SearchQueryTerm $term
     *
     * @return bool
     */
    private function _doFullTextSearch($keywords, SearchQueryTerm $term)
    {
        if ($keywords !== '' && !$term->subLeft && !$term->exact && !$term->exclude) {
            return true;
        }

        return false;
    }

    /**
     * This method will return PostgreSQL specific SQL necessary to find an exact phrase search.
     *
     * @param string $val The phrase or exact value to search for.
     *
     * @return string The SQL to perform the search.
     */
    private function _sqlPhraseExactMatch($val)
    {
        $ftVal = explode(' ', $val);
        $ftVal = implode(' & ', $ftVal);
        $likeVal = '%'.$val.'%';

        return sprintf("%s @@ '%s'::tsquery AND %s LIKE '%s'", Craft::$app->getDb()->quoteColumnName('keywords_vector'), $ftVal, Craft::$app->getDb()->quoteColumnName('keywords'), $likeVal);
    }
}