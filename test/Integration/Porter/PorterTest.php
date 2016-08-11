<?php
namespace ScriptFUSIONTest\Integration\Porter;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use ScriptFUSION\Mapper\CollectionMapper;
use ScriptFUSION\Mapper\Mapping;
use ScriptFUSION\Porter\Cache\CacheAdvice;
use ScriptFUSION\Porter\Cache\CacheToggle;
use ScriptFUSION\Porter\Cache\CacheUnavailableException;
use ScriptFUSION\Porter\Collection\CountableMappedRecords;
use ScriptFUSION\Porter\Collection\CountableProviderRecords;
use ScriptFUSION\Porter\Collection\FilteredRecords;
use ScriptFUSION\Porter\Collection\MappedRecords;
use ScriptFUSION\Porter\Collection\PorterRecords;
use ScriptFUSION\Porter\Collection\ProviderRecords;
use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\Provider;
use ScriptFUSION\Porter\Provider\Resource\ProviderResource;
use ScriptFUSION\Porter\Provider\StaticDataProvider;
use ScriptFUSION\Porter\ProviderAlreadyRegisteredException;
use ScriptFUSION\Porter\ProviderNotFoundException;
use ScriptFUSION\Porter\Specification\ImportSpecification;
use ScriptFUSION\Porter\Specification\StaticDataImportSpecification;
use ScriptFUSIONTest\MockFactory;

