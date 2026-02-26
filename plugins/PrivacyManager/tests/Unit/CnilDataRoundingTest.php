<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\PrivacyManager\tests\Unit;

use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Plugins\PrivacyManager\CnilDataRounding;

class CnilDataRoundingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @group Plugins
     */
    public function testRoundCountMetricsUsesExpectedThresholds(): void
    {
        $table = new DataTable();

        foreach ([0, 1, 14, 15, 24, 25] as $value) {
            $row = new Row();
            $row->addColumn('nb_visits', $value);
            $table->addRow($row);
        }

        CnilDataRounding::roundCountMetrics($table);

        $actual = [];
        foreach ($table->getRows() as $row) {
            $actual[] = $row->getColumn('nb_visits');
        }

        $this->assertSame([0, 10, 10, 20, 20, 30], $actual);
    }

    /**
     * @group Plugins
     */
    public function testRoundCountMetricsSkipsRatesDurationsAndMoneyAndRoundsTotals(): void
    {
        $table = new DataTable();
        $row = new Row();
        $row->addColumn('nb_actions', 13);
        $row->addColumn('bounce_rate', 0.5);
        $row->addColumn('avg_time_on_page', 123);
        $row->addColumn('revenue', 99.99);
        $table->addRow($row);

        $table->setMetadata('totals', [
            'nb_actions' => 21,
            'bounce_rate' => 0.2,
            'avg_time_on_page' => 65,
            'revenue' => 10.50,
        ]);

        CnilDataRounding::roundCountMetrics($table);

        $firstRow = $table->getFirstRow();
        $this->assertSame(10, $firstRow->getColumn('nb_actions'));
        $this->assertSame(0.5, $firstRow->getColumn('bounce_rate'));
        $this->assertSame(123, $firstRow->getColumn('avg_time_on_page'));
        $this->assertSame(99.99, $firstRow->getColumn('revenue'));

        $totals = $table->getMetadata('totals');
        $this->assertSame(20, $totals['nb_actions']);
        $this->assertSame(0.2, $totals['bounce_rate']);
        $this->assertSame(65, $totals['avg_time_on_page']);
        $this->assertSame(10.50, $totals['revenue']);
    }
}
