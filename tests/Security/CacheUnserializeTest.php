<?php

use Illuminate\Support\Facades\Cache;

/**
 * Hardening: values read from the Redis cache are unserialized; with
 * serializable_classes = false a value planted in Redis cannot instantiate
 * PHP objects (object injection / gadget chains).
 */
class CacheUnserializeTest extends TestCase
{
    public function testObjectsAreNotRestoredFromTheRedisCache()
    {
        $store = Cache::store('redis');
        $store->put('security-test:object', new ArrayObject(['a' => 1]), 60);

        $value = $store->get('security-test:object');

        $this->assertNotInstanceOf(ArrayObject::class, $value);
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $value);
        $store->forget('security-test:object');
    }

    public function testScalarsAndArraysStillRoundTrip()
    {
        $store = Cache::store('redis');
        $store->put('security-test:scalar', 1, 60);
        $store->put('security-test:array', ['blocked' => true], 60);
        $this->assertSame(1, (int) $store->get('security-test:scalar'));
        $this->assertSame(['blocked' => true], $store->get('security-test:array'));

        $this->assertTrue($store->add('security-test:counter', 1, 60));
        $this->assertSame(2, $store->increment('security-test:counter'));

        $store->forget('security-test:scalar');
        $store->forget('security-test:array');
        $store->forget('security-test:counter');
    }
}
