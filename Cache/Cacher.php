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

namespace Jarves\Cache;

use Jarves\Cache\Backend\AbstractCache;
use Jarves\StopwatchHelper;

class Cacher
{
    /**
     * @var AbstractCache
     */
    protected $fastCache;

    /**
     * @var AbstractCache
     */
    protected $distributedCache;

    /**
     * @var StopwatchHelper
     */
    protected $stopwatch;

    /**
     * @param StopwatchHelper $stopwatch
     * @param $distributedCache
     * @param $fastCache
     */
    public function __construct(StopwatchHelper $stopwatch, AbstractCache $distributedCache, AbstractCache $fastCache)
    {
        $this->stopwatch = $stopwatch;
        $this->distributedCache = $distributedCache;
        $this->fastCache = $fastCache;
    }

    /**
     * Marks a code as invalidate beginning at $time.
     * This is the distributed cache controller. Use it if you want
     * to invalidate caches on a distributed backend (`setDistributedCache()` and getDistributedCache()).
     *
     * You don't have to define the full key, instead you can pass only the starting part of the key.
     * This means, if you have following caches defined:
     *
     *   - news/list/2
     *   - news/list/3
     *   - news/list/4
     *   - news/comments/134
     *   - news/comments/51
     *
     * you can mark all listing caches as invalid by calling
     *   - invalidateCache('news/list');
     *
     * or mark all caches as invalid which starts with `news/` you can call:
     *   - invalidateCache('news');
     *
     *
     * The invalidation mechanism explodes the key by / and checks all levels whether they're marked
     * as invalid (through a microsecond timestamp) or not.
     *
     * Default is $time is `mark all caches as invalid which are older than CURRENT`.
     *
     * This method is called by the Jarves\Configuration\Event::$clearCaches configuration
     *
     * @param  string $key
     * @param  integer $time Unix timestamp. Default is microtime(true). Uses float for ms.
     *
     * @return boolean
     */
    public function invalidateCache($key, $time = null)
    {
        return $this->distributedCache->invalidate($key, $time ?: microtime(true));
    }

    /**
     * Returns latest invalidation timestamp for the given $key.
     *
     * Returns an timestamp as integer which tells the cache handler that all stored caches
     * before this timestamp are automatically invalide.
     *
     * Returns null when no invalidation has set yet, means also that the cache with given key
     * is valid.
     *
     * @return integer|null
     */
    public function isCacheIsValid($key, $timestamp)
    {
        $parents = explode('/', $key);
        $code = '';
        foreach ($parents as $parent) {
            $code .= $parent;
            $invalidateTime = $this->distributedCache->getInvalidate($code);
            if (null !== $invalidateTime && $invalidateTime >= $timestamp) {
                //we found a invalidation that is newer than the cache of $timestamp
                //this means this cache has been invalidated.
                return false;
            }
            $code .= '/';
        }

        return true;
    }

    /**
     * Returns a distributed cache value.
     *
     * This uses cache invalidation mechanism described in
     *
     * @see setDistributedCache() for more information invalidateCache().
     *
     * @param string $key
     *
     * @return mixed null when not found
     */
    public function getDistributedCache($key)
    {
        $this->stopwatch->start(sprintf('Get Cache `%s`', $key));

        $cache = $this->fastCache->get($key);

        if (null === $cache) {
            $this->stopwatch->stop(sprintf('Get Cache `%s`', $key));
            return null;
        }

        if (!is_array($cache) || !isset($cache['timestamp'])) {
            throw new \RuntimeException(sprintf(
                'You requested a cache through the distributed cache mechanism, which was not ' .
                'set through this mechanism. You can only distributed cache when you set the cache through the' .
                'distributed cache. [%s]', substr($cache, 0, 50)));
        }

        $isValid = $this->isCacheIsValid($key, $cache['timestamp']);

        $this->stopwatch->stop(sprintf('Get Cache `%s`', $key));

        return $isValid ? $cache['data'] : null;
    }

    /**
     * Deletes a distributed cache
     *
     * @param string $key
     */
    public function deleteDistributedCache($key)
    {
        $this->fastCache->delete($key);

        //whe need to invalidate this cache, so other jarves servers refresh their cache
        $this->distributedCache->invalidate($key);
    }

    /**
     * Sets a local cache using very fast cache techniques like apc_store or php arrays.
     *
     * Does not use cache invalidation mechanism.
     *
     * Notes: Not practical for load balances or php-pm scenarios. Not even practical when
     * you use PHP in PHP-FPM, because there are several php instances as well which do
     * nothing know about cache refreshing in this `fast cache`. Is only useful for configuration
     * purposes at bootstrap or in combination with the invalidation mechanism (which is then
     * the same as if you would call setDistributedCache())
     *
     * @param string $key
     * @param mixed $value Only simple data types. Serialize your value if you have objects/arrays.
     * @param int $lifeTime
     *
     * @return boolean
     */
    public function setFastCache($key, $value, $lifeTime = null)
    {
        return $this->fastCache->set($key, $value, $lifeTime);
    }

    /**
     * Returns a local cache value.
     * 
     * @param string $key
     *
     * @return mixed null when not found
     */
    public function getFastCache($key)
    {
        return $this->fastCache->get($key);
    }

    /**
     * Sets a distributed cache.
     *
     * This stores a ms timestamp on the distributed cache (Jarves::setCache())
     * and the actual data on the high-speed cache driver (Jarves::setFastCache()).
     * This mechanism makes sure, you gain the maximum performance by using the
     * fast cache driver to store the actual data and using the distributed cache driver
     * to store a ms timestamp where we can check (over several jarves.cms installations)
     * whether the cache is still valid or not.
     *
     * Use Jarves::invalidateCache($key) to invalidate this cache.
     * You don't have to define the full key, instead you can pass only a part of the key.
     *
     * @see invalidateCache for more information.
     *
     * Don't mix the usage of getDistributedCache() and getCache() since this method
     * stores extra values at the value, which makes getCache() returning something invalid.
     *
     * @param string $key
     * @param mixed $value Only simple data types. Serialize your value if you have objects/arrays.
     * @param int $lifeTime
     *
     * @return boolean
     * @static
     */
    public function setDistributedCache($key, $value, $lifeTime = null)
    {
        $timestamp = microtime(true);

        $cache['data'] = $value;
        $cache['timestamp'] = $timestamp;

        $this->distributedCache->deleteInvalidate($key);

        return $this->fastCache->set($key, $cache, $lifeTime);
    }

}