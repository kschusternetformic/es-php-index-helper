<?php

namespace Nexucis\OpenSearch\Helper\Nodowntime\Exceptions;

use OpenSearch\Common\Exceptions\OpenSearchException;

/**
 * IndexAlreadyExistException thrown when an index already exists
 *
 * @category OpenSearch Helper
 * @package  Nexucis\OpenSearch\Helper\Nodowntime\Exceptions
 * @author   Augustin Husson <husson.augustin@gmail.com>
 * @license  MIT
 */
class IndexAlreadyExistException extends \Exception implements OpenSearchException
{

    /**
     * @var string
     */
    private $index;

    /**
     * IndexNotFoundException constructor.
     * @param string $alias
     */
    public function __construct($alias)
    {
        $this->index = $alias;
        parent::__construct(sprintf('index %s already exists', $this->index));
    }
}
