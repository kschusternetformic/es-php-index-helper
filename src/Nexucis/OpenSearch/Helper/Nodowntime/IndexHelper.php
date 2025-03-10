<?php

namespace Nexucis\OpenSearch\Helper\Nodowntime;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\InvalidArgumentException;
use OpenSearch\Common\Exceptions\RuntimeException;
use Nexucis\OpenSearch\Helper\Nodowntime\Exceptions\IndexAlreadyExistException;
use Nexucis\OpenSearch\Helper\Nodowntime\Exceptions\IndexNotFoundException;
use Nexucis\OpenSearch\Helper\Nodowntime\Parameter\SearchParameter;
use stdClass;

/**
 * Class IndexHelper : This class can help you to manage your index with the alias management.
 * According to the official documentation https://www.elastic.co/guide/en/elasticsearch/guide/master/index-aliases.html,
 * alias management allow to use with no downtime your index.
 *
 * @category OpenSearch Helper
 * @package  Nexucis\OpenSearch\Helper\Nodowntime
 * @author   Augustin Husson <husson.augustin@gmail.com>
 * @license  MIT
 */
class IndexHelper implements IndexHelperInterface
{

    const INDEX_NAME_CONVENTION_1 = '_v1';
    const INDEX_NAME_CONVENTION_2 = '_v2';

    const RETURN_ACKNOWLEDGE = "ok";

    /**
     * @var Client
     */
    protected $client;

    /**
     * IndexHelper constructor.
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }


    /**
     * You can pass an alias name or an index name here.
     *
     * @param string $index [REQUIRED]
     * @return bool
     */
    public function existsIndex($index)
    {
        $params = array(
            'index' => $index,
        );

        return $this->client->indices()->exists($params);
    }


    /**
     * @param string $alias [REQUIRED]
     * @return void
     * @throws IndexAlreadyExistException
     */
    public function createIndexByAlias($alias)
    {
        $index = $alias . self::INDEX_NAME_CONVENTION_1;

        if ($this->existsIndex($index)) {
            throw new IndexAlreadyExistException($index);
        }

        $params = array(
            'index' => $index,
            'body' => array(
                'aliases' => array(
                    $alias => new stdClass()
                )),
        );

        $this->client->indices()->create($params);
    }

