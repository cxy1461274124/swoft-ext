<?php declare(strict_types=1);
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://swoft.org/docs
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace Swoft\Limiter\Annotation\Parser;

use Swoft\Annotation\Annotation\Mapping\AnnotationParser;
use Swoft\Annotation\Annotation\Parser\Parser;
use Swoft\Limiter\Annotation\Mapping\RateLimiter;
use Swoft\Limiter\Exception\RateLImiterException;
use Swoft\Limiter\RateLimiterRegister;

/**
 * Class RateLimiterParser
 *
 * @since 2.0
 *
 * @AnnotationParser(RateLimiter::class)
 */
class RateLimiterParser extends Parser
{
    /**
     * @param int         $type
     * @param RateLimiter $annotationObject
     *
     * @return array
     * @throws RateLImiterException
     */
    public function parse(int $type, $annotationObject): array
    {
        if ($type != self::TYPE_METHOD) {
            return [];
        }

        RateLimiterRegister::registerRateLimiter($this->className, $this->methodName, $annotationObject);
        return [];
    }
}
