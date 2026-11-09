<?php

/**
 * Guards the runtime the application is tested and shipped with.
 */
class PlatformTest extends TestCase
{
    public function testRunsOnPhp85OrNewer()
    {
        $this->assertGreaterThanOrEqual(80500, PHP_VERSION_ID, 'PHP '.PHP_VERSION.' is older than the supported 8.5 branch');
    }

    public function testRequiredExtensionsAreLoaded()
    {
        foreach (['ctype', 'filter', 'hash', 'json', 'mbstring', 'openssl', 'pdo_sqlite', 'session', 'tokenizer'] as $extension) {
            $this->assertTrue(extension_loaded($extension), "ext-$extension is missing");
        }
    }

    public function testFrameworkIsTheSupportedMajorVersion()
    {
        $this->assertSame(13, (int) explode('.', app()->version())[0]);
    }
}
