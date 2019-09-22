<?php

/**
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2019 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/master/LICENSE.md
 */

declare(strict_types=1);

namespace Opulence\Ioc\Tests\Bootstrappers\Inspection;

use Opulence\Ioc\Bootstrappers\Bootstrapper;
use Opulence\Ioc\Bootstrappers\Inspection\BindingInspectionContainer;
use Opulence\Ioc\Bootstrappers\Inspection\BindingInspector;
use Opulence\Ioc\Bootstrappers\Inspection\ImpossibleBindingException;
use Opulence\Ioc\Bootstrappers\Inspection\TargetedBootstrapperBinding;
use Opulence\Ioc\IContainer;
use Opulence\Ioc\Tests\Bootstrappers\Inspection\Mocks\Bar;
use Opulence\Ioc\Tests\Bootstrappers\Inspection\Mocks\Foo;
use Opulence\Ioc\Tests\Bootstrappers\Inspection\Mocks\IBar;
use Opulence\Ioc\Tests\Bootstrappers\Inspection\Mocks\IFoo;
use PHPUnit\Framework\TestCase;

/**
 * Tests the binding inspector
 */
class BindingInspectorTest extends TestCase
{
    private BindingInspector $inspector;
    private BindingInspectionContainer $container;

    protected function setUp(): void
    {
        $this->container = new BindingInspectionContainer();
        $this->inspector = new BindingInspector($this->container);
    }

    public function testInspectingBindingForBootstrapperThatCannotResolveSomethingThrowsException(): void
    {
        $this->expectException(ImpossibleBindingException::class);
        $bootstrapper = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->resolve(IFoo::class);
            }
        };
        $this->inspector->getBindings([$bootstrapper]);
    }

    public function testInspectingBootstrappersWithCyclicalDependenciesThrowsException(): void
    {
        $this->expectException(ImpossibleBindingException::class);
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                /*
                 * Order here is important - a truly cyclical dependency means those dependencies are resolved prior
                 * to them being bound
                 */
                $container->resolve(IFoo::class);
                $container->bindInstance(IBar::class, new Bar());
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                // Ditto about order being important
                $container->resolve(IBar::class);
                $container->bindInstance(IFoo::class, new Foo());
            }
        };
        $this->inspector->getBindings([$bootstrapperA, $bootstrapperB]);
    }

    public function testInspectingBootstrapperThatNeedsTargetedBindingWorksWhenOneHasUniversalBinding(): void
    {
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->for('SomeClass', function (IContainer $container) {
                    $container->resolve(IFoo::class);
                });
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->bindInstance(IFoo::class, new Foo());
            }
        };
        $actualBindings = $this->inspector->getBindings([$bootstrapperA, $bootstrapperB]);
        $this->assertCount(1, $actualBindings);
        $this->assertEquals(IFoo::class, $actualBindings[0]->getInterface());
        $this->assertSame($bootstrapperB, $actualBindings[0]->getBootstrapper());
    }

    public function testInspectingBootstrapperThatNeedsUniversalBindingThrowsExceptionWhenAnotherOneHasTargetedBinding(): void
    {
        $this->expectException(ImpossibleBindingException::class);
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->resolve(IFoo::class);
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->for('SomeClass', function (IContainer $container) {
                    $container->bindInstance(IFoo::class, new Foo());
                });
            }
        };
        $this->inspector->getBindings([$bootstrapperA, $bootstrapperB]);
    }

    public function testInspectingBootstrapperThatReliesOnBindingSetInAnotherStillWorks(): void
    {
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->resolve(IFoo::class);
                $container->bindPrototype('foo', 'bar');
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->bindInstance(IFoo::class, new Foo());
            }
        };
        $actualBindings = $this->inspector->getBindings([$bootstrapperA, $bootstrapperB]);
        $this->assertCount(2, $actualBindings);
        $this->assertEquals(IFoo::class, $actualBindings[0]->getInterface());
        $this->assertSame($bootstrapperB, $actualBindings[0]->getBootstrapper());
        $this->assertEquals('foo', $actualBindings[1]->getInterface());
        $this->assertSame($bootstrapperA, $actualBindings[1]->getBootstrapper());
    }

    public function testInspectingBootstrapperThatReliesOnTargetedBindingSetInAnotherStillWorks(): void
    {
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->for('SomeClass', function (IContainer $container) {
                    $container->resolve(IFoo::class);
                    $container->bindPrototype(IBar::class, Bar::class);
                });
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->for('SomeClass', function (IContainer $container) {
                    $container->bindInstance(IFoo::class, new Foo());
                });
            }
        };
        /** @var TargetedBootstrapperBinding[] $actualBindings */
        $actualBindings = $this->inspector->getBindings([$bootstrapperA, $bootstrapperB]);
        $this->assertCount(2, $actualBindings);
        $this->assertEquals('SomeClass', $actualBindings[0]->getTargetClass());
        $this->assertEquals(IFoo::class, $actualBindings[0]->getInterface());
        $this->assertSame($bootstrapperB, $actualBindings[0]->getBootstrapper());
        $this->assertEquals('SomeClass', $actualBindings[1]->getTargetClass());
        $this->assertEquals(IBar::class, $actualBindings[1]->getInterface());
        $this->assertSame($bootstrapperA, $actualBindings[1]->getBootstrapper());
    }

    public function testInspectingBootstrapperThatReliesOnMultipleOtherBootstrappersBindingStillWorks(): void
    {
        $bootstrapperA = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->resolve(IFoo::class);
                $container->bindPrototype('foo', 'bar');
                $container->resolve(IBar::class);
            }
        };
        $bootstrapperB = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->bindInstance(IFoo::class, new Foo());
            }
        };
        $bootstrapperC = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->bindInstance(IBar::class, new Bar());
            }
        };
        $actualBindings = $this->inspector->getBindings([$bootstrapperA, $bootstrapperB, $bootstrapperC]);
        $this->assertCount(3, $actualBindings);
        $this->assertEquals(IFoo::class, $actualBindings[0]->getInterface());
        $this->assertSame($bootstrapperB, $actualBindings[0]->getBootstrapper());
        $this->assertEquals('foo', $actualBindings[1]->getInterface());
        $this->assertSame($bootstrapperA, $actualBindings[1]->getBootstrapper());
        $this->assertEquals(IBar::class, $actualBindings[2]->getInterface());
        $this->assertSame($bootstrapperC, $actualBindings[2]->getBootstrapper());
    }

    public function testInspectingBindingsCreatesBindingsFromWhatIsBoundInBootstrapper(): void
    {
        $expectedBootstrapper = new class extends Bootstrapper {
            public function registerBindings(IContainer $container): void
            {
                $container->bindInstance(IFoo::class, new Foo());
            }
        };
        $actualBindings = $this->inspector->getBindings([$expectedBootstrapper]);
        $this->assertCount(1, $actualBindings);
        $this->assertEquals(IFoo::class, $actualBindings[0]->getInterface());
        $this->assertSame($expectedBootstrapper, $actualBindings[0]->getBootstrapper());
    }
}
