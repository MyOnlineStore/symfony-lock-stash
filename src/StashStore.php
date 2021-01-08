<?php
declare(strict_types=1);

namespace Sandwich\Symfony\Lock\Store;

use Stash\Interfaces\PoolInterface;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

final class StashStore implements BlockingStoreInterface
{
    /** @var PoolInterface */
    private $pool;

    /** @var int */
    private $initialTtl;

    public function __construct(PoolInterface $pool, int $initialTtl = 300)
    {
        if ($initialTtl < 1) {
            throw new InvalidArgumentException(
                \sprintf('%s() expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl)
            );
        }

        $this->pool = $pool;
        $this->initialTtl = $initialTtl;
    }

    public function save(Key $key): void
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

    public function waitAndSave(Key $key): void
    {
        throw new InvalidArgumentException(
            \sprintf('The store "%s" does not supports blocking locks.', self::class)
        );
    }

    /**
     * @inheritdoc
     */
    public function putOffExpiration(Key $key, $ttl): void
    {
        if ($ttl < 1) {
            throw new InvalidArgumentException(
                \sprintf('%s() expects a TTL greater or equals to 1. Got %s.', __METHOD__, $ttl)
            );
        }

        $token = $this->getToken($key);
        $item = $this->pool->getItem((string) $key);

        if (!$item->isMiss() && $item->get() !== $token) {
            throw new LockConflictedException();
        }

        $item->set($token);
        $item->setTTL((int) \ceil($ttl));

        if (!$item->save()) {
            throw new LockConflictedException();
        }
    }

    public function delete(Key $key): void
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

    public function exists(Key $key): bool
    {
        $item = $this->pool->getItem((string) $key);

        return !$item->isMiss() && $item->get() === $this->getToken($key);
    }

    /**
     * Retrieve an unique token for the given key.
     */
    private function getToken(Key $key): string
    {
        if (!$key->hasState(self::class)) {
            $token = \base64_encode(\random_bytes(32));
            $key->setState(self::class, $token);
        }

        return $key->getState(self::class);
    }
}
