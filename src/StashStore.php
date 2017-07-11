<?php

namespace Sandwich\Symfony\Lock\Store;

use Stash\Interfaces\PoolInterface;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\StoreInterface;

final class StashStore implements StoreInterface
{
    /**
     * @var PoolInterface
     */
    private $pool;

    /**
     * @var int
     */
    private $initialTtl;

    /**
     * @param PoolInterface $pool
     * @param int           $initialTtl
     */
    public function __construct(PoolInterface $pool, $initialTtl = 300)
    {
        if ($initialTtl < 1) {
            throw new InvalidArgumentException(
                sprintf('%s() expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl)
            );
        }

        $this->pool = $pool;
        $this->initialTtl = $initialTtl;
    }

    /**
     * @inheritdoc
     */
    public function save(Key $key)
    {
        $item = $this->pool->getItem((string) $key);

        if ($item->isMiss()) {
            $item->set($this->getToken($key));

            if ($item->save()) {
                return;
            }
        }

        // the lock is already acquire. It could be us. Let's try to put off.
        $this->putOffExpiration($key, $this->initialTtl);
    }

    /**
     * @inheritdoc
     */
    public function waitAndSave(Key $key)
    {
        throw new InvalidArgumentException(
            sprintf('The store "%s" does not supports blocking locks.', get_class($this))
        );
    }

    /**
     * @inheritdoc
     */
    public function putOffExpiration(Key $key, $ttl)
    {
        if ($ttl < 1) {
            throw new InvalidArgumentException(
                sprintf('%s() expects a TTL greater or equals to 1. Got %s.', __METHOD__, $ttl)
            );
        }

        $token = $this->getToken($key);
        $item = $this->pool->getItem((string) $key);

        if (!$item->isMiss() && $item->get() !== $token) {
            throw new LockConflictedException();
        }

        $item->set($token);
        $item->setTTL((int) ceil($ttl));

        if (!$item->save()) {
            throw new LockConflictedException();
        }
    }

    /**
     * @inheritdoc
     */
    public function delete(Key $key)
    {
        $item = $this->pool->getItem((string) $key);

        if ($item->isMiss()) {
            return;
        }

        if ($item->get() !== $this->getToken($key)) {
            // we are not the owner of the lock. Nothing to do.
            return;
        }

        $this->pool->deleteItem((string) $key);
    }

    /**
     * @inheritdoc
     */
    public function exists(Key $key)
    {
        $item = $this->pool->getItem((string) $key);

        return !$item->isMiss() && $item->get() === $this->getToken($key);
    }

    /**
     * Retrieve an unique token for the given key.
     *
     * @param Key $key
     *
     * @return string
     */
    private function getToken(Key $key)
    {
        if (!$key->hasState(__CLASS__)) {
            $token = base64_encode(random_bytes(32));
            $key->setState(__CLASS__, $token);
        }

        return $key->getState(__CLASS__);
    }
}
