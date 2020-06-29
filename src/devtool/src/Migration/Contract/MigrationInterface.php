<?php declare(strict_types=1);
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://swoft.org/docs
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace Swoft\Devtool\Migration\Contract;

/**
 * Class MigrationInterface
 *
 * @since 2.0
 */
interface MigrationInterface
{
    /**
     * @return void
     */
    public function up(): void;

    /**
     * @return void
     */
    public function down(): void;
}
