<?php

namespace Tests;

use Aimeos\KeywordsBridge\Drivers\Ahrefs;
use Aimeos\KeywordsBridge\Facades\Keywords;
use Aimeos\KeywordsBridge\Keyword;
use Aimeos\KeywordsBridge\ServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;


class AhrefsTest extends TestCase
{
    private const URL = 'https://api.ahrefs.com/v3/keywords-explorer/';


    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }


    protected function defineEnvironment($app)
    {
        $app['config']->set('keywords-bridge.default', 'ahrefs');
        $app['config']->set('keywords-bridge.drivers.ahrefs', ['key' => 'apikey', 'country' => 'gb']);
    }


    public function testVolume(): void
    {
        Http::fake([self::URL . 'overview*' => Http::response(['keywords' => [
            ['keyword' => 'laravel cms', 'volume' => 1900],
            ['keyword' => 'Headless CMS', 'volume' => 14800],
            ['keyword' => 'rare keyword', 'volume' => null],
        ]])]);

        $result = Keywords::volume(['Laravel  CMS', 'headless cms', 'rare keyword', 'unknown', ' ', 'laravel cms'], 'DE');

        $this->assertEquals([
            new Keyword('laravel cms', 1900),
            new Keyword('headless cms', 14800),
            new Keyword('rare keyword', null),
            new Keyword('unknown', null),
        ], $result);

        Http::assertSent(fn(Request $request) => str_starts_with($request->url(), self::URL . 'overview?')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer apikey')
            && $request['keywords'] === 'laravel cms,headless cms,rare keyword,unknown'
            && $request['select'] === 'keyword,volume'
            && $request['country'] === 'de'
            && $request['limit'] == 4);
    }


    public function testVolumeWorldwide(): void
    {
        Http::fake([self::URL . 'overview*' => Http::response(['keywords' => [
            ['keyword' => 'laravel cms', 'volume' => 500, 'global_volume' => 6100],
        ]])]);

        $this->assertEquals([new Keyword('laravel cms', 6100)], Keywords::volume(['laravel cms']));

        Http::assertSent(fn(Request $request) => $request['select'] === 'keyword,global_volume' && $request['country'] === 'gb');
    }


    public function testVolumeChunked(): void
    {
        Http::fake([self::URL . 'overview*' => Http::response(['keywords' => []])]);

        $keywords = array_map(fn($i) => "keyword $i", range(1, 150));
        $this->assertCount(150, Keywords::volume($keywords));

        Http::assertSentCount(2);
        Http::assertSent(fn(Request $request) => $request['limit'] == 100 && str_ends_with($request['keywords'], ',keyword 100'));
        Http::assertSent(fn(Request $request) => $request['limit'] == 50 && str_starts_with($request['keywords'], 'keyword 101,'));
    }


    public function testVolumeComma(): void
    {
        Http::fake();

        $this->assertEquals([new Keyword('cms, laravel')], Keywords::volume(['cms, laravel']));
        Http::assertNothingSent();
    }


    public function testVolumeCountryInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Keywords::volume(['laravel cms'], 'usa');
    }


    public function testRelated(): void
    {
        Http::fake([self::URL . 'related-terms*' => Http::response(['keywords' => [
            ['keyword' => 'Laravel Admin Panel', 'volume' => 2400],
            ['keyword' => 'laravel headless cms', 'volume' => 320],
            ['keyword' => 'laravel blog', 'volume' => null],
        ]])]);

        $this->assertEquals([
            new Keyword('laravel admin panel', 2400),
            new Keyword('laravel headless cms', 320),
            new Keyword('laravel blog', null),
        ], Keywords::related(' Laravel CMS ', 'us', 3));

        Http::assertSent(fn(Request $request) => str_starts_with($request->url(), self::URL . 'related-terms?')
            && $request['keywords'] === 'laravel cms'
            && $request['select'] === 'keyword,volume'
            && $request['order_by'] === 'volume:desc'
            && $request['country'] === 'us'
            && $request['limit'] == 3);
    }


    public function testRelatedWorldwide(): void
    {
        Http::fake([self::URL . 'related-terms*' => Http::response(['keywords' => [
            ['keyword' => 'laravel admin panel', 'global_volume' => 9900],
        ]])]);

        $this->assertEquals([new Keyword('laravel admin panel', 9900)], Keywords::related('laravel cms', null, 5000));

        Http::assertSent(fn(Request $request) => $request['select'] === 'keyword,global_volume'
            && $request['order_by'] === 'global_volume:desc'
            && $request['country'] === 'gb'
            && $request['limit'] == 1000);
    }


    public function testRelatedInvalid(): void
    {
        Http::fake();

        $this->assertSame([], Keywords::related('  '));
        $this->assertSame([], Keywords::related('cms, laravel'));
        Http::assertNothingSent();
    }


    public function testRequestFailed(): void
    {
        Http::fake([self::URL . '*' => Http::response(['error' => 'Insufficient plan'], 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP status 403: Insufficient plan');
        Keywords::volume(['laravel cms']);
    }


    public function testRequestNotRetried(): void
    {
        Http::fake([self::URL . '*' => Http::response(['error' => 'Too many requests'], 429)]);

        try {
            Keywords::volume(['laravel cms'], 'us');
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP status 429: Too many requests', $e->getMessage());
        }

        Http::assertSentCount(1);
    }


    public function testConnectionError(): void
    {
        Http::fake(fn() => throw new \Illuminate\Http\Client\ConnectionException('Timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ahrefs API request failed: Timeout');
        Keywords::volume(['laravel cms']);
    }


    public function testConfigMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[key]');
        new Ahrefs(['country' => 'us']);
    }


    public function testConfigCountry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ahrefs(['key' => 'apikey', 'country' => 'us&x=1']);
    }
}
