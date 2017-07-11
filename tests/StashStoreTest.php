<?php

namespace Sandwich\Tests\Symfony\Lock\Store;

use Sandwich\Symfony\Lock\Store\StashStore;
use Stash\Interfaces\ItemInterface;
use Stash\Interfaces\PoolInterface;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

class StashStoreTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var PoolInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $pool;

    /**
     * @var StashStore
     */
    private $stashStore;

    protected function setUp()
    {
        $this->pool = $this->getMock(PoolInterface::class);
        $this->stashStore = new StashStore($this->pool);
    }

    public function testConstructWillThrowAnExceptionForInvalidTtl()
    {
        $this->setExpectedException(InvalidArgumentException::class);
        new StashStore($this->pool, -1);
    }

    public function testSaveWillPutOffExpirationIfItemExists()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::exactly(2))->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('another-token');
        $item->expects(self::never())->method('set');
        $item->expects(self::never())->method('save');

        $this->pool->expects(self::exactly(2))->method('getItem')->with('foo')->willReturn($item);

        $this->setExpectedException(LockConflictedException::class);
        $this->stashStore->save($key);
    }

    public function testSaveWillPutOffExpirationIfSaveFails()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::exactly(2))->method('isMiss')->willReturn(true);
        $item->expects(self::exactly(2))->method('set')->with('some-token');
        $item->expects(self::once())->method('setTTL')->with(300);
        $item->expects(self::exactly(2))->method('save')->willReturn(false);

        $this->pool->expects(self::exactly(2))->method('getItem')->with('foo')->willReturn($item);

        $this->setExpectedException(LockConflictedException::class);
        $this->stashStore->save($key);
    }

    public function testSaveWillReturnUponSuccess()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(true);
        $item->expects(self::once())->method('set')->with('some-token');
        $item->expects(self::once())->method('save')->willReturn(true);

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        $this->stashStore->save($key);
    }

    public function testWaitAndSaveWillThrowException()
    {
        $this->setExpectedException(InvalidArgumentException::class);
        $this->stashStore->waitAndSave(new Key('foo'));
    }

    public function testPutOffExpirationWillThrowExceptionForInvalidTtl()
    {
        $this->setExpectedException(InvalidArgumentException::class);
        $this->stashStore->putOffExpiration(new Key('foo'), -1);
    }

    public function testPutOffExpirationWillThrowExceptionIfItemExistsWithOtherToken()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('another-token');
        $item->expects(self::never())->method('set');
        $item->expects(self::never())->method('save');

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        $this->setExpectedException(LockConflictedException::class);
        $this->stashStore->putOffExpiration($key, 10);
    }

    public function testPutOffExpirationWillThrowExceptionIfItemSaveFails()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('some-token');
        $item->expects(self::once())->method('set')->with('some-token');
        $item->expects(self::once())->method('setTTL')->with(10);
        $item->expects(self::once())->method('save')->willReturn(false);

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        $this->setExpectedException(LockConflictedException::class);
        $this->stashStore->putOffExpiration($key, 10);
    }

    public function testPutOffExpirationWillExtendItem()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('some-token');
        $item->expects(self::once())->method('set')->with('some-token');
        $item->expects(self::once())->method('setTTL')->with(10);
        $item->expects(self::once())->method('save')->willReturn(true);

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        $this->stashStore->putOffExpiration($key, 10);
    }

    public function testDeleteWillDoNothingIfItemDoesNotExists()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(true);

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);
        $this->pool->expects(self::never())->method('deleteItem');

        $this->stashStore->delete($key);
    }

    public function testDeleteWillDoNothingIfItemIsNotOwnedByKey()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('another-token');

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);
        $this->pool->expects(self::never())->method('deleteItem');

        $this->stashStore->delete($key);
    }

    public function testDeleteWillDeleteItem()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('some-token');

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);
        $this->pool->expects(self::once())->method('deleteItem')->with('foo');

        $this->stashStore->delete($key);
    }

    public function testExistsWillReturnFalseIfItemDoesNotExist()
    {
        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(true);

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        self::assertFalse($this->stashStore->exists(new Key('foo')));
    }

    public function testExistsWillReturnFalseIfItemExistsButIsNotOwnedByKey()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('another-token');

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        self::assertFalse($this->stashStore->exists($key));
    }

    public function testExistsWillReturnTrueIfItemExistsAndIsOwnedByKey()
    {
        $key = new Key('foo');
        $key->setState(StashStore::class, 'some-token');

        $item = $this->getMock(ItemInterface::class);
        $item->expects(self::once())->method('isMiss')->willReturn(false);
        $item->expects(self::once())->method('get')->willReturn('some-token');

        $this->pool->expects(self::once())->method('getItem')->with('foo')->willReturn($item);

        self::assertTrue($this->stashStore->exists($key));
    }
}
