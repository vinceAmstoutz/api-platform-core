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

namespace ApiPlatform\Tests\Fixtures\TestBundle\ApiResource\GenIdFalse;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;

#[ApiResource(operations: [])]
class AggregateRating
{
    public function __construct(
        #[ApiProperty(iris: ['https://schema.org/ratingValue'])] public float $ratingValue,
        #[ApiProperty(iris: ['https://schema.org/ratingCount'])] public int $ratingCount,
    ) {
    }
}
