<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\GraphQl\Tests\Type;

use ApiPlatform\GraphQl\Resolver\Factory\ResolverFactoryInterface;
use ApiPlatform\GraphQl\Tests\Fixtures\Enum\GenderTypeEnum;
use ApiPlatform\GraphQl\Tests\Fixtures\Serializer\NameConverter\CustomConverter;
use ApiPlatform\GraphQl\Type\ContextAwareTypeBuilderInterface;
use ApiPlatform\GraphQl\Type\FieldsBuilder;
use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use ApiPlatform\GraphQl\Type\TypesContainerInterface;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\FilterInterface;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\GraphQl\Operation;
use ApiPlatform\Metadata\GraphQl\Query;
use ApiPlatform\Metadata\GraphQl\QueryCollection;
use ApiPlatform\Metadata\GraphQl\Subscription;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\State\Pagination\Pagination;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\TypeInfo\Type;

/**
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
class FieldsBuilderTest extends TestCase
{
    use ProphecyTrait;

    private ObjectProphecy $propertyNameCollectionFactoryProphecy;
    private ObjectProphecy $propertyMetadataFactoryProphecy;
    private ObjectProphecy $resourceMetadataCollectionFactoryProphecy;
    private ObjectProphecy $typesContainerProphecy;
    private ObjectProphecy $typeBuilderProphecy;
    private ObjectProphecy $typeConverterProphecy;
    private ObjectProphecy $itemResolverFactoryProphecy;
    private ObjectProphecy $filterLocatorProphecy;
    private ObjectProphecy $resourceClassResolverProphecy;
    private FieldsBuilder $fieldsBuilder;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $this->propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $this->resourceMetadataCollectionFactoryProphecy = $this->prophesize(ResourceMetadataCollectionFactoryInterface::class);
        $this->typesContainerProphecy = $this->prophesize(TypesContainerInterface::class);
        $this->typeBuilderProphecy = $this->prophesize(ContextAwareTypeBuilderInterface::class);
        $this->typeConverterProphecy = $this->prophesize(TypeConverterInterface::class);
        $this->itemResolverFactoryProphecy = $this->prophesize(ResolverFactoryInterface::class);
        $this->filterLocatorProphecy = $this->prophesize(ContainerInterface::class);
        $this->resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $this->fieldsBuilder = $this->buildFieldsBuilder();
    }

    private function buildFieldsBuilder(?AdvancedNameConverterInterface $advancedNameConverter = null): FieldsBuilder
    {
        return new FieldsBuilder($this->propertyNameCollectionFactoryProphecy->reveal(), $this->propertyMetadataFactoryProphecy->reveal(), $this->resourceMetadataCollectionFactoryProphecy->reveal(), $this->resourceClassResolverProphecy->reveal(), $this->typesContainerProphecy->reveal(), $this->typeBuilderProphecy->reveal(), $this->typeConverterProphecy->reveal(), $this->itemResolverFactoryProphecy->reveal(), $this->filterLocatorProphecy->reveal(), new Pagination(), $advancedNameConverter ?? new CustomConverter(), '__');
    }

    public function testGetNodeQueryFields(): void
    {
        $nodeInterfaceType = $this->prophesize(InterfaceType::class)->reveal();
        $this->typeBuilderProphecy->getNodeInterface()->shouldBeCalled()->willReturn($nodeInterfaceType);

        $itemResolver = function (): void {
        };
        $this->itemResolverFactoryProphecy->__invoke()->shouldBeCalled()->willReturn($itemResolver);

        $nodeQueryFields = $this->fieldsBuilder->getNodeQueryFields();
        $this->assertArrayHasKey('type', $nodeQueryFields);
        $this->assertArrayHasKey('args', $nodeQueryFields);
        $this->assertArrayHasKey('resolve', $nodeQueryFields);

        $this->assertSame($nodeInterfaceType, $nodeQueryFields['type']);
        $this->assertArrayHasKey('id', $nodeQueryFields['args']);
        $this->assertArrayHasKey('type', $nodeQueryFields['args']['id']);
        $this->assertInstanceOf(NonNull::class, $nodeQueryFields['args']['id']['type']);
        /** @var NonNull $idType */
        $idType = $nodeQueryFields['args']['id']['type'];
        $this->assertSame(GraphQLType::id(), $idType->getWrappedType());
        $this->assertSame($itemResolver, $nodeQueryFields['resolve']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('itemQueryFieldsProvider')]
    public function testGetItemQueryFields(string $resourceClass, Operation $operation, array $configuration, ?GraphQLType $graphqlType, ?callable $resolver, array $expectedQueryFields): void
    {
        $this->resourceClassResolverProphecy->isResourceClass($resourceClass)->willReturn(true);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), false, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($graphqlType);
        $this->typeConverterProphecy->resolveType(Argument::type('string'))->willReturn(GraphQLType::string());
        $this->itemResolverFactoryProphecy->__invoke($resourceClass, $resourceClass, $operation, Argument::any())->willReturn($resolver);

        $queryFields = $this->fieldsBuilder->getItemQueryFields($resourceClass, $operation, $configuration);

        $this->assertEquals($expectedQueryFields, $queryFields);
    }

    public static function itemQueryFieldsProvider(): array
    {
        return [
            'no resource field configuration' => [\stdClass::class, (new Query())->withClass(\stdClass::class)->withName('action'), [], null, null, []],
            'nested item query' => [\stdClass::class, (new Query())->withNested(true)->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), [], new ObjectType(['name' => 'item', 'fields' => []]), function (): void {
            }, []],
            'nominal standard type case with deprecation reason and description' => [\stdClass::class, (new Query())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withDeprecationReason('not useful')->withDescription('Custom description.'), [], GraphQLType::string(), null,
                [
                    'actionShortName' => [
                        'type' => GraphQLType::string(),
                        'description' => 'Custom description.',
                        'args' => [
                            'id' => ['type' => GraphQLType::nonNull(GraphQLType::id())],
                        ],
                        'resolve' => null,
                        'deprecationReason' => 'not useful',
                    ],
                ],
            ],
            'nominal item case' => [\stdClass::class, (new Query())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), [], $graphqlType = new ObjectType(['name' => 'item', 'fields' => []]), $resolver = function (): void {
            },
                [
                    'actionShortName' => [
                        'type' => $graphqlType,
                        'description' => null,
                        'args' => [
                            'id' => ['type' => GraphQLType::nonNull(GraphQLType::id())],
                        ],
                        'resolve' => $resolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
            'empty overridden args and add fields' => [
                \stdClass::class, (new Query())->withClass(\stdClass::class)->withShortName('ShortName'), ['args' => [], 'name' => 'customActionName'], GraphQLType::string(), null,
                [
                    'shortName' => [
                        'type' => GraphQLType::string(),
                        'description' => null,
                        'args' => [],
                        'resolve' => null,
                        'deprecationReason' => null,
                        'name' => 'customActionName',
                    ],
                ],
            ],
            'override args with custom ones' => [
                \stdClass::class, (new Query())->withClass(\stdClass::class)->withShortName('ShortName'), ['args' => ['customArg' => ['type' => 'a type']]], GraphQLType::string(), null,
                [
                    'shortName' => [
                        'type' => GraphQLType::string(),
                        'description' => null,
                        'args' => [
                            'customArg' => [
                                'type' => GraphQLType::string(),
                            ],
                        ],
                        'resolve' => null,
                        'deprecationReason' => null,
                    ],
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('collectionQueryFieldsProvider')]
    public function testGetCollectionQueryFields(string $resourceClass, Operation $operation, array $configuration, ?GraphQLType $graphqlType, ?callable $resolver, array $expectedQueryFields): void
    {
        $this->resourceClassResolverProphecy->isResourceClass($resourceClass)->willReturn(true);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), false, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($graphqlType);
        $this->typeConverterProphecy->resolveType(Argument::type('string'))->willReturn(GraphQLType::string());
        $this->typeBuilderProphecy->getPaginatedCollectionType($graphqlType, $operation)->willReturn($graphqlType);
        $this->itemResolverFactoryProphecy->__invoke($resourceClass, $resourceClass, $operation, Argument::any())->willReturn($resolver);
        $this->filterLocatorProphecy->has('my_filter')->willReturn(true);
        $filterProphecy = $this->prophesize(FilterInterface::class);
        $filterProphecy->getDescription($resourceClass)->willReturn([
            'boolField' => ['type' => 'bool', 'required' => true],
            'boolField[]' => ['type' => 'bool', 'required' => false],
            'parent.child[related.nested]' => ['type' => 'bool', 'required' => false],
            'dateField[before]' => ['type' => \DateTimeInterface::class, 'required' => false],
        ]);
        $this->filterLocatorProphecy->get('my_filter')->willReturn($filterProphecy->reveal());
        $this->typesContainerProphecy->has('ShortNameFilter_dateField')->willReturn(false);
        $this->typesContainerProphecy->has('ShortNameFilter_parent__child')->willReturn(false);
        $this->typesContainerProphecy->set('ShortNameFilter_dateField', Argument::type(ListOfType::class));
        $this->typesContainerProphecy->set('ShortNameFilter_parent__child', Argument::type(ListOfType::class));

        $queryFields = $this->fieldsBuilder->getCollectionQueryFields($resourceClass, $operation, $configuration);

        $this->assertEquals($expectedQueryFields, $queryFields);
    }

    public static function collectionQueryFieldsProvider(): array
    {
        return [
            'no resource field configuration' => [\stdClass::class, (new QueryCollection())->withClass(\stdClass::class)->withName('action'), [], null, null, []],
            'nested collection query' => [\stdClass::class, (new QueryCollection())->withNested(true)->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), [], GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), function (): void {
            }, []],
            'nominal collection case with deprecation reason and description' => [\stdClass::class, (new QueryCollection())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withDeprecationReason('not useful')->withDescription('Custom description.'), [], $graphqlType = GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), $resolver = function (): void {
            },
                [
                    'actionShortNames' => [
                        'type' => $graphqlType,
                        'description' => 'Custom description.',
                        'args' => [
                            'first' => [
                                'type' => GraphQLType::int(),
                                'description' => 'Returns the first n elements from the list.',
                            ],
                            'last' => [
                                'type' => GraphQLType::int(),
                                'description' => 'Returns the last n elements from the list.',
                            ],
                            'before' => [
                                'type' => GraphQLType::string(),
                                'description' => 'Returns the elements in the list that come before the specified cursor.',
                            ],
                            'after' => [
                                'type' => GraphQLType::string(),
                                'description' => 'Returns the elements in the list that come after the specified cursor.',
                            ],
                        ],
                        'resolve' => $resolver,
                        'deprecationReason' => 'not useful',
                    ],
                ],
            ],
            'collection with filters' => [\stdClass::class, (new QueryCollection())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withFilters(['my_filter']), [], $graphqlType = GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), $resolver = function (): void {
            },
                [
                    'actionShortNames' => [
                        'type' => $graphqlType,
                        'description' => null,
                        'args' => [
                            'first' => [
                                'type' => GraphQLType::int(),
                                'description' => 'Returns the first n elements from the list.',
                            ],
                            'last' => [
                                'type' => GraphQLType::int(),
                                'description' => 'Returns the last n elements from the list.',
                            ],
                            'before' => [
                                'type' => GraphQLType::string(),
                                'description' => 'Returns the elements in the list that come before the specified cursor.',
                            ],
                            'after' => [
                                'type' => GraphQLType::string(),
                                'description' => 'Returns the elements in the list that come after the specified cursor.',
                            ],
                            'boolField' => GraphQLType::nonNull($graphqlType),
                            'boolField_list' => GraphQLType::listOf($graphqlType),
                            'parent__child' => GraphQLType::listOf(new InputObjectType(['name' => 'ShortNameFilter_parent__child', 'fields' => ['related__nested' => $graphqlType]])),
                            'dateField' => GraphQLType::listOf(new InputObjectType(['name' => 'ShortNameFilter_dateField', 'fields' => ['before' => $graphqlType]])),
                        ],
                        'resolve' => $resolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
            'collection empty overridden args and add fields' => [
                \stdClass::class, (new QueryCollection())->withArgs([])->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), ['args' => [], 'name' => 'customActionName'], $graphqlType = GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), $resolver = function (): void {
                },
                [
                    'actionShortNames' => [
                        'type' => $graphqlType,
                        'description' => null,
                        'args' => [],
                        'resolve' => $resolver,
                        'deprecationReason' => null,
                        'name' => 'customActionName',
                    ],
                ],
            ],
            'collection override args with custom ones' => [
                \stdClass::class, (new QueryCollection())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), ['args' => ['customArg' => ['type' => 'a type']]], $graphqlType = GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), $resolver = function (): void {
                },
                [
                    'actionShortNames' => [
                        'type' => $graphqlType,
                        'description' => null,
                        'args' => [
                            'customArg' => [
                                'type' => GraphQLType::string(),
                            ],
                        ],
                        'resolve' => $resolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
            'collection with page-based pagination enabled' => [\stdClass::class, (new QueryCollection())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withPaginationType('page')->withFilters(['my_filter']), [], $graphqlType = GraphQLType::listOf(new ObjectType(['name' => 'collection', 'fields' => []])), $resolver = function (): void {
            },
                [
                    'actionShortNames' => [
                        'type' => $graphqlType,
                        'description' => null,
                        'args' => [
                            'page' => [
                                'type' => GraphQLType::int(),
                                'description' => 'Returns the current page.',
                            ],
                            'boolField' => GraphQLType::nonNull($graphqlType),
                            'boolField_list' => GraphQLType::listOf($graphqlType),
                            'parent__child' => GraphQLType::listOf(new InputObjectType(['name' => 'ShortNameFilter_parent__child', 'fields' => ['related__nested' => $graphqlType]])),
                            'dateField' => GraphQLType::listOf(new InputObjectType(['name' => 'ShortNameFilter_dateField', 'fields' => ['before' => $graphqlType]])),
                        ],
                        'resolve' => $resolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutationFieldsProvider')]
    public function testGetMutationFields(string $resourceClass, Operation $operation, GraphQLType $graphqlType, GraphQLType $inputGraphqlType, ?callable $mutationResolver, array $expectedMutationFields): void
    {
        $this->resourceClassResolverProphecy->isResourceClass($resourceClass)->willReturn(true);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), false, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($graphqlType);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), true, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($inputGraphqlType);
        $this->itemResolverFactoryProphecy->__invoke($resourceClass, $resourceClass, $operation, Argument::any())->willReturn($mutationResolver);

        $mutationFields = $this->fieldsBuilder->getMutationFields($resourceClass, $operation);

        $this->assertSame($expectedMutationFields, $mutationFields);
    }

    public static function mutationFieldsProvider(): array
    {
        return [
            'nominal case with deprecation reason' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withDeprecationReason('not useful'), $graphqlType = new ObjectType(['name' => 'mutation', 'fields' => []]), $inputGraphqlType = new ObjectType(['name' => 'input', 'fields' => []]), $mutationResolver = function (): void {
            },
                [
                    'actionShortName' => [
                        'type' => $graphqlType,
                        'description' => 'Actions a ShortName.',
                        'args' => [
                            'input' => [
                                'type' => $inputGraphqlType,
                                'description' => null,
                                'args' => [],
                                'resolve' => null,
                                'deprecationReason' => 'not useful',
                            ],
                        ],
                        'resolve' => $mutationResolver,
                        'deprecationReason' => 'not useful',
                    ],
                ],
            ],
            'custom description' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withDescription('Custom description.'), $graphqlType = new ObjectType(['name' => 'mutation', 'fields' => []]), $inputGraphqlType = new ObjectType(['name' => 'input', 'fields' => []]), $mutationResolver = function (): void {
            },
                [
                    'actionShortName' => [
                        'type' => $graphqlType,
                        'description' => 'Custom description.',
                        'args' => [
                            'input' => [
                                'type' => $inputGraphqlType,
                                'description' => null,
                                'args' => [],
                                'resolve' => null,
                                'deprecationReason' => null,
                            ],
                        ],
                        'resolve' => $mutationResolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subscriptionFieldsProvider')]
    public function testGetSubscriptionFields(string $resourceClass, Operation $operation, GraphQLType $graphqlType, GraphQLType $inputGraphqlType, ?callable $subscriptionResolver, array $expectedSubscriptionFields): void
    {
        $this->resourceClassResolverProphecy->isResourceClass($resourceClass)->willReturn(true);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), false, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($graphqlType);
        $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), true, Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), $resourceClass, $resourceClass, null, 0)->willReturn($inputGraphqlType);
        $this->resourceMetadataCollectionFactoryProphecy->create($resourceClass)->willReturn(new ResourceMetadataCollection($resourceClass, [(new ApiResource())->withGraphQlOperations([$operation->getName() => $operation])]));
        $this->itemResolverFactoryProphecy->__invoke($resourceClass, $resourceClass, $operation, Argument::any())->willReturn($subscriptionResolver);

        $subscriptionFields = $this->fieldsBuilder->getSubscriptionFields($resourceClass, $operation);

        $this->assertSame($expectedSubscriptionFields, $subscriptionFields);
    }

    public static function subscriptionFieldsProvider(): array
    {
        return [
            'mercure not enabled' => [\stdClass::class, (new Subscription())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName'), new ObjectType(['name' => 'subscription', 'fields' => []]), new ObjectType(['name' => 'input', 'fields' => []]), null, [],
            ],
            'nominal case with deprecation reason' => [\stdClass::class, (new Subscription())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withMercure(true)->withDeprecationReason('not useful'), $graphqlType = new ObjectType(['name' => 'subscription', 'fields' => []]), $inputGraphqlType = new ObjectType(['name' => 'input', 'fields' => []]), $subscriptionResolver = function (): void {
            },
                [
                    'actionShortNameSubscribe' => [
                        'type' => $graphqlType,
                        'description' => 'Subscribes to the action event of a ShortName.',
                        'args' => [
                            'input' => [
                                'type' => $inputGraphqlType,
                                'description' => null,
                                'args' => [],
                                'resolve' => null,
                                'deprecationReason' => 'not useful',
                            ],
                        ],
                        'resolve' => $subscriptionResolver,
                        'deprecationReason' => 'not useful',
                    ],
                ],
            ],
            'custom description' => [\stdClass::class, (new Subscription())->withClass(\stdClass::class)->withName('action')->withShortName('ShortName')->withMercure(true)->withDescription('Custom description.'), $graphqlType = new ObjectType(['name' => 'subscription', 'fields' => []]), $inputGraphqlType = new ObjectType(['name' => 'input', 'fields' => []]), $subscriptionResolver = function (): void {
            },
                [
                    'actionShortNameSubscribe' => [
                        'type' => $graphqlType,
                        'description' => 'Custom description.',
                        'args' => [
                            'input' => [
                                'type' => $inputGraphqlType,
                                'description' => null,
                                'args' => [],
                                'resolve' => null,
                                'deprecationReason' => null,
                            ],
                        ],
                        'resolve' => $subscriptionResolver,
                        'deprecationReason' => null,
                    ],
                ],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resourceObjectTypeFieldsProvider')]
    public function testGetResourceObjectTypeFields(string $resourceClass, Operation $operation, array $properties, bool $input, int $depth, ?array $ioMetadata, array $expectedResourceObjectTypeFields, ?callable $advancedNameConverterFactory = null): void
    {
        $this->resourceClassResolverProphecy->isResourceClass($resourceClass)->willReturn(true);
        $this->resourceClassResolverProphecy->isResourceClass('nestedResourceClass')->willReturn(true);
        $this->resourceClassResolverProphecy->isResourceClass('nestedResourceNoQueryClass')->willReturn(true);
        $this->resourceClassResolverProphecy->isResourceClass(Argument::type('string'))->willReturn(false);
        $this->propertyNameCollectionFactoryProphecy->create($resourceClass)->willReturn(new PropertyNameCollection(array_keys($properties)));
        foreach ($properties as $propertyName => $propertyMetadata) {
            $this->propertyMetadataFactoryProphecy->create($resourceClass, $propertyName, ['normalization_groups' => null, 'denormalization_groups' => null])->willReturn($propertyMetadata);
            $this->typeConverterProphecy->convertPhpType(Type::null(), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), '', $resourceClass, $propertyName, $depth + 1)->willReturn(null);
            $this->typeConverterProphecy->convertPhpType(Type::callable(), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), '', $resourceClass, $propertyName, $depth + 1)->willReturn('NotRegisteredType');
            $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), '', $resourceClass, $propertyName, $depth + 1)->willReturn(GraphQLType::string());
            $this->typeConverterProphecy->convertPhpType(Type::list(Type::string()), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), '', $resourceClass, $propertyName, $depth + 1)->willReturn(GraphQLType::nonNull(GraphQLType::listOf(GraphQLType::nonNull(GraphQLType::string()))));

            if ('propertyObject' === $propertyName) {
                $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), 'objectClass', $resourceClass, $propertyName, $depth + 1)->willReturn(new ObjectType(['name' => 'objectType', 'fields' => []]));
                $this->itemResolverFactoryProphecy->__invoke('objectClass', $resourceClass, $operation, Argument::any())->willReturn(static function (): void {
                });
            }
            if ('propertyNestedResource' === $propertyName) {
                $nestedResourceQueryOperation = new Query();
                $this->resourceMetadataCollectionFactoryProphecy->create('nestedResourceClass')->willReturn(new ResourceMetadataCollection('nestedResourceClass', [(new ApiResource())->withGraphQlOperations(['item_query' => $nestedResourceQueryOperation])]));
                $this->typeConverterProphecy->convertPhpType(Argument::type(Type::class), Argument::type('bool'), Argument::that(static fn (Operation $arg): bool => $arg->getName() === $operation->getName()), 'nestedResourceClass', $resourceClass, $propertyName, $depth + 1)->willReturn(new ObjectType(['name' => 'objectType', 'fields' => []]));
                $this->itemResolverFactoryProphecy->__invoke('nestedResourceClass', $resourceClass, $nestedResourceQueryOperation, Argument::any())->willReturn(static function (): void {
                });
            }
        }
        $this->typesContainerProphecy->has('NotRegisteredType')->willReturn(false);
        $this->typesContainerProphecy->all()->willReturn([]);

        $fieldsBuilder = $this->fieldsBuilder;
        if ($advancedNameConverterFactory) {
            $fieldsBuilder = $this->buildFieldsBuilder($advancedNameConverterFactory($this));
        }
        $resourceObjectTypeFields = $fieldsBuilder->getResourceObjectTypeFields($resourceClass, $operation, $input, $depth, $ioMetadata);

        $this->assertEquals($expectedResourceObjectTypeFields, $resourceObjectTypeFields);
    }

    public static function resourceObjectTypeFieldsProvider(): iterable
    {
        $advancedNameConverterFactory = function (self $that): AdvancedNameConverterInterface {
            $advancedNameConverterProphecy = $that->prophesize(AdvancedNameConverterInterface::class);
            $advancedNameConverterProphecy->normalize('field', \stdClass::class)->willReturn('normalizedField');

            return $advancedNameConverterProphecy->reveal();
        };

        yield 'query' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(true)->withWritable(false),
                'propertyNotReadable' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(false),
                'nameConverted' => (new ApiProperty())->withNativeType(Type::string())->withReadable(true)->withWritable(false),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'name_converted' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'query with advanced name converter' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'field' => (new ApiProperty())->withNativeType(Type::string())->withReadable(true)->withWritable(false),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'normalizedField' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
            ],
            $advancedNameConverterFactory,
        ];
        yield 'query input' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
                'nonWritableProperty' => (new ApiProperty())->withNativeType(Type::string())->withReadable(false)->withWritable(false),
            ],
            true, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'query with simple non-null string array property' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'property' => (new ApiProperty())->withNativeType(Type::list(Type::string()))->withReadable(true)->withWritable(false),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'property' => [
                    'type' => GraphQLType::nonNull(GraphQLType::listOf(GraphQLType::nonNull(GraphQLType::string()))),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'query with nested resources' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'propertyNestedResource' => (new ApiProperty())->withNativeType(Type::object('nestedResourceClass'))->withReadable(true)->withWritable(true),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyNestedResource' => [
                    'type' => GraphQLType::nonNull(new ObjectType(['name' => 'objectType', 'fields' => []])),
                    'description' => null,
                    'args' => [],
                    'resolve' => static function (): void {
                    },
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'mutation non input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('mutation'),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
                'propertyReadable' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(true)->withWritable(true),
                'propertyObject' => (new ApiProperty())->withNativeType(Type::object('objectClass'))->withReadable(true)->withWritable(true),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyReadable' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'propertyObject' => [
                    'type' => GraphQLType::nonNull(new ObjectType(['name' => 'objectType', 'fields' => []])),
                    'description' => null,
                    'args' => [],
                    'resolve' => static function (): void {
                    },
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'mutation input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('mutation'),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withDescription('propertyBool description')->withReadable(false)->withWritable(true)->withDeprecationReason('not useful'),
                'propertySubresource' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
                'nonWritableProperty' => (new ApiProperty())->withNativeType(Type::string())->withReadable(false)->withWritable(false),
                'id' => (new ApiProperty())->withNativeType(Type::int())->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => 'propertyBool description',
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => 'not useful',
                ],
                'propertySubresource' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                '_id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'custom mutation' => [\stdClass::class, (new Mutation())->withResolver('resolver')->withName('mutation'),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withDescription('propertyBool description')->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => 'propertyBool description',
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'mutation nested input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('mutation'),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            true, 1, null,
            [
                'id' => [
                    'type' => GraphQLType::id(),
                ],
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'delete mutation input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('delete'),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'create mutation input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('create'),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'propertyBool' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'update mutation input' => [\stdClass::class, (new Mutation())->withClass(\stdClass::class)->withName('update'),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyBool' => [
                    'type' => GraphQLType::string(),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'subscription non input' => [\stdClass::class, (new Subscription())->withClass(\stdClass::class),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
                'propertyReadable' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(true)->withWritable(true),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'propertyReadable' => [
                    'type' => GraphQLType::nonNull(GraphQLType::string()),
                    'description' => null,
                    'args' => [],
                    'resolve' => null,
                    'deprecationReason' => null,
                ],
            ],
        ];
        yield 'subscription input' => [\stdClass::class, (new Subscription())->withClass(\stdClass::class),
            [
                'property' => new ApiProperty(),
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withDescription('propertyBool description')->withReadable(false)->withWritable(true)->withDeprecationReason('not useful'),
                'propertySubresource' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
                'id' => (new ApiProperty())->withNativeType(Type::int())->withReadable(false)->withWritable(true),
            ],
            true, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
                'clientSubscriptionId' => GraphQLType::string(),
            ],
        ];
        yield 'null io metadata non input' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            false, 0, ['class' => null], [],
        ];
        yield 'null io metadata input' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'propertyBool' => (new ApiProperty())->withNativeType(Type::bool())->withReadable(false)->withWritable(true),
            ],
            true, 0, ['class' => null],
            [
                'clientMutationId' => GraphQLType::string(),
            ],
        ];
        yield 'invalid types' => [\stdClass::class, (new Query())->withClass(\stdClass::class),
            [
                'propertyInvalidType' => (new ApiProperty())->withNativeType(Type::null())->withReadable(true)->withWritable(false),
                'propertyNotRegisteredType' => (new ApiProperty())->withNativeType(Type::callable())->withReadable(true)->withWritable(false),
            ],
            false, 0, null,
            [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                ],
            ],
        ];
    }

    public function testGetEnumFields(): void
    {
        $enumClass = GenderTypeEnum::class;

        $this->propertyMetadataFactoryProphecy->create($enumClass, GenderTypeEnum::MALE->name)->willReturn(new ApiProperty(
            description: 'Description of MALE case',
        ));
        $this->propertyMetadataFactoryProphecy->create($enumClass, GenderTypeEnum::FEMALE->name)->willReturn(new ApiProperty(
            description: 'Description of FEMALE case',
        ));

        $enumFields = $this->fieldsBuilder->getEnumFields($enumClass);

        $this->assertSame([
            GenderTypeEnum::MALE->name => ['value' => GenderTypeEnum::MALE->value, 'description' => 'Description of MALE case'],
            GenderTypeEnum::FEMALE->name => ['value' => GenderTypeEnum::FEMALE->value, 'description' => 'Description of FEMALE case'],
        ], $enumFields);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resolveResourceArgsProvider')]
    public function testResolveResourceArgs(array $args, array $expectedResolvedArgs, ?string $expectedExceptionMessage = null): void
    {
        if (null !== $expectedExceptionMessage) {
            $this->expectExceptionMessage($expectedExceptionMessage);
        }

        $this->typeConverterProphecy->resolveType(Argument::type('string'))->willReturn(GraphQLType::string());

        $operation = (new Query())->withName('operation')->withShortName('shortName');
        $args = $this->fieldsBuilder->resolveResourceArgs($args, $operation);

        $this->assertSame($expectedResolvedArgs, $args);
    }

    public static function resolveResourceArgsProvider(): array
    {
        return [
            [[], []],
            [['customArg' => []], [], 'The argument "customArg" of the custom operation "operation" in shortName needs a "type" option.'],
            [['customArg' => ['type' => 'a type']], ['customArg' => ['type' => GraphQLType::string()]]],
        ];
    }
}
