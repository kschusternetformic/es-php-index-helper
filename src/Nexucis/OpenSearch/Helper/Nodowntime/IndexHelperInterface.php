<?php

namespace Nexucis\OpenSearch\Helper\Nodowntime;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\InvalidArgumentException;
use Nexucis\OpenSearch\Helper\Nodowntime\Exceptions\IndexAlreadyExistException;
use Nexucis\OpenSearch\Helper\Nodowntime\Exceptions\IndexNotFoundException;
use Nexucis\OpenSearch\Helper\Nodowntime\Parameter\SearchParameter;

/**
 * Class IndexHelperInterface
 *
 * @category OpenSearch Helper
 * @package  Nexucis\OpenSearch\Helper\Nodowntime
 * @author   Augustin Husson <husson.augustin@gmail.com>
 * @license  MIT
 */
interface IndexHelperInterface
{

    /**
     * You can pass an alias name or an index name here.
     *
     * @param string $index [REQUIRED]
     * @return bool
     */
    public function existsIndex($index);

    /**
     * @param string $alias [REQUIRED]
     * @return void
     * @throws IndexAlreadyExistException
     */
    public function createIndexByAlias($alias);

    /**
     * @param string $alias [REQUIRED]
     * @return void
     * @throws IndexNotFoundException
     */
    public function deleteIndexByAlias($alias);

    /**
     * @param string $aliasSrc [REQUIRED]
     * @param string $aliasDest [REQUIRED]
     * @param string|bool $refresh wait until the result are visible to search
     * @param bool $waitForCompletion : According to the official documentation (https://www.elastic.co/guide/en/elasticsearch/reference/2.4/docs-reindex.html),
     * it is strongly advised to not set this parameter to false with ElasticSearch 2.4. In fact, it would be preferable to create an asynchronous process that executes this task.
     * If you set it to false, don't forget to put an alias to the new index when the corresponding task is gone.
     * @return string : the task ID if the parameter $waitForCompletion is set to false, acknowledge if not
     * @throws IndexAlreadyExistException
     * @throws IndexNotFoundException
     */
    public function copyIndex($aliasSrc, $aliasDest, $refresh = false, $waitForCompletion = true);

    /**
     * @param string $alias [REQUIRED]
     * @param string|bool $refresh wait until the result are visible to search
     * @param bool $needToCreateIndexDest
     * @param bool $waitForCompletion : According to the official documentation (https://www.elastic.co/guide/en/elasticsearch/reference/2.4/docs-reindex.html),
     * it is strongly advised to not set this parameter to false with ElasticSearch 2.4.
     * If you set it to false, don't forget to remove the old index and to switch the alias after the task is gone.
     * @return string : the task ID if the parameter $waitForCompletion is set to false, acknowledge if not
     * @throws IndexNotFoundException
     */
    public function reindex($alias, $refresh = false, $needToCreateIndexDest = true, $waitForCompletion = true);

    /**
     * This method must call when you want to add something inside the settings. Because the reindexation is a long task,
     * you should do the difference between add and delete something inside the settings. In the add task,
     * you don't need to reindex , unlike the delete task
     *
     * @param string $alias [REQUIRED]
     * @param mixed[] $settings [REQUIRED]
     * @return void
     * @throws IndexNotFoundException
     * @throws InvalidArgumentException
     */
    public function addSettings($alias, $settings);

    /**
     * This method must call when you want to delete something inside the settings.
     *
     * @param string $alias [REQUIRED]
     * @param mixed[] $settings [REQUIRED]
     * @param string|bool $refresh wait until the result are visible to search
     * @param bool $needReindexation : The process of reindexation can be so long, instead of calling reindex method inside this method,
     * you may want to call it in an asynchronous process.
     * But if you pass this parameters to false, don't forget to reindex. If you don't do it, you will not see your modification of the settings
     * @param bool $waitForCompletion : According to the official documentation (https://www.elastic.co/guide/en/elasticsearch/reference/2.4/docs-reindex.html),
     * it is strongly advised to not set this parameter to false with ElasticSearch 2.4.
     * If you set it to false, don't forget to remove the old index and to switch the alias after the task is gone.
     * @return string : the task ID if the parameter $waitForCompletion is set to false, acknowledge if not
     * @throws IndexNotFoundException
     */
    public function updateSettings($alias, $settings, $refresh = false, $needReindexation = true, $waitForCompletion = true);

