<?php declare(strict_types=1);
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://swoft.org/docs
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace Swoft\Breaker\State;

use Swoft\Bean\Annotation\Mapping\Bean;

/**
 * Class CloseState
 *
 * @since 2.0
 *
 * @Bean(scope=Bean::PROTOTYPE)
 */
class CloseState extends AbstractState
{
    /**
     * Reset state
     */
    public function reset(): void
    {
        $this->breaker->resetFailCount();
    }

    /**
     * Success
     */
    public function success(): void
    {
        // Reset failCount
        $this->breaker->resetFailCount();
        return;
    }

    /**
     */
    public function exception(): void
    {
        parent::exception();

        if ($this->breaker->isReachFailThreshold()) {
            $this->breaker->moveToOpen();
        }
    }
}
