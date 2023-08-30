<?php

namespace Illuminate\Tests\Session;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Cookie\CookieJar;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Stringable;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Tests\Session\TestEnum;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

class SessionStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testSessionIsLoadedFromHandler()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(serialize(['foo' => 'bar', 'bagged' => ['name' => 'taylor']]));
        $session->start();

        $this->assertSame('bar', $session->get('foo'));
        $this->assertSame('baz', $session->get('bar', 'baz'));
        $this->assertTrue($session->has('foo'));
        $this->assertFalse($session->has('bar'));
        $this->assertTrue($session->isStarted());

        $session->put('baz', 'boom');
        $this->assertTrue($session->has('baz'));
    }

    public function testSessionMigration()
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->migrate());
        $this->assertNotEquals($oldId, $session->getId());

        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->migrate(true));
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testSessionRegeneration()
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->regenerate());
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testCantSetInvalidId()
    {
        $session = $this->getSession();
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId(null);
        $this->assertNotNull($session->getId());
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId(['a']);
        $this->assertNotSame(['a'], $session->getId());

        $session->setId('wrong');
        $this->assertNotSame('wrong', $session->getId());
    }

    public function testSessionInvalidate()
    {
        $session = $this->getSession();
        $oldId = $session->getId();

        $session->put('foo', 'bar');
        $this->assertGreaterThan(0, count($session->all()));

        $session->flash('name', 'Taylor');
        $this->assertTrue($session->has('name'));

        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->invalidate());

        $this->assertFalse($session->has('name'));
        $this->assertNotEquals($oldId, $session->getId());
        $this->assertCount(0, $session->all());
    }

    public function testBrandNewSessionIsProperlySaved()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();
        $session->put('foo', 'bar');
        $session->flash('baz', 'boom');
        $session->now('qux', 'norf');
        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => ['baz'],
                ],
            ])
        );
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsProperlyUpdated()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([
            '_token' => Str::random(40),
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => ['baz'],
            ],
        ]));
        $session->start();

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        );

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsReSavedWhenNothingHasChanged()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([
            '_token' => Str::random(40),
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => [],
            ],
        ]));
        $session->start();

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        );

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testSessionIsReSavedWhenNothingHasChangedExceptSessionId()
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $token = Str::random(40);
        $session->getHandler()->shouldReceive('read')->once()->with($oldId)->andReturn(serialize([
            '_token' => $token,
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => [],
            ],
        ]));
        $session->start();

        $oldId = $session->getId();
        $session->migrate();
        $newId = $session->getId();

        $this->assertNotEquals($newId, $oldId);

        $session->getHandler()->shouldReceive('write')->once()->with(
            $newId,
            serialize([
                '_token' => $token,
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => [],
                ],
            ])
        );

        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testOldInputFlashing()
    {
        $session = $this->getSession();
        $session->put('boom', 'baz');
        $session->flashInput(['foo' => 'bar', 'bar' => 0, 'name' => null]);

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertSame('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));

        $session->ageFlashData();

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertSame('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));

        $this->assertSame('default', $session->getOldInput('input', 'default'));
        $this->assertNull($session->getOldInput('name', 'default'));
    }

    public function testDataFlashing()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->flash('bar', 0);
        $session->flash('baz');

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));
        $this->assertTrue($session->get('baz'));

        $session->ageFlashData();

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataFlashingNow()
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->now('bar', 0);

        $this->assertTrue($session->has('foo'));
        $this->assertSame('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataMergeNewFlashes()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->put('fu', 'baz');
        $session->put('_flash.old', ['qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('fu', $session->get('_flash.new')));
        $session->keep(['fu', 'qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('fu', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('qu', $session->get('_flash.new')));
        $this->assertFalse(array_search('qu', $session->get('_flash.old')));
    }

    public function testReflash()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->put('_flash.old', ['foo']);
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testReflashWithNow()
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testOnly()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $session->put('qu', 'ux');
        $this->assertEquals(['foo' => 'bar', 'qu' => 'ux'], $session->all());
        $this->assertEquals(['qu' => 'ux'], $session->only(['qu']));
    }

    public function testReplace()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $session->put('qu', 'ux');
        $session->replace(['foo' => 'baz']);
        $this->assertSame('baz', $session->get('foo'));
        $this->assertSame('ux', $session->get('qu'));
    }

    public function testRemove()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $pulled = $session->remove('foo');
        $this->assertFalse($session->has('foo'));
        $this->assertSame('bar', $pulled);
    }

    public function testClear()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');

        $session->flush();
        $this->assertFalse($session->has('foo'));

        $session->put('foo', 'bar');

        $session->flush();
        $this->assertFalse($session->has('foo'));
    }

    public function testIncrement()
    {
        $session = $this->getSession();

        $session->put('foo', 5);
        $foo = $session->increment('foo');
        $this->assertEquals(6, $foo);
        $this->assertEquals(6, $session->get('foo'));

        $foo = $session->increment('foo', 4);
        $this->assertEquals(10, $foo);
        $this->assertEquals(10, $session->get('foo'));

        $session->increment('bar');
        $this->assertEquals(1, $session->get('bar'));
    }

    public function testDecrement()
    {
        $session = $this->getSession();

        $session->put('foo', 5);
        $foo = $session->decrement('foo');
        $this->assertEquals(4, $foo);
        $this->assertEquals(4, $session->get('foo'));

        $foo = $session->decrement('foo', 4);
        $this->assertEquals(0, $foo);
        $this->assertEquals(0, $session->get('foo'));

        $session->decrement('bar');
        $this->assertEquals(-1, $session->get('bar'));
    }

    public function testHasOldInputWithoutKey()
    {
        $session = $this->getSession();
        $session->flash('boom', 'baz');
        $this->assertFalse($session->hasOldInput());

        $session->flashInput(['foo' => 'bar']);
        $this->assertTrue($session->hasOldInput());
    }

    public function testHandlerNeedsRequest()
    {
        $session = $this->getSession();
        $this->assertFalse($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->never();

        $session = new Store('test', m::mock(new CookieSessionHandler(new CookieJar, 60, false)));
        $this->assertTrue($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->once();
        $request = new Request;
        $session->setRequestOnHandler($request);
    }

    public function testToken()
    {
        $session = $this->getSession();
        $this->assertEquals($session->token(), $session->token());
    }

    public function testRegenerateToken()
    {
        $session = $this->getSession();
        $token = $session->token();
        $session->regenerateToken();
        $this->assertNotEquals($token, $session->token());
    }

    public function testName()
    {
        $session = $this->getSession();
        $this->assertEquals($session->getName(), $this->getSessionName());
        $session->setName('foo');
        $this->assertSame('foo', $session->getName());
    }

    public function testForget()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertTrue($session->has('foo'));
        $session->forget('foo');
        $this->assertFalse($session->has('foo'));

        $session->put('foo', 'bar');
        $session->put('bar', 'baz');
        $session->forget(['foo', 'bar']);
        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->has('bar'));
    }

    public function testSetPreviousUrl()
    {
        $session = $this->getSession();
        $session->setPreviousUrl('https://example.com/foo/bar');

        $this->assertTrue($session->has('_previous.url'));
        $this->assertSame('https://example.com/foo/bar', $session->get('_previous.url'));

        $url = $session->previousUrl();
        $this->assertSame('https://example.com/foo/bar', $url);
    }

    public function testPasswordConfirmed()
    {
        $session = $this->getSession();
        $this->assertFalse($session->has('auth.password_confirmed_at'));
        $session->passwordConfirmed();
        $this->assertTrue($session->has('auth.password_confirmed_at'));
    }

    public function testKeyPush()
    {
        $session = $this->getSession();
        $session->put('language', ['PHP' => ['Laravel']]);
        $session->push('language.PHP', 'Symfony');

        $this->assertEquals(['PHP' => ['Laravel', 'Symfony']], $session->get('language'));
    }

    public function testKeyPull()
    {
        $session = $this->getSession();
        $session->put('name', 'Taylor');

        $this->assertSame('Taylor', $session->pull('name'));
        $this->assertSame('Taylor Otwell', $session->pull('name', 'Taylor Otwell'));
        $this->assertNull($session->pull('name'));
    }

    public function testKeyHas()
    {
        $session = $this->getSession();
        $session->put('first_name', 'Mehdi');
        $session->put('last_name', 'Rajabi');

        $this->assertTrue($session->has('first_name'));
        $this->assertTrue($session->has('last_name'));
        $this->assertTrue($session->has('first_name', 'last_name'));
        $this->assertTrue($session->has(['first_name', 'last_name']));

        $this->assertFalse($session->has('first_name', 'foo'));
        $this->assertFalse($session->has('foo', 'bar'));
    }

    public function testKeyExists()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertTrue($session->exists('foo'));
        $session->put('baz', null);
        $session->put('hulk', ['one' => true]);
        $this->assertFalse($session->has('baz'));
        $this->assertTrue($session->exists('baz'));
        $this->assertFalse($session->exists('bogus'));
        $this->assertTrue($session->exists(['foo', 'baz']));
        $this->assertFalse($session->exists(['foo', 'baz', 'bogus']));
        $this->assertTrue($session->exists(['hulk.one']));
        $this->assertFalse($session->exists(['hulk.two']));
    }

    public function testKeyMissing()
    {
        $session = $this->getSession();
        $session->put('foo', 'bar');
        $this->assertFalse($session->missing('foo'));
        $session->put('baz', null);
        $session->put('hulk', ['one' => true]);
        $this->assertFalse($session->has('baz'));
        $this->assertFalse($session->missing('baz'));
        $this->assertTrue($session->missing('bogus'));
        $this->assertFalse($session->missing(['foo', 'baz']));
        $this->assertTrue($session->missing(['foo', 'baz', 'bogus']));
        $this->assertFalse($session->missing(['hulk.one']));
        $this->assertTrue($session->missing(['hulk.two']));
    }

    public function testRememberMethodCallsPutAndReturnsDefault()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('get')->andReturn(null);
        $result = $session->remember('foo', function () {
            return 'bar';
        });
        $this->assertSame('bar', $session->get('foo'));
        $this->assertSame('bar', $result);
    }

    public function testRememberMethodReturnsPreviousValueIfItAlreadySets()
    {
        $session = $this->getSession();
        $session->put('key', 'foo');
        $result = $session->remember('key', function () {
            return 'bar';
        });
        $this->assertSame('foo', $session->get('key'));
        $this->assertSame('foo', $result);
    }

    public function testStringMethod()
    {
        $session = $this->getSession();
        $session->put('test_string', [
            'int' => 123,
            'int_str' => '456',
            'float' => 123.456,
            'float_str' => '123.456',
            'float_zero' => 0.000,
            'float_str_zero' => '0.000',
            'str' => 'abc',
            'empty_str' => '',
            'null' => null,
        ]);
        $this->assertTrue($session->string('test_string.int') instanceof Stringable);
        $this->assertTrue($session->string('test_string.unknown_key') instanceof Stringable);
        $this->assertSame('123', $session->string('test_string.int')->value());
        $this->assertSame('456', $session->string('test_string.int_str')->value());
        $this->assertSame('123.456', $session->string('test_string.float')->value());
        $this->assertSame('123.456', $session->string('test_string.float_str')->value());
        $this->assertSame('0', $session->string('test_string.float_zero')->value());
        $this->assertSame('0.000', $session->string('test_string.float_str_zero')->value());
        $this->assertSame('', $session->string('test_string.empty_str')->value());
        $this->assertSame('', $session->string('test_string.null')->value());
        $this->assertSame('', $session->string('test_string.unknown_key')->value());
    }

    public function testBooleanMethod()
    {
        $session = $this->getSession();
        $session->put('test_boolean', ['with_trashed' => 'false', 'download' => true, 'checked' => 1, 'unchecked' => '0', 'with_on' => 'on', 'with_yes' => 'yes']);
        $this->assertTrue($session->boolean('test_boolean.checked'));
        $this->assertTrue($session->boolean('test_boolean.download'));
        $this->assertFalse($session->boolean('test_boolean.unchecked'));
        $this->assertFalse($session->boolean('test_boolean.with_trashed'));
        $this->assertFalse($session->boolean('test_boolean.some_undefined_key'));
        $this->assertTrue($session->boolean('test_boolean.with_on'));
        $this->assertTrue($session->boolean('test_boolean.with_yes'));
    }

    public function testIntegerMethod()
    {
        $session = $this->getSession();
        $session->put('test_integer', [
            'int' => '123',
            'raw_int' => 456,
            'zero_padded' => '078',
            'space_padded' => ' 901',
            'nan' => 'nan',
            'mixed' => '1ab',
            'underscore_notation' => '2_000',
            'null' => null,
        ]);
        $this->assertSame(123, $session->integer('test_integer.int'));
        $this->assertSame(456, $session->integer('test_integer.raw_int'));
        $this->assertSame(78, $session->integer('test_integer.zero_padded'));
        $this->assertSame(901, $session->integer('test_integer.space_padded'));
        $this->assertSame(0, $session->integer('test_integer.nan'));
        $this->assertSame(1, $session->integer('test_integer.mixed'));
        $this->assertSame(2, $session->integer('test_integer.underscore_notation'));
        $this->assertSame(123456, $session->integer('test_integer.unknown_key', 123456));
        $this->assertSame(0, $session->integer('test_integer.null'));
        $this->assertSame(0, $session->integer('test_integer.null', 123456));
    }

    public function testFloatMethod()
    {
        $session = $this->getSession();
        $session->put('test_float', [
            'float' => '1.23',
            'raw_float' => 45.6,
            'decimal_only' => '.6',
            'zero_padded' => '0.78',
            'space_padded' => ' 90.1',
            'nan' => 'nan',
            'mixed' => '1.ab',
            'scientific_notation' => '1e3',
            'null' => null,
        ]);
        $this->assertSame(1.23, $session->float('test_float.float'));
        $this->assertSame(45.6, $session->float('test_float.raw_float'));
        $this->assertSame(.6, $session->float('test_float.decimal_only'));
        $this->assertSame(0.78, $session->float('test_float.zero_padded'));
        $this->assertSame(90.1, $session->float('test_float.space_padded'));
        $this->assertSame(0.0, $session->float('test_float.nan'));
        $this->assertSame(1.0, $session->float('test_float.mixed'));
        $this->assertSame(1e3, $session->float('test_float.scientific_notation'));
        $this->assertSame(123.456, $session->float('test_float.unknown_key', 123.456));
        $this->assertSame(0.0, $session->float('test_float.null'));
        $this->assertSame(0.0, $session->float('test_float.null', 123.456));
    }

    public function testCollectMethod()
    {
        $session = $this->getSession();
        $session->put('test_collect', ['users' => [1, 2, 3]]);

        $this->assertInstanceOf(Collection::class, $session->collect('test_collect.users'));
        $this->assertTrue($session->collect('test_collect.developers')->isEmpty());
        $this->assertEquals([1, 2, 3], $session->collect('test_collect.users')->all());
        $this->assertEquals(['users' => [1, 2, 3]], $session->collect('test_collect')->all());

        $session->put('test_collect', ['text-payload']);
        $request = Request::create('/', 'GET', ['text-payload']);
        $this->assertEquals(['text-payload'], $session->collect('test_collect')->all());

        $session->put('test_collect', ['email' => 'test@example.com']);
        $this->assertEquals(['test@example.com'], $session->collect('test_collect.email')->all());

        $session->put('test_collect', []);
        $request = Request::create('/', 'GET', []);
        $this->assertInstanceOf(Collection::class, $session->collect('test_collect'));
        $this->assertTrue($session->collect()->isEmpty());

        $session->put('users', [1, 2, 3]);
        $session->put('roles', [4, 5, 6]);
        $session->put('foo', ['bar', 'baz']);
        $session->put('email', ['test@example.com']);
        $this->assertInstanceOf(Collection::class, $session->collect(['users']));
        $this->assertTrue($session->collect(['developers'])->isEmpty());
        $this->assertTrue($session->collect(['roles'])->isNotEmpty());
        $this->assertEquals(['roles' => [4, 5, 6]], $session->collect(['roles'])->all());
        $this->assertEquals(['users' => [1, 2, 3], 'email' => 'test@example.com'], $session->collect(['users', 'email'])->all());
        $this->assertEquals(collect(['roles' => [4, 5, 6], 'foo' => ['bar', 'baz']]), $session->collect(['roles', 'foo']));
        $this->assertEquals(['users' => [1, 2, 3], 'roles' => [4, 5, 6], 'foo' => ['bar', 'baz'], 'email' => 'test@example.com'], $session->collect()->all());
    }

    public function testDateMethod()
    {
        $session = $this->getSession();
        $session->put('test_date', [
            'as_null' => null,
            'as_invalid' => 'invalid',

            'as_datetime' => '20-01-01 16:30:25',
            'as_format' => '1577896225',
            'as_timezone' => '20-01-01 13:30:25',

            'as_date' => '2020-01-01',
            'as_time' => '16:30:25',
        ]);

        $current = Carbon::create(2020, 1, 1, 16, 30, 25);

        $this->assertNull($session->date('test_date.as_null'));
        $this->assertNull($session->date('test_date.doesnt_exists'));

        $this->assertEquals($current, $session->date('test_date.as_datetime'));
        $this->assertEquals($current->format('Y-m-d H:i:s P'), $session->date('test_date.as_format', 'U')->format('Y-m-d H:i:s P'));
        $this->assertEquals($current, $session->date('test_date.as_timezone', null, 'America/Santiago'));

        $this->assertTrue($session->date('test_date.as_date')->isSameDay($current));
        $this->assertTrue($session->date('test_date.as_time')->isSameSecond('16:30:25'));
    }

    public function testDateMethodExceptionWhenValueInvalid()
    {
        $this->expectException(InvalidArgumentException::class);

        $session = $this->getSession();
        $session->put('date', 'invalid');

        $session->date('date');
    }

    public function testDateMethodExceptionWhenFormatInvalid()
    {
        $this->expectException(InvalidArgumentException::class);

        $session = $this->getSession();
        $session->put('date', '20-01-01 16:30:25');

        $session->date('date', 'invalid_format');
    }

    public function testEnumMethod()
    {
        $session = $this->getSession();
        $session->put('test_enum', [
            'valid_enum_value' => 'test',
            'invalid_enum_value' => 'invalid',
        ]);

        $this->assertNull($session->enum('test_enum.doesnt_exists', TestEnum::class));

        $this->assertEquals(TestEnum::test, $session->enum('test_enum.valid_enum_value', TestEnum::class));

        $this->assertNull($session->enum('test_enum.invalid_enum_value', TestEnum::class));
    }

    public function testValidationErrorsCanBeSerializedAsJson()
    {
        $session = $this->getSession('json');
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();
        $session->put('errors', $errorBag = new ViewErrorBag);
        $messageBag = new MessageBag([
            'first_name' => [
                'Your first name is required',
                'Your first name must be at least 1 character',
            ],
        ]);
        $messageBag->setFormat('<p>:message</p>');
        $errorBag->put('default', $messageBag);

        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            json_encode([
                '_token' => $session->token(),
                'errors' => [
                    'default' => [
                        'format' => '<p>:message</p>',
                        'messages' => [
                            'first_name' => [
                                'Your first name is required',
                                'Your first name must be at least 1 character',
                            ],
                        ],
                    ],
                ],
                '_flash' => [
                    'old' => [],
                    'new' => [],
                ],
            ])
        );
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testValidationErrorsCanBeReadAsJson()
    {
        $session = $this->getSession('json');
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(json_encode([
            'errors' => [
                'default' => [
                    'format' => '<p>:message</p>',
                    'messages' => [
                        'first_name' => [
                            'Your first name is required',
                            'Your first name must be at least 1 character',
                        ],
                    ],
                ],
            ],
        ]));
        $session->start();

        $errors = $session->get('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertInstanceOf(MessageBag::class, $errors->getBags()['default']);
        $this->assertEquals('<p>:message</p>', $errors->getBags()['default']->getFormat());
        $this->assertEquals(['first_name' => [
            'Your first name is required',
            'Your first name must be at least 1 character',
        ]], $errors->getBags()['default']->getMessages());
    }

    public function testItIsMacroable()
    {
        $this->getSession()->macro('foo', function () {
            return 'macroable';
        });

        $this->assertSame('macroable', $this->getSession()->foo());
    }

    public function getSession($serialization = 'php')
    {
        $reflection = new ReflectionClass(Store::class);

        return $reflection->newInstanceArgs($this->getMocks($serialization));
    }

    public function getMocks($serialization = 'json')
    {
        return [
            $this->getSessionName(),
            m::mock(SessionHandlerInterface::class),
            $this->getSessionId(),
            $serialization,
        ];
    }

    public function getSessionId()
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    public function getSessionName()
    {
        return 'name';
    }
}