    /**
     * @param string $alias [REQUIRED]
     * @return void
     * @throws IndexNotFoundException
     */
    public function deleteIndexByAlias($alias)
    {
        if (!$this->existsIndex($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $params = array(
            'index' => $this->findIndexByAlias($alias)
        );

        $this->client->indices()->delete($params);
    }

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
    public function copyIndex($aliasSrc, $aliasDest, $refresh = false, $waitForCompletion = true)
    {
        if (!$this->existsAlias($aliasSrc)) {
            throw new IndexNotFoundException($aliasSrc);
        }

        if ($this->existsAlias($aliasDest)) {
            throw new IndexAlreadyExistException($aliasDest);
        }

        $indexSrc = $this->findIndexByAlias($aliasSrc);
        $indexDest = $aliasDest . self::INDEX_NAME_CONVENTION_1;


        $this->copyMappingAndSetting($indexSrc, $indexDest);

        // currently, the reindex api doesn't work when there are no documents inside the index source
        // So if there are some documents to copy and if the reindex Api send an error, we throw a RuntimeException
        if (!$this->indexIsEmpty($indexSrc)) {
            $response = $this->copyDocuments($indexSrc, $indexDest, $refresh, $waitForCompletion);

            if ($waitForCompletion) {
                if (!$response) {
                    $this->deleteIndex($indexDest);
                    throw new RuntimeException('reindex failed');
                }
            } else {
                // return the task ID
                return $response;
            }
        }

        $this->putAlias($aliasDest, $indexDest);

        return self::RETURN_ACKNOWLEDGE;
    }

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
    public function reindex($alias, $refresh = false, $needToCreateIndexDest = true, $waitForCompletion = true)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSrc = $this->findIndexByAlias($alias);
        $indexDest = $this->getIndexDest($alias, $indexSrc);


        if ($needToCreateIndexDest) { // for example, if you have updated your settings/mappings, your index_dest is already created. So you don't need to create it again
            if ($this->existsIndex($indexDest)) {
                $this->deleteIndex($indexDest);
            }

            $this->copyMappingAndSetting($indexSrc, $indexDest);
        }

        // currently, the reindex api doesn't work when there are no documents inside the index source
        // So if there are some documents to copy and if the reindex Api send an error, we throw a RuntimeException

        if (!$this->indexIsEmpty($indexSrc)) {
            $response = $this->copyDocuments($indexSrc, $indexDest, $refresh, $waitForCompletion);

            if ($waitForCompletion) {
                if (!$response) {
                    $this->deleteIndex($indexDest);
                    throw new RuntimeException('reindex failed');
                }
            } else {
                // return the task ID
                return $response;
            }
        }

        $this->switchIndex($alias, $indexSrc, $indexDest);
        $this->deleteIndex($indexSrc);

        return self::RETURN_ACKNOWLEDGE;
    }

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
    public function addSettings($alias, $settings)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        if (!is_array($settings) || count($settings) === 0) {
            throw new InvalidArgumentException("settings are empty, you are not allowed to add an empty array as the settings.");
        }

        $indexSource = $this->findIndexByAlias($alias);

        $this->closeIndex($indexSource);
        $params = array(
            'index' => $indexSource,
            'body' => array(
                'settings' => $settings
            )
        );

        $this->client->indices()->putSettings($params);

        $this->openIndex($indexSource);
    }

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
    public function updateSettings($alias, $settings, $refresh = false, $needReindexation = true, $waitForCompletion = true)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSrc = $this->findIndexByAlias($alias);
        $indexDest = $this->getIndexDest($alias, $indexSrc);

        if ($this->existsIndex($indexDest)) {
            $this->deleteIndex($indexDest);
        }

        $mapping = $this->getMappingsByIndex($indexSrc)[$indexSrc];
        $mappingSource = null;

        if (is_array($mapping) && array_key_exists('mappings', $mapping)) {
            $mappingSource = $mapping['mappings'];
        }

        $params = array(
            'index' => $indexDest,
        );

        if (is_array($settings) && count($settings) > 0) {
            $params['body'] = array(
                'settings' => $settings
            );
        }

        if (is_array($mappingSource) && (count($mappingSource) !== 0)) {
            $this->createBody($params);
            $params['body']['mappings'] = $mappingSource;
        }

        $result = $this->client->indices()->create($params);

        if ($result['acknowledged'] && $needReindexation) {
            return $this->reindex($alias, $refresh, false, $waitForCompletion);
        }

