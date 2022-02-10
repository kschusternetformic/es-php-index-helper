<?php

namespace Nexucis\OpenSearch\Helper\Nodowntime\Exceptions;

use OpenSearch\Common\Exceptions\OpenSearchException;

/**
 * IndexNotFoundException thrown when an index is not found
 *
 * @category OpenSearch Helper
 * @package  Nexucis\OpenSearch\Helper\Nodowntime\Exceptions
 * @author   Augustin Husson <husson.augustin@gmail.com>
 * @license  MIT
 */
class IndexNotFoundException extends \Exception implements OpenSearchException
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
        parent::__construct(sprintf('index %s not found', $this->index));
    }
}