    /**
     * This method must call whenever you want to add or delete something inside the mappings
     *
     * @param string $alias [REQUIRED]
     * @param mixed[] $mapping [REQUIRED]
     * @param string|bool $refresh wait until the result are visible to search
     * @param bool $needReindexation : The process of reindexation can be so long, instead of calling reindex method inside this method,
     * you may want to call it in an asynchronous process.
     * But if you pass this parameters to false, don't forget to reindex. If you don't do it, you will not see your modification of the mappings
     * @param bool $waitForCompletion : According to the official documentation (https://www.elastic.co/guide/en/elasticsearch/reference/2.4/docs-reindex.html),
     * it is strongly advised to not set this parameter to false with ElasticSearch 2.4.
     * If you set it to false, don't forget to remove the old index and to switch the alias after the task is gone.
     * @param bool $includeTypeName : Indicate if you still use a type in your index. To be ready for the next release (v8), you should consider to set this parameter to false.
     * Which means you have to change your mapping to remove the usage of the type. See more here: https://www.elastic.co/blog/moving-from-types-to-typeless-apis-in-elasticsearch-7-0
     * This parameter will be removed in the next release
     * @return string : the task ID if the parameter $waitForCompletion is set to false, acknowledge if not
     * @throws IndexNotFoundException
     */
    public function updateMappings($alias, $mapping, $refresh = false, $needReindexation = true, $waitForCompletion = true, $includeTypeName = true);

    /**
     * @return mixed[]
     */
    public function getListAlias();

    /**
     * @param string $alias [REQUIRED]
     * @return mixed[]
     */
    public function getMappings($alias);

    /**
     * @param string $alias [REQUIRED]
     * @return mixed[]
     */
    public function getSettings($alias);

    /**
     * @param string $alias [REQUIRED] the name of the index or the name of the alias
     * @param string $type [REQUIRED] the type of the document. This parameter will be removed in the next major release
     * @param string|int $id [REQUIRED] the document ID
     * @param bool $refresh
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function getDocument($alias, $type, $id, $refresh = false);

    /**
     * @param string $alias [REQUIRED]
     * @param int $from the offset from the first result you want to fetch (0 by default)
     * @param int $size allows you to configure the maximum amount of hits to be returned. (10 by default)
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function getAllDocuments($alias, $from = 0, $size = 10);

    /**
     * @param string $alias [REQUIRED]
     * @param mixed[]|null $query
     * @param string $type. This parameter will be removed in the next major release
     * @param int $from the offset from the first result you want to fetch (0 by default)
     * @param int $size allows you to configure the maximum amount of hits to be returned. (10 by default)
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function searchDocuments($alias, $query = null, $type = null, $from = 0, $size = 10);

    /**
     * @param string $alias
     * @param string $type. This parameter will be removed in the next major release
     * @param mixed[]|null $body
     * @param SearchParameter $searchParameter
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function advancedSearchDocument($alias, $type = null, $body = null, $searchParameter = null);

    /**
     * @param string $index [REQUIRED] If the alias is associated to an unique index, you can pass an alias rather than an index
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]. This parameter will be removed in the next major release
     * @param mixed[] $body [REQUIRED] : actual document to update
     * @param bool $refresh wait until the result are visible to search
     * @return bool : true if the document has been updated. Otherwise, the document has been created.
     * @throws IndexNotFoundException
     */
    public function updateDocument($index, $id, $type, $body, $refresh = false);

    /**
     * @param string $index [REQUIRED] If the alias is associated to an unique index, you can pass an alias rather than an index
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]. This parameter will be removed in the next major release
     * @param mixed[] $body [REQUIRED] : actual document to create
     * @param bool $refresh wait until the result are visible to search
     * @return bool : true if the document has been created.
     * @throws IndexNotFoundException
     */
    public function addDocument($index, $type, $body, $id = null, $refresh = false);

    /**
     * Remove all documents from the given index seen through its alias
     *
     * @param string $alias [REQUIRED]
     * @return void
     */
    public function deleteAllDocuments($alias);

    /**
     * @param string $alias [REQUIRED]
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]. This parameter will be removed in the next major release
     * @param bool $refresh , Refresh the index after performing the operation
     * @return void
     * @throws IndexNotFoundException
     */
    public function deleteDocument($alias, $id, $type, $refresh = false);

    /**
     * @return Client
     */
    public function getClient();
}