        return self::RETURN_ACKNOWLEDGE;
    }

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
    public function updateMappings($alias, $mapping, $refresh = false, $needReindexation = true, $waitForCompletion = true, $includeTypeName = true)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSrc = $this->findIndexByAlias($alias);
        $indexDest = $this->getIndexDest($alias, $indexSrc);
        if ($this->existsIndex($indexDest)) {
            $this->deleteIndex($indexDest);
        }

        $settings = $this->getSettingsByIndex($indexSrc)[$indexSrc]['settings']['index'];

        $params = array(
            'index' => $indexDest,
        );

        if (is_array($mapping) && count($mapping) > 0) {
            $params['body'] = array(
                'mappings' => $mapping,
            );
        }

        $this->copySettings($params, $settings);

        if ($includeTypeName) {
            $params['include_type_name'] = true;
        }

        $result = $this->client->indices()->create($params);

        if ($result['acknowledged'] && $needReindexation) {
            return $this->reindex($alias, $refresh, false, $waitForCompletion);
        }

        return self::RETURN_ACKNOWLEDGE;
    }

    /**
     * @return mixed[]
     */
    public function getListAlias()
    {
        $indices = $this->client->indices()->getAliases();
        $result = array();
        foreach ($indices as $index) {
            foreach ($index['aliases'] as $alias => $params_alias) {
                $result[] = $alias;
            }
        }
        return $result;
    }

    /**
     * @param string $alias [REQUIRED]
     * @return mixed[]
     * @throws IndexNotFoundException
     */
    public function getMappings($alias)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSource = $this->findIndexByAlias($alias);
        $mapping = $this->getMappingsByIndex($indexSource)[$indexSource];

        if (is_array($mapping) && array_key_exists('mappings', $mapping)) {
            return $mapping['mappings'];
        }

        return array();
    }

    /**
     * @param string $alias [REQUIRED]
     * @return mixed[]
     * @throws IndexNotFoundException
     */
    public function getSettings($alias)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSource = $this->findIndexByAlias($alias);
        return $this->getSettingsByIndex($indexSource)[$indexSource]['settings']['index'];
    }

    /**
     * @param string $alias [REQUIRED] the name of the index or the name of the alias
     * @param string $type [REQUIRED] the type of the document
     * @param string|int $id [REQUIRED] the document ID
     * @param bool $refresh
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function getDocument($alias, $type, $id, $refresh = false)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $params = array(
            'index' => $alias,
            'type' => $type,
            'id' => $id,
            'refresh' => $refresh
        );

        return $this->client->get($params);
    }

    /**
     * @param string $alias [REQUIRED]
     * @param int $from the offset from the first result you want to fetch (0 by default)
     * @param int $size allows you to configure the maximum amount of hits to be returned. (10 by default)
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function getAllDocuments($alias, $from = 0, $size = 10)
    {
        return $this->searchDocuments($alias, null, null, $from, $size);
    }

    /**
     * @param string $alias [REQUIRED]
     * @param mixed[]|null $query
     * @param string $type . This parameter will be removed in the next major release
     * @param int $from the offset from the first result you want to fetch (0 by default)
     * @param int $size allows you to configure the maximum amount of hits to be returned. (10 by default)
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function searchDocuments($alias, $query = null, $type = null, $from = 0, $size = 10)
    {
        $body = null;

        if (is_array($query)) {
            $body = array(
                'query' => $query
            );
        }

        return $this->advancedSearchDocument(
            $alias,
            $type,
            $body,
            (new SearchParameter())
                ->from($from)
                ->size($size)
        );
    }

    /**
     * @param string $alias [REQUIRED]
     * @param string $type . This parameter will be removed in the next major release
     * @param mixed[]|null $body
     * @param SearchParameter $searchParameter
     * @return callable|mixed[]
     * @throws IndexNotFoundException
     */
    public function advancedSearchDocument($alias, $type = null, $body = null, $searchParameter = null)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $params = array();

        if ($searchParameter !== null) {
            $params = $searchParameter->build();
        }

        $params['index'] = $alias;

        if ($type !== null) {
            $params['type'] = $type;
        }

        if (is_array($body)) {
            $params['body'] = $body;
        }

        return $this->client->search($params);
    }

    /**
     * @param string $index [REQUIRED] If the alias is associated to an unique index, you can pass an alias rather than an index
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]
     * @param mixed[] $body [REQUIRED] : actual document to update
     * @param bool $refresh wait until the result are visible to search
     * @return bool : true if the document has been updated. Otherwise, the document has been created.
     * @throws IndexNotFoundException
     */
    public function updateDocument($index, $id, $type, $body, $refresh = false)
    {
        if (!$this->existsIndex($index)) {
            throw new IndexNotFoundException($index);
        }
        return $this->indexDocument($index, $body, $type, $id) > 1;
    }

    /**
     * @param string $index [REQUIRED] If the alias is associated to an unique index, you can pass an alias rather than an index
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]
     * @param mixed[] $body [REQUIRED] : actual document to create
     * @param bool $refresh wait until the result are visible to search
     * @return bool : true if the document has been created.
     * @throws IndexNotFoundException
     */
    public function addDocument($index, $type, $body, $id = null, $refresh = false)
    {
        if (!$this->existsIndex($index)) {
            throw new IndexNotFoundException($index);
        }
        return $this->indexDocument($index, $body, $type, $id, $refresh) === 1;
    }

    /**
     * Remove all documents from the given index seen through its alias
     *
     * @param string $alias [REQUIRED]
     * @return void
     * @throws IndexNotFoundException
     */
    public function deleteAllDocuments($alias)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $indexSrc = $this->findIndexByAlias($alias);
        $indexDest = $this->getIndexDest($alias, $indexSrc);

        if ($this->existsIndex($indexDest)) {
            $this->deleteIndex($indexDest);
        }

        $this->copyMappingAndSetting($indexSrc, $indexDest);

        $this->switchIndex($alias, $indexSrc, $indexDest);

        $this->deleteIndex($indexSrc);
    }

    /**
     * @param string $alias [REQUIRED]
     * @param string $id [REQUIRED]
     * @param string $type [REQUIRED]
     * @param bool $refresh , Refresh the index after performing the operation
     * @return void
     * @throws IndexNotFoundException
     */
    public function deleteDocument($alias, $id, $type, $refresh = false)
    {
        if (!$this->existsAlias($alias)) {
            throw new IndexNotFoundException($alias);
        }

        $params = array(
            'index' => $alias,
            'type' => $type,
            'id' => $id,
            'refresh' => $refresh
        );

        $this->client->delete($params);
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param string $index
     * @param string|integer $id
     * @param mixed[] $body
     * @param string $type
     * @param bool $refresh
     * @return mixed
     */
    protected function indexDocument($index, $body, $type, $id = null, $refresh = false)
    {

        $params = array(
            'index' => $index,
            'type' => $type,
            'body' => $body,
            'refresh' => $refresh
        );

        if ($id !== null) {
            $params['id'] = $id;
        }

        $response = $this->client->index($params);

        return $response['_version'];
    }

    /**
     * @param string $index : index can put here [REQUIRED]
     * @return void
     */
    protected function deleteIndex($index)
    {
        $params = array(
            'index' => $index
        );

        $this->client->indices()->delete($params);
    }

    /**
     * @param string $index
     * @return void
     */
    protected function openIndex($index)
    {
        $params = array(
            'index' => $index
        );
        $this->client->indices()->open($params);
    }

    /**
     * @param string $index
     * @return void
     */
    protected function closeIndex($index)
    {
        $params = array(
            'index' => $index
        );
        $this->client->indices()->close($params);
    }

    /**
     * @param string $alias
     * @return string
     */
    protected function findIndexByAlias($alias)
    {
        $params = array(
            'name' => urlencode($alias)
        );
        return array_keys($this->client->indices()->getAlias($params))[0];
    }

    /**
     * @param string $index
     * @return bool : true if the index doesn't have any documents. False otherwise.
     */
    protected function indexIsEmpty($index)
    {
        return $this->countDocuments($index) == 0;
    }

    /**
     * @param string $index
     * @return int
     */
    protected function countDocuments($index)
    {
        $params = array(
            'index' => $index,
        );

        return $this->client->count($params)['count'];
    }

    /**
     * @param string $alias
     * @return bool
     */
    protected function existsAlias($alias)
    {
        $params = array(
            'name' => urlencode($alias)
        );

        return $this->client->indices()->existsAlias($params);
    }

    /**
     * @param string $indexSource
     * @param string $indexDest
     *
     * @return void
     */
    protected function copyMappingAndSetting($indexSource, $indexDest)
    {
        $params = array(
            'index' => $indexDest,
        );

        $mapping = $this->getMappingsByIndex($indexSource)[$indexSource];
        $mappingSource = null;

        if (is_array($mapping) && array_key_exists('mappings', $mapping)) {
            $mappingSource = $mapping['mappings'];
        }

        $settingSource = $this->getSettingsByIndex($indexSource)[$indexSource]['settings']['index'];

        if (is_array($mappingSource) && (count($mappingSource) !== 0)) {
            $this->createBody($params);
            $params['body']['mappings'] = $mappingSource;
        }

        $this->copySettings($params, $settingSource);

        $this->client->indices()->create($params);
    }

    /**
     * @param string[] $params
     * @param mixed[] $settings
     *
     * @return void
     */
    protected function copySettings(&$params, $settings)
    {
        $numberOfShards = null;
        $numberOfReplicas = null;
        $analysisSource = null;

        if (is_array($settings)) {
            if (array_key_exists('number_of_shards', $settings)) {
                $numberOfShards = $settings['number_of_shards'];
            }

            if (array_key_exists('number_of_replicas', $settings)) {
                $numberOfReplicas = $settings['number_of_replicas'];
            }

            if (array_key_exists('analysis', $settings)) {
                $analysisSource = $settings['analysis'];
            }
        }

        if ($numberOfShards !== null) {
            $this->createBody($params);

            $params['body']['settings'] = array(
                'number_of_shards' => $numberOfShards
            );
        }

        if ($numberOfReplicas !== null) {
            $this->createBody($params);

            if (!array_key_exists('settings', $params['body'])) {
                $params['body']['settings'] = array();
            }

            $params['body']['settings']['number_of_replicas'] = $numberOfReplicas;
        }

        if (is_array($analysisSource) && (count($analysisSource) !== 0)) {
            $this->createBody($params);

            if (!array_key_exists('settings', $params['body'])) {
                $params['body']['settings'] = array();
            }

            $params['body']['settings']['analysis'] = $analysisSource;
        }
    }

    /**
     * @param string[] $params
     * @return void
     */
    private function createBody(&$params)
    {
        if (!array_key_exists('body', $params)) {
            $params['body'] = array();
        }
    }

    /**
     * @param string $indexSrc
     * @param string $indexDest
     * @param string|bool $refresh wait until the result are visible to search
     * @param bool $waitForCompletion
     * @return bool | string
     */
    protected function copyDocuments($indexSrc, $indexDest, $refresh = false, $waitForCompletion = true)
    {
        $params = array(
            'body' => array(
                'source' => array(
                    'index' => $indexSrc
                ),
                'dest' => array(
                    'index' => $indexDest
                )
            ),
            'wait_for_completion' => $waitForCompletion,
            'refresh' => $refresh
        );

        $response = $this->client->reindex($params);

        if ($waitForCompletion) {
            return count($response['failures']) === 0;
        }
        // return the task ID
        return $response['task'];
    }

    /**
     * @param string $index
     * @return mixed[]
     */
    protected function getSettingsByIndex($index)
    {
        $params = array(
            'index' => $index
        );
        return $this->client->indices()->getSettings($params);
    }

    /**
     * @param string $index
     * @return mixed[]
     */
    protected function getMappingsByIndex($index)
    {
        $params = array(
            'index' => $index
        );
        return $this->client->indices()->getMapping($params);
    }

    /**
     * @param string $alias
     * @param string $indexSrc
     * @return string
     */
    protected function getIndexDest($alias, $indexSrc)
    {
        if ($alias . self::INDEX_NAME_CONVENTION_1 === $indexSrc) {
            return $alias . self::INDEX_NAME_CONVENTION_2;
        } else {
            return $alias . self::INDEX_NAME_CONVENTION_1;
        }
    }

    /**
     * @param string $alias
     * @param string $indexSrc
     * @param string $indexDest
     *
     * @return void
     */
    protected function switchIndex($alias, $indexSrc, $indexDest)
    {

        $params = array(
            'body' => array(
                'actions' => array(
                    0 => array(
                        'remove' => array(
                            'index' => $indexSrc,
                            'alias' => $alias),
                    ),
                    1 => array(
                        'add' => array(
                            'index' => $indexDest,
                            'alias' => $alias),
                    )
                ),
            ),
        );

        $this->client->indices()->updateAliases($params);
    }

    /**
     * @param string $alias
     * @param string $index
     *
     * @return void
     */
    protected function putAlias($alias, $index)
    {
        $params = array(
            'index' => $index,
            'name' => urlencode($alias)
        );

        $this->client->indices()->putAlias($params);
    }
}