final class PorterTest extends \PHPUnit_Framework_TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var Porter */
    private $porter;

    /** @var Provider|MockInterface */
    private $provider;

    /** @var ProviderResource */
    private $resource;

    protected function setUp()
    {
        $this->porter = (new Porter)->registerProvider(
            $this->provider =
                \Mockery::mock(Provider::class)
                    ->shouldReceive('fetch')
                    ->andReturn(new \ArrayIterator(['foo']))
                    ->byDefault()
                    ->getMock()
        );

        $this->resource = MockFactory::mockResource($this->provider);
    }

    public function testGetProvider()
    {
        self::assertSame($this->provider, $this->porter->getProvider(get_class($this->provider)));
    }

    public function testRegisterSameProvider()
    {
        $this->setExpectedException(ProviderAlreadyRegisteredException::class);

        $this->porter->registerProvider($this->provider);
    }

    public function testRegisterSameProviderType()
    {
        $this->setExpectedException(ProviderAlreadyRegisteredException::class);

        $this->porter->registerProvider(clone $this->provider);
    }

    public function testRegisterProviderTag()
    {
        $this->porter->registerProvider($provider = clone $this->provider, 'foo');

        self::assertSame($provider, $this->porter->getProvider(get_class($this->provider), 'foo'));
    }

    public function testGetStaticProvider()
    {
        self::assertInstanceOf(StaticDataProvider::class, $this->porter->getProvider(StaticDataProvider::class));
    }

    public function testGetInvalidProvider()
    {
        $this->setExpectedException(ProviderNotFoundException::class);

        (new Porter)->getProvider('foo');
    }

    public function testGetInvalidTag()
    {
        $this->setExpectedException(ProviderNotFoundException::class);

        (new Porter)->getProvider(get_class($this->provider), 'foo');
    }

    public function testGetStaticProviderTag()
    {
        $this->setExpectedException(ProviderNotFoundException::class);

        $this->porter->getProvider(StaticDataProvider::class, 'foo');
    }

    public function testImportTaggedResource()
    {
        $this->porter->registerProvider(
            $provider = \Mockery::mock(Provider::class)
                ->shouldReceive('fetch')
                ->andReturn(new \ArrayIterator([$output = 'bar']))
                ->getMock(),
            $tag = 'foo'
        );

        $records = $this->porter->import(MockFactory::mockImportSpecification(
            MockFactory::mockResource($provider)
                ->shouldReceive('getProviderTag')
                ->andReturn($tag)
                ->getMock()
        ));

        self::assertSame($output, $records->current());
    }

    public function testHasProvider()
    {
        self::assertTrue($this->porter->hasProvider(get_class($this->provider)));
        self::assertFalse($this->porter->hasProvider(get_class($this->provider), 'foo'));
        self::assertFalse($this->porter->hasProvider('foo'));
    }

    public function testImport()
    {
        $records = $this->porter->import($specification = new ImportSpecification($this->resource));

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertNotSame($specification, $records->getSpecification());
        self::assertInstanceOf(ProviderRecords::class, $records->getPreviousCollection());
        self::assertSame('foo', $records->current());
    }

    /**
     * Tests that when the resource is countable the count is propagated to the outermost collection.
     */
    public function testImportCountableRecords()
    {
        $records = $this->porter->import(
            new StaticDataImportSpecification(
                new CountableProviderRecords(\Mockery::mock(\Iterator::class), $count = rand(1, 9), $this->resource)
            )
        );

        // Innermost collection.
        self::assertInstanceOf(\Countable::class, $first = $records->findFirstCollection());
        self::assertCount($count, $first);

        // Outermost collection.
        self::assertInstanceOf(\Countable::class, $records);
        self::assertCount($count, $records);
    }

    /**
     * Tests that when the resource is countable the count is propagated to the outermost collection via a mapped
     * collection.
     */
    public function testImportAndMapCountableRecords()
    {
        $records = $this->porter->import(
            (new StaticDataImportSpecification(
                new CountableProviderRecords(\Mockery::mock(\Iterator::class), $count = rand(1, 9), $this->resource)
            ))->setMapping(\Mockery::mock(Mapping::class))
        );

        self::assertInstanceOf(CountableMappedRecords::class, $records->getPreviousCollection());
        self::assertInstanceOf(\Countable::class, $records);
        self::assertCount($count, $records);
    }

    /**
     * Tests that when the resource is countable the count is lost when filtering is applied.
     */
    public function testImportAndFilterCountableRecords()
    {
        $records = $this->porter->import(
            (new StaticDataImportSpecification(
                new CountableProviderRecords(\Mockery::mock(\Iterator::class), $count = rand(1, 9), $this->resource)
            ))->setFilter([$this, __FUNCTION__])
        );

        // Innermost collection.
        self::assertInstanceOf(\Countable::class, $records->findFirstCollection());

        // Outermost collection.
        self::assertNotInstanceOf(\Countable::class, $records);
    }

    public function testFilter()
    {
        $this->provider->shouldReceive('fetch')->andReturn(new \ArrayIterator(range(1, 10)));

        $records = $this->porter->import(
            (new ImportSpecification($this->resource))
                ->setFilter(function ($record) {
                    return $record % 2;
                })
        );

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertInstanceOf(FilteredRecords::class, $records->getPreviousCollection());
        self::assertSame([1, 3, 5, 7, 9], iterator_to_array($records));
    }

    public function testMap()
    {
        $records = $this->porter->setMapper(
            \Mockery::mock(CollectionMapper::class)
                ->shouldReceive('mapCollection')
                ->with(\Mockery::type(\Iterator::class), \Mockery::type(Mapping::class), \Mockery::any())
                ->once()
                ->andReturn(new \ArrayIterator($result = ['foo' => 'bar']))
                ->getMock()
        )->import(
            (new ImportSpecification($this->resource))->setMapping(\Mockery::mock(Mapping::class))
        );

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertInstanceOf(MappedRecords::class, $records->getPreviousCollection());
        self::assertSame($result, iterator_to_array($records));
    }

    public function testApplyCacheAdvice()
    {
        $this->porter->registerProvider(
            $provider = \Mockery::mock(implode(',', [Provider::class, CacheToggle::class]))
                ->shouldReceive('fetch')->andReturn(new \EmptyIterator)
                ->shouldReceive('disableCache')->once()
                ->shouldReceive('enableCache')->once()
                ->getMock()
        );

        $this->porter->import($specification = new ImportSpecification(MockFactory::mockResource($provider)));
        $this->porter->import($specification->setCacheAdvice(CacheAdvice::SHOULD_CACHE()));
    }

    public function testCacheUnavailable()
    {
        $this->setExpectedException(CacheUnavailableException::class);

        $this->porter->import((new ImportSpecification($this->resource))->setCacheAdvice(CacheAdvice::MUST_CACHE()));
    }
}
