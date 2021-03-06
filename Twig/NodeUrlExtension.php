<?php
/**
 * This file is part of Jarves.
 *
 * (c) Marc J. Schmidt <marc@marcjschmidt.de>
 *
 *     J.A.R.V.E.S - Just A Rather Very Easy [content management] System.
 *
 *     http://jarves.io
 *
 * To get the full copyright and license information, please view the
 * LICENSE file, that was distributed with this source code.
 */

namespace Jarves\Twig;

use Jarves\Jarves;
use Jarves\Model\Node;
use Jarves\PageStack;
use Jarves\Utils;

class NodeUrlExtension extends \Twig_Extension
{
    /**
     * @var PageStack
     */
    private $pageStack;

    /**
     * @param PageStack $pageStack
     */
    function __construct(PageStack $pageStack)
    {
        $this->pageStack = $pageStack;
    }


    public function getName()
    {
        return 'nodeUrl';
    }

    public function getFilters()
    {
        return array(
            'url' => new \Twig_SimpleFilter('url', [$this, 'getUrl'])
        );
    }

    public function getFunctions()
    {
        return array(
            'currentUrl' => new \Twig_SimpleFunction('currentUrl', [$this, 'getUrl']),
            'class_when_uri' => new \Twig_SimpleFunction('class_when_uri', [$this, 'classWhenUri']),
            'is_uri' => new \Twig_SimpleFunction('is_uri', [$this, 'isUri'])
        );
    }

    public function isUri($index, $expected = null)
    {
        $url = trim($this->pageStack->getRequest()->getPathInfo(), '/');
        $uris = explode('/', $url);

        $uri = isset($uris[$index]) ? $uris[$index] : null;

        return $uri === $expected;
    }

    public function classWhenUri($className, $index, $expected = null)
    {
        $url = trim($this->pageStack->getRequest()->getPathInfo(), '/');
        $uris = explode('/', $url);

        $uri = isset($uris[$index]) ? $uris[$index] : null;

        return $uri === $expected ? $className : '';
    }

    public function getUrl($nodeOrId = false)
    {
        return $this->pageStack->getNodeUrl($nodeOrId);
    }
}